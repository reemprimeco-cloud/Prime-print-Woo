/**
 * Render the UV DTF sample to files for a look: PNG and TIFF next to each other.
 *   ICC_PROFILE=… CHROMIUM_PATH=… npx tsx scripts/export-uvdtf.ts /tmp/out
 */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { uvdtfSpec } from '@binder/shared';
import { closeBrowser } from '../src/pipeline/browser.ts';
import { renderTransfer } from '../src/pipeline/uvdtf.ts';
import { sample, startService, testConfig } from '../test/helpers.ts';

const out = process.argv[2] ?? '.';
const cfg = testConfig();
const svc = await startService(cfg);
try {
  const d = sample('uvdtf-text-logo');
  const r = await renderTransfer(cfg, svc.url, uvdtfSpec(d.sticker!), d);
  writeFileSync(join(out, 'uvdtf.png'), r.png);
  writeFileSync(join(out, 'uvdtf.tif'), r.tiff);
  console.log(r.timingsMs, r.png.length, r.tiff.length);
} finally {
  await svc.close();
  await closeBrowser();
}
