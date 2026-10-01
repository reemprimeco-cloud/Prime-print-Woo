import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { stickerSpec, uvdtfSpec, validateDesign, type DesignJSON, type Spec } from '../src/index.ts';

const here = dirname(fileURLToPath(import.meta.url));
const read = (p: string) => JSON.parse(readFileSync(join(here, '..', p), 'utf8'));
const spec = (n: 'outer' | 'inner'): Spec => read(`templates/binder-${n}-spec.json`);
const sample = (n: string): DesignJSON => read(`samples/${n}.json`);

describe('generated sample designs against the validator', () => {
  it('outer-shapes: spine fill through the turn-in, shapes, opacity, new fonts: clean', () => {
    const r = validateDesign(sample('outer-shapes'), spec('outer'));
    expect(r.errors).toEqual([]);
    expect(r.warnings).toEqual([]);
  });

  it('sticker-round and sticker-star: clean, spec rebuilt from the design itself', () => {
    for (const n of ['sticker-round', 'sticker-star', 'sticker-custom']) {
      const d = sample(n);
      const r = validateDesign(d, stickerSpec(d.sticker!));
      expect(r.errors).toEqual([]);
      expect(r.warnings).toEqual([]);
    }
  });

  it('uvdtf-text-logo: clean, spec rebuilt from the design itself', () => {
    const d = sample('uvdtf-text-logo');
    const r = validateDesign(d, uvdtfSpec(d.sticker!));
    expect(r.errors).toEqual([]);
    expect(r.warnings).toEqual([]);
  });

  it('outer-image-only: clean', () => {
    const r = validateDesign(sample('outer-image-only'), spec('outer'));
    expect(r.errors).toEqual([]);
    expect(r.warnings).toEqual([]);
  });

  it('outer-arabic-text: clean', () => {
    const r = validateDesign(sample('outer-arabic-text'), spec('outer'));
    expect(r.errors).toEqual([]);
    expect(r.warnings).toEqual([]);
  });

  it('outer-mixed: clean (background, logo, text and rotated spine title all inside their safe boxes)', () => {
    const r = validateDesign(sample('outer-mixed'), spec('outer'));
    expect(r.errors).toEqual([]);
    expect(r.warnings).toEqual([]);
  });

  it('inner-mixed: clean', () => {
    const r = validateDesign(sample('inner-mixed'), spec('inner'));
    expect(r.errors).toEqual([]);
    expect(r.warnings).toEqual([]);
  });

  it('outer-lowres: blocked on resolution', () => {
    const r = validateDesign(sample('outer-lowres'), spec('outer'));
    expect(r.ok).toBe(false);
    expect(r.errors.map((e) => e.code)).toContain('dpi.block');
  });
});
