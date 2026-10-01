import { deflateSync, inflateSync } from 'node:zlib';

/**
 * A CMYK TIFF with named spot-colour channels, the way Photoshop saves one:
 * what the UV DTF RIP needs to print white ink and varnish exactly where the
 * artwork is (Reem, 2026-10-01: "TIFF file with 2 spot channels, 1 is white
 * and 2 is varnish").
 *
 * Layout (TIFF 6 + Adobe's Photoshop additions):
 *   - Photometric Separated, InkSet CMYK, 8 bits, 4 + N samples per pixel,
 *     interleaved, Deflate-compressed strips, 300 dpi;
 *   - the extra samples are declared "unspecified" (not alpha) so a reader
 *     that knows nothing about Photoshop does not treat them as transparency;
 *   - the Photoshop image-resource block (tag 34377) names each extra channel
 *     and marks it a spot colour, with a display colour and solidity, through
 *     resources 1006 (names), 1045 (Unicode names), 1007 and 1077 (DisplayInfo);
 *   - the ICC profile the CMYK was converted to is embedded (tag 34675).
 *
 * Channel polarity follows Photoshop's spot channels: 0 = solid ink, 255 = no
 * ink (a spot channel is painted black where it prints). CMYK samples are TIFF
 * ink amounts: 0 = no ink, 255 = solid.
 */

export interface SpotChannel {
  name: string;
  /** Per pixel, 0 = solid ink … 255 = no ink. Length = width * height. */
  ink: Uint8Array;
  /** How Photoshop shows the channel on screen: sRGB 0..255. */
  display: [number, number, number];
  /** Ink solidity 0..100 (what Photoshop calls "solidity" / opacity). */
  solidity: number;
}

export interface SpotTiffInput {
  width: number;
  height: number;
  /** Interleaved C, M, Y, K ink amounts, 0..255, length = width * height * 4. */
  cmyk: Uint8Array;
  spots: SpotChannel[];
  dpi: number;
  /** The CMYK profile the data is in; embedded as-is. */
  icc?: Uint8Array;
  /** Rows per compressed strip. */
  rowsPerStrip?: number;
}

// --- Photoshop image resources ------------------------------------------------------------------------

function pascal(name: string): Buffer {
  const bytes = Buffer.from(name, 'latin1').subarray(0, 255);
  const out = Buffer.alloc(1 + bytes.length + ((1 + bytes.length) % 2)); // padded to even length
  out[0] = bytes.length;
  bytes.copy(out, 1);
  return out;
}

function resource(id: number, data: Buffer): Buffer {
  const head = Buffer.alloc(4 + 2 + 2 + 4);
  head.write('8BIM', 0, 'latin1');
  head.writeUInt16BE(id, 4);
  head.writeUInt16BE(0, 6); // empty Pascal name, padded to 2 bytes
  head.writeUInt32BE(data.length, 8);
  return Buffer.concat([head, data, data.length % 2 ? Buffer.alloc(1) : Buffer.alloc(0)]);
}

/** The resources that make the extra channels named spot colours. */
export function photoshopResources(spots: SpotChannel[], dpi: number): Buffer {
  // 1005 ResolutionInfo: hRes (16.16 fixed), hResUnit 1 = ppi, widthUnit 2 = cm, then the same vertically.
  const resInfo = Buffer.alloc(16);
  resInfo.writeUInt32BE(Math.round(dpi * 65536), 0);
  resInfo.writeUInt16BE(1, 4);
  resInfo.writeUInt16BE(2, 6);
  resInfo.writeUInt32BE(Math.round(dpi * 65536), 8);
  resInfo.writeUInt16BE(1, 12);
  resInfo.writeUInt16BE(2, 14);

  // 1006 AlphaNamesList: a series of Pascal strings (unpadded, per the spec).
  const names = Buffer.concat(
    spots.map((s) => {
      const b = Buffer.from(s.name, 'latin1').subarray(0, 255);
      return Buffer.concat([Buffer.from([b.length]), b]);
    }),
  );

  // 1045 Unicode alpha names: uint32 length (in characters, incl. terminator) + UTF-16BE + 0.
  const unicode = Buffer.concat(
    spots.map((s) => {
      const chars = Buffer.from(s.name + '\0', 'utf16le').swap16();
      const len = Buffer.alloc(4);
      len.writeUInt32BE(chars.length / 2, 0);
      return Buffer.concat([len, chars]);
    }),
  );

  // Display colour as 16-bit RGB components (colour space 0 = RGB).
  const colour = (s: SpotChannel): Buffer => {
    const b = Buffer.alloc(2 + 8 + 2);
    b.writeUInt16BE(0, 0);
    s.display.forEach((v, i) => b.writeUInt16BE(Math.round((v / 255) * 65535), 2 + i * 2));
    b.writeUInt16BE(0, 8);
    b.writeUInt16BE(Math.max(0, Math.min(100, Math.round(s.solidity))), 10);
    return b;
  };

  // 1007 DisplayInfo (classic): per channel colour, opacity, kind (1 = protected, i.e. a spot colour), padding.
  const classic = Buffer.concat(spots.map((s) => Buffer.concat([colour(s), Buffer.from([1, 0])])));

  // 1077 DisplayInfo (current): version 1, then per channel colour, opacity, mode 2 = spot colour, padding 1.
  const version = Buffer.alloc(4);
  version.writeUInt32BE(1, 0);
  const current = Buffer.concat([version, ...spots.map((s) => Buffer.concat([colour(s), Buffer.from([2, 1])]))]);

  return Buffer.concat([resource(1005, resInfo), resource(1006, names), resource(1045, unicode), resource(1007, classic), resource(1077, current)]);
}

// --- TIFF ----------------------------------------------------------------------------------------------

const TYPE = { BYTE: 1, ASCII: 2, SHORT: 3, LONG: 4, RATIONAL: 5, UNDEFINED: 7 } as const;

interface Entry {
  tag: number;
  type: number;
  count: number;
  value: Buffer; // raw bytes of the value(s), little-endian
}

const u16 = (...v: number[]): Buffer => {
  const b = Buffer.alloc(v.length * 2);
  v.forEach((x, i) => b.writeUInt16LE(x, i * 2));
  return b;
};
const u32 = (...v: number[]): Buffer => {
  const b = Buffer.alloc(v.length * 4);
  v.forEach((x, i) => b.writeUInt32LE(x >>> 0, i * 4));
  return b;
};
const rational = (n: number, d: number): Buffer => u32(n, d);

/** Build the file. Little-endian ("II"), one IFD, strips after the IFD. */
export function encodeSpotTiff(input: SpotTiffInput): Buffer {
  const { width, height, cmyk, spots, dpi } = input;
  const extra = spots.length;
  const spp = 4 + extra;
  if (cmyk.length !== width * height * 4) throw new Error('cmyk buffer has the wrong length');
  for (const s of spots) if (s.ink.length !== width * height) throw new Error(`spot channel "${s.name}" has the wrong length`);

  const rowsPerStrip = Math.max(1, Math.min(height, input.rowsPerStrip ?? 64));
  const stripCount = Math.ceil(height / rowsPerStrip);

  // Interleave per strip and compress.
  const strips: Buffer[] = [];
  const rowBytes = width * spp;
  for (let s = 0; s < stripCount; s++) {
    const y0 = s * rowsPerStrip;
    const y1 = Math.min(height, y0 + rowsPerStrip);
    const raw = Buffer.alloc((y1 - y0) * rowBytes);
    let o = 0;
    for (let y = y0; y < y1; y++) {
      let src = y * width * 4;
      let p = y * width;
      for (let x = 0; x < width; x++) {
        raw[o++] = cmyk[src]!;
        raw[o++] = cmyk[src + 1]!;
        raw[o++] = cmyk[src + 2]!;
        raw[o++] = cmyk[src + 3]!;
        for (let k = 0; k < extra; k++) raw[o++] = spots[k]!.ink[p]!;
        src += 4;
        p++;
      }
    }
    strips.push(deflateSync(raw, { level: 6 }));
  }

  const photoshop = photoshopResources(spots, dpi);
  const software = Buffer.from('Prime Printing binder-render-service\0', 'latin1');

  const entries: Entry[] = [
    { tag: 256, type: TYPE.LONG, count: 1, value: u32(width) }, // ImageWidth
    { tag: 257, type: TYPE.LONG, count: 1, value: u32(height) }, // ImageLength
    { tag: 258, type: TYPE.SHORT, count: spp, value: u16(...Array(spp).fill(8)) }, // BitsPerSample
    { tag: 259, type: TYPE.SHORT, count: 1, value: u16(8) }, // Compression: Adobe Deflate
    { tag: 262, type: TYPE.SHORT, count: 1, value: u16(5) }, // Photometric: Separated
    { tag: 273, type: TYPE.LONG, count: stripCount, value: Buffer.alloc(stripCount * 4) }, // StripOffsets (patched below)
    { tag: 277, type: TYPE.SHORT, count: 1, value: u16(spp) }, // SamplesPerPixel
    { tag: 278, type: TYPE.LONG, count: 1, value: u32(rowsPerStrip) }, // RowsPerStrip
    { tag: 279, type: TYPE.LONG, count: stripCount, value: u32(...strips.map((b) => b.length)) }, // StripByteCounts
    { tag: 282, type: TYPE.RATIONAL, count: 1, value: rational(dpi, 1) }, // XResolution
    { tag: 283, type: TYPE.RATIONAL, count: 1, value: rational(dpi, 1) }, // YResolution
    { tag: 284, type: TYPE.SHORT, count: 1, value: u16(1) }, // PlanarConfiguration: chunky
    { tag: 296, type: TYPE.SHORT, count: 1, value: u16(2) }, // ResolutionUnit: inch
    { tag: 305, type: TYPE.ASCII, count: software.length, value: software }, // Software
    { tag: 332, type: TYPE.SHORT, count: 1, value: u16(1) }, // InkSet: CMYK
    ...(extra ? [{ tag: 338, type: TYPE.SHORT, count: extra, value: u16(...Array(extra).fill(0)) }] : []), // ExtraSamples: unspecified
    { tag: 34377, type: TYPE.BYTE, count: photoshop.length, value: photoshop }, // Photoshop image resources
    ...(input.icc ? [{ tag: 34675, type: TYPE.UNDEFINED, count: input.icc.length, value: Buffer.from(input.icc) }] : []), // ICC profile
  ].sort((a, b) => a.tag - b.tag);

  // Layout: header (8) | IFD (2 + 12n + 4) | out-of-line values | strips.
  const ifdSize = 2 + entries.length * 12 + 4;
  let cursor = 8 + ifdSize;
  const outOfLine: Buffer[] = [];
  const valueOffset = new Map<Entry, number>();
  for (const e of entries) {
    if (e.value.length > 4) {
      valueOffset.set(e, cursor);
      const padded = e.value.length % 2 ? Buffer.concat([e.value, Buffer.alloc(1)]) : e.value;
      outOfLine.push(padded);
      cursor += padded.length;
    }
  }
  const stripOffsets: number[] = [];
  for (const b of strips) {
    stripOffsets.push(cursor);
    cursor += b.length + (b.length % 2);
  }
  // StripOffsets was laid out as a placeholder of the right size; fill it in place.
  const offsetsEntry = entries.find((e) => e.tag === 273)!;
  u32(...stripOffsets).copy(offsetsEntry.value);

  const header = Buffer.alloc(8);
  header.write('II', 0, 'latin1');
  header.writeUInt16LE(42, 2);
  header.writeUInt32LE(8, 4);

  const ifd = Buffer.alloc(ifdSize);
  ifd.writeUInt16LE(entries.length, 0);
  entries.forEach((e, i) => {
    const at = 2 + i * 12;
    ifd.writeUInt16LE(e.tag, at);
    ifd.writeUInt16LE(e.type, at + 2);
    ifd.writeUInt32LE(e.count, at + 4);
    if (e.value.length > 4) ifd.writeUInt32LE(valueOffset.get(e)!, at + 8);
    else e.value.copy(ifd, at + 8); // left-justified in the 4-byte field
  });
  ifd.writeUInt32LE(0, ifdSize - 4); // no next IFD

  return Buffer.concat([header, ifd, ...outOfLine, ...strips.flatMap((b) => (b.length % 2 ? [b, Buffer.alloc(1)] : [b]))]);
}

// --- Reading back (for tests and the health check) ----------------------------------------------------

export interface SpotTiffInfo {
  width: number;
  height: number;
  samplesPerPixel: number;
  photometric: number;
  compression: number;
  extraSamples: number[];
  dpi: number;
  spotNames: string[];
  hasIcc: boolean;
  /** Decoded interleaved samples (width * height * samplesPerPixel). */
  pixels: Uint8Array;
}

/** Enough of a TIFF reader to check what encodeSpotTiff() wrote. Single IFD, little-endian, Deflate. */
export function readSpotTiff(file: Buffer): SpotTiffInfo {
  if (file.toString('latin1', 0, 2) !== 'II' || file.readUInt16LE(2) !== 42) throw new Error('not a little-endian TIFF');
  const ifd = file.readUInt32LE(4);
  const n = file.readUInt16LE(ifd);
  const tags = new Map<number, { type: number; count: number; data: Buffer }>();
  const sizes: Record<number, number> = { 1: 1, 2: 1, 3: 2, 4: 4, 5: 8, 7: 1 };
  for (let i = 0; i < n; i++) {
    const at = ifd + 2 + i * 12;
    const tag = file.readUInt16LE(at);
    const type = file.readUInt16LE(at + 2);
    const count = file.readUInt32LE(at + 4);
    const bytes = (sizes[type] ?? 1) * count;
    const data = bytes > 4 ? file.subarray(file.readUInt32LE(at + 8), file.readUInt32LE(at + 8) + bytes) : file.subarray(at + 8, at + 8 + bytes);
    tags.set(tag, { type, count, data });
  }
  const shorts = (tag: number): number[] => {
    const t = tags.get(tag);
    if (!t) return [];
    return Array.from({ length: t.count }, (_, i) => (t.type === 3 ? t.data.readUInt16LE(i * 2) : t.data.readUInt32LE(i * 4)));
  };
  const width = shorts(256)[0]!;
  const height = shorts(257)[0]!;
  const spp = shorts(277)[0]!;
  const offsets = shorts(273);
  const counts = shorts(279);
  const pixels = Buffer.concat(offsets.map((o, i) => (shorts(259)[0] === 8 ? inflateSync(file.subarray(o, o + counts[i]!)) : file.subarray(o, o + counts[i]!))));

  const names: string[] = [];
  const ps = tags.get(34377)?.data;
  if (ps) {
    let p = 0;
    while (p + 12 <= ps.length && ps.toString('latin1', p, p + 4) === '8BIM') {
      const id = ps.readUInt16BE(p + 4);
      const nameLen = ps[p + 6]!;
      let q = p + 7 + nameLen;
      if ((1 + nameLen) % 2) q++;
      const size = ps.readUInt32BE(q);
      const data = ps.subarray(q + 4, q + 4 + size);
      if (id === 1006) {
        let r = 0;
        while (r < data.length) {
          const l = data[r]!;
          names.push(data.toString('latin1', r + 1, r + 1 + l));
          r += 1 + l;
        }
      }
      p = q + 4 + size + (size % 2);
    }
  }

  const xres = tags.get(282)?.data;
  return {
    width,
    height,
    samplesPerPixel: spp,
    photometric: shorts(262)[0] ?? 0,
    compression: shorts(259)[0] ?? 1,
    extraSamples: shorts(338),
    dpi: xres ? xres.readUInt32LE(0) / xres.readUInt32LE(4) : 0,
    spotNames: names,
    hasIcc: tags.has(34675),
    pixels: new Uint8Array(pixels.buffer, pixels.byteOffset, pixels.length),
  };
}

