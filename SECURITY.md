# Security policy

## Reporting a vulnerability

Please **do not** open a public issue for security problems. Report them privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**. Include:

- a description of the issue and its impact,
- steps to reproduce (a sandbox store is ideal),
- the plugin, WordPress and WooCommerce versions.

We acknowledge reports within three working days and aim to release a fix within 14 days for confirmed issues.

## Scope notes

- Card data is never processed by this plugin; it is entered on Thawani's hosted pages.
- Webhooks are authenticated with HMAC-SHA256 and a timestamp tolerance of 10 minutes.
- Payment state is always re-fetched from the Thawani API before an order is changed.
