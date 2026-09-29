import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { stickerSpec, validateDesign, type DesignJSON, type ImageElement, type Spec, type TextElement } from '@binder/shared';
import {
  isSpineRect,
  shapeToProps,
  spineBox,
  PX_PER_MM,
  backgroundProps,
  homePanel,
  imageToProps,
  isRtlText,
  normAngle,
  objectToElement,
  objectsToElements,
  textToProps,
  type FabricLike,
} from '../src/editor/live/fabric-map';

const here = dirname(fileURLToPath(import.meta.url));
const outer: Spec = JSON.parse(readFileSync(join(here, '../../binder-shared/templates/binder-outer-spec.json'), 'utf8'));
const sticker = stickerSpec({ w_mm: 50, h_mm: 50, shape: 'round' });

const fab = (over: Partial<FabricLike>): FabricLike => ({ left: 0, top: 0, width: 10, height: 10, scaleX: 1, scaleY: 1, angle: 0, binder: { kind: 'text' }, ...over });

describe('text round trip', () => {
  const el: TextElement = { type: 'text', text: 'الصف السادس', font: 'Tajawal', size_pt: 42, weight: '700', color_cmyk: [0, 0, 0, 100], x_mm: 40, y_mm: 200, w_mm: 220, h_mm: 20, align: 'center', rtl: true, line_height: 1.2 };
  it('design -> props -> design gives the same element', () => {
    const p = textToProps(el);
    expect(p.direction).toBe('rtl');
    expect(p.fontSize).toBeCloseTo(56, 6); // 42 pt = 56 px
    const back = objectToElement(fab({ ...p, height: 20 * PX_PER_MM, text: el.text, binder: p.binder }), outer);
    expect(back).toEqual(el);
  });
  it('a corner-scaled text box folds the scale into the font size and width', () => {
    const p = textToProps(el);
    const scaled = objectToElement(fab({ ...p, height: 20 * PX_PER_MM, scaleX: 2, scaleY: 2, text: el.text }), outer) as TextElement;
    expect(scaled.size_pt).toBe(84);
    expect(scaled.w_mm).toBe(440);
    expect(scaled.h_mm).toBe(40);
  });
  it('direction follows the first strong character', () => {
    expect(isRtlText('Prime برايم')).toBe(false);
    expect(isRtlText('برايم Prime')).toBe(true);
    expect(isRtlText('123')).toBe(false);
  });
});

describe('image round trip', () => {
  const el: ImageElement = { type: 'image', src: 'https://x/a.jpg', x_mm: 20, y_mm: 18, w_mm: 200, h_mm: 150, rotation_deg: 90, source_px: { w: 4000, h: 3000 } };
  it('design -> props -> design, with the bitmap being a smaller proxy', () => {
    const p = imageToProps(el, { width: 800, height: 600 });
    const back = objectToElement(fab({ ...p, width: 800, height: 600, binder: p.binder }), outer);
    expect(back).toEqual(el);
  });
  it('height always follows the original file, whatever the drag did', () => {
    const p = imageToProps(el, { width: 800, height: 600 });
    const squashed = objectToElement(fab({ ...p, width: 800, height: 600, scaleY: p.scaleY * 3 }), outer) as ImageElement;
    expect(squashed.h_mm).toBe(150);
  });
});

describe('background and z-order', () => {
  it('the background rect covers the whole canvas and is element 0', () => {
    const bg = backgroundProps(sticker, [0, 100, 100, 0]);
    expect(bg.width).toBeCloseTo(52 * PX_PER_MM, 6);
    const objects: FabricLike[] = [
      fab({ ...bg, binder: bg.binder }),
      fab({ ...imageToProps({ type: 'image', src: 'https://x/l.png', x_mm: 10, y_mm: 10, w_mm: 20, h_mm: 20, source_px: { w: 1200, h: 1200 } }, { width: 1200, height: 1200 }), width: 1200, height: 1200 }),
      fab({ ...textToProps({ type: 'text', text: 'Hi', font: 'Poppins', size_pt: 12, weight: '400', color_cmyk: [0, 0, 0, 100], x_mm: 5, y_mm: 40, w_mm: 40, align: 'left', rtl: false }), height: 6 * PX_PER_MM, text: 'Hi' }),
    ];
    const { elements, indexOf } = objectsToElements(objects, sticker);
    expect(elements.map((e) => e.type)).toEqual(['rect', 'image', 'text']);
    expect(indexOf).toEqual([0, 1, 2]);
    const design: DesignJSON = { template: 'sticker', mode: 'live', canvas_mm: { ...sticker.canvas_with_bleed_mm }, elements, sticker: sticker.sticker };
    expect(validateDesign(design, sticker).ok).toBe(true);
  });
  it('objects without binder meta are ignored', () => {
    expect(objectToElement({ ...fab({}), binder: undefined as never }, outer)).toBeNull();
  });
});

describe('helpers', () => {
  it('normAngle', () => {
    expect(normAngle(370)).toBe(10);
    expect(normAngle(-90)).toBe(-90);
    expect(normAngle(270)).toBe(-90);
  });
  it('homePanel is the front cover on a binder and the only panel on a sticker', () => {
    expect(homePanel(outer).x).toBe(outer.bleed_mm + 385 + 5);
    expect(homePanel(sticker)).toEqual({ x: 3, y: 3, w: 46, h: 46 });
  });
});

describe('shapes, opacity and the spine colour', () => {
  it('a shape round-trips with its outline, colour, rotation and opacity', () => {
    const el = { type: 'shape' as const, shape: 'star' as const, x_mm: 400, y_mm: 60, w_mm: 60, h_mm: 50, rotation_deg: 15, color_cmyk: [0, 25, 85, 10] as [number, number, number, number], opacity: 0.6 };
    const p = shapeToProps(el);
    expect(p.path.startsWith('M')).toBe(true);
    const back = objectToElement(fab({ ...p, binder: p.binder }), outer);
    expect(back).toEqual(el);
  });
  it('an image with opacity keeps it; full opacity is not written', () => {
    const el: ImageElement = { type: 'image', src: 'https://x/a.jpg', x_mm: 20, y_mm: 18, w_mm: 200, h_mm: 150, rotation_deg: 0, source_px: { w: 4000, h: 3000 }, opacity: 0.5 };
    const p = imageToProps(el, { width: 800, height: 600 });
    expect(objectToElement(fab({ ...p, width: 800, height: 600, opacity: 0.5, binder: p.binder }), outer)).toEqual(el);
    const { opacity: _o, ...opaque } = el;
    expect(objectToElement(fab({ ...p, width: 800, height: 600, opacity: 1, binder: p.binder }), outer)).toEqual(opaque);
  });
  it('the spine strip spans the spine panel and the full height, and is recognised again', () => {
    const b = spineBox(outer)!;
    expect(b).toEqual({ x: outer.bleed_mm + 305, y: 0, w: 80, h: outer.canvas_with_bleed_mm.h });
    const el = objectToElement(fab({ binder: { kind: 'spine', cmyk: [90, 0, 40, 10] } }), outer)!;
    expect(el).toEqual({ type: 'rect', x_mm: b.x, y_mm: 0, w_mm: 80, h_mm: b.h, color_cmyk: [90, 0, 40, 10] });
    expect(isSpineRect(el as never, outer)).toBe(true);
    expect(spineBox(sticker)).toBeNull();
  });
});
