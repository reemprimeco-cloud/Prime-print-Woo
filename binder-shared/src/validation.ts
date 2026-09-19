/**
 * Design validation (§4.5, §5.3). One implementation, used by the editor while
 * the customer works and again by the render service before it renders — the
 * client is never trusted on its own.
 *
 * Hard blocks (severity "error") stop `finalize`. Warnings are recorded and
 * shown; the customer may proceed. A layout is never silently changed.
 *
 * Thresholds that are not in spec.json live in THRESHOLDS below, in one place:
 * §4.5 states them in prose only.
 */

import {
  boundingBox,
  boxInside,
  canvasBox,
  effectiveDpi,
  elementBox,
  elementCovers,
  panelsInCanvas,
  visibleBox,
  EPS_MM,
} from './geometry.ts';
import {
  FONTS,
  type DesignElement,
  type DesignJSON,
  type ImageElement,
  type RectElement,
  type Issue,
  type Spec,
  type TextElement,
  type ValidationResult,
} from './types.ts';

export const THRESHOLDS = {
  /** Below this effective DPI an image "may print blurry" (§4.5.1, warning). */
  warnDpi: 150,
  /** Below this it is a hard block (§4.5.1). */
  blockDpi: 100,
  /** An image element whose area is at least this share of the canvas is treated as an intended background when it is the bottom layer. */
  backgroundShare: 0.5,
  maxElements: 60,
  maxTextLength: 2000,
  /** Hard ceiling on source pixel dimensions (guards the render host's memory). */
  maxSourcePx: 40000,
} as const;

export const WEIGHTS = ['100', '200', '300', '400', '500', '600', '700', '800', '900'] as const;

const err = (code: string, message: string, extra: Partial<Issue> = {}): Issue => ({
  code,
  severity: 'error',
  message,
  ...extra,
});
const warn = (code: string, message: string, extra: Partial<Issue> = {}): Issue => ({
  code,
  severity: 'warning',
  message,
  ...extra,
});

const isNum = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v);
const isObj = (v: unknown): v is Record<string, unknown> =>
  typeof v === 'object' && v !== null && !Array.isArray(v);

/** Absolute http(s) URL, or a site-relative path. Host allow-listing is the render service's job. */
export function isAcceptableSrc(src: unknown): src is string {
  if (typeof src !== 'string' || src.length === 0 || src.length > 2000) return false;
  if (src.startsWith('/') && !src.startsWith('//')) return true;
  try {
    const u = new URL(src);
    return u.protocol === 'https:' || u.protocol === 'http:';
  } catch {
    return false;
  }
}

/** Structural checks. Returns errors only; when any exist the rule checks are skipped. */
export function validateShape(input: unknown, spec: Spec): Issue[] {
  const issues: Issue[] = [];
  if (!isObj(input)) return [err('shape.not_object', 'Design must be an object.')];
  const d = input;

  if (d.template !== spec.template) {
    issues.push(err('shape.template', `Design template must be "${spec.template}".`));
  }
  if (d.mode !== 'upload' && d.mode !== 'live') {
    issues.push(err('shape.mode', 'mode must be "upload" or "live".'));
  }

  const c = d.canvas_mm;
  if (
    !isObj(c) ||
    !isNum(c.w) ||
    !isNum(c.h) ||
    Math.abs(c.w - spec.canvas_with_bleed_mm.w) > EPS_MM ||
    Math.abs(c.h - spec.canvas_with_bleed_mm.h) > EPS_MM
  ) {
    issues.push(
      err(
        'shape.canvas_mismatch',
        `canvas_mm must equal the template canvas ${spec.canvas_with_bleed_mm.w} x ${spec.canvas_with_bleed_mm.h} mm.`,
      ),
    );
  }

  if (!Array.isArray(d.elements)) {
    issues.push(err('shape.elements', 'elements must be an array.'));
    return issues;
  }
  if (d.elements.length > THRESHOLDS.maxElements) {
    issues.push(err('shape.too_many_elements', `At most ${THRESHOLDS.maxElements} elements.`));
    return issues;
  }

  const limitX = spec.canvas_with_bleed_mm.w * 4;
  const limitY = spec.canvas_with_bleed_mm.h * 4;

  d.elements.forEach((raw: unknown, i: number) => {
    const at = { element: i };
    if (!isObj(raw)) {
      issues.push(err('shape.element', `Element ${i} must be an object.`, at));
      return;
    }
    for (const k of ['x_mm', 'y_mm', 'w_mm'] as const) {
      if (!isNum(raw[k])) issues.push(err('shape.number', `Element ${i}: ${k} must be a finite number.`, at));
    }
    if (raw.rotation_deg !== undefined && !isNum(raw.rotation_deg)) {
      issues.push(err('shape.number', `Element ${i}: rotation_deg must be a finite number.`, at));
    }
    if (isNum(raw.x_mm) && Math.abs(raw.x_mm) > limitX) issues.push(err('shape.range', `Element ${i}: x_mm is out of range.`, at));
    if (isNum(raw.y_mm) && Math.abs(raw.y_mm) > limitY) issues.push(err('shape.range', `Element ${i}: y_mm is out of range.`, at));
    if (isNum(raw.w_mm) && (raw.w_mm <= 0 || raw.w_mm > limitX)) issues.push(err('shape.range', `Element ${i}: w_mm is out of range.`, at));

    if (raw.type === 'image') {
      if (!isNum(raw.h_mm) || raw.h_mm <= 0 || raw.h_mm > limitY) {
        issues.push(err('shape.range', `Element ${i}: h_mm must be a positive number.`, at));
      }
      if (!isAcceptableSrc(raw.src)) issues.push(err('shape.src', `Element ${i}: src must be an http(s) URL.`, at));
      const sp = raw.source_px;
      if (
        !isObj(sp) ||
        !isNum(sp.w) ||
        !isNum(sp.h) ||
        !Number.isInteger(sp.w) ||
        !Number.isInteger(sp.h) ||
        sp.w < 1 ||
        sp.h < 1 ||
        sp.w > THRESHOLDS.maxSourcePx ||
        sp.h > THRESHOLDS.maxSourcePx
      ) {
        issues.push(err('shape.source_px', `Element ${i}: source_px {w,h} of positive integers is required.`, at));
      }
    } else if (raw.type === 'text') {
      if (typeof raw.text !== 'string' || raw.text.length === 0 || raw.text.length > THRESHOLDS.maxTextLength) {
        issues.push(err('shape.text', `Element ${i}: text must be 1-${THRESHOLDS.maxTextLength} characters.`, at));
      }
      if (!(FONTS as readonly string[]).includes(raw.font as string)) {
        issues.push(err('shape.font', `Element ${i}: font must be one of ${FONTS.join(', ')}.`, at));
      }
      if (!isNum(raw.size_pt) || raw.size_pt < 4 || raw.size_pt > 1500) {
        issues.push(err('shape.size', `Element ${i}: size_pt must be between 4 and 1500.`, at));
      }
      if (!(WEIGHTS as readonly string[]).includes(raw.weight as string)) {
        issues.push(err('shape.weight', `Element ${i}: weight must be one of ${WEIGHTS.join(', ')}.`, at));
      }
      const col = raw.color_cmyk;
      if (!Array.isArray(col) || col.length !== 4 || !col.every((n) => isNum(n) && n >= 0 && n <= 100)) {
        issues.push(err('shape.color', `Element ${i}: color_cmyk must be four numbers from 0 to 100.`, at));
      }
      if (raw.align !== 'left' && raw.align !== 'center' && raw.align !== 'right') {
        issues.push(err('shape.align', `Element ${i}: align must be left, center or right.`, at));
      }
      if (typeof raw.rtl !== 'boolean') issues.push(err('shape.rtl', `Element ${i}: rtl must be a boolean.`, at));
      if (raw.h_mm !== undefined && (!isNum(raw.h_mm) || raw.h_mm <= 0)) {
        issues.push(err('shape.range', `Element ${i}: h_mm must be a positive number.`, at));
      }
      if (raw.line_height !== undefined && (!isNum(raw.line_height) || raw.line_height < 0.5 || raw.line_height > 4)) {
        issues.push(err('shape.range', `Element ${i}: line_height must be between 0.5 and 4.`, at));
      }
    } else if (raw.type === 'rect') {
      if (!isNum(raw.h_mm) || raw.h_mm <= 0 || raw.h_mm > limitY) {
        issues.push(err('shape.range', `Element ${i}: h_mm must be a positive number.`, at));
      }
      const col = raw.color_cmyk;
      if (!Array.isArray(col) || col.length !== 4 || !col.every((n) => isNum(n) && n >= 0 && n <= 100)) {
        issues.push(err('shape.color', `Element ${i}: color_cmyk must be four numbers from 0 to 100.`, at));
      }
    } else {
      issues.push(err('shape.type', `Element ${i}: type must be "image", "text" or "rect".`, at));
    }
  });

  if (d.mode === 'upload') {
    const els = d.elements as Array<{ type?: unknown }>;
    if (els.length !== 1 || els[0]?.type !== 'image') {
      issues.push(err('shape.upload_mode', 'An upload-mode design is exactly one image element.'));
    }
  }

  return issues;
}

/**
 * Is this element part of the artwork background rather than a "text/logo"
 * element? The one image of an upload-mode design, or any image or colour
 * rectangle that covers everything that stays visible on the finished piece
 * (the trim area less the turn-in). Such a layer is by definition the
 * background; whether it also reaches into the bleed is a separate warning.
 */
export function isBackground(el: DesignElement, index: number, design: DesignJSON, spec: Spec): boolean {
  if (el.type === 'text') return false;
  if (el.type === 'image' && design.mode === 'upload' && index === 0) return true;
  return elementCovers(el, visibleBox(spec));
}

/** Run the §4.5 rules on a structurally valid design. */
export function validateRules(design: DesignJSON, spec: Spec): Issue[] {
  const issues: Issue[] = [];
  const canvas = canvasBox(spec);
  const panels = panelsInCanvas(spec);
  const visible = visibleBox(spec);

  design.elements.forEach((el, i) => {
    const at = { element: i };

    // 1. Resolution ------------------------------------------------------------
    if (el.type === 'image') {
      const dpi = effectiveDpi(el);
      const shown = Math.round(dpi);
      if (dpi < THRESHOLDS.blockDpi) {
        issues.push(
          err('dpi.block', `Image resolution is only ${shown} DPI at this size — too low to print. Use a larger file or make the image smaller.`, {
            ...at,
            detail: { dpi: shown, min: THRESHOLDS.blockDpi },
          }),
        );
      } else if (dpi < THRESHOLDS.warnDpi) {
        issues.push(
          warn('dpi.warn', `Image resolution is ${shown} DPI at this size — it may print blurry.`, {
            ...at,
            detail: { dpi: shown, recommended: THRESHOLDS.warnDpi },
          }),
        );
      }
    }

    if (isBackground(el, i, design, spec)) return; // §4.5.2/4 apply to text and logos only.

    // 4. Turn-in zone (hard rule, only where the template has one) ----------------
    const bb = boundingBox(el);
    if (spec.turn_in_mm > 0 && !boxInside(bb, visible)) {
      issues.push(
        err(
          'turnin.violation',
          `This element reaches into the ${spec.turn_in_mm} mm turn-in area, which wraps behind the board and will not be visible — background colour only.`,
          { ...at, detail: { turn_in_mm: spec.turn_in_mm } },
        ),
      );
    }

    // 2. Safe margin (warning) ------------------------------------------------------
    const inSomeSafeBox = panels.some((p) => boxInside(bb, p.safe));
    if (!inSomeSafeBox) {
      issues.push(
        warn(
          'safe.outside',
          `This element is closer than ${spec.safe_margin_mm} mm to a trim or fold line, or crosses panels — it may be cut off or folded.`,
          { ...at, detail: { safe_margin_mm: spec.safe_margin_mm } },
        ),
      );
    }
  });

  // 3. Empty bleed (warning) --------------------------------------------------------
  const bottom = design.elements[0];
  if (bottom && (bottom.type === 'image' || bottom.type === 'rect')) {
    const share = (bottom.w_mm * bottom.h_mm) / (canvas.w * canvas.h);
    const intendedBackground = (design.mode === 'upload' && bottom.type === 'image') || share >= THRESHOLDS.backgroundShare;
    if (intendedBackground && !elementCovers(bottom, canvas)) {
      issues.push(
        warn('bleed.empty', 'The background does not cover the whole canvas including the bleed — white may show at the edge after cutting.', {
          element: 0,
        }),
      );
    }
  }

  return issues;
}

/** Shape first; rules only on a well-formed design. */
export function validateDesign(input: unknown, spec: Spec): ValidationResult {
  const shape = validateShape(input, spec);
  if (shape.length > 0) return { ok: false, errors: shape, warnings: [] };

  const issues = validateRules(input as DesignJSON, spec);
  const errors = issues.filter((x) => x.severity === 'error');
  const warnings = issues.filter((x) => x.severity === 'warning');

  return { ok: errors.length === 0, errors, warnings };
}

export type { ImageElement, RectElement, TextElement };
export { elementBox };
