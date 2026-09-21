// Step 7 end to end: a designed product becomes a real WooCommerce order; the shop gets the CMYK print file, the
// customer gets the RGB proof, the design can't be reused, and the shop notification fires once.
//   prerequisites: dev WordPress on :9400 with WooCommerce (dev/boot.sh) and the render service on :8787
//   run:           node dev/binder-tests/e2e-step7.mjs
import { chromium } from '../../binder-render-service/node_modules/playwright/index.mjs';
import { join, dirname } from 'node:path';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const out = join(root, 'binder-render-service/output/e2e7');
mkdirSync(out, { recursive: true });
const WP = process.env.WP_URL ?? 'http://127.0.0.1:9400';
const asset = (n) => join(root, 'binder-shared/samples/assets', n);

let pass = 0, fail = 0;
const ok = (cond, label, detail = '') => {
  cond ? pass++ : fail++;
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${label}${!cond && detail ? `   [${detail}]` : ''}`);
};

const idOf = async (slug) => (await (await fetch(`${WP}/wp-json/wc/store/v1/products?slug=${slug}`)).json())[0].id;
const NOTEPAD = await idOf('stationery-notepad');
await fetch(`${WP}/?binder_dev_assign=${NOTEPAD}:binder_outer`);

const browser = await chromium.launch();
const errors = [];
const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
const page = await ctx.newPage();
page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));

// ---- 1. the customer designs and adds to cart
await page.goto(`${WP}/product/stationery-notepad/`);
await page.waitForSelector('[data-binder-panel]');
await page.click('[data-binder-open="upload"]');
const f = page.frameLocator('.binder-modal__frame');
await f.locator('input[type=file]').setInputFiles(asset('bg-outer.jpg'));
await f.locator('.binder-save--saved').waitFor({ timeout: 60000 });
await f.locator('button:has-text("Approve design")').click();
await f.locator('.binder-done').waitFor({ timeout: 120000 });
await page.click('.binder-modal__close');
const designId = Number(await page.inputValue('[data-binder-input]'));
const token = await page.inputValue('[data-binder-session]');
await page.click('.single_add_to_cart_button');
await page.waitForLoadState('networkidle');
ok(designId > 0, 'a design is approved and in the cart', String(designId));

// ---- 2. the order is created and paid (dev helper runs the same code path as checkout)
const order = await (await ctx.request.get(`${WP}/?binder_dev_order=1`)).json();
ok(order.order_id > 0, 'an order is created from the cart', JSON.stringify(order));

// ---- 3. the shop notification fired with a working CMYK link
const note = await (await fetch(`${WP}/?binder_dev_notified=1`)).json();
ok(note && note.order === order.order_id, 'the shop notification fired for this order', JSON.stringify(note).slice(0, 200));
ok(note.files.length === 1 && note.files[0].design_id === designId && note.files[0].template === 'binder_outer', 'it lists the outer design');
ok(note.message.includes(`#${order.order_id}`) && note.message.includes(note.files[0].cmyk_url), 'the message names the order and carries the CMYK link', note.message);

const anon = await fetch(note.files[0].cmyk_url);
ok(anon.status === 200 && anon.headers.get('content-type') === 'application/pdf', 'the signed link downloads the CMYK PDF without logging in', String(anon.status));
const bytes = Buffer.from(await anon.arrayBuffer());
ok(bytes.subarray(0, 5).toString() === '%PDF-' && bytes.includes('DeviceCMYK'), 'and it really is the CMYK production file');
ok((await fetch(note.files[0].cmyk_url.replace(/sig=[0-9a-f]{6}/, 'sig=000000'))).status === 404, 'a tampered signature is refused');
ok((await fetch(note.files[0].cmyk_url.replace(/exp=\d+/, 'exp=1000000000'))).status === 404, 'an expired/altered link is refused');
ok((await fetch(`${WP}/?binder_dl=cmyk&id=${designId}&t=${token}`)).status === 404, "the customer's own token cannot fetch the CMYK file");

// ---- 4. the design is now spoken for
const other = await browser.newContext();
await other.request.post(`${WP}/product/stationery-notepad/`, { form: { 'add-to-cart': String(NOTEPAD), quantity: '1', 'binder_design[binder_outer]': String(designId), binder_session: token } });
ok((await (await other.request.get(`${WP}/wp-json/wc/store/v1/cart`)).json()).items_count === 0, 'the same design cannot be ordered a second time');
await other.close();

// ---- 5. the customer sees the item note and the RGB proof, not the CMYK file
await page.goto(order.received);
const text = await page.textContent('body');
ok(new RegExp(`Outer cover design[\\s\\S]*Attached \\(#${designId}\\)`).test(text), 'the confirmation page names the attached design');
const proofHref = await page.$eval('.binder-proofs a', (a) => a.href).catch(() => '');
ok(!!proofHref, 'the confirmation page offers the proof');
const proof = proofHref ? await fetch(proofHref) : { status: 0, headers: new Headers() };
ok(proof.status === 200 && proof.headers.get('content-type') === 'application/pdf', 'the proof downloads', String(proof.status));
ok(!(await page.content()).includes('binder_dl=cmyk'), 'no CMYK link appears on the customer page');
await page.locator('.binder-proofs').screenshot({ path: join(out, 'customer-proofs.png') }).catch(() => {});

// ---- 6. the shop's order screen
const staff = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
await staff.request.post(`${WP}/wp-login.php`, { form: { log: 'admin', pwd: 'password', 'wp-submit': 'Log In', testcookie: '1' }, headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' } });
const sp = await staff.newPage();
sp.on('pageerror', (e) => errors.push(`admin pageerror: ${e.message}`));
await sp.goto(`${WP}/wp-admin/post.php?post=${order.order_id}&action=edit`);
await sp.waitForLoadState('domcontentloaded');
if (!(await sp.locator('#binder-print-files').count())) await sp.goto(`${WP}/wp-admin/admin.php?page=wc-orders&action=edit&id=${order.order_id}`);
ok((await sp.locator('#binder-print-files').count()) === 1, 'the order screen has a Print Files box');
const box = await sp.locator('#binder-print-files').innerText();
ok(/Outer cover/.test(box) && /Ready/.test(box), 'it lists the outer cover as Ready', box.replace(/\s+/g, ' '));
const cmykHref = await sp.$eval('#binder-print-files a.button-primary', (a) => a.href);
const dl = await staff.request.get(cmykHref);
ok(dl.status() === 200 && dl.headers()['content-type'] === 'application/pdf', 'the staff button downloads the CMYK PDF', String(dl.status()));
ok(/Binder print files are ready/.test(await sp.textContent('body')), 'an order note records that the files are ready');
await sp.locator('#binder-print-files').screenshot({ path: join(out, 'print-files-box.png') }).catch(() => {});

// ---- 7. going Processing -> Completed does not notify the shop a second time
const done = await (await fetch(`${WP}/?binder_dev_complete=${order.order_id}`)).json();
ok(done.completed === true && done.notified_again === false, 'completing the order does not send a second notification', JSON.stringify(done));

await browser.close();
ok(errors.length === 0, 'no page errors', errors.slice(0, 3).join(' | '));
console.log(`\nSUMMARY  pass=${pass} fail=${fail}`);
process.exit(fail ? 1 : 0);
