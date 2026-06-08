#!/usr/bin/env bash
# scripts/check_css_hygiene.sh
#
# Catch CSS/HTML drift before commit:
#   1. Any hex/rgba literal outside :root in public/assets/style.css
#   2. Any inline style="..." with a hardcoded color in public/*.php or src/*.php
#
# Run from the repo root:    bash scripts/check_css_hygiene.sh
# Exit code:
#   0 — clean
#   1 — issues found

set -u
cd "$(dirname "$0")/.." || exit 2

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
warn()  { printf '\033[33m%s\033[0m\n' "$*"; }

EXIT=0

# 1. Hardcoded colors outside :root in style.css
echo "→ Checking public/assets/style.css for color literals outside :root..."
HEX_HITS=$(awk 'BEGIN{r=0} /^:root/{r=1} r&&/^}/{r=0;next} !r' public/assets/style.css \
  | grep -nE 'rgba?\([0-9]|#[0-9a-fA-F]{3,8}' || true)
if [ -n "$HEX_HITS" ]; then
  red "FAIL: hardcoded color literals found outside :root in style.css"
  echo "$HEX_HITS"
  EXIT=1
else
  green "OK: style.css :root is the only color source."
fi

# 2. Inline style="..." with hardcoded colors in PHP files
echo "→ Checking public/*.php and src/*.php for inline style colors..."
INLINE_HITS=$(grep -rnE 'style="[^"]*(rgba?\([0-9]|#[0-9a-fA-F]{3,8})' public/*.php src/*.php 2>/dev/null || true)
if [ -n "$INLINE_HITS" ]; then
  red "FAIL: inline style= attributes with hardcoded colors"
  echo "$INLINE_HITS"
  EXIT=1
else
  green "OK: no hardcoded colors in inline styles."
fi

# 3. Warning: pages that hand-roll their head/body skeleton instead of using helpers
echo "→ Warning scan: pages that still hand-roll <head>/<body> skeleton..."
LEGACY=$(grep -lE '^<!DOCTYPE html>' public/*.php 2>/dev/null \
  | xargs grep -L 'layout_page_start' 2>/dev/null || true)
if [ -n "$LEGACY" ]; then
  warn "WARN: these pages still hand-roll their skeleton — consider migrating to layout_page_start():"
  printf '  %s\n' $LEGACY
fi

if [ "$EXIT" -eq 0 ]; then
  green "✓ CSS hygiene check passed."
else
  red "✗ CSS hygiene check failed."
fi
exit $EXIT
