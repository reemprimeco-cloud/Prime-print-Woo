/**
 * Design schema (§4.3) and the shape of spec.json (§1).
 *
 * Coordinate contract, shared by every editor and by the print route:
 *   - all positions and sizes are millimetres, in CANVAS coordinates, i.e. the
 *     origin is the top-left corner of the canvas WITH bleed (spec
 *     canvas_with_bleed_mm), not of the trim box;
 *   - x_mm/y_mm is the top-left corner of the element's unrotated box;
 *   - rotation_deg is clockwise, about the centre of that box.
 * spec.json panel boxes are relative to the TRIM box, so they are shifted by
 * bleed_mm before use (see geometry.ts).
 */

export type TemplateKey = 'binder_outer' | 'binder_inner' | 'sticker';
export type DesignMode = 'upload' | 'live';

/** Sticker outlines the shop cuts. "custom" is cut to the artwork by hand. */
export type StickerShape = 'rectangle' | 'square' | 'round' | 'hexagon' | 'triangle' | 'star' | 'heart' | 'custom';

/**
 * What makes one sticker template different from another: the size the
 * customer chose on the product page and the shape it is cut to. The spec is
 * derived from these (sticker.ts); a design carries them so the render
 * service can rebuild the same spec without trusting anything else.
 */
export interface StickerParams {
  w_mm: number;
  h_mm: number;
  shape: StickerShape;
}

export interface Box {
  x: number;
  y: number;
  w: number;
  h: number;
}

export interface PanelSpec {
  name: string;
  trim_mm: Box;
  safe_mm: Box;
}

export interface Spec {
  template: TemplateKey;
  unit: 'mm';
  dpi: number;
  color: string;
  bleed_mm: number;
  safe_margin_mm: number;
  turn_in_mm: number;
  trim_mm: { w: number; h: number };
  canvas_with_bleed_mm: { w: number; h: number };
  canvas_with_bleed_px: { w: number; h: number };
  panels_relative_to_trim: PanelSpec[];
  fold_lines_x_mm_from_trim_left: number[];
  fold_lines_y_mm_from_trim_top: number[];
  /** Present on the sticker template only. */
  sticker?: StickerParams;
}

export interface ImageElement {
  type: 'image';
  /** https URL of the uploaded artwork. */
  src: string;
  x_mm: number;
  y_mm: number;
  w_mm: number;
  h_mm: number;
  rotation_deg?: number;
  /** Pixel size of the ORIGINAL file. Required: the DPI check depends on it. */
  source_px: { w: number; h: number };
}

/** Fonts the print route ships with (§0). */
export const FONTS = ['Tajawal', 'Poppins'] as const;
export type FontName = (typeof FONTS)[number];

export interface TextElement {
  type: 'text';
  text: string;
  font: FontName;
  size_pt: number;
  /** CSS weight as a string, e.g. "400", "700". */
  weight: string;
  /** Percent, [C, M, Y, K], each 0..100. */
  color_cmyk: [number, number, number, number];
  x_mm: number;
  y_mm: number;
  w_mm: number;
  align: 'left' | 'center' | 'right';
  rtl: boolean;
  /** Optional extensions — never required, absent in the §4.3 example. */
  h_mm?: number;
  rotation_deg?: number;
  /** Unitless multiplier of the font size. Default 1.2. */
  line_height?: number;
}

/**
 * A solid rectangle in one CMYK colour. This is how a background colour is
 * expressed: a rectangle over the whole canvas (§4.5.4 speaks of "background
 * fill"). Same coordinate contract as every other element.
 */
export interface RectElement {
  type: 'rect';
  x_mm: number;
  y_mm: number;
  w_mm: number;
  h_mm: number;
  rotation_deg?: number;
  /** Percent, [C, M, Y, K], each 0..100. */
  color_cmyk: [number, number, number, number];
}

export type DesignElement = ImageElement | TextElement | RectElement;

export interface DesignJSON {
  template: TemplateKey;
  mode: DesignMode;
  canvas_mm: { w: number; h: number };
  elements: DesignElement[];
  /** Required when template is "sticker": the size and shape the design was made for. */
  sticker?: StickerParams;
}

export type Severity = 'error' | 'warning';

export interface Issue {
  /** Stable machine code; UIs translate it. */
  code: string;
  severity: Severity;
  /** Index into design.elements, when the issue is about one element. */
  element?: number;
  /** English text for logs and the admin screen. */
  message: string;
  detail?: Record<string, number | string>;
}

export interface ValidationResult {
  /** False when at least one error (hard block) is present. */
  ok: boolean;
  errors: Issue[];
  warnings: Issue[];
}
