// Shared helpers for the end-to-end screenshots / smoke tests (run against the dev stack in /dev).
import { chromium } from 'playwright-core';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const SITE = process.env.SITE || 'http://localhost:8090';
export const SHOTS = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../docs/screenshots');

export async function launch(opts = {}) {
  const browser = await chromium.launch({ channel: 'chrome', headless: opts.headed ? false : true });
  const context = await browser.newContext({ viewport: { width: 1360, height: 900 }, deviceScaleFactor: 1.5, locale: opts.locale || 'en-US' });
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  return { browser, context, page };
}

export async function shot(page, name, opts = {}) {
  await page.waitForTimeout(opts.wait ?? 400);
  const file = path.join(SHOTS, name + '.png');
  if (opts.locator) await opts.locator.screenshot({ path: file });
  else await page.screenshot({ path: file, fullPage: !!opts.full, clip: opts.clip });
  console.log('📸', name);
}

export async function login(page, user = 'admin', pass = 'admin') {
  await page.goto(SITE + '/wp-login.php');
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
}

export async function fillBlocksCheckout(page, c = {}) {
  const v = { email: 'salim@example.com', first: 'Salim', last: 'Al Harthi', address: 'Way 3021, Al Khuwair', city: 'Muscat', state: 'Muscat', postcode: '133', phone: '+968 9123 4567', ...c };
  const email = page.locator('#email');
  if (await email.isVisible().catch(() => false) && !(await email.inputValue())) await email.fill(v.email);
  const set = async (sel, val) => { const l = page.locator(sel); if (await l.count() && await l.first().isVisible()) { await l.first().fill(''); await l.first().fill(val); } };
  await set('#shipping-first_name, #billing-first_name', v.first);
  await set('#shipping-last_name, #billing-last_name', v.last);
  await set('#shipping-address_1, #billing-address_1', v.address);
  await set('#shipping-city, #billing-city', v.city);
  await set('#shipping-postcode, #billing-postcode', v.postcode);
  await set('#shipping-phone, #billing-phone', v.phone);
  const state = page.locator('#shipping-state, #billing-state').first();
  if (await state.count() && await state.isVisible()) {
    if ((await state.evaluate(e => e.tagName)) === 'SELECT') await state.selectOption({ index: 1 }).catch(() => {});
    else await state.fill(v.state);
  }
}

export async function payOnThawani(page, card = '4242424242424242', opts = {}) {
  await page.waitForURL(/uatcheckout\.thawani\.om\/pay\//, { timeout: 60000 });
  await page.waitForSelector('#holder');
  if (opts.beforeFill) await opts.beforeFill();
  await page.fill('#holder', 'Salim Al Harthi');
  await page.locator('input[name="cc-number"]').pressSequentially(card, { delay: 20 });
  await page.getByPlaceholder('MM / YY').pressSequentially('1230', { delay: 30 });
  await page.getByPlaceholder('CVV').fill('123');
  const nick = page.locator('#nickname');
  if (await nick.isVisible().catch(() => false)) await nick.fill(opts.nickname || 'My Visa');
  if (opts.afterFill) await opts.afterFill();
  await page.locator('button:visible').filter({ hasText: /^\s*pay\b/i }).first().click();
  if (opts.skipOtp) return;
  await enterOtp(page, opts);
}

export async function enterOtp(page, opts = {}) {
  const otp = page.getByPlaceholder(/OTP/i);
  await otp.waitFor({ timeout: 60000 });
  await otp.fill('1234');
  if (opts.onOtp) await opts.onOtp();
  await page.getByRole('button', { name: /Verify/i }).click();
}
