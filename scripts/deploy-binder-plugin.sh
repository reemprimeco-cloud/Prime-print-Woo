#!/usr/bin/env bash
#
# Upload the Prime Designer plugin to the live WordPress.com site over SFTP.
#
# Companion to deploy-theme.sh: same host, same password prompt, same batch
# upload. The plugin ships with the built customer editor in assets/dist, so
# this builds it first (scripts/build-binder-plugin-assets.sh) unless told not
# to. The whole plugin is uploaded every time — it is small (~5 MB with the
# editor and its fonts), and WordPress.com has no shell to diff against.
#
# Usage:
#   scripts/deploy-binder-plugin.sh            # build the editor, upload plugin
#   scripts/deploy-binder-plugin.sh --no-build # upload what is already built
#
# Before running, set your SFTP user once per terminal session:
#   export PRIME_SFTP_USER='...'               # from WP.com → Settings → Hosting Config
#
# After the first upload: WordPress → Plugins → activate "Prime Designer",
# then Settings → Prime Designer for the render service URL and secret, and
# tick the design templates on each product that needs the designer.
set -euo pipefail
trap 'echo "deploy failed at line $LINENO (exit $?)" >&2' ERR

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$ROOT/wp-content/plugins/prime-binder-designer"
REMOTE="/htdocs/wp-content/plugins/prime-binder-designer"
HOST="sftp.wp.com"

USER_NAME="${PRIME_SFTP_USER:-}"
if [ -z "$USER_NAME" ]; then
  echo "Set your SFTP username first:" >&2
  echo "  export PRIME_SFTP_USER='your-sftp-user'" >&2
  echo "(WordPress.com → Settings → Hosting Configuration → SFTP/SSH)" >&2
  exit 1
fi

if [ "${1:-}" != "--no-build" ]; then
  bash "$ROOT/scripts/build-binder-plugin-assets.sh"
  echo
fi

if [ ! -f "$PLUGIN_DIR/assets/dist/index.html" ]; then
  echo "assets/dist/index.html is missing — the editor has not been built." >&2
  echo "Run without --no-build, or scripts/build-binder-plugin-assets.sh first." >&2
  exit 1
fi

cd "$PLUGIN_DIR"

FILES=$(find . -type f ! -name '.DS_Store' ! -name '.gitkeep' | sed 's|^\./||' | sort)
VERSION=$(grep -m1 'PRIME_BINDER_VERSION' prime-binder-designer.php | sed "s/.*'\([0-9.]*\)'.*/\1/")

echo "Plugin version : $VERSION"
echo "Uploading to   : $USER_NAME@$HOST:$REMOTE"
echo
echo "$FILES" | sed 's/^/  /'
echo
if [ "${PRIME_ASSUME_YES:-}" = "1" ]; then
  reply=y   # the GitHub deploy job (.github/workflows/deploy-live.yml) answers for you
else
  read -r -p "Upload these $(echo "$FILES" | wc -l | tr -d ' ') file(s)? [y/N] " reply
fi
[ "$reply" = "y" ] || [ "$reply" = "Y" ] || { echo "Cancelled."; exit 1; }

BATCH=$(mktemp)
trap 'rm -f "$BATCH"' EXIT

# Create any directories the files live in; -mkdir keeps sftp going if one exists.
{
  echo "-mkdir $REMOTE"
  # Parent directories of every file, deepest last. `|| true` keeps pipefail
  # from killing the run when every file sits at the top level.
  DIRS=$(echo "$FILES" | grep '/' | sed 's|/[^/]*$||' | sort -u || true)
  echo "$DIRS" | while read -r dir; do
    [ -n "$dir" ] || continue
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

# Commands go in on stdin rather than via -b: sftp's batch mode switches off
# password prompting, and WordPress.com SFTP is password-only. The password
# prompt comes through the terminal; the "-mkdir" lines ignore "already
# exists", any failed "put" stops the run with a non-zero exit.
if [ -n "${PRIME_SFTP_PASSWORD:-}" ]; then
  # Unattended (the GitHub deploy job): the password comes from an encrypted
  # repository secret through the environment, never the command line.
  SSHPASS="$PRIME_SFTP_PASSWORD" sshpass -e sftp -o StrictHostKeyChecking=accept-new "$USER_NAME@$HOST" < "$BATCH"
else
  echo "Enter the SFTP password when asked (from WP.com → Hosting Configuration)."
  sftp "$USER_NAME@$HOST" < "$BATCH"
fi

echo
echo "Done. Plugin $VERSION is uploaded."
echo "If this was the first upload: WordPress → Plugins → activate Prime Designer."
