import { execFileSync } from 'node:child_process';
import { readFileSync, mkdtempSync, writeFileSync } from 'node:fs';
import { createServer as createHttpServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadConfig, type Config } from '../src/config.ts';
import { createServer } from '../src/server.ts';
import type { DesignJSON } from '@binder/shared';

const here = dirname(fileURLToPath(import.meta.url));
export const ROOT = join(here, '..');

export const SECRET = 'test-secret-0123456789';

export function sample(name: string): DesignJSON {
  return JSON.parse(readFileSync(join(ROOT, '../binder-shared/samples', `${name}.json`), 'utf8'));
}

export function testConfig(over: Partial<Config> = {}): Config {
  const base = loadConfig({ ...process.env, ENABLE_SAMPLES: '1', BINDER_SECRET: SECRET, ALLOWED_IMAGE_HOSTS: '127.0.0.1', ALLOWED_CALLBACK_HOSTS: '127.0.0.1' });
  return { ...base, outputDir: mkdtempSync(join(tmpdir(), 'binder-test-')), rateLimitMax: 1000, ...over };
}

export async function startService(cfg: Config): Promise<{ url: string; close: () => Promise<void> }> {
  const { start } = createServer(cfg);
  const server = await start(0);
  const url = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
  return { url, close: () => new Promise((r) => server.close(() => r())) };
}

export function post(url: string, body: unknown, headers: Record<string, string> = { 'X-Binder-Secret': SECRET }): Promise<Response> {
  return fetch(`${url}/render`, { method: 'POST', headers: { 'Content-Type': 'application/json', ...headers }, body: JSON.stringify(body) });
}

let n = 0;
export const request = (design: DesignJSON, extra: Record<string, unknown> = {}) => ({
  design_id: 100 + n++,
  template: design.template,
  session_token: `test-session-${String(n).padStart(8, '0')}-abcdef`,
  design_json: design,
  ...extra,
});

/** A throwaway HTTP server (image host / callback receiver) on 127.0.0.1. */
export async function listen(handler: Parameters<typeof createHttpServer>[1]): Promise<{ url: string; server: Server; close: () => Promise<void> }> {
  const server = createHttpServer(handler);
  await new Promise<void>((r) => server.listen(0, '127.0.0.1', r));
  return { url: `http://127.0.0.1:${(server.address() as AddressInfo).port}`, server, close: () => new Promise((r) => server.close(() => r())) };
}

/** Run the independent Python verifier (scripts/verify-pdf.py) on bytes; throws with its output on failure. */
export function verifyPdf(bytes: Buffer, template: string, flags: string[] = []): string {
  const dir = mkdtempSync(join(tmpdir(), 'binder-verify-'));
  const file = join(dir, 'x.pdf');
  writeFileSync(file, bytes);
  try {
    return execFileSync('python3', [join(ROOT, 'scripts/verify-pdf.py'), file, template, ...flags], { encoding: 'utf8' });
  } catch (e) {
    throw new Error(`verify-pdf failed:\n${(e as { stdout?: string }).stdout ?? String(e)}`);
  }
}
