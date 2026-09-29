import type { Cmyk } from '@binder/shared';

/**
 * Colour picker <-> print colour. The customer picks on screen (RGB); the
 * design stores CMYK percentages, which the press uses exactly. The
 * conversion is the plain device formula, with two guarantees that matter on
 * press: black is 100 K only (never a four-colour rich black), and white is
 * no ink at all.
 */
export function hexToCmyk(hex: string): Cmyk {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
  if (!m) return [0, 0, 0, 100];
  const n = parseInt(m[1]!, 16);
  const r = ((n >> 16) & 255) / 255;
  const g = ((n >> 8) & 255) / 255;
  const b = (n & 255) / 255;
  const k = 1 - Math.max(r, g, b);
  if (k >= 0.995) return [0, 0, 0, 100];
  const c = (1 - r - k) / (1 - k);
  const mm = (1 - g - k) / (1 - k);
  const y = (1 - b - k) / (1 - k);
  const pct = (v: number) => Math.max(0, Math.min(100, Math.round(v * 100)));
  return [pct(c), pct(mm), pct(y), pct(k)];
}

export function cmykToHex([c, m, y, k]: Cmyk): string {
  const kk = 1 - k / 100;
  const ch = (v: number) => Math.round(255 * (1 - v / 100) * kk);
  return '#' + [ch(c), ch(m), ch(y)].map((v) => v.toString(16).padStart(2, '0')).join('');
}

export function rgbToHex(r: number, g: number, b: number): string {
  return '#' + [r, g, b].map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('');
}

export const sameCmyk = (a: Cmyk, b: Cmyk): boolean => a.every((v, i) => v === b[i]);

/** Quick picks under the picker: black (K only), white (no ink), and a few house colours. */
export const QUICK_COLOURS: Cmyk[] = [
  [0, 0, 0, 100],
  [0, 0, 0, 0],
  [0, 0, 0, 50],
  [0, 100, 100, 0],
  [0, 100, 0, 0],
  [0, 25, 85, 10],
  [0, 0, 100, 0],
  [90, 0, 100, 10],
  [90, 0, 40, 10],
  [100, 70, 0, 0],
  [100, 85, 30, 45],
  [5, 10, 25, 0],
];
