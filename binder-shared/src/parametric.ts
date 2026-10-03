/**
 * One door for the three templates whose spec is derived from what the
 * customer typed (sticker, uvdtf, bag): the editors, the print route and the
 * render service all rebuild the spec from the design itself through here.
 */

import { bagSpec, normalizeBagParams } from './bag.ts';
import { normalizeParametricParams, parametricSpec } from './sticker.ts';
import { isParametricTemplate, type ParametricTemplate, type Spec } from './types.ts';

/** The params a design carries for its template: `sticker` for the sticker and transfer, `bag` for the bag. */
export function parametricParamsOf(template: ParametricTemplate, design: unknown): unknown {
  if (typeof design !== 'object' || design === null) return undefined;
  const d = design as { sticker?: unknown; bag?: unknown };
  return template === 'bag' ? d.bag : d.sticker;
}

/**
 * The spec for raw params (from a URL, a request or a design), or null when
 * they are missing or out of range.
 */
export function parametricSpecFor(template: ParametricTemplate, params: unknown): Spec | null {
  if (template === 'bag') {
    const p = normalizeBagParams(params);
    return p ? bagSpec(p) : null;
  }
  const p = normalizeParametricParams(template, params);
  return p ? parametricSpec(template, p) : null;
}

/** The spec a design was made for, rebuilt from the size the design itself declares. */
export function parametricSpecFromDesign(template: unknown, design: unknown): Spec | null {
  if (!isParametricTemplate(template)) return null;
  return parametricSpecFor(template, parametricParamsOf(template, design));
}
