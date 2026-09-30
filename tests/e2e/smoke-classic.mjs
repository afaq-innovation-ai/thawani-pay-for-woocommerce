import { launch, SITE, payOnThawani, shot } from './lib.mjs';
const { browser, page } = await launch();
await page.goto(SITE + '/?add-to-cart=13');
// Requires a page with the [woocommerce_checkout] shortcode set as the checkout page.
await page.goto(SITE + (process.env.CHECKOUT_PATH || '/classic-checkout/'));
await page.waitForSelector('#billing_first_name');
await page.fill('#billing_first_name', 'Aisha'); await page.fill('#billing_last_name', 'Al Said');
await page.fill('#billing_address_1', 'Way 1234, Qurum'); await page.fill('#billing_city', 'Muscat');
await page.fill('#billing_postcode', '112'); await page.fill('#billing_phone', '+968 9988 7766'); await page.fill('#billing_email', 'aisha@example.com');
const st = page.locator('#billing_state'); if (await st.isVisible()) { if (await st.evaluate(e=>e.tagName)==='SELECT') await st.selectOption({index:1}); else await st.fill('Muscat'); }
await page.waitForTimeout(2500);
await page.check('#payment_method_thawani');
await page.waitForTimeout(800);
await page.locator('#payment').scrollIntoViewIfNeeded();
await shot(page, '21-checkout-classic', { locator: page.locator('#payment') });
await page.click('#place_order');
await payOnThawani(page);
await page.waitForURL(u => u.toString().startsWith(SITE), { timeout: 90000 });
console.log('classic back:', page.url());
console.log((await page.locator('main').first().innerText()).includes('Payment ID') ? 'PAID OK' : 'NO PAYMENT DETAILS');
await browser.close();
