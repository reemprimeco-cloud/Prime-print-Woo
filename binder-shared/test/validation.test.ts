import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
  boundingBox,
  canvasBox,
  effectiveDpi,
  foldLinesInCanvas,
  imageCovers,
  mmToPx,
  panelsInCanvas,
  planColors,
  trimBox,
  validateDesign,
  visibleBox,
  type DesignJSON,
  type ImageElement,
  type RectElement,
  type Spec,
  type TextElement,
} from '../src/index.ts';

const here = dirname(fileURLToPath(import.meta.url));
const load = (n: string): Spec =>
  JSON.parse(readFileSync(join(here, '../templates', `binder-${n}-spec.json`), 'utf8'));
const outer = load('outer');
const inner = load('inner');

// Every number below is derived from the spec files — nothing is typed in by hand.
const fullImage = (spec: Spec, over: Partial<ImageElement> = {}): ImageElement => ({
  type: 'image',
  src: 'https://example.com/a.jpg',
  x_mm: 0,
  y_mm: 0,
  w_mm: spec.canvas_with_bleed_mm.w,
  h_mm: spec.canvas_with_bleed_mm.h,
  source_px: { w: spec.canvas_with_bleed_px.w, h: spec.canvas_with_bleed_px.h },
  ...over,
});

const text = (over: Partial<TextElement> = {}): TextElement => ({
  type: 'text',
  text: 'Hello',
  font: 'Poppins',
  size_pt: 24,
  weight: '700',
  color_cmyk: [0, 0, 0, 100],
  x_mm: 0,
  y_mm: 0,
  w_mm: 100,
  h_mm: 12,
  align: 'left',
  rtl: false,
  ...over,
});

const design = (spec: Spec, elements: DesignJSON['elements'], mode: DesignJSON['mode'] = 'live'): DesignJSON => ({
  template: spec.template,
  mode,
  canvas_mm: { ...spec.canvas_with_bleed_mm },
  elements,
  binding: 'ltr',
});

describe('spec files are internally consistent (guards against a bad regeneration)', () => {
  for (const spec of [outer, inner]) {
    it(`${spec.template}: canvas = trim + 2 x bleed, px = mm/25.4*dpi`, () => {
      expect(spec.canvas_with_bleed_mm.w).toBe(spec.trim_mm.w + 2 * spec.bleed_mm);
      expect(spec.canvas_with_bleed_mm.h).toBe(spec.trim_mm.h + 2 * spec.bleed_mm);
      expect(Math.round(mmToPx(spec.canvas_with_bleed_mm.w, spec.dpi))).toBe(spec.canvas_with_bleed_px.w);
      expect(Math.round(mmToPx(spec.canvas_with_bleed_mm.h, spec.dpi))).toBe(spec.canvas_with_bleed_px.h);
    });

    it(`${spec.template}: panels tile the visible area and safe boxes are inset by safe_margin`, () => {
      const v = visibleBox(spec);
      const panels = panelsInCanvas(spec);
      expect(panels.reduce((n, p) => n + p.trim.w, 0)).toBeCloseTo(v.w, 6);
      panels.forEach((p, i) => {
        expect(p.trim.h).toBeCloseTo(v.h, 6);
        expect(p.safe.x - p.trim.x).toBeCloseTo(spec.safe_margin_mm, 6);
        expect(p.safe.w).toBeCloseTo(p.trim.w - 2 * spec.safe_margin_mm, 6);
        if (i > 0) expect(p.trim.x).toBeCloseTo(panels[i - 1]!.trim.x + panels[i - 1]!.trim.w, 6);
      });
    });

    it(`${spec.template}: fold lines sit on panel edges`, () => {
      const folds = foldLinesInCanvas(spec).x;
      const panels = panelsInCanvas(spec);
      const edges = panels.flatMap((p) => [p.trim.x, p.trim.x + p.trim.w]);
      for (const f of folds) expect(edges.some((e) => Math.abs(e - f) < 1e-6)).toBe(true);
    });
  }

  it('trim box sits one bleed inside the canvas', () => {
    expect(trimBox(outer)).toEqual({ x: outer.bleed_mm, y: outer.bleed_mm, w: outer.trim_mm.w, h: outer.trim_mm.h });
    expect(canvasBox(outer).w).toBe(outer.canvas_with_bleed_mm.w);
  });
});

describe('design shape', () => {
  it('rejects a canvas that does not match the template', () => {
    const d = design(outer, [fullImage(outer)]);
    d.canvas_mm = { w: 100, h: 100 };
    expect(validateDesign(d, outer).errors.map((e) => e.code)).toContain('shape.canvas_mismatch');
  });

  it('rejects a design for the other template', () => {
    expect(validateDesign(design(inner, [fullImage(inner)]), outer).errors.map((e) => e.code)).toContain('shape.template');
  });

  it('requires source_px on images', () => {
    const img = { ...fullImage(outer) } as Partial<ImageElement>;
    delete img.source_px;
    expect(validateDesign(design(outer, [img as ImageElement]), outer).errors.map((e) => e.code)).toContain('shape.source_px');
  });

  it('rejects non-http image sources (file:, javascript:, data:)', () => {
    for (const src of ['file:///etc/passwd', 'javascript:alert(1)', 'data:image/png;base64,AAAA', '//evil.example/x.png']) {
      const r = validateDesign(design(outer, [fullImage(outer, { src })]), outer);
      expect(r.errors.map((e) => e.code)).toContain('shape.src');
    }
  });

  it('rejects unknown fonts, bad weights and out-of-range CMYK', () => {
    const bad = text({ font: 'Comic Sans' as never, weight: 'bold', color_cmyk: [0, 0, 0, 101] });
    const codes = validateDesign(design(outer, [fullImage(outer), bad]), outer).errors.map((e) => e.code);
    expect(codes).toEqual(expect.arrayContaining(['shape.font', 'shape.weight', 'shape.color']));
  });

  it('NaN / Infinity never pass', () => {
    const r = validateDesign(design(outer, [fullImage(outer, { x_mm: Number.NaN })]), outer);
    expect(r.ok).toBe(false);
  });

  it('upload mode is exactly one image', () => {
    expect(validateDesign(design(outer, [fullImage(outer), text()], 'upload'), outer).errors.map((e) => e.code)).toContain('shape.upload_mode');
    expect(validateDesign(design(outer, [text()], 'upload'), outer).errors.map((e) => e.code)).toContain('shape.upload_mode');
  });
});

describe('§4.5.1 resolution', () => {
  it('full-canvas image at the spec pixel size is 300 DPI and clean', () => {
    const img = fullImage(outer);
    expect(effectiveDpi(img)).toBeCloseTo(spec300(outer), 0);
    const r = validateDesign(design(outer, [img]), outer);
    expect(r.ok).toBe(true);
    expect(r.warnings).toEqual([]);
  });

  it('warns under 150 DPI and blocks under 100 DPI', () => {
    const at = (dpi: number) =>
      fullImage(outer, { source_px: { w: Math.round((outer.canvas_with_bleed_mm.w / 25.4) * dpi), h: 1000 } });
    const warn = validateDesign(design(outer, [at(140)]), outer);
    expect(warn.ok).toBe(true);
    expect(warn.warnings.map((w) => w.code)).toContain('dpi.warn');
    const block = validateDesign(design(outer, [at(90)]), outer);
    expect(block.ok).toBe(false);
    expect(block.errors.map((e) => e.code)).toContain('dpi.block');
  });

  it('exactly 150 and exactly 100 are not flagged / not blocked', () => {
    // ceil, not round: a pixel count rounded down would land a hair under the threshold.
    const at = (dpi: number) =>
      fullImage(outer, { source_px: { w: Math.ceil((outer.canvas_with_bleed_mm.w / 25.4) * dpi), h: 1000 } });
    expect(validateDesign(design(outer, [at(150)]), outer).warnings).toEqual([]);
    expect(validateDesign(design(outer, [at(100)]), outer).ok).toBe(true);
  });
});

describe('§4.5.4 turn-in (outer only, hard rule)', () => {
  const v = visibleBox(outer);
  it('blocks text placed inside the turn-in frame', () => {
    const t = text({ x_mm: trimBox(outer).x + 2, y_mm: v.y + 30 }); // 2 mm inside the trim edge, well inside the 15 mm turn-in
    const r = validateDesign(design(outer, [fullImage(outer), t]), outer);
    expect(r.ok).toBe(false);
    expect(r.errors.find((e) => e.code === 'turnin.violation')?.element).toBe(1);
  });

  it('blocks a logo (non-full-bleed image) in the turn-in frame, but never a full-bleed background', () => {
    const logo = fullImage(outer, { x_mm: v.x - 10, y_mm: v.y + 20, w_mm: 30, h_mm: 30, source_px: { w: 3000, h: 3000 } });
    expect(validateDesign(design(outer, [fullImage(outer), logo]), outer).errors.map((e) => e.code)).toContain('turnin.violation');
    expect(validateDesign(design(outer, [fullImage(outer)]), outer).ok).toBe(true);
  });

  it('a rotated element is judged by its rotated bounding box', () => {
    // Unrotated it sits inside the visible area (1 mm from the turn-in line); turned 90 degrees
    // about its centre it becomes wide and crosses that line.
    const t = text({ x_mm: v.x + 1, y_mm: v.y + 40, w_mm: 10, h_mm: 60 });
    expect(validateDesign(design(outer, [fullImage(outer), t]), outer).errors).toEqual([]);
    const turned = { ...t, rotation_deg: 90 };
    expect(boundingBox(turned).x).toBeLessThan(v.x);
    expect(validateDesign(design(outer, [fullImage(outer), turned]), outer).errors.map((e) => e.code)).toContain('turnin.violation');
  });

  it('does not apply to the inner liner (turn_in_mm = 0)', () => {
    const t = text({ x_mm: trimBox(inner).x + 0.5, y_mm: trimBox(inner).y + 20 });
    const r = validateDesign(design(inner, [fullImage(inner), t]), inner);
    expect(r.errors).toEqual([]);
    expect(r.warnings.map((w) => w.code)).toContain('safe.outside'); // still warned
  });
});

describe('§4.5.2 safe margin (warning)', () => {
  it('text fully inside a panel safe box is clean; crossing a panel is warned', () => {
    const [back, spine] = panelsInCanvas(outer);
    const ok = text({ x_mm: back!.safe.x + 5, y_mm: back!.safe.y + 5, w_mm: 50 });
    expect(validateDesign(design(outer, [fullImage(outer), ok]), outer).warnings).toEqual([]);
    const across = text({ x_mm: back!.safe.x + back!.safe.w - 10, y_mm: back!.safe.y + 5, w_mm: 30 });
    expect(spine).toBeTruthy();
    const r = validateDesign(design(outer, [fullImage(outer), across]), outer);
    expect(r.ok).toBe(true);
    expect(r.warnings.map((w) => w.code)).toContain('safe.outside');
  });
});

describe('§4.5.3 empty bleed (warning)', () => {
  it('upload-mode image that covers the trim but not the bleed warns', () => {
    const t = trimBox(outer);
    const img = fullImage(outer, { x_mm: t.x, y_mm: t.y, w_mm: t.w, h_mm: t.h });
    const r = validateDesign(design(outer, [img], 'upload'), outer);
    expect(r.ok).toBe(true);
    expect(r.warnings.map((w) => w.code)).toContain('bleed.empty');
  });

  it('a rotated full-size image leaves corner gaps and warns', () => {
    const img = fullImage(outer, { rotation_deg: 3 });
    expect(imageCovers(img, canvasBox(outer))).toBe(false);
    expect(validateDesign(design(outer, [img]), outer).warnings.map((w) => w.code)).toContain('bleed.empty');
  });

  it('a design with no background image does not warn (white is intended)', () => {
    const [back] = panelsInCanvas(outer);
    const t = text({ x_mm: back!.safe.x + 5, y_mm: back!.safe.y + 5 });
    expect(validateDesign(design(outer, [t]), outer).warnings).toEqual([]);
  });
});

function spec300(spec: Spec): number {
  return spec.dpi;
}

describe('rectangles: solid CMYK fills (background colour)', () => {
  const rect = (spec: Spec, over: Partial<RectElement> = {}): RectElement => ({
    type: 'rect',
    x_mm: 0,
    y_mm: 0,
    w_mm: spec.canvas_with_bleed_mm.w,
    h_mm: spec.canvas_with_bleed_mm.h,
    color_cmyk: [100, 70, 20, 40],
    ...over,
  });

  it('a full-canvas rectangle is a background: clean, and exempt from the turn-in / safe rules', () => {
    const r = validateDesign(design(outer, [rect(outer)]), outer);
    expect(r.errors).toEqual([]);
    expect(r.warnings).toEqual([]);
  });

  it('a rectangle that does not reach the bleed warns, like an image would', () => {
    const t = trimBox(outer);
    const r = validateDesign(design(outer, [rect(outer, { x_mm: t.x, y_mm: t.y, w_mm: t.w, h_mm: t.h })]), outer);
    expect(r.ok).toBe(true);
    expect(r.warnings.map((w) => w.code)).toContain('bleed.empty');
  });

  it('a colour fill (rect) may run through the turn-in: the spine colour wraps behind the board', () => {
    const spine = panelsInCanvas(outer).find((p) => p.name === 'spine')!;
    const fill = rect(outer, { x_mm: spine.trim.x, y_mm: 0, w_mm: spine.trim.w, h_mm: outer.canvas_with_bleed_mm.h });
    const r = validateDesign(design(outer, [rect(outer), fill]), outer);
    expect(r.errors).toEqual([]);
  });

  it('a small drawn shape in the turn-in zone is content, and is blocked', () => {
    const v = visibleBox(outer);
    const star = { type: 'shape' as const, shape: 'star' as const, x_mm: v.x - 8, y_mm: v.y + 30, w_mm: 20, h_mm: 20, color_cmyk: [0, 100, 100, 0] as [number, number, number, number] };
    const r = validateDesign(design(outer, [rect(outer), star]), outer);
    expect(r.errors.map((e) => e.code)).toContain('turnin.violation');
  });

  it('a picture covering one whole panel to the edge is that panel\'s background, not a logo in the turn-in', () => {
    const back = panelsInCanvas(outer).find((p) => p.name === 'back_cover')!;
    const img = { type: 'image' as const, src: 'https://x/a.jpg', x_mm: 0, y_mm: 0, w_mm: back.trim.x + back.trim.w + 2, h_mm: outer.canvas_with_bleed_mm.h, source_px: { w: 4000, h: 4200 } };
    const r = validateDesign(design(outer, [img]), outer);
    expect(r.errors.map((e) => e.code)).not.toContain('turnin.violation');
  });

  it('shapes: kind, colour and opacity are checked', () => {
    const base = { type: 'shape' as const, shape: 'heart' as const, x_mm: 400, y_mm: 60, w_mm: 30, h_mm: 30, color_cmyk: [0, 50, 0, 0] as [number, number, number, number] };
    expect(validateDesign(design(outer, [base]), outer).ok).toBe(true);
    expect(validateDesign(design(outer, [{ ...base, shape: 'blob' } as never]), outer).errors.map((e) => e.code)).toContain('shape.kind');
    expect(validateDesign(design(outer, [{ ...base, opacity: 1.5 }]), outer).errors.map((e) => e.code)).toContain('shape.opacity');
    expect(validateDesign(design(outer, [{ ...base, opacity: 0.4 }]), outer).ok).toBe(true);
  });

  it('shape: bad colour, missing height', () => {
    const bad = { ...rect(outer), color_cmyk: [0, 0, 0, 200] } as unknown as RectElement;
    expect(validateDesign(design(outer, [bad]), outer).errors.map((e) => e.code)).toContain('shape.color');
    const noH = { ...rect(outer) } as Partial<RectElement>;
    delete noH.h_mm;
    expect(validateDesign(design(outer, [noH as RectElement]), outer).errors.map((e) => e.code)).toContain('shape.range');
  });

  it('rectangles are colour-planned exactly like text', () => {
    const d = design(outer, [rect(outer), rect(outer, { color_cmyk: [0, 0, 0, 100] })]);
    const plan = planColors(d);
    expect(plan.entries.map((e) => e.cmyk)).toEqual([[100, 70, 20, 40], [0, 0, 0, 100]]);
    expect(plan.byElement.get(0)).toBe(0);
    expect(plan.byElement.get(1)).toBe(1);
    expect(new Set(plan.entries.map((e) => e.sentinel.join(','))).size).toBe(2);
  });
});
