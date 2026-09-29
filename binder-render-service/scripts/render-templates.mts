/**
 * Rasterise a template SVG to the overlay PNG and print it to the reference
 * PDF, with Chromium — so the labels use a real sans font and the drawing
 * matches what the editor and the print route show. Called by
 * make-templates.py; run from binder-render-service so Playwright and pdf-lib
 * resolve:
 *
 *   npx tsx scripts/render-templates.mts <stem> <spec.json>
 *
 * Writes <stem>-overlay.png (2400 px wide, alpha) and <stem>-template.pdf
 * (MediaBox = BleedBox = canvas, TrimBox inset by the bleed).
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { PDFDocument } from 'pdf-lib';

const [stem, specPath] = process.argv.slice(2);
if (!stem || !specPath) throw new Error('usage: render-templates.mts <stem> <spec.json>');

const spec = JSON.parse(readFileSync(specPath, 'utf8')) as { canvas_with_bleed_mm: { w: number; h: number }; bleed_mm: number; template: string };
const svg = readFileSync(`${stem}-template.svg`, 'utf8');
const { w, h } = spec.canvas_with_bleed_mm;
const OVERLAY_PX = 2400;

const browser = await chromium.launch({
  ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}),
  args: process.env.CHROMIUM_NO_SANDBOX === '1' ? ['--no-sandbox'] : [],
});
try {
  // Overlay PNG: the SVG scaled to 2400 px wide on a transparent page.
  const scale = OVERLAY_PX / ((w / 25.4) * 96);
  const png = await browser.newPage({ viewport: { width: OVERLAY_PX, height: Math.round((h / 25.4) * 96 * scale) }, deviceScaleFactor: 1 });
  await png.setContent(`<!doctype html><html><body style="margin:0;background:transparent">${svg.replace(/width="[^"]+" height="[^"]+"/, `width="${OVERLAY_PX}" height="${Math.round((h / 25.4) * 96 * scale)}"`)}</body></html>`);
  await png.evaluate(() => document.fonts.ready);
  writeFileSync(`${stem}-overlay.png`, await png.screenshot({ omitBackground: true, type: 'png' }));
  await png.close();

  // Reference PDF at true size, then the page boxes.
  const pdfPage = await browser.newPage();
  await pdfPage.setContent(`<!doctype html><html><head><style>@page{size:${w}mm ${h}mm;margin:0}html,body{margin:0;width:${w}mm;height:${h}mm;overflow:hidden}svg{display:block;width:${w}mm;height:${h}mm}</style></head><body>${svg}</body></html>`);
  await pdfPage.evaluate(() => document.fonts.ready);
  const raw = await pdfPage.pdf({ width: `${w}mm`, height: `${h}mm`, printBackground: true, margin: { top: '0', right: '0', bottom: '0', left: '0' }, preferCSSPageSize: true });
  await pdfPage.close();

  const pt = (mm: number) => (mm * 72) / 25.4;
  const doc = await PDFDocument.load(raw);
  const page = doc.getPage(0);
  page.setMediaBox(0, 0, pt(w), pt(h));
  page.setBleedBox(0, 0, pt(w), pt(h));
  page.setTrimBox(pt(spec.bleed_mm), pt(spec.bleed_mm), pt(w - 2 * spec.bleed_mm), pt(h - 2 * spec.bleed_mm));
  doc.setTitle(`Prime Printing ${spec.template} template`);
  doc.setProducer('make-templates.py');
  writeFileSync(`${stem}-template.pdf`, await doc.save({ useObjectStreams: false }));
  console.log(`rendered ${stem}: overlay ${OVERLAY_PX} px wide, PDF ${w} x ${h} mm`);
} finally {
  await browser.close();
}
