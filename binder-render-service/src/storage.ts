import { randomBytes } from 'node:crypto';
import { mkdir, readdir, rm, stat, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import type { Config } from './config.ts';
import { hmacHex, safeEqual } from './util.ts';

export type FileKind = 'rgb' | 'cmyk';

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

  async save(designId: number, template: string, pdfs: Record<FileKind, Uint8Array>): Promise<{ token: string }> {
    const token = randomBytes(16).toString('hex');
    const dir = join(this.cfg.outputDir, token);
    await mkdir(dir, { recursive: true });
    await Promise.all(
      (Object.keys(pdfs) as FileKind[]).map((k) => writeFile(join(dir, `${k}.pdf`), pdfs[k])),
    );
    await writeFile(join(dir, 'meta.json'), JSON.stringify({ designId, template, created: Date.now() }));
    return { token };
  }

  signedUrl(base: string, token: string, kind: FileKind, ttlSeconds = 3600): string {
    const exp = Math.floor(Date.now() / 1000) + ttlSeconds;
    const sig = hmacHex(this.key(), `${token}|${kind}|${exp}`);
    return `${base}/files/${token}/${kind}.pdf?exp=${exp}&sig=${sig}`;
  }

  verify(token: string, kind: string, exp: string, sig: string): boolean {
    if (!/^[a-f0-9]{32}$/.test(token) || (kind !== 'rgb' && kind !== 'cmyk')) return false;
    if (!/^\d+$/.test(exp) || Number(exp) < Date.now() / 1000) return false;
    return safeEqual(hmacHex(this.key(), `${token}|${kind}|${exp}`), sig);
  }

  path(token: string, kind: FileKind): string {
    return join(this.cfg.outputDir, token, `${kind}.pdf`);
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
