/**
 * Starter designs. Each is built from the template's own geometry — the
 * front panel's safe box — so it fits a 3 × 3 cm sticker and a 690 mm binder
 * cover alike. Choosing one replaces the design; undo brings the old one back.
 */
import type { Cmyk, DesignElement, Spec, TextElement } from '@binder/shared';
import { homePanel, spineBox } from './fabric-map';

export interface StarterTemplate {
  id: string;
  en: string;
  ar: string;
  build: (spec: Spec) => DesignElement[];
}

const MM_TO_PT = 72 / 25.4;
const round = (n: number) => Math.round(n * 1000) / 1000;

function full(spec: Spec, cmyk: Cmyk): DesignElement {
  return { type: 'rect', x_mm: 0, y_mm: 0, w_mm: spec.canvas_with_bleed_mm.w, h_mm: spec.canvas_with_bleed_mm.h, color_cmyk: cmyk };
}

/** A centred line of text, sized as a share of the panel height. */
function line(spec: Spec, text: string, at: number, share: number, font: TextElement['font'], weight: string, cmyk: Cmyk, rtl: boolean): TextElement {
  const p = homePanel(spec);
  const sizeMm = Math.min(p.h * share, (p.w / Math.max(4, text.length)) * 1.6);
  const h = sizeMm * 1.35;
  return {
    type: 'text',
    text,
    font,
    size_pt: round(sizeMm * MM_TO_PT),
    weight,
    color_cmyk: cmyk,
    x_mm: round(p.x),
    y_mm: round(p.y + p.h * at - h / 2),
    w_mm: round(p.w),
    h_mm: round(h),
    align: 'center',
    rtl,
    line_height: 1.2,
  };
}

function spine(spec: Spec, cmyk: Cmyk): DesignElement[] {
  const b = spineBox(spec);
  return b ? [{ type: 'rect', x_mm: round(b.x), y_mm: 0, w_mm: round(b.w), h_mm: round(b.h), color_cmyk: cmyk }] : [];
}

const WHITE: Cmyk = [0, 0, 0, 0];
const BLACK: Cmyk = [0, 0, 0, 100];
const GOLD: Cmyk = [0, 25, 85, 10];
const MAGENTA: Cmyk = [0, 100, 20, 5];
const CREAM: Cmyk = [0, 5, 25, 0];
const NAVY: Cmyk = [100, 85, 30, 45];
const TEAL: Cmyk = [90, 0, 40, 10];

export const STARTERS: StarterTemplate[] = [
  {
    id: 'thank-you',
    en: 'Thank-you',
    ar: 'شكراً لك',
    build: (s) => [full(s, BLACK), line(s, 'شكراً لك', 0.42, 0.26, 'Cairo', '700', GOLD, true), line(s, 'THANK YOU', 0.66, 0.1, 'Montserrat', '700', WHITE, false)],
  },
  {
    id: 'follow-us',
    en: 'Follow us',
    ar: 'تابعونا',
    build: (s) => {
      const p = homePanel(s);
      const star = Math.min(p.w, p.h) * 0.14;
      return [
        full(s, MAGENTA),
        { type: 'shape', shape: 'star', x_mm: round(p.x + p.w / 2 - star / 2), y_mm: round(p.y + p.h * 0.14), w_mm: round(star), h_mm: round(star), color_cmyk: [0, 10, 100, 0] },
        line(s, 'تابعونا', 0.5, 0.24, 'Cairo', '700', WHITE, true),
        line(s, '@prime.printing', 0.74, 0.08, 'Poppins', '700', WHITE, false),
      ];
    },
  },
  {
    id: 'gergean',
    en: 'Gergean',
    ar: 'قرقيعان',
    build: (s) => [full(s, CREAM), line(s, 'قرقيعان', 0.45, 0.26, 'Lalezar', '400', MAGENTA, true), line(s, 'Happy Gergean', 0.7, 0.08, 'Dancing Script', '700', NAVY, false)],
  },
  {
    id: 'school',
    en: 'School subject',
    ar: 'مادة دراسية',
    build: (s) => [full(s, NAVY), ...spine(s, TEAL), line(s, 'الصف السادس', 0.3, 0.1, 'Cairo', '700', WHITE, true), line(s, 'رياضيات', 0.5, 0.14, 'Reem Kufi', '700', GOLD, true), line(s, 'Mathematics', 0.72, 0.06, 'Playfair Display', '700', WHITE, false)],
  },
  {
    id: 'department',
    en: 'Department',
    ar: 'قسم',
    build: (s) => [full(s, CREAM), ...spine(s, NAVY), line(s, 'قسم اللغة الإنجليزية', 0.42, 0.08, 'El Messiri', '700', NAVY, true), line(s, 'English Department', 0.6, 0.06, 'Playfair Display', '700', NAVY, false)],
  },
];
