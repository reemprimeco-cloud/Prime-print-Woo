import { chromium, type Browser } from 'playwright';
import type { DesignJSON, Spec } from '@binder/shared';
import { MM_PER_INCH } from '@binder/shared';
import type { Config } from '../config.ts';
import type { PreparedAssets } from './assets.ts';

export interface MeasuredText {
  index: number;
  w_mm: number;
  h_mm: number;
}

export interface RgbRender {
  /** Chromium's PDF: exact physical page size, vector text, original image pixels. RGB. */
  pdf: Buffer;
  measured: MeasuredText[];
}

let browser: Promise<Browser> | null = null;

/** One long-lived Chromium; every render gets its own isolated context. */
export function getBrowser(cfg: Config): Promise<Browser> {
  browser ??= chromium.launch({
    headless: true,
    ...(cfg.chromiumPath ? { executablePath: cfg.chromiumPath } : {}),
    args: [
      '--font-render-hinting=none',
      '--disable-dev-shm-usage',
      ...(cfg.noSandbox ? ['--no-sandbox'] : []),
    ],
  });
  return browser;
}

export async function closeBrowser(): Promise<void> {
  if (!browser) return;
  const b = await browser;
  browser = null;
  await b.close();
}

/** CSS pixels (96 per inch) for a length in mm. */
const cssPx = (mm: number): number => Math.ceil((mm / MM_PER_INCH) * 96);

/**
 * Render a design in the print-only route and capture it as a PDF.
 *
 * The browser has no network of its own. It may load pages, scripts and fonts
 * from this service's origin, and design images only as local files already
 * fetched and checked by prepareAssets(): design `src` values come from
 * customers, and an unrestricted headless browser is a server-side request
 * forgery gadget.
 */
export async function renderRgbPdf(
  cfg: Config,
  baseUrl: string,
  spec: Spec,
  design: DesignJSON,
  assets?: Pick<PreparedAssets, 'files'>,
): Promise<RgbRender> {
  const b = await getBrowser(cfg);
  const { w, h } = spec.canvas_with_bleed_mm;

  // Viewport at CSS-pixel size. The page is laid out in mm, so it does not need
  // an 8161-px-wide viewport (§5.2 step 1) to be exact; that would only cost memory.
  const context = await b.newContext({ viewport: { width: cssPx(w), height: cssPx(h) }, deviceScaleFactor: 1 });
  const baseOrigin = new URL(baseUrl).origin;
  const blocked: string[] = [];

  try {
    await context.route('**/*', (route) => {
      const url = new URL(route.request().url());
      const asset = assets?.files.get(url.href);
      if (asset) return route.fulfill({ path: asset.path, contentType: asset.mime });
      if (url.origin === baseOrigin) return route.continue();
      blocked.push(url.href);
      return route.abort('blockedbyclient');
    });
    await context.addInitScript((d) => {
      (window as unknown as { __BINDER_DESIGN__: unknown }).__BINDER_DESIGN__ = d;
    }, design);

    const page = await context.newPage();
    await page.goto(`${baseUrl}/print-render/${spec.template}`, { waitUntil: 'load', timeout: cfg.renderTimeoutMs });

    await page.waitForFunction(
      () => {
        const win = window as unknown as { __RENDER_READY__?: boolean; __RENDER_ERROR__?: string };
        return win.__RENDER_READY__ === true || !!win.__RENDER_ERROR__;
      },
      undefined,
      { timeout: cfg.renderTimeoutMs },
    );

    const error = await page.evaluate(() => (window as unknown as { __RENDER_ERROR__?: string }).__RENDER_ERROR__);
    if (error) {
      throw new Error(`Print route failed: ${error}${blocked.length ? ` (blocked requests: ${blocked.join(', ')})` : ''}`);
    }

    const measured = await page.evaluate(() => (window as unknown as { __MEASURED__?: MeasuredText[] }).__MEASURED__ ?? []);

    const pdf = await page.pdf({
      width: `${w}mm`,
      height: `${h}mm`,
      printBackground: true,
      margin: { top: '0', right: '0', bottom: '0', left: '0' },
      preferCSSPageSize: true,
    });

    return { pdf, measured };
  } finally {
    await context.close();
  }
}


/**
 * A fast on-screen proof: the same print route, real-looking colours, captured
 * as a PNG about `width` px wide. RGB only; never the production file.
 */
export async function renderPreviewPng(
  cfg: Config,
  baseUrl: string,
  spec: Spec,
  design: DesignJSON,
  assets?: Pick<PreparedAssets, 'files'>,
  width = 1600,
): Promise<Buffer> {
  const b = await getBrowser(cfg);
  const { w, h } = spec.canvas_with_bleed_mm;
  const vw = cssPx(w);
  const vh = cssPx(h);
  const context = await b.newContext({ viewport: { width: vw, height: vh }, deviceScaleFactor: width / vw });
  const baseOrigin = new URL(baseUrl).origin;

  try {
    await context.route('**/*', (route) => {
      const url = new URL(route.request().url());
      const asset = assets?.files.get(url.href);
      if (asset) return route.fulfill({ path: asset.path, contentType: asset.mime });
      return url.origin === baseOrigin ? route.continue() : route.abort('blockedbyclient');
    });
    await context.addInitScript((d) => {
      (window as unknown as { __BINDER_DESIGN__: unknown }).__BINDER_DESIGN__ = d;
    }, design);

    const page = await context.newPage();
    await page.goto(`${baseUrl}/print-render/${spec.template}?proof=1`, { waitUntil: 'load', timeout: cfg.renderTimeoutMs });
    await page.waitForFunction(
      () => {
        const win = window as unknown as { __RENDER_READY__?: boolean; __RENDER_ERROR__?: string };
        return win.__RENDER_READY__ === true || !!win.__RENDER_ERROR__;
      },
      undefined,
      { timeout: cfg.renderTimeoutMs },
    );
    const error = await page.evaluate(() => (window as unknown as { __RENDER_ERROR__?: string }).__RENDER_ERROR__);
    if (error) throw new Error(`Print route failed: ${error}`);

    // White paper behind transparent areas, like a proof on a sheet.
    return await page.screenshot({ type: 'png', clip: { x: 0, y: 0, width: vw, height: vh }, omitBackground: false });
  } finally {
    await context.close();
  }
}


/**
 * The print route captured as a transparent raster: `size` pixels for the
 * whole canvas, so a 50 x 50 mm artboard at 300 dpi comes back 591 x 591.
 * Used for the UV DTF transfer, whose print file is the raster itself.
 */
export async function renderArtboardPng(
  cfg: Config,
  baseUrl: string,
  spec: Spec,
  design: DesignJSON,
  assets: Pick<PreparedAssets, 'files'> | undefined,
  size: { width: number; height: number },
): Promise<{ png: Buffer; measured: MeasuredText[] }> {
  const b = await getBrowser(cfg);
  const { w, h } = spec.canvas_with_bleed_mm;
  const vw = cssPx(w);
  const vh = cssPx(h);
  // One scale for both axes keeps the artwork undistorted; the caller trims the odd pixel.
  const scale = Math.max(size.width / vw, size.height / vh);
  const context = await b.newContext({ viewport: { width: vw, height: vh }, deviceScaleFactor: scale });
  const baseOrigin = new URL(baseUrl).origin;
  const blocked: string[] = [];

  try {
    await context.route('**/*', (route) => {
      const url = new URL(route.request().url());
      const asset = assets?.files.get(url.href);
      if (asset) return route.fulfill({ path: asset.path, contentType: asset.mime });
      if (url.origin === baseOrigin) return route.continue();
      blocked.push(url.href);
      return route.abort('blockedbyclient');
    });
    await context.addInitScript((d) => {
      (window as unknown as { __BINDER_DESIGN__: unknown }).__BINDER_DESIGN__ = d;
    }, design);

    const page = await context.newPage();
    // proof=1: real colours (no sentinels) — the raster IS the artwork, nothing is rewritten afterwards.
    await page.goto(`${baseUrl}/print-render/${spec.template}?proof=1`, { waitUntil: 'load', timeout: cfg.renderTimeoutMs });
    await page.waitForFunction(
      () => {
        const win = window as unknown as { __RENDER_READY__?: boolean; __RENDER_ERROR__?: string };
        return win.__RENDER_READY__ === true || !!win.__RENDER_ERROR__;
      },
      undefined,
      { timeout: cfg.renderTimeoutMs },
    );
    const error = await page.evaluate(() => (window as unknown as { __RENDER_ERROR__?: string }).__RENDER_ERROR__);
    if (error) throw new Error(`Print route failed: ${error}${blocked.length ? ` (blocked requests: ${blocked.join(', ')})` : ''}`);

    const measured = await page.evaluate(() => (window as unknown as { __MEASURED__?: MeasuredText[] }).__MEASURED__ ?? []);
    const png = await page.screenshot({ type: 'png', clip: { x: 0, y: 0, width: vw, height: vh }, omitBackground: true });
    return { png, measured };
  } finally {
    await context.close();
  }
}
