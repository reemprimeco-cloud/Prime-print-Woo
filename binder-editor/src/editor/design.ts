/**
 * Pure geometry for the upload-mode editor: converting between the design JSON
 * (millimetres, canvas coordinates, top-left of the unrotated box, rotation
 * clockwise about the centre — see @binder/shared types.ts) and the Fabric
 * canvas (pixels, objects positioned by their centre).
 *
 * No Fabric or DOM here, so it is unit-tested directly.
 */
import type { ImageElement, Spec } from '@binder/shared';

export interface FabricLike {
  /** Centre of the object, canvas pixels. */
  left: number;
  top: number;
  /** Unscaled size of the displayed bitmap, pixels. */
  width: number;
  height: number;
  scaleX: number;
  scaleY: number;
  /** Degrees, clockwise. */
  angle: number;
}

const round = (n: number, places = 3): number => {
  const k = 10 ** places;
  return Math.round(n * k) / k;
};

/** Normalise to (-180, 180] and snap to 0 / 90 / 180 / -90 within a degree and a half. */
export function normalizeAngle(deg: number): number {
  let a = ((deg % 360) + 360) % 360;
  if (a > 180) a -= 360;
  for (const target of [0, 90, 180, -90, -180]) {
    if (Math.abs(a - target) < 1.5) a = target === -180 ? 180 : target;
  }
  return round(a, 2);
}

export interface ImageSource {
  src: string;
  source_px: { w: number; h: number };
}

/**
 * The Fabric object -> the design element. Height is derived from the width
 * and the ORIGINAL image's aspect ratio, so repeated drags cannot let the two
 * drift apart; the displayed bitmap may be a downsized proxy.
 */
export function fabricToElement(o: FabricLike, source: ImageSource, pxPerMm: number): ImageElement {
  const w = (o.width * o.scaleX) / pxPerMm;
  const h = (w * source.source_px.h) / source.source_px.w;
  const cx = o.left / pxPerMm;
  const cy = o.top / pxPerMm;

  return {
    type: 'image',
    src: source.src,
    x_mm: round(cx - w / 2),
    y_mm: round(cy - h / 2),
    w_mm: round(w),
    h_mm: round(h),
    rotation_deg: normalizeAngle(o.angle),
    source_px: { ...source.source_px },
  };
}

/** The design element -> Fabric properties, for a bitmap of the given displayed size. */
export function elementToFabric(
  el: ImageElement,
  bitmap: { width: number; height: number },
  pxPerMm: number,
): FabricLike {
  return {
    left: (el.x_mm + el.w_mm / 2) * pxPerMm,
    top: (el.y_mm + el.h_mm / 2) * pxPerMm,
    width: bitmap.width,
    height: bitmap.height,
    scaleX: (el.w_mm * pxPerMm) / bitmap.width,
    scaleY: (el.h_mm * pxPerMm) / bitmap.height,
    angle: el.rotation_deg ?? 0,
  };
}

/** Width the image must have to cover the canvas (unrotated). */
export function coverWidthMm(spec: Spec, source_px: { w: number; h: number }): number {
  const { w, h } = spec.canvas_with_bleed_mm;
  const scale = Math.max(w / source_px.w, h / source_px.h);
  return source_px.w * scale;
}

/** An element of the given width, centred on the canvas centre (or on `about`). */
export function centeredElement(
  spec: Spec,
  source: ImageSource,
  widthMm: number,
  rotation = 0,
  about?: { x: number; y: number },
): ImageElement {
  const { w: cw, h: ch } = spec.canvas_with_bleed_mm;
  const h = (widthMm * source.source_px.h) / source.source_px.w;
  const cx = about?.x ?? cw / 2;
  const cy = about?.y ?? ch / 2;

  return {
    type: 'image',
    src: source.src,
    x_mm: round(cx - widthMm / 2),
    y_mm: round(cy - h / 2),
    w_mm: round(widthMm),
    h_mm: round(h),
    rotation_deg: rotation,
    source_px: { ...source.source_px },
  };
}

/** "Fill": cover the whole canvas, bleed included. */
export function fillElement(spec: Spec, source: ImageSource, rotation = 0): ImageElement {
  // A quarter turn swaps which side must reach which edge.
  const turned = Math.abs(rotation) === 90;
  const sp = turned ? { w: source.source_px.h, h: source.source_px.w } : source.source_px;
  const width = coverWidthMm(spec, sp) * (turned ? source.source_px.w / source.source_px.h : 1);
  return centeredElement(spec, source, width, rotation);
}

/** "Fit": the whole image visible inside the canvas. */
export function fitElement(spec: Spec, source: ImageSource, rotation = 0): ImageElement {
  const { w: cw, h: ch } = spec.canvas_with_bleed_mm;
  const turned = Math.abs(rotation) === 90;
  const iw = turned ? source.source_px.h : source.source_px.w;
  const ih = turned ? source.source_px.w : source.source_px.h;
  const scale = Math.min(cw / iw, ch / ih);
  // Width of the unrotated image = scale * its own pixel width
  return centeredElement(spec, source, source.source_px.w * scale, rotation);
}

/** Change size by a factor about the element's own centre. */
export function scaledElement(el: ImageElement, factor: number): ImageElement {
  const cx = el.x_mm + el.w_mm / 2;
  const cy = el.y_mm + el.h_mm / 2;
  const w = el.w_mm * factor;
  const h = el.h_mm * factor;
  return { ...el, x_mm: round(cx - w / 2), y_mm: round(cy - h / 2), w_mm: round(w), h_mm: round(h) };
}

export function rotatedElement(el: ImageElement, byDeg: number): ImageElement {
  return { ...el, rotation_deg: normalizeAngle((el.rotation_deg ?? 0) + byDeg) };
}

export function movedElement(el: ImageElement, to: { x: number; y: number }): ImageElement {
  return { ...el, x_mm: round(to.x - el.w_mm / 2), y_mm: round(to.y - el.h_mm / 2) };
}
