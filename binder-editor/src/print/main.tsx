import { createRoot } from 'react-dom/client';
import { normalizeStickerParams, stickerSpec, type DesignJSON, type Spec } from '@binder/shared';
import { PrintCanvas, type MeasuredText } from './PrintCanvas';

/**
 * Print-only route: /print-render/<template>
 *
 *   design JSON   window.__BINDER_DESIGN__ (injected by the render service
 *                 before load), or ?sample=<name> for standalone testing
 *   spec          /spec/<template>.json — geometry is read, never hardcoded
 *
 * Signals for the caller (Playwright):
 *   window.__RENDER_READY__  true once fonts + images are settled
 *   window.__RENDER_ERROR__  a message if anything failed
 *   window.__MEASURED__      real text box sizes in mm, for server-side re-validation
 */
declare global {
  interface Window {
    __BINDER_DESIGN__?: DesignJSON;
    __RENDER_READY__?: boolean;
    __RENDER_ERROR__?: string;
    __MEASURED__?: MeasuredText[];
  }
}

function fail(message: string): void {
  window.__RENDER_ERROR__ = message;
  document.body.setAttribute('data-render-error', message);
}

async function boot(): Promise<void> {
  const template = location.pathname.split('/').filter(Boolean).pop() ?? '';

  let design = window.__BINDER_DESIGN__;
  if (!design) {
    const sample = new URLSearchParams(location.search).get('sample');
    if (!sample) throw new Error('No design supplied (window.__BINDER_DESIGN__ or ?sample=)');
    const s = await fetch(`/samples/${encodeURIComponent(sample)}.json`);
    if (!s.ok) throw new Error(`Unknown sample "${sample}"`);
    design = (await s.json()) as DesignJSON;
  }

  let spec: Spec;
  if (template === 'sticker') {
    // No spec file: the sticker's geometry is derived from the size and shape the design carries.
    const params = normalizeStickerParams(design.sticker);
    if (!params) throw new Error('Sticker design carries no valid size and shape');
    spec = stickerSpec(params);
  } else {
    const res = await fetch(`/spec/${encodeURIComponent(template)}.json`);
    if (!res.ok) throw new Error(`Unknown template "${template}"`);
    spec = (await res.json()) as Spec;
  }
  if (design.template !== spec.template) {
    throw new Error(`Design is for ${design.template} but the route is ${spec.template}`);
  }

  const root = document.getElementById('root');
  if (!root) throw new Error('#root missing');

  // No StrictMode: it would run the readiness effect twice.
  createRoot(root).render(
    <PrintCanvas
      spec={spec}
      design={design}
      onReady={(measured) => {
        window.__MEASURED__ = measured;
        window.__RENDER_READY__ = true;
      }}
      onError={fail}
      proof={new URLSearchParams(location.search).get('proof') === '1'}
    />,
  );
}

boot().catch((e: unknown) => fail(e instanceof Error ? e.message : String(e)));
