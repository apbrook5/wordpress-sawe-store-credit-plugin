# Changelog — SAWE Membership Store Credits

All notable changes to this plugin are documented here.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
This project uses [Semantic Versioning](https://semver.org/).

---

## [1.1.5] — 2026-03-20

### Added
- **Search box on Available Store Credits tab** — A text search input now appears above the credit cards on the My Account "Available Store Credits" tab. Typing filters cards in real time by credit name or description (case-insensitive). A "No credits match your search." notice is shown when nothing matches.
- **Search bar on Active Store Credits admin page** — A search input above the credits table on the admin "Active Store Credits" page filters rows in real time by credit name, username, or display name (case-insensitive).

---

## [1.1.4] — 2026-03-17

### Added
- **Per-user uses remaining on coupon display** — When a coupon has a "Usage limit per user" set in WooCommerce, a "Your uses remaining:" line is now shown in the coupon details on the cart/checkout display and the My Account "Available Coupons" tab. The count is calculated from WooCommerce's own `_used_by` record for the current user.

---

## [1.1.3] — 2026-03-17

### Added
- **Remaining uses on coupon display** — When a coupon has a global usage limit set, a "Uses remaining:" line is now shown in the coupon details on the cart/checkout display and the My Account "Available Coupons" tab.

---

## [1.1.2] — 2026-03-16

### Fixed
- **Syntax error in `class-sawe-msc-user-credits.php`** — Missing closing brace on `if ( $has_role )` block introduced in 1.1.1 caused a PHP ParseError on every page load.

---

## [1.1.1] — 2026-03-16

### Fixed
- **Store credit "Applied to this order:" showing pre-coupon amount** — When a WooCommerce coupon was applied before the store credit, the qualifying product total was calculated from `line_subtotal` (pre-coupon price) instead of `line_total` (post-coupon price). The credit was therefore capped against the wrong (higher) amount. Now uses `line_total` so the credit and its displayed amount are both correctly bounded by what the customer actually owes after coupons.
- **`WC_Coupon::COUPON_SUCCESS` undefined constant** — Corrected to `WC_Coupon::WC_COUPON_SUCCESS` in `class-sawe-msc-coupons.php`.
- **WooCommerce native [Remove] coupon link re-applied by auto-apply** — Hooked `woocommerce_removed_coupon` (fires for all coupon removals, including WC's own cart-totals UI) to add the code to `SESSION_COUPON_REMOVED`, preventing `maybe_auto_apply_coupons()` from immediately re-adding it.

- **Store credit balance zeroed when eligible role removed** — Balance is now preserved when a user's qualifying role is removed. The credit is hidden from cart, checkout, and My Account via a role check in `get_active_credits_for_user()`, and is automatically restored when the role is re-added. Previously, `sync_user()` called `remove_credit()` which destructively zeroed the balance.

### Added
- **Active Store Credits admin page** — New "Active Store Credits" submenu under SAWE Coupons and Credits. Displays a table of all awarded user credits with columns for Credit Name, Username (linked to WP profile), Display Name, Current Balance (inline-editable with Save button), Initial Balance, Created date, Last Updated date, Last Renewal date, and Next Renewal date. Includes a Download CSV button that exports the same data as a UTF-8 CSV file.

---

## [1.1.0] — 2026-03

### Added
- **SAWE Coupons system** — Extends WooCommerce's native coupon system with role-based restrictions, auto-apply behaviour, and contextual display. The following features are added to any WC coupon via a new "SAWE Coupon Settings" meta box on the coupon edit screen:
  - **Eligible Member Roles** — Restrict a coupon to specific WordPress roles using the same tag-chip UI as store credits. If no roles are configured, the coupon remains available to all users.
  - **Show in My Account → Available Coupons** — Displays the coupon code and details in a new "Available Coupons" tab on the WooCommerce My Account page, allowing members to discover and copy their exclusive codes.
  - **Show on Cart and Checkout** — Renders an info card for the coupon above the cart totals and checkout form, but only when the coupon applies to items in the current cart and the user has an eligible role.
  - **Auto-apply** — Automatically applies the coupon to the cart when conditions are met. The user may remove or re-apply it with action buttons, mirroring the store credit UX.
- **Admin sidebar renamed** — "SAWE Store Credits" top-level menu is renamed to "SAWE Coupons and Credits".
- **Coupons submenu link** — A new "Coupons" item appears under the SAWE Coupons and Credits menu, linking directly to the WooCommerce coupon list (`edit.php?post_type=shop_coupon`).
- **Available Coupons My Account tab** — A new `/my-account/available-coupons/` endpoint lists all role-eligible, non-expired coupons that have the "Show in My Account" option enabled.
- **New files**: `includes/class-sawe-msc-coupons.php`, `admin/class-sawe-msc-coupon-admin.php`, `public/js/sawe-msc-coupons.js`.

### Changed
- Plugin description updated to reference both store credits and role-based coupons.
- `public/css/sawe-msc-public.css` — Added coupon card and action button styles (green palette to distinguish from credit blue).
- `admin/js/sawe-msc-admin.js` — Added `coupon-roles` entry to the tag-chip list-manager configuration so the roles picker works on coupon edit screens.

---

## [1.0.10] — 2026-03

### Changed
- **Admin edit screen — Credit Name and Member Description** — Replaced the standard WordPress title input and block editor (Gutenberg) with plain text inputs inside the Store Credit Settings meta box. The "Credit Name" text field maps to `post_title` and the "Member Description" textarea maps to `post_content`; the front-end My Account display is unchanged.

---

## [1.0.9] — 2026-03

### Fixed
- **Store credit applying to products in nested sub-categories** — Category matching is now strict. WooCommerce automatically propagates parent category terms onto products (e.g. a product in "Electronics → Laptops" also has "Electronics" stored on it). The category check now strips those propagated ancestor terms before comparing, so only products directly assigned to the configured category qualify.

---

## [1.0.8] — 2026-03

### Fixed
- GitHub Actions workflow now also triggers on the `release: published` event so that creating a release through the GitHub website builds and attaches the plugin zip correctly. Previously only tag pushes from the command line triggered the build.
- Workflow now uploads to an existing release (GitHub website flow) or creates one (tag push flow) without error.

---

## [1.0.7] — 2026-03

### Changed
- Release zip (`sawe-membership-store-credits-{version}.zip`) now contains an unversioned internal folder (`sawe-membership-store-credits/`) so that uploading to WordPress replaces the existing plugin folder instead of creating a new one.

---

## [1.0.6] — 2026-03

### Fixed
- **Store credit not re-applying after cart is cleared** — `SESSION_REMOVED` is now cleared when the cart becomes empty (either by removing the last item or using the "Clear cart" button), so credits auto-apply again when the user next adds qualifying products.
- **Re-apply button not shown after credit removal** — The "Re-apply Store Credit" button now appears immediately after the user removes a credit, regardless of whether a discount amount is currently calculated. Previously it only appeared when `applied_amount > 0`, which was never true right after removal.

---

## [1.0.5] — 2026-03

### Changed
- Reduced the store credit display box size by 25% via `font-size: 0.75em` on `.sawe-msc-credit-box`, proportionally scaling all internal padding, margins, and text.
- Synced `SAWE_MSC_VERSION` constant with the `Version:` plugin header (both now `1.0.5`).

---

## [1.0.3] — 2026-03

### Changed
- Renamed the WordPress admin sidebar menu label and page title from "Store Credits" to "SAWE Store Credits" for clearer branding.

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
