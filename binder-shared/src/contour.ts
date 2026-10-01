/**
 * A cut line that follows the artwork: the "custom shape" die-cut sticker.
 *
 * The customer's artwork is rasterised (the editor reads its own canvas, the
 * render service captures the print page) and the cut line is traced from
 * where that raster is opaque, pushed out by a border, with holes filled —
 * a sticker is one piece of vinyl with a white border around the picture.
 * Both sides run this same code, so what the customer sees is what the plotter
 * cuts.
 *
 * Steps, all on a small working raster (a few hundred px on the long side):
 *   1. threshold the alpha into a mask;
 *   2. grow it by the border (Euclidean distance transform, Felzenszwalb);
 *   3. fill holes (anything not reachable from the raster edge is inside);
 *   4. trace each piece's outer boundary (Moore neighbourhood);
 *   5. simplify (Ramer–Douglas–Peucker) and round the corners (Chaikin).
 */

import type { PathCmd } from './sticker.ts';

export interface ContourOptions {
  /** Alpha at or above this is artwork (0..255). */
  threshold?: number;
  /** How far outside the artwork the cut runs, in pixels of the mask. */
  offsetPx?: number;
  /** Pieces smaller than this many pixels (after the offset) are dropped as specks. */
  minAreaPx?: number;
  /** Simplification tolerance in pixels. */
  simplifyPx?: number;
  /** Corner-rounding passes (0 = polygon as traced). */
  smoothPasses?: number;
}

export type Polygon = Array<[number, number]>;

/** 1-D squared distance transform (Felzenszwalb & Huttenlocher). */
function dt1d(f: Float64Array, n: number, d: Float64Array, v: Int32Array, z: Float64Array): void {
  let k = 0;
  v[0] = 0;
  z[0] = -Infinity;
  z[1] = Infinity;
  for (let q = 1; q < n; q++) {
    let s = (f[q]! + q * q - (f[v[k]!]! + v[k]! * v[k]!)) / (2 * q - 2 * v[k]!);
    while (s <= z[k]!) {
      k--;
      s = (f[q]! + q * q - (f[v[k]!]! + v[k]! * v[k]!)) / (2 * q - 2 * v[k]!);
    }
    k++;
    v[k] = q;
    z[k] = s;
    z[k + 1] = Infinity;
  }
  k = 0;
  for (let q = 0; q < n; q++) {
    while (z[k + 1]! < q) k++;
    d[q] = (q - v[k]!) * (q - v[k]!) + f[v[k]!]!;
  }
}

/** Squared Euclidean distance from every pixel to the nearest set pixel of the mask. */
export function distanceTransform(mask: Uint8Array, w: number, h: number): Float64Array {
  const INF = 1e20;
  const f = new Float64Array(w * h);
  for (let i = 0; i < w * h; i++) f[i] = mask[i] ? 0 : INF;
  const n = Math.max(w, h);
  const col = new Float64Array(n);
  const d = new Float64Array(n);
  const v = new Int32Array(n);
  const z = new Float64Array(n + 1);
  // Columns.
  for (let x = 0; x < w; x++) {
    for (let y = 0; y < h; y++) col[y] = f[y * w + x]!;
    dt1d(col, h, d, v, z);
    for (let y = 0; y < h; y++) f[y * w + x] = d[y]!;
  }
  // Rows.
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) col[x] = f[y * w + x]!;
    dt1d(col, w, d, v, z);
    for (let x = 0; x < w; x++) f[y * w + x] = d[x]!;
  }
  return f;
}

/** Everything not reachable from the raster edge through empty pixels becomes set: holes are filled. */
export function fillHoles(mask: Uint8Array, w: number, h: number): Uint8Array {
  const outside = new Uint8Array(w * h);
  const stack: number[] = [];
  const push = (i: number) => {
    if (!mask[i] && !outside[i]) {
      outside[i] = 1;
      stack.push(i);
    }
  };
  for (let x = 0; x < w; x++) {
    push(x);
    push((h - 1) * w + x);
  }
  for (let y = 0; y < h; y++) {
    push(y * w);
    push(y * w + w - 1);
  }
  while (stack.length) {
    const i = stack.pop()!;
    const x = i % w;
    const y = (i - x) / w;
    if (x > 0) push(i - 1);
    if (x < w - 1) push(i + 1);
    if (y > 0) push(i - w);
    if (y < h - 1) push(i + w);
  }
  const out = new Uint8Array(w * h);
  for (let i = 0; i < w * h; i++) out[i] = outside[i] ? 0 : 1;
  return out;
}

/** Label 4-connected pieces; returns labels (0 = background) and each label's pixel count. */
function label(mask: Uint8Array, w: number, h: number): { labels: Int32Array; areas: number[] } {
  const labels = new Int32Array(w * h);
  const areas: number[] = [0];
  const stack: number[] = [];
  for (let s = 0; s < w * h; s++) {
    if (!mask[s] || labels[s]) continue;
    const id = areas.length;
    areas.push(0);
    labels[s] = id;
    stack.push(s);
    while (stack.length) {
      const i = stack.pop()!;
      areas[id]!++;
      const x = i % w;
      const y = (i - x) / w;
      const nb = [x > 0 ? i - 1 : -1, x < w - 1 ? i + 1 : -1, y > 0 ? i - w : -1, y < h - 1 ? i + w : -1];
      for (const j of nb) {
        if (j >= 0 && mask[j] && !labels[j]) {
          labels[j] = id;
          stack.push(j);
        }
      }
    }
  }
  return { labels, areas };
}

/** Outer boundary of one labelled piece, as pixel-centre coordinates, clockwise (y down). Moore neighbour tracing. */
function traceBoundary(labels: Int32Array, w: number, h: number, id: number): Polygon {
  const at = (x: number, y: number) => x >= 0 && y >= 0 && x < w && y < h && labels[y * w + x] === id;
  // Start: the top-most, left-most pixel of the piece.
  let sx = -1;
  let sy = -1;
  outer: for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      if (labels[y * w + x] === id) {
        sx = x;
        sy = y;
        break outer;
      }
    }
  }
  if (sx < 0) return [];
  // 8 neighbours, clockwise starting from west.
  const DX = [-1, -1, 0, 1, 1, 1, 0, -1];
  const DY = [0, -1, -1, -1, 0, 1, 1, 1];
  const poly: Polygon = [[sx, sy]];
  let cx = sx;
  let cy = sy;
  let dir = 0; // came from the west (the backtrack direction)
  const limit = w * h * 4;
  for (let steps = 0; steps < limit; steps++) {
    let found = -1;
    // Search clockwise starting just after the backtrack direction.
    for (let k = 0; k < 8; k++) {
      const d = (dir + 1 + k) % 8;
      if (at(cx + DX[d]!, cy + DY[d]!)) {
        found = d;
        break;
      }
    }
    if (found < 0) break; // a single pixel
    cx += DX[found]!;
    cy += DY[found]!;
    if (cx === sx && cy === sy) break;
    poly.push([cx, cy]);
    dir = (found + 4) % 8; // backtrack: the direction of the pixel we came from
  }
  return poly;
}

/** Ramer–Douglas–Peucker on a closed polygon. */
export function simplify(poly: Polygon, epsilon: number): Polygon {
  if (poly.length < 4 || epsilon <= 0) return poly;
  const keep = new Uint8Array(poly.length);
  const dist = (p: [number, number], a: [number, number], b: [number, number]) => {
    const dx = b[0] - a[0];
    const dy = b[1] - a[1];
    const len2 = dx * dx + dy * dy;
    if (len2 === 0) return Math.hypot(p[0] - a[0], p[1] - a[1]);
    const t = Math.max(0, Math.min(1, ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / len2));
    return Math.hypot(p[0] - (a[0] + t * dx), p[1] - (a[1] + t * dy));
  };
  const rdp = (i: number, j: number) => {
    const stack: Array<[number, number]> = [[i, j]];
    while (stack.length) {
      const [s, e] = stack.pop()!;
      let best = -1;
      let bestD = epsilon;
      for (let k = s + 1; k < e; k++) {
        const d = dist(poly[k]!, poly[s]!, poly[e]!);
        if (d > bestD) {
          bestD = d;
          best = k;
        }
      }
      if (best >= 0) {
        keep[best] = 1;
        stack.push([s, best], [best, e]);
      }
    }
  };
  // Split the ring at its two farthest-apart points so RDP sees two open chains.
  let far = 0;
  let farD = -1;
  for (let k = 1; k < poly.length; k++) {
    const d = Math.hypot(poly[k]![0] - poly[0]![0], poly[k]![1] - poly[0]![1]);
    if (d > farD) {
      farD = d;
      far = k;
    }
  }
  keep[0] = 1;
  keep[far] = 1;
  rdp(0, far);
  rdp(far, poly.length - 1);
  keep[poly.length - 1] = 1;
  return poly.filter((_, k) => keep[k]);
}

/** Chaikin corner cutting on a closed polygon. */
export function smooth(poly: Polygon, passes: number): Polygon {
  let out = poly;
  for (let p = 0; p < passes; p++) {
    if (out.length < 3) return out;
    const next: Polygon = [];
    for (let i = 0; i < out.length; i++) {
      const a = out[i]!;
      const b = out[(i + 1) % out.length]!;
      next.push([a[0] * 0.75 + b[0] * 0.25, a[1] * 0.75 + b[1] * 0.25]);
      next.push([a[0] * 0.25 + b[0] * 0.75, a[1] * 0.25 + b[1] * 0.75]);
    }
    out = next;
  }
  return out;
}

/**
 * The cut outline(s) of an alpha raster, in pixel coordinates (0,0 = top-left
 * corner of the first pixel; points sit on pixel centres).
 */
export function traceContours(alpha: Uint8Array, w: number, h: number, opts: ContourOptions = {}): Polygon[] {
  const threshold = opts.threshold ?? 96;
  const offset = opts.offsetPx ?? 0;
  const minArea = opts.minAreaPx ?? 16;
  const eps = opts.simplifyPx ?? 1.2;
  const passes = opts.smoothPasses ?? 2;

  let mask: Uint8Array = new Uint8Array(w * h);
  for (let i = 0; i < w * h; i++) mask[i] = alpha[i]! >= threshold ? 1 : 0;
  if (!mask.some((v) => v)) return [];

  if (offset > 0) {
    const d2 = distanceTransform(mask, w, h);
    const r2 = offset * offset;
    const grown = new Uint8Array(w * h);
    for (let i = 0; i < w * h; i++) grown[i] = d2[i]! <= r2 ? 1 : 0;
    mask = grown;
  }
  mask = fillHoles(mask, w, h);

  const { labels, areas } = label(mask, w, h);
  const polys: Polygon[] = [];
  for (let id = 1; id < areas.length; id++) {
    if (areas[id]! < minArea) continue;
    let poly = traceBoundary(labels, w, h, id);
    if (poly.length < 3) continue;
    poly = poly.map(([x, y]) => [x + 0.5, y + 0.5]);
    poly = simplify(poly, eps);
    poly = smooth(poly, passes);
    if (poly.length >= 3) polys.push(poly);
  }
  // Largest first.
  return polys.sort((a, b) => polygonArea(b) - polygonArea(a));
}

export function polygonArea(poly: Polygon): number {
  let s = 0;
  for (let i = 0; i < poly.length; i++) {
    const a = poly[i]!;
    const b = poly[(i + 1) % poly.length]!;
    s += a[0] * b[1] - b[0] * a[1];
  }
  return Math.abs(s) / 2;
}

/** Polygons in pixels -> path commands in mm, clamped to a box (the sticker's trim) so the plotter never leaves the piece. */
export function contourPath(polys: Polygon[], pxPerMm: number, clamp?: { x: number; y: number; w: number; h: number }): PathCmd[] {
  const cmds: PathCmd[] = [];
  const r = (n: number) => Math.round(n * 100) / 100;
  for (const poly of polys) {
    poly.forEach(([px, py], i) => {
      let x = px / pxPerMm;
      let y = py / pxPerMm;
      if (clamp) {
        x = Math.min(clamp.x + clamp.w, Math.max(clamp.x, x));
        y = Math.min(clamp.y + clamp.h, Math.max(clamp.y, y));
      }
      cmds.push(i === 0 ? { c: 'M', x: r(x), y: r(y) } : { c: 'L', x: r(x), y: r(y) });
    });
    cmds.push({ c: 'Z' });
  }
  return cmds;
}

/**
 * The whole job for a sticker: alpha raster of the canvas (any resolution) ->
 * cut path in canvas mm, with the border grown outside the artwork and the
 * result kept inside the trim box.
 */
export function customCutPath(
  alpha: Uint8Array,
  w: number,
  h: number,
  canvasMm: { w: number; h: number },
  borderMm: number,
  trim: { x: number; y: number; w: number; h: number },
): PathCmd[] {
  const pxPerMm = w / canvasMm.w;
  const polys = traceContours(alpha, w, h, { offsetPx: borderMm * pxPerMm, simplifyPx: Math.max(0.6, 0.15 * pxPerMm), smoothPasses: 2, minAreaPx: Math.max(16, 4 * pxPerMm * pxPerMm) });
  return contourPath(polys, pxPerMm, trim);
}
