/**
 * Fabric.js <-> design JSON (§4.3), as pure functions with no Fabric import,
 * so they are unit-tested directly.
 *
 * The live editor's canvas works in a fixed unit: one CSS pixel per 1/96 inch,
 * so a length in mm is × 96/25.4 and a font size in pt is × 96/72 (the canvas
 * is then zoomed to fit the screen; zoom never touches these numbers). Every
 * object is positioned by its CENTRE (originX/originY 'center') and turns
 * about that centre, clockwise — the same convention as the design JSON's
 * rotation, so converting is only a units change plus centre <-> top-left.
 *
 * What each element becomes on the canvas:
 *   rect  (background)  a Rect over the whole canvas, not selectable, at the bottom
 *   image               a FabricImage of the proxy bitmap, aspect locked to the original file
 *   text                a Textbox: fixed width, height from its lines, in-canvas editing
 */
import { FONTS, SHAPE_KINDS, WEIGHTS, shapePath, svgPathData, type Cmyk, type DesignElement, type ImageElement, type RectElement, type ShapeElement, type ShapeKind, type Spec, type TextElement } from '@binder/shared';

export const PX_PER_MM = 96 / 25.4;
export const PX_PER_PT = 96 / 72;
/** A font size in pt, as canvas px. */
export const ptToPx = (pt: number): number => pt * PX_PER_PT;

const round = (n: number, places = 3): number => {
  const k = 10 ** places;
  return Math.round(n * k) / k;
};

/** Normalise to (-180, 180]. */
export function normAngle(deg: number): number {
  let a = ((deg % 360) + 360) % 360;
  if (a > 180) a -= 360;
  return round(a, 2);
}

/** First strong directional character decides: Arabic/Hebrew -> right-to-left. */
export function isRtlText(text: string): boolean {
  for (const ch of text) {
    if (/[֐-ࣿיִ-﷿ﹰ-﻿]/.test(ch)) return true;
    if (/[A-Za-zÀ-ɏͰ-ϿЀ-ӿ]/.test(ch)) return false;
  }
  return false;
}

/** What the editor keeps on a Fabric object besides Fabric's own props. */
export interface BinderMeta {
  /** background: whole-sheet colour · spine: the spine's colour, top to bottom · the rest are the customer's items. */
  kind: 'background' | 'spine' | 'image' | 'text' | 'shape';
  /** Shape outline. */
  shape?: ShapeKind;
  /** Ink colour for text and the background rectangle. */
  cmyk?: Cmyk;
  /** Image: the uploaded original (the canvas shows a downsized proxy). */
  src?: string;
  source_px?: { w: number; h: number };
  /** Editor-only: the customer locked it against accidental moves. Not part of the design. */
  locked?: boolean;
}

/** SVG path data for a shape filling a w × h box (canvas px), as a Fabric Path is built from. */
export function shapePathData(shape: ShapeKind, w: number, h: number): string {
  return svgPathData(shapePath(shape, { x: 0, y: 0, w, h }));
}

/** The spine's colour strip: the spine panel's width, the canvas's full height (bleed and turn-in included). */
export function spineBox(spec: Spec): { x: number; y: number; w: number; h: number } | null {
  const p = spec.panels_relative_to_trim.find((q) => q.name === 'spine');
  if (!p) return null;
  return { x: p.trim_mm.x + spec.bleed_mm, y: 0, w: p.trim_mm.w, h: spec.canvas_with_bleed_mm.h };
}

/** The parts of a Fabric object these conversions read. Centre-origin, canvas px. */
export interface FabricLike {
  left: number;
  top: number;
  width: number;
  height: number;
  scaleX: number;
  scaleY: number;
  angle: number;
  opacity?: number;
  binder: BinderMeta;
  // text only
  text?: string;
  fontFamily?: string;
  fontWeight?: string | number;
  fontSize?: number;
  textAlign?: string;
  lineHeight?: number;
}

function weightOf(w: unknown): string {
  const s = String(w ?? '400');
  if (s === 'normal') return '400';
  if (s === 'bold') return '700';
  return (WEIGHTS as readonly string[]).includes(s) ? s : '400';
}

/** One canvas object -> one design element, or null for things that are not part of the design. */
export function objectToElement(o: FabricLike, spec: Spec): DesignElement | null {
  const m = o.binder;
  if (!m) return null;

  if (m.kind === 'background') {
    return {
      type: 'rect',
      x_mm: 0,
      y_mm: 0,
      w_mm: spec.canvas_with_bleed_mm.w,
      h_mm: spec.canvas_with_bleed_mm.h,
      color_cmyk: m.cmyk ?? [0, 0, 0, 0],
    };
  }

  if (m.kind === 'spine') {
    const b = spineBox(spec);
    if (!b) return null;
    return { type: 'rect', x_mm: round(b.x), y_mm: 0, w_mm: round(b.w), h_mm: round(b.h), color_cmyk: m.cmyk ?? [0, 0, 0, 0] };
  }

  const angle = normAngle(o.angle ?? 0);
  const w = (o.width * o.scaleX) / PX_PER_MM;
  const opacity = typeof o.opacity === 'number' && o.opacity < 1 ? { opacity: round(Math.max(0, o.opacity), 2) } : {};

  if (m.kind === 'shape') {
    const h = (o.height * o.scaleY) / PX_PER_MM;
    if (!m.shape || !SHAPE_KINDS.includes(m.shape)) return null;
    const el: ShapeElement = {
      type: 'shape',
      shape: m.shape,
      x_mm: round(o.left / PX_PER_MM - w / 2),
      y_mm: round(o.top / PX_PER_MM - h / 2),
      w_mm: round(w),
      h_mm: round(h),
      ...(angle ? { rotation_deg: angle } : {}),
      color_cmyk: m.cmyk ?? [0, 0, 0, 100],
      ...opacity,
    };
    return el;
  }

  if (m.kind === 'image') {
    if (!m.src || !m.source_px) return null;
    // Height follows the ORIGINAL file's aspect ratio, so no drag can distort it.
    const h = (w * m.source_px.h) / m.source_px.w;
    return {
      type: 'image',
      src: m.src,
      x_mm: round(o.left / PX_PER_MM - w / 2),
      y_mm: round(o.top / PX_PER_MM - h / 2),
      w_mm: round(w),
      h_mm: round(h),
      rotation_deg: angle,
      source_px: { ...m.source_px },
      ...opacity,
    };
  }

  const h = (o.height * o.scaleY) / PX_PER_MM;
  const text = String(o.text ?? '');
  const family = String(o.fontFamily ?? '');
  const align = o.textAlign === 'left' || o.textAlign === 'right' ? o.textAlign : 'center';
  return {
    type: 'text',
    text,
    font: (FONTS as readonly string[]).includes(family) ? (family as TextElement['font']) : 'Poppins',
    size_pt: round((Number(o.fontSize ?? 16) * o.scaleY) / PX_PER_PT, 2),
    weight: weightOf(o.fontWeight),
    color_cmyk: m.cmyk ?? [0, 0, 0, 100],
    x_mm: round(o.left / PX_PER_MM - w / 2),
    y_mm: round(o.top / PX_PER_MM - h / 2),
    w_mm: round(w),
    ...(h > 0 ? { h_mm: round(h) } : {}),
    align,
    rtl: isRtlText(text),
    ...(angle ? { rotation_deg: angle } : {}),
    line_height: typeof o.lineHeight === 'number' ? o.lineHeight : 1.2,
  };
}

/** Canvas objects (bottom to top) -> design elements in the same z-order. */
export function objectsToElements(objects: FabricLike[], spec: Spec): { elements: DesignElement[]; indexOf: number[] } {
  const elements: DesignElement[] = [];
  const indexOf: number[] = []; // element index -> object index
  objects.forEach((o, i) => {
    const el = objectToElement(o, spec);
    if (el) {
      elements.push(el);
      indexOf.push(i);
    }
  });
  return { elements, indexOf };
}

/** Fabric props (centre origin, px) for a text element. Height is left to Fabric: it follows the lines. */
export function textToProps(el: TextElement): { left: number; top: number; width: number; angle: number; fontFamily: string; fontWeight: string; fontSize: number; textAlign: string; lineHeight: number; direction: 'rtl' | 'ltr'; binder: BinderMeta } {
  const w = el.w_mm * PX_PER_MM;
  const h = (el.h_mm ?? estimateHeightMm(el)) * PX_PER_MM;
  return {
    left: el.x_mm * PX_PER_MM + w / 2,
    top: el.y_mm * PX_PER_MM + h / 2,
    width: w,
    angle: el.rotation_deg ?? 0,
    fontFamily: el.font,
    fontWeight: el.weight,
    fontSize: ptToPx(el.size_pt),
    textAlign: el.align,
    lineHeight: el.line_height ?? 1.2,
    direction: el.rtl ? 'rtl' : 'ltr',
    binder: { kind: 'text', cmyk: el.color_cmyk },
  };
}

/** Fabric props for an image element, given the bitmap the canvas will show. */
export function imageToProps(el: ImageElement, bitmap: { width: number; height: number }): { left: number; top: number; scaleX: number; scaleY: number; angle: number; binder: BinderMeta } {
  return {
    left: (el.x_mm + el.w_mm / 2) * PX_PER_MM,
    top: (el.y_mm + el.h_mm / 2) * PX_PER_MM,
    scaleX: (el.w_mm * PX_PER_MM) / bitmap.width,
    scaleY: (el.h_mm * PX_PER_MM) / bitmap.height,
    angle: el.rotation_deg ?? 0,
    binder: { kind: 'image', src: el.src, source_px: { ...el.source_px } },
  };
}

/** Fabric props for a shape element: a Path of the outline in its own box. */
export function shapeToProps(el: ShapeElement): { left: number; top: number; width: number; height: number; angle: number; opacity: number; path: string; binder: BinderMeta } {
  const w = el.w_mm * PX_PER_MM;
  const h = el.h_mm * PX_PER_MM;
  return {
    left: el.x_mm * PX_PER_MM + w / 2,
    top: el.y_mm * PX_PER_MM + h / 2,
    width: w,
    height: h,
    angle: el.rotation_deg ?? 0,
    opacity: el.opacity ?? 1,
    path: shapePathData(el.shape, w, h),
    binder: { kind: 'shape', shape: el.shape, cmyk: el.color_cmyk },
  };
}

/** Is this rect element the spine colour (as opposed to the whole-sheet background)? */
export function isSpineRect(el: RectElement, spec: Spec): boolean {
  const b = spineBox(spec);
  return !!b && Math.abs(el.x_mm - b.x) < 0.05 && Math.abs(el.w_mm - b.w) < 0.05 && el.y_mm < 0.05 && Math.abs(el.h_mm - b.h) < 0.05;
}

export function backgroundProps(spec: Spec, cmyk: Cmyk): { left: number; top: number; width: number; height: number; binder: BinderMeta } {
  const w = spec.canvas_with_bleed_mm.w * PX_PER_MM;
  const h = spec.canvas_with_bleed_mm.h * PX_PER_MM;
  return { left: w / 2, top: h / 2, width: w, height: h, binder: { kind: 'background', cmyk } };
}

export function backgroundOf(elements: DesignElement[]): RectElement | null {
  const first = elements[0];
  return first?.type === 'rect' ? first : null;
}

/** Rough text height when a saved element carries none (the print route measures the real one). */
function estimateHeightMm(el: TextElement): number {
  const em = (el.size_pt * 25.4) / 72;
  const lh = el.line_height ?? 1.2;
  const charsPerLine = Math.max(1, Math.floor(el.w_mm / (em * 0.55)));
  const lines = el.text.split('\n').reduce((n, line) => n + Math.max(1, Math.ceil(line.length / charsPerLine)), 0);
  return lines * em * lh;
}

/** Where a new element lands: the middle of the front panel (or the only panel). */
export function homePanel(spec: Spec): { x: number; y: number; w: number; h: number } {
  const b = spec.bleed_mm;
  const p = spec.panels_relative_to_trim.find((q) => q.name === 'front_cover' || q.name === 'inside_front' || q.name === 'front') ?? spec.panels_relative_to_trim[0]!;
  return { x: p.safe_mm.x + b, y: p.safe_mm.y + b, w: p.safe_mm.w, h: p.safe_mm.h };
}
