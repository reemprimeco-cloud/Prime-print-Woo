import { PDFDocument, PDFName, PDFRawStream, decodePDFRawStream } from 'pdf-lib';
import type { ColorPlan } from '@binder/shared';

type Mode = 'cmyk' | 'rgb';

/** Naive CMYK->sRGB, only for the on-screen RGB proof PDF. Never used for print. */
function proofRgb([c, m, y, k]: [number, number, number, number]): [number, number, number] {
  const kk = 1 - k / 100;
  return [(1 - c / 100) * kk, (1 - m / 100) * kk, (1 - y / 100) * kk];
}

const NUM = String.raw`(-?(?:\d+\.?\d*|\.\d+)(?:e-?\d+)?)`;
// "r g b rg" / "r g b RG" as whole tokens.
const RGB_OP = new RegExp(String.raw`(?<![\w/.])${NUM}\s+${NUM}\s+${NUM}\s+(rg|RG)(?![\w])`, 'g');

const fmt = (n: number): string => String(Math.round(n * 10000) / 10000);

/**
 * Rewrite the sentinel RGB colours Chromium wrote for text (see
 * @binder/shared colors.ts) into either the design's exact DeviceCMYK values
 * ("cmyk", for the print file) or a plain sRGB approximation ("rgb", for the
 * customer's on-screen proof).
 *
 * Only the sentinel values are touched; any other colour operator is left for
 * Ghostscript. Returns the number of operators rewritten so callers can assert
 * that every text colour was actually found.
 */
export async function applyTextColors(
  pdf: Uint8Array,
  plan: ColorPlan,
  mode: Mode,
): Promise<{ pdf: Uint8Array; replaced: number }> {
  const doc = await PDFDocument.load(pdf);
  const ctx = doc.context;
  let replaced = 0;

  const bySentinel = new Map(plan.entries.map((e) => [e.sentinel.join(','), e]));

  for (const [ref, obj] of ctx.enumerateIndirectObjects()) {
    if (!(obj instanceof PDFRawStream)) continue;

    const subtype = obj.dict.get(PDFName.of('Subtype'));
    const type = obj.dict.get(PDFName.of('Type'));
    // Content streams are the page's /Contents and Form XObjects; skip everything else.
    const isForm = subtype?.toString() === '/Form';
    const isKnownOther =
      subtype?.toString() === '/Image' ||
      type?.toString() === '/XRef' ||
      type?.toString() === '/ObjStm' ||
      type?.toString() === '/Metadata' ||
      obj.dict.has(PDFName.of('Length1')) ||
      obj.dict.has(PDFName.of('FunctionType')) ||
      obj.dict.has(PDFName.of('N')); // ICC profile streams
    if (isKnownOther && !isForm) continue;

    let bytes: Uint8Array;
    try {
      bytes = decodePDFRawStream(obj).decode();
    } catch {
      continue; // not a stream this code understands (e.g. DCT); leave it alone
    }

    const text = Buffer.from(bytes).toString('latin1');
    if (!RGB_OP.test(text)) {
      RGB_OP.lastIndex = 0;
      continue;
    }
    RGB_OP.lastIndex = 0;

    let touched = 0;
    const patched = text.replace(RGB_OP, (whole, r: string, g: string, b: string, op: string) => {
      const key = [r, g, b].map((v) => Math.round(parseFloat(v) * 255)).join(',');
      const entry = bySentinel.get(key);
      if (!entry) return whole;
      touched++;
      if (mode === 'cmyk') {
        const [c, m, y, k] = entry.cmyk;
        return `${fmt(c / 100)} ${fmt(m / 100)} ${fmt(y / 100)} ${fmt(k / 100)} ${op === 'rg' ? 'k' : 'K'}`;
      }
      const [rr, gg, bb] = proofRgb(entry.cmyk);
      return `${fmt(rr)} ${fmt(gg)} ${fmt(bb)} ${op}`;
    });

    if (touched === 0) continue;
    replaced += touched;

    // Keep a Form XObject's own entries (BBox, Matrix, Resources...); drop only what the new encoding replaces.
    const dictEntries: Record<string, unknown> = {};
    for (const [key, value] of obj.dict.entries()) {
      const name = key.toString();
      if (name === '/Filter' || name === '/Length' || name === '/DecodeParms') continue;
      dictEntries[name.slice(1)] = value;
    }
    ctx.assign(ref, ctx.flateStream(Buffer.from(patched, 'latin1'), dictEntries as never));
  }

  return { pdf: await doc.save({ useObjectStreams: false }), replaced };
}
