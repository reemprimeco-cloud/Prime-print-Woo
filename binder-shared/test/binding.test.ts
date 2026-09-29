import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { isBinding, panelsInCanvas, validateDesign, withBinding, type DesignJSON, type Spec } from '../src/index.ts';

const here = dirname(fileURLToPath(import.meta.url));
const spec = (n: 'outer' | 'inner'): Spec => JSON.parse(readFileSync(join(here, '..', `templates/binder-${n}-spec.json`), 'utf8'));
const outer = spec('outer');
const inner = spec('inner');

describe('withBinding: an Arabic binder opens from the right, so the cover panels swap names', () => {
  it('ltr leaves the spec file as it is (front cover on the right)', () => {
    const s = withBinding(outer, 'ltr');
    expect(s.binding).toBe('ltr');
    expect(s.panels_relative_to_trim.map((p) => p.name)).toEqual(['back_cover', 'spine', 'front_cover']);
  });
  it('rtl swaps the names and moves nothing', () => {
    const s = withBinding(outer, 'rtl');
    expect(s.binding).toBe('rtl');
    expect(s.panels_relative_to_trim.map((p) => p.name)).toEqual(['front_cover', 'spine', 'back_cover']);
    expect(s.panels_relative_to_trim.map((p) => p.trim_mm)).toEqual(outer.panels_relative_to_trim.map((p) => p.trim_mm));
    expect(panelsInCanvas(s).find((p) => p.name === 'front_cover')!.trim.x).toBe(outer.bleed_mm + 15); // now the left-hand panel
    const i = withBinding(inner, 'rtl');
    expect(i.panels_relative_to_trim.map((p) => p.name)).toEqual(['inside_back', 'spine', 'inside_front']);
  });
  it('is idempotent and reversible', () => {
    expect(withBinding(withBinding(outer, 'rtl'), 'rtl')).toEqual(withBinding(outer, 'rtl'));
    expect(withBinding(withBinding(outer, 'rtl'), 'ltr')).toEqual(withBinding(outer, 'ltr'));
  });
  it('isBinding', () => {
    expect(isBinding('ltr') && isBinding('rtl')).toBe(true);
    expect(isBinding('arabic')).toBe(false);
    expect(isBinding(undefined)).toBe(false);
  });
});

describe('validation: a binder design must say which way it opens', () => {
  const image = { type: 'image' as const, src: 'https://example.com/a.jpg', x_mm: 0, y_mm: 0, w_mm: outer.canvas_with_bleed_mm.w, h_mm: outer.canvas_with_bleed_mm.h, source_px: { w: 9000, h: 4700 } };
  const design = (over: Partial<DesignJSON> = {}): DesignJSON => ({ template: 'binder_outer', mode: 'upload', canvas_mm: { ...outer.canvas_with_bleed_mm }, elements: [image], binding: 'ltr', ...over });

  it('missing or unknown binding is a hard block', () => {
    expect(validateDesign(design({ binding: undefined }), outer).errors.map((e) => e.code)).toContain('shape.binding');
    expect(validateDesign(design({ binding: 'arabic' as never }), outer).errors.map((e) => e.code)).toContain('shape.binding');
  });
  it('the binding must match the spec the editor is showing', () => {
    expect(validateDesign(design({ binding: 'rtl' }), withBinding(outer, 'ltr')).ok).toBe(false);
    expect(validateDesign(design({ binding: 'rtl' }), withBinding(outer, 'rtl')).ok).toBe(true);
    // A spec straight from the file (no binding stated) accepts either.
    expect(validateDesign(design({ binding: 'rtl' }), outer).ok).toBe(true);
  });
});
