import { describe, expect, it } from 'vitest';
import { customCutPath, fillHoles, polygonArea, traceContours, type Polygon } from '../src/index.ts';

/** A w×h alpha raster painted by a predicate. */
function raster(w: number, h: number, inside: (x: number, y: number) => boolean): Uint8Array {
  const a = new Uint8Array(w * h);
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) a[y * w + x] = inside(x + 0.5, y + 0.5) ? 255 : 0;
  return a;
}
const radius = (poly: Polygon, cx: number, cy: number) => poly.map(([x, y]) => Math.hypot(x - cx, y - cy));

describe('contour tracing (custom die-cut)', () => {
  it('a disc traces to a ring of points around its centre, grown by the offset', () => {
    const a = raster(120, 120, (x, y) => Math.hypot(x - 60, y - 60) <= 30);
    const [poly] = traceContours(a, 120, 120, { offsetPx: 10, simplifyPx: 1, smoothPasses: 1 });
    expect(poly).toBeDefined();
    const r = radius(poly!, 60, 60);
    expect(Math.min(...r)).toBeGreaterThan(37);
    expect(Math.max(...r)).toBeLessThan(42.5);
    expect(poly!.length).toBeGreaterThan(12);
    expect(poly!.length).toBeLessThan(200);
  });

  it('fills holes: a ring becomes a disc, a doughnut sticker is one piece', () => {
    const a = raster(100, 100, (x, y) => {
      const d = Math.hypot(x - 50, y - 50);
      return d <= 40 && d >= 25;
    });
    const mask = new Uint8Array(a.map((v) => (v ? 1 : 0)));
    const filled = fillHoles(mask, 100, 100);
    expect(filled[50 * 100 + 50]).toBe(1);
    expect(filled[0]).toBe(0);
    const polys = traceContours(a, 100, 100, { offsetPx: 0 });
    expect(polys.length).toBe(1);
    expect(polygonArea(polys[0]!)).toBeGreaterThan(Math.PI * 38 * 38);
  });

  it('drops specks and keeps separate pieces, largest first', () => {
    const a = raster(200, 100, (x, y) => (x < 80 && y > 10 && y < 90) || (x > 120 && x < 160 && y > 30 && y < 70) || (x > 190 && y > 95));
    const polys = traceContours(a, 200, 100, { offsetPx: 0, minAreaPx: 60 });
    expect(polys.length).toBe(2);
    expect(polygonArea(polys[0]!)).toBeGreaterThan(polygonArea(polys[1]!));
  });

  it('customCutPath: mm path inside the trim box, border grown outside the art', () => {
    // 60 × 40 mm canvas at 4 px/mm; a 20 mm square of art in the middle.
    const w = 240;
    const h = 160;
    const a = raster(w, h, (x, y) => x >= 80 && x < 160 && y >= 40 && y < 120);
    const path = customCutPath(a, w, h, { w: 60, h: 40 }, 2, { x: 1, y: 1, w: 58, h: 38 });
    const pts = path.filter((c): c is { c: 'L'; x: number; y: number } | { c: 'M'; x: number; y: number } => c.c === 'M' || c.c === 'L');
    expect(path[0]!.c).toBe('M');
    expect(path[path.length - 1]!.c).toBe('Z');
    const xs = pts.map((p) => p.x);
    const ys = pts.map((p) => p.y);
    expect(Math.min(...xs)).toBeGreaterThan(17.5);
    expect(Math.min(...xs)).toBeLessThan(18.6);
    expect(Math.max(...xs)).toBeGreaterThan(41.4);
    expect(Math.max(...ys)).toBeLessThan(32.6);
  });

  it('empty artwork gives no path', () => {
    expect(traceContours(new Uint8Array(100), 10, 10)).toEqual([]);
  });
});
