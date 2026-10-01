import { PDFArray, PDFDict, PDFDocument, PDFName } from 'pdf-lib';
import { mmToPt, stickerOutlines, type PathCmd, type Spec } from '@binder/shared';

/**
 * Draw the sticker's cut line on the finished PDF as a "CutContour" spot
 * colour: the convention cutting plotters and RIPs (Roland VersaWorks,
 * Graphtec, Summa) read as the contour to cut rather than something to print.
 * It is a hairline stroke in a Separation colour space whose alternate is
 * 100 % magenta, set to overprint so it knocks nothing out of the artwork.
 *
 * Applied after Ghostscript: the CMYK conversion would otherwise fold the spot
 * colour into process magenta and the plotter would never see it.
 */
export async function stampCutLine(pdf: Uint8Array, spec: Spec, cut?: PathCmd[]): Promise<Uint8Array> {
  if (!spec.sticker) return pdf;
  if (cut && cut.length === 0) return pdf; // a custom shape with nothing to trace: no cut line

  const doc = await PDFDocument.load(pdf);
  const page = doc.getPage(0);
  const ctx = doc.context;
  const H = mmToPt(spec.canvas_with_bleed_mm.h);

  // Separation /CutContour, alternate DeviceCMYK, tint 1 -> 0 1 0 0.
  const tint = ctx.register(
    ctx.obj({
      FunctionType: 2,
      Domain: [0, 1],
      C0: [0, 0, 0, 0],
      C1: [0, 1, 0, 0],
      N: 1,
    }),
  );
  const colorSpace = ctx.register(ctx.obj([PDFName.of('Separation'), PDFName.of('CutContour'), PDFName.of('DeviceCMYK'), tint]));
  const overprint = ctx.register(ctx.obj({ Type: 'ExtGState', OP: true, op: true, OPM: 1 }));

  // normalize() guarantees a Resources dict and a Contents array.
  page.node.normalize();
  const resources = page.node.Resources()!;
  const colorSpaces = resources.lookupMaybe(PDFName.of('ColorSpace'), PDFDict) ?? (() => {
    const d = ctx.obj({});
    resources.set(PDFName.of('ColorSpace'), d);
    return d;
  })();
  colorSpaces.set(PDFName.of('CSCutContour'), colorSpace);
  page.node.setExtGState(PDFName.of('GSCutContour'), overprint);

  const ops = pathOps(cut ?? stickerOutlines(spec).cut, H);
  const stream = ctx.register(ctx.stream(`q /GSCutContour gs /CSCutContour CS 1 SCN 0.25 w 0 J 0 j\n${ops} S\nQ\n`));
  (page.node.Contents() as PDFArray).push(stream);

  return doc.save({ useObjectStreams: false });
}

/** Path commands in canvas mm (y down) -> PDF path operators in points (y up). */
export function pathOps(cmds: PathCmd[], pageHeightPt: number): string {
  const X = (mm: number) => mmToPt(mm).toFixed(3);
  const Y = (mm: number) => (pageHeightPt - mmToPt(mm)).toFixed(3);
  return cmds
    .map((c) => {
      switch (c.c) {
        case 'M':
          return `${X(c.x)} ${Y(c.y)} m`;
        case 'L':
          return `${X(c.x)} ${Y(c.y)} l`;
        case 'C':
          return `${X(c.x1)} ${Y(c.y1)} ${X(c.x2)} ${Y(c.y2)} ${X(c.x)} ${Y(c.y)} c`;
        case 'Z':
          return 'h';
      }
    })
    .join('\n');
}
