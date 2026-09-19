// Step 4 end to end: real browser -> real editor -> real WordPress REST -> real render service -> real PDF.
//   prerequisites: dev WordPress on :9400 (dev/boot.sh), render service on :8787 (see binder-render-service/README.md)
//   run:           node dev/binder-tests/e2e-step4.mjs
import { chromium } from '../../binder-render-service/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '../..');
const out = join(root, 'binder-render-service/output/e2e');
mkdirSync(out, { recursive: true });

const WP = process.env.WP_URL ?? 'http://127.0.0.1:9400';
const EDITOR = `${WP}/wp-content/plugins/prime-binder-designer/assets/dist/index.html`;
const spec = JSON.parse(readFileSync(join(root, 'binder-shared/templates/binder-outer-spec.json'), 'utf8'));
const asset = (n) => join(root, 'binder-shared/samples/assets', n);

let pass = 0, fail = 0;
const ok = (cond, label, detail = '') => {
  cond ? pass++ : fail++;
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${label}${!cond && detail ? `   [${detail}]` : ''}`);
};

const productId = (await (await fetch(`${WP}/wp-json/wc/store/v1/products?per_page=1`)).json())[0].id;
const url = (extra = '') => `${EDITOR}?rest=/wp-json/binder/v1&product=${productId}&template=binder_outer&mode=upload${extra}`;

const browser = await chromium.launch();
const errors = [];
const watch = (page) => {
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  page.on('console', (m) => m.type() === 'error' && !/favicon|415 \(Unsupported Media Type\)/.test(m.text()) && errors.push(`console: ${m.text()}`));
};

// ================================================================= 1. happy path (desktop, English)
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
watch(page);
const finalizeCalls = [];
page.on('request', (r) => r.url().includes('/finalize') && finalizeCalls.push(r.url()));

await page.goto(url('&lang=en'));
await page.waitForSelector('.binder-drop');
ok((await page.textContent('h1')) === 'Design the outer cover', 'title is shown');
ok((await page.textContent('.binder-bar p')).includes(`${spec.trim_mm.w} × ${spec.trim_mm.h}`), 'subtitle shows the trim size read from spec.json');
await page.waitForFunction(() => { const i = document.querySelector('.binder-overlay'); return i && i.complete && i.naturalWidth > 0; }, null, { timeout: 15000 }).catch(() => {});
ok(await page.$eval('.binder-overlay', (i) => i.complete && i.naturalWidth === 2400), 'the guide overlay PNG loaded (signed URL)');
const legend = await page.textContent('.binder-legend');
ok(legend.includes(`Bleed ${spec.bleed_mm} mm`) && legend.includes(`Safe area ${spec.safe_margin_mm} mm`) && legend.includes(`Wrap-around ${spec.turn_in_mm} mm`), 'legend numbers come from the spec');

await page.setInputFiles('input[type=file]', asset('bg-outer.jpg'));
await page.waitForSelector('.binder-file', { timeout: 60000 });
ok((await page.textContent('.binder-file')).includes('8161 × 4205 px'), 'upload reports the real pixel size');
await page.waitForSelector('.binder-quality');
ok((await page.textContent('.binder-quality')).includes('Sharp'), 'quality: sharp');
ok(/30\d DPI|29\d DPI/.test(await page.textContent('.binder-quality')), 'quality: ~300 DPI', await page.textContent('.binder-quality'));
ok((await page.textContent('.binder-card:has(h2:text("Checks"))')).includes('Everything looks good'), 'no warnings for a full-bleed 300 dpi image');
await page.waitForSelector('.binder-save--saved', { timeout: 20000 });
ok(true, 'draft autosaved');

// The picture is really on the canvas (not just in state).
await page.waitForTimeout(800);
const px = await page.evaluate(() => {
  const c = document.querySelector('canvas.lower-canvas');
  const d = c.getContext('2d').getImageData(Math.floor(c.width / 2), Math.floor(c.height / 2), 1, 1).data;
  return [d[0], d[1], d[2]];
});
ok(!(px[0] === 255 && px[1] === 255 && px[2] === 255), 'the artwork is painted on the canvas', px.join(','));
await page.screenshot({ path: join(out, 'desktop-loaded.png') });

// Direct manipulation: drag the picture, and the checks react.
const box = await page.$eval('canvas.upper-canvas', (c) => { const r = c.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height }; });
await page.mouse.move(box.x + box.w / 2, box.y + box.h / 2);
await page.mouse.down();
await page.mouse.move(box.x + box.w / 2 + 90, box.y + box.h / 2 + 40, { steps: 8 });
await page.mouse.up();
await page.waitForSelector('.binder-issues li.is-warn', { timeout: 5000 });
ok((await page.textContent('.binder-issues')).includes('does not cover the whole sheet'), 'dragging the picture off the edge raises the bleed warning');
await page.click('button:has-text("Fill")');
await page.waitForSelector('.binder-msg--ok', { timeout: 5000 });
ok(true, 'Fill restores full coverage and clears the warning');

await page.click('button:has-text("Rotate right")');
await page.waitForTimeout(300);
ok((await page.textContent('.binder-quality')).length > 0, 'rotate works without errors');
await page.click('button:has-text("Rotate left")');
await page.click('button:has-text("Fill")');
await page.waitForSelector('.binder-save--saved');

// ---- Approve
await page.click('button:has-text("Approve design")');
await page.waitForSelector('.binder-wait', { timeout: 10000 });
ok(true, 'approving shows the waiting state');
await page.waitForSelector('.binder-done', { timeout: 120000 });
ok((await page.textContent('.binder-done h2')) === 'Your design is ready', 'design approved and ready');
await page.screenshot({ path: join(out, 'desktop-done.png') });

const token = await page.evaluate(() => localStorage.getItem('binder_session'));
const rest = (path, init = {}) => fetch(`${WP}/wp-json/binder/v1${path}`, { ...init, headers: { 'X-Binder-Session': token, ...(init.headers ?? {}) } });
const designId = (await (await rest('/design/0'.replace('/0', '/1'))).status) && null;

const proofHref = await page.$eval('a:has-text("View proof")', (a) => a.href);
const proof = await fetch(proofHref);
ok(proof.status === 200 && proof.headers.get('content-type') === 'application/pdf', 'the customer can download the RGB proof PDF', String(proof.status));
const idFromProof = Number(new URL(proofHref).searchParams.get('id'));
writeFileSync(join(out, 'proof.rgb.pdf'), Buffer.from(await proof.arrayBuffer()));
const noToken = await fetch(proofHref.replace(/([?&])t=[^&]+/, '$1t=wrong'));
ok(noToken.status === 404, 'the proof link with the wrong token is a 404');
const cmykGuess = await fetch(`${WP}/?binder_dl=cmyk&id=${idFromProof}&t=${token}`);
ok(cmykGuess.status === 404, 'the CMYK production file is NOT downloadable with the customer token');

// ---- Staff view: CMYK file and geometry
const staff = await browser.newContext();
await staff.request.post(`${WP}/wp-login.php`, { form: { log: 'admin', pwd: 'password', 'wp-submit': 'Log In', testcookie: '1' }, headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' } });
const nonce = await (await staff.request.get(`${WP}/wp-admin/admin-ajax.php?action=rest-nonce`)).text();
const rec = await (await staff.request.get(`${WP}/wp-json/binder/v1/design/${idFromProof}`, { headers: { 'X-WP-Nonce': nonce } })).json();
ok(rec.status === 'ready' && !!rec.print_url, 'staff see the design as ready with a CMYK link', JSON.stringify(rec).slice(0, 200));
const cmyk = await staff.request.get(rec.print_url);
ok(cmyk.status() === 200, 'staff can download the CMYK file', String(cmyk.status()));
const cmykPath = join(out, 'e2e.cmyk.pdf');
writeFileSync(cmykPath, await cmyk.body());
writeFileSync(join(out, 'e2e.design.json'), JSON.stringify(rec.design_json));
const sh = (args) => { try { return { ok: true, out: execFileSync('python3', args, { encoding: 'utf8' }) }; } catch (e) { return { ok: false, out: e.stdout ?? String(e) }; } };
const v = sh([join(root, 'binder-render-service/scripts/verify-pdf.py'), cmykPath, 'binder_outer']);
ok(v.ok && v.out.includes('SUMMARY  OK'), 'the CMYK PDF passes every print check (size, boxes, CMYK images, 300 dpi)', v.out.split('\n').filter((l) => l.startsWith('FAIL')).join(' | '));
const g = sh([join(root, 'binder-render-service/scripts/verify-geometry.py'), cmykPath, 'binder_outer', join(out, 'e2e.design.json')]);
ok(g.ok && g.out.includes('SUMMARY  OK'), 'the artwork sits where the editor put it (structural + trim/fold edges)', g.out.split('\n').filter((l) => l.startsWith('FAIL')).join(' | '));
ok(rec.design_json.elements.length === 1 && rec.design_json.elements[0].type === 'image' && rec.design_json.mode === 'upload', 'stored design is a single-image upload design');
ok(Math.abs(rec.design_json.canvas_mm.w - spec.canvas_with_bleed_mm.w) < 1e-6, 'stored canvas_mm equals the spec canvas');

// ---- Reopen a finished design
const again = await ctx.newPage();
watch(again);
await again.goto(url(`&lang=en&design=${idFromProof}`));
await again.waitForSelector('.binder-done', { timeout: 30000 });
ok(true, 'reopening a finished design shows it as ready');

// ================================================================= 2. low resolution is stopped in the browser
const lowCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const low = await lowCtx.newPage();
watch(low);
const lowFinalize = [];
low.on('request', (r) => r.url().includes('/finalize') && lowFinalize.push(r.url()));
await low.goto(url('&lang=en'));
await low.waitForSelector('.binder-drop');
await low.setInputFiles('input[type=file]', asset('lowres.jpg'));
await low.waitForSelector('.binder-issues li.is-error', { timeout: 60000 });
ok((await low.textContent('.binder-issues')).includes('too low to print'), 'a 600 px image is explained as too low to print');
ok(await low.$eval('button:has-text("Approve design")', (b) => b.disabled), 'Approve is disabled for a blocked design');
ok((await low.textContent('.binder-quality')).includes('Too low'), 'quality meter says too low');
await low.screenshot({ path: join(out, 'desktop-lowres.png') });
ok(lowFinalize.length === 0, 'no finalize request was ever made');

// ---- Bad file types
await low.setInputFiles('input[type=file]', { name: 'evil.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('not an image at all') });
await low.waitForSelector('.binder-msg--error', { timeout: 20000 });
ok(true, 'a fake image is refused by the server with a clear message');

// ================================================================= 3. Arabic, RTL
const ar = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
watch(ar);
await ar.goto(url('&lang=ar'));
await ar.waitForSelector('.binder-drop');
ok((await ar.textContent('h1')) === 'صمّم الغلاف الخارجي', 'Arabic title');
ok((await ar.getAttribute('.binder-app', 'dir')) === 'rtl', 'layout is right-to-left');
ok(await ar.$eval('.binder-frame', (f) => f.getAttribute('dir') === 'ltr'), 'the design canvas itself stays left-to-right (mm coordinates are physical)');
await ar.setInputFiles('input[type=file]', asset('bg-outer.jpg'));
await ar.waitForSelector('.binder-quality', { timeout: 60000 });
await ar.screenshot({ path: join(out, 'desktop-arabic.png') });

// ================================================================= 4. phone
const mob = await (await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true })).newPage();
watch(mob);
await mob.goto(url('&lang=en'));
await mob.waitForSelector('.binder-drop');
await mob.setInputFiles('input[type=file]', asset('bg-outer.jpg'));
await mob.waitForSelector('.binder-quality', { timeout: 60000 });
await mob.waitForTimeout(600);
ok(await mob.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'no horizontal scrolling on a 390 px phone');
await mob.screenshot({ path: join(out, 'phone.png'), fullPage: true });

// ================================================================= console
ok(errors.length === 0, 'no page errors or console errors in any scenario', errors.slice(0, 3).join(' | '));

await browser.close();
console.log(`\nSUMMARY  pass=${pass} fail=${fail}`);
process.exit(fail ? 1 : 0);
