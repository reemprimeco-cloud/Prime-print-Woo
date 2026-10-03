/**
 * The custom paper bag (Reem, 2026-10-03): the customer types the bag's
 * width, height and depth; the flat sheet (the dieline) is laid out from
 * those three numbers, and the customer designs on it panel by panel —
 * front, back, the two sides and the base — like a sticker, with a guide
 * saying which panel is which.
 *
 * The flat sheet, left to right (G = glue flap, W = width, D = depth):
 *
 *     ┌─────┬──────────┬─────┬──────────┬─────┐  ─┐
 *     │     │ TOP FOLD (folded inside the bag) │   │ T   top hem
 *     │  G  ├──────────┼─────┼──────────┼─────┤  ─┤
 *     │  L  │  FRONT   │ S   │   BACK   │ S   │   │
 *     │  U  │    W     │ I D │    W     │ I D │   │ H   body
 *     │  E  │          │ D   │          │ D   │   │
 *     │     ├──────────┼─────┼──────────┼─────┤  ─┤
 *     │     │        BASE (bottom flaps)       │   │ B = D/2 + 15
 *     └─────┴──────────┴─────┴──────────┴─────┘  ─┘
 *
 * The sheet is one printed page: the artwork, with bleed on every side, and a
 * dieline the print file carries on its own layer (render service dieline.ts):
 * the cut (outer edge and handle holes) and the creases (every fold). The
 * dieline is never printed; it is what the die-maker and the finisher read.
 *
 * Constants are the shop's standard construction: 3 mm bleed, 5 mm safe zone,
 * 15 mm glue flap, 30 mm top hem, 6 mm handle holes 20 mm below the top
 * edge (mirrored in the hem so they line up once it is folded in).
 */

import { mmToPx } from './geometry.ts';
import { svgPathData, type PathCmd } from './sticker.ts';
import type { BagParams, Box, PanelSpec, Spec } from './types.ts';

export const BAG = {
  bleed_mm: 3,
  safe_margin_mm: 5,
  /** The glue flap on the left edge of the sheet: glued under the right-hand side panel. */
  glue_mm: 15,
  /** The hem at the top of the bag, folded inside. Design here is hidden. */
  top_fold_mm: 30,
  /** The bottom flaps are half the depth plus this overlap. */
  base_extra_mm: 15,
  hole_diameter_mm: 6,
  /** Handle holes this far below the top edge of the finished bag. */
  hole_from_top_mm: 20,
  /** Smallest and largest bag, per dimension, in mm. */
  min_w_mm: 60,
  max_w_mm: 500,
  min_h_mm: 80,
  max_h_mm: 600,
  min_d_mm: 30,
  max_d_mm: 250,
  dpi: 300,
} as const;

/** Panel names on the flat sheet, left to right / top to bottom. */
export const BAG_PANELS = ['glue', 'top_fold', 'front', 'side_a', 'back', 'side_b', 'base'] as const;
export type BagPanel = (typeof BAG_PANELS)[number];

/** Panels the customer never sees on the finished bag: glued under, or folded inside. */
export const BAG_HIDDEN_PANELS: readonly BagPanel[] = ['glue', 'top_fold'];

const round1 = (n: number): number => Math.round(n * 10) / 10;

/**
 * Validate and tidy what a product page (or a saved design) says about the
 * bag. Sizes are rounded to a tenth of a millimetre; out-of-range sizes give
 * null. `w_mm` is the front panel's width, `h_mm` the bag's height, `d_mm`
 * the depth (the side gusset).
 */
export function normalizeBagParams(input: unknown): BagParams | null {
  if (typeof input !== 'object' || input === null) return null;
  const p = input as Record<string, unknown>;
  const w = Number(p.w_mm);
  const h = Number(p.h_mm);
  const d = Number(p.d_mm);
  if (!Number.isFinite(w) || !Number.isFinite(h) || !Number.isFinite(d)) return null;
  const w1 = round1(w);
  const h1 = round1(h);
  const d1 = round1(d);
  if (w1 < BAG.min_w_mm || w1 > BAG.max_w_mm) return null;
  if (h1 < BAG.min_h_mm || h1 > BAG.max_h_mm) return null;
  if (d1 < BAG.min_d_mm || d1 > BAG.max_d_mm) return null;
  return { w_mm: w1, h_mm: h1, d_mm: d1 };
}

export function sameBagParams(a: BagParams, b: BagParams): boolean {
  return Math.abs(a.w_mm - b.w_mm) < 0.05 && Math.abs(a.h_mm - b.h_mm) < 0.05 && Math.abs(a.d_mm - b.d_mm) < 0.05;
}

/** The flat sheet's measurements, in mm relative to the trim box (no bleed). */
export interface BagLayout {
  /** Glue flap width, top hem height, bottom flap height. */
  glue: number;
  top: number;
  base: number;
  /** Trim (flat sheet) size. */
  sheet: { w: number; h: number };
  /** x of each column boundary: glue | front | side_a | back | side_b, then the right edge. */
  columns: { glue: number; front: number; side_a: number; back: number; side_b: number; end: number };
  /** y of each row boundary: top hem | body | base, then the bottom edge. */
  rows: { top: number; body: number; base: number; end: number };
}

export function bagLayout(p: BagParams): BagLayout {
  const { w_mm: w, h_mm: h, d_mm: d } = p;
  const g = BAG.glue_mm;
  const t = BAG.top_fold_mm;
  const b = round1(d / 2 + BAG.base_extra_mm);
  const front = g;
  const side_a = round1(g + w);
  const back = round1(g + w + d);
  const side_b = round1(g + 2 * w + d);
  const end = round1(g + 2 * w + 2 * d);
  return {
    glue: g,
    top: t,
    base: b,
    sheet: { w: end, h: round1(t + h + b) },
    columns: { glue: 0, front, side_a, back, side_b, end },
    rows: { top: 0, body: t, base: round1(t + h), end: round1(t + h + b) },
  };
}

const insetBox = (b: Box, d: number): Box => ({ x: round1(b.x + d), y: round1(b.y + d), w: round1(Math.max(0, b.w - 2 * d)), h: round1(Math.max(0, b.h - 2 * d)) });
const emptySafe = (b: Box): Box => ({ x: round1(b.x + b.w / 2), y: round1(b.y + b.h / 2), w: 0, h: 0 });

/** The Spec for one bag. Pure: the same params always give the same spec. */
export function bagSpec(params: BagParams): Spec {
  const p = { w_mm: params.w_mm, h_mm: params.h_mm, d_mm: params.d_mm };
  const L = bagLayout(p);
  const b = BAG.bleed_mm;
  const s = BAG.safe_margin_mm;
  const { columns: c, rows: r } = L;
  const cw = round1(L.sheet.w + 2 * b);
  const ch = round1(L.sheet.h + 2 * b);
  const bodyH = round1(r.base - r.body);

  const panel = (name: BagPanel, box: Box, hidden = false): PanelSpec => ({
    name,
    trim_mm: box,
    safe_mm: hidden ? emptySafe(box) : insetBox(box, s),
  });

  return {
    template: 'bag',
    unit: 'mm',
    dpi: BAG.dpi,
    color: 'CMYK (FOGRA39 / ISO Coated v2)',
    bleed_mm: b,
    safe_margin_mm: s,
    turn_in_mm: 0,
    trim_mm: { w: L.sheet.w, h: L.sheet.h },
    canvas_with_bleed_mm: { w: cw, h: ch },
    canvas_with_bleed_px: { w: Math.round(mmToPx(cw, BAG.dpi)), h: Math.round(mmToPx(ch, BAG.dpi)) },
    panels_relative_to_trim: [
      panel('glue', { x: 0, y: 0, w: L.glue, h: L.sheet.h }, true),
      panel('top_fold', { x: c.front, y: 0, w: round1(c.end - c.front), h: L.top }, true),
      panel('front', { x: c.front, y: r.body, w: p.w_mm, h: bodyH }),
      panel('side_a', { x: c.side_a, y: r.body, w: p.d_mm, h: bodyH }),
      panel('back', { x: c.back, y: r.body, w: p.w_mm, h: bodyH }),
      panel('side_b', { x: c.side_b, y: r.body, w: p.d_mm, h: bodyH }),
      panel('base', { x: c.front, y: r.base, w: round1(c.end - c.front), h: L.base }),
    ],
    fold_lines_x_mm_from_trim_left: [c.front, c.side_a, round1(c.side_a + p.d_mm / 2), c.back, c.side_b, round1(c.side_b + p.d_mm / 2)],
    fold_lines_y_mm_from_trim_top: [r.body, r.base],
    bag: p,
  };
}

// ---- The dieline ----------------------------------------------------------------------------

/** A straight crease (fold) line, in canvas mm. */
export interface Crease {
  x1: number;
  y1: number;
  x2: number;
  y2: number;
}

export interface BagDieline {
  /** The outer edge of the sheet: what the die cuts. Canvas mm. */
  cut: PathCmd[];
  /** The handle holes, each a closed circle. Canvas mm. */
  holes: PathCmd[][];
  /** Every fold: the panel boundaries, the gusset centre folds, the base diagonals. Canvas mm. */
  creases: Crease[];
}

const KAPPA = 0.5522847498;

function circlePath(cx: number, cy: number, r: number): PathCmd[] {
  const k = r * KAPPA;
  return [
    { c: 'M', x: cx + r, y: cy },
    { c: 'C', x1: cx + r, y1: cy + k, x2: cx + k, y2: cy + r, x: cx, y: cy + r },
    { c: 'C', x1: cx - k, y1: cy + r, x2: cx - r, y2: cy + k, x: cx - r, y: cy },
    { c: 'C', x1: cx - r, y1: cy - k, x2: cx - k, y2: cy - r, x: cx, y: cy - r },
    { c: 'C', x1: cx + k, y1: cy - r, x2: cx + r, y2: cy - k, x: cx + r, y: cy },
    { c: 'Z' },
  ];
}

/**
 * The dieline of a bag spec, in canvas millimetres (the bleed offset is
 * applied). The same geometry draws the editor's guide and the print file's
 * Dieline layer, so the two cannot disagree.
 */
export function bagDieline(spec: Spec): BagDieline {
  const p = spec.bag;
  if (!p) throw new Error('bagDieline: not a bag spec');
  const L = bagLayout(p);
  const o = spec.bleed_mm;
  const { columns: c, rows: r } = L;
  const X = (x: number) => round1(x + o);
  const Y = (y: number) => round1(y + o);

  const cut: PathCmd[] = [
    { c: 'M', x: X(0), y: Y(0) },
    { c: 'L', x: X(c.end), y: Y(0) },
    { c: 'L', x: X(c.end), y: Y(r.end) },
    { c: 'L', x: X(0), y: Y(r.end) },
    { c: 'Z' },
  ];

  // Handle holes: two per face, a quarter of the width either side of the
  // centre, 20 mm below the top edge of the finished bag; and the same holes
  // mirrored about the hem fold so they coincide once the hem is folded in.
  const holes: PathCmd[][] = [];
  const rad = BAG.hole_diameter_mm / 2;
  const below = r.body + BAG.hole_from_top_mm;
  const above = r.body - BAG.hole_from_top_mm;
  for (const x0 of [c.front, c.back]) {
    for (const dx of [p.w_mm / 4, (3 * p.w_mm) / 4]) {
      holes.push(circlePath(X(x0 + dx), Y(below), rad));
      if (above - rad > 0) holes.push(circlePath(X(x0 + dx), Y(above), rad));
    }
  }

  const creases: Crease[] = [];
  for (const x of spec.fold_lines_x_mm_from_trim_left) creases.push({ x1: X(x), y1: Y(0), x2: X(x), y2: Y(r.end) });
  for (const y of spec.fold_lines_y_mm_from_trim_top) creases.push({ x1: X(c.front), y1: Y(y), x2: X(c.end), y2: Y(y) });
  // The base of each side gusset folds in on two diagonals meeting at its centre.
  for (const x0 of [c.side_a, c.side_b]) {
    const mid = x0 + p.d_mm / 2;
    const tip = r.base + p.d_mm / 2;
    creases.push({ x1: X(x0), y1: Y(r.base), x2: X(mid), y2: Y(Math.min(tip, r.end)) });
    creases.push({ x1: X(x0 + p.d_mm), y1: Y(r.base), x2: X(mid), y2: Y(Math.min(tip, r.end)) });
  }

  return { cut, holes, creases };
}

// ---- The guide overlay for the editors --------------------------------------------------------

/** Guide colours: the cut as on the sticker guide, creases in cyan, safe zones grey. */
export const BAG_GUIDES = {
  cut: '#EC008C',
  crease: '#00AEEF',
  safe: '#8A8F98',
  hidden: '#1f2937',
} as const;

/** Default (English) panel labels; the editor passes translated ones. */
export const BAG_LABELS: Record<BagPanel, string> = {
  glue: 'GLUE',
  top_fold: 'TOP FOLD · folded inside',
  front: 'FRONT',
  side_a: 'SIDE',
  back: 'BACK',
  side_b: 'SIDE',
  base: 'BASE',
};

const f = (n: number): string => String(Math.round(n * 1000) / 1000);
const esc = (s: string): string => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/**
 * The guide the editor lays over the sheet: a veil over the bleed, a darker
 * veil over the hidden panels, every crease, the safe zone of every visible
 * panel, the handle holes, the cut, and a label in each panel. Never printed.
 */
export function bagOverlaySvg(spec: Spec, labels: Partial<Record<BagPanel, string>> = {}): string {
  const p = spec.bag;
  if (!p) return '';
  const { w, h } = spec.canvas_with_bleed_mm;
  const die = bagDieline(spec);
  const o = spec.bleed_mm;
  const stroke = Math.max(0.3, Math.min(w, h) / 400);
  const dash = `${f(stroke * 5)} ${f(stroke * 3)}`;
  const names = { ...BAG_LABELS, ...labels };

  let out = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${f(w)} ${f(h)}" width="${f(w)}mm" height="${f(h)}mm">`;
  // What is cut away.
  out += `<path d="M0 0H${f(w)}V${f(h)}H0Z ${svgPathData(die.cut)}" fill="#ffffff" fill-opacity="0.45" fill-rule="evenodd"/>`;

  for (const panel of spec.panels_relative_to_trim) {
    const name = panel.name as BagPanel;
    const t = panel.trim_mm;
    const bx = t.x + o;
    const by = t.y + o;
    const hidden = BAG_HIDDEN_PANELS.includes(name);
    if (hidden) {
      out += `<rect x="${f(bx)}" y="${f(by)}" width="${f(t.w)}" height="${f(t.h)}" fill="${BAG_GUIDES.hidden}" fill-opacity="0.08"/>`;
    } else {
      const s = panel.safe_mm;
      if (s.w > 0 && s.h > 0) {
        out += `<rect x="${f(s.x + o)}" y="${f(s.y + o)}" width="${f(s.w)}" height="${f(s.h)}" fill="none" stroke="${BAG_GUIDES.safe}" stroke-width="${f(stroke)}" stroke-dasharray="${dash}"/>`;
      }
    }
    // The label, sized to the panel; the glue flap reads sideways.
    const label = names[name] ?? name;
    const vertical = name === 'glue';
    const room = vertical ? t.h : t.w;
    const size = Math.max(2.5, Math.min(vertical ? t.w * 0.55 : t.h * 0.35, (room * 1.6) / Math.max(4, label.length)));
    const cx = bx + t.w / 2;
    const cy = by + t.h / 2;
    const transform = vertical ? ` transform="rotate(-90 ${f(cx)} ${f(cy)})"` : '';
    out += `<text x="${f(cx)}" y="${f(cy)}" font-family="Poppins, Arial, sans-serif" font-weight="700" font-size="${f(size)}" fill="${BAG_GUIDES.hidden}" fill-opacity="${hidden ? 0.35 : 0.22}" text-anchor="middle" dominant-baseline="central" letter-spacing="${f(size * 0.08)}"${transform}>${esc(label)}</text>`;
  }

  for (const cr of die.creases) {
    out += `<line x1="${f(cr.x1)}" y1="${f(cr.y1)}" x2="${f(cr.x2)}" y2="${f(cr.y2)}" stroke="${BAG_GUIDES.crease}" stroke-width="${f(stroke)}" stroke-dasharray="${dash}"/>`;
  }
  for (const hole of die.holes) {
    out += `<path d="${svgPathData(hole)}" fill="#ffffff" fill-opacity="0.7" stroke="${BAG_GUIDES.cut}" stroke-width="${f(stroke)}"/>`;
  }
  out += `<path d="${svgPathData(die.cut)}" fill="none" stroke="${BAG_GUIDES.cut}" stroke-width="${f(stroke)}"/>`;
  out += `</svg>`;
  return out;
}

export function bagOverlayDataUrl(spec: Spec, labels: Partial<Record<BagPanel, string>> = {}): string {
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(bagOverlaySvg(spec, labels))}`;
}
