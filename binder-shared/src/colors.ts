import type { DesignJSON, TextElement } from './types.ts';

export type Cmyk = [number, number, number, number];

/**
 * Exact CMYK for vector colours (§5.2 step 4).
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

export function planTextColors(design: DesignJSON): ColorPlan {
  const entries: ColorPlanEntry[] = [];
  const byElement = new Map<number, number>();
  const seen = new Map<string, number>();

  design.elements.forEach((el, i) => {
    if (el.type !== 'text') return;
    const cmyk = (el as TextElement).color_cmyk;
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
