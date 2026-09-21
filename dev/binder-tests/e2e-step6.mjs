// Step 6 end to end: product page -> modal editor -> approved design -> gated Add to Cart -> cart line.
//   prerequisites: dev WordPress on :9400 with WooCommerce (dev/boot.sh) and the render service on :8787
//   run:           node dev/binder-tests/e2e-step6.mjs
import { chromium } from '../../binder-render-service/node_modules/playwright/index.mjs';
import { join, dirname } from 'node:path';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const out = join(root, 'binder-render-service/output/e2e6');
mkdirSync(out, { recursive: true });
const WP = process.env.WP_URL ?? 'http://127.0.0.1:9400';
const asset = (n) => join(root, 'binder-shared/samples/assets', n);

let pass = 0, fail = 0;
const ok = (cond, label, detail = '') => {
  cond ? pass++ : fail++;
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${label}${!cond && detail ? `   [${detail}]` : ''}`);
};

// Dev-site products by slug (their ids change whenever the seeder reruns). The assignment hook lives in dev/mu-plugins.
const idOf = async (slug) => (await (await fetch(`${WP}/wp-json/wc/store/v1/products?slug=${slug}`)).json())[0].id;
const NOTEPAD = await idOf('stationery-notepad');   // outer cover only
const SETPRODUCT = await idOf('desk-stationery-set');     // set: outer + inner
const SIGN = await idOf('printed-sign-board-variable'); // ordinary product
await fetch(`${WP}/?binder_dev_assign=${NOTEPAD}:binder_outer`);
await fetch(`${WP}/?binder_dev_assign=${SETPRODUCT}:binder_set`);
await fetch(`${WP}/?binder_dev_assign=${SIGN}:`);

const browser = await chromium.launch();
const errors = [];
const watch = (p) => {
  p.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  p.on('console', (m) => m.type() === 'error' && !/favicon|without API key|Failed to load resource/.test(m.text()) && errors.push(`console: ${m.text()}`));
};
const cartCount = async (ctx) => (await (await ctx.request.get(`${WP}/wp-json/wc/store/v1/cart`)).json()).items_count;

/** Upload the sample artwork in the open modal and approve it. */
async function designInModal(page, file = 'bg-outer.jpg') {
  const f = page.frameLocator('.binder-modal__frame');
  await f.locator('input[type=file]').setInputFiles(asset(file));
  await f.locator('.binder-file').waitFor({ timeout: 60000 });
  await f.locator('.binder-save--saved').waitFor({ timeout: 20000 });
  await f.locator('button:has-text("Approve design")').click();
  await f.locator('.binder-done').waitFor({ timeout: 120000 });
}

// ================================================================ 1. outer-only product, desktop
const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
const page = await ctx.newPage();
watch(page);
await page.goto(`${WP}/product/stationery-notepad/`);
await page.waitForSelector('[data-binder-panel]');

const btn = page.locator('.single_add_to_cart_button');
ok(await btn.isDisabled(), 'Add to Cart starts disabled');
ok((await page.textContent('.binder-row__state')) === 'No design yet', 'row says no design yet');
ok(await page.isVisible('.binder-panel__hint'), 'the hint explains why');
ok((await page.locator('[data-binder-open]').allTextContents()).join('|') === 'Upload your design|Design it now', 'two buttons: Upload your design / Design it now');
ok((await page.inputValue('[data-binder-session]')).length >= 16, 'the session token is in the form');

await page.evaluate(() => document.querySelector('form.cart').requestSubmit());
await page.waitForTimeout(600);
ok(page.url().includes('/product/stationery-notepad') && !page.url().includes('add-to-cart') && (await cartCount(ctx)) === 0, 'submitting the form without a design does nothing');

// The server is the real gate: a hand-made request with no design, and with a made-up one, is refused.
const post = (fields) => ctx.request.post(`${WP}/product/stationery-notepad/`, { form: { 'add-to-cart': String(NOTEPAD), quantity: '1', ...fields } });
await post({});
ok((await cartCount(ctx)) === 0, 'server: a request with no design adds nothing');
const token = await page.inputValue('[data-binder-session]');
await post({ 'binder_design[binder_outer]': '999999', binder_session: token });
ok((await cartCount(ctx)) === 0, 'server: a made-up design id adds nothing');

// ---- open the editor
await page.click('[data-binder-open="upload"]');
await page.waitForSelector('.binder-modal__frame');
const src = await page.$eval('.binder-modal__frame', (f) => f.getAttribute('src'));
ok(/template=binder_outer/.test(src) && /mode=upload/.test(src) && new RegExp(`product=${NOTEPAD}`).test(src), 'the modal opens the outer editor in upload mode', src);
ok(await page.evaluate(() => document.documentElement.classList.contains('binder-modal-open')), 'the page behind is scroll-locked');
await page.frameLocator('.binder-modal__frame').locator('.binder-drop').waitFor({ timeout: 20000 });
ok(true, 'the editor loads inside the modal');

// Closing without approving leaves the gate shut and remembers the draft.
await page.frameLocator('.binder-modal__frame').locator('input[type=file]').setInputFiles(asset('bg-outer.jpg'));
await page.frameLocator('.binder-modal__frame').locator('.binder-save--saved').waitFor({ timeout: 60000 });
await page.keyboard.press('Escape');
await page.waitForSelector('.binder-modal', { state: 'detached' });
ok(await btn.isDisabled(), 'closing the modal without approving keeps Add to Cart disabled');
ok(!(await page.evaluate(() => document.documentElement.classList.contains('binder-modal-open'))), 'scroll lock released');
await page.click('[data-binder-open="upload"]');
await page.waitForSelector('.binder-modal__frame');
ok(/design=\d+/.test(await page.$eval('.binder-modal__frame', (f) => f.getAttribute('src'))), 'reopening continues the saved draft');
await page.frameLocator('.binder-modal__frame').locator('.binder-file').waitFor({ timeout: 30000 });
ok(true, 'the draft is restored in the editor');

// ---- approve
await page.frameLocator('.binder-modal__frame').locator('button:has-text("Approve design")').click();
await page.frameLocator('.binder-modal__frame').locator('.binder-done').waitFor({ timeout: 120000 });
await page.waitForFunction(() => document.querySelector('[data-binder-input]').value !== '', null, { timeout: 5000 });
ok(true, 'approval reaches the product page');
await page.click('.binder-modal__close');
await page.waitForSelector('.binder-modal', { state: 'detached' });
ok((await page.textContent('.binder-row__state')).includes('Design ready'), 'row shows Design ready');
ok(await btn.isEnabled(), 'Add to Cart is enabled');
ok(!(await page.isVisible('.binder-panel__hint')), 'the hint is gone');
ok(await page.isVisible('[data-binder-proof]'), 'the proof link is offered');
ok((await page.textContent('[data-binder-open="upload"]')) === 'Change design', 'the button now reads Change design');
await page.locator('.binder-panel').screenshot({ path: join(out, 'panel-ready.png') });

// ---- add to cart
const designId = Number(await page.inputValue('[data-binder-input]'));
await btn.click();
await page.waitForLoadState('networkidle');
ok((await cartCount(ctx)) === 1, 'the product is in the cart');
await page.goto(`${WP}/cart/`);
const cartText = await page.textContent('body');
ok(/Outer cover design/.test(cartText) && /Attached/.test(cartText), 'the cart line shows the attached outer design', '');
const cart = await (await ctx.request.get(`${WP}/wp-json/wc/store/v1/cart`)).json();
ok(cart.items.length === 1, 'exactly one cart line');

// A design that belongs to somebody else can't be reused.
const other = await browser.newContext();
const otherToken = 'cccccccccccccccccccccccc';
await other.request.post(`${WP}/product/stationery-notepad/`, { form: { 'add-to-cart': String(NOTEPAD), quantity: '1', 'binder_design[binder_outer]': String(designId), binder_session: otherToken } });
ok((await cartCount(other)) === 0, "server: another visitor cannot add this visitor's design");
await other.close();

// ================================================================ 2. shop card sends the customer to the product page
await page.goto(`${WP}/shop/`);
const cardHref = await page.evaluate(() => {
  const a = [...document.querySelectorAll('a.add_to_cart_button, a.button')].find((x) => /stationery/.test(x.href) || new RegExp(`add-to-cart=${NOTEPAD}`).test(x.href));
  return a ? { href: a.href, ajax: a.classList.contains('ajax_add_to_cart') } : null;
});
ok(!cardHref || (!cardHref.ajax && !/add-to-cart=/.test(cardHref.href)), 'a designed product has no one-click add on the shop card', JSON.stringify(cardHref));

// ================================================================ 3. set product (two covers), phone
const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
const p2 = await phone.newPage();
watch(p2);
await p2.goto(`${WP}/product/desk-stationery-set/`);
await p2.waitForSelector('[data-binder-panel]');
ok((await p2.locator('[data-binder-row]').count()) === 2, 'a set shows two rows (outer + inner)');
ok((await p2.textContent('.binder-panel__hint')).includes('both covers'), 'the hint asks for both covers');
const sbtn = p2.locator('.single_add_to_cart_button');

await p2.locator('[data-binder-row="binder_outer"] [data-binder-open="upload"]').click();
await p2.waitForSelector('.binder-modal__frame');
const box = await p2.$eval('.binder-modal', (m) => { const r = m.getBoundingClientRect(); return [r.width, r.height]; });
ok(box[0] === 390 && box[1] === 844, 'on a phone the modal fills the screen', box.join('x'));
await designInModal(p2, 'bg-outer.jpg');
await p2.click('.binder-modal__close');
await p2.waitForSelector('.binder-modal', { state: 'detached' });
ok(await sbtn.isDisabled(), 'with only the outer cover done, Add to Cart stays disabled');
ok((await p2.textContent('[data-binder-row="binder_inner"] .binder-row__state')) === 'No design yet', 'the inner row still waits');

await p2.locator('[data-binder-row="binder_inner"] [data-binder-open="upload"]').click();
await p2.waitForSelector('.binder-modal__frame');
ok(/template=binder_inner/.test(await p2.$eval('.binder-modal__frame', (f) => f.getAttribute('src'))), 'the inner row opens the inner editor');
await designInModal(p2, 'bg-inner.jpg').catch(async () => designInModal(p2, 'bg-outer.jpg'));
await p2.click('.binder-modal__close');
await p2.waitForSelector('.binder-modal', { state: 'detached' });
ok(await sbtn.isEnabled(), 'with both covers done, Add to Cart is enabled');
await p2.screenshot({ path: join(out, 'set-phone-ready.png') });
await sbtn.click();
await p2.waitForLoadState('networkidle');
ok((await cartCount(phone)) === 1, 'the set is in the cart');
await p2.goto(`${WP}/cart/`);
const t2 = await p2.textContent('body');
ok(/Outer cover design/.test(t2) && /Inner cover design/.test(t2), 'the cart line lists both covers');

// ================================================================ 4. an ordinary product is untouched
await p2.goto(`${WP}/product/printed-sign-board-variable/`);
ok((await p2.locator('[data-binder-panel]').count()) === 0, 'an unassigned product shows no design panel');

await browser.close();
ok(errors.length === 0, 'no page errors', errors.slice(0, 3).join(' | '));
console.log(`\nSUMMARY  pass=${pass} fail=${fail}`);
process.exit(fail ? 1 : 0);
