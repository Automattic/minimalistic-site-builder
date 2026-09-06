"""Compose two full-page screenshots side by side, top-aligned, with labels.

Usage: python3 tests/tools/side_by_side.py left.png "Left label" right.png "Right label" out.png [column width]
"""
import sys
from PIL import Image, ImageDraw

left, left_label, right, right_label, out = sys.argv[1:6]
width = int(sys.argv[6]) if len(sys.argv) > 6 else 720
gutter, header = 24, 48

def column(path):
    img = Image.open(path).convert("RGB")
    scale = width / img.width
    return img.resize((width, round(img.height * scale)), Image.LANCZOS)

a, b = column(left), column(right)
canvas = Image.new("RGB", (width * 2 + gutter, header + max(a.height, b.height)), "white")
canvas.paste(a, (0, header))
canvas.paste(b, (width + gutter, header))
draw = ImageDraw.Draw(canvas)
draw.text((12, 14), left_label, fill="black")
draw.text((width + gutter + 12, 14), right_label, fill="black")
canvas.save(out)
print(out, canvas.size)
