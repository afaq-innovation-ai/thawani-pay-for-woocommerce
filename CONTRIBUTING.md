# Contributing

Thank you for helping improve Thawani Pay for WooCommerce.

## Workflow

1. Fork the repository and create a branch from `main`, e.g. `fix/refund-rounding`.
2. Start the development stack in `dev/` (see the README) and reproduce the problem in the Thawani sandbox.
3. Keep changes focused; add or update unit tests in `tests/unit` for pure logic.
4. Run the checks before opening a pull request:

   ```bash
   composer install
   composer test
   composer lint
   ```

5. If you changed user-facing strings, regenerate the translation template and update the Arabic translation:

   ```bash
   docker compose -f dev/docker-compose.yml exec cli wp i18n make-pot \
     wp-content/plugins/thawani-pay-for-woocommerce \
     wp-content/plugins/thawani-pay-for-woocommerce/languages/thawani-pay-for-woocommerce.pot \
     --exclude=vendor,node_modules,tests,dev,docs,bin
   ```

   Then update `languages/thawani-pay-for-woocommerce-ar.po` and compile it with `wp i18n make-mo` and `wp i18n make-json --no-purge`.

## Coding style

- WordPress Coding Standards (enforced by `phpcs.xml.dist`), PHP 7.4 compatible.
- Every amount sent to Thawani is an integer number of **baisa** — always go through `Support\Money`.
- Never change an order's payment status from a redirect or webhook body directly — call `Gateway\PaymentSync`.

## Commit messages

Use the imperative mood and explain *why* in the body when it is not obvious, e.g. `Keep customer-cancelled orders pending`.
