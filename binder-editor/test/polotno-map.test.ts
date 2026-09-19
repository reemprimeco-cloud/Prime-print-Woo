import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { validateDesign, boundingBox, type DesignJSON, type Spec } from '@binder/shared';
import {
  PX_PER_MM,
  PX_PER_PT,
  backgroundElement,
  designToPolotno,
  isRtlText,
  normAngle,
  polotnoCenter,
  polotnoOrigin,
  polotnoToDesign,
} from '../src/editor/live/polotno-map';

const here = dirname(fileURLToPath(import.meta.url));
const load = (n: string): Spec => JSON.parse(readFileSync(join(here, '../../binder-shared/templates', `binder-${n}-spec.json`), 'utf8'));
const sample = (n: string): DesignJSON => JSON.parse(readFileSync(join(here, '../../binder-shared/samples', `${n}.json`), 'utf8'));
const outer = load('outer');

describe('pivot conversion', () => {
  it('matches the values observed in Polotno: 400x72 at (1500,500) turned 90 has its centre at (1464,700)', () => {
    const c = polotnoCenter({ x: 1500, y: 500, width: 400, height: 72, rotation: 90 });
    expect(c.x).toBeCloseTo(1464, 6);
    expect(c.y).toBeCloseTo(700, 6);
  });

  it('centre <-> origin round trips for any rotation', () => {
    for (const rot of [0, 15, 45, 90, 137, 180, -90, -33.5]) {
      const e = { x: 321.5, y: 88.25, width: 240, height: 61, rotation: rot };
      const c = polotnoCenter(e);
      const o = polotnoOrigin(c, e.width, e.height, rot);
      expect(o.x).toBeCloseTo(e.x, 6);
      expect(o.y).toBeCloseTo(e.y, 6);
    }
  });

  it('a rotation about the centre keeps the centre in the design JSON', () => {
    const json = { pages: [{ children: [{ type: 'text', x: 1500, y: 500, width: 400, height: 72, rotation: 90, text: 'ROT', fontFamily: 'Poppins', fontSize: 60, fontWeight: '700', align: 'center', lineHeight: 1.2, custom: { cmyk: [0, 0, 0, 100] } }] }] };
    const d = polotnoToDesign(json, outer);
    const el = d.elements[0]!;
    if (el.type !== 'text') throw new Error('type');
    expect(el.x_mm + el.w_mm / 2).toBeCloseTo(1464 / PX_PER_MM, 2);
    expect(el.y_mm + (el.h_mm ?? 0) / 2).toBeCloseTo(700 / PX_PER_MM, 2);
    expect(el.rotation_deg).toBe(90);
  });
});

describe('units', () => {
  it('font size: pt <-> px, and text width/height come out in mm', () => {
    const json = { pages: [{ children: [{ type: 'text', x: 0, y: 0, width: 100 * PX_PER_MM, height: 20 * PX_PER_MM, text: 'Hello', fontFamily: 'Tajawal', fontSize: 42 * PX_PER_PT, fontWeight: 'bold', align: 'left', lineHeight: 1.3, custom: { cmyk: [10, 20, 30, 40] } }] }] };
    const el = polotnoToDesign(json, outer).elements[0]!;
    if (el.type !== 'text') throw new Error('type');
    expect(el.size_pt).toBeCloseTo(42, 2);
    expect(el.w_mm).toBeCloseTo(100, 2);
    expect(el.h_mm).toBeCloseTo(20, 2);
    expect(el.weight).toBe('700');
    expect(el.line_height).toBe(1.3);
    expect(el.color_cmyk).toEqual([10, 20, 30, 40]);
    expect(el.font).toBe('Tajawal');
  });

  it('an unknown font falls back to Poppins rather than reaching the print pipeline', () => {
    const json = { pages: [{ children: [{ type: 'text', x: 0, y: 0, width: 100, height: 20, text: 'x', fontFamily: 'Comic Sans', fontSize: 20, custom: {} }] }] };
    const el = polotnoToDesign(json, outer).elements[0]!;
    if (el.type !== 'text') throw new Error('type');
    expect(el.font).toBe('Poppins');
  });
});

describe('what is exported', () => {
  const base = { x: 0, y: 0, width: 100, height: 20 };

  it('drops the guide overlay, empty text and anything it does not recognise', () => {
    const json = {
      pages: [{
        children: [
          { ...base, type: 'image', src: 'x', custom: { overlay: true } },
          { ...base, type: 'text', text: '   ', custom: {} },
          { ...base, type: 'svg', src: 'x' },
          { ...base, type: 'image', src: 'stranger.png', custom: {} }, // not one of ours: no original URL / pixel size
          { ...base, type: 'text', text: 'keep', fontFamily: 'Poppins', fontSize: 20, custom: {} },
        ],
      }],
    };
    const d = polotnoToDesign(json, outer);
    expect(d.elements).toHaveLength(1);
    expect(d.elements[0]!.type).toBe('text');
  });

  it('the background figure becomes a full-canvas rectangle, whatever its on-screen size', () => {
    const bg = { ...backgroundElement({ canvas_mm: { ...outer.canvas_with_bleed_mm } }, [100, 70, 20, 40]), width: 5, height: 5 };
    const d = polotnoToDesign({ pages: [{ children: [bg as never] }] }, outer);
    expect(d.elements).toEqual([{ type: 'rect', x_mm: 0, y_mm: 0, w_mm: outer.canvas_with_bleed_mm.w, h_mm: outer.canvas_with_bleed_mm.h, color_cmyk: [100, 70, 20, 40] }]);
  });

  it('image height follows the ORIGINAL aspect ratio; the original URL is exported, not the on-screen proxy', () => {
    const json = { pages: [{ children: [{ type: 'image', x: 100, y: 100, width: 300, height: 999, src: 'https://x/screen.jpg', custom: { src: 'https://x/orig.jpg', source_px: { w: 4000, h: 2000 } } }] }] };
    const el = polotnoToDesign(json, outer).elements[0]!;
    if (el.type !== 'image') throw new Error('type');
    expect(el.src).toBe('https://x/orig.jpg');
    expect(el.w_mm / el.h_mm).toBeCloseTo(2, 3);
  });

  it('text direction is decided by the first strong character', () => {
    expect(isRtlText('الصف السادس')).toBe(true);
    expect(isRtlText('  ١٢٣ الاسم')).toBe(true);
    expect(isRtlText('Name: محمد')).toBe(false);
    expect(isRtlText('2026')).toBe(false);
    expect(isRtlText('')).toBe(false);
  });

  it('angles wrap', () => {
    expect(normAngle(270)).toBe(-90);
    expect(normAngle(-181)).toBe(179);
    expect(normAngle(360)).toBe(0);
  });
});

describe('round trip: design -> Polotno -> design', () => {
  for (const name of ['outer-mixed', 'outer-rect-bg', 'outer-arabic-text', 'inner-mixed']) {
    it(`${name} survives unchanged (positions to 0.01 mm)`, () => {
      const original = sample(name);
      const spec = load(original.template === 'binder_outer' ? 'outer' : 'inner');
      const polotno = designToPolotno(original);
      const back = polotnoToDesign({ pages: [{ children: polotno }] }, spec);

      expect(back.elements).toHaveLength(original.elements.length);
      original.elements.forEach((o, i) => {
        const b = back.elements[i]!;
        expect(b.type).toBe(o.type);
        expect(b.x_mm).toBeCloseTo(o.x_mm ?? 0, 2);
        expect(b.y_mm).toBeCloseTo(o.y_mm ?? 0, 2);
        expect(b.w_mm).toBeCloseTo(o.w_mm, 2);
        // Image heights are re-derived from the file's own pixel aspect, which differs from the mm
        // aspect by a rounding sliver (<0.05 mm) when the pixel size was itself rounded from mm.
        if (o.type !== 'text') expect(b.h_mm).toBeCloseTo(o.h_mm, 1);
        expect(b.rotation_deg ?? 0).toBeCloseTo(o.rotation_deg ?? 0, 1);
        if (o.type !== 'image') expect((b as never as { color_cmyk: number[] }).color_cmyk).toEqual((o as never as { color_cmyk: number[] }).color_cmyk);
        if (o.type === 'text' && b.type === 'text') {
          expect(b.text).toBe(o.text);
          expect(b.size_pt).toBeCloseTo(o.size_pt, 2);
          expect(b.rtl).toBe(o.rtl);
          expect(b.font).toBe(o.font);
          expect(b.weight).toBe(o.weight);
        }
        if (o.type === 'image' && b.type === 'image') {
          expect(b.src).toBe(o.src);
          expect(b.source_px).toEqual(o.source_px);
        }
      });
    });
  }

  it('the exported design still passes the shared validator for a clean sample', () => {
    const original = sample('outer-rect-bg');
    const back = polotnoToDesign({ pages: [{ children: designToPolotno(original) }] }, outer);
    const r = validateDesign(back, outer);
    expect(r.errors).toEqual([]);
    expect(boundingBox(back.elements[1]!).w).toBeGreaterThan(0);
  });
});
