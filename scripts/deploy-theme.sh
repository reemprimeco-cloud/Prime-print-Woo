#!/usr/bin/env bash
#
# Upload the theme to the live WordPress.com site over SFTP.
#
# WordPress.com Business gives SFTP only — no shell, so no rsync and no git on
# the far side. This uploads a chosen set of files with `sftp -b`, which asks
# for the password once, in your terminal. Nothing here stores it, and the
# password never appears in the command line or in any file.
#
# Usage:
#   scripts/deploy-theme.sh                 # upload only what changed since the
#                                           # last deployed version tag (below)
#   scripts/deploy-theme.sh --all           # upload the whole theme
#   scripts/deploy-theme.sh inc/product.php # upload named files
#
# Before running, set your SFTP user once per terminal session:
#   export PRIME_SFTP_USER='...'            # from WP.com → Settings → Hosting Config
#
# Remember: WordPress.com's edge cache serves stale CSS/JS at the same ?ver=,
# so `style.css` (which carries Version:) is always uploaded too. See README.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
THEME_DIR="$ROOT/wp-content/themes/prime-printing"
REMOTE="/htdocs/wp-content/themes/prime-printing"
HOST="sftp.wp.com"

# The commit whose theme state is currently live. Move this forward after a
# successful deploy so the next run knows what "changed" means.
LAST_DEPLOYED="${PRIME_LAST_DEPLOYED:-ad2f363}"

USER_NAME="${PRIME_SFTP_USER:-}"
if [ -z "$USER_NAME" ]; then
  echo "Set your SFTP username first:" >&2
  echo "  export PRIME_SFTP_USER='your-sftp-user'" >&2
  echo "(WordPress.com → Settings → Hosting Configuration → SFTP/SSH)" >&2
  exit 1
fi

cd "$THEME_DIR"

case "${1:-}" in
  --all)
    FILES=$(find . -type f \
      ! -path './node_modules/*' ! -path './.git/*' ! -name '.DS_Store' \
      | sed 's|^\./||' | sort)
    ;;
  "")
    FILES=$(git -C "$ROOT" diff --name-only "$LAST_DEPLOYED" HEAD -- "$THEME_DIR" \
      | sed "s|wp-content/themes/prime-printing/||" | sort -u)
    ;;
  *)
    FILES="$*"
    ;;
esac

# style.css carries the Version: that busts WordPress.com's asset cache, so it
# ships with every deploy whether or not git thinks it changed.
FILES=$(printf '%s\nstyle.css\n' "$FILES" | grep -v '^$' | sort -u)

if [ -z "$FILES" ]; then
  echo "Nothing to upload."
  exit 0
fi

VERSION=$(grep -m1 '^Version:' style.css | awk '{print $2}')

echo "Theme version : $VERSION"
echo "Uploading to  : $USER_NAME@$HOST:$REMOTE"
echo
echo "$FILES" | sed 's/^/  /'
echo
read -r -p "Upload these $(echo "$FILES" | wc -l | tr -d ' ') file(s)? [y/N] " reply
[ "$reply" = "y" ] || [ "$reply" = "Y" ] || { echo "Cancelled."; exit 1; }

BATCH=$(mktemp)
trap 'rm -f "$BATCH"' EXIT

# Create any directories the files live in; -p keeps sftp going if one exists.
{
  echo "-mkdir $REMOTE"
  echo "$FILES" | sed 's|/[^/]*$||' | grep '/' | sort -u | while read -r dir; do
    path="$REMOTE"
    IFS='/' read -ra parts <<< "$dir"
    for part in "${parts[@]}"; do
      path="$path/$part"
      echo "-mkdir $path"
    done
  done
  echo "$FILES" | while read -r file; do
    echo "put \"$file\" \"$REMOTE/$file\""
  done
} > "$BATCH"

sftp -b "$BATCH" "$USER_NAME@$HOST"

echo
echo "Done. Theme $VERSION is live."
echo "Next: record this deploy so the next run knows what changed —"
echo "  export PRIME_LAST_DEPLOYED=$(git -C "$ROOT" rev-parse --short HEAD)"
