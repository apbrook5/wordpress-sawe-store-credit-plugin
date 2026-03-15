# Changelog — SAWE Membership Store Credits

All notable changes to this plugin are documented here.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
This project uses [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

_Nothing yet._

---

## [1.0.1] — 2026-03

### Fixed
- **Credit applied to non-qualifying products** — `product_qualifies()` now returns
  `false` immediately when a credit definition has no qualifying products AND no
  qualifying categories configured, preventing the credit from applying to every
  product in the store.
- **Variable products never matched categories** — WooCommerce assigns `product_cat`
  terms to the parent variable product, not to individual variations. The category
  check now looks up the parent product ID when a variation ID is passed, so
  variable product categories resolve correctly.
- **Variable products by ID** — The direct product ID check now also tests the
  parent product ID so that admins can list either the parent or a specific
  variation in the qualifying products list and have it work as expected.

---

## [1.0.0] — 2026-03

### Added
- Initial release.
- `sawe_store_credit` custom post type for defining store credits (name, description, amount, renewal date, eligible roles, qualifying products/categories).
- Per-user balance table (`wp_sawe_msc_user_credits`) with `award`, `deduct`, `restore`, and `remove` operations.
- Automatic credit award when a user with a matching role visits the WooCommerce store or logs in.
- Automatic balance zeroing when a user's role no longer qualifies.
- WooCommerce fee-based discount injection (`woocommerce_cart_calculate_fees`, priority 20) — discount applied after all other coupons, capped at qualifying product subtotal.
- Session-based pending-deduction system — DB balance is not committed until order placement.
- "Remove Store Credit" / "Re-apply Store Credit" AJAX buttons on the checkout page.
- Styled credit notice box (light blue background, gold border) above the checkout form.
- Balance restoration on order cancellation and refund.
- WP-Cron daily renewal job (`sawe_msc_daily_renewal`) — resets balances on the configured Month/Day for eligible users.
- WooCommerce My Account "Available Store Credits" tab with full credit details.
- My Account dashboard summary widget.
- Admin menu "Store Credits" with Settings sub-page.
- Tag-chip list managers in the meta box for roles, product categories, and products.
- Custom list table columns: Amount, Renewal Date, Eligible Roles.
- Option to remove database tables on plugin uninstall.
- WooCommerce HPOS (High-Performance Order Storage) compatibility declaration.
- Full developer documentation in `docs/DEVELOPER-GUIDE.md`.
