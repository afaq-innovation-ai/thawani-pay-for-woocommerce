// Screenshots of the saved-cards wallet (My account → Payment methods) and the saved-card checkout.
import { execSync } from 'node:child_process';
import { launch, login, SITE, shot } from './lib.mjs';

const wp = (cmd) => execSync(`docker compose -f ../../dev/docker-compose.yml exec -T cli wp ${cmd}`, { stdio: 'pipe' });

async function wallet(locale, names) {
  const { browser, page } = await launch({ locale });
  await login(page, 'salim', 'salim-demo-2026');
  await page.goto(SITE + '/my-account/payment-methods/');
  await page.waitForSelector('.tp-cc');
  const box = page.locator('.tp-wallet');
  await box.scrollIntoViewIfNeeded();
  await page.evaluate(() => window.scrollBy(0, -120));
  await shot(page, names[0]);
  if (names[1]) {
    await page.locator('[data-tp-rename]').first().click();
    await page.locator('.tp-rename:not([hidden]) input[name="nickname"]').selectText();
    await page.addStyleTag({ content: '.tp-wallet{padding:24px 26px 26px;background:#fff}' });
    await shot(page, names[1], { locator: page.locator('.tp-wallet') });
  }
  await browser.close();
}

await wallet('en-US', ['14-saved-cards', '27-rename-card']);

{
  const { browser, page } = await launch();
  await login(page, 'salim', 'salim-demo-2026');
  await page.goto(`${SITE}/?add-to-cart=17`);
  await page.goto(SITE + '/checkout/');
  await page.waitForSelector('.wc-block-components-checkout-place-order-button');
  await page.waitForSelector('.wc-block-components-order-summary-item');
  await page.waitForTimeout(1500);
  await page.addStyleTag({ content: '.wc-block-components-notices,.wc-block-store-notices{display:none!important}' });
  await page.evaluate(() => { const el = document.querySelector('.wp-block-woocommerce-checkout-payment-block'); window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 260); });
  await shot(page, '15-checkout-saved-card');
  await page.goto(SITE + '/cart/?empty-cart');
  await browser.close();
}

wp('site switch-language ar');
try {
  await wallet('ar', ['28-saved-cards-arabic']);
} finally {
  wp('site switch-language en_US');
}
