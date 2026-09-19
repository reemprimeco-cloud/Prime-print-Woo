#!/usr/bin/env python3
"""
Is the artwork where the spec says it is?

The generated background images (binder-shared/scripts/make-samples.py) have a
red frame drawn exactly on the trim edge and yellow lines exactly on the fold
lines, positions computed from spec.json. This rasterises the finished PDF at
600 dpi around those features and measures where they really landed, so a
shift introduced anywhere in the pipeline (viewport rounding, page size
quantisation, the content translation in boxes.ts) shows up as a number.

    python3 scripts/verify-geometry.py output/outer-image-only.cmyk.pdf binder_outer
"""
import json, os, sys
import fitz

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SPECS = {'binder_outer': 'binder-outer-spec.json', 'binder_inner': 'binder-inner-spec.json'}
pdf, template = sys.argv[1], sys.argv[2]
spec = json.load(open(os.path.join(ROOT, '..', 'binder-shared', 'templates', SPECS[template])))
DPI = 600
PX_MM = 25.4 / DPI
TOL_MM = 0.25  # pixel test: bounded by the 300 dpi source image and JPEG edge smoothing
STRUCT_TOL_MM = 0.02  # structural test: exact placement matrices, no pixels involved

doc = fitz.open(pdf)
page = doc[0]
W, H, b = spec['canvas_with_bleed_mm']['w'], spec['canvas_with_bleed_mm']['h'], spec['bleed_mm']
pt = lambda mm: mm * 72 / 25.4
fails = 0


def strip(x0, y0, x1, y1):
    """Rasterise a window given in mm from the top-left of the page."""
    clip = fitz.Rect(pt(x0), pt(y0), pt(x1), pt(y1))
    pix = page.get_pixmap(dpi=DPI, clip=clip, alpha=False)
    return pix, x0, y0


def is_red(r, g, bl):
    return r > 170 and g < 110 and bl < 120


def is_yellow(r, g, bl):
    return r > 200 and g > 170 and bl < 120


def first_last(pix, pred, axis):
    """First and last row/col (mm offsets within the window) containing predicate pixels."""
    hits = []
    w, h, n = pix.width, pix.height, pix.n
    px = pix.samples
    rng = range(w) if axis == 'x' else range(h)
    for i in rng:
        found = False
        other = range(h) if axis == 'x' else range(w)
        for j in other:
            o = (j * w + i) * n if axis == 'x' else (i * w + j) * n
            if pred(px[o], px[o + 1], px[o + 2]):
                found = True
                break
        if found:
            hits.append(i)
    return (hits[0] * PX_MM, (hits[-1] + 1) * PX_MM) if hits else None


def check(name, measured, expected):
    global fails
    ok = measured is not None and abs(measured - expected) <= TOL_MM
    if not ok:
        fails += 1
    print(('PASS  ' if ok else 'FAIL  ') + f'{name}: expected {expected:.3f} mm, measured {measured if measured is None else round(measured, 3)} mm')


# ---- Structural: where the PDF says each image and text box sits ------------------------------------
design = json.load(open(sys.argv[3])) if len(sys.argv) > 3 else None
mmv = lambda v: v * 25.4 / 72


def check_struct(name, measured, expected):
    global fails
    ok = abs(measured - expected) <= STRUCT_TOL_MM
    if not ok:
        fails += 1
    print(('PASS  ' if ok else 'FAIL  ') + f'{name}: expected {expected:.3f} mm, PDF says {measured:.3f} mm')


seen_x = set()
imgs = []
for i in page.get_images(full=True):   # the same image object can be listed once per resource dictionary that references it
    if i[0] not in seen_x:
        seen_x.add(i[0]); imgs.append(i)
smask = {i[1] for i in imgs if i[1]}
placed = [(i, page.get_image_rects(i[0])) for i in imgs if i[0] not in smask]
if design:
    design_images = [e for e in design['elements'] if e['type'] == 'image']
    check_struct('image count', len(placed), len(design_images))
    for e in design_images:
        # Match by source pixel size (the PDF lists images in its own order).
        match = [(img, rects) for img, rects in placed if (img[2], img[3]) == (e['source_px']['w'], e['source_px']['h'])]
        if not match:
            print('FAIL  no image with source size', e['source_px']); fails += 1; continue
        img, rects = match[0]
        r = rects[0]
        if e.get('rotation_deg'):
            print('INFO  rotated image skipped in structural check')
            continue
        check_struct(f"image {img[2]}x{img[3]} left", mmv(r.x0), e['x_mm'])
        check_struct(f"image {img[2]}x{img[3]} top", mmv(r.y0), e['y_mm'])
        check_struct(f"image {img[2]}x{img[3]} width", mmv(r.width), e['w_mm'])
        check_struct(f"image {img[2]}x{img[3]} height", mmv(r.height), e['h_mm'])

    # Unrotated text: every span's left/right edges must sit inside the element's box.
    boxes = [e for e in design['elements'] if e['type'] == 'text' and not e.get('rotation_deg')]
    spans = [sp for bl in page.get_text('dict')['blocks'] for ln in bl.get('lines', []) for sp in ln['spans'] if sp['text'].strip()]
    inside = 0
    # (Vertical placement is not asserted: a span's bbox uses the font's ascent/descent, which
    # legitimately reaches above the CSS line box.)
    spans = [sp for sp in spans if abs(sp.get('dir', (1, 0))[0]) > 0.99] if False else spans
    horiz = []
    for bl in page.get_text('dict')['blocks']:
        for ln in bl.get('lines', []):
            if abs(ln['dir'][0]) > 0.99:
                horiz += [sp for sp in ln['spans'] if sp['text'].strip()]
    spans = horiz
    for sp in spans:
        x0, y0, x1, y1 = (mmv(v) for v in sp['bbox'])
        if any(b_['x_mm'] - 0.5 <= x0 and x1 <= b_['x_mm'] + b_['w_mm'] + 0.5 for b_ in boxes):
            inside += 1
    if boxes:
        ok = inside == len(spans)
        fails += 0 if ok else 1
        print(('PASS  ' if ok else 'FAIL  ') + f'all {len(spans)} text spans lie inside their design boxes horizontally ({inside}/{len(spans)})')

# ---- Pixel: only meaningful when the sample carries the drawn background (trim frame, fold lines) --------------
has_bg = design is None or any(e['type'] == 'image' and abs(e['w_mm'] - W) < 1e-6 and abs(e['h_mm'] - H) < 1e-6 for e in design['elements'])
if not has_bg:
    print('INFO  no full-canvas background image in this design; pixel edge checks skipped')
    print(f'\nSUMMARY  {"OK" if fails == 0 else str(fails) + " FAILED"}')
    sys.exit(1 if fails else 0)

mid_y = H / 2
mid_x = W / 2
win = 12  # mm

# Trim frame: outer edge of the red frame == trim edge, on all four sides.
pix, x0, y0 = strip(b - 2, mid_y - 5, b - 2 + win, mid_y + 5)
fl = first_last(pix, is_red, 'x')
check('left  trim edge', (x0 + fl[0]) if fl else None, b)
pix, x0, y0 = strip(W - b - win + 2, mid_y - 5, W - b + 2, mid_y + 5)
fl = first_last(pix, is_red, 'x')
check('right trim edge', (x0 + fl[1]) if fl else None, W - b)
pix, x0, y0 = strip(mid_x - 5, b - 2, mid_x + 5, b - 2 + win)
fl = first_last(pix, is_red, 'y')
check('top   trim edge', (y0 + fl[0]) if fl else None, b)
pix, x0, y0 = strip(mid_x - 5, H - b - win + 2, mid_x + 5, H - b + 2)
fl = first_last(pix, is_red, 'y')
check('bottom trim edge', (y0 + fl[1]) if fl else None, H - b)

# Fold lines: yellow, centred on (fold + bleed) from the trim-relative spec values.
for fx in spec['fold_lines_x_mm_from_trim_left']:
    cx = fx + b
    pix, x0, y0 = strip(cx - 4, mid_y - 30, cx + 4, mid_y - 20)   # a window clear of the panel labels
    fl = first_last(pix, is_yellow, 'x')
    check(f'fold line x={fx}', (x0 + (fl[0] + fl[1]) / 2) if fl else None, cx)

print(f'\nSUMMARY  {"OK" if fails == 0 else str(fails) + " FAILED"}')
sys.exit(1 if fails else 0)
