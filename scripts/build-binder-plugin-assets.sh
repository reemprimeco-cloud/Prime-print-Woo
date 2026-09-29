#!/usr/bin/env bash
# Build the customer editor and copy it into the WordPress plugin, ready to upload.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/binder-editor"
npm run build:editor
DEST="$ROOT/wp-content/plugins/prime-binder-designer/assets/dist"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -R dist-editor/. "$DEST/"
echo "Editor copied to $DEST"
du -sh "$DEST"
