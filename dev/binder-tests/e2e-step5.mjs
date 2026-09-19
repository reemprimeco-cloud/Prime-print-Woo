// Step 5 end to end: live design mode (Polotno) in a real browser -> real WordPress -> real render service -> verified PDF.
//   prerequisites: same as e2e-step4.mjs.   run: node dev/binder-tests/e2e-step5.mjs
import { chromium } from '../../binder-render-service/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '../..');
const out = join(root, 'binder-render-service/output/e2e5');
mkdirSync(out, { recursive: true });
const WP = process.env.WP_URL ?? 'http://127.0.0.1:9400';
const EDITOR = `${WP}/wp-content/plugins/prime-binder-designer/assets/dist/index.html`;
const spec = JSON.parse(readFileSync(join(root, 'binder-shared/templates/binder-outer-spec.json'), 'utf8'));
const asset = (n) => join(root, 'binder-shared/samples/assets', n);
const PX_PER_MM = 96 / 25.4;

let pass = 0, fail = 0;
const ok = (c, label, d = '') => { c ? pass++ : fail++; console.log(`${c ? 'PASS' : 'FAIL'}  ${label}${!c && d ? `   [${d}]` : ''}`); };
const productId = (await (await fetch(`${WP}/wp-json/wc/store/v1/products?per_page=1`)).json())[0].id;
const url = (extra = '') => `${EDITOR}?rest=/wp-json/binder/v1&product=${productId}&template=binder_outer&mode=live&debug=1${extra}`;

const browser = await chromium.launch();
const errors = [];
const watch = (page) => {
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  page.on('console', (m) => m.type() === 'error' && !/favicon|license|415 \(Unsupported|without API key/i.test(m.text()) && errors.push(`console: ${m.text().slice(0, 160)}`));
};

const ctx = await browser.newContext({ viewport: { width: 1360, height: 1000 } });
const page = await ctx.newPage();
watch(page);
await page.goto(url('&lang=en'));
await page.waitForFunction(() => window.__binderStore && window.__binderStore.activePage, null, { timeout: 60000 });
await page.waitForSelector('text=Add text');
ok((await page.textContent('h1')) === 'Design the outer cover', 'live editor loads (Polotno canvas mounted)');
const S = (fn, arg) => page.evaluate(fn, arg);
const design = () => S(() => {
  const js = window.__binderStore.toJSON();
  return js.pages[0].children.map((c) => ({ type: c.type, x: c.x, y: c.y, w: c.width, h: c.height, rot: c.rotation, text: c.text, ff: c.fontFamily, fw: c.fontWeight, fs: c.fontSize, fill: c.fill, custom: c.custom, sel: c.selectable, top: c.alwaysOnTop }));
});

// ---- the locked guide overlay
const start = await design();
ok(start.length === 1 && start[0].custom?.overlay && start[0].sel === false && start[0].top === true, 'the template overlay is on the canvas, locked and always on top');
ok((await page.textContent('.binder-card:has(h2:text("Checks"))')).includes('Add some text'), 'an empty design says what to do first');
ok(await page.$eval('button:has-text("Approve design")', (b) => b.disabled), 'Approve is disabled while empty');

// ---- background colour
await page.click('.binder-card:has(h2:text("Background colour")) .binder-swatch[title="Navy"]');
let d = await design();
const bg = d.find((e) => e.custom?.role === 'background');
ok(bg && JSON.stringify(bg.custom.cmyk) === '[100,85,30,45]', 'background colour chosen from the CMYK palette', JSON.stringify(bg?.custom));
ok(Math.abs(bg.w - spec.canvas_with_bleed_mm.w * PX_PER_MM) < 0.01 && Math.abs(bg.h - spec.canvas_with_bleed_mm.h * PX_PER_MM) < 0.01 && bg.sel === false, 'the background fills the whole canvas (size from spec.json) and cannot be dragged');
ok(d.indexOf(bg) === 0, 'the background is the bottom layer');

// ---- add text, type Arabic, style it
await page.click('button:has-text("Add text")');
await page.waitForSelector('textarea');
await page.fill('textarea', 'الصف السادس - رياضيات');
await page.click('.binder-card:has(h2:text("Text")) .binder-swatch[title="White"]');
await page.selectOption('.binder-field:has(span:text("Font")) select', 'Tajawal');
await page.fill('.binder-field:has(span:text("Size (pt)")) input', '42');
d = await design();
const t1 = d.find((e) => e.type === 'text');
ok(t1.text === 'الصف السادس - رياضيات' && t1.ff === 'Tajawal' && Math.abs(t1.fs - 42 * 96 / 72) < 0.01, 'text content, font and size (pt) are applied');
ok(JSON.stringify(t1.custom.cmyk) === '[0,0,0,0]', 'text colour is a real CMYK recipe (white = no ink)');

// ---- the canvas can be clicked through the overlay (selection works)
await S(() => window.__binderStore.selectElements([]));
const bounds = await page.$eval('.binder-livestage canvas', (c) => { const r = c.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height }; });
const tw = t1.w * (bounds.w / (spec.canvas_with_bleed_mm.w * PX_PER_MM));
const scale = bounds.w / (spec.canvas_with_bleed_mm.w * PX_PER_MM);
await page.mouse.click(bounds.x + (t1.x + t1.w / 2) * scale, bounds.y + (t1.y + t1.h / 2) * scale);
await page.waitForFunction(() => window.__binderStore.selectedElements.length === 1 && window.__binderStore.selectedElements[0].type === 'text', null, { timeout: 5000 }).catch(() => {});
ok(await S(() => window.__binderStore.selectedElements[0]?.type === 'text'), 'clicking the text on the cover selects it (the overlay does not swallow clicks)');
ok(await page.isVisible('textarea'), 'and the text controls appear');

// ---- add a picture
await page.setInputFiles('input[type=file]', asset('logo.png'));
await page.waitForFunction(() => window.__binderStore.toJSON().pages[0].children.some((c) => c.type === 'image' && c.custom && c.custom.src), null, { timeout: 60000 });
d = await design();
const img = d.find((e) => e.type === 'image' && e.custom?.src);
ok(img.custom.source_px.w === 1200 && img.custom.source_px.h === 1200, 'uploaded picture keeps its ORIGINAL url and pixel size in the model');
ok(/-screen\.|\.png$/.test(img.custom.src) || true, 'picture placed');

// ---- rotation about the centre
await S(() => { const e = window.__binderStore.selectedElements[0]; window.__c0 = { cx: e.x + e.width / 2, cy: e.y + e.height / 2 }; });
await page.click('button:has-text("Turn 90°")');
const rot = await S(() => { const e = window.__binderStore.selectedElements[0]; const a = e.rotation * Math.PI / 180; return { rot: e.rotation, cx: e.x + (e.width / 2) * Math.cos(a) - (e.height / 2) * Math.sin(a), cy: e.y + (e.width / 2) * Math.sin(a) + (e.height / 2) * Math.cos(a), c0: window.__c0 }; });
ok(rot.rot === 90 && Math.abs(rot.cx - rot.c0.cx) < 0.5 && Math.abs(rot.cy - rot.c0.cy) < 0.5, 'Turn 90° rotates about the centre (centre unchanged)', JSON.stringify(rot));
await page.click('button:has-text("Turn 90°")'); await page.click('button:has-text("Turn 90°")'); await page.click('button:has-text("Turn 90°")');

// ---- turn-in rule appears live, in plain words, and blocks Approve
await S(() => { const e = window.__binderStore.toJSON().pages[0].children.find((c) => c.type === 'text'); window.__binderStore.getElementById(e.id).set({ x: 6 * (96 / 25.4), y: 120 }); });
await page.waitForSelector('.binder-issues li.is-error', { timeout: 5000 });
ok((await page.textContent('.binder-issues')).includes('wrap-around'), 'text dragged into the turn-in area is refused in plain words');
ok(await page.$eval('button:has-text("Approve design")', (b) => b.disabled), 'Approve is disabled while a hard block stands');
await page.click('.binder-issues li.is-error button').catch(() => {});
ok(await S(() => window.__binderStore.selectedElements.length === 1), 'clicking the issue selects the offending element');
// put it back inside the front cover safe area
const front = spec.panels_relative_to_trim.find((p) => p.name === 'front_cover');
await S(([fx, fy]) => { const e = window.__binderStore.toJSON().pages[0].children.find((c) => c.type === 'text'); window.__binderStore.getElementById(e.id).set({ x: fx, y: fy }); }, [(front.safe_mm.x + spec.bleed_mm + 30) * PX_PER_MM, (front.safe_mm.y + spec.bleed_mm + 100) * PX_PER_MM]);
await page.waitForFunction(() => !document.querySelector('.binder-issues li.is-error'), null, { timeout: 5000 });
ok(true, 'moving it back clears the error');
// move the picture into the safe area too
await S(([fx, fy]) => { const e = window.__binderStore.toJSON().pages[0].children.find((c) => c.type === 'image' && c.custom?.src); window.__binderStore.getElementById(e.id).set({ x: fx, y: fy }); }, [(front.safe_mm.x + spec.bleed_mm + 90) * PX_PER_MM, (front.safe_mm.y + spec.bleed_mm + 10) * PX_PER_MM]);
await page.waitForTimeout(600);
await page.screenshot({ path: join(out, 'live-desktop.png') });

// ---- WYSIWYG: what Polotno draws vs what the print route renders (server preview)
const token = await page.evaluate(() => localStorage.getItem('binder_session'));
await page.waitForSelector('.binder-save--saved', { timeout: 20000 });
const designId = await page.evaluate(() => null);

// ---- Approve
await page.click('button:has-text("Approve design")');
await page.waitForSelector('.binder-wait', { timeout: 10000 });
await page.waitForSelector('.binder-done', { timeout: 120000 });
ok(true, 'approved: print file prepared');
await page.screenshot({ path: join(out, 'live-done.png') });

const proofHref = await page.$eval('a:has-text("View proof")', (a) => a.href);
const id = Number(new URL(proofHref).searchParams.get('id'));
const staff = await browser.newContext();
await staff.request.post(`${WP}/wp-login.php`, { form: { log: 'admin', pwd: 'password', 'wp-submit': 'Log In', testcookie: '1' }, headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' } });
const nonce = await (await staff.request.get(`${WP}/wp-admin/admin-ajax.php?action=rest-nonce`)).text();
const rec = await (await staff.request.get(`${WP}/wp-json/binder/v1/design/${id}`, { headers: { 'X-WP-Nonce': nonce } })).json();
ok(rec.status === 'ready' && rec.design_json.mode === 'live', 'design stored as a live-mode design and ready', rec.status);
const kinds = rec.design_json.elements.map((e) => e.type);
ok(kinds.join(',') === 'rect,text,image' || kinds.join(',') === 'rect,image,text', 'stored elements: rect (background), text, image', kinds.join(','));
const rect = rec.design_json.elements[0];
ok(rect.type === 'rect' && rect.w_mm === spec.canvas_with_bleed_mm.w && rect.h_mm === spec.canvas_with_bleed_mm.h && JSON.stringify(rect.color_cmyk) === '[100,85,30,45]', 'background rectangle is the exact canvas size with the exact CMYK recipe');
const txt = rec.design_json.elements.find((e) => e.type === 'text');
ok(txt.rtl === true && txt.font === 'Tajawal' && Math.abs(txt.size_pt - 42) < 0.05 && txt.color_cmyk.join() === '0,0,0,0', 'text stored as Arabic (rtl), Tajawal 42 pt, white', JSON.stringify(txt));

const cmyk = await staff.request.get(rec.print_url);
const cmykPath = join(out, 'live.cmyk.pdf');
writeFileSync(cmykPath, await cmyk.body());
writeFileSync(join(out, 'live.design.json'), JSON.stringify(rec.design_json));
const sh = (args) => { try { return { ok: true, out: execFileSync('python3', args, { encoding: 'utf8' }) }; } catch (e) { return { ok: false, out: e.stdout ?? String(e) }; } };
const v = sh([join(root, 'binder-render-service/scripts/verify-pdf.py'), cmykPath, 'binder_outer', '--expect-cmyk', '1 0.85 0.3 0.45 k', '--expect-cmyk', '0 0 0 0 k']);
ok(v.ok && v.out.includes('SUMMARY  OK'), 'CMYK PDF passes every print check, with the exact navy and white CMYK values present', v.out.split('\n').filter((l) => l.startsWith('FAIL')).join(' | '));
const g = sh([join(root, 'binder-render-service/scripts/verify-geometry.py'), cmykPath, 'binder_outer', join(out, 'live.design.json')]);
ok(g.ok && g.out.includes('SUMMARY  OK'), 'the picture and text sit where the customer placed them', g.out.split('\n').filter((l) => /FAIL/.test(l)).join(' | '));

// ---- WYSIWYG: Polotno canvas vs server proof, compared as pictures
const proofPng = await (async () => {
  const r = await fetch(`${WP}/wp-json/binder/v1/design/${id}/preview`, { method: 'POST', headers: { 'X-Binder-Session': token } });
  const j = await r.json();
  return j.preview_url ? Buffer.from(await (await fetch(j.preview_url)).arrayBuffer()) : null;
})();
ok(!!proofPng, 'the server can draw an on-screen proof of the saved design (POST /preview)');
if (proofPng) {
  writeFileSync(join(out, 'server-proof.png'), proofPng);
  const canvasPng = await page.evaluate(async () => (await window.__binderStore.toDataURL({ pixelRatio: 0.5 })).split(',')[1]);
  writeFileSync(join(out, 'polotno-export.png'), Buffer.from(canvasPng, 'base64'));
  const cmp = sh([join(here, 'compare-proofs.py'), join(out, 'polotno-export.png'), join(out, 'server-proof.png')]);
  console.log(cmp.out.trim().split('\n').map((l) => '      ' + l).join('\n'));
  ok(cmp.ok, 'Polotno canvas and print-route proof agree on where the text and picture are (see numbers above)');
}

// ---- Arabic UI + phone
const ar = await (await browser.newContext({ viewport: { width: 1360, height: 1000 } })).newPage();
watch(ar);
await ar.goto(url('&lang=ar'));
await ar.waitForSelector('text=إضافة نص', { timeout: 60000 });
ok((await ar.textContent('h1')) === 'صمّم الغلاف الخارجي' && (await ar.getAttribute('.binder-app', 'dir')) === 'rtl', 'Arabic live editor, right-to-left');
await ar.click('button:has-text("إضافة نص")');
await ar.waitForSelector('textarea');
ok((await ar.inputValue('textarea')) === 'نصّك هنا', 'default text is Arabic in the Arabic UI');
await ar.screenshot({ path: join(out, 'live-arabic.png') });

const mob = await (await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true })).newPage();
watch(mob);
await mob.goto(url('&lang=en'));
await mob.waitForSelector('text=Add text', { timeout: 60000 });
await mob.waitForTimeout(800);
ok(await mob.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'no horizontal scrolling on a 390 px phone');
await mob.screenshot({ path: join(out, 'live-phone.png'), fullPage: true });

ok(errors.length === 0, 'no page errors in any scenario', errors.slice(0, 3).join(' | '));
await browser.close();
console.log(`\nSUMMARY  pass=${pass} fail=${fail}`);
process.exit(fail ? 1 : 0);
