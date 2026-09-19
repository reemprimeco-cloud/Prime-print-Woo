import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { planTextColors, type DesignJSON, type Spec } from '@binder/shared';
import type { Config } from '../config.ts';
import { renderRgbPdf, type MeasuredText } from './browser.ts';
import { applyTextColors } from './colors.ts';
import { convertToCmyk } from './ghostscript.ts';
import { stampBoxes } from './boxes.ts';
import type { PreparedAssets } from './assets.ts';

export interface RenderedPdfs {
  /** sRGB proof (real-looking colours), exact page boxes. For the customer. */
  rgb: Uint8Array;
  /** Print file: DeviceCMYK, FOGRA39, exact page boxes. For the shop. */
  cmyk: Uint8Array;
  measured: MeasuredText[];
  timingsMs: { browser: number; colors: number; ghostscript: number; boxes: number };
}

/**
 * The whole production pipeline for one design (§5.2):
 *   Chromium print route -> PDF (RGB, sentinel text colours)
 *   -> exact CMYK text colours -> Ghostscript FOGRA39 -> TrimBox/BleedBox.
 */
export async function renderDesign(
  cfg: Config,
  baseUrl: string,
  spec: Spec,
  design: DesignJSON,
  title: string,
  assets?: Pick<PreparedAssets, 'files'>,
): Promise<RenderedPdfs> {
  const t: RenderedPdfs['timingsMs'] = { browser: 0, colors: 0, ghostscript: 0, boxes: 0 };
  let mark = Date.now();
  const lap = (k: keyof typeof t) => {
    t[k] += Date.now() - mark;
    mark = Date.now();
  };

  const plan = planTextColors(design);
  const textCount = design.elements.filter((e) => e.type === 'text').length;

  const { pdf: chromiumPdf, measured } = await renderRgbPdf(cfg, baseUrl, spec, design, assets);
  lap('browser');

  const cmykText = await applyTextColors(chromiumPdf, plan, 'cmyk');
  const rgbText = await applyTextColors(chromiumPdf, plan, 'rgb');
  // Every text element paints with a sentinel; if none were found the pipeline would silently
  // hand Ghostscript RGB black and print a rich black. Fail instead.
  if (textCount > 0 && (cmykText.replaced === 0 || rgbText.replaced === 0)) {
    throw new Error('Text colour rewrite found no sentinel colours in the PDF — refusing to produce a print file.');
  }
  lap('colors');

  const work = await mkdtemp(join(tmpdir(), 'binder-render-'));
  try {
    const inPath = join(work, 'in.pdf');
    const outPath = join(work, 'out-cmyk.pdf');
    await writeFile(inPath, cmykText.pdf);
    await convertToCmyk(cfg, inPath, outPath);
    lap('ghostscript');

    const cmykRaw = await readFile(outPath);
    const [cmyk, rgb] = await Promise.all([
      stampBoxes(cmykRaw, spec, { title: `${title} (CMYK print file)` }),
      stampBoxes(rgbText.pdf, spec, { title: `${title} (RGB proof)` }),
    ]);
    lap('boxes');

    return { rgb, cmyk, measured, timingsMs: t };
  } finally {
    await rm(work, { recursive: true, force: true });
  }
}
