import { createStore } from 'polotno/model/store';
import type { Spec } from '@binder/shared';
import { PX_PER_MM } from './polotno-map';

export type PolotnoStore = ReturnType<typeof createStore>;

/** Weights offered to customers; each has a font file in fonts/. */
export const TEXT_WEIGHTS = ['400', '500', '700'] as const;
export const FONT_FAMILIES = ['Tajawal', 'Poppins'] as const;

const FONT_FILES: Record<string, Record<string, string>> = {
  Tajawal: { '400': 'Tajawal-Regular.ttf', '500': 'Tajawal-Medium.ttf', '700': 'Tajawal-Bold.ttf' },
  Poppins: { '400': 'Poppins-Regular.ttf', '500': 'Poppins-Medium.ttf', '700': 'Poppins-Bold.ttf' },
};

/**
 * A Polotno store sized to the template canvas, with the shop's two fonts and
 * the locked guide overlay on top. Polotno's own license key comes from
 * VITE_POLOTNO_KEY (https://polotno.com/cabinet). Without one it runs in
 * evaluation mode and draws a "license key is missing" banner; development and
 * testing are permitted for 60 days, production needs a subscription.
 */
export function createLiveStore(spec: Spec, overlayUrl: string): PolotnoStore {
  const store = createStore({ key: (import.meta.env.VITE_POLOTNO_KEY as string | undefined) ?? '', showCredit: true });

  const width = spec.canvas_with_bleed_mm.w * PX_PER_MM;
  const height = spec.canvas_with_bleed_mm.h * PX_PER_MM;
  store.setSize(width, height);

  for (const [family, files] of Object.entries(FONT_FILES)) {
    store.addFont({
      fontFamily: family,
      styles: Object.entries(files).map(([weight, file]) => ({
        src: `url(${new URL(`./fonts/${file}`, document.baseURI).href})`,
        fontStyle: 'normal',
        fontWeight: weight,
      })),
    });
  }

  const page = store.addPage();
  page.set({ background: '#ffffff' });

  // The template's guide lines: visible, never selectable, never exported.
  page.addElement({
    type: 'image',
    src: overlayUrl,
    x: 0,
    y: 0,
    width,
    height,
    selectable: false,
    draggable: false,
    resizable: false,
    removable: false,
    contentEditable: false,
    styleEditable: false,
    alwaysOnTop: true,
    showInExport: false,
    custom: { overlay: true },
  });

  return store;
}

/** Make sure every face the editor offers is decoded before text is measured. */
export async function preloadFonts(): Promise<void> {
  const loads: Array<Promise<unknown>> = [];
  for (const family of FONT_FAMILIES) {
    for (const weight of TEXT_WEIGHTS) loads.push(document.fonts.load(`${weight} 24px "${family}"`, 'Aa الصف'));
  }
  await Promise.allSettled(loads);
}
