import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { createHmac } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { closeBrowser } from '../src/pipeline/browser.ts';
import { bagSpec, stickerSpec, validateDesign } from '@binder/shared';
import { loadSpec } from '../src/validate-request.ts';
import { ROOT, SECRET, listen, post, request, sample, startService, testConfig, verifyPdf } from './helpers.ts';

let svc: Awaited<ReturnType<typeof startService>>;
let cfg = testConfig();

beforeAll(async () => {
  svc = await startService(cfg);
}, 60_000);

afterAll(async () => {
  await svc.close();
  await closeBrowser();
});

async function ok(name: string): Promise<{ body: any; cmyk: Buffer; rgb: Buffer }> {
  const design = sample(name);
  const res = await post(svc.url, request(design));
  const body: any = await res.json();
  expect(res.status, JSON.stringify(body)).toBe(200);
  expect(body.status).toBe('ready');
  const get = async (u: string) => Buffer.from(await (await fetch(u)).arrayBuffer());
  return { body, cmyk: await get(body.pdf_cmyk_url), rgb: await get(body.pdf_rgb_url) };
}

describe('auth and health', () => {
  it('healthz reports every dependency present', async () => {
    const h: any = await (await fetch(`${svc.url}/healthz`)).json();
    expect(h).toMatchObject({ ok: true, ghostscript: true, icc_profile: true, print_page: true });
  });

  it('rejects a missing or wrong shared secret', async () => {
    const body = request(sample('outer-arabic-text'));
    expect((await post(svc.url, body, {})).status).toBe(401);
    expect((await post(svc.url, body, { 'X-Binder-Secret': 'nope' })).status).toBe(401);
    expect((await fetch(`${svc.url}/jobs/x`)).status).toBe(401);
  });

  it('signed file links: valid works, tampered and expired do not', async () => {
    const { body } = await ok('outer-arabic-text');
    expect((await fetch(body.pdf_cmyk_url)).status).toBe(200);
    expect((await fetch(body.pdf_cmyk_url.replace(/sig=[0-9a-f]{4}/, 'sig=0000'))).status).toBe(403);
    expect((await fetch(body.pdf_cmyk_url.replace(/exp=\d+/, 'exp=1'))).status).toBe(403);
    expect((await fetch(`${svc.url}/files/${'0'.repeat(32)}/cmyk.pdf`)).status).toBe(403);
  }, 60_000);
});

describe('valid designs render to verified print PDFs', () => {
  it('image only', async () => {
    const { cmyk } = await ok('outer-image-only');
    expect(verifyPdf(cmyk, 'binder_outer')).toContain('SUMMARY  OK');
  }, 90_000);

  it('Arabic text: K-only black, embedded fonts', async () => {
    const { cmyk, rgb } = await ok('outer-arabic-text');
    expect(verifyPdf(cmyk, 'binder_outer', ['--k-only'])).toContain('SUMMARY  OK');
    expect(verifyPdf(rgb, 'binder_outer', ['--rgb'])).toContain('SUMMARY  OK');
  }, 60_000);

  it('mixed: background + logo with alpha + Arabic + Latin + rotated spine', async () => {
    const { cmyk } = await ok('outer-mixed');
    expect(verifyPdf(cmyk, 'binder_outer')).toContain('SUMMARY  OK');
  }, 90_000);

  it('shapes, opacity, spine colour and a new font: exact CMYK for every fill', async () => {
    const { cmyk } = await ok('outer-shapes');
    const out = verifyPdf(cmyk, 'binder_outer', ['--expect-cmyk', '0.9 0 0.4 0.1', '--expect-cmyk', '0 0.25 0.85 0.1', '--expect-cmyk', '0 1 0.6 0', '--expect-cmyk', '1 0.85 0.3 0.45']);
    expect(out).toContain('SUMMARY  OK');
  }, 90_000);

  it('inner liner template', async () => {
    const { cmyk } = await ok('inner-mixed');
    expect(verifyPdf(cmyk, 'binder_inner')).toContain('SUMMARY  OK');
  }, 90_000);

  it('sticker (round, upload): page boxes from the derived spec, CutContour cut line on both files', async () => {
    const d = sample('sticker-round');
    const spec = stickerSpec(d.sticker!);
    expect(spec.canvas_with_bleed_mm).toEqual({ w: 52, h: 52 });
    const { cmyk, rgb } = await ok('sticker-round');
    expect(verifyPdf(cmyk, 'sticker', [], spec)).toContain('SUMMARY  OK');
    expect(verifyPdf(rgb, 'sticker', ['--rgb'], spec)).toContain('SUMMARY  OK');
  }, 90_000);

  it('sticker (star, designed online): exact CMYK background, logo, Arabic text, star cut line', async () => {
    const d = sample('sticker-star');
    const { cmyk } = await ok('sticker-star');
    expect(verifyPdf(cmyk, 'sticker', ['--expect-cmyk', '0 1 1 0'], stickerSpec(d.sticker!))).toContain('SUMMARY  OK');
  }, 90_000);
});

describe('paper bag: one PDF, the artwork and the dieline on separate layers', () => {
  it('bag-branded: page boxes from the derived spec, Artwork + Dieline layers, CutContour and Crease spot colours', async () => {
    const d = sample('bag-branded');
    const spec = bagSpec(d.bag!);
    expect(spec.canvas_with_bleed_mm).toEqual({ w: 581, h: 341 });
    const { cmyk, rgb } = await ok('bag-branded');
    expect(verifyPdf(cmyk, 'bag', [], spec)).toContain('SUMMARY  OK');
    expect(verifyPdf(rgb, 'bag', ['--rgb'], spec)).toContain('SUMMARY  OK');
    writeFileSync(join(cfg.outputDir, 'bag-branded.cmyk.pdf'), cmyk);
  }, 120_000);

  it('a bag design without its size, or for another size, is refused before rendering', async () => {
    const d = sample('bag-branded');
    const { bag: _dropped, ...noParams } = d;
    expect((await post(svc.url, request({ ...noParams, template: 'bag' } as never))).status).toBe(422);
    expect((await post(svc.url, request({ ...d, bag: { ...d.bag!, d_mm: 90 } }))).status).toBe(422);
    expect((await post(svc.url, request({ ...d, bag: { ...d.bag!, w_mm: 20 } }))).status).toBe(422);
  });
});

describe('sticker template: the design itself must say which sticker it is for', () => {
  it('a sticker design without a size, or whose canvas disagrees with its size, is refused before rendering', async () => {
    const d = sample('sticker-round');
    const { sticker: _dropped, ...noParams } = d;
    expect((await post(svc.url, request({ ...noParams, template: 'sticker' } as never))).status).toBe(422);
    expect((await post(svc.url, request({ ...d, sticker: { ...d.sticker!, w_mm: 70 } }))).status).toBe(422);
    expect((await post(svc.url, request({ ...d, sticker: { ...d.sticker!, shape: 'blob' } } as never))).status).toBe(422);
    expect((await post(svc.url, request({ ...d, sticker: { ...d.sticker!, w_mm: 5, h_mm: 5 }, canvas_mm: { w: 7, h: 7 } }))).status).toBe(422);
  });
});

describe('validation blocks bad designs BEFORE any rendering (§5.3)', () => {
  const stored = () => readdirSync(cfg.outputDir).length;

  it('low-resolution image is a hard block, and nothing is stored', async () => {
    const before = stored();
    const res = await post(svc.url, request(sample('outer-lowres')));
    const body: any = await res.json();
    expect(res.status).toBe(422);
    expect(body.error).toBe('validation_failed');
    expect(body.errors.map((e: any) => e.code)).toContain('dpi.block');
    expect(stored()).toBe(before);
  });

  it('text inside the turn-in zone is a hard block', async () => {
    const d = sample('outer-arabic-text');
    const t = d.elements[0]!;
    if (t.type === 'text') { t.x_mm = 5; t.w_mm = 60; } // 5 mm from the canvas edge: bleed + turn-in
    const res = await post(svc.url, request(d));
    expect(res.status).toBe(422);
    expect(((await res.json()) as any).errors.map((e: any) => e.code)).toContain('turnin.violation');
  });

  it('does not trust a client-declared text height: the browser measures the real one', async () => {
    const d = sample('outer-arabic-text');
    const t = d.elements[0]!;
    if (t.type !== 'text') throw new Error('sample changed');
    // Claims to be 5 mm tall (so it looks safely above the bottom turn-in edge)…
    t.h_mm = 5;
    t.size_pt = 60;
    t.text = 'سطر أول\nسطر ثان\nسطر ثالث\nسطر رابع';
    // …but is really four 60 pt lines, and sits near the bottom of the visible area.
    t.y_mm = 356 - 3 - 15 - 20;
    // Precondition: on what the client claims, the design is clean — so any refusal below comes from the measured re-check.
    expect(validateDesign(d, loadSpec(cfg, 'binder_outer')!).ok).toBe(true);
    const client = await post(svc.url, request(d)); // the final measured check must refuse
    expect(client.status).toBe(422);
    expect(((await client.json()) as any).errors.map((e: any) => e.code)).toContain('turnin.violation');
  }, 60_000);

  it('rejects malformed requests', async () => {
    const d = sample('outer-arabic-text');
    expect((await post(svc.url, { ...request(d), template: 'binder_nope' })).status).toBe(400);
    expect((await post(svc.url, { ...request(d), design_id: 'x' })).status).toBe(400);
    expect((await post(svc.url, { ...request(d), session_token: 'short' })).status).toBe(400);
    expect((await post(svc.url, [1, 2, 3])).status).toBe(400);
    const junk = await fetch(`${svc.url}/render`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Binder-Secret': SECRET }, body: '{nope' });
    expect(junk.status).toBe(400);
  });

  it('a design for the other template is refused', async () => {
    const res = await post(svc.url, { ...request(sample('inner-mixed')), template: 'binder_outer' });
    expect(res.status).toBe(422);
  });
});

describe('SSRF: design image URLs cannot reach anything but allowed hosts', () => {
  const withSrc = (src: string) => {
    const d = sample('outer-image-only');
    const el = d.elements[0]!;
    if (el.type === 'image') el.src = src;
    return d;
  };

  for (const src of ['http://169.254.169.254/latest/meta-data/', 'http://localhost:22/', 'https://evil.example/x.jpg', 'http://10.0.0.5/a.jpg']) {
    it(`refuses ${src}`, async () => {
      const res = await post(svc.url, request(withSrc(src)));
      expect(res.status).toBe(422);
      expect(((await res.json()) as any).errors.map((e: any) => e.code)).toContain('src.host_not_allowed');
    });
  }

  it('refuses file: and javascript: URLs', async () => {
    for (const src of ['file:///etc/passwd', 'javascript:alert(1)']) {
      const res = await post(svc.url, request(withSrc(src)));
      expect(res.status).toBe(422);
    }
  });

  it('refuses relative image paths when samples are not enabled', async () => {
    const strict = await startService(testConfig({ enableSamples: false }));
    try {
      const res = await post(strict.url, request(sample('outer-image-only')));
      expect(res.status).toBe(422);
      expect(((await res.json()) as any).errors.map((e: any) => e.code)).toContain('src.relative');
    } finally {
      await strict.close();
    }
  });

  it('an allowed-host image that redirects elsewhere is refused, and the target is never contacted', async () => {
    let targetHit = false;
    const target = await listen((_q, r) => { targetHit = true; r.end('secret'); });
    const host = await listen((_q, r) => { r.statusCode = 302; r.setHeader('Location', `http://localhost:${new URL(target.url).port}/x.jpg`); r.end(); });
    try {
      const d = sample('outer-image-only');
      const el = d.elements[0]!;
      if (el.type === 'image') el.src = `${host.url}/redirect.jpg`;
      const res = await post(svc.url, request(d));
      expect(res.status).toBe(422);
      expect(((await res.json()) as any).errors.map((e: any) => e.code)).toContain('image.redirect');
      expect(targetHit).toBe(false); // nothing followed the redirect
    } finally {
      await host.close();
      await target.close();
    }
  }, 60_000);
});

describe('image inspection: the server checks the files, not the client\'s description of them', () => {
  const assets = join(ROOT, '../binder-shared/samples/assets');
  const serve = (files: Record<string, string>) =>
    listen((q, r) => {
      const f = files[q.url ?? ''];
      if (!f) { r.statusCode = 404; return r.end(); }
      r.setHeader('Content-Type', f.endsWith('.png') ? 'image/png' : 'image/jpeg');
      r.end(readFileSync(join(assets, f)));
    });

  it('renders a design whose images are absolute URLs on an allowed host (browser is given local files)', async () => {
    const host = await serve({ '/bg.jpg': 'bg-outer.jpg' });
    try {
      const d = sample('outer-image-only');
      const el = d.elements[0]!;
      if (el.type === 'image') el.src = `${host.url}/bg.jpg`;
      const res = await post(svc.url, request(d));
      const body: any = await res.json();
      expect(res.status, JSON.stringify(body)).toBe(200);
      const cmyk = Buffer.from(await (await fetch(body.pdf_cmyk_url)).arrayBuffer());
      expect(verifyPdf(cmyk, 'binder_outer')).toContain('SUMMARY  OK');
    } finally {
      await host.close();
    }
  }, 90_000);

  it('a lying source_px cannot get a 600 px image past the resolution block', async () => {
    const host = await serve({ '/small.jpg': 'lowres.jpg' });
    try {
      const d = sample('outer-image-only'); // claims 8161 x 4205 px (300 dpi)
      const el = d.elements[0]!;
      if (el.type === 'image') el.src = `${host.url}/small.jpg`; // ...but this file is 600 x 310
      expect(validateDesign(d, loadSpec(cfg, 'binder_outer')!).ok).toBe(true); // clean on the claim alone
      const res = await post(svc.url, request(d));
      expect(res.status).toBe(422);
      expect(((await res.json()) as any).errors.map((e: any) => e.code)).toContain('dpi.block');
    } finally {
      await host.close();
    }
  });

  it('refuses something that is not an image, and unsupported image types', async () => {
    const host = await listen((q, r) => { r.setHeader('Content-Type', q.url === '/x.svg' ? 'image/svg+xml' : 'text/html'); r.end(q.url === '/x.svg' ? '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>' : '<html>not an image</html>'); });
    try {
      for (const [path, code] of [['/page.jpg', 'image.invalid'], ['/x.svg', 'image.unsupported_type']] as const) {
        const d = sample('outer-image-only');
        const el = d.elements[0]!;
        if (el.type === 'image') el.src = `${host.url}${path}`;
        const res = await post(svc.url, request(d));
        expect(res.status).toBe(422);
        expect(((await res.json()) as any).errors.map((e: any) => e.code)).toContain(code);
      }
    } finally {
      await host.close();
    }
  });
});

describe('preview (fast RGB PNG proof)', () => {
  it('returns a PNG of the right proportions with real text colours, and needs the secret', async () => {
    const d = sample('outer-arabic-text');
    const t = d.elements[0]!;
    if (t.type === 'text') t.color_cmyk = [100, 0, 0, 0]; // cyan text must look cyan, not the print sentinel
    const res = await fetch(`${svc.url}/preview`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Binder-Secret': SECRET }, body: JSON.stringify(request(d)) });
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toBe('image/png');
    const png = Buffer.from(await res.arrayBuffer());
    expect(png.subarray(1, 4).toString()).toBe('PNG');
    const w = png.readUInt32BE(16);
    const h = png.readUInt32BE(20);
    expect(w).toBeGreaterThan(1500);
    expect(w).toBeLessThan(1700);
    const canvas = loadSpec(cfg, 'binder_outer')!.canvas_with_bleed_mm;
    expect(Math.abs(w / h - canvas.w / canvas.h)).toBeLessThan(0.01);
    // A cyan-ish pixel exists (R low, B high) and no pixel matches the print sentinel green.
    const py = execFileSync('python3', ['-c', `
import sys
from PIL import Image
im = Image.open(sys.argv[1]).convert('RGB'); px = im.load(); w, h = im.size
cyan = sentinel = 0
for y in range(0, h, 2):
    for x in range(0, w, 2):
        r, g, b = px[x, y]
        if r < 60 and g > 150 and b > 200: cyan += 1
        if g == 201 and b == 103: sentinel += 1
print(cyan, sentinel)`, (() => { const f = join(tmpdir(), 'preview-check.png'); writeFileSync(f, png); return f; })()], { encoding: 'utf8' });
    const [cyan, sentinel] = py.trim().split(' ').map(Number);
    expect(cyan).toBeGreaterThan(50);
    expect(sentinel).toBe(0);
    expect((await fetch(`${svc.url}/preview`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).status).toBe(401);
  }, 60_000);

  it('refuses the same bad designs as /render', async () => {
    const res = await fetch(`${svc.url}/preview`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Binder-Secret': SECRET }, body: JSON.stringify(request(sample('outer-lowres'))) });
    expect(res.status).toBe(422);
  });
});

describe('rate limiting per session_token (§5.3)', () => {
  it('429 with Retry-After once the window is used up', async () => {
    const limited = await startService(testConfig({ rateLimitMax: 2 }));
    try {
      const body = request(sample('outer-arabic-text'));
      expect((await post(limited.url, body)).status).toBe(200);
      expect((await post(limited.url, { ...body, design_id: body.design_id + 1 })).status).toBe(200);
      const third = await post(limited.url, { ...body, design_id: body.design_id + 2 });
      expect(third.status).toBe(429);
      expect(Number(third.headers.get('Retry-After'))).toBeGreaterThan(0);
      // A different visitor is not affected.
      expect((await post(limited.url, request(sample('outer-arabic-text')))).status).toBe(200);
    } finally {
      await limited.close();
    }
  }, 90_000);

  it('503 when the queue is full', async () => {
    const tiny = await startService(testConfig({ concurrency: 1, maxQueue: 0 }));
    try {
      const a = post(tiny.url, request(sample('outer-image-only')));
      await new Promise((r) => setTimeout(r, 800));
      const b = await post(tiny.url, request(sample('outer-arabic-text')));
      expect(b.status).toBe(503);
      expect((await a).status).toBe(200);
    } finally {
      await tiny.close();
    }
  }, 90_000);
});

describe('async mode with a signed callback', () => {
  it('202 immediately, then a correctly signed "ready" callback and a job record', async () => {
    const received: Array<{ sig: string; raw: string }> = [];
    const hook = await listen((q, r) => {
      let raw = '';
      q.on('data', (c) => (raw += c));
      q.on('end', () => { received.push({ sig: String(q.headers['x-binder-signature']), raw }); r.end('ok'); });
    });
    try {
      const res = await post(svc.url, request(sample('outer-arabic-text'), { callback_url: `${hook.url}/cb` }));
      const ack: any = await res.json();
      expect(res.status).toBe(202);
      expect(ack.status).toBe('rendering');

      for (let i = 0; i < 100 && received.length === 0; i++) await new Promise((r) => setTimeout(r, 200));
      expect(received).toHaveLength(1);
      const { sig, raw } = received[0]!;
      expect(sig).toBe(`sha256=${createHmac('sha256', SECRET).update(raw).digest('hex')}`);
      const payload = JSON.parse(raw);
      expect(payload).toMatchObject({ job_id: ack.job_id, status: 'ready' });
      expect((await fetch(payload.pdf_cmyk_url)).status).toBe(200);

      const job: any = await (await fetch(`${svc.url}/jobs/${ack.job_id}`, { headers: { 'X-Binder-Secret': SECRET } })).json();
      expect(job.status).toBe('ready');
    } finally {
      await hook.close();
    }
  }, 60_000);

  it('a render that fails delivers a "failed" callback (unreachable image on an allowed host)', async () => {
    const received: string[] = [];
    const hook = await listen((q, r) => { let raw = ''; q.on('data', (c) => (raw += c)); q.on('end', () => { received.push(raw); r.end('ok'); }); });
    const images = await listen((_q, r) => { r.statusCode = 404; r.end('nope'); });
    try {
      const d = sample('outer-image-only');
      const el = d.elements[0]!;
      if (el.type === 'image') el.src = `${images.url}/missing.jpg`;
      const res = await post(svc.url, request(d, { callback_url: `${hook.url}/cb` }));
      expect(res.status).toBe(202);
      for (let i = 0; i < 100 && received.length === 0; i++) await new Promise((r) => setTimeout(r, 200));
      expect(JSON.parse(received[0]!)).toMatchObject({ status: 'failed', error: 'image.unavailable' });
    } finally {
      await hook.close();
      await images.close();
    }
  }, 60_000);

  it('a callback host that is not allow-listed is refused up front', async () => {
    const res = await post(svc.url, request(sample('outer-arabic-text'), { callback_url: 'https://evil.example/hook' }));
    expect(res.status).toBe(400);
  });
});

describe('custom-shape sticker: the cut line follows the artwork', () => {
  it('stamps a traced CutContour path (many segments, not the trim rectangle) on both files', async () => {
    const d = sample('sticker-custom');
    const spec = stickerSpec(d.sticker!);
    const { cmyk, rgb } = await ok('sticker-custom');
    expect(verifyPdf(cmyk, 'sticker', [], spec)).toContain('SUMMARY  OK');
    for (const pdf of [cmyk, rgb]) {
      const raw = Buffer.from(pdf).toString('latin1');
      const stream = raw.slice(raw.indexOf('/CSCutContour CS 1 SCN'));
      const segments = (stream.slice(0, stream.indexOf('\nQ')).match(/ l\n/g) ?? []).length;
      expect(segments).toBeGreaterThan(24);
      // Every point stays inside the trim box (1..61 mm in canvas coordinates -> 2.83..172.9 pt).
      const pts = [...stream.slice(0, stream.indexOf('\nQ')).matchAll(/([\d.]+) ([\d.]+) [ml]\n/g)].map((m) => [Number(m[1]), Number(m[2])]);
      expect(pts.length).toBeGreaterThan(24);
      for (const [x, y] of pts) {
        expect(x).toBeGreaterThanOrEqual(2.8);
        expect(x).toBeLessThanOrEqual(173);
        expect(y).toBeGreaterThanOrEqual(2.8);
        expect(y).toBeLessThanOrEqual(173);
      }
    }
  }, 120_000);
});
