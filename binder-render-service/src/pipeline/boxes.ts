import { PDFArray, PDFDocument, PDFName, type PDFRef } from 'pdf-lib';
import { mmToPt, type Spec } from '@binder/shared';

/**
 * Give the PDF the exact page geometry of the reference template PDFs:
 *
 *   MediaBox = BleedBox = the whole canvas (canvas_with_bleed_mm)
 *   TrimBox  = the canvas inset by bleed_mm on every side
 *
 * Chromium sizes its page in whole 300-dpi device pixels, so the page it
 * writes is a fraction of a millimetre off the canvas (691.049 x 355.939 mm for
 * 691 x 356). The artwork inside is laid out from the top-left corner, so the
 * page is cropped/extended at the right and bottom and the content is shifted
 * vertically (PDF origin is bottom-left) so the canvas' top-left corner stays
 * exactly where it was. A shift larger than one point means the page setup is
 * wrong, and is an error rather than something to paper over.
 */
export async function stampBoxes(pdf: Uint8Array, spec: Spec, meta: { title: string }): Promise<Uint8Array> {
  const doc = await PDFDocument.load(pdf);
  if (doc.getPageCount() !== 1) {
    throw new Error(`Expected a single page, got ${doc.getPageCount()} (content overflowed the canvas)`);
  }

  const page = doc.getPage(0);
  const media = page.getMediaBox();
  const W = mmToPt(spec.canvas_with_bleed_mm.w);
  const H = mmToPt(spec.canvas_with_bleed_mm.h);
  const bleed = mmToPt(spec.bleed_mm);

  if (Math.abs(media.width - W) > 1 || Math.abs(media.height - H) > 1) {
    throw new Error(
      `Rendered page is ${media.width.toFixed(2)} x ${media.height.toFixed(2)} pt but the template canvas is ${W.toFixed(2)} x ${H.toFixed(2)} pt`,
    );
  }

  // Shift so the canvas top edge lands on the top edge of the exact-size page.
  const dy = H - (media.y + media.height);
  const ctx = doc.context;
  const pre = ctx.register(ctx.stream(`q 1 0 0 1 ${-media.x} ${dy} cm\n`));
  const post = ctx.register(ctx.stream('\nQ\n'));
  const existing = page.node.get(PDFName.of('Contents'));
  const middle: PDFRef[] = existing instanceof PDFArray ? (existing.asArray() as PDFRef[]) : [existing as PDFRef];
  page.node.set(PDFName.of('Contents'), ctx.obj([pre, ...middle, post]));

  page.setMediaBox(0, 0, W, H);
  page.setBleedBox(0, 0, W, H);
  page.setTrimBox(bleed, bleed, W - 2 * bleed, H - 2 * bleed);

  doc.setTitle(meta.title);
  doc.setProducer('Prime Printing binder render service');
  doc.setCreator('binder-render-service');

  return doc.save({ useObjectStreams: false });
}
