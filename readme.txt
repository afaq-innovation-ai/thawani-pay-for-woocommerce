=== Thawani Pay for WooCommerce ===
Contributors: afaqinnovationai
Tags: thawani, oman, payment gateway, subscriptions, omr
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Omani debit and credit cards through Thawani Checkout: hosted payment page, saved cards, refunds, signed webhooks and automatic reconciliation.

== Description ==

Thawani Pay for WooCommerce connects your store to Thawani, one of Oman's leading payment gateways. Customers pay in Omani Rial on Thawani's secure hosted page and return to a confirmed order.

**Features**

* Hosted Thawani checkout — no card data on your server.
* WooCommerce Cart & Checkout blocks and classic shortcode checkout.
* Itemised order summary (products, shipping, fees) on the Thawani page.
* Saved cards wallet: cards shown as bank cards in My account, which customers can rename, set as default or remove.
* Recurring payments with Subscriptions for WooCommerce (WP Swings), with an automatic payment link when a renewal needs the customer's OTP.
* Full and partial refunds from the order screen.
* Signed webhooks (HMAC-SHA256) with replay protection.
* Background reconciliation, so orders are confirmed even if the customer closes the tab.
* Order details box with a one-click "Sync with Thawani".
* Transactions screen that reads checkout sessions from the Thawani API.
* Separate sandbox and live credentials with an in-page connection test.
* Complete Arabic translation and RTL support.
* Compatible with High-Performance Order Storage (HPOS).

Developed by Afaq Innovation and AI. This is an independent integration built on the public Thawani E-Commerce API; Thawani Technologies does not endorse or support it.

== Installation ==

1. Upload the plugin zip under Plugins → Add New → Upload Plugin and activate it.
2. Make sure the store currency is Omani Rial (WooCommerce → Settings → General).
3. Open WooCommerce → Settings → Payments → Thawani Pay.
4. Try it in the sandbox with the built-in test keys, then enter your live keys and webhook secret from the Thawani merchant portal.

== Frequently Asked Questions ==

= Which currencies are supported? =

Omani Rial (OMR). The method is hidden at checkout for other currencies.

= Do I need webhooks? =

They are recommended. Without them, payments are still confirmed when the customer returns and by the background reconciler every 15 minutes.

= Which test card can I use? =

In sandbox mode use 4242 4242 4242 4242 with any future expiry, any CVV and OTP 1234.

= Do subscriptions renew automatically? =

The plugin charges the saved card on every renewal. Thawani currently asks the cardholder for an OTP on these charges, so the customer receives an email with a link to confirm the renewal. If Thawani enables charges without OTP on your account, renewals complete without the customer.

= Where are saved cards stored? =

At Thawani. WooCommerce only keeps the card reference, brand, last four digits and expiry.

== Screenshots ==

1. Block checkout with Thawani Pay.
2. Thawani hosted payment page with the itemised order.
3. Order received page with payment details.
4. Order screen with the Thawani Pay box and a partial refund.
5. Saved cards in My account.
6. Settings with the connection test.
7. Transactions screen.

== Changelog ==

= 1.4.0 =
* Saved cards appear as selectable bank cards on the block and classic checkout.

= 1.3.0 =
* Saved cards wallet: cards shown as bank cards in My account, with rename, default and remove.
* Card names shown on the block and classic checkout.

= 1.2.1 =
* Settings screen: fixed the section menu being shifted left by WooCommerce styles, and the layout now uses the full width of large screens.

= 1.2.0 =
* Redesigned settings screen with a live health panel, section navigation and toggles.
* New transactions dashboard with totals, status filters and search.
* Redesigned order panel with amount, status, card brand and refunds.

= 1.1.0 =
* Subscriptions for WooCommerce (WP Swings) support: saved card at sign-up, renewal charges, payment link when OTP is required.

= 1.0.0 =
* Initial release.
