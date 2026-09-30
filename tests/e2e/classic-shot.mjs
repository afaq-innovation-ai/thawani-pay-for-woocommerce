// Classic (shortcode) checkout with saved cards — requires a [woocommerce_checkout] page set as the checkout page.
import { launch, login, SITE, shot } from './lib.mjs';
const { browser, page } = await launch();
await login(page, 'salim', 'salim-demo-2026');
await page.goto(SITE + '/?add-to-cart=13');
await page.goto(SITE + (process.env.CHECKOUT_PATH || '/classic-checkout/'));
await page.waitForSelector('.tp-saved-option');
await page.waitForTimeout(1500);
await shot(page, '21-checkout-classic', { locator: page.locator('#payment') });
await browser.close();
