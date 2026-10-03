import { describe, expect, it } from 'vitest';
import {
  BAG,
  bagDieline,
  bagLayout,
  bagOverlaySvg,
  bagSpec,
  normalizeBagParams,
  parametricSpecFromDesign,
  sameBagParams,
  validateDesign,
  withBinding,
  type DesignJSON,
} from '../src/index.ts';

const p = { w_mm: 200, h_mm: 250, d_mm: 80 };

describe('normalizeBagParams', () => {
  it('accepts the calculator values, rounding to 0.1 mm', () => {
    expect(normalizeBagParams({ w_mm: '200', h_mm: 250.04, d_mm: 80 })).toEqual(p);
  });
  it('refuses a missing depth, out-of-range sizes and rubbish', () => {
    expect(normalizeBagParams({ w_mm: 200, h_mm: 250 })).toBeNull();
    expect(normalizeBagParams({ w_mm: BAG.min_w_mm - 1, h_mm: 250, d_mm: 80 })).toBeNull();
    expect(normalizeBagParams({ w_mm: 200, h_mm: BAG.max_h_mm + 1, d_mm: 80 })).toBeNull();
    expect(normalizeBagParams({ w_mm: 200, h_mm: 250, d_mm: BAG.max_d_mm + 1 })).toBeNull();
    expect(normalizeBagParams(null)).toBeNull();
    expect(normalizeBagParams('200x250x80')).toBeNull();
  });
});

describe('bagSpec', () => {
  it('lays the flat sheet out: glue flap, front, side, back, side; hem, body, base', () => {
    const s = bagSpec(p);
    const L = bagLayout(p);
    expect(L.sheet).toEqual({ w: 575, h: 335 }); // 15 + 2*200 + 2*80; 30 + 250 + (40 + 15)
    expect(s.template).toBe('bag');
    expect(s.bleed_mm).toBe(3);
    expect(s.safe_margin_mm).toBe(5);
    expect(s.trim_mm).toEqual({ w: 575, h: 335 });
    expect(s.canvas_with_bleed_mm).toEqual({ w: 581, h: 341 });
    expect(s.canvas_with_bleed_px).toEqual({ w: 6862, h: 4028 });
    expect(s.panels_relative_to_trim.map((q) => q.name)).toEqual(['glue', 'top_fold', 'front', 'side_a', 'back', 'side_b', 'base']);
    const front = s.panels_relative_to_trim.find((q) => q.name === 'front')!;
    expect(front.trim_mm).toEqual({ x: 15, y: 30, w: 200, h: 250 });
    expect(front.safe_mm).toEqual({ x: 20, y: 35, w: 190, h: 240 });
    const back = s.panels_relative_to_trim.find((q) => q.name === 'back')!;
    expect(back.trim_mm).toEqual({ x: 295, y: 30, w: 200, h: 250 });
    const base = s.panels_relative_to_trim.find((q) => q.name === 'base')!;
    expect(base.trim_mm).toEqual({ x: 15, y: 280, w: 560, h: 55 });
    // Hidden panels have no safe area at all: anything placed there warns.
    const glue = s.panels_relative_to_trim.find((q) => q.name === 'glue')!;
    expect(glue.safe_mm.w).toBe(0);
    expect(s.fold_lines_x_mm_from_trim_left).toEqual([15, 215, 255, 295, 495, 535]);
    expect(s.fold_lines_y_mm_from_trim_top).toEqual([30, 280]);
    expect(s.bag).toEqual(p);
  });
  it('is deterministic and ignores binding', () => {
    expect(bagSpec(p)).toEqual(bagSpec({ ...p }));
    expect(sameBagParams(bagSpec(p).bag!, p)).toBe(true);
    expect(withBinding(bagSpec(p), 'rtl')).toEqual(bagSpec(p));
  });
  it('is what parametricSpecFromDesign rebuilds from a design', () => {
    expect(parametricSpecFromDesign('bag', { bag: p })).toEqual(bagSpec(p));
    expect(parametricSpecFromDesign('bag', { sticker: { w_mm: 50, h_mm: 50, shape: 'round' } })).toBeNull();
    expect(parametricSpecFromDesign('sticker', { sticker: { w_mm: 50, h_mm: 50, shape: 'round' } })?.template).toBe('sticker');
  });
});

describe('bagDieline', () => {
  const s = bagSpec(p);
  const die = bagDieline(s);
  it('cuts the sheet outline, offset by the bleed', () => {
    expect(die.cut).toEqual([
      { c: 'M', x: 3, y: 3 },
      { c: 'L', x: 578, y: 3 },
      { c: 'L', x: 578, y: 338 },
      { c: 'L', x: 3, y: 338 },
      { c: 'Z' },
    ]);
  });
  it('punches two handle holes per face, mirrored in the hem', () => {
    expect(die.holes).toHaveLength(8);
    const centres = die.holes.map((h) => h[0]).map((m) => (m.c === 'M' ? { x: m.x - 3, y: m.y } : null));
    // front holes at x = 15 + 50 and 15 + 150 (canvas +3), 20 mm below the hem fold (y = 30 + 20 + 3) and mirrored above it (30 - 20 + 3)
    expect(centres).toContainEqual({ x: 68, y: 53 });
    expect(centres).toContainEqual({ x: 68, y: 13 });
    expect(centres).toContainEqual({ x: 168, y: 53 });
    expect(centres).toContainEqual({ x: 348, y: 53 });
  });
  it('creases every fold plus the base diagonals of both gussets', () => {
    expect(die.creases).toHaveLength(6 + 2 + 4);
    expect(die.creases).toContainEqual({ x1: 18, y1: 3, x2: 18, y2: 338 }); // glue flap fold
    expect(die.creases).toContainEqual({ x1: 18, y1: 283, x2: 578, y2: 283 }); // base fold
    expect(die.creases).toContainEqual({ x1: 218, y1: 283, x2: 258, y2: 323 }); // side_a base diagonal
  });
  it('draws the guide with a label in every panel', () => {
    const svg = bagOverlaySvg(s, { front: 'الواجهة' });
    expect(svg).toContain('>الواجهة<');
    expect(svg).toContain('>BACK<');
    expect(svg).toContain('>GLUE<');
    expect(svg.match(/<line /g)).toHaveLength(12);
    expect(svg).toContain('#EC008C');
  });
});

describe('validation on a bag', () => {
  const s = bagSpec(p);
  const design = (elements: DesignJSON['elements'], extra: Partial<DesignJSON> = {}): DesignJSON => ({
    template: 'bag',
    mode: 'live',
    canvas_mm: { ...s.canvas_with_bleed_mm },
    elements,
    bag: p,
    ...extra,
  });
  const text = (x: number, y: number, w = 100): DesignJSON['elements'][number] => ({
    type: 'text',
    text: 'Prime',
    font: 'Poppins',
    size_pt: 18,
    weight: '700',
    color_cmyk: [0, 0, 0, 100],
    x_mm: x,
    y_mm: y,
    w_mm: w,
    h_mm: 10,
    align: 'center',
    rtl: false,
  });

  it('needs no binding, but must carry the bag size the spec was built for', () => {
    expect(validateDesign(design([]), s).ok).toBe(true);
    const { bag: _b, ...noBag } = design([]);
    expect(validateDesign(noBag, s).errors.map((e) => e.code)).toContain('shape.bag');
    expect(validateDesign(design([], { bag: { ...p, d_mm: 90 } }), s).errors.map((e) => e.code)).toContain('shape.bag');
  });
  it('text on the front, inside its safe zone, is clean; text on the glue flap or the hem warns', () => {
    expect(validateDesign(design([text(60, 150)]), s).warnings).toEqual([]);
    const glue = validateDesign(design([text(4, 150, 10)]), s);
    expect(glue.ok).toBe(true);
    expect(glue.warnings.map((w) => w.code)).toEqual(['bag.hidden']);
    const hem = validateDesign(design([text(100, 10)]), s);
    expect(hem.warnings.map((w) => w.code)).toEqual(['bag.hidden']);
  });
  it('text across the front/side fold warns about the safe zone', () => {
    const r = validateDesign(design([text(180, 150)]), s);
    expect(r.warnings.map((w) => w.code)).toEqual(['safe.outside']);
  });
  it('a background that stops short of the bleed warns', () => {
    const r = validateDesign(design([{ type: 'rect', x_mm: 3, y_mm: 3, w_mm: 575, h_mm: 335, color_cmyk: [0, 0, 0, 10] }]), s);
    expect(r.warnings.map((w) => w.code)).toEqual(['bleed.empty']);
  });
});
