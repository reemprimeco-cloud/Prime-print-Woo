import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { boundingBox, canvasBox, effectiveDpi, imageCovers, validateDesign, type DesignJSON, type Spec } from '@binder/shared';
import {
  centeredElement,
  coverWidthMm,
  elementToFabric,
  fabricToElement,
  fillElement,
  fitElement,
  normalizeAngle,
  rotatedElement,
  scaledElement,
} from '../src/editor/design';

const here = dirname(fileURLToPath(import.meta.url));
const load = (n: string): Spec => JSON.parse(readFileSync(join(here, '../../binder-shared/templates', `binder-${n}-spec.json`), 'utf8'));
const outer = load('outer');
const inner = load('inner');

const src = (w: number, h: number) => ({ src: 'https://example.com/a.jpg', source_px: { w, h } });
const design = (spec: Spec, el: ReturnType<typeof fillElement>): DesignJSON => ({ template: spec.template, mode: 'upload', canvas_mm: { ...spec.canvas_with_bleed_mm }, elements: [el] });

describe('fill and fit', () => {
  for (const spec of [outer, inner]) {
    for (const [w, h] of [[8161, 4205], [3000, 4000], [4000, 3000], [1000, 1000], [6000, 800]] as const) {
      it(`${spec.template}: fill covers the whole canvas for a ${w}x${h} image`, () => {
        const el = fillElement(spec, src(w, h));
        expect(imageCovers(el, canvasBox(spec))).toBe(true);
        // ...and is as small as it can be: one dimension matches the canvas exactly.
        const { w: cw, h: ch } = spec.canvas_with_bleed_mm;
        expect(Math.abs(el.w_mm - cw) < 0.01 || Math.abs(el.h_mm - ch) < 0.01).toBe(true);
        expect(el.w_mm / el.h_mm).toBeCloseTo(w / h, 3);
      });

      it(`${spec.template}: fit keeps the whole ${w}x${h} image inside the canvas`, () => {
        const el = fitElement(spec, src(w, h));
        const bb = boundingBox(el);
        expect(bb.x).toBeGreaterThanOrEqual(-0.01);
        expect(bb.y).toBeGreaterThanOrEqual(-0.01);
        expect(bb.x + bb.w).toBeLessThanOrEqual(spec.canvas_with_bleed_mm.w + 0.01);
        expect(bb.y + bb.h).toBeLessThanOrEqual(spec.canvas_with_bleed_mm.h + 0.01);
      });
    }

    it(`${spec.template}: a quarter turn still fills and fits`, () => {
      for (const rot of [90, -90]) {
        const fill = fillElement(spec, src(3000, 4000), rot);
        expect(imageCovers(fill, canvasBox(spec))).toBe(true);
        const fit = fitElement(spec, src(3000, 4000), rot);
        const bb = boundingBox(fit);
        expect(bb.x + bb.w).toBeLessThanOrEqual(spec.canvas_with_bleed_mm.w + 0.01);
        expect(bb.y + bb.h).toBeLessThanOrEqual(spec.canvas_with_bleed_mm.h + 0.01);
      }
    });
  }

  it('an image at exactly the spec pixel size, filled, is 300 dpi and validates clean', () => {
    const el = fillElement(outer, src(outer.canvas_with_bleed_px.w, outer.canvas_with_bleed_px.h));
    expect(effectiveDpi(el)).toBeCloseTo(outer.dpi, 0);
    expect(validateDesign(design(outer, el), outer).warnings).toEqual([]);
  });

  it('filling with a small image is flagged by the validator, not hidden', () => {
    const el = fillElement(outer, src(1200, 600));
    expect(validateDesign(design(outer, el), outer).errors.map((e) => e.code)).toContain('dpi.block');
  });
});

describe('Fabric <-> element round trip', () => {
  const pxPerMm = 1600 / 691;
  const bitmap = { width: 2400, height: 1236 }; // a downsized proxy of the original

  it('round-trips position, size and rotation to 3 decimals', () => {
    const source = src(8161, 4205);
    for (const el of [
      centeredElement(outer, source, 400, 0),
      centeredElement(outer, source, 250, 17.5, { x: 100, y: 200 }),
      centeredElement(outer, source, 900, -90, { x: 300, y: 150 }),
    ]) {
      const back = fabricToElement(elementToFabric(el, bitmap, pxPerMm), source, pxPerMm);
      expect(back.x_mm).toBeCloseTo(el.x_mm, 2);
      expect(back.y_mm).toBeCloseTo(el.y_mm, 2);
      expect(back.w_mm).toBeCloseTo(el.w_mm, 2);
      expect(back.h_mm).toBeCloseTo(el.h_mm, 2);
      expect(back.rotation_deg).toBeCloseTo(el.rotation_deg ?? 0, 1);
    }
  });

  it('height always follows the ORIGINAL aspect ratio, whatever the proxy bitmap is', () => {
    const source = src(8161, 4205);
    const f = elementToFabric(centeredElement(outer, source, 500), { width: 1000, height: 1000 }, pxPerMm); // wrong-aspect proxy
    const el = fabricToElement({ ...f, scaleX: 0.7, scaleY: 0.7 }, source, pxPerMm);
    expect(el.w_mm / el.h_mm).toBeCloseTo(8161 / 4205, 3);
  });

  it('rotation is about the centre: turning an element does not move its centre', () => {
    const el = centeredElement(outer, src(3000, 2000), 300, 0, { x: 250, y: 120 });
    const turned = rotatedElement(el, 90);
    expect(turned.x_mm + turned.w_mm / 2).toBeCloseTo(el.x_mm + el.w_mm / 2, 3);
    expect(turned.y_mm + turned.h_mm / 2).toBeCloseTo(el.y_mm + el.h_mm / 2, 3);
  });

  it('scaling about the centre keeps the centre', () => {
    const el = centeredElement(outer, src(3000, 2000), 300, 0, { x: 250, y: 120 });
    const big = scaledElement(el, 1.5);
    expect(big.x_mm + big.w_mm / 2).toBeCloseTo(250, 3);
    expect(big.y_mm + big.h_mm / 2).toBeCloseTo(120, 3);
    expect(big.w_mm / big.h_mm).toBeCloseTo(el.w_mm / el.h_mm, 3);
  });
});

describe('angles', () => {
  it('normalises, wraps and snaps', () => {
    expect(normalizeAngle(370)).toBe(10);
    expect(normalizeAngle(360)).toBe(0);
    expect(normalizeAngle(-270)).toBe(90);
    expect(normalizeAngle(89)).toBe(90);
    expect(normalizeAngle(91)).toBe(90);
    expect(normalizeAngle(-91)).toBe(-90);
    expect(normalizeAngle(180.5)).toBe(180);
    expect(normalizeAngle(-179.5)).toBe(180);
    expect(normalizeAngle(20)).toBe(20);
    expect(normalizeAngle(0.4)).toBe(0);
  });

  it('cover width helper matches fill', () => {
    expect(fillElement(outer, src(4000, 3000)).w_mm).toBeCloseTo(coverWidthMm(outer, { w: 4000, h: 3000 }), 3);
  });
});
