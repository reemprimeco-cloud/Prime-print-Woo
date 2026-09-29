#!/usr/bin/env python3
"""
Independent check of a rendered print PDF (does not share code with the pipeline).

    python3 scripts/verify-pdf.py output/outer-arabic-text.cmyk.pdf binder_outer [--k-only] [--png out.png]

Checks, against the spec.json read from disk:
  - exactly one page
  - MediaBox == BleedBox == canvas_with_bleed_mm, TrimBox inset by bleed_mm (tolerance 0.01 mm)
  - every font embedded
  - no RGB or gray colour operators in the page content; only DeviceCMYK
  - every image is CMYK, and its effective resolution
  - ink coverage per separation (Ghostscript inkcov); --k-only asserts C=M=Y=0
"""
import json, os, re, subprocess, sys
import fitz
import pypdf

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
GS = next(p for p in [os.path.expanduser('~/.local/prime-tools/gs/bin/gs'), '/usr/bin/gs', '/usr/local/bin/gs'] if os.path.exists(p))
SPECS = {'binder_outer': 'binder-outer-spec.json', 'binder_inner': 'binder-inner-spec.json'}

pdf_path, template = sys.argv[1], sys.argv[2]
k_only = '--k-only' in sys.argv
expect_cmyk = [sys.argv[i + 1] for i, a in enumerate(sys.argv) if a == '--expect-cmyk']
png = sys.argv[sys.argv.index('--png') + 1] if '--png' in sys.argv else None
rgb_expected = '--rgb' in sys.argv   # proof PDF: RGB is expected, CMYK checks are skipped

# The sticker template has no spec file: pass the spec the design was rendered with (--spec file.json).
spec_path = sys.argv[sys.argv.index('--spec') + 1] if '--spec' in sys.argv else os.path.join(ROOT, '..', 'binder-shared', 'templates', SPECS[template])
spec = json.load(open(spec_path))
mm = lambda pt: pt * 25.4 / 72
fails = 0


def check(cond, label, detail=''):
    global fails
    print(('PASS  ' if cond else 'FAIL  ') + label + (f'   [{detail}]' if detail and not cond else (f'   {detail}' if detail else '')))
    if not cond:
        fails += 1


r = pypdf.PdfReader(pdf_path)
check(len(r.pages) == 1, 'exactly one page', f'{len(r.pages)} pages')
pg = r.pages[0]


def box_mm(name):
    b = pg.get(name)
    return [round(mm(float(x)), 3) for x in b] if b is not None else None


W, H, b = spec['canvas_with_bleed_mm']['w'], spec['canvas_with_bleed_mm']['h'], spec['bleed_mm']
close = lambda a, e: a is not None and all(abs(x - y) <= 0.01 for x, y in zip(a, e))
check(close(box_mm('/MediaBox'), [0, 0, W, H]), f'MediaBox = {W} x {H} mm', str(box_mm('/MediaBox')))
check(close(box_mm('/BleedBox'), [0, 0, W, H]), 'BleedBox = MediaBox', str(box_mm('/BleedBox')))
check(close(box_mm('/TrimBox'), [b, b, W - b, H - b]), f'TrimBox inset {b} mm on every side', str(box_mm('/TrimBox')))

doc = fitz.open(pdf_path)
page = doc[0]

if spec.get('sticker'):
    # The cut line must be there as the CutContour spot colour, overprinting.
    raw = open(pdf_path, 'rb').read()
    check(b'/CutContour' in raw and b'/Separation' in raw, 'cut line drawn in the CutContour spot colour')
    check(b'/OP true' in raw, 'cut line overprints (does not knock out the artwork)')

fonts = list({f[0]: f for f in page.get_fonts(full=True)}.values())   # de-duplicate by object number
for f in fonts:
    data = doc.extract_font(f[0])
    check(bool(data and len(data[3]) > 0), f'font embedded: {f[3]}')
if not fonts:
    print('INFO  no fonts (image-only design)')

xr = page.get_contents()
content = b''.join(doc.xref_stream(x) for x in ([xr] if isinstance(xr, int) else xr)).decode('latin-1')
# Form XObjects too.
for x in range(1, doc.xref_length()):
    try:
        if 'Form' in (doc.xref_get_key(x, 'Subtype')[1] or ''):
            content += doc.xref_stream(x).decode('latin-1')
    except Exception:
        pass
rgb_ops = re.findall(r'(?<![\w/.])(?:-?[\d.]+\s+){3}(?:rg|RG)(?![\w])', content)
gray_ops = re.findall(r'(?<![\w/.])-?[\d.]+\s+(?:g|G)(?![\w])', content)
cmyk_ops = sorted(set(re.findall(r'(?<![\w/.])((?:-?[\d.]+\s+){4}[kK])(?![\w])', content)))
if rgb_expected:
    print('INFO  proof PDF (RGB expected). colour operators:', sorted(set(rgb_ops))[:4])
else:
    check(not rgb_ops, 'no RGB colour operators in page content', str(rgb_ops[:3]))
    check(not gray_ops, 'no DeviceGray colour operators in page content', str(gray_ops[:3]))
    print('INFO  CMYK operators:', cmyk_ops[:6])
    for want in expect_cmyk:
        # Ghostscript stores DeviceCMYK in 16-bit fixed point, so 0.7 comes back as 0.6992: compare to 0.3% ink.
        near = lambda a, b: len(a) == len(b) and all(abs(float(x) - float(y)) <= 0.003 for x, y in zip(a, b))
        check(any(near(op.split()[:4], want.split()[:4]) for op in cmyk_ops), f'CMYK colour present (within 0.3% ink): {want}', str(cmyk_ops[:6]))

all_images = list({i[0]: i for i in page.get_images(full=True)}.values())   # de-duplicate by object number
smask_xrefs = {img[1] for img in all_images if img[1]}   # soft-mask (alpha) images are DeviceGray by design
for img in all_images:
    xref, w, h, cs = img[0], img[2], img[3], img[5]
    if xref in smask_xrefs:
        print(f'INFO  soft mask (alpha) {w}x{h} {cs} — expected to be gray')
        continue
    rects = page.get_image_rects(xref)
    dpi = [round(w / (rc.width / 72), 1) for rc in rects]
    if not rgb_expected:
        check('CMYK' in cs.upper() or cs.upper().startswith('ICC') or cs == 'DeviceCMYK', f'image {w}x{h} is CMYK', cs)
    print(f'INFO  image {w}x{h}px colorspace={cs} effective dpi={dpi}')

if not rgb_expected:
    out = subprocess.run([GS, '-q', '-dBATCH', '-dNOPAUSE', '-sDEVICE=inkcov', '-r72', '-o', '-', pdf_path], capture_output=True, text=True).stdout
    m = re.search(r'([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+CMYK', out)
    if m:
        c, mg, y, k = (float(v) for v in m.groups())
        print(f'INFO  ink coverage  C={c:.5f} M={mg:.5f} Y={y:.5f} K={k:.5f}')
        if k_only:
            check(c == 0 and mg == 0 and y == 0 and k > 0, 'K-only artwork: C, M, Y are exactly zero')

if png:
    page.get_pixmap(dpi=40, alpha=False).save(png)
    print('INFO  preview ->', png)

print(f'\nSUMMARY  {"OK" if fails == 0 else str(fails) + " FAILED"}')
sys.exit(1 if fails else 0)
