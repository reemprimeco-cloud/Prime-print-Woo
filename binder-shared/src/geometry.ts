import type { Box, DesignElement, ImageElement, PanelSpec, RectElement, ShapeElement, Spec } from './types.ts';

export const MM_PER_INCH = 25.4;

/** px = mm / 25.4 * dpi (§1). */
export const mmToPx = (mm: number, dpi: number): number => (mm / MM_PER_INCH) * dpi;

/** PDF points (1/72 in). */
export const mmToPt = (mm: number): number => (mm * 72) / MM_PER_INCH;

/** Tolerance for "covers" / "inside" comparisons, in mm. */
export const EPS_MM = 0.05;

/** Full canvas including bleed, in canvas coordinates. */
export function canvasBox(spec: Spec): Box {
  return { x: 0, y: 0, w: spec.canvas_with_bleed_mm.w, h: spec.canvas_with_bleed_mm.h };
}

/** The trim box in canvas coordinates: the canvas inset by the bleed. */
export function trimBox(spec: Spec): Box {
  return { x: spec.bleed_mm, y: spec.bleed_mm, w: spec.trim_mm.w, h: spec.trim_mm.h };
}

/** spec.json boxes are relative to the trim box; shift them into canvas coordinates. */
export function toCanvas(box: Box, spec: Spec): Box {
  return { x: box.x + spec.bleed_mm, y: box.y + spec.bleed_mm, w: box.w, h: box.h };
}

export interface PanelInCanvas {
  name: string;
  trim: Box;
  safe: Box;
}

export function panelsInCanvas(spec: Spec): PanelInCanvas[] {
  return spec.panels_relative_to_trim.map((p: PanelSpec) => ({
    name: p.name,
    trim: toCanvas(p.trim_mm, spec),
    safe: toCanvas(p.safe_mm, spec),
  }));
}

/**
 * The area that stays visible on the finished piece: the trim box inset by the
 * turn-in on every side. With turn_in_mm = 0 (the inner liner) this is the
 * trim box itself.
 */
export function visibleBox(spec: Spec): Box {
  const t = trimBox(spec);
  const k = spec.turn_in_mm;
  return { x: t.x + k, y: t.y + k, w: t.w - 2 * k, h: t.h - 2 * k };
}

export interface FoldLines {
  x: number[];
  y: number[];
}

export function foldLinesInCanvas(spec: Spec): FoldLines {
  return {
    x: spec.fold_lines_x_mm_from_trim_left.map((v) => v + spec.bleed_mm),
    y: spec.fold_lines_y_mm_from_trim_top.map((v) => v + spec.bleed_mm),
  };
}

/** Estimated height of a text element that carries no measured h_mm. */
export function estimateTextHeightMm(el: Extract<DesignElement, { type: 'text' }>): number {
  const em = (el.size_pt * MM_PER_INCH) / 72; // 1 em in mm
  const lh = el.line_height ?? 1.2;
  const charsPerLine = Math.max(1, Math.floor(el.w_mm / (em * 0.55)));
  const lines = el.text
    .split('\n')
    .reduce((n, line) => n + Math.max(1, Math.ceil(line.length / charsPerLine)), 0);
  return lines * em * lh;
}

/** Element box before rotation. */
export function elementBox(el: DesignElement): Box {
  const h = el.type === 'text' ? (el.h_mm ?? estimateTextHeightMm(el)) : el.h_mm;
  return { x: el.x_mm, y: el.y_mm, w: el.w_mm, h };
}

const rad = (deg: number): number => (deg * Math.PI) / 180;

/** Axis-aligned bounding box of an element after rotation about its centre. */
export function boundingBox(el: DesignElement): Box {
  const b = elementBox(el);
  const a = rad(el.rotation_deg ?? 0);
  if (a === 0) return b;
  const cos = Math.abs(Math.cos(a));
  const sin = Math.abs(Math.sin(a));
  const w = b.w * cos + b.h * sin;
  const h = b.w * sin + b.h * cos;
  const cx = b.x + b.w / 2;
  const cy = b.y + b.h / 2;
  return { x: cx - w / 2, y: cy - h / 2, w, h };
}

export function boxInside(inner: Box, outer: Box, eps = EPS_MM): boolean {
  return (
    inner.x >= outer.x - eps &&
    inner.y >= outer.y - eps &&
    inner.x + inner.w <= outer.x + outer.w + eps &&
    inner.y + inner.h <= outer.y + outer.h + eps
  );
}

/**
 * Whether an image or rectangle element fully covers `target`, honouring
 * rotation: every corner of the target must fall inside the (rotated) box.
 */
export function elementCovers(el: ImageElement | RectElement | ShapeElement, target: Box, eps = EPS_MM): boolean {
  const cx = el.x_mm + el.w_mm / 2;
  const cy = el.y_mm + el.h_mm / 2;
  const a = -rad(el.rotation_deg ?? 0);
  const cos = Math.cos(a);
  const sin = Math.sin(a);
  const corners: Array<[number, number]> = [
    [target.x, target.y],
    [target.x + target.w, target.y],
    [target.x, target.y + target.h],
    [target.x + target.w, target.y + target.h],
  ];

  return corners.every(([px, py]) => {
    const dx = px - cx;
    const dy = py - cy;
    const lx = dx * cos - dy * sin; // into the element's own frame
    const ly = dx * sin + dy * cos;
    return Math.abs(lx) <= el.w_mm / 2 + eps && Math.abs(ly) <= el.h_mm / 2 + eps;
  });
}

/** Kept under its earlier name; images and rectangles are treated alike. */
export const imageCovers = elementCovers;

/** Effective print resolution of a placed image (§4.5.1). */
export function effectiveDpi(el: ImageElement): number {
  return el.source_px.w / (el.w_mm / MM_PER_INCH);
}
