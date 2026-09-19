import type { DesignJSON } from './types.ts';

export type Cmyk = [number, number, number, number];

/**
 * Exact CMYK for vector colours — text and solid rectangles (§5.2 step 4).
 *
 * Chromium cannot paint CMYK and writes every colour as RGB. Handing that to a
 * colour-managed RGB->CMYK conversion turns a customer's pure black text
 * (0,0,0,100) into a four-colour "rich black" (about C89 M78 Y62 K97) — wrong
 * for small text on press, and not what the design asked for.
 *
 * So each distinct text colour is painted in the print route as a *sentinel*
 * RGB value that appears nowhere else, and the pipeline then rewrites exactly
 * those RGB operators in the PDF into DeviceCMYK operators carrying the
 * design's own numbers. Raster images are unaffected and are converted through
 * the ICC profile.
 *
 * Both sides call this function on the same design, so they always agree.
 */
export interface ColorPlanEntry {
  cmyk: Cmyk;
  /** 8-bit sRGB stand-in the print route paints with. */
  sentinel: [number, number, number];
}

export interface ColorPlan {
  entries: ColorPlanEntry[];
  /** element index -> index into entries, text elements only. */
  byElement: Map<number, number>;
}

/** Distinct, unnatural colours: red channel counts up, green/blue fixed. */
const sentinelFor = (i: number): [number, number, number] => [i + 1, 201, 103];

export function planColors(design: DesignJSON): ColorPlan {
  const entries: ColorPlanEntry[] = [];
  const byElement = new Map<number, number>();
  const seen = new Map<string, number>();

  design.elements.forEach((el, i) => {
    if (el.type === 'image') return; // photographs are converted through the ICC profile, not by number
    const cmyk = el.color_cmyk;
    const key = cmyk.join(',');
    let idx = seen.get(key);
    if (idx === undefined) {
      idx = entries.length;
      seen.set(key, idx);
      entries.push({ cmyk: [...cmyk] as Cmyk, sentinel: sentinelFor(idx) });
    }
    byElement.set(i, idx);
  });

  return { entries, byElement };
}

/** Earlier name of planColors; rectangles are planned too now. */
export const planTextColors = planColors;

/**
 * Plain CMYK (percent) to sRGB for ON-SCREEN use only: the preview render and
 * the editor's text swatches. Print output never uses this; it carries the
 * design's exact CMYK numbers (see planTextColors above).
 */
export function cmykToRgbCss([c, m, y, k]: Cmyk): string {
  const kk = 1 - k / 100;
  const ch = (v: number) => Math.round(255 * (1 - v / 100) * kk);
  return `rgb(${ch(c)}, ${ch(m)}, ${ch(y)})`;
}
