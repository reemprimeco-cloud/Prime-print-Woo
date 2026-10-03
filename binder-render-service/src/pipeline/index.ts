import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { customCutPath, mmToPx, planColors, STICKER, type DesignJSON, type PathCmd, type Spec } from '@binder/shared';
import sharp from 'sharp';
import type { Config } from '../config.ts';
import { renderArtboardPng, renderRgbPdf, type MeasuredText } from './browser.ts';
import { applyTextColors } from './colors.ts';
import { convertToCmyk } from './ghostscript.ts';
import { stampBoxes } from './boxes.ts';
import { stampCutLine } from './cutline.ts';
import { stampDieline } from './dieline.ts';
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
 *   -> exact CMYK text colours -> Ghostscript FOGRA39 -> TrimBox/BleedBox
 *   -> a sticker's cut line / a bag's Artwork + Dieline layers.
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

  const plan = planColors(design);
  const colouredCount = design.elements.filter((e) => e.type !== 'image').length;

  const { pdf: chromiumPdf, measured } = await renderRgbPdf(cfg, baseUrl, spec, design, assets);
  lap('browser');

  const cmykText = await applyTextColors(chromiumPdf, plan, 'cmyk');
  const rgbText = await applyTextColors(chromiumPdf, plan, 'rgb');
  // Every text element paints with a sentinel; if none were found the pipeline would silently
  // hand Ghostscript RGB black and print a rich black. Fail instead.
  if (colouredCount > 0 && (cmykText.replaced === 0 || rgbText.replaced === 0)) {
    throw new Error('Colour rewrite found no sentinel colours in the PDF — refusing to produce a print file.');
  }
  lap('colors');

  // A custom-shape sticker is cut along its artwork: trace the outline from a
  // transparent capture of the same print page (contour.ts, the code the editor
  // used to show the customer the line), a border outside the artwork, kept
  // inside the trim box.
  let cut: PathCmd[] | undefined;
  if (spec.sticker?.shape === 'custom') {
    const dpi = 100;
    const size = { width: Math.round(mmToPx(spec.canvas_with_bleed_mm.w, dpi)), height: Math.round(mmToPx(spec.canvas_with_bleed_mm.h, dpi)) };
    const { png } = await renderArtboardPng(cfg, baseUrl, spec, design, assets, size);
    const alpha = await sharp(png).resize(size.width, size.height, { fit: 'fill' }).ensureAlpha().extractChannel(3).raw().toBuffer();
    cut = customCutPath(new Uint8Array(alpha.buffer, alpha.byteOffset, alpha.length), size.width, size.height, spec.canvas_with_bleed_mm, STICKER.custom_border_mm, {
      x: spec.bleed_mm,
      y: spec.bleed_mm,
      w: spec.trim_mm.w,
      h: spec.trim_mm.h,
    });
    lap('browser');
  }

  const work = await mkdtemp(join(tmpdir(), 'binder-render-'));
  try {
    const inPath = join(work, 'in.pdf');
    const outPath = join(work, 'out-cmyk.pdf');
    await writeFile(inPath, cmykText.pdf);
    await convertToCmyk(cfg, inPath, outPath);
    lap('ghostscript');

    const cmykRaw = await readFile(outPath);
    const [cmyk, rgb] = await Promise.all([
      stampBoxes(cmykRaw, spec, { title: `${title} (CMYK print file)` }).then((p) => stampCutLine(p, spec, cut)).then((p) => stampDieline(p, spec)),
      stampBoxes(rgbText.pdf, spec, { title: `${title} (RGB proof)` }).then((p) => stampCutLine(p, spec, cut)).then((p) => stampDieline(p, spec)),
    ]);
    lap('boxes');

    return { rgb, cmyk, measured, timingsMs: t };
  } finally {
    await rm(work, { recursive: true, force: true });
  }
}
