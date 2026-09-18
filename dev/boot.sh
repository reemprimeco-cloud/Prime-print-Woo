#!/usr/bin/env bash
#
# Boots the dev site, then directly corrects the SQLite database for the
# things wp-playground-cli has proven unreliable at doing itself in this
# sandbox:
#
#   - the WordPress/plugin zip fetch occasionally fails outright
#     ("fetch failed") — the whole boot is retried when that happens.
#   - `installPlugin ... "activate": true` for a SECOND plugin in the same
#     blueprint run silently does not activate it (no error, files download
#     fine, it is just never added to active_plugins).
#   - a blueprint `runPHP` step meant to correct exactly that turned out to
#     be equally unreliable — its effects (switch_theme(), activate_plugin())
#     do not consistently land in the database the running server actually
#     reads from, for reasons that were not worth chasing further given a
#     simpler, proven-reliable alternative was available: writing directly
#     to the site's SQLite file, the same way this session already
#     successfully VERIFIED dozens of boots' worth of state all along.
#
# Usage: ./dev/boot.sh [port] [max_attempts]
set -uo pipefail

PORT="${1:-9400}"
MAX_ATTEMPTS="${2:-5}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG="$ROOT/dev/boot.log"

find_db() {
  local server_pid vfs
  server_pid=$(pgrep -f "wp-playground-cli server.*--port=$PORT" | tail -1)
  [ -z "$server_pid" ] && return 1
  vfs=$(find /private/var/folders -maxdepth 4 -iname "node-playground-cli-site-${server_pid}--${server_pid}-*" -type d 2>/dev/null | head -1)
  [ -z "$vfs" ] && return 1
  echo "$vfs/wordpress/wp-content/database/.ht.sqlite"
}

for attempt in $(seq 1 "$MAX_ATTEMPTS"); do
  echo "=== boot attempt $attempt/$MAX_ATTEMPTS ===" | tee -a "$LOG"

  pkill -f wp-playground 2>/dev/null
  sleep 2

  nohup "$ROOT/dev/start.sh" "$PORT" > "$LOG" 2>&1 &
  disown

  ready=0
  for i in $(seq 1 150); do
    code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 3 "http://127.0.0.1:$PORT/" 2>/dev/null)
    if [ "$code" = "200" ] || [ "$code" = "301" ] || [ "$code" = "302" ]; then
      ready=1
      break
    fi
    if grep -q "^Error" "$LOG" 2>/dev/null; then
      break
    fi
    sleep 5
  done

  if [ "$ready" != "1" ]; then
    echo "attempt $attempt: did not come up cleanly, retrying" | tee -a "$LOG"
    continue
  fi

  db=$(find_db)
  if [ -z "${db:-}" ] || [ ! -f "$db" ]; then
    echo "attempt $attempt: came up but could not locate its database, retrying" | tee -a "$LOG"
    continue
  fi

  # An HTTP 200 here only means "a WordPress site is responding" — the
  # blueprint's own installPlugin/setSiteOptions/runPHP steps keep running
  # for a while after that first response, so WooCommerce reliably shows as
  # NOT YET active for several seconds even on an eventually-successful boot.
  # Poll for real completion (WooCommerce active — the one step this CLI has
  # never once failed at) before attempting the direct correction below,
  # instead of racing it.
  blueprint_done=0
  for j in $(seq 1 40); do
    active_now=$(sqlite3 "$db" ".timeout 3000" "SELECT option_value FROM wp_options WHERE option_name='active_plugins';" 2>/dev/null)
    if [[ "$active_now" == *woocommerce* ]]; then
      blueprint_done=1
      break
    fi
    sleep 3
  done

  if [ "$blueprint_done" != "1" ]; then
    echo "attempt $attempt: blueprint never finished applying (WooCommerce never activated), retrying" | tee -a "$LOG"
    continue
  fi

  # Direct correction — see the header comment for why this replaced trying
  # to get the blueprint's own steps to do it reliably. Retried a few times
  # in place first: the live server is often mid-write (seeding products,
  # sideloading images) right as blueprint_done flips true, and a `database is
  # locked` failure here is cheap to retry — a few seconds — versus a full
  # reboot, which is minutes.
  # Two SEPARATE single-line invocations, not one heredoc with both UPDATE
  # statements piped over stdin — empirically, the heredoc form reports no
  # error but silently does not persist either write, while the exact same
  # SQL run as two single-line `sqlite3 db "UPDATE ..."` calls works every
  # time. Root cause not chased further (not worth it); treat as a known
  # quirk of this sqlite3 CLI build with multi-statement stdin input.
  corrected=0
  for k in $(seq 1 8); do
    sqlite3 "$db" ".timeout 5000" "UPDATE wp_options SET option_value='prime-printing' WHERE option_name IN ('template','stylesheet');" 2>>"$LOG"
    sqlite3 "$db" ".timeout 5000" "UPDATE wp_options SET option_value='a:2:{i:0;s:27:\"woocommerce/woocommerce.php\";i:1;s:21:\"polylang/polylang.php\";}' WHERE option_name='active_plugins';" 2>>"$LOG"

    state=$(sqlite3 "$db" ".timeout 3000" "SELECT option_value FROM wp_options WHERE option_name='template';" 2>/dev/null)
    active=$(sqlite3 "$db" ".timeout 3000" "SELECT option_value FROM wp_options WHERE option_name='active_plugins';" 2>/dev/null)

    if [ "$state" = "prime-printing" ] && [[ "$active" == *woocommerce* ]] && [[ "$active" == *polylang* ]]; then
      corrected=1
      break
    fi

    sleep 3
  done

  if [ "$corrected" != "1" ]; then
    echo "attempt $attempt: direct DB correction did not take after retrying in place (template=$state active=$active), retrying full boot" | tee -a "$LOG"
    continue
  fi

  # Polylang only builds its full request context (and registers the first
  # language, via inc/i18n.php's admin_init hook) on an ADMIN request — a
  # plain frontend hit never triggers it on a fresh install with zero
  # languages configured (see the comment on prime_setup_languages() in
  # inc/i18n.php for the full explanation). `--login` does not itself load
  # /wp-admin/, so that visit has to happen explicitly, once, here.
  cookies=$(mktemp)
  curl -s --max-time 20 -c "$cookies" -b "$cookies" \
    -d "log=admin" -d "pwd=password" -d "wp-submit=Log In" \
    -d "redirect_to=http://127.0.0.1:$PORT/wp-admin/" -d "testcookie=1" \
    "http://127.0.0.1:$PORT/wp-login.php" -o /dev/null 2>&1 || true
  curl -s --max-time 20 -c "$cookies" -b "$cookies" "http://127.0.0.1:$PORT/wp-admin/" -o /dev/null 2>&1 || true
  rm -f "$cookies"

  languages=$(sqlite3 "$db" ".timeout 3000" "SELECT COUNT(*) FROM wp_term_taxonomy WHERE taxonomy='language';" 2>/dev/null)
  if [ "${languages:-0}" -lt 1 ]; then
    echo "attempt $attempt: admin visit did not register any language, retrying" | tee -a "$LOG"
    continue
  fi

  echo "=== ready: http://127.0.0.1:$PORT/ (attempt $attempt, corrected via $db, $languages language(s) registered) ===" | tee -a "$LOG"
  exit 0
done

echo "=== gave up after $MAX_ATTEMPTS attempts ===" | tee -a "$LOG"
exit 1
