#!/usr/bin/env bash
# Regenerate the labeled test images used by index.html (needs ImageMagick).
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p img
colors=(crimson darkorange goldenrod forestgreen teal navy purple)
labels=(ONE TWO THREE FOUR FIVE SIX SEVEN)
for i in 0 1 2 3 4 5 6; do
  convert -size 900x500 "xc:${colors[$i]}" -gravity center -pointsize 90 -fill white \
    -annotate 0 "IMG ${labels[$i]}" "img/img$i.png"
done
