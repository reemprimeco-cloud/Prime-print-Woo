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
import { FONTS, WEIGHTS, type Cmyk, type DesignElement, type ImageElement, type RectElement, type Spec, type TextElement } from '@binder/shared';

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
  kind: 'background' | 'image' | 'text';
  /** Ink colour for text and the background rectangle. */
  cmyk?: Cmyk;
  /** Image: the uploaded original (the canvas shows a downsized proxy). */
  src?: string;
  source_px?: { w: number; h: number };
  /** Editor-only: the customer locked it against accidental moves. Not part of the design. */
  locked?: boolean;
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

  const angle = normAngle(o.angle ?? 0);
  const w = (o.width * o.scaleX) / PX_PER_MM;

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
  const p = spec.panels_relative_to_trim.find((q) => q.name === 'front_cover' || q.name === 'inside_front') ?? spec.panels_relative_to_trim[0]!;
  return { x: p.safe_mm.x + b, y: p.safe_mm.y + b, w: p.safe_mm.w, h: p.safe_mm.h };
}
