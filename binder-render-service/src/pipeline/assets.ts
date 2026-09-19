import { createWriteStream } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Readable, Transform } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import { imageSizeFromFile } from 'image-size/fromFile';
import { THRESHOLDS, type DesignJSON, type Issue } from '@binder/shared';
import type { Config } from '../config.ts';

const MAX_BYTES = 150 * 1024 * 1024;
const MAX_PIXELS = 150_000_000; // a decoded image costs ~4 bytes per pixel in the browser
const FETCH_TIMEOUT_MS = 60_000;

const MIME: Record<string, string> = { jpg: 'image/jpeg', png: 'image/png', webp: 'image/webp' };

export interface PreparedAssets {
  /** The design with source_px replaced by what the files really are. */
  design: DesignJSON;
  /** Absolute image URL -> local file the browser is given instead of touching the network. */
  files: Map<string, { path: string; mime: string }>;
  /** Corrections made (warnings). */
  issues: Issue[];
  cleanup: () => Promise<void>;
}

export class AssetError extends Error {
  constructor(
    public code: string,
    message: string,
    public issues: Issue[],
  ) {
    super(message);
  }
}

const fail = (code: string, message: string, element: number): AssetError =>
  new AssetError(code, message, [{ code, severity: 'error', message, element }]);

/**
 * Fetch every design image on the server, once, and hand the browser local
 * files (§5.3). This closes three holes at once:
 *
 *  - SSRF: the headless browser never makes an outbound request for artwork,
 *    so there is nothing to redirect or rebind. Redirects are refused here.
 *  - A dishonest source_px: the client tells us how big its image is, and the
 *    DPI hard-block trusts that number. The real dimensions are read from the
 *    file itself and replace the claim.
 *  - Rendering depends on the artwork host being up: a failure is reported
 *    before any browser is launched.
 */
export async function prepareAssets(cfg: Config, design: DesignJSON): Promise<PreparedAssets> {
  const dir = await mkdtemp(join(tmpdir(), 'binder-assets-'));
  const cleanup = () => rm(dir, { recursive: true, force: true });
  const files = new Map<string, { path: string; mime: string }>();
  const issues: Issue[] = [];
  const real = new Map<string, { w: number; h: number }>();

  try {
    let n = 0;
    for (const [i, el] of design.elements.entries()) {
      if (el.type !== 'image' || el.src.startsWith('/')) continue; // relative = sample assets served by this service itself

      const url = new URL(el.src).href;
      if (files.has(url)) continue;

      const host = new URL(url).hostname.toLowerCase();
      if (!cfg.allowedImageHosts.includes(host)) throw fail('src.host_not_allowed', `Image host "${host}" is not allowed.`, i);

      let res: Response;
      try {
        res = await fetch(url, { redirect: 'manual', signal: AbortSignal.timeout(FETCH_TIMEOUT_MS), headers: { Accept: 'image/jpeg,image/png,image/webp' } });
      } catch (e) {
        throw fail('image.unavailable', `Could not fetch the image (${e instanceof Error ? e.message : 'network error'}).`, i);
      }
      if (res.status >= 300 && res.status < 400) throw fail('image.redirect', 'Image URL redirects; redirects are not followed.', i);
      if (!res.ok || !res.body) throw fail('image.unavailable', `Image URL answered ${res.status}.`, i);
      if (Number(res.headers.get('content-length') ?? 0) > MAX_BYTES) throw fail('image.too_large', 'Image file is too large.', i);

      const path = join(dir, `img-${n++}`);
      let bytes = 0;
      const counter = new Transform({
        transform(chunk: Buffer, _enc, cb) {
          bytes += chunk.length;
          bytes > MAX_BYTES ? cb(new Error('too large')) : cb(null, chunk);
        },
      });
      try {
        await pipeline(Readable.fromWeb(res.body as never), counter, createWriteStream(path));
      } catch {
        throw fail('image.too_large', 'Image file is too large or the download was interrupted.', i);
      }

      let size: Awaited<ReturnType<typeof imageSizeFromFile>>;
      try {
        size = await imageSizeFromFile(path);
      } catch {
        throw fail('image.invalid', 'The file is not a readable image.', i);
      }
      const mime = size.type ? MIME[size.type] : undefined;
      if (!mime) throw fail('image.unsupported_type', 'Only JPEG, PNG and WebP images are supported.', i);

      // EXIF orientation 5-8 means the browser shows the image turned 90 degrees.
      const turned = (size.orientation ?? 1) >= 5;
      const w = turned ? size.height : size.width;
      const h = turned ? size.width : size.height;
      if (!w || !h || w * h > MAX_PIXELS || w > THRESHOLDS.maxSourcePx || h > THRESHOLDS.maxSourcePx) {
        throw fail('image.too_large', 'Image dimensions are too large to print safely.', i);
      }

      files.set(url, { path, mime });
      real.set(url, { w, h });
    }

    const corrected: DesignJSON = {
      ...design,
      elements: design.elements.map((el, i) => {
        if (el.type !== 'image' || el.src.startsWith('/')) return el;
        const r = real.get(new URL(el.src).href);
        if (!r || (r.w === el.source_px.w && r.h === el.source_px.h)) return el;
        issues.push({
          code: 'image.source_px_corrected',
          severity: 'warning',
          element: i,
          message: `The image is really ${r.w} x ${r.h} px, not the ${el.source_px.w} x ${el.source_px.h} px claimed; the real size was used.`,
          detail: { claimed_w: el.source_px.w, claimed_h: el.source_px.h, real_w: r.w, real_h: r.h },
        });
        return { ...el, source_px: { w: r.w, h: r.h } };
      }),
    };

    return { design: corrected, files, issues, cleanup };
  } catch (e) {
    await cleanup();
    throw e;
  }
}
