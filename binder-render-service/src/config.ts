import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..');

/** Everything the service reads from the environment, with dev-friendly defaults. */
export interface Config {
  port: number;
  /** Shared secret expected in X-Binder-Secret (§5.3). Required outside dev. */
  secret: string;
  /** Built binder-editor (dist/), which contains print.html and the fonts. */
  editorDist: string;
  /** Directory holding binder-*-spec.json (§1). */
  templatesDir: string;
  /** Sample designs, served only when ENABLE_SAMPLES=1. */
  samplesDir: string;
  enableSamples: boolean;
  gsBin: string;
  /** ICC profile used for RGB->CMYK (FOGRA39 / ISO Coated v2 300%). */
  iccProfile: string;
  outputDir: string;
  /** Hosts Chromium may fetch design images from, besides this service itself. */
  allowedImageHosts: string[];
  renderTimeoutMs: number;
  noSandbox: boolean;
  /** A Chromium binary to use instead of the one Playwright downloaded (CHROMIUM_PATH). */
  chromiumPath: string;
  /** Public base URL used in returned file links (defaults to the request's own origin). */
  publicBaseUrl: string;
  /** Simultaneous Chromium renders. Each can use several hundred MB with a 300 dpi image. */
  concurrency: number;
  /** Renders allowed to wait for a free slot before new requests get 503. */
  maxQueue: number;
  /** Renders per session_token per window (§5.3). */
  rateLimitMax: number;
  rateLimitWindowMs: number;
  /** Hosts an async callback may be delivered to. */
  allowedCallbackHosts: string[];
  /** Hours generated files stay on disk. The WP plugin copies them out straight away. */
  retentionHours: number;
  production: boolean;
}

function firstExisting(...paths: string[]): string {
  return paths.find((p) => existsSync(p)) ?? paths[0]!;
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const home = env.HOME ?? '';
  return {
    port: Number(env.PORT ?? 8787),
    secret: env.BINDER_SECRET ?? '',
    editorDist: resolve(env.EDITOR_DIST ?? join(root, '../binder-editor/dist')),
    templatesDir: resolve(env.TEMPLATES_DIR ?? firstExisting(join(root, 'templates'), join(root, '../binder-shared/templates'))),
    samplesDir: resolve(env.SAMPLES_DIR ?? join(root, '../binder-shared/samples')),
    enableSamples: env.ENABLE_SAMPLES === '1',
    gsBin: env.GS_BIN ?? firstExisting(join(home, '.local/prime-tools/gs/bin/gs'), '/usr/bin/gs', '/usr/local/bin/gs'),
    iccProfile: resolve(env.ICC_PROFILE ?? join(root, 'assets/icc/FOGRA39.icc')),
    outputDir: resolve(env.OUTPUT_DIR ?? join(root, 'output')),
    allowedImageHosts: (env.ALLOWED_IMAGE_HOSTS ?? '').split(',').map((h) => h.trim().toLowerCase()).filter(Boolean),
    renderTimeoutMs: Number(env.RENDER_TIMEOUT_MS ?? 120_000),
    noSandbox: env.CHROMIUM_NO_SANDBOX === '1',
    chromiumPath: env.CHROMIUM_PATH ?? '',
    publicBaseUrl: (env.PUBLIC_BASE_URL ?? '').replace(/\/$/, ''),
    concurrency: Math.max(1, Number(env.RENDER_CONCURRENCY ?? 1)),
    maxQueue: Math.max(0, Number(env.RENDER_MAX_QUEUE ?? 20)),
    rateLimitMax: Number(env.RATE_LIMIT_MAX ?? 8),
    rateLimitWindowMs: Number(env.RATE_LIMIT_WINDOW_MS ?? 10 * 60_000),
    allowedCallbackHosts: (env.ALLOWED_CALLBACK_HOSTS ?? '').split(',').map((h) => h.trim().toLowerCase()).filter(Boolean),
    retentionHours: Number(env.RETENTION_HOURS ?? 24),
    production: env.NODE_ENV === 'production',
  };
}
