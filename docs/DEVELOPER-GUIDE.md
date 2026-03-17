# SAWE Membership Store Credits — Developer Guide

> **Audience:** Developers who need to install, configure, extend, debug, or upgrade this plugin.
> **Plugin version documented:** 1.1.4
> **Last updated:** 2026-03-17

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [File Map](#2-file-map)
3. [Database Schema](#3-database-schema)
4. [Custom Post Type & Meta Reference (Store Credits)](#4-custom-post-type--meta-reference-store-credits)
5. [Coupon Meta Reference](#5-coupon-meta-reference)
6. [Class Reference](#6-class-reference)
7. [Hook & Filter Reference](#7-hook--filter-reference)
8. [WooCommerce Session Keys](#8-woocommerce-session-keys)
9. [Order Meta Keys](#9-order-meta-keys)
10. [WordPress Option Keys](#10-wordpress-option-keys)
11. [CSS Class Reference](#11-css-class-reference)
12. [JavaScript API](#12-javascript-api)
13. [How Credits Flow End-to-End](#13-how-credits-flow-end-to-end)
14. [How Coupons Flow End-to-End](#14-how-coupons-flow-end-to-end)
15. [Common Maintenance Tasks](#15-common-maintenance-tasks)
16. [Extending the Plugin](#16-extending-the-plugin)
17. [Debugging Checklist](#17-debugging-checklist)
18. [Upgrade Notes](#18-upgrade-notes)
19. [Coding Standards](#19-coding-standards)

---

## 1. Architecture Overview

```
WordPress request
       │
       ▼
SAWE_Membership_Store_Credits  (bootstrap, DI, activation hooks)
       │
       ├── SAWE_MSC_DB                   Pure static DB layer — no WP/WC dependencies
       ├── SAWE_MSC_Credit_Post_Type     CPT registration + meta helpers + renewal math
       ├── SAWE_MSC_User_Credits         Award / revoke / renewal logic; product qualification
       ├── SAWE_MSC_Cart                 WC fee injection; session management; AJAX remove/restore
       ├── SAWE_MSC_Checkout             Checkout notice display; order finalisation; restore on cancel
       ├── SAWE_MSC_Account              My Account store-credits tab + dashboard widget
       ├── SAWE_MSC_Coupons              Coupon role restriction, auto-apply, display, available-coupons tab
       ├── SAWE_MSC_Admin                Admin menu, settings page, credit meta boxes, list columns
       └── SAWE_MSC_Coupon_Admin         Admin meta box for shop_coupon (SAWE coupon settings)
```

**Design principles:**

- **Static DB layer** — `SAWE_MSC_DB` is a pure static helper class with no constructor. Any other class can call it without needing an instance.
- **Singleton components** — every other class uses `::instance()` so hooks are never registered twice, even if called from multiple places.
- **Nothing written to DB until checkout** — the store credit discount amount lives exclusively in the WC session (`sawe_msc_applied`) until `woocommerce_checkout_order_created` fires. Abandoned carts never burn a user's balance.
- **CPT as config store** — each store credit definition is a WordPress post (`sawe_store_credit`). This gives admins full draft/publish/trash workflow, revision history, and REST API access for free.
- **WC native coupons extended** — coupons use WooCommerce's own `shop_coupon` post type. SAWE layers extra post meta and behaviour on top without replacing WC's coupon engine.

---

## 2. File Map

```
sawe-membership-store-credits/
│
├── sawe-membership-store-credits.php   ← Plugin header, constants, bootstrap, activation hooks
├── uninstall.php                        ← Runs on plugin deletion; optionally drops DB table
│
├── includes/
│   ├── class-sawe-msc-db.php            ← All SQL: table creation, CRUD helpers
│   ├── class-sawe-msc-credit-post-type.php  ← CPT + meta registration, get_credit_meta(), renewal date math
│   ├── class-sawe-msc-user-credits.php  ← sync_user(), process_renewals(), product_qualifies()
│   ├── class-sawe-msc-cart.php          ← Fee injection, AJAX handlers, session management (credits)
│   ├── class-sawe-msc-checkout.php      ← Checkout display, DB finalisation, cancel restore (credits)
│   ├── class-sawe-msc-account.php       ← My Account store-credits tab/endpoint + dashboard widget
│   └── class-sawe-msc-coupons.php       ← Coupon role restriction, auto-apply, display, available-coupons tab
│
├── admin/
│   ├── class-sawe-msc-admin.php         ← Menus, WP Settings API, credit meta boxes, list columns
│   ├── class-sawe-msc-coupon-admin.php  ← SAWE Coupon Settings meta box on shop_coupon screens
│   ├── css/sawe-msc-admin.css           ← Tag-chip UI styles (shared by credits and coupons)
│   └── js/sawe-msc-admin.js             ← Tag-chip add/remove behaviour (shared by credits and coupons)
│
├── public/
│   ├── css/sawe-msc-public.css          ← Credit + coupon box styles (overridable)
│   ├── js/sawe-msc-cart.js              ← Credit remove/restore AJAX handlers
│   └── js/sawe-msc-coupons.js           ← Coupon apply/remove AJAX handlers
│
└── docs/
    ├── DEVELOPER-GUIDE.md               ← This file
    └── CHANGELOG.md                     ← Version history
```

---

## 3. Database Schema

### Table: `{prefix}sawe_msc_user_credits`

Stores **one row per (credit definition, user) pair**. The balance is decremented on order placement and restored on cancellation/refund. Coupons do not use this table — they rely on WooCommerce's native coupon usage tracking.

| Column           | Type                    | Notes |
|------------------|-------------------------|-------|
| `id`             | BIGINT UNSIGNED PK AI   | Internal row ID |
| `credit_post_id` | BIGINT UNSIGNED NOT NULL | FK → `wp_posts.ID` where `post_type = sawe_store_credit` |
| `user_id`        | BIGINT UNSIGNED NOT NULL | FK → `wp_users.ID` |
| `balance`        | DECIMAL(10,2)            | Current remaining credit |
| `initial_amount` | DECIMAL(10,2)            | Amount at last award/renewal (used to cap restores) |
| `awarded_at`     | DATETIME UTC             | When the row was first inserted |
| `renewed_at`     | DATETIME UTC NULL        | When the balance was last reset by cron renewal |
| `last_updated`   | DATETIME UTC             | Auto-updated on every write |

**Unique index:** `(credit_post_id, user_id)` — enforces one row per pair.

---

## 4. Custom Post Type & Meta Reference (Store Credits)

**Post type slug:** `sawe_store_credit`
**Post title** → shown to members as the credit name (edited via the "Credit Name" text field in the meta box; standard WP title input is hidden).
**Post content** → shown to members as the credit description (edited via the "Member Description" textarea in the meta box).

### Post meta keys

All keys are prefixed `_sawe_msc_`.

| Meta key                      | PHP type  | Stored as      | Description |
|-------------------------------|-----------|----------------|-------------|
| `_sawe_msc_admin_notes`       | `string`  | plain text     | Admin-only notes; never shown to members |
| `_sawe_msc_initial_amount`    | `float`   | numeric string | Dollar value awarded at creation and on each renewal |
| `_sawe_msc_expiry_month`      | `int`     | 1–12           | Month component of annual renewal date |
| `_sawe_msc_expiry_day`        | `int`     | 1–31           | Day component of annual renewal date |
| `_sawe_msc_roles`             | `string`  | JSON array     | WP role slugs eligible for this credit, e.g. `["sawe_member","subscriber"]` |
| `_sawe_msc_product_categories`| `string`  | JSON array     | `product_cat` term IDs whose products qualify |
| `_sawe_msc_products`          | `string`  | JSON array     | Individual product post IDs that qualify |

**Reading meta in code:**
```php
$meta = SAWE_MSC_Credit_Post_Type::get_credit_meta( $post_id );
// Returns:
// [
//   'admin_notes'        => string,
//   'initial_amount'     => float,
//   'expiry_month'       => int,
//   'expiry_day'         => int,
//   'roles'              => string[],
//   'product_categories' => int[],
//   'products'           => int[],
// ]
```

---

## 5. Coupon Meta Reference

SAWE extends WooCommerce's native `shop_coupon` post type with four additional meta keys. All are managed via the "SAWE Coupon Settings" meta box added by `SAWE_MSC_Coupon_Admin`.

| Meta key                            | PHP type | Values        | Description |
|-------------------------------------|----------|---------------|-------------|
| `_sawe_msc_coupon_roles`            | `string` | JSON array    | WP role slugs eligible for this coupon. Empty = available to all users. |
| `_sawe_msc_coupon_display_account`  | `string` | `'yes'`/`'no'` | Show coupon in the "Available Coupons" My Account tab. |
| `_sawe_msc_coupon_display_cart`     | `string` | `'yes'`/`'no'` | Show coupon info card on cart/checkout when applicable. |
| `_sawe_msc_coupon_auto_apply`       | `string` | `'yes'`/`'no'` | Automatically apply the coupon when the user is eligible and cart items qualify. |

**Reading coupon roles in code:**
```php
$coupon_id = 42; // shop_coupon post ID
$roles = SAWE_MSC_Coupons::instance()->get_coupon_roles( $coupon_id );
// Returns string[] of WP role slugs, empty if unrestricted.
```

**Role restriction is enforced at WC validation time** via the `woocommerce_coupon_is_valid` filter, so even manually entered coupon codes are blocked for ineligible users.

---

## 6. Class Reference

### `SAWE_Membership_Store_Credits`
*File:* `sawe-membership-store-credits.php`

Bootstrap singleton. Loads all files, registers activation/deactivation hooks, and instantiates all component singletons after `plugins_loaded`.

| Method | Purpose |
|--------|---------|
| `::instance()` | Returns/creates the singleton |
| `includes()` | Requires all class files (including new coupon classes) |
| `init_hooks()` | Registers `plugins_loaded` callbacks |
| `load_textdomain()` | Loads `.mo` translation files |
| `check_woocommerce()` | Shows admin error if WC is missing |
| `init_components()` | Instantiates all component singletons |

---

### `SAWE_MSC_DB`
*File:* `includes/class-sawe-msc-db.php`
*All methods static — no instance needed.*

| Method | Signature | Purpose |
|--------|-----------|---------|
| `user_credits_table()` | `(): string` | Returns fully-qualified table name |
| `create_tables()` | `(): void` | Runs `dbDelta()` to create/update table |
| `drop_tables()` | `(): void` | Drops table; called from `uninstall.php` |
| `get_user_credit()` | `(int, int): ?object` | Fetches single row or null |
| `get_credits_for_user()` | `(int): object[]` | All rows for a user |
| `award_credit()` | `(int, int, float): bool` | Inserts or resets balance on renewal |
| `deduct_credit()` | `(int, int, float): bool` | Subtracts amount, clamped to 0 |
| `restore_credit()` | `(int, int, float): bool` | Adds amount back, capped at `initial_amount` |
| `remove_credit()` | `(int, int): bool` | Zeros balance (available for admin/programmatic use; no longer called automatically when a role is removed) |
| `get_all_rows_for_credit()` | `(int): object[]` | All user rows for one credit (renewal cron) |

---

### `SAWE_MSC_Credit_Post_Type`
*File:* `includes/class-sawe-msc-credit-post-type.php`

Registers the `sawe_store_credit` CPT and post meta.

| Method | Signature | Purpose |
|--------|-----------|---------|
| `register_post_type()` | `(): void` | Registers CPT (title/editor supports removed — managed via meta box) |
| `register_meta()` | `(): void` | Registers all 7 post meta keys with REST + auth |
| `get_published_credits()` | `(): WP_Post[]` | All `publish` credit posts |
| `get_credit_meta()` | `(int): array` | Returns typed, decoded meta array |
| `get_next_renewal_date()` | `(int): DateTime` | Next renewal DateTime (UTC) |

---

### `SAWE_MSC_User_Credits`
*File:* `includes/class-sawe-msc-user-credits.php`

Bridges CPT config and the DB balance table.

| Method | Signature | Purpose |
|--------|-----------|---------|
| `sync_user()` | `(int): void` | Awards credits to newly eligible users; balance is **preserved** (not zeroed) when a role is removed |
| `process_renewals()` | `(): void` | Cron callback — resets balances on renewal date for users who currently hold a required role |
| `get_active_credits_for_user()` | `(int): array[]` | Returns enriched credit array for display/cart; **filters out credits where the user no longer holds the required role** |
| `product_qualifies()` | `(int, array): bool` | Returns true if product is in qualifying list/category |

---

### `SAWE_MSC_Cart`
*File:* `includes/class-sawe-msc-cart.php*

Injects credit discounts as WC fees and manages remove/restore.

| Constant | Value | Purpose |
|----------|-------|---------|
| `SESSION_APPLIED` | `'sawe_msc_applied'` | `[post_id => amount]` reserved this page load |
| `SESSION_REMOVED` | `'sawe_msc_removed'` | `[post_id, ...]` user removed credits |

---

### `SAWE_MSC_Checkout`
*File:* `includes/class-sawe-msc-checkout.php`

| Method | Purpose |
|--------|---------|
| `display_credit_notice()` | Renders credit boxes above checkout form (priority 5) |
| `finalise_deductions()` | On order create: deducts from DB, stores order meta |
| `restore_on_cancel()` | On cancel/refund: restores using order meta, clears it |

---

### `SAWE_MSC_Account`
*File:* `includes/class-sawe-msc-account.php*

| Constant | Value |
|----------|-------|
| `ENDPOINT` | `'store-credits'` |

Registers the `/my-account/store-credits/` endpoint; adds "Available Store Credits" tab.

---

### `SAWE_MSC_Coupons` *(new in 1.1.0)*
*File:* `includes/class-sawe-msc-coupons.php`

| Constant | Value | Purpose |
|----------|-------|---------|
| `SESSION_COUPON_REMOVED` | `'sawe_msc_coupon_removed'` | Coupon codes user removed this session |
| `ENDPOINT` | `'available-coupons'` | My Account tab slug |

| Method | Purpose |
|--------|---------|
| `check_role_restriction()` | Filter `woocommerce_coupon_is_valid` — blocks ineligible users |
| `suppress_auto_apply_message()` | Filter `woocommerce_coupon_message` — silences auto-apply notices |
| `maybe_auto_apply_coupons()` | Auto-applies eligible coupons on cart/checkout load |
| `on_coupon_removed()` | Hook `woocommerce_removed_coupon` — adds code to `SESSION_COUPON_REMOVED` when WC removes a coupon by any means (including its own [Remove] link) |
| `display_coupon_notices()` | Renders coupon info cards on cart (priority 15) and checkout (priority 6) |
| `add_endpoint()` | Registers `available-coupons` rewrite endpoint |
| `add_menu_item()` | Inserts "Available Coupons" tab in My Account nav |
| `endpoint_content()` | Renders the "Available Coupons" My Account tab |
| `ajax_apply_coupon()` | AJAX `sawe_msc_apply_coupon` — applies coupon + removes from session list |
| `ajax_remove_coupon()` | AJAX `sawe_msc_remove_coupon` — removes coupon + adds to session list |
| `get_coupon_roles()` | Public — returns role slugs for a coupon (used by admin class too) |
| `clear_session()` | Clears `SESSION_COUPON_REMOVED` on logout / thank-you page |
| `enqueue_scripts()` | Enqueues `sawe-msc-public.css` + `sawe-msc-coupons.js` on cart/checkout/account |

---

### `SAWE_MSC_Admin`
*File:* `admin/class-sawe-msc-admin.php`

| Method | Purpose |
|--------|---------|
| `register_menus()` | Adds "SAWE Coupons and Credits" menu + "Settings", "Coupons", "Active Store Credits" sub-pages |
| `register_settings()` | Registers `sawe_msc_remove_tables_on_uninstall` option |
| `render_settings_page()` | Renders the Settings page |
| `add_meta_boxes()` | Adds "Store Credit Settings" meta box to CPT edit screen |
| `render_settings_metabox()` | Renders Credit Name, Member Description, and all meta fields |
| `save_meta_boxes()` | Sanitises + saves all credit meta including post_title/post_content |
| `columns()` | Adds Amount, Renewal Date, Eligible Roles columns to CPT list |
| `column_content()` | Renders the data in each custom column |
| `enqueue_scripts()` | Loads admin CSS + JS on `sawe_store_credit` screens |
| `maybe_download_csv()` | Runs on `admin_init`; streams CSV and exits if download action is requested |
| `render_active_credits_page()` | Renders the Active Store Credits table; handles inline balance save POSTs |
| `get_all_credit_rows()` | Private — SQL join of user credits + WP users; returns all rows across all credit definitions |
| `stream_credits_csv()` | Private — outputs CSV with BOM directly to `php://output` |

---

### `SAWE_MSC_Coupon_Admin` *(new in 1.1.0)*
*File:* `admin/class-sawe-msc-coupon-admin.php`

| Method | Purpose |
|--------|---------|
| `add_meta_boxes()` | Adds "SAWE Coupon Settings" meta box to `shop_coupon` edit screen |
| `render_meta_box()` | Renders roles tag-chip + display/auto-apply checkboxes |
| `save_meta_box()` | Sanitises + saves all 4 SAWE coupon meta keys |
| `enqueue_scripts()` | Loads shared admin CSS + JS on `shop_coupon` screens |

---

## 7. Hook & Filter Reference

### Actions registered by this plugin

| Hook | Class / Method | Priority | Notes |
|------|---------------|----------|-------|
| `before_woocommerce_init` | main file | — | Declares HPOS compatibility |
| `plugins_loaded` | bootstrap | 5 | Instantiates main class |
| `init` | `SAWE_MSC_Credit_Post_Type` | default | Registers CPT + meta |
| `init` | `SAWE_MSC_Account` | default | Registers `store-credits` endpoint |
| `init` | `SAWE_MSC_Coupons` | default | Registers `available-coupons` endpoint |
| `woocommerce_before_cart` | `SAWE_MSC_Coupons` | 1 | Auto-applies coupons |
| `woocommerce_before_checkout_form` | `SAWE_MSC_Coupons` | 1 | Auto-applies coupons |
| `woocommerce_before_cart_totals` | `SAWE_MSC_Cart` | default | Credit remove/restore buttons |
| `woocommerce_before_cart_totals` | `SAWE_MSC_Coupons` | 15 | Coupon info cards + buttons |
| `woocommerce_before_checkout_form` | `SAWE_MSC_Checkout` | 5 | Credit notice boxes |
| `woocommerce_before_checkout_form` | `SAWE_MSC_Coupons` | 6 | Coupon info cards + buttons |
| `woocommerce_cart_calculate_fees` | `SAWE_MSC_Cart` | 20 | Injects credit discounts |
| `wp_ajax_sawe_msc_remove_credit` | `SAWE_MSC_Cart` | default | AJAX: remove credit |
| `wp_ajax_sawe_msc_restore_credit` | `SAWE_MSC_Cart` | default | AJAX: restore credit |
| `wp_ajax_sawe_msc_apply_coupon` | `SAWE_MSC_Coupons` | default | AJAX: apply coupon |
| `wp_ajax_sawe_msc_remove_coupon` | `SAWE_MSC_Coupons` | default | AJAX: remove coupon |
| `woocommerce_checkout_order_created` | `SAWE_MSC_Checkout` | 10 | Finalises DB deductions |
| `woocommerce_order_status_cancelled` | `SAWE_MSC_Checkout` | default | Restores credit balance |
| `woocommerce_order_status_refunded` | `SAWE_MSC_Checkout` | default | Restores credit balance |
| `woocommerce_thankyou` | `SAWE_MSC_Cart` | default | Clears credit session |
| `woocommerce_thankyou` | `SAWE_MSC_Coupons` | default | Clears coupon session |
| `wp_logout` | `SAWE_MSC_Cart` | default | Clears credit session |
| `wp_logout` | `SAWE_MSC_Coupons` | default | Clears coupon session |
| `woocommerce_account_dashboard` | `SAWE_MSC_Account` | default | Dashboard credit summary widget |
| `woocommerce_account_store-credits_endpoint` | `SAWE_MSC_Account` | default | Store credits tab content |
| `woocommerce_account_available-coupons_endpoint` | `SAWE_MSC_Coupons` | default | Available coupons tab content |
| `woocommerce_removed_coupon` | `SAWE_MSC_Coupons` | default | Adds removed coupon code to `SESSION_COUPON_REMOVED` (catches WC native [Remove] link) |
| `add_meta_boxes` | `SAWE_MSC_Admin` | default | Credit meta box |
| `add_meta_boxes` | `SAWE_MSC_Coupon_Admin` | default | Coupon meta box |
| `save_post` | `SAWE_MSC_Admin` | 10 | Saves credit meta |
| `save_post` | `SAWE_MSC_Coupon_Admin` | 10 | Saves coupon meta |
| `admin_init` | `SAWE_MSC_Admin` | default | Streams CSV download before page headers are sent |
| `admin_enqueue_scripts` | `SAWE_MSC_Admin` | default | Admin CSS + JS on credit screens |
| `admin_enqueue_scripts` | `SAWE_MSC_Coupon_Admin` | default | Admin CSS + JS on coupon screens |

### Filters registered by this plugin

| Hook | Class / Method | Notes |
|------|---------------|-------|
| `woocommerce_coupon_is_valid` | `SAWE_MSC_Coupons` | Role restriction; returns false if user lacks required role |
| `woocommerce_coupon_message` | `SAWE_MSC_Coupons` | Suppresses success notice during auto-apply |
| `query_vars` | `SAWE_MSC_Account` | Adds `store-credits` endpoint var |
| `query_vars` | `SAWE_MSC_Coupons` | Adds `available-coupons` endpoint var |
| `woocommerce_account_menu_items` | `SAWE_MSC_Account` | Inserts "Available Store Credits" menu item |
| `woocommerce_account_menu_items` | `SAWE_MSC_Coupons` | Inserts "Available Coupons" menu item |
| `manage_sawe_store_credit_posts_columns` | `SAWE_MSC_Admin` | Adds custom list columns |

---

## 8. WooCommerce Session Keys

| Key | Type | Set by | Read by | Purpose |
|-----|------|--------|---------|---------|
| `sawe_msc_applied` | `array<int,float>` | `SAWE_MSC_Cart::apply_credits_to_cart()` | `SAWE_MSC_Checkout` | Credit post ID → discount reserved this page load |
| `sawe_msc_removed` | `int[]` | `SAWE_MSC_Cart::ajax_remove_credit()` | `SAWE_MSC_Cart::apply_credits_to_cart()` | Credit post IDs user has removed this session |
| `sawe_msc_coupon_removed` | `string[]` | `SAWE_MSC_Coupons::ajax_remove_coupon()` and `on_coupon_removed()` (WC native [Remove] link) | `SAWE_MSC_Coupons::maybe_auto_apply_coupons()` | Coupon codes user has manually removed this session |

All session keys are cleared on logout and after order placement.

---

## 9. Order Meta Keys

| Key | Type | Written by | Read by |
|-----|------|-----------|---------|
| `_sawe_msc_credit_deductions` | `array<int,float>` | `SAWE_MSC_Checkout::finalise_deductions()` | `SAWE_MSC_Checkout::restore_on_cancel()` |

This meta is **deleted** once a restore has been applied to prevent double-restores.

WooCommerce tracks coupon usage itself in `_used_by` on the coupon post — no separate SAWE meta is needed for coupons.

---

## 10. WordPress Option Keys

| Option | Type | Default | Purpose |
|--------|------|---------|---------|
| `sawe_msc_db_version` | `string` | — | Tracks installed DB schema version |
| `sawe_msc_remove_tables_on_uninstall` | `bool` | `false` | If true, `uninstall.php` drops the DB table on plugin deletion |

---

## 11. CSS Class Reference

All classes are prefixed `sawe-msc-` to avoid collisions.

### Public (frontend) — Store Credits

| Class | Element | Description |
|-------|---------|-------------|
| `.sawe-msc-credit-notice-wrap` | `<div>` | Outer wrapper for all credit boxes on a page |
| `.sawe-msc-credit-box` | `<div>` | Individual credit card; light-blue + gold border |
| `.sawe-msc-credit-title` | `<h4>` | Credit name heading |
| `.sawe-msc-credit-desc` | `<p>` | Credit description paragraph |
| `.sawe-msc-credit-details` | `<ul>` | Balance / renewal date list |
| `.sawe-msc-remove-btn` | `<button>` | "Remove Store Credit" button (amber) |
| `.sawe-msc-restore-btn` | `<button>` | "Re-apply Store Credit" button (green) |

### Public (frontend) — Coupons *(new in 1.1.0)*

| Class | Element | Description |
|-------|---------|-------------|
| `.sawe-msc-coupon-notice-wrap` | `<div>` | Outer wrapper for all coupon boxes on a page |
| `.sawe-msc-coupon-section-title` | `<h4>` | "Available Coupons" heading above the boxes |
| `.sawe-msc-coupon-box` | `<div>` | Individual coupon card; pale green + green border |
| `.sawe-msc-coupon-title` | `<h4>` | Coupon name heading |
| `.sawe-msc-coupon-desc` | `<p>` | Coupon description paragraph |
| `.sawe-msc-coupon-details` | `<ul>` | Discount / code / expiry / uses-remaining list |
| `.sawe-msc-coupon-code` | `<code>` | Inline chip showing the copyable coupon code |
| `.sawe-msc-coupon-apply-btn` | `<button>` | "Apply Coupon" / "Re-apply Coupon" button (green) |
| `.sawe-msc-coupon-remove-btn` | `<button>` | "Remove Coupon" button (amber) |

### Admin

| Class | Element | Description |
|-------|---------|-------------|
| `.sawe-msc-metabox` | `<div>` | Meta box wrapper (credits + coupons) |
| `.sawe-msc-field` | `<div>` | Individual field row |
| `.sawe-msc-list-manager` | `<div>` | Roles / categories / products list area |
| `.sawe-msc-selected-list` | `<div>` | Tag-chip container |
| `.sawe-msc-tag` | `<span>` | Individual chip |
| `.sawe-msc-remove-tag` | `<button>` | × button inside a chip |
| `.sawe-msc-add-dropdown` | `<select>` | Dropdown to pick a new item |
| `.sawe-msc-add-tag-btn` | `<button>` | "Add" button |

---

## 12. JavaScript API

### `public/js/sawe-msc-cart.js`

Depends on: `jQuery`, `wc-cart`.
Localised object: `saweMscData`

```js
saweMscData = {
  ajaxUrl: string,
  nonce:   string,
  i18n: {
    removeLabel:  string,  // "Remove store credit"
    restoreLabel: string,  // "Re-apply store credit"
  }
}
```

AJAX actions: `sawe_msc_remove_credit`, `sawe_msc_restore_credit`.

### `public/js/sawe-msc-coupons.js` *(new in 1.1.0)*

Depends on: `jQuery`, `wc-cart`.
Localised object: `saweMscCouponData`

```js
saweMscCouponData = {
  ajaxUrl: string,
  nonce:   string,
  i18n: {
    applyLabel:    string,  // "Apply Coupon"
    reapplyLabel:  string,  // "Re-apply Coupon"
    removeLabel:   string,  // "Remove Coupon"
    applyingLabel: string,  // "Applying…"
    removingLabel: string,  // "Removing…"
  }
}
```

AJAX actions: `sawe_msc_apply_coupon`, `sawe_msc_remove_coupon`.

**Events triggered after successful AJAX (both files):**
- `update_checkout` on `document.body`
- `wc_update_cart` on `document.body`

### `admin/js/sawe-msc-admin.js`

Manages tag-chip list managers. Reads `data-target` from `.sawe-msc-add-tag-btn`:

| `data-target` | Dropdown ID | List ID | Input name | Screen |
|---------------|-------------|---------|------------|--------|
| `roles` | `roles-dropdown` | `roles-list` | `sawe_msc_roles[]` | `sawe_store_credit` |
| `cats` | `cats-dropdown` | `cats-list` | `sawe_msc_product_categories[]` | `sawe_store_credit` |
| `products` | `products-dropdown` | `products-list` | `sawe_msc_products[]` | `sawe_store_credit` |
| `coupon-roles` | `coupon-roles-dropdown` | `coupon-roles-list` | `sawe_msc_coupon_roles[]` | `shop_coupon` |

---

## 13. How Credits Flow End-to-End

```
User visits store page / logs in
         │
         ▼
SAWE_MSC_User_Credits::sync_user()
  • For each published sawe_store_credit:
      – If user has matching role AND no DB row → award_credit()
      – If user has no matching role → balance preserved in DB; credit
        hidden at display time via get_active_credits_for_user() role check
         │
         ▼
get_active_credits_for_user()
  • Filters out credits where user lacks the required role
  • Credits reappear automatically if the role is re-added later
         │
         ▼
User adds qualifying product to cart
         │
         ▼
woocommerce_cart_calculate_fees (priority 20)
SAWE_MSC_Cart::apply_credits_to_cart()
  • Calculates qualifying_total from post-coupon cart item totals (line_total)
  • Determines discount = min(balance, qualifying_total)
  • Calls $cart->add_fee( label, -discount, taxable=false )
  • Saves { post_id: discount } to SESSION_APPLIED
         │
         ▼
User sees checkout form
SAWE_MSC_Checkout::display_credit_notice() (priority 5)
  • Renders .sawe-msc-credit-box for each active credit
  • Shows balance, amount applied, renewal date
  • Shows Remove / Re-apply button
         │
         ▼
User places order
woocommerce_checkout_order_created
SAWE_MSC_Checkout::finalise_deductions()
  • Reads SESSION_APPLIED
  • Calls SAWE_MSC_DB::deduct_credit() for each entry
  • Stores deductions in order meta _sawe_msc_credit_deductions
  • Clears session keys
```

---

## 14. How Coupons Flow End-to-End

```
Admin creates WC coupon (shop_coupon) and sets SAWE options:
  • Eligible Member Roles (optional)
  • Display in My Account (optional)
  • Display on Cart/Checkout (optional)
  • Auto-apply (optional)
         │
         ▼
User visits cart or checkout
woocommerce_before_cart / woocommerce_before_checkout_form (priority 1)
SAWE_MSC_Coupons::maybe_auto_apply_coupons()
  • For each auto-apply coupon:
      – Skip if code is in SESSION_COUPON_REMOVED
      – Skip if already applied to cart
      – Skip if user role doesn't match
      – Skip if no qualifying cart items
      – WC()->cart->apply_coupon( $code )  ← WC handles discount calc
  • WC success notices suppressed during this loop
         │
         ▼
woocommerce_before_cart_totals (priority 15)
woocommerce_before_checkout_form (priority 6)
SAWE_MSC_Coupons::display_coupon_notices()
  • For each display_cart coupon eligible to user + applicable to cart:
      – Applied: "Remove Coupon" button
      – Not applied + auto_apply + removed by user: "Re-apply Coupon" button
      – Not applied otherwise: "Apply Coupon" button
         │
   ┌─────┴────────────────────────────────┐
   │ User clicks plugin Remove/Apply btn  │
   │ AJAX → sawe_msc_remove/apply_coupon  │
   │ Updates SESSION_COUPON_REMOVED       │
   │ Triggers WC cart refresh             │
   ├──────────────────────────────────────┤
   │ User clicks WC native [Remove] link  │
   │ woocommerce_removed_coupon fires     │
   │ on_coupon_removed() adds code to     │
   │ SESSION_COUPON_REMOVED — prevents    │
   │ auto-apply from re-adding it         │
   └──────────────────────────────────────┘
         │
         ▼
woocommerce_coupon_is_valid (filter)
SAWE_MSC_Coupons::check_role_restriction()
  • If coupon has _sawe_msc_coupon_roles and user lacks required role → false
  • Otherwise → pass through (WC validates the rest)
         │
         ▼
User visits My Account → Available Coupons
SAWE_MSC_Coupons::endpoint_content()
  • Shows all display_account coupons user is eligible for
  • Excludes expired / usage-limit-reached coupons
  • Displays coupon code, discount, expiry, uses remaining (if limited), product restrictions
         │
         ▼
Order placed / session cleared
woocommerce_thankyou / wp_logout
SAWE_MSC_Coupons::clear_session()
  • Clears SESSION_COUPON_REMOVED
  • WC tracks its own coupon usage in _used_by
```

---

## 15. Common Maintenance Tasks

### Set up an auto-apply role-restricted coupon

1. In WooCommerce, go to **Marketing → Coupons** or **SAWE Coupons and Credits → Coupons**.
2. Create or edit a coupon. Set the discount type and amount as usual.
3. Scroll to the **SAWE Coupon Settings** meta box.
4. Add the eligible role(s) using the **Eligible Member Roles** tag-chip picker.
5. Check **Show on Cart and Checkout** and **Automatically apply coupon when eligible**.
6. Optionally check **Show in My Account** so members can find the code on their account page.
7. Click **Update**.

### Add a new eligible role to an existing credit

1. Go to **SAWE Coupons and Credits → Store Credits → [credit name] → Edit**.
2. In the **Eligible Member Roles** section, pick the role from the dropdown and click **Add Role**.
3. Click **Update** to save.

### View and edit all user credit balances

Go to **SAWE Coupons and Credits → Active Store Credits**. The table shows every awarded credit across all users with inline balance editing. Click **Save** on any row to update the balance directly. Use **Download CSV** to export the full table.

### Manually award a credit to a user

```bash
wp eval "SAWE_MSC_DB::award_credit( 42, 15, 100.00 );"
```

### Force a renewal now (test mode)

```bash
wp cron event run sawe_msc_daily_renewal
```

### Flush rewrite rules after activation

After first activating the plugin, visit **Settings → Permalinks** and click **Save Changes**, or run:

```bash
wp rewrite flush
```

This ensures both `/my-account/store-credits/` and `/my-account/available-coupons/` resolve correctly.

---

## 16. Extending the Plugin

### Add a custom action after auto-apply

Hook into `woocommerce_before_cart` at a later priority than 1:

```php
add_action( 'woocommerce_before_cart', function() {
    $applied = WC()->cart->get_applied_coupons();
    // Your logic here
}, 20 );
```

### Show available coupons in a custom location

```php
if ( is_user_logged_in() ) {
    $coupon_ids = get_posts( [
        'post_type'  => 'shop_coupon',
        'post_status'=> 'publish',
        'fields'     => 'ids',
        'meta_query' => [ [ 'key' => '_sawe_msc_coupon_display_account', 'value' => 'yes' ] ],
    ] );
    foreach ( $coupon_ids as $id ) {
        $coupon = new WC_Coupon( $id );
        echo esc_html( strtoupper( $coupon->get_code() ) );
    }
}
```

### Override coupon card styles

In your child theme's `style.css` or **Appearance → Customize → Additional CSS**:

```css
/* Change coupon card to SAWE navy + gold */
.sawe-msc-coupon-box {
    background-color: #003087;
    border-color:     #c8a400;
    color:            #ffffff;
}
.sawe-msc-coupon-title { color: #ffd700; }
```

### Add REST API support for coupon meta

The SAWE coupon meta keys are stored on `shop_coupon` posts. To expose them via REST, register them explicitly:

```php
register_post_meta( 'shop_coupon', '_sawe_msc_coupon_roles', [
    'show_in_rest'   => true,
    'single'         => true,
    'type'           => 'string',
    'auth_callback'  => fn() => current_user_can( 'manage_woocommerce' ),
] );
```

---

## 17. Debugging Checklist

### Store Credits

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| Credit not awarded after login | User role not in credit's Eligible Roles | Check credit settings |
| Credit not awarded | Credit post is in Draft | Publish the credit post |
| Discount not showing at checkout | No qualifying products in cart | Verify product/category is in credit settings |
| Discount not showing at checkout | User's role was removed | Credit is hidden until role is restored; balance is preserved |
| "Applied to this order:" shows wrong amount | Coupon applied before credit | Fixed in 1.1.1 — qualifying total now uses post-coupon `line_total` |
| Balance not deducted after order | Guest order (no `user_id`) | Expected — guests not supported |
| "Available Store Credits" tab 404 | Rewrite rules stale | Settings → Permalinks → Save |
| Cron not running | `DISABLE_WP_CRON = true` | Set up real cron: `*/5 * * * * wp cron event run --due-now` |
| Active Store Credits page blank | No credits awarded yet | Award credits by having eligible users visit the store |
| CSV download produces garbled text in Excel | BOM missing (not applicable — fixed) | Plugin outputs UTF-8 BOM automatically |

### Coupons *(new in 1.1.0)*

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| Coupon not auto-applied | Cart is empty or has no qualifying items | Add qualifying products |
| Coupon not auto-applied | User role doesn't match restriction | Check SAWE Coupon Settings → Eligible Roles |
| Coupon not auto-applied | User manually removed it this session (via plugin button or WC native [Remove] link) | Coupon appears with "Re-apply" button |
| Coupon not auto-applied | `_sawe_msc_coupon_auto_apply` not set | Check checkbox in SAWE Coupon Settings |
| Coupon not visible on cart | `_sawe_msc_coupon_display_cart` not set | Check checkbox |
| Coupon not visible on cart | No qualifying items in cart | Per requirement: only shown when applicable |
| "This coupon is not valid" for eligible user | WC coupon itself is expired or usage-limited | Check coupon expiry + usage limit |
| "Available Coupons" tab 404 | Rewrite rules stale | Settings → Permalinks → Save |
| Coupon boxes appear but buttons don't work | `sawe-msc-coupons.js` not enqueued | Check `is_cart()` / `is_checkout()` return true on that page |

---

## 18. Upgrade Notes

### 1.1.x → 1.1.4

- **No DB changes, no rewrite flush needed.**
- **Per-user uses remaining on coupon display** — When a coupon has a "Usage limit per user" set, a "Your uses remaining:" line is now shown in coupon details on cart/checkout and the My Account "Available Coupons" tab.

### 1.1.x → 1.1.3

- **No DB changes, no rewrite flush needed.**
- **Coupon uses remaining** — the coupon display on cart/checkout and the My Account "Available Coupons" tab now show a "Uses remaining:" line when a coupon has a global usage limit set. Coupons with no limit are unaffected.

### 1.1.x → 1.1.2

- **No DB changes, no rewrite flush needed.**
- **Credit balance preservation on role removal** — credits are no longer zeroed when a user's eligible role is removed. If you have users whose balances were previously zeroed due to role changes, you will need to manually restore their balances via **SAWE Coupons and Credits → Active Store Credits** or with `SAWE_MSC_DB::award_credit()`.
- **Active Store Credits page** — new admin page available at **SAWE Coupons and Credits → Active Store Credits**. Inline balance editing and CSV export are available immediately with no configuration.
- **WC native [Remove] coupon link** — now correctly prevents auto-reapply via the `woocommerce_removed_coupon` hook. No action required.

### 1.0.x → 1.1.0

- **Flush rewrite rules** after upgrading. The new `available-coupons` My Account endpoint won't resolve until permalink rules are refreshed. Go to **Settings → Permalinks → Save Changes**.
- **No DB schema changes** — the existing `sawe_msc_user_credits` table is unchanged. Coupons use WC's native storage.
- **Admin menu renamed** — "SAWE Store Credits" is now "SAWE Coupons and Credits" in the sidebar. All existing store credit functionality is unaffected.
- **New meta keys on `shop_coupon`** — existing WC coupons are unaffected until you explicitly configure them via the new meta box.

### Upgrading the DB schema (future)

Update `SAWE_MSC_DB::create_tables()` and bump `DB_VERSION`. Add a migration check in `plugins_loaded`:

```php
if ( version_compare( get_option( SAWE_MSC_DB::DB_VERSION_KEY ), SAWE_MSC_DB::DB_VERSION, '<' ) ) {
    SAWE_MSC_DB::create_tables();
}
```

### Updating the plugin version

1. Bump `SAWE_MSC_VERSION` constant.
2. Bump the `Version:` header.
3. Update `DEVELOPER-GUIDE.md` header and `CHANGELOG.md`.
4. If schema changed, bump `SAWE_MSC_DB::DB_VERSION`.

---

## 19. Coding Standards

- **WordPress Coding Standards** (WPCS) — run `phpcs` before committing.
- All output escaped with `esc_html()`, `esc_attr()`, `wp_kses_post()`, or `wc_price()`.
- All DB queries use `$wpdb->prepare()`.
- PHP 8.0+ typed properties and union types are permitted.
- No global state — all state lives in WC session, DB, or post meta.
- Text domain: `sawe-msc`. All user-visible strings wrapped in `__()` or `esc_html_e()`.
- New classes follow the singleton pattern with `::instance()` accessor.
- Admin and frontend concerns are separated into `admin/` and `includes/` directories.
