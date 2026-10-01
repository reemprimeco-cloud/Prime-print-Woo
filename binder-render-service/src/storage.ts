import { randomBytes } from 'node:crypto';
import { mkdir, readdir, rm, stat, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import type { Config } from './config.ts';
import { hmacHex, safeEqual } from './util.ts';

/**
 * What a render can produce. The binder covers and cut stickers give two PDFs
 * (the RGB proof and the CMYK print file); a UV DTF transfer gives a PNG and a
 * TIFF with spot channels.
 */
export type FileKind = 'rgb' | 'cmyk' | 'png' | 'tiff';

export const FILE_KINDS: Record<FileKind, { ext: string; mime: string }> = {
  rgb: { ext: 'pdf', mime: 'application/pdf' },
  cmyk: { ext: 'pdf', mime: 'application/pdf' },
  png: { ext: 'png', mime: 'image/png' },
  tiff: { ext: 'tif', mime: 'image/tiff' },
};

export function isFileKind(v: unknown): v is FileKind {
  return typeof v === 'string' && v in FILE_KINDS;
}

/**
 * Generated PDFs live briefly on this service's disk. The WordPress plugin
 * downloads them straight away and keeps the durable copy (private, in the
 * site's own storage), so a redeploy that wipes this disk loses nothing.
 * Links are signed and expire: the files are customer artwork.
 */
export class Storage {
  constructor(private cfg: Config) {}

  private key(): string {
    // Signing key: the shared secret. An unset secret is only tolerated outside production.
    return this.cfg.secret || 'dev-only-signing-key';
  }

  async save(designId: number, template: string, files: Partial<Record<FileKind, Uint8Array>>): Promise<{ token: string }> {
    const token = randomBytes(16).toString('hex');
    const dir = join(this.cfg.outputDir, token);
    await mkdir(dir, { recursive: true });
    await Promise.all(
      (Object.keys(files) as FileKind[]).map((k) => writeFile(this.path(token, k), files[k]!)),
    );
    await writeFile(join(dir, 'meta.json'), JSON.stringify({ designId, template, created: Date.now() }));
    return { token };
  }

  signedUrl(base: string, token: string, kind: FileKind, ttlSeconds = 3600): string {
    const exp = Math.floor(Date.now() / 1000) + ttlSeconds;
    const sig = hmacHex(this.key(), `${token}|${kind}|${exp}`);
    return `${base}/files/${token}/${kind}.${FILE_KINDS[kind].ext}?exp=${exp}&sig=${sig}`;
  }

  verify(token: string, kind: string, exp: string, sig: string): boolean {
    if (!/^[a-f0-9]{32}$/.test(token) || !isFileKind(kind)) return false;
    if (!/^\d+$/.test(exp) || Number(exp) < Date.now() / 1000) return false;
    return safeEqual(hmacHex(this.key(), `${token}|${kind}|${exp}`), sig);
  }

  path(token: string, kind: FileKind): string {
    return join(this.cfg.outputDir, token, `${kind}.${FILE_KINDS[kind].ext}`);
  }

  /** Delete render folders older than the retention window. */
  async sweep(now = Date.now()): Promise<number> {
    let removed = 0;
    let entries: string[] = [];
    try {
      entries = await readdir(this.cfg.outputDir);
    } catch {
      return 0;
    }
    for (const name of entries) {
      if (!/^[a-f0-9]{32}$/.test(name)) continue; // never touch anything this service did not create
      const dir = join(this.cfg.outputDir, name);
      const st = await stat(dir).catch(() => null);
      if (st && now - st.mtimeMs > this.cfg.retentionHours * 3_600_000) {
        await rm(dir, { recursive: true, force: true });
        removed++;
      }
    }
    return removed;
  }
}
