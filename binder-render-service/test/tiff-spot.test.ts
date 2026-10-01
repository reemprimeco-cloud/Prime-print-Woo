import { describe, expect, it } from 'vitest';
import sharp from 'sharp';
import { encodeSpotTiff, readSpotTiff } from '../src/pipeline/tiff-spot.ts';

describe('spot-channel TIFF', () => {
  const w = 5;
  const h = 3;
  const cmyk = new Uint8Array(w * h * 4);
  const white = new Uint8Array(w * h);
  const varnish = new Uint8Array(w * h);
  for (let i = 0; i < w * h; i++) {
    cmyk.set([i * 10, 255 - i * 10, 7, i % 2 ? 255 : 0], i * 4);
    white[i] = i * 17;
    varnish[i] = 255 - i * 17;
  }
  const file = encodeSpotTiff({
    width: w,
    height: h,
    cmyk,
    spots: [
      { name: 'White', ink: white, display: [255, 255, 255], solidity: 100 },
      { name: 'Varnish', ink: varnish, display: [120, 200, 255], solidity: 30 },
    ],
    dpi: 300,
    icc: new Uint8Array([1, 2, 3, 4]),
    rowsPerStrip: 2,
  });

  it('is CMYK + two named spot channels at 300 dpi, Deflate, with the profile embedded', () => {
    const info = readSpotTiff(file);
    expect(info).toMatchObject({ width: w, height: h, samplesPerPixel: 6, photometric: 5, compression: 8, extraSamples: [0, 0], dpi: 300, spotNames: ['White', 'Varnish'], hasIcc: true });
  });

  it('round-trips every sample, across strips', () => {
    const { pixels } = readSpotTiff(file);
    expect(pixels.length).toBe(w * h * 6);
    for (let i = 0; i < w * h; i++) {
      expect(Array.from(pixels.subarray(i * 6, i * 6 + 6))).toEqual([...cmyk.subarray(i * 4, i * 4 + 4), white[i], varnish[i]]);
    }
  });

  it('is a TIFF libtiff can open: 6 channels, CMYK, 300 dpi', async () => {
    const meta = await sharp(Buffer.from(file)).metadata();
    expect(meta.format).toBe('tiff');
    expect(meta.width).toBe(w);
    expect(meta.height).toBe(h);
    expect(meta.channels).toBe(6);
    expect(meta.space).toBe('cmyk');
    expect(meta.density).toBe(300);
  });
});
