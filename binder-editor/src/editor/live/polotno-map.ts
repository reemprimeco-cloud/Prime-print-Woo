/**
 * Polotno <-> design JSON (§4.3), as pure functions.
 *
 * Polotno works in pixels; the design JSON in millimetres. The working scale is
 * one CSS pixel per 1/96 inch, so a font size in pt converts by 96/72 and a
 * length in mm by 96/25.4 — nothing here is a template dimension; every
 * position comes from what the customer placed, and the canvas size from spec.json.
 *
 * Coordinate conventions (verified against Polotno in a real browser, see
 * dev/binder-tests/e2e-step5.mjs):
 *   Polotno   x,y = the element's top-left corner, and rotation turns the
 *             element about THAT corner, clockwise.
 *   Design    x_mm,y_mm = top-left of the UNROTATED box, rotation about the
 *             box CENTRE, clockwise (@binder/shared types.ts).
 * The two describe the same picture; converting is a pivot change.
 */
import {
  FONTS,
  WEIGHTS,
  type Cmyk,
  type DesignElement,
  type DesignJSON,
  type ImageElement,
  type RectElement,
  type Spec,
  type TextElement,
} from '@binder/shared';

export const PX_PER_MM = 96 / 25.4;
export const PX_PER_PT = 96 / 72;

const round = (n: number, places = 3): number => {
  const k = 10 ** places;
  return Math.round(n * k) / k;
};
const rad = (deg: number): number => (deg * Math.PI) / 180;

/** A loose view of the parts of a Polotno element this module reads and writes. */
export interface PolotnoElement {
  id?: string;
  type: string;
  x: number;
  y: number;
  width: number;
  height: number;
  rotation?: number;
  custom?: Record<string, unknown>;
  [key: string]: unknown;
}

// ---- Pivot conversion ----------------------------------------------------------------------------------

/** Centre of a Polotno element (its pivot is the top-left corner). */
export function polotnoCenter(e: { x: number; y: number; width: number; height: number; rotation?: number }): { x: number; y: number } {
  const a = rad(e.rotation ?? 0);
  const hx = e.width / 2;
  const hy = e.height / 2;
  return { x: e.x + hx * Math.cos(a) - hy * Math.sin(a), y: e.y + hx * Math.sin(a) + hy * Math.cos(a) };
}

/** Polotno top-left pivot for an element with a given centre, size and rotation. */
export function polotnoOrigin(center: { x: number; y: number }, width: number, height: number, rotation: number): { x: number; y: number } {
  const a = rad(rotation);
  const hx = width / 2;
  const hy = height / 2;
  return { x: center.x - (hx * Math.cos(a) - hy * Math.sin(a)), y: center.y - (hx * Math.sin(a) + hy * Math.cos(a)) };
}

/** Normalise to (-180, 180]. */
export function normAngle(deg: number): number {
  let a = ((deg % 360) + 360) % 360;
  if (a > 180) a -= 360;
  return round(a, 2);
}

// ---- Text direction ---------------------------------------------------------------------------------------

/** First strong directional character decides: Arabic/Hebrew -> right-to-left. */
export function isRtlText(text: string): boolean {
  for (const ch of text) {
    if (/[֐-ࣿיִ-﷿ﹰ-﻿]/.test(ch)) return true;
    if (/[A-Za-zÀ-ɏͰ-ϿЀ-ӿ]/.test(ch)) return false;
  }
  return false;
}

// ---- Polotno -> design ---------------------------------------------------------------------------------------

const cmykOf = (e: PolotnoElement, fallback: Cmyk): Cmyk => {
  const c = e.custom?.cmyk;
  return Array.isArray(c) && c.length === 4 && c.every((n) => typeof n === 'number') ? (c as Cmyk) : fallback;
};

function weightOf(w: unknown): string {
  const s = String(w ?? '400');
  if (s === 'normal') return '400';
  if (s === 'bold') return '700';
  const nearest = (WEIGHTS as readonly string[]).includes(s) ? s : '400';
  return nearest;
}

function convertText(e: PolotnoElement, spec: Spec): TextElement {
  const rotation = normAngle(e.rotation ?? 0);
  const c = polotnoCenter({ ...e, rotation });
  const w = e.width / PX_PER_MM;
  const h = e.height / PX_PER_MM;
  const text = String(e.text ?? '');
  const family = String(e.fontFamily ?? '');
  const align = e.align === 'left' || e.align === 'right' ? e.align : 'center';

  return {
    type: 'text',
    text,
    font: (FONTS as readonly string[]).includes(family) ? (family as TextElement['font']) : 'Poppins',
    size_pt: round(Number(e.fontSize ?? 16) / PX_PER_PT, 2),
    weight: weightOf(e.fontWeight),
    color_cmyk: cmykOf(e, [0, 0, 0, 100]),
    x_mm: round(c.x / PX_PER_MM - w / 2),
    y_mm: round(c.y / PX_PER_MM - h / 2),
    w_mm: round(w),
    ...(h > 0 ? { h_mm: round(h) } : {}),
    align,
    rtl: isRtlText(text),
    ...(rotation ? { rotation_deg: rotation } : {}),
    line_height: typeof e.lineHeight === 'number' ? e.lineHeight : 1.2,
  };
}

function convertImage(e: PolotnoElement): ImageElement | null {
  const src = e.custom?.src;
  const px = e.custom?.source_px as { w: number; h: number } | undefined;
  if (typeof src !== 'string' || !px?.w || !px?.h) return null;

  const rotation = normAngle(e.rotation ?? 0);
  const w = e.width / PX_PER_MM;
  const h = (w * px.h) / px.w; // aspect always follows the original file
  const c = polotnoCenter({ ...e, height: h * PX_PER_MM, rotation });

  return {
    type: 'image',
    src,
    x_mm: round(c.x / PX_PER_MM - w / 2),
    y_mm: round(c.y / PX_PER_MM - h / 2),
    w_mm: round(w),
    h_mm: round(h),
    rotation_deg: rotation,
    source_px: { w: px.w, h: px.h },
  };
}

/**
 * Polotno store JSON -> design JSON. Elements the editor does not offer
 * (overlay guide, anything foreign) are dropped; the order is the z-order.
 */
export function polotnoToDesign(json: { pages: Array<{ children: PolotnoElement[] }> }, spec: Spec): DesignJSON {
  return polotnoToDesignWithIds(json, spec).design;
}

/**
 * The same conversion, also returning for each design element the id of the
 * Polotno element it came from — so a validation issue on design element N can
 * select the right thing on the canvas.
 */
export function polotnoToDesignWithIds(
  json: { pages: Array<{ children: PolotnoElement[] }> },
  spec: Spec,
): { design: DesignJSON; ids: string[] } {
  const children = json.pages[0]?.children ?? [];
  const elements: DesignElement[] = [];
  const ids: string[] = [];

  for (const e of children) {
    if (e.custom?.overlay) continue;

    if (e.type === 'figure' && e.custom?.role === 'background') {
      const bg: RectElement = {
        type: 'rect',
        x_mm: 0,
        y_mm: 0,
        w_mm: spec.canvas_with_bleed_mm.w,
        h_mm: spec.canvas_with_bleed_mm.h,
        color_cmyk: cmykOf(e, [0, 0, 0, 0]),
      };
      elements.push(bg);
      ids.push(String(e.id ?? ''));
    } else if (e.type === 'text' && String(e.text ?? '').trim() !== '') {
      elements.push(convertText(e, spec));
      ids.push(String(e.id ?? ''));
    } else if (e.type === 'image') {
      const img = convertImage(e);
      if (img) {
        elements.push(img);
        ids.push(String(e.id ?? ''));
      }
    }
  }

  return { design: { template: spec.template, mode: 'live', canvas_mm: { ...spec.canvas_with_bleed_mm }, elements }, ids };
}

// ---- Design -> Polotno --------------------------------------------------------------------------------------------

/** On-screen colour for a CMYK value (the editor's swatches; never used for print). */
export function cmykToCss([c, m, y, k]: Cmyk): string {
  const kk = 1 - k / 100;
  const ch = (v: number) => Math.round(255 * (1 - v / 100) * kk);
  return `rgb(${ch(c)}, ${ch(m)}, ${ch(y)})`;
}

let counter = 0;
export const newId = (): string => `bd${Date.now().toString(36)}${(counter++).toString(36)}`;

export interface ImageDisplay {
  /** What Polotno should load for an image src (a downsized copy), keyed by the original URL. */
  proxyFor?: (src: string) => string;
}

/** Design JSON -> Polotno element list (bottom to top), for reopening a saved design. */
export function designToPolotno(design: DesignJSON, display: ImageDisplay = {}): PolotnoElement[] {
  const out: PolotnoElement[] = [];

  for (const el of design.elements) {
    if (el.type === 'rect') {
      out.push(backgroundElement(design, el.color_cmyk));
    } else if (el.type === 'text') {
      const rotation = el.rotation_deg ?? 0;
      const w = el.w_mm * PX_PER_MM;
      const h = (el.h_mm ?? 0) * PX_PER_MM;
      const origin = polotnoOrigin({ x: (el.x_mm + el.w_mm / 2) * PX_PER_MM, y: (el.y_mm + (el.h_mm ?? 0) / 2) * PX_PER_MM }, w, h, rotation);
      out.push({
        id: newId(),
        type: 'text',
        x: origin.x,
        y: origin.y,
        width: w,
        height: h,
        rotation,
        text: el.text,
        fontFamily: el.font,
        fontWeight: el.weight,
        fontSize: el.size_pt * PX_PER_PT,
        fill: cmykToCss(el.color_cmyk),
        align: el.align,
        lineHeight: el.line_height ?? 1.2,
        letterSpacing: 0,
        custom: { cmyk: el.color_cmyk },
      });
    } else {
      const rotation = el.rotation_deg ?? 0;
      const w = el.w_mm * PX_PER_MM;
      const h = el.h_mm * PX_PER_MM;
      const origin = polotnoOrigin({ x: (el.x_mm + el.w_mm / 2) * PX_PER_MM, y: (el.y_mm + el.h_mm / 2) * PX_PER_MM }, w, h, rotation);
      out.push({
        id: newId(),
        type: 'image',
        x: origin.x,
        y: origin.y,
        width: w,
        height: h,
        rotation,
        src: display.proxyFor?.(el.src) ?? el.src,
        keepRatio: true,
        custom: { src: el.src, source_px: el.source_px },
      });
    }
  }

  return out;
}

/** The background rectangle: locked in place, recoloured from the palette only. */
export function backgroundElement(design: Pick<DesignJSON, 'canvas_mm'>, cmyk: Cmyk): PolotnoElement {
  return {
    id: newId(),
    type: 'figure',
    subType: 'rect',
    name: 'Background',
    x: 0,
    y: 0,
    width: design.canvas_mm.w * PX_PER_MM,
    height: design.canvas_mm.h * PX_PER_MM,
    fill: cmykToCss(cmyk),
    strokeWidth: 0,
    selectable: false,
    draggable: false,
    resizable: false,
    removable: false,
    custom: { role: 'background', cmyk },
  };
}
