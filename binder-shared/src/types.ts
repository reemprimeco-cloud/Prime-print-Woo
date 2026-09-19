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

export type TemplateKey = 'binder_outer' | 'binder_inner';
export type DesignMode = 'upload' | 'live';

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

export type DesignElement = ImageElement | TextElement;

export interface DesignJSON {
  template: TemplateKey;
  mode: DesignMode;
  canvas_mm: { w: number; h: number };
  elements: DesignElement[];
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
