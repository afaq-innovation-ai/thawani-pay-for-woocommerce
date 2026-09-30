# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.2.0] — 2026-09-30

### Changed
- Redesigned settings screen: branded header, live health panel (API connection, webhooks, store currency, saved cards), sticky section navigation, card sections, toggle switches and a sticky save bar.
- Redesigned transactions screen: totals (collected, paid, awaiting, cancelled), status filters, instant search, customer avatars and relative dates.
- Redesigned order panel: amount and status at a glance, card brand, refunds summary and collapsible technical details.
- The plugin's own admin notices are no longer repeated on its screens (the health panel shows the same information).

### Added
- Last webhook delivery is recorded and shown in the health panel.
- Arabic README (`README.ar.md`), animated demo and framed screenshots.
- `tests/e2e/seed.mjs` to create realistic demo orders through the Thawani sandbox.

## [1.1.0] — 2026-09-30

### Added
- Recurring payments with Subscriptions for WooCommerce (WP Swings): the card is saved at sign-up, every renewal is charged with a Thawani payment intent, and when Thawani needs the customer's OTP (or declines the charge) the subscription is put on hold and the customer is emailed a payment link. Paying it re-activates the subscription.
- The saved card that paid an order is recorded (`_thawani_card_id`); a renewal paid with a different card updates the card used for future renewals.
- New filters: `thawani_pay_force_save_card`, `thawani_pay_show_save_option`, `thawani_pay_checkout_description`, `thawani_pay_order_box_rows`. New action: `thawani_pay_renewal_requires_customer`.
- "Renewal of / Subscription" row in the order screen box.
- End-to-end subscription test (`tests/e2e/subscriptions.mjs`).

## [1.0.0] — 2026-09-30

### Added
- Thawani Checkout gateway with a hosted payment page for WooCommerce block and classic checkout.
- Itemised order summary on the Thawani page (products, shipping, fees) with an automatic single-line fallback.
- Saved cards: Thawani customers, payment methods and payment intents, with OTP / 3-D Secure authorisation.
- Full and partial refunds through the Thawani Refunds API.
- Signed webhook endpoint (`thawani-pay/v1/webhook`) with HMAC-SHA256 verification and replay protection.
- Background reconciler (Action Scheduler) and a live "confirming your payment" panel on the thank-you page.
- Order screen box with payment details and a "Sync with Thawani" action.
- Transactions screen that reads checkout sessions from the API, filterable by store or account.
- Separate sandbox and live keys and webhook secrets, with an in-page connection test.
- Admin notices for currency, missing keys, live mode without HTTPS and sandbox mode.
- Complete Arabic translation, including the checkout blocks.
- HPOS and Cart & Checkout blocks compatibility declarations.
- PHPUnit tests, WordPress Coding Standards, CI and release workflows, Docker development stack.

[1.2.0]: https://github.com/afaq-innovation-ai/thawani-pay-for-woocommerce/releases/tag/v1.2.0
[1.1.0]: https://github.com/afaq-innovation-ai/thawani-pay-for-woocommerce/releases/tag/v1.1.0
[1.0.0]: https://github.com/afaq-innovation-ai/thawani-pay-for-woocommerce/releases/tag/v1.0.0
