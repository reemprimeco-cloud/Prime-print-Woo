#!/usr/bin/env python3
"""
Do two renderings of the same design put things in the same place?
  compare-proofs.py polotno-export.png server-proof.png

Both are scaled to the same width. Features are found by colour so no OCR or
geometry is assumed: the navy background is ignored; white text pixels and the
logo's white ring are located in each picture, and their bounding boxes compared
(as a fraction of the canvas, reported in mm of a 691 mm canvas).
"""
import sys
from PIL import Image

a = Image.open(sys.argv[1]).convert('RGB')
b = Image.open(sys.argv[2]).convert('RGB')
W = 1200
a = a.resize((W, round(a.height * W / a.width)))
b = b.resize((W, round(b.height * W / b.width)))
MM = 691.0 / W


def bbox(im, region):
    px = im.load()
    x0, y0, x1, y1 = region
    xs, ys = [], []
    for y in range(y0, y1, 1):
        for x in range(x0, x1, 1):
            r, g, bl = px[x, y]
            if r > 235 and g > 235 and bl > 235:
                xs.append(x); ys.append(y)
    return (min(xs), min(ys), max(xs), max(ys)) if xs else None


fails = 0
print(f'sizes: polotno {a.size}, server {b.size}')
# Search the whole picture for near-white pixels; the design puts white text and a white-ringed logo on navy.
ba, bb = bbox(a, (0, 0, W, a.height)), bbox(b, (0, 0, W, b.height))
print('white-feature bbox  polotno', ba, ' server', bb)
if not ba or not bb:
    print('FAIL: no white features found'); sys.exit(1)
for name, i in [('left', 0), ('top', 1), ('right', 2), ('bottom', 3)]:
    d = abs(ba[i] - bb[i]) * MM
    ok = d <= 3.0
    fails += 0 if ok else 1
    print(f'  {"ok  " if ok else "FAIL"} {name:6s} differs by {d:5.2f} mm')
sys.exit(1 if fails else 0)
