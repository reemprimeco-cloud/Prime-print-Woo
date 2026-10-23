#!/usr/bin/env bash
#
# Upload the PRIME Image Lab plugin to the live WordPress.com site over SFTP.
#
# Companion to deploy-binder-plugin.sh: same host, same batch upload. There is
# nothing to build: the plugin is plain PHP, CSS and JavaScript (a few files,
# well under 100 KB), uploaded whole each time.
#
# Usage:
#   scripts/deploy-image-lab.sh
#
# Before running, set your SFTP user once per terminal session:
#   export PRIME_SFTP_USER='...'               # from WP.com → Settings → Hosting Config
#
# After the first upload: WordPress → Plugins → activate "PRIME Image Lab",
# then add the shortcode [prime_image_lab] to a page with the slug image-lab.
# Rollback: deactivate the plugin (the page then shows no tool), or delete
# wp-content/plugins/prime-image-lab over SFTP.
set -euo pipefail
trap 'echo "deploy failed at line $LINENO (exit $?)" >&2' ERR

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$ROOT/wp-content/plugins/prime-image-lab"
REMOTE="/htdocs/wp-content/plugins/prime-image-lab"
HOST="sftp.wp.com"

USER_NAME="${PRIME_SFTP_USER:-}"
if [ -z "$USER_NAME" ]; then
  echo "Set your SFTP username first:" >&2
  echo "  export PRIME_SFTP_USER='your-sftp-user'" >&2
  echo "(WordPress.com → Settings → Hosting Configuration → SFTP/SSH)" >&2
  exit 1
fi

cd "$PLUGIN_DIR"

FILES=$(find . -type f ! -name '.DS_Store' ! -name '.gitkeep' | sed 's|^\./||' | sort)
VERSION=$(grep -m1 'PRIME_IMAGE_LAB_VERSION' prime-image-lab.php | sed "s/.*'\([0-9.]*\)'.*/\1/")

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
echo "If this was the first upload: WordPress → Plugins → activate PRIME Image Lab."
