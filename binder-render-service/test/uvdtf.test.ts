import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import sharp from 'sharp';
import { uvdtfSpec } from '@binder/shared';
import { closeBrowser } from '../src/pipeline/browser.ts';
import { readSpotTiff } from '../src/pipeline/tiff-spot.ts';
import { post, request, sample, startService, testConfig } from './helpers.ts';

let svc: Awaited<ReturnType<typeof startService>>;

beforeAll(async () => {
  svc = await startService(testConfig());
}, 60_000);

afterAll(async () => {
  await svc.close();
  await closeBrowser();
});

describe('UV DTF transfer: a transparent PNG and a CMYK TIFF with White and Varnish spot channels', () => {
  it('renders the sample at the artboard size and names the files by kind', async () => {
    const d = sample('uvdtf-text-logo');
    const spec = uvdtfSpec(d.sticker!);
    expect(spec.canvas_with_bleed_px).toEqual({ w: 591, h: 354 });

    const res = await post(svc.url, request(d));
    const body: any = await res.json();
    expect(res.status, JSON.stringify(body)).toBe(200);
    expect(body).toMatchObject({ status: 'ready', proof_kind: 'png', print_kind: 'tiff' });
    expect(body.pdf_cmyk_url).toBeUndefined();
    expect(body.files.png).toMatch(/\/png\.png\?exp=/);
    expect(body.files.tiff).toMatch(/\/tiff\.tif\?exp=/);

    const get = async (u: string) => {
      const r = await fetch(u);
      expect(r.status).toBe(200);
      return { type: r.headers.get('content-type'), bytes: Buffer.from(await r.arrayBuffer()) };
    };
    const png = await get(body.files.png);
    const tiff = await get(body.files.tiff);
    expect(png.type).toBe('image/png');
    expect(tiff.type).toBe('image/tiff');

    // PNG: exact pixel size, 300 dpi, transparent where nothing was placed.
    const meta = await sharp(png.bytes).metadata();
    expect([meta.width, meta.height, meta.channels, meta.density]).toEqual([591, 354, 4, 300]);
    const rgba = await sharp(png.bytes).raw().toBuffer();
    const at = (x: number, y: number) => Array.from(rgba.subarray((y * 591 + x) * 4, (y * 591 + x) * 4 + 4));
    expect(at(585, 348)[3]).toBe(0); // bottom-right corner: empty
    expect(at(150, 150)[3]).toBe(255); // inside the logo

    // TIFF: CMYK + White + Varnish, no ink where transparent, solid spot ink under the logo.
    const t = readSpotTiff(tiff.bytes);
    expect(t).toMatchObject({ width: 591, height: 354, samplesPerPixel: 7, photometric: 5, extraSamples: [2, 0, 0], dpi: 300, spotNames: ['White', 'Varnish'], hasIcc: true });
    const px = (x: number, y: number) => Array.from(t.pixels.subarray((y * 591 + x) * 7, (y * 591 + x) * 7 + 7));
    expect(px(585, 348)).toEqual([0, 0, 0, 0, 0, 255, 255]); // no ink, transparent, no white, no varnish
    const logo = px(150, 150);
    expect(logo[4]).toBe(255); // opaque
    expect(logo[5]).toBe(0); // solid white
    expect(logo[6]).toBe(0); // solid varnish
    expect(logo.slice(0, 4).some((v) => v > 0)).toBe(true);
  }, 90_000);

  it('refuses a fill or a shape: a transfer is text and pictures only', async () => {
    const d = sample('uvdtf-text-logo');
    const res = await post(svc.url, request({ ...d, elements: [{ type: 'rect', x_mm: 0, y_mm: 0, w_mm: 50, h_mm: 30, color_cmyk: [0, 0, 0, 100] }, ...d.elements] }));
    expect(res.status).toBe(422);
    expect(((await res.json()) as any).errors.map((e: any) => e.code)).toContain('shape.uvdtf_element');
  });

  it('refuses a transfer whose size is missing or does not match its canvas', async () => {
    const d = sample('uvdtf-text-logo');
    const { sticker: _dropped, ...noParams } = d;
    expect((await post(svc.url, request(noParams as never))).status).toBe(422);
    expect((await post(svc.url, request({ ...d, sticker: { ...d.sticker!, w_mm: 70 } }))).status).toBe(422);
  });
});
