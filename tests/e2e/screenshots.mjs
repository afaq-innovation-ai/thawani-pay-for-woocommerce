// Generates every screenshot used in README.md against the local dev stack + Thawani UAT.
// It doubles as an end-to-end smoke test of the whole payment lifecycle.
// Usage: cd tests/e2e && npm install && node screenshots.mjs
import { launch, SITE, shot, login, fillBlocksCheckout, payOnThawani, enterOtp, orderPanels } from './lib.mjs';

const ADMIN = SITE + '/wp-admin';
const step = (t) => console.log('\n▶', t);
const only = process.argv[2] || '';
const run = (name) => !only || only.split(',').includes(name);

async function hideOverlays(page) {
  await page.addStyleTag({ content: '#woo-egg-overlay,.woocommerce-layout__activity-panel-wrapper,.components-modal__screen-overlay{display:none!important}' }).catch(() => {});
  await page.keyboard.press('Escape').catch(() => {});
}

async function paymentBlockIntoView(page) {
  await page.waitForSelector('.wc-block-components-order-summary-item', { timeout: 30000 }).catch(() => {});
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
  await page.addStyleTag({ content: '.wc-block-components-notices, .wc-block-store-notices{display:none!important}' });
  await page.evaluate(() => {
    const el = document.querySelector('.wp-block-woocommerce-checkout-shipping-methods-block, .wp-block-woocommerce-checkout-payment-block');
    window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 40);
  });
}

// ---------------------------------------------------------------- Admin set-up
if (run('admin')) {
  const s = await launch();
  const page = s.page;
  await login(page);

  step('Plugins screen');
  await page.goto(ADMIN + '/plugins.php');
  await shot(page, '01-plugins', { locator: page.locator('tr[data-slug="thawani-pay-for-woocommerce"]') });

  step('WooCommerce payments list');
  await page.goto(ADMIN + '/admin.php?page=wc-settings&tab=checkout');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2500);
  await shot(page, '02-payments-list');

  step('Settings');
  await page.goto(ADMIN + '/admin.php?page=wc-settings&tab=checkout&section=thawani');
  await page.waitForSelector('.tp-status');
  await shot(page, '03-settings-overview');
  const cardTop = (id) => page.evaluate((sel) => { const el = document.querySelector(sel); window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 60); }, id);
  await cardTop('#tp-section_api');
  await page.click('.thawani-pay-test[data-mode="test"]');
  await page.waitForSelector('.thawani-pay-test-result.is-ok', { timeout: 30000 });
  await shot(page, '04-settings-api');
  await cardTop('#tp-section_webhooks');
  await shot(page, '05-settings-webhooks-checkout');
  await s.browser.close();
}

// ---------------------------------------------------------------- Guest checkout + refund
if (run('refund') && process.env.ORDER) {
  const orderId = process.env.ORDER;
  const s = await launch();
  const page = s.page;
  await login(page);
  await page.setViewportSize({ width: 1360, height: 1150 });
  await page.goto(`${ADMIN}/admin.php?page=wc-orders&action=edit&id=${orderId}`);
  await hideOverlays(page);
  await shot(page, '10-admin-order', { locator: await orderPanels(page) });
  await page.goto(`${ADMIN}/admin.php?page=wc-orders&action=edit&id=${orderId}`);
  await hideOverlays(page);
  await page.click('button.refund-items');
  await page.fill('#refund_amount', '4.750');
  await page.fill('#refund_reason', 'Customer returned the beanie');
  page.once('dialog', (d) => d.accept());
  await page.click('button.do-api-refund');
  await page.waitForTimeout(8000);
  await page.goto(`${ADMIN}/admin.php?page=wc-orders&action=edit&id=${orderId}`);
  await page.waitForSelector('.note_content:has-text("Refund ID")', { timeout: 60000 });
  await hideOverlays(page);
  await shot(page, '11-refund', { locator: await orderPanels(page) });
  await s.browser.close();
}

if (run('guest')) {
  step('Guest checkout → Thawani → order received');
  let s = await launch();
  let page = s.page;
  await page.goto(SITE + '/?add-to-cart=11');
  await page.goto(SITE + '/?add-to-cart=13');
  await page.goto(SITE + '/checkout/');
  await page.waitForSelector('#email');
  await fillBlocksCheckout(page);
  await page.waitForTimeout(1200);
  await paymentBlockIntoView(page);
  await shot(page, '06-checkout-blocks');
  await page.locator('.wc-block-components-checkout-place-order-button').click();
  await payOnThawani(page, '4242424242424242', {
    afterFill: () => shot(page, '07-thawani-payment-page'),
    onOtp: () => shot(page, '08-thawani-otp'),
  });
  await page.waitForURL((u) => u.toString().startsWith(SITE), { timeout: 90000 });
  await page.waitForLoadState('networkidle');
  const orderId = page.url().match(/order-received\/(\d+)/)[1];
  await shot(page, '09-order-received', { locator: page.locator('main').first() });
  await s.browser.close();

  step('Admin order screen + partial refund');
  s = await launch();
  page = s.page;
  await login(page);
  await page.setViewportSize({ width: 1360, height: 1150 });
  await page.goto(`${ADMIN}/admin.php?page=wc-orders&action=edit&id=${orderId}`);
  await hideOverlays(page);
  await shot(page, '10-admin-order', { locator: await orderPanels(page) });
  await page.goto(`${ADMIN}/admin.php?page=wc-orders&action=edit&id=${orderId}`);
  await hideOverlays(page);
  await page.click('button.refund-items');
  await page.fill('#refund_amount', '4.750');
  await page.fill('#refund_reason', 'Customer returned the beanie');
  page.once('dialog', (d) => d.accept());
  await page.click('button.do-api-refund');
  await page.waitForTimeout(8000);
  await page.goto(`${ADMIN}/admin.php?page=wc-orders&action=edit&id=${orderId}`);
  await page.waitForSelector('.note_content:has-text("Refund ID")', { timeout: 60000 });
  await hideOverlays(page);
  await shot(page, '11-refund', { locator: await orderPanels(page) });
  await s.browser.close();
}

// ---------------------------------------------------------------- Saved cards
if (run('saved')) {
  step('Customer saves a card');
  const s = await launch();
  const page = s.page;
  await login(page, 'salim', 'salim-demo-2026');
  await page.goto(SITE + '/?add-to-cart=15');
  await page.goto(SITE + '/checkout/');
  await page.waitForSelector('.wc-block-components-checkout-place-order-button');
  await fillBlocksCheckout(page);
  const fresh = page.locator('#radio-control-wc-payment-method-options-thawani');
  if (await fresh.count()) await fresh.check();
  await page.getByLabel(/Save payment information/i).first().check();
  await paymentBlockIntoView(page);
  await shot(page, '12-checkout-save-card');
  await page.locator('.wc-block-components-checkout-place-order-button').click();
  await payOnThawani(page, '4242424242424242', { nickname: 'Salim Visa', afterFill: () => shot(page, '13-thawani-save-card') });
  await page.waitForURL((u) => u.toString().startsWith(SITE), { timeout: 90000 });

  step('My account → payment methods');
  await page.goto(SITE + '/my-account/payment-methods/');
  await shot(page, '14-saved-cards', { locator: page.locator('main').first() });

  step('Pay with saved card');
  await page.goto(SITE + '/?add-to-cart=17');
  await page.goto(SITE + '/checkout/');
  await page.waitForSelector('.wc-block-components-checkout-place-order-button');
  await page.waitForTimeout(1500);
  await paymentBlockIntoView(page);
  await shot(page, '15-checkout-saved-card');
  await page.locator('.wc-block-components-checkout-place-order-button').click();
  await page.waitForURL(/thawani\.om\/authorize|order-received/, { timeout: 90000 });
  if (page.url().includes('thawani.om')) {
    await enterOtp(page, { onOtp: () => shot(page, '16-saved-card-authorize') });
    await page.waitForURL((u) => u.toString().startsWith(SITE), { timeout: 90000 });
  }
  console.log('saved-card order:', page.url());
  await s.browser.close();
}

// ---------------------------------------------------------------- Cancel flow
if (run('cancel')) {
  step('Customer cancels on Thawani');
  const s = await launch();
  const page = s.page;
  await page.goto(SITE + '/?add-to-cart=17');
  await page.goto(SITE + '/checkout/');
  await page.waitForSelector('#email');
  await fillBlocksCheckout(page, { email: 'mariam@example.com', first: 'Mariam', last: 'Al Balushi' });
  await page.locator('.wc-block-components-checkout-place-order-button').click();
  await page.waitForURL(/uatcheckout\.thawani\.om\/pay\//, { timeout: 60000 });
  await page.waitForSelector('#holder');
  await page.getByRole('button', { name: /^Cancel$/ }).click();
  await page.getByRole('button', { name: /I am sure/i }).click();
  await page.waitForURL((u) => u.toString().startsWith(SITE), { timeout: 60000 });
  await page.waitForTimeout(2500);
  await page.evaluate(() => window.scrollTo(0, 0));
  await shot(page, '17-cancelled');
  await s.browser.close();
}

// ---------------------------------------------------------------- Transactions
if (run('transactions')) {
  step('Transactions page');
  const s = await launch();
  const page = s.page;
  await login(page);
  await page.goto(ADMIN + '/admin.php?page=thawani-pay-transactions&mode=test&scope=store');
  await page.waitForSelector('.tp-kpis');
  await shot(page, '18-transactions');
  await s.browser.close();
}

// ---------------------------------------------------------------- Arabic / RTL
if (run('arabic')) {
  step('Arabic checkout + settings (RTL)');
  const { execSync } = await import('node:child_process');
  const wp = (cmd) => execSync(`docker compose -f ../../dev/docker-compose.yml exec -T cli wp ${cmd}`, { stdio: 'pipe' });
  wp('site switch-language ar');
  const s = await launch({ locale: 'ar' });
  const page = s.page;
  await page.goto(SITE + '/?add-to-cart=11');
  await page.goto(SITE + '/checkout/');
  await page.waitForSelector('#email');
  await fillBlocksCheckout(page);
  await paymentBlockIntoView(page);
  await shot(page, '19-arabic-checkout');
  await login(page);
  await page.goto(ADMIN + '/admin.php?page=wc-settings&tab=checkout&section=thawani');
  await hideOverlays(page);
  await page.waitForSelector('.tp-status');
  await shot(page, '20-arabic-settings');
  await s.browser.close();
  wp('site switch-language en_US');
}

console.log('\n✅ done');
