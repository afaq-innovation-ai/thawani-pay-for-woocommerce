// End-to-end: subscribe with Thawani → renewal → customer confirms the renewal → subscription re-activated.
// Requires "Subscriptions for WooCommerce" (WP Swings) active and a subscription product (PRODUCT env, default 1029).
import { execSync } from 'node:child_process';
import { launch, SITE, shot, login, fillBlocksCheckout, payOnThawani, enterOtp } from './lib.mjs';

const PRODUCT = process.env.PRODUCT || '1029';
const wp = (php) => execSync(`docker compose -f ../../dev/docker-compose.yml exec -T cli wp eval '${php.replace(/'/g, "'\\''")}'`, { encoding: 'utf8' }).replace(/sendmail.*\n/g, '').trim();
const step = (t) => console.log('\n▶', t);
const phase = process.argv[2] || 'all';

async function hide(page) {
  await page.addStyleTag({ content: '#woo-egg-overlay,.wc-block-components-notices,.wc-block-store-notices{display:none!important}' }).catch(() => {});
}

async function adminRenewalShot(page) {
  // Keep only the Thawani box and the payment-related notes, side by side.
  await page.addStyleTag({ content: '#woocommerce-order-notes .add_note,.handle-actions{display:none!important}' });
  await page.evaluate(() => document.querySelectorAll('#woocommerce-order-notes li.note').forEach((li) => { if (/Email .* sent/.test(li.innerText)) li.remove(); }));
  await page.evaluate(() => {
    const wrap = document.createElement('div');
    wrap.id = 'thawani-shot';
    wrap.style.cssText = 'position:absolute;left:0;top:0;z-index:99999;display:flex;gap:18px;align-items:flex-start;padding:18px;background:#f0f0f1;width:980px';
    const box = document.querySelector('#thawani-pay-order').cloneNode(true);
    const notes = document.querySelector('#woocommerce-order-notes').cloneNode(true);
    box.style.cssText = 'flex:0 0 360px;margin:0;background:#fff';
    notes.style.cssText = 'flex:1;margin:0;background:#fff';
    wrap.append(box, notes);
    document.body.appendChild(wrap);
    window.scrollTo(0, 0);
  });
  await shot(page, '26-renewal-order-admin', { locator: page.locator('#thawani-shot') });
}

if (phase === 'admin' && process.env.RENEWAL) {
  const a = await launch();
  await login(a.page);
  await a.page.goto(`${SITE}/wp-admin/admin.php?page=wc-orders&action=edit&id=${process.env.RENEWAL}`);
  await hide(a.page);
  await adminRenewalShot(a.page);
  await a.browser.close();
}

if (phase === 'all' || phase === 'subscribe') {
  step('Customer subscribes');
  const { browser, page } = await launch();
  await login(page, 'salim', 'salim-demo-2026');
  await page.goto(SITE + '/cart/?empty-cart=1').catch(() => {});
  await page.goto(`${SITE}/?add-to-cart=${PRODUCT}`);
  await page.goto(SITE + '/checkout/');
  await page.waitForSelector('.wc-block-components-checkout-place-order-button');
  await fillBlocksCheckout(page);
  const fresh = page.locator('#radio-control-wc-payment-method-options-thawani');
  if (await fresh.count()) await fresh.check();
  await page.waitForSelector('.wc-block-components-order-summary-item');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
  await hide(page);
  await page.evaluate(() => { const el = document.querySelector('.wp-block-woocommerce-checkout-payment-block'); window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 260); });
  await shot(page, '22-subscription-checkout');
  await page.locator('.wc-block-components-checkout-place-order-button').click();
  await payOnThawani(page, '4242424242424242', { nickname: 'Salim Visa' });
  await page.waitForURL((u) => u.toString().startsWith(SITE), { timeout: 90000 });
  const orderId = page.url().match(/order-received\/(\d+)/)[1];
  console.log('parent order', orderId);
  await page.goto(SITE + '/my-account/wps_subscriptions/');
  await page.waitForTimeout(1000);
  await shot(page, '23-subscription-active', { locator: page.locator('main').first() });
  await browser.close();
}

if (phase === 'all' || phase === 'renew') {
  step('Renewal becomes due');
  const out = wp(`
    $subs = wc_get_orders(["type"=>"wps_subscriptions","limit"=>1,"orderby"=>"id","order"=>"DESC","return"=>"ids"]);
    $sid = $subs[0];
    echo "subscription=$sid status=", wps_sfw_get_meta_data($sid,"wps_subscription_status",true), " card=", wps_sfw_get_meta_data($sid,"_thawani_card_id",true), "\\n";
    wps_sfw_update_meta_data($sid, "wps_next_payment_date", time() - 60);
    do_action("wps_sfw_create_renewal_order_schedule");
    $rid = wps_sfw_get_meta_data($sid, "wps_wsp_last_renewal_order_id", true);
    $r = wc_get_order($rid);
    echo "renewal=$rid order_status=", $r->get_status(), " sub_status=", wps_sfw_get_meta_data($sid,"wps_subscription_status",true), "\\n";
    foreach (array_reverse(wc_get_order_notes(["order_id"=>$rid])) as $n) echo " - ", wp_strip_all_tags($n->content), "\\n";
    echo "PAYURL=", $r->get_checkout_payment_url(), "\\n";
  `);
  console.log(out);
  const rid = out.match(/renewal=(\d+)/)[1];

  step('Admin view of the renewal order');
  const a = await launch();
  await login(a.page);
  await a.page.setViewportSize({ width: 1360, height: 1100 });
  await a.page.goto(`${SITE}/wp-admin/admin.php?page=wc-orders&action=edit&id=${rid}`);
  await hide(a.page);
  await adminRenewalShot(a.page);
  await a.browser.close();

  step('Renewal email in Mailpit');
  await new Promise((r) => setTimeout(r, 1500));
  const list = await (await fetch('http://localhost:8026/api/v1/messages?limit=20')).json();
  const mail = list.messages.find((m) => m.Subject.includes(`#${rid}`));
  if (mail) {
    const m = await launch();
    await m.page.setViewportSize({ width: 760, height: 1000 });
    await m.page.goto(`http://localhost:8026/view/${mail.ID}.html`);
    await shot(m.page, '24-renewal-email', { full: true });
    await m.browser.close();
  } else {
    console.log('renewal email not found in Mailpit');
  }
}

if (phase === 'all' || phase === 'confirm') {
  step('Customer opens the renewal link and confirms with OTP');
  const rid = wp(`$s=wc_get_orders(["type"=>"wps_subscriptions","limit"=>1,"orderby"=>"id","order"=>"DESC","return"=>"ids"])[0]; echo wps_sfw_get_meta_data($s,"wps_wsp_last_renewal_order_id",true);`);
  const payUrl = wp(`echo wc_get_order(${rid})->get_checkout_payment_url();`);
  const { browser, page } = await launch();
  await login(page, 'salim', 'salim-demo-2026');
  await page.goto(payUrl);
  await page.waitForSelector('#place_order');
  await hide(page);
  await shot(page, '25-renewal-pay-page', { locator: page.locator('main').first() });
  await page.click('#place_order');
  await page.waitForURL(/thawani\.om/, { timeout: 60000 });
  await enterOtp(page);
  await page.waitForURL((u) => u.toString().startsWith(SITE), { timeout: 90000 });
  console.log('back at', page.url());
  console.log(wp(`$s=wc_get_orders(["type"=>"wps_subscriptions","limit"=>1,"orderby"=>"id","order"=>"DESC","return"=>"ids"])[0]; echo "renewal status=", wc_get_order(${rid})->get_status(), " subscription=", wps_sfw_get_meta_data($s,"wps_subscription_status",true);`));
  await browser.close();
}
