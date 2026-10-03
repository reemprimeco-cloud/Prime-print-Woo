import { isBinding, isParametricTemplate, isStickerLikeTemplate, normalizeBagParams, normalizeParametricParams, type BagParams, type Binding, type DesignMode, type StickerParams, type TemplateKey } from '@binder/shared';

export type Lang = 'en' | 'ar';

export interface EditorConfig {
  /** REST base, e.g. /wp-json/binder/v1 (no trailing slash). */
  rest: string;
  productId: number;
  template: TemplateKey;
  mode: DesignMode;
  lang: Lang;
  /** Random per-browser id that owns this customer's drafts. Shared with the product page. */
  sessionToken: string;
  /** Reopen an existing design (cart / account "Edit design"). */
  designId?: number;
  /** Sticker and UV DTF templates: the size (and shape) chosen on the product page (w, h in mm and shape on the URL). */
  sticker?: StickerParams;
  /** Paper bag: width, height and depth chosen on the product page (w, h, d in mm on the URL). */
  bag?: BagParams;
  /** Binder covers: which way the binder opens, chosen on the product page (binding=ltr|rtl on the URL). */
  binding?: Binding;
  /** What the product page shows about the order (display only): product name, chips, total, per-sheet note. */
  order?: OrderSummary;
}

export interface OrderSummary {
  title: string;
  chips: string[];
  total: string;
  note: string;
}

function readOrder(raw: string | null): OrderSummary | undefined {
  if (!raw) return undefined;
  try {
    const o = JSON.parse(raw) as Partial<OrderSummary>;
    const str = (v: unknown, max = 120) => (typeof v === 'string' ? v.slice(0, max) : '');
    return {
      title: str(o.title),
      chips: Array.isArray(o.chips) ? o.chips.slice(0, 8).map((c) => str(c, 60)).filter(Boolean) : [],
      total: str(o.total, 40),
      note: str(o.note, 60),
    };
  } catch {
    return undefined;
  }
}

const KEY = 'binder_session';

/** Same key the product page reads, so the add-to-cart form can prove the design is this visitor's. */
export function sessionToken(): string {
  const fresh = () =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
      ? crypto.randomUUID()
      : Array.from({ length: 32 }, () => Math.floor(Math.random() * 16).toString(16)).join('');

  try {
    const stored = window.localStorage.getItem(KEY);
    if (stored && /^[A-Za-z0-9-]{16,64}$/.test(stored)) return stored;
    const token = fresh();
    window.localStorage.setItem(KEY, token);
    return token;
  } catch {
    // Storage blocked (private mode): a token that lasts for this page load still works.
    return fresh();
  }
}

/** Configuration arrives on the URL of the editor page (the product page builds it). */
export function readConfig(search = window.location.search): EditorConfig {
  const q = new URLSearchParams(search);
  const template = q.get('template');
  const mode = q.get('mode');
  const lang = q.get('lang');

  const sticker = isStickerLikeTemplate(template) ? normalizeParametricParams(template, { w_mm: q.get('w'), h_mm: q.get('h'), shape: q.get('shape') ?? 'rectangle' }) : null;
  const bag = template === 'bag' ? normalizeBagParams({ w_mm: q.get('w'), h_mm: q.get('h'), d_mm: q.get('d') }) : null;
  const binding = q.get('binding');

  return {
    rest: (q.get('rest') ?? '/wp-json/binder/v1').replace(/\/$/, ''),
    productId: Number(q.get('product') ?? 0),
    template: template === 'binder_inner' ? 'binder_inner' : isParametricTemplate(template) ? template : 'binder_outer',
    mode: mode === 'live' ? 'live' : 'upload',
    lang: lang === 'ar' ? 'ar' : 'en',
    sessionToken: sessionToken(),
    ...(q.get('design') ? { designId: Number(q.get('design')) } : {}),
    ...(sticker ? { sticker } : {}),
    ...(bag ? { bag } : {}),
    ...(!isParametricTemplate(template) && isBinding(binding) ? { binding } : {}),
    ...(readOrder(q.get('order')) ? { order: readOrder(q.get('order'))! } : {}),
  };
}
