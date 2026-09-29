#!/usr/bin/env python3
"""
Generate the binder template package from one set of measurements.

    python3 binder-shared/scripts/make-templates.py

Writes, for each cover, into binder-shared/templates/ (and copies to the
WordPress plugin's templates/ folder):
    binder-<name>-spec.json      the geometry every editor and the renderer reads (§1)
    binder-<name>-template.svg   the guide drawing, real mm units
    binder-<name>-overlay.png    the same drawing rasterised, 2400 px wide, alpha (§4.2)
    binder-<name>-template.pdf   the same drawing as a PDF with TrimBox/BleedBox

Change a number in COVERS below, run this, then regenerate the samples
(make-samples.py) — never edit the generated files by hand.

Requires PyMuPDF (pip install pymupdf) for the PNG and PDF.
"""
import json, os, shutil, subprocess
import fitz  # PyMuPDF

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
OUT = os.path.join(ROOT, 'templates')
PLUGIN = os.path.join(os.path.dirname(ROOT), 'wp-content', 'plugins', 'prime-binder-designer', 'templates')

DPI = 300
OVERLAY_PX = 2400

# ---- The measurements ------------------------------------------------------------------------
# Reem's call (2026-09-29): spine 80 mm. The cover panels keep their width, so
# the flat size grew by 5 mm against the first package (spine 75).
SPINE_MM = int(os.environ.get('BINDER_SPINE_MM', 80))

COVERS = {
    'outer': {
        'template': 'binder_outer',
        'title': 'BINDER OUTER COVER',
        'bleed_mm': 3.0,
        'safe_margin_mm': 5.0,
        'turn_in_mm': 15.0,
        'panel_h_mm': 320,                       # the visible height; the turn-in is added above and below
        'panels': [('back_cover', 'BACK COVER', 290), ('spine', 'SPINE', SPINE_MM), ('front_cover', 'FRONT COVER', 290)],
    },
    'inner': {
        'template': 'binder_inner',
        'title': 'BINDER INNER LINER',
        'bleed_mm': 3.0,
        'safe_margin_mm': 5.0,
        'turn_in_mm': 0.0,
        'panel_h_mm': 310,
        'panels': [('inside_front', 'INSIDE FRONT', 285), ('spine', 'SPINE', SPINE_MM), ('inside_back', 'INSIDE BACK', 285)],
    },
}

COLORS = {'bleed': '#F2836B', 'trim': '#000000', 'fold': '#00AEEF', 'safe': '#EC008C', 'miter': '#F7941D', 'turnin': '#E0E0E0', 'text': '#666666'}


def mm_to_px(mm):
    return round(mm / 25.4 * DPI)


def build_spec(c):
    b, s, t = c['bleed_mm'], c['safe_margin_mm'], c['turn_in_mm']
    trim_w = 2 * t + sum(w for _, _, w in c['panels'])
    trim_h = 2 * t + c['panel_h_mm']
    cw, ch = trim_w + 2 * b, trim_h + 2 * b

    panels, folds_x, x = [], [], t
    for name, _, w in c['panels']:
        panels.append({
            'name': name,
            'trim_mm': {'x': x, 'y': t, 'w': w, 'h': c['panel_h_mm']},
            'safe_mm': {'x': x + s, 'y': t + s, 'w': w - 2 * s, 'h': c['panel_h_mm'] - 2 * s},
        })
        folds_x.append(x)
        x += w
    folds_x.append(x)
    folds_y = [t, t + c['panel_h_mm']]
    if t == 0:
        # No turn-in: the outer edges are the trim itself, not folds.
        folds_x = folds_x[1:-1]
        folds_y = []

    return {
        'template': c['template'],
        'unit': 'mm',
        'dpi': DPI,
        'color': 'CMYK (FOGRA39 / ISO Coated v2)',
        'bleed_mm': b,
        'safe_margin_mm': s,
        'turn_in_mm': t,
        'trim_mm': {'w': trim_w, 'h': trim_h},
        'canvas_with_bleed_mm': {'w': cw, 'h': ch},
        'canvas_with_bleed_px': {'w': mm_to_px(cw), 'h': mm_to_px(ch)},
        'panels_relative_to_trim': panels,
        'fold_lines_x_mm_from_trim_left': folds_x,
        'fold_lines_y_mm_from_trim_top': folds_y,
    }


def build_svg(c, spec):
    b, t = spec['bleed_mm'], spec['turn_in_mm']
    W, H = spec['canvas_with_bleed_mm']['w'], spec['canvas_with_bleed_mm']['h']
    TW, TH = spec['trim_mm']['w'], spec['trim_mm']['h']
    out = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}mm" height="{H}mm" viewBox="0 0 {W} {H}">',
           '<g font-family="DejaVu Sans, Helvetica, Arial, sans-serif">']
    # Bleed veil (outside the trim) and turn-in veil (inside the trim, outside the visible area).
    out.append(f'<path d="M0,0h{W}v{H}h-{W}z M{b},{b}v{TH}h{TW}v-{TH}z" fill="{COLORS["bleed"]}" fill-opacity="0.35" fill-rule="evenodd"/>')
    if t > 0:
        out.append(f'<path d="M{b},{b}h{TW}v{TH}h-{TW}z M{b + t},{b + t}v{TH - 2 * t}h{TW - 2 * t}v-{TH - 2 * t}z" fill="{COLORS["turnin"]}" fill-opacity="0.35" fill-rule="evenodd"/>')
    out.append(f'<rect x="{b}" y="{b}" width="{TW}" height="{TH}" fill="none" stroke="{COLORS["trim"]}" stroke-width="0.35"/>')
    for fx in spec['fold_lines_x_mm_from_trim_left']:
        out.append(f'<line x1="{fx + b}" y1="{b}" x2="{fx + b}" y2="{b + TH}" stroke="{COLORS["fold"]}" stroke-width="0.3" stroke-dasharray="3 1.5"/>')
    for fy in spec['fold_lines_y_mm_from_trim_top']:
        out.append(f'<line x1="{b}" y1="{fy + b}" x2="{b + TW}" y2="{fy + b}" stroke="{COLORS["fold"]}" stroke-width="0.3" stroke-dasharray="3 1.5"/>')
    if t > 0:
        m = t + 11  # corner miter cut: 45° across the turn-in corner
        for (x0, y0, x1, y1) in [(b, b + m, b + m, b), (b + TW, b + m, b + TW - m, b), (b, b + TH - m, b + m, b + TH), (b + TW, b + TH - m, b + TW - m, b + TH)]:
            out.append(f'<line x1="{x0}" y1="{y0}" x2="{x1}" y2="{y1}" stroke="{COLORS["miter"]}" stroke-width="0.3" stroke-dasharray="1.5 1"/>')
    labels = {name: label for name, label, _ in c['panels']}
    for p in spec['panels_relative_to_trim']:
        s = p['safe_mm']
        tr = p['trim_mm']
        x, y, w, h = s['x'] + b, s['y'] + b, s['w'], s['h']
        out.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" fill="none" stroke="{COLORS["safe"]}" stroke-width="0.25" stroke-dasharray="2 1.2"/>')
        cx, cy = x + w / 2, y + h / 2
        if p['name'] == 'spine':
            out.append(f'<text x="{cx}" y="{cy}" font-size="7" font-weight="700" fill="{COLORS["text"]}" fill-opacity="0.55" text-anchor="middle" dominant-baseline="middle" transform="rotate(-90 {cx} {cy})">{labels[p["name"]]}  ·  {tr["w"]} × {tr["h"]} mm</text>')
        else:
            out.append(f'<text x="{cx}" y="{cy - 6}" font-size="11" font-weight="700" fill="{COLORS["text"]}" fill-opacity="0.55" text-anchor="middle" dominant-baseline="middle">{labels[p["name"]]}</text>')
            out.append(f'<text x="{cx}" y="{cy + 6}" font-size="7" font-weight="400" fill="{COLORS["text"]}" fill-opacity="0.55" text-anchor="middle" dominant-baseline="middle">{tr["w"]} × {tr["h"]} mm</text>')
    if t > 0:
        out.append(f'<text x="{W / 2}" y="{b + t / 2 + 1.5}" font-size="5" font-weight="400" fill="{COLORS["text"]}" fill-opacity="0.55" text-anchor="middle" dominant-baseline="middle">TURN-IN {t:g} mm · wraps behind board · background only, no text</text>')
    # Legend, bottom-left of the first panel's safe box.
    first = spec['panels_relative_to_trim'][0]['safe_mm']
    lx, ly = first['x'] + b + 7, first['y'] + b + first['h'] - 27.2
    items = [('bleed', f'Bleed {b:g} mm'), ('trim', 'Trim / cut line'), ('fold', 'Fold line'), ('safe', f'Safe area {spec["safe_margin_mm"]:g} mm')]
    if t > 0:
        items += [('miter', 'Corner miter cut'), ('turnin', 'Turn-in (wrap)')]
    out.append(f'<text x="{lx}" y="{ly - 3.8}" font-size="3.2" fill="{COLORS["text"]}">PRIME PRINTING · {c["title"]} · flat {TW:g} × {TH:g} mm + {b:g} mm bleed · {DPI} DPI · CMYK</text>')
    for i, (key, label) in enumerate(items):
        y = ly + i * 4.2
        out.append(f'<rect x="{lx}" y="{y}" width="6" height="2.6" fill="{COLORS[key]}" stroke="{COLORS[key]}" stroke-width="0.3"/>')
        out.append(f'<text x="{lx + 8}" y="{y + 2.2}" font-size="3.2" fill="{COLORS["text"]}">{label}</text>')
    out.append('</g></svg>')
    return '\n'.join(out)


def render_with_chromium(stem, spec_path):
    """PNG + PDF via Chromium (render-templates.mts), which draws the labels in a real sans
    font. Needs binder-render-service's node_modules (Playwright, pdf-lib)."""
    service = os.path.join(os.path.dirname(ROOT), 'binder-render-service')
    if not os.path.isdir(os.path.join(service, 'node_modules')):
        return False
    r = subprocess.run(['npx', 'tsx', os.path.join(service, 'scripts', 'render-templates.mts'), stem, spec_path], cwd=service)
    return r.returncode == 0


def write_raster_and_pdf(svg, spec, stem):
    """Fallback: MuPDF. Same geometry, but its SVG text falls back to a serif face."""
    W, H, b = spec['canvas_with_bleed_mm']['w'], spec['canvas_with_bleed_mm']['h'], spec['bleed_mm']
    pt = lambda mm: mm * 72 / 25.4
    doc = fitz.open(stream=svg.encode('utf-8'), filetype='svg')
    # Overlay PNG: 2400 px wide, transparent background.
    page = doc[0]
    scale = OVERLAY_PX / page.rect.width
    page.get_pixmap(matrix=fitz.Matrix(scale, scale), alpha=True).save(stem + '-overlay.png')
    # Reference PDF with the page boxes the print files carry.
    pdf = fitz.open('pdf', doc.convert_to_pdf())
    p = pdf[0]
    p.set_mediabox(fitz.Rect(0, 0, pt(W), pt(H)))
    mb = p.mediabox  # read back: PyMuPDF wants the other boxes strictly inside what it stored
    p.set_bleedbox(mb)
    p.set_trimbox(fitz.Rect(mb.x0 + pt(b), mb.y0 + pt(b), mb.x1 - pt(b), mb.y1 - pt(b)))
    pdf.set_metadata({'title': f'Prime Printing {spec["template"]} template', 'producer': 'make-templates.py'})
    pdf.save(stem + '-template.pdf', garbage=4, deflate=True)


def main():
    os.makedirs(OUT, exist_ok=True)
    for name, c in COVERS.items():
        spec = build_spec(c)
        stem = os.path.join(OUT, f'binder-{name}')
        with open(stem + '-spec.json', 'w') as f:
            json.dump(spec, f, indent=2)
        svg = build_svg(c, spec)
        with open(stem + '-template.svg', 'w') as f:
            f.write(svg)
        if not render_with_chromium(stem, stem + '-spec.json'):
            print('  (Chromium renderer unavailable, falling back to MuPDF)')
            write_raster_and_pdf(svg, spec, stem)
        print(f'{name}: trim {spec["trim_mm"]["w"]} x {spec["trim_mm"]["h"]} mm, spine {SPINE_MM} mm, canvas {spec["canvas_with_bleed_px"]["w"]} x {spec["canvas_with_bleed_px"]["h"]} px')
    if os.path.isdir(PLUGIN):
        for f in os.listdir(OUT):
            shutil.copy2(os.path.join(OUT, f), os.path.join(PLUGIN, f))
        print('copied to', PLUGIN)


if __name__ == '__main__':
    main()
