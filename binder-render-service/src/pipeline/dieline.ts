import { PDFArray, PDFDict, PDFDocument, PDFName, PDFString } from 'pdf-lib';
import { bagDieline, mmToPt, type Spec } from '@binder/shared';
import { pathOps } from './cutline.ts';

/**
 * The paper bag's print file is one page with two layers (PDF optional
 * content groups, what Illustrator and Acrobat show as layers):
 *
 *   Artwork  — everything Chromium drew: the design in CMYK, out to the bleed.
 *   Dieline  — the cut (sheet outline and handle holes) in the "CutContour"
 *              spot colour and every fold in a "Crease" spot colour, drawn as
 *              hairlines that overprint, so the die-maker reads them and the
 *              press never prints them.
 *
 * Both layers are on by default. Reem asked for "the diecut + the design in
 * CMYK with bleeding" as separated layers of one PDF (2026-10-03).
 *
 * Applied after Ghostscript, like the sticker cut line: the CMYK conversion
 * would otherwise fold the spot colours into process inks.
 */
export async function stampDieline(pdf: Uint8Array, spec: Spec): Promise<Uint8Array> {
  if (!spec.bag) return pdf;

  const doc = await PDFDocument.load(pdf);
  const page = doc.getPage(0);
  const ctx = doc.context;
  const H = mmToPt(spec.canvas_with_bleed_mm.h);
  const die = bagDieline(spec);

  // The two layers.
  const artwork = ctx.register(ctx.obj({ Type: 'OCG', Name: PDFString.of('Artwork') }));
  const dieline = ctx.register(ctx.obj({ Type: 'OCG', Name: PDFString.of('Dieline') }));
  doc.catalog.set(
    PDFName.of('OCProperties'),
    ctx.obj({
      OCGs: [artwork, dieline],
      D: { Order: [artwork, dieline], ON: [artwork, dieline] },
    }),
  );

  // Spot colours: CutContour (alternate 100 % magenta) and Crease (100 % cyan).
  const separation = (name: string, c1: [number, number, number, number]) =>
    ctx.register(ctx.obj([PDFName.of('Separation'), PDFName.of(name), PDFName.of('DeviceCMYK'), ctx.register(ctx.obj({ FunctionType: 2, Domain: [0, 1], C0: [0, 0, 0, 0], C1: c1, N: 1 }))]));
  const overprint = ctx.register(ctx.obj({ Type: 'ExtGState', OP: true, op: true, OPM: 1 }));

  page.node.normalize();
  const resources = page.node.Resources()!;
  const dictIn = (key: string): PDFDict => {
    const found = resources.lookupMaybe(PDFName.of(key), PDFDict);
    if (found) return found;
    const d = ctx.obj({});
    resources.set(PDFName.of(key), d);
    return d;
  };
  dictIn('ColorSpace').set(PDFName.of('CSCutContour'), separation('CutContour', [0, 1, 0, 0]));
  dictIn('ColorSpace').set(PDFName.of('CSCrease'), separation('Crease', [1, 0, 0, 0]));
  dictIn('Properties').set(PDFName.of('OCArtwork'), artwork);
  dictIn('Properties').set(PDFName.of('OCDieline'), dieline);
  page.node.setExtGState(PDFName.of('GSDieline'), overprint);

  // Everything already on the page becomes the Artwork layer.
  const contents = page.node.Contents() as PDFArray;
  const existing = contents.asArray();
  const open = ctx.register(ctx.stream('/OC /OCArtwork BDC\n'));
  const close = ctx.register(ctx.stream('\nEMC\n'));

  // The Dieline layer: creases dashed in Crease, then the cut and the holes in CutContour.
  const X = (mm: number) => mmToPt(mm).toFixed(3);
  const Y = (mm: number) => (H - mmToPt(mm)).toFixed(3);
  const creases = die.creases.map((c) => `${X(c.x1)} ${Y(c.y1)} m ${X(c.x2)} ${Y(c.y2)} l S`).join('\n');
  const cut = [die.cut, ...die.holes].map((path) => `${pathOps(path, H)} S`).join('\n');
  const layer = ctx.register(
    ctx.stream(
      `/OC /OCDieline BDC\nq /GSDieline gs 0 J 0 j\n` +
        `/CSCrease CS 1 SCN 0.25 w [4 2] 0 d\n${creases}\n` +
        `/CSCutContour CS 1 SCN 0.25 w [] 0 d\n${cut}\n` +
        `Q\nEMC\n`,
    ),
  );

  page.node.set(PDFName.of('Contents'), ctx.obj([open, ...existing, close, layer]));

  return doc.save({ useObjectStreams: false });
}
