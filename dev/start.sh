#!/usr/bin/env bash
#
# Starts the real WordPress development site.
#
# WordPress Playground runs genuine PHP 8.2 (compiled to WebAssembly) inside
# Node, with WooCommerce installed and this theme active. It needs no PHP, no
# MySQL and no admin password — but it is a development environment, not a host.
# Production is Cloudways (Phase 11).
#
# Usage:  ./dev/start.sh [port]
set -euo pipefail

PORT="${1:-9400}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ ! -d "$ROOT/node_modules/@wp-playground" ]; then
  echo "Installing WordPress Playground (first run only)…"
  ( cd "$ROOT" && npm install )
fi

echo "Starting WordPress on http://127.0.0.1:$PORT"
echo "  theme    $ROOT/wp-content/themes/prime-printing"
echo "  seeder   $ROOT/dev/mu-plugins  (development data — see the README)"
echo

cd "$ROOT"
exec npx @wp-playground/cli server \
  --port="$PORT" \
  --php=8.2 \
  --blueprint=blueprint.json \
  --mount-before-install="$ROOT/wp-content/themes/prime-printing:/wordpress/wp-content/themes/prime-printing" \
  --mount-before-install="$ROOT/dev/mu-plugins:/wordpress/wp-content/mu-plugins" \
  --login
