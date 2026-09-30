// Creates realistic demo orders through the real Thawani sandbox (used before taking screenshots).
import { launch, SITE, fillBlocksCheckout, payOnThawani } from './lib.mjs';

const people = [
  { first: 'Khalid', last: 'Al Rashdi', email: 'khalid@example.com', phone: '+968 9234 5678', items: [11, 15], outcome: 'pay' },
  { first: 'Aisha', last: 'Al Said', email: 'aisha@example.com', phone: '+968 9345 6789', items: [13], outcome: 'pay' },
  { first: 'Maryam', last: 'Al Lawati', email: 'maryam@example.com', phone: '+968 9456 7890', items: [17], outcome: 'cancel' },
  { first: 'Fatma', last: 'Al Hinai', email: 'fatma@example.com', phone: '+968 9567 8901', items: [11, 13, 17], outcome: 'pay' },
  { first: 'Ahmed', last: 'Al Busaidi', email: 'ahmed@example.com', phone: '+968 9678 9012', items: [15], outcome: 'abandon' },
];

for (const p of people) {
  const { browser, page } = await launch();
  for (const id of p.items) await page.goto(`${SITE}/?add-to-cart=${id}`);
  await page.goto(SITE + '/checkout/');
  await page.waitForSelector('#email');
  await fillBlocksCheckout(page, { email: p.email, first: p.first, last: p.last, phone: p.phone });
  await page.waitForTimeout(800);
  await page.locator('.wc-block-components-checkout-place-order-button').click();
  await page.waitForURL(/uatcheckout\.thawani\.om\/pay\//, { timeout: 60000 });
  if (p.outcome === 'pay') {
    await payOnThawani(page);
    await page.waitForURL((u) => u.toString().startsWith(SITE), { timeout: 90000 });
  } else if (p.outcome === 'cancel') {
    await page.waitForSelector('#holder');
    await page.getByRole('button', { name: /^Cancel$/ }).click();
    await page.getByRole('button', { name: /I am sure/i }).click();
    await page.waitForURL((u) => u.toString().startsWith(SITE), { timeout: 60000 });
  }
  console.log(`✓ ${p.first} ${p.last}: ${p.outcome}`);
  await browser.close();
}
