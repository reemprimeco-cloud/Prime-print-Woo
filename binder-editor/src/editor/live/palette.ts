import type { Cmyk } from '@binder/shared';

export interface Swatch {
  cmyk: Cmyk;
  en: string;
  ar: string;
}

/**
 * The colours a customer can pick. Every one is a real CMYK recipe, so what
 * they choose is what the press mixes (no RGB approximation of the ink).
 * Black is 100 K only — never a four-colour rich black — and white is no ink.
 *
 * These are generic process colours. If the shop wants its own brand inks
 * here, this is the one list to edit.
 */
export const PALETTE: Swatch[] = [
  { cmyk: [0, 0, 0, 100], en: 'Black', ar: 'أسود' },
  { cmyk: [0, 0, 0, 80], en: 'Charcoal', ar: 'فحمي' },
  { cmyk: [0, 0, 0, 50], en: 'Grey', ar: 'رمادي' },
  { cmyk: [0, 0, 0, 20], en: 'Silver', ar: 'فضي' },
  { cmyk: [0, 0, 0, 0], en: 'White', ar: 'أبيض' },
  { cmyk: [0, 100, 100, 0], en: 'Red', ar: 'أحمر' },
  { cmyk: [0, 55, 100, 0], en: 'Orange', ar: 'برتقالي' },
  { cmyk: [0, 25, 85, 10], en: 'Gold', ar: 'ذهبي' },
  { cmyk: [0, 0, 100, 0], en: 'Yellow', ar: 'أصفر' },
  { cmyk: [50, 0, 100, 0], en: 'Lime', ar: 'أخضر فاتح' },
  { cmyk: [90, 0, 100, 10], en: 'Green', ar: 'أخضر' },
  { cmyk: [90, 0, 40, 10], en: 'Teal', ar: 'تركوازي' },
  { cmyk: [100, 0, 0, 0], en: 'Cyan', ar: 'سماوي' },
  { cmyk: [55, 10, 0, 0], en: 'Sky', ar: 'أزرق سماوي' },
  { cmyk: [100, 70, 0, 0], en: 'Blue', ar: 'أزرق' },
  { cmyk: [100, 85, 30, 45], en: 'Navy', ar: 'كحلي' },
  { cmyk: [65, 90, 0, 0], en: 'Purple', ar: 'بنفسجي' },
  { cmyk: [0, 100, 0, 0], en: 'Magenta', ar: 'أرجواني' },
  { cmyk: [0, 50, 0, 0], en: 'Pink', ar: 'وردي' },
  { cmyk: [30, 70, 90, 45], en: 'Brown', ar: 'بني' },
  { cmyk: [5, 10, 25, 0], en: 'Cream', ar: 'كريمي' },
];

export const sameCmyk = (a: Cmyk, b: Cmyk): boolean => a.every((v, i) => v === b[i]);
