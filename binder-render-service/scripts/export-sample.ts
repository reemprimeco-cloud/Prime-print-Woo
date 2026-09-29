/**
 * Step 2 harness: render one sample design to an RGB PDF through the real
 * print route, and write it out for inspection.
 *
 *   ENABLE_SAMPLES=1 npx tsx scripts/export-sample.ts outer-arabic-text
 */
import express from 'express';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import type { AddressInfo } from 'node:net';
import { loadConfig } from '../src/config.ts';
import { createAppRouter } from '../src/app-router.ts';
import { closeBrowser } from '../src/pipeline/browser.ts';
import { renderDesign } from '../src/pipeline/index.ts';
import type { DesignJSON, Spec } from '@binder/shared';
import { loadSpec } from '../src/validate-request.ts';

process.env.ENABLE_SAMPLES = '1';
const cfg = loadConfig();
const name = process.argv[2] ?? 'outer-arabic-text';

const design = JSON.parse(readFileSync(join(cfg.samplesDir, `${name}.json`), 'utf8')) as DesignJSON;
// Binder covers come from their spec files; a sticker's spec is derived from the design itself.
const spec = loadSpec(cfg, design.template, design);
if (!spec) throw new Error(`No spec for ${name}`);

const app = express();
app.use(createAppRouter(cfg));
const server = app.listen(0, '127.0.0.1');
await new Promise((r) => server.once('listening', r));
const base = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;

try {
  const t0 = Date.now();
  const r = await renderDesign(cfg, base, spec, design, name);
  mkdirSync(cfg.outputDir, { recursive: true });
  writeFileSync(join(cfg.outputDir, `${name}.rgb.pdf`), r.rgb);
  writeFileSync(join(cfg.outputDir, `${name}.cmyk.pdf`), r.cmyk);
  console.log(`rendered ${name} in ${Date.now() - t0} ms  ${JSON.stringify(r.timingsMs)}`);
  console.log(`  rgb  ${(r.rgb.length / 1024).toFixed(0)} KB   cmyk ${(r.cmyk.length / 1024).toFixed(0)} KB   -> ${cfg.outputDir}`);
  console.log('  measured text boxes (mm):', JSON.stringify(r.measured));
} finally {
  await closeBrowser();
  server.close();
}
