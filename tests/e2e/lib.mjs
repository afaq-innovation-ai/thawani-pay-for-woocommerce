// Shared helpers for the end-to-end screenshots / smoke tests (run against the dev stack in /dev).
import { chromium } from 'playwright-core';
import path from 'node:path';
import os from 'node:os';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

export const SITE = process.env.SITE || 'http://afai-store.localhost:8090';
export const SHOTS = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../docs/screenshots');

export async function launch(opts = {}) {
  const browser = await chromium.launch({ channel: 'chrome', headless: opts.headed ? false : true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1.5, locale: opts.locale || 'en-US' });
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  return { browser, context, page };
}

/**
 * Screenshot + presentation frame.
 *   frame: 'browser' (default for full-window shots) → macOS-style window with the page URL,
 *          'card'    (default for element shots)     → element on a rounded card,
 *          'none'    → raw image.
 */
export async function shot(page, name, opts = {}) {
  await page.waitForTimeout(opts.wait ?? 400);
  const raw = path.join(os.tmpdir(), `tp-raw-${name}.png`);
  if (opts.locator) await opts.locator.screenshot({ path: raw });
  else await page.screenshot({ path: raw, fullPage: !!opts.full, clip: opts.clip });
  const frame = opts.frame || (opts.locator ? 'card' : 'browser');
  const out = path.join(SHOTS, name + '.png');
  if (frame === 'none') fs.copyFileSync(raw, out);
  else await frameImage(page.context().browser(), raw, out, { mode: frame, url: opts.url || page.url(), dir: opts.dir });
  console.log('📸', name);
}

export async function frameImage(browser, raw, out, { mode = 'browser', url = '', dir = 'ltr' } = {}) {
  const data = fs.readFileSync(raw).toString('base64');
  const pretty = url.replace(/^https?:\/\//, '').replace(/:\d+(?=\/|$)/, '').replace(/[?#].*$/, '').replace(/\/$/, '');
  const ctx = await browser.newContext({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 1 });
  const p = await ctx.newPage();
  const lock = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2.4"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
  const html = `<!doctype html><html><head><style>
    *{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
    .stage{display:inline-block;padding:${mode === 'browser' ? '64px 72px 78px' : '56px'};background:
      radial-gradient(900px 500px at 12% 0%,rgba(140,198,63,.22),transparent 60%),
      radial-gradient(900px 600px at 100% 100%,rgba(29,58,102,.20),transparent 60%),
      linear-gradient(135deg,#f4f7fb 0%,#eef3ea 100%)}
    .win{border-radius:14px;overflow:hidden;background:#fff;box-shadow:0 30px 80px rgba(15,36,70,.22),0 8px 20px rgba(15,36,70,.10),0 0 0 1px rgba(15,36,70,.06)}
    .bar{height:44px;display:flex;align-items:center;gap:8px;padding:0 16px;background:linear-gradient(#f7f8fa,#eef0f3);border-bottom:1px solid #e2e5ea;position:relative}
    .dot{width:12px;height:12px;border-radius:50%}.r{background:#ff5f57}.y{background:#febc2e}.g{background:#28c840}
    .url{position:absolute;left:50%;transform:translateX(-50%);display:flex;align-items:center;gap:7px;min-width:420px;max-width:760px;height:28px;padding:0 14px;border-radius:8px;background:#fff;border:1px solid #e2e5ea;color:#475569;font-size:13px;justify-content:center;white-space:nowrap;overflow:hidden}
    img{display:block;width:${mode === 'browser' ? 1440 : 'auto'}px;${mode === 'card' ? 'max-width:1200px;' : ''}height:auto}
    .card{border-radius:14px;overflow:hidden;box-shadow:0 24px 60px rgba(15,36,70,.18),0 0 0 1px rgba(15,36,70,.06);background:#fff}
  </style></head><body><div class="stage" id="s">${
    mode === 'browser'
      ? `<div class="win"><div class="bar"><span class="dot r"></span><span class="dot y"></span><span class="dot g"></span><div class="url">${lock}<span>${pretty}</span></div></div><img src="data:image/png;base64,${data}"></div>`
      : `<div class="card"><img src="data:image/png;base64,${data}"></div>`
  }</div></body></html>`;
  await p.setContent(html);
  // Show images at their CSS size (raw captures are 1.5x).
  await p.evaluate((max) => { const im = document.querySelector('img'); im.style.width = Math.min(max, im.naturalWidth / 1.5) + 'px'; }, mode === 'browser' ? 1440 : 1200);
  await p.locator('#s').screenshot({ path: out });
  await ctx.close();
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

/** Clone the Thawani box and the (payment-related) order notes side by side for a clean screenshot. */
export async function orderPanels(page) {
  await page.addStyleTag({ content: '#woo-egg-overlay{display:none!important}' }).catch(() => {});
  await page.evaluate(() => {
    document.getElementById('tp-shot')?.remove();
    const wrap = document.createElement('div');
    wrap.id = 'tp-shot';
    const veil = document.createElement('div'); veil.id = 'tp-veil'; veil.style.cssText = 'position:fixed;inset:0;z-index:2147483646;background:#f0f0f1'; document.getElementById('tp-veil')?.remove(); document.body.appendChild(veil);
    wrap.style.cssText = 'position:fixed;left:0;top:0;z-index:2147483647;display:flex;gap:18px;align-items:flex-start;padding:18px;background:#f0f0f1;width:940px';
    const box = document.querySelector('#thawani-pay-order').cloneNode(true);
    const notes = document.querySelector('#woocommerce-order-notes').cloneNode(true);
    notes.querySelector('.add_note')?.remove();
    notes.querySelectorAll('li.note').forEach((li) => { if (/Email .* sent|failed to send/.test(li.innerText)) li.remove(); });
    [box, notes].forEach((el) => { el.querySelectorAll('.handle-actions').forEach((h) => h.remove()); el.classList.remove('closed'); });
    box.style.cssText = 'flex:0 0 340px;margin:0;background:#fff';
    notes.style.cssText = 'flex:1;margin:0;background:#fff';
    wrap.append(box, notes);
    document.body.appendChild(wrap);
    window.scrollTo(0, 0);
  });
  return page.locator('#tp-shot');
}
