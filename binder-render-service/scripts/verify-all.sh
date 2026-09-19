#!/usr/bin/env bash
# Render every sample through the real pipeline, then verify each PDF independently.
# Usage: scripts/verify-all.sh
set -uo pipefail
cd "$(dirname "$0")/.."
FAILS=0
run() { # name template kflag
  local name="$1" tpl="$2" kflag="${3:-}"
  echo "=================== $name"
  npx tsx scripts/export-sample.ts "$name" 2>&1 | grep -E "^rendered|rror" || { echo "FAIL render"; FAILS=$((FAILS+1)); return; }
  python3 scripts/verify-pdf.py "output/$name.cmyk.pdf" "$tpl" $kflag --png "output/$name.cmyk.png" | grep -E "PASS|FAIL|INFO|SUMMARY" || true
  python3 scripts/verify-pdf.py "output/$name.cmyk.pdf" "$tpl" $kflag >/dev/null || FAILS=$((FAILS+1))
  python3 scripts/verify-pdf.py "output/$name.rgb.pdf" "$tpl" --rgb >/dev/null || { echo "FAIL rgb proof"; FAILS=$((FAILS+1)); }
  python3 scripts/verify-geometry.py "output/$name.cmyk.pdf" "$tpl" "../binder-shared/samples/$name.json" | grep -E "FAIL|SUMMARY"
  python3 scripts/verify-geometry.py "output/$name.cmyk.pdf" "$tpl" "../binder-shared/samples/$name.json" >/dev/null || FAILS=$((FAILS+1))
}
run outer-image-only binder_outer
run outer-arabic-text binder_outer --k-only
run outer-mixed binder_outer
run outer-rect-bg binder_outer
run inner-mixed binder_inner
echo
[ "$FAILS" -eq 0 ] && echo "ALL SAMPLES OK" || { echo "$FAILS check(s) FAILED"; exit 1; }
