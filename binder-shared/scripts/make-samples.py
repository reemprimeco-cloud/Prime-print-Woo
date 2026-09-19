#!/usr/bin/env python3
"""
Generate the sample designs and their test images from the spec.json files.

Nothing here is typed in by hand: every position comes from the panels, safe
boxes and canvas size in templates/*-spec.json. Re-run after a spec change:

    python3 binder-shared/scripts/make-samples.py

Outputs (all under binder-shared/samples/):
    *.json          design JSON files (§4.3)
    assets/*.jpg|png  the images they reference (served at /samples/assets/...)
The images are generated, large and reproducible, so assets/ is git-ignored.
"""
import json, os
from PIL import Image, ImageDraw, ImageFont

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
TEMPLATES = os.path.join(ROOT, 'templates')
OUT = os.path.join(ROOT, 'samples')
ASSETS = os.path.join(OUT, 'assets')
os.makedirs(ASSETS, exist_ok=True)

FONT = os.path.expanduser('~/Library/Fonts/Poppins-Bold.ttf')


def load(name):
    with open(os.path.join(TEMPLATES, f'binder-{name}-spec.json')) as f:
        return json.load(f)


def px_per_mm(spec):
    return spec['canvas_with_bleed_px']['w'] / spec['canvas_with_bleed_mm']['w']


def panels(spec):
    b = spec['bleed_mm']
    out = []
    for p in spec['panels_relative_to_trim']:
        t, s = p['trim_mm'], p['safe_mm']
        out.append({
            'name': p['name'],
            'trim': {'x': t['x'] + b, 'y': t['y'] + b, 'w': t['w'], 'h': t['h']},
            'safe': {'x': s['x'] + b, 'y': s['y'] + b, 'w': s['w'], 'h': s['h']},
        })
    return out


def make_background(spec, path):
    """A full-canvas image at the spec's own pixel size, with the geometry drawn on it
    (trim frame, fold lines, safe frames) so the printed PDF can be checked by eye."""
    W, H = spec['canvas_with_bleed_px']['w'], spec['canvas_with_bleed_px']['h']
    k = px_per_mm(spec)
    img = Image.new('RGB', (W, H))
    px = img.load()
    # Diagonal gradient, generated per row-block for speed.
    grad = Image.linear_gradient('L').resize((W, H))
    img = Image.composite(Image.new('RGB', (W, H), (16, 37, 74)), Image.new('RGB', (W, H), (124, 165, 196)), grad.rotate(90, expand=True).resize((W, H)))
    d = ImageDraw.Draw(img)
    b = spec['bleed_mm']
    # Trim frame (red), 0.6 mm wide.
    lw = max(2, int(0.6 * k))
    d.rectangle([b * k, b * k, W - b * k, H - b * k], outline=(230, 40, 60), width=lw)
    for p in panels(spec):
        s = p['safe']
        d.rectangle([s['x'] * k, s['y'] * k, (s['x'] + s['w']) * k, (s['y'] + s['h']) * k], outline=(255, 255, 255), width=lw)
        f = ImageFont.truetype(FONT, int(14 * k))
        d.text(((p['trim']['x'] + p['trim']['w'] / 2) * k, (p['trim']['y'] + p['trim']['h'] / 2) * k), p['name'], fill=(255, 255, 255), font=f, anchor='mm')
    for fx in spec['fold_lines_x_mm_from_trim_left']:
        x = (fx + b) * k
        d.line([x, 0, x, H], fill=(255, 220, 0), width=lw)
    for fy in spec['fold_lines_y_mm_from_trim_top']:
        y = (fy + b) * k
        d.line([0, y, W, y], fill=(255, 220, 0), width=lw)
    img.save(path, 'JPEG', quality=88, subsampling=0)
    return (W, H)


def make_logo(path):
    img = Image.new('RGBA', (1200, 1200), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.ellipse([40, 40, 1160, 1160], fill=(255, 255, 255, 255))
    d.ellipse([140, 140, 1060, 1060], fill=(16, 37, 74, 255))
    f = ImageFont.truetype(FONT, 300)
    d.text((600, 600), 'PRIME', fill=(255, 255, 255, 255), font=f, anchor='mm')
    img.save(path, 'PNG')
    return (1200, 1200)


def make_lowres(spec, path):
    img = Image.new('RGB', (600, 310), (200, 60, 60))
    ImageDraw.Draw(img).text((20, 20), 'LOW RES', fill=(255, 255, 255), font=ImageFont.truetype(FONT, 60))
    img.save(path, 'JPEG', quality=80)
    return (600, 310)


def center(box):
    return box['x'] + box['w'] / 2, box['y'] + box['h'] / 2


def full_image(spec, src, size):
    return {'type': 'image', 'src': src, 'x_mm': 0, 'y_mm': 0,
            'w_mm': spec['canvas_with_bleed_mm']['w'], 'h_mm': spec['canvas_with_bleed_mm']['h'],
            'rotation_deg': 0, 'source_px': {'w': size[0], 'h': size[1]}}


def text(t, box, size_pt, font='Tajawal', weight='700', rtl=True, cmyk=(0, 0, 0, 100), align='center', h=None, rot=0):
    el = {'type': 'text', 'text': t, 'font': font, 'size_pt': size_pt, 'weight': weight,
          'color_cmyk': list(cmyk), 'x_mm': box['x'], 'y_mm': box['y'], 'w_mm': box['w'],
          'align': align, 'rtl': rtl}
    if h is not None:
        el['h_mm'] = h
    if rot:
        el['rotation_deg'] = rot
    return el


def design(spec, mode, elements):
    return {'template': spec['template'], 'mode': mode,
            'canvas_mm': dict(spec['canvas_with_bleed_mm']), 'elements': elements}


def write(name, obj):
    with open(os.path.join(OUT, name), 'w', encoding='utf-8') as f:
        json.dump(obj, f, ensure_ascii=False, indent=2)
    print('wrote', name)


def main():
    outer, inner = load('outer'), load('inner')

    bg_outer = make_background(outer, os.path.join(ASSETS, 'bg-outer.jpg'))
    bg_inner = make_background(inner, os.path.join(ASSETS, 'bg-inner.jpg'))
    logo = make_logo(os.path.join(ASSETS, 'logo.png'))
    low = make_lowres(outer, os.path.join(ASSETS, 'lowres.jpg'))

    op = {p['name']: p for p in panels(outer)}
    ip = {p['name']: p for p in panels(inner)}

    # 1. image only (upload mode): one full-canvas image at the spec pixel size.
    write('outer-image-only.json', design(outer, 'upload', [full_image(outer, '/samples/assets/bg-outer.jpg', bg_outer)]))

    # 2. Arabic text only, on the front cover, no background image.
    fc = op['front_cover']['safe']
    title = {'x': fc['x'], 'y': fc['y'] + fc['h'] * 0.30, 'w': fc['w']}
    sub = {'x': fc['x'], 'y': fc['y'] + fc['h'] * 0.55, 'w': fc['w']}
    latin = {'x': fc['x'], 'y': fc['y'] + fc['h'] * 0.78, 'w': fc['w']}
    write('outer-arabic-text.json', design(outer, 'live', [
        text('الصف السادس - رياضيات', title, 42, 'Tajawal', '700', True),
        text('الاسم: محمد أحمد العتيبي\nالسنة الدراسية ٢٠٢٦ / 2026', sub, 24, 'Tajawal', '500', True),
        text('Mathematics — Grade 6', latin, 20, 'Poppins', '500', False),
    ]))

    # 3. mixed: full-bleed background + logo + Arabic + Latin + rotated spine title.
    sp = op['spine']['safe']
    cx, cy = center(sp)
    spine_len = sp['h']
    logo_size = fc['w'] * 0.25
    mixed = [
        full_image(outer, '/samples/assets/bg-outer.jpg', bg_outer),
        {'type': 'image', 'src': '/samples/assets/logo.png', 'x_mm': fc['x'] + (fc['w'] - logo_size) / 2, 'y_mm': fc['y'] + 10,
         'w_mm': logo_size, 'h_mm': logo_size, 'rotation_deg': 0, 'source_px': {'w': logo[0], 'h': logo[1]}},
        text('الصف السادس - رياضيات', {'x': fc['x'], 'y': fc['y'] + fc['h'] * 0.42, 'w': fc['w']}, 42, 'Tajawal', '700', True, cmyk=(0, 0, 0, 0)),
        text('Prime Printing Co.', {'x': fc['x'], 'y': fc['y'] + fc['h'] * 0.62, 'w': fc['w']}, 22, 'Poppins', '600', False, cmyk=(0, 0, 0, 0)),
        text('سجل ٤ حلقات', {'x': cx - spine_len / 2, 'y': cy - 6, 'w': spine_len}, 26, 'Tajawal', '700', True, cmyk=(0, 0, 0, 0), h=12, rot=90),
    ]
    write('outer-mixed.json', design(outer, 'live', mixed))

    # 3b. live design with a solid CMYK background (rect) + logo + white text — exact colours must survive.
    navy = (100, 70, 20, 40)
    write('outer-rect-bg.json', design(outer, 'live', [
        {'type': 'rect', 'x_mm': 0, 'y_mm': 0, 'w_mm': outer['canvas_with_bleed_mm']['w'], 'h_mm': outer['canvas_with_bleed_mm']['h'], 'color_cmyk': list(navy)},
        {'type': 'image', 'src': '/samples/assets/logo.png', 'x_mm': fc['x'] + (fc['w'] - logo_size) / 2, 'y_mm': fc['y'] + 10,
         'w_mm': logo_size, 'h_mm': logo_size, 'rotation_deg': 0, 'source_px': {'w': logo[0], 'h': logo[1]}},
        text('الصف السادس - رياضيات', {'x': fc['x'], 'y': fc['y'] + fc['h'] * 0.45, 'w': fc['w']}, 42, 'Tajawal', '700', True, cmyk=(0, 0, 0, 0)),
        text('Prime Printing Co.', {'x': fc['x'], 'y': fc['y'] + fc['h'] * 0.65, 'w': fc['w']}, 22, 'Poppins', '600', False, cmyk=(0, 100, 100, 0)),
    ]))

    # 4. deliberately bad: 600 px wide image stretched over the whole canvas (~22 DPI).
    write('outer-lowres.json', design(outer, 'upload', [full_image(outer, '/samples/assets/lowres.jpg', low)]))

    # 5. inner liner (front panel is on the LEFT here): mixed content.
    ifront = ip['inside_front']['safe']
    write('inner-mixed.json', design(inner, 'live', [
        full_image(inner, '/samples/assets/bg-inner.jpg', bg_inner),
        text('هذا السجل ملك للطالب', {'x': ifront['x'], 'y': ifront['y'] + ifront['h'] * 0.4, 'w': ifront['w']}, 30, 'Tajawal', '700', True, cmyk=(0, 0, 0, 0)),
        text('Name: ______________', {'x': ifront['x'], 'y': ifront['y'] + ifront['h'] * 0.6, 'w': ifront['w']}, 18, 'Poppins', '500', False, cmyk=(0, 0, 0, 0)),
    ]))


if __name__ == '__main__':
    main()
