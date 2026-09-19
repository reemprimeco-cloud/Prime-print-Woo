#!/usr/bin/env bash
# Run a binder plugin test file inside a throwaway WordPress Playground.
# Usage: dev/binder-tests/run.sh step1
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
NAME="${1:?usage: run.sh <test-name, e.g. step1>}"
BP="$(mktemp -t binder-bp).json"

python3 - "$BP" "$NAME" <<'PY'
import json, sys
bp, name = sys.argv[1], sys.argv[2]
json.dump({
  "preferredVersions": {"php": "8.2", "wp": "latest"},
  "features": {"networking": True},
  "steps": [{"step": "runPHP", "code": "<?php $GLOBALS['BINDER_TEST']='%s'; require '/binder-tests/_runner.php';" % name}],
}, open(bp, "w"))
PY

rm -f "$ROOT/dev/binder-tests/out-${NAME}.txt"
cd "$ROOT"
npx @wp-playground/cli run-blueprint --blueprint="$BP" \
  --mount-before-install="$ROOT/wp-content/plugins/prime-binder-designer:/wordpress/wp-content/plugins/prime-binder-designer" \
  --mount-before-install="$ROOT/dev/binder-tests:/binder-tests" > "$ROOT/dev/binder-tests/last-run.log" 2>&1
rm -f "$BP"
echo "----- results -----"
cat "$ROOT/dev/binder-tests/out-${NAME}.txt" 2>/dev/null || { echo "(no result file was written — the test did not run)"; tail -20 "$ROOT/dev/binder-tests/last-run.log"; }
