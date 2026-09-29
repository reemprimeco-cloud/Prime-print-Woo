/**
 * The sticker template: one shape, cut to size, no folds, no panels.
 *
 * Unlike the binder covers there is no spec.json on disk — the customer picks
 * the size and shape on the product page, so the spec is derived from those
 * three values. Everything that reads a Spec (validation, both editors, the
 * print route, the page boxes) works unchanged; only the geometry is computed.
 *
 * Constants (bleed, safe zone) are the ones on the approved sticker designer
 * mock-up: bleed 1 mm, safe zone 2 mm.
 */

import { mmToPx } from './geometry.ts';
import type { Box, Spec, StickerParams, StickerShape } from './types.ts';

export const STICKER = {
  bleed_mm: 1,
  safe_margin_mm: 2,
  /** Smallest and largest side a sticker may have, in mm. */
  min_mm: 10,
  max_mm: 1000,
  dpi: 300,
} as const;

export const STICKER_SHAPES: readonly StickerShape[] = ['rectangle', 'square', 'round', 'hexagon', 'triangle', 'star', 'heart', 'custom'];

/** Names the product calculators use for the same shapes. */
const SHAPE_ALIASES: Record<string, StickerShape> = {
  circle: 'round',
  oval: 'round',
  ellipse: 'round',
  rect: 'rectangle',
};

const round1 = (n: number): number => Math.round(n * 10) / 10;

/**
 * Validate and tidy what a product page (or a saved design) says about the
 * sticker. Sizes are rounded to a tenth of a millimetre; unknown shapes and
 * out-of-range sizes give null.
 */
export function normalizeStickerParams(input: unknown): StickerParams | null {
  if (typeof input !== 'object' || input === null) return null;
  const p = input as Record<string, unknown>;
  const w = Number(p.w_mm);
  const h = Number(p.h_mm);
  if (!Number.isFinite(w) || !Number.isFinite(h)) return null;
  const w1 = round1(w);
  const h1 = round1(h);
  if (w1 < STICKER.min_mm || h1 < STICKER.min_mm || w1 > STICKER.max_mm || h1 > STICKER.max_mm) return null;

  const raw = String(p.shape ?? 'rectangle').toLowerCase().trim();
  const shape = (SHAPE_ALIASES[raw] ?? raw) as StickerShape;
  if (!STICKER_SHAPES.includes(shape)) return null;

  return { w_mm: w1, h_mm: h1, shape };
}

export function sameStickerParams(a: StickerParams, b: StickerParams): boolean {
  return a.shape === b.shape && Math.abs(a.w_mm - b.w_mm) < 0.05 && Math.abs(a.h_mm - b.h_mm) < 0.05;
}

/** The Spec for one sticker. Pure: the same params always give the same spec. */
export function stickerSpec(params: StickerParams): Spec {
  const { w_mm: w, h_mm: h, shape } = params;
  const b = STICKER.bleed_mm;
  const s = STICKER.safe_margin_mm;
  const cw = round1(w + 2 * b);
  const ch = round1(h + 2 * b);

  return {
    template: 'sticker',
    unit: 'mm',
    dpi: STICKER.dpi,
    color: 'CMYK (FOGRA39 / ISO Coated v2)',
    bleed_mm: b,
    safe_margin_mm: s,
    turn_in_mm: 0,
    trim_mm: { w, h },
    canvas_with_bleed_mm: { w: cw, h: ch },
    canvas_with_bleed_px: { w: Math.round(mmToPx(cw, STICKER.dpi)), h: Math.round(mmToPx(ch, STICKER.dpi)) },
    panels_relative_to_trim: [
      {
        name: 'sticker',
        trim_mm: { x: 0, y: 0, w, h },
        safe_mm: { x: s, y: s, w: round1(Math.max(0, w - 2 * s)), h: round1(Math.max(0, h - 2 * s)) },
      },
    ],
    fold_lines_x_mm_from_trim_left: [],
    fold_lines_y_mm_from_trim_top: [],
    sticker: { w_mm: w, h_mm: h, shape },
  };
}

// ---- Outline geometry -----------------------------------------------------------------------

export type PathCmd =
  | { c: 'M'; x: number; y: number }
  | { c: 'L'; x: number; y: number }
  | { c: 'C'; x1: number; y1: number; x2: number; y2: number; x: number; y: number }
  | { c: 'Z' };

/** Control-point distance for a quarter ellipse drawn as one cubic Bézier. */
const KAPPA = 0.5522847498;

/**
 * The outline of a shape fitted to a box, as path commands in the box's own
 * units. Every shape is closed. "custom" (cut to the artwork by the shop) is
 * drawn as the rectangle the artwork must stay within.
 */
export function shapePath(shape: StickerShape, box: Box): PathCmd[] {
  const { x, y, w, h } = box;
  const cx = x + w / 2;
  const cy = y + h / 2;
  const poly = (pts: Array<[number, number]>): PathCmd[] => [
    { c: 'M', x: pts[0]![0], y: pts[0]![1] },
    ...pts.slice(1).map(([px, py]) => ({ c: 'L' as const, x: px, y: py })),
    { c: 'Z' },
  ];

  switch (shape) {
    case 'round': {
      const rx = w / 2;
      const ry = h / 2;
      const kx = rx * KAPPA;
      const ky = ry * KAPPA;
      return [
        { c: 'M', x: cx + rx, y: cy },
        { c: 'C', x1: cx + rx, y1: cy + ky, x2: cx + kx, y2: cy + ry, x: cx, y: cy + ry },
        { c: 'C', x1: cx - kx, y1: cy + ry, x2: cx - rx, y2: cy + ky, x: cx - rx, y: cy },
        { c: 'C', x1: cx - rx, y1: cy - ky, x2: cx - kx, y2: cy - ry, x: cx, y: cy - ry },
        { c: 'C', x1: cx + kx, y1: cy - ry, x2: cx + rx, y2: cy - ky, x: cx + rx, y: cy },
        { c: 'Z' },
      ];
    }
    case 'hexagon':
      // Flat top and bottom, points left and right.
      return poly([
        [x + w / 4, y],
        [x + (3 * w) / 4, y],
        [x + w, cy],
        [x + (3 * w) / 4, y + h],
        [x + w / 4, y + h],
        [x, cy],
      ]);
    case 'triangle':
      return poly([
        [cx, y],
        [x + w, y + h],
        [x, y + h],
      ]);
    case 'star': {
      // Five points, tip at the top, inner radius the classic 38.2 % of the
      // outer. A star on a unit circle is narrower than the circle and sits
      // high in it, so it is fitted to the box by its own bounding box: the
      // size the customer ordered is the size of the star, not of a circle
      // around it.
      const unit: Array<[number, number]> = [];
      for (let i = 0; i < 10; i++) {
        const a = -Math.PI / 2 + (i * Math.PI) / 5;
        const k = i % 2 === 0 ? 1 : 0.382;
        unit.push([k * Math.cos(a), k * Math.sin(a)]);
      }
      const minX = Math.min(...unit.map((p) => p[0]));
      const maxX = Math.max(...unit.map((p) => p[0]));
      const minY = Math.min(...unit.map((p) => p[1]));
      const maxY = Math.max(...unit.map((p) => p[1]));
      return poly(unit.map(([ux, uy]) => [x + ((ux - minX) / (maxX - minX)) * w, y + ((uy - minY) / (maxY - minY)) * h]));
    }
    case 'heart': {
      // A heart in a unit square, scaled to the box.
      const X = (u: number) => x + u * w;
      const Y = (v: number) => y + v * h;
      return [
        { c: 'M', x: X(0.5), y: Y(1) },
        { c: 'C', x1: X(0.5), y1: Y(1), x2: X(0), y2: Y(0.62), x: X(0), y: Y(0.34) },
        { c: 'C', x1: X(0), y1: Y(0.14), x2: X(0.14), y2: Y(0), x: X(0.3), y: Y(0) },
        { c: 'C', x1: X(0.42), y1: Y(0), x2: X(0.5), y2: Y(0.1), x: X(0.5), y: Y(0.2) },
        { c: 'C', x1: X(0.5), y1: Y(0.1), x2: X(0.58), y2: Y(0), x: X(0.7), y: Y(0) },
        { c: 'C', x1: X(0.86), y1: Y(0), x2: X(1), y2: Y(0.14), x: X(1), y: Y(0.34) },
        { c: 'C', x1: X(1), y1: Y(0.62), x2: X(0.5), y2: Y(1), x: X(0.5), y: Y(1) },
        { c: 'Z' },
      ];
    }
    case 'rectangle':
    case 'square':
    case 'custom':
    default:
      return poly([
        [x, y],
        [x + w, y],
        [x + w, y + h],
        [x, y + h],
      ]);
  }
}

const inset = (b: Box, d: number): Box => ({ x: b.x + d, y: b.y + d, w: Math.max(0, b.w - 2 * d), h: Math.max(0, b.h - 2 * d) });

/** The three guide outlines of a sticker spec, in canvas mm: where it is cut, where bleed ends, where content is safe. */
export function stickerOutlines(spec: Spec): { cut: PathCmd[]; bleed: PathCmd[]; safe: PathCmd[] } {
  const shape = spec.sticker?.shape ?? 'rectangle';
  const trim: Box = { x: spec.bleed_mm, y: spec.bleed_mm, w: spec.trim_mm.w, h: spec.trim_mm.h };
  return {
    cut: shapePath(shape, trim),
    bleed: shapePath(shape, inset(trim, -spec.bleed_mm)),
    safe: shapePath(shape, inset(trim, spec.safe_margin_mm)),
  };
}

const f = (n: number): string => String(Math.round(n * 1000) / 1000);

/** SVG path data for a command list. */
export function svgPathData(cmds: PathCmd[]): string {
  return cmds
    .map((c) => (c.c === 'M' || c.c === 'L' ? `${c.c}${f(c.x)} ${f(c.y)}` : c.c === 'C' ? `C${f(c.x1)} ${f(c.y1)} ${f(c.x2)} ${f(c.y2)} ${f(c.x)} ${f(c.y)}` : 'Z'))
    .join(' ');
}

/** Guide colours, matching the approved sticker designer legend. */
export const STICKER_GUIDES = {
  cut: '#EC008C',
  bleed: '#2A7DE1',
  safe: '#8A8F98',
} as const;

/**
 * The guide overlay for the editors, as an SVG in canvas millimetres: a light
 * veil over the bleed (what is cut away), the cut line, and the safe zone.
 * It replaces the PNG the binder templates ship with; never printed.
 */
export function stickerOverlaySvg(spec: Spec): string {
  const { w, h } = spec.canvas_with_bleed_mm;
  const o = stickerOutlines(spec);
  const stroke = Math.max(0.2, Math.min(w, h) / 250);
  const dash = `${f(stroke * 4)} ${f(stroke * 3)}`;
  return (
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${f(w)} ${f(h)}" width="${f(w)}mm" height="${f(h)}mm">` +
    `<path d="M0 0H${f(w)}V${f(h)}H0Z ${svgPathData(o.cut)}" fill="#ffffff" fill-opacity="0.45" fill-rule="evenodd"/>` +
    `<path d="${svgPathData(o.bleed)}" fill="none" stroke="${STICKER_GUIDES.bleed}" stroke-width="${f(stroke)}" stroke-dasharray="${dash}"/>` +
    `<path d="${svgPathData(o.safe)}" fill="none" stroke="${STICKER_GUIDES.safe}" stroke-width="${f(stroke)}" stroke-dasharray="${dash}"/>` +
    `<path d="${svgPathData(o.cut)}" fill="none" stroke="${STICKER_GUIDES.cut}" stroke-width="${f(stroke)}"/>` +
    `</svg>`
  );
}

export function stickerOverlayDataUrl(spec: Spec): string {
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(stickerOverlaySvg(spec))}`;
}
