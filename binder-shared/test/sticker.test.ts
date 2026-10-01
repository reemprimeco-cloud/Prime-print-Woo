import { describe, expect, it } from 'vitest';
import {
  normalizeStickerParams,
  sameStickerParams,
  shapePath,
  STICKER,
  STICKER_SHAPES,
  stickerOutlines,
  stickerOverlaySvg,
  stickerSpec,
  svgPathData,
  validateDesign,
  type DesignJSON,
  type PathCmd,
  type StickerShape,
} from '../src/index.ts';

const round = { w_mm: 50, h_mm: 50, shape: 'round' as const };

describe('normalizeStickerParams', () => {
  it('accepts the calculator values (cm converted by the caller), rounding to 0.1 mm', () => {
    expect(normalizeStickerParams({ w_mm: 50, h_mm: 70.04, shape: 'rectangle' })).toEqual({ w_mm: 50, h_mm: 70, shape: 'rectangle' });
    expect(normalizeStickerParams({ w_mm: '50', h_mm: '50', shape: 'Round' })).toEqual(round);
  });
  it('maps the names the calculators use', () => {
    expect(normalizeStickerParams({ w_mm: 50, h_mm: 50, shape: 'circle' })?.shape).toBe('round');
    expect(normalizeStickerParams({ w_mm: 50, h_mm: 50, shape: 'rect' })?.shape).toBe('rectangle');
  });
  it('refuses sizes outside the range, unknown shapes and rubbish', () => {
    expect(normalizeStickerParams({ w_mm: STICKER.min_mm - 1, h_mm: 50, shape: 'round' })).toBeNull();
    expect(normalizeStickerParams({ w_mm: 50, h_mm: STICKER.max_mm + 1, shape: 'round' })).toBeNull();
    expect(normalizeStickerParams({ w_mm: 50, h_mm: 50, shape: 'blob' })).toBeNull();
    expect(normalizeStickerParams({ w_mm: 'x', h_mm: 50, shape: 'round' })).toBeNull();
    expect(normalizeStickerParams(null)).toBeNull();
    expect(normalizeStickerParams('50x50')).toBeNull();
  });
});

describe('stickerSpec', () => {
  it('adds the bleed on every side and keeps the safe zone inside the trim', () => {
    const s = stickerSpec({ w_mm: 50, h_mm: 30, shape: 'rectangle' });
    expect(s.template).toBe('sticker');
    expect(s.bleed_mm).toBe(1);
    expect(s.safe_margin_mm).toBe(2);
    expect(s.turn_in_mm).toBe(0);
    expect(s.trim_mm).toEqual({ w: 50, h: 30 });
    expect(s.canvas_with_bleed_mm).toEqual({ w: 52, h: 32 });
    expect(s.canvas_with_bleed_px).toEqual({ w: 614, h: 378 }); // 52 / 25.4 * 300
    expect(s.panels_relative_to_trim).toHaveLength(1);
    expect(s.panels_relative_to_trim[0]!.safe_mm).toEqual({ x: 2, y: 2, w: 46, h: 26 });
    expect(s.fold_lines_x_mm_from_trim_left).toEqual([]);
    expect(s.sticker).toEqual({ w_mm: 50, h_mm: 30, shape: 'rectangle' });
  });
  it('is deterministic', () => {
    expect(stickerSpec(round)).toEqual(stickerSpec({ ...round }));
    expect(sameStickerParams(stickerSpec(round).sticker!, round)).toBe(true);
  });
});

const bounds = (cmds: PathCmd[]) => {
  const xs: number[] = [];
  const ys: number[] = [];
  for (const c of cmds) {
    if (c.c === 'Z') continue;
    xs.push(c.x);
    ys.push(c.y);
    if (c.c === 'C') xs.push(c.x1, c.x2), ys.push(c.y1, c.y2);
  }
  return { minX: Math.min(...xs), maxX: Math.max(...xs), minY: Math.min(...ys), maxY: Math.max(...ys) };
};

describe('shapePath', () => {
  const box = { x: 1, y: 1, w: 60, h: 40 };
  for (const shape of STICKER_SHAPES) {
    it(`${shape}: closed, starts with a move, stays inside its box`, () => {
      const p = shapePath(shape as StickerShape, box);
      expect(p[0]!.c).toBe('M');
      expect(p[p.length - 1]!.c).toBe('Z');
      const b = bounds(p);
      expect(b.minX).toBeGreaterThanOrEqual(box.x - 1e-9);
      expect(b.maxX).toBeLessThanOrEqual(box.x + box.w + 1e-9);
      expect(b.minY).toBeGreaterThanOrEqual(box.y - 1e-9);
      expect(b.maxY).toBeLessThanOrEqual(box.y + box.h + 1e-9);
      // Every shape touches all four sides of its box: the size the customer ordered is the size cut.
      expect(b.minX).toBeCloseTo(box.x, 6);
      expect(b.maxX).toBeCloseTo(box.x + box.w, 6);
      expect(b.minY).toBeCloseTo(box.y, 6);
      expect(b.maxY).toBeCloseTo(box.y + box.h, 6);
    });
  }
  it('a rectangle is the box itself', () => {
    expect(svgPathData(shapePath('rectangle', box))).toBe('M1 1 L61 1 L61 41 L1 41 Z');
  });
});

describe('stickerOutlines and overlay', () => {
  it('cut on the trim, bleed on the canvas edge, safe inset by the safe margin', () => {
    const s = stickerSpec({ w_mm: 50, h_mm: 30, shape: 'rectangle' });
    const o = stickerOutlines(s);
    expect(bounds(o.cut)).toEqual({ minX: 1, maxX: 51, minY: 1, maxY: 31 });
    expect(bounds(o.bleed)).toEqual({ minX: 0, maxX: 52, minY: 0, maxY: 32 });
    expect(bounds(o.safe)).toEqual({ minX: 3, maxX: 49, minY: 3, maxY: 29 });
  });
  it('the overlay is an SVG in canvas millimetres with the three guides', () => {
    const svg = stickerOverlaySvg(stickerSpec(round));
    expect(svg.startsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52"')).toBe(true);
    expect(svg).toContain('#EC008C'); // cut
    expect(svg).toContain('#2A7DE1'); // bleed
    expect(svg).toContain('#8A8F98'); // safe
    expect((svg.match(/<path /g) ?? []).length).toBe(4); // veil + 3 guides
  });
});

describe('validation on the sticker template', () => {
  const spec = stickerSpec(round);
  const image = { type: 'image' as const, src: 'https://example.com/a.jpg', x_mm: 0, y_mm: 0, w_mm: 52, h_mm: 52, source_px: { w: 1200, h: 1200 } };
  const design = (over: Partial<DesignJSON> = {}): DesignJSON => ({ template: 'sticker', mode: 'upload', canvas_mm: { w: 52, h: 52 }, elements: [image], sticker: round, ...over });

  it('a full-bleed upload passes', () => {
    const r = validateDesign(design(), spec);
    expect(r.ok).toBe(true);
    expect(r.warnings).toEqual([]);
  });
  it('the design must say which sticker it is for, and it must match', () => {
    expect(validateDesign(design({ sticker: undefined }), spec).errors.map((e) => e.code)).toContain('shape.sticker');
    expect(validateDesign(design({ sticker: { ...round, shape: 'star' } }), spec).errors.map((e) => e.code)).toContain('shape.sticker');
    expect(validateDesign(design({ sticker: { ...round, w_mm: 60 }, canvas_mm: { w: 62, h: 52 } }), spec).ok).toBe(false);
  });
  it('the turn-in rule never fires (no wrap-around on a sticker); the safe-zone warning does', () => {
    const logo = { ...image, x_mm: 1.5, y_mm: 20, w_mm: 20, h_mm: 20, source_px: { w: 600, h: 600 } };
    const r = validateDesign(design({ mode: 'live', elements: [logo] }), spec);
    expect(r.ok).toBe(true);
    expect(r.errors.map((e) => e.code)).not.toContain('turnin.violation');
    expect(r.warnings.map((w) => w.code)).toContain('safe.outside');
  });
});

describe('uvdtf (UV DTF transfer)', async () => {
  const { normalizeUvdtfParams, uvdtfSpec, UVDTF, isParametricTemplate } = await import('../src/index.ts');

  it('is a rectangle of the typed size, whatever shape was sent', () => {
    expect(normalizeUvdtfParams({ w_mm: 50, h_mm: 100.04, shape: 'star' })).toEqual({ w_mm: 50, h_mm: 100, shape: 'rectangle' });
    expect(normalizeUvdtfParams({ w_mm: UVDTF.min_mm - 1, h_mm: 50 })).toBeNull();
    expect(normalizeUvdtfParams({ w_mm: 50, h_mm: UVDTF.max_mm + 1 })).toBeNull();
    expect(isParametricTemplate('uvdtf')).toBe(true);
    expect(isParametricTemplate('binder_outer')).toBe(false);
  });

  it('has no bleed and no safe margin: the artboard is the trim', () => {
    const s = uvdtfSpec({ w_mm: 50, h_mm: 100, shape: 'rectangle' });
    expect(s.template).toBe('uvdtf');
    expect(s.bleed_mm).toBe(0);
    expect(s.canvas_with_bleed_mm).toEqual({ w: 50, h: 100 });
    expect(s.canvas_with_bleed_px).toEqual({ w: 591, h: 1181 });
    expect(s.panels_relative_to_trim[0]!.safe_mm).toEqual({ x: 0, y: 0, w: 50, h: 100 });
  });

  it('refuses fills and shapes, accepts text and images, and reports the size it is for', () => {
    const s = uvdtfSpec({ w_mm: 50, h_mm: 50, shape: 'rectangle' });
    const base: DesignJSON = { template: 'uvdtf', mode: 'live', canvas_mm: { w: 50, h: 50 }, sticker: { w_mm: 50, h_mm: 50, shape: 'rectangle' }, elements: [] };
    const text = { type: 'text' as const, text: 'Hi', font: 'Poppins' as const, size_pt: 24, weight: '700', color_cmyk: [0, 0, 0, 100] as [number, number, number, number], x_mm: 5, y_mm: 5, w_mm: 40, align: 'center' as const, rtl: false };
    expect(validateDesign({ ...base, elements: [text] }, s).ok).toBe(true);
    const r = validateDesign({ ...base, elements: [{ type: 'rect', x_mm: 0, y_mm: 0, w_mm: 50, h_mm: 50, color_cmyk: [0, 0, 0, 100] }] }, s);
    expect(r.ok).toBe(false);
    expect(r.errors[0]!.code).toBe('shape.uvdtf_element');
    expect(validateDesign({ ...base, sticker: { w_mm: 60, h_mm: 50, shape: 'rectangle' }, elements: [text] }, s).errors[0]!.code).toBe('shape.sticker');
  });
});
