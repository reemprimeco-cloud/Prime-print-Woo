import { isBinding, normalizeStickerParams, type Binding, type DesignMode, type StickerParams, type TemplateKey } from '@binder/shared';

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
  /** Sticker template only: the size and shape chosen on the product page (w, h in mm and shape on the URL). */
  sticker?: StickerParams;
  /** Binder covers: which way the binder opens, chosen on the product page (binding=ltr|rtl on the URL). */
  binding?: Binding;
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

  const sticker = template === 'sticker' ? normalizeStickerParams({ w_mm: q.get('w'), h_mm: q.get('h'), shape: q.get('shape') ?? 'rectangle' }) : null;
  const binding = q.get('binding');

  return {
    rest: (q.get('rest') ?? '/wp-json/binder/v1').replace(/\/$/, ''),
    productId: Number(q.get('product') ?? 0),
    template: template === 'binder_inner' ? 'binder_inner' : template === 'sticker' ? 'sticker' : 'binder_outer',
    mode: mode === 'live' ? 'live' : 'upload',
    lang: lang === 'ar' ? 'ar' : 'en',
    sessionToken: sessionToken(),
    ...(q.get('design') ? { designId: Number(q.get('design')) } : {}),
    ...(sticker ? { sticker } : {}),
    ...(template !== 'sticker' && isBinding(binding) ? { binding } : {}),
  };
}
