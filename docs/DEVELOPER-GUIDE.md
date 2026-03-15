# SAWE Membership Store Credits — Developer Guide

> **Audience:** Developers who need to install, configure, extend, debug, or upgrade this plugin.  
> **Plugin version documented:** 1.0.0  
> **Last updated:** 2026-03

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [File Map](#2-file-map)
3. [Database Schema](#3-database-schema)
4. [Custom Post Type & Meta Reference](#4-custom-post-type--meta-reference)
5. [Class Reference](#5-class-reference)
6. [Hook & Filter Reference](#6-hook--filter-reference)
7. [WooCommerce Session Keys](#7-woocommerce-session-keys)
8. [Order Meta Keys](#8-order-meta-keys)
9. [WordPress Option Keys](#9-wordpress-option-keys)
10. [CSS Class Reference](#10-css-class-reference)
11. [JavaScript API](#11-javascript-api)
12. [How Credits Flow End-to-End](#12-how-credits-flow-end-to-end)
13. [Common Maintenance Tasks](#13-common-maintenance-tasks)
14. [Extending the Plugin](#14-extending-the-plugin)
15. [Debugging Checklist](#15-debugging-checklist)
16. [Upgrade Notes](#16-upgrade-notes)
17. [Coding Standards](#17-coding-standards)

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
       ├── SAWE_MSC_Account              My Account tab + dashboard widget
       └── SAWE_MSC_Admin                Admin menu, settings page, meta boxes, list columns
```

**Design principles:**

- **Static DB layer** — `SAWE_MSC_DB` is a pure static helper class with no constructor.  Any
  other class can call it without needing an instance.
- **Singleton components** — every other class uses `::instance()` so hooks are never registered
  twice, even if called from multiple places.
- **Nothing written to DB until checkout** — the discount amount lives exclusively in the WC
  session (`sawe_msc_applied`) until `woocommerce_checkout_order_created` fires.  This means
  abandoned carts never burn a user's balance.
- **CPT as config store** — each store credit definition is a WordPress post (`sawe_store_credit`).
  This gives admins full draft/publish/trash workflow, revision history, and REST API access for
  free.

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
│   ├── class-sawe-msc-cart.php          ← Fee injection, AJAX handlers, session management
│   ├── class-sawe-msc-checkout.php      ← Checkout display, DB finalisation, cancel restore
│   └── class-sawe-msc-account.php       ← My Account tab/endpoint + dashboard widget
│
├── admin/
│   ├── class-sawe-msc-admin.php         ← Menus, WP Settings API, meta boxes, list columns
│   ├── css/sawe-msc-admin.css           ← Tag-chip UI styles
│   └── js/sawe-msc-admin.js             ← Tag-chip add/remove behaviour
│
├── public/
│   ├── css/sawe-msc-public.css          ← Credit box styles (overridable)
│   └── js/sawe-msc-cart.js              ← Remove/restore AJAX handlers
│
└── docs/
    ├── DEVELOPER-GUIDE.md               ← This file
    └── CHANGELOG.md                     ← Version history
```

---

## 3. Database Schema

### Table: `{prefix}sawe_msc_user_credits`

Stores **one row per (credit definition, user) pair**.  The balance is decremented on order
placement and restored on cancellation/refund.

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

**To inspect balances manually:**
```sql
SELECT u.user_login, c.credit_post_id, c.balance, c.initial_amount, c.renewed_at
FROM   wp_sawe_msc_user_credits c
JOIN   wp_users u ON u.ID = c.user_id
ORDER  BY c.credit_post_id, u.user_login;
```

**To manually reset a single user's balance:**
```sql
UPDATE wp_sawe_msc_user_credits
SET    balance = initial_amount, renewed_at = NOW()
WHERE  credit_post_id = <POST_ID>
  AND  user_id = <USER_ID>;
```

---

## 4. Custom Post Type & Meta Reference

**Post type slug:** `sawe_store_credit`  
**Post title** → shown to members as the credit name.  
**Post content** (body/editor) → shown to members as the credit description.  

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
// Returns a typed, pre-decoded array:
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

## 5. Class Reference

### `SAWE_Membership_Store_Credits`
*File:* `sawe-membership-store-credits.php`

The bootstrap singleton.  Loads all files, registers activation/deactivation hooks, and
instantiates component singletons after `plugins_loaded`.

| Method | Purpose |
|--------|---------|
| `::instance()` | Returns/creates the singleton |
| `includes()` | Requires all class files |
| `init_hooks()` | Registers `plugins_loaded` callbacks |
| `load_textdomain()` | Loads `.mo` translation files |
| `check_woocommerce()` | Shows admin error notice if WC is missing |
| `init_components()` | Instantiates all component singletons |

---

### `SAWE_MSC_DB`
*File:* `includes/class-sawe-msc-db.php`  
*All methods static — no instance needed.*

| Method | Signature | Purpose |
|--------|-----------|---------|
| `user_credits_table()` | `(): string` | Returns fully-qualified table name |
| `create_tables()` | `(): void` | Runs `dbDelta()` to create/update table; called on activation |
| `drop_tables()` | `(): void` | Drops table; called from `uninstall.php` when opted-in |
| `get_user_credit()` | `(int $credit_post_id, int $user_id): ?object` | Fetches single row or null |
| `get_credits_for_user()` | `(int $user_id): object[]` | All rows for a user (all credit definitions) |
| `award_credit()` | `(int $credit_post_id, int $user_id, float $amount): bool` | Inserts new row or resets balance on renewal |
| `deduct_credit()` | `(int $credit_post_id, int $user_id, float $amount): bool` | Subtracts amount, clamped to 0 |
| `restore_credit()` | `(int $credit_post_id, int $user_id, float $amount): bool` | Adds amount back, capped at `initial_amount` |
| `remove_credit()` | `(int $credit_post_id, int $user_id): bool` | Zeros balance (role removed) |
| `get_all_rows_for_credit()` | `(int $credit_post_id): object[]` | All user rows for one credit (used by renewal cron) |

---

### `SAWE_MSC_Credit_Post_Type`
*File:* `includes/class-sawe-msc-credit-post-type.php`

Registers the CPT and post meta.  Also provides static helpers used by all other classes.

| Method | Signature | Purpose |
|--------|-----------|---------|
| `register_post_type()` | `(): void` | Registers `sawe_store_credit` CPT |
| `register_meta()` | `(): void` | Registers all 7 post meta keys with REST + auth |
| `get_published_credits()` | `(): WP_Post[]` | Returns all `publish` credit posts |
| `get_credit_meta()` | `(int $post_id): array` | Returns typed, decoded meta array |
| `get_next_renewal_date()` | `(int $post_id): DateTime` | Returns next renewal DateTime (UTC) |

---

### `SAWE_MSC_User_Credits`
*File:* `includes/class-sawe-msc-user-credits.php`

Bridges the CPT config and the DB balance table.

| Method | Signature | Purpose |
|--------|-----------|---------|
| `sync_current_user()` | `(): void` | Hook callback — calls `sync_user()` for current user |
| `sync_user_on_login()` | `(string $login, WP_User $user): void` | Hook callback for `wp_login` |
| `sync_user()` | `(int $user_id): void` | Awards new credits; zeroes credits when role is removed |
| `process_renewals()` | `(): void` | Cron callback — resets balances on renewal date |
| `get_active_credits_for_user()` | `(int $user_id): array[]` | Returns enriched credit array for display/cart use |
| `product_qualifies()` | `(int $product_id, array $meta): bool` | Returns true if product is in qualifying list or category |

**`get_active_credits_for_user()` return shape:**
```php
[
  [
    'row'     => object,      // DB row from sawe_msc_user_credits
    'post'    => WP_Post,     // The sawe_store_credit post
    'meta'    => array,       // get_credit_meta() output
    'balance' => float,       // current balance (may be 0)
    'renewal' => DateTime,    // next renewal date UTC
  ],
  ...
]
```

---

### `SAWE_MSC_Cart`
*File:* `includes/class-sawe-msc-cart.php*

Injects discounts as WooCommerce fees and manages the user-facing remove/restore UI.

| Constant | Value | Purpose |
|----------|-------|---------|
| `SESSION_APPLIED` | `'sawe_msc_applied'` | WC session key — `[post_id => amount]` reserved this page load |
| `SESSION_REMOVED` | `'sawe_msc_removed'` | WC session key — `[post_id, ...]` user removed |

| Method | Purpose |
|--------|---------|
| `apply_credits_to_cart()` | `woocommerce_cart_calculate_fees` callback; adds negative fee per credit |
| `get_qualifying_cart_total()` | Sums `line_subtotal` for all qualifying items in cart |
| `on_cart_item_removed()` | Clears `SESSION_APPLIED` so amounts recalculate |
| `on_cart_updated()` | Same as above for quantity updates |
| `ajax_remove_credit()` | AJAX action `sawe_msc_remove_credit` |
| `ajax_restore_credit()` | AJAX action `sawe_msc_restore_credit` |
| `get_removed_ids()` | Private — reads `SESSION_REMOVED` from WC session |
| `::get_applied()` | Public static — reads `SESSION_APPLIED`; used by Checkout class |
| `clear_session()` | Wipes both session keys on logout / thank-you page |
| `enqueue_scripts()` | Enqueues `sawe-msc-public.css` + `sawe-msc-cart.js` on cart/checkout |

---

### `SAWE_MSC_Checkout`
*File:* `includes/class-sawe-msc-checkout.php`

| Method | Purpose |
|--------|---------|
| `display_credit_notice()` | Renders styled `.sawe-msc-credit-box` above checkout form |
| `finalise_deductions()` | On `woocommerce_checkout_order_created`: deducts from DB, stores meta on order |
| `restore_on_cancel()` | On order cancel/refund: restores amounts using order meta, then clears it |

**Order meta written:**  
`_sawe_msc_credit_deductions` → `array( $credit_post_id => $amount_deducted, ... )`

---

### `SAWE_MSC_Account`
*File:* `includes/class-sawe-msc-account.php`

| Constant | Value |
|----------|-------|
| `ENDPOINT` | `'store-credits'` |

| Method | Purpose |
|--------|---------|
| `add_endpoint()` | Registers rewrite endpoint |
| `add_query_vars()` | Adds endpoint to WP query vars |
| `add_menu_item()` | Inserts "Available Store Credits" before logout in My Account nav |
| `endpoint_content()` | Renders full credit list on the tab page |
| `dashboard_widget()` | Shows compact summary on the My Account dashboard |
| `render_credit_card()` | Shared HTML renderer; `$compact = true` suppresses description |
| `enqueue_styles()` | Loads `sawe-msc-public.css` on account pages |

> **After adding this endpoint** you must flush rewrite rules.  Go to  
> **Settings → Permalinks** and click **Save Changes**, or run `wp rewrite flush`.

---

### `SAWE_MSC_Admin`
*File:* `admin/class-sawe-msc-admin.php`

| Method | Purpose |
|--------|---------|
| `register_menus()` | Adds top-level "Store Credits" menu + "Settings" sub-page |
| `register_settings()` | Registers `sawe_msc_remove_tables_on_uninstall` option |
| `render_remove_tables_field()` | Renders the uninstall checkbox |
| `render_settings_page()` | Renders the Settings page |
| `add_meta_boxes()` | Adds "Store Credit Settings" meta box to CPT edit screen |
| `render_settings_metabox()` | Renders all meta fields including tag-chip list managers |
| `save_meta_boxes()` | Sanitises + saves all meta on `save_post`; verifies nonce + caps |
| `columns()` | Adds Amount, Renewal Date, Eligible Roles columns to CPT list |
| `column_content()` | Renders the data in each custom column |
| `enqueue_scripts()` | Loads admin CSS + JS only on `sawe_store_credit` screens |

---

## 6. Hook & Filter Reference

### Actions registered by this plugin

| Hook | Class / Method | Priority | Notes |
|------|---------------|----------|-------|
| `before_woocommerce_init` | main file | — | Declares HPOS compatibility |
| `plugins_loaded` | bootstrap | 5 | Instantiates main class |
| `plugins_loaded` | `SAWE_Membership_Store_Credits` | default | Loads textdomain, checks WC, inits components |
| `init` | `SAWE_MSC_Credit_Post_Type` | default | Registers CPT + meta |
| `init` | `SAWE_MSC_Account` | default | Registers rewrite endpoint |
| `woocommerce_before_shop_loop` | `SAWE_MSC_User_Credits` | default | Syncs current user |
| `woocommerce_before_single_product` | `SAWE_MSC_User_Credits` | default | Syncs current user |
| `woocommerce_before_cart` | `SAWE_MSC_User_Credits` | default | Syncs current user |
| `woocommerce_before_checkout_form` | `SAWE_MSC_User_Credits` | default | Syncs current user |
| `woocommerce_before_checkout_form` | `SAWE_MSC_Checkout` | 5 | Renders credit notice box |
| `wp_login` | `SAWE_MSC_User_Credits` | 10 | Syncs user on login |
| `sawe_msc_daily_renewal` | `SAWE_MSC_User_Credits` | default | WP-Cron renewal callback |
| `woocommerce_cart_calculate_fees` | `SAWE_MSC_Cart` | 20 | Injects credit discounts |
| `woocommerce_cart_item_removed` | `SAWE_MSC_Cart` | 10 | Clears session cache |
| `woocommerce_update_cart_action_cart_updated` | `SAWE_MSC_Cart` | default | Clears session cache |
| `wp_ajax_sawe_msc_remove_credit` | `SAWE_MSC_Cart` | default | AJAX: remove credit |
| `wp_ajax_sawe_msc_restore_credit` | `SAWE_MSC_Cart` | default | AJAX: restore credit |
| `wp_enqueue_scripts` | `SAWE_MSC_Cart` | default | Public CSS + JS |
| `wp_enqueue_scripts` | `SAWE_MSC_Account` | default | Account page CSS |
| `woocommerce_thankyou` | `SAWE_MSC_Cart` | default | Clears session |
| `wp_logout` | `SAWE_MSC_Cart` | default | Clears session |
| `woocommerce_checkout_order_created` | `SAWE_MSC_Checkout` | 10 | Finalises DB deductions |
| `woocommerce_order_status_cancelled` | `SAWE_MSC_Checkout` | default | Restores balance |
| `woocommerce_order_status_refunded` | `SAWE_MSC_Checkout` | default | Restores balance |
| `woocommerce_account_dashboard` | `SAWE_MSC_Account` | default | Dashboard summary widget |
| `woocommerce_account_store-credits_endpoint` | `SAWE_MSC_Account` | default | Tab page content |
| `admin_menu` | `SAWE_MSC_Admin` | default | Registers menus |
| `admin_init` | `SAWE_MSC_Admin` | default | Registers settings |
| `add_meta_boxes` | `SAWE_MSC_Admin` | default | Adds meta box |
| `save_post` | `SAWE_MSC_Admin` | 10 | Saves meta box data |
| `admin_enqueue_scripts` | `SAWE_MSC_Admin` | default | Admin CSS + JS |

### Filters registered by this plugin

| Hook | Class / Method | Notes |
|------|---------------|-------|
| `query_vars` | `SAWE_MSC_Account` | Adds `store-credits` endpoint var |
| `woocommerce_account_menu_items` | `SAWE_MSC_Account` | Inserts "Available Store Credits" menu item |
| `manage_sawe_store_credit_posts_columns` | `SAWE_MSC_Admin` | Adds custom list columns |

---

## 7. WooCommerce Session Keys

| Key | Type | Set by | Read by | Purpose |
|-----|------|--------|---------|---------|
| `sawe_msc_applied` | `array<int,float>` | `SAWE_MSC_Cart::apply_credits_to_cart()` | `SAWE_MSC_Checkout`, `SAWE_MSC_Checkout::display_credit_notice()` | Maps credit post ID → discount amount reserved this page load |
| `sawe_msc_removed` | `int[]` | `SAWE_MSC_Cart::ajax_remove_credit()` | `SAWE_MSC_Cart::apply_credits_to_cart()`, `SAWE_MSC_Checkout::display_credit_notice()` | Credit post IDs the user has manually removed from this order |

Both keys are cleared by `SAWE_MSC_Cart::clear_session()` (on logout and thank-you page) and after `finalise_deductions()` runs.

---

## 8. Order Meta Keys

| Key | Type | Written by | Read by |
|-----|------|-----------|---------|
| `_sawe_msc_credit_deductions` | `array<int,float>` | `SAWE_MSC_Checkout::finalise_deductions()` | `SAWE_MSC_Checkout::restore_on_cancel()` |

This meta is **deleted** once a restore has been applied to prevent double-restores.

---

## 9. WordPress Option Keys

| Option | Type | Default | Purpose |
|--------|------|---------|---------|
| `sawe_msc_db_version` | `string` | — | Tracks installed DB schema version |
| `sawe_msc_remove_tables_on_uninstall` | `bool` | `false` | If true, `uninstall.php` drops the DB table |

---

## 10. CSS Class Reference

All classes are prefixed `sawe-msc-` to avoid collisions.

### Public (frontend)

| Class | Element | Description |
|-------|---------|-------------|
| `.sawe-msc-credit-notice-wrap` | `<div>` | Outer wrapper for all credit boxes on a page |
| `.sawe-msc-credit-box` | `<div>` | Individual credit card; styled light-blue + gold border |
| `.sawe-msc-credit-title` | `<h4>` | Credit name heading |
| `.sawe-msc-credit-desc` | `<p>` | Credit description paragraph |
| `.sawe-msc-credit-details` | `<ul>` | Balance / renewal date list |
| `.sawe-msc-remove-btn` | `<button>` | "Remove Store Credit" button |
| `.sawe-msc-restore-btn` | `<button>` | "Re-apply Store Credit" button |

**To customise** the credit box, override these classes in your child theme or via
**Appearance → Customize → Additional CSS**.  Example:
```css
.sawe-msc-credit-box {
    background-color: #f0f7ff;
    border-color: #003087;   /* SAWE navy */
}
.sawe-msc-credit-title { color: #003087; }
```

### Admin

| Class | Element | Description |
|-------|---------|-------------|
| `.sawe-msc-metabox` | `<div>` | Meta box wrapper |
| `.sawe-msc-field` | `<div>` | Individual field row |
| `.sawe-msc-list-manager` | `<div>` | Roles / categories / products list area |
| `.sawe-msc-selected-list` | `<div>` | Tag-chip container |
| `.sawe-msc-tag` | `<span>` | Individual chip |
| `.sawe-msc-remove-tag` | `<button>` | × button inside a chip |
| `.sawe-msc-add-dropdown` | `<select>` | Dropdown to pick a new item |
| `.sawe-msc-add-tag-btn` | `<button>` | "Add" button |

---

## 11. JavaScript API

### `public/js/sawe-msc-cart.js`

Depends on: `jQuery`, `wc-cart` (WC script handle).  
Localised object: `saweMscData`

```js
saweMscData = {
  ajaxUrl: string,   // wp-admin/admin-ajax.php
  nonce:   string,   // wp_create_nonce('sawe_msc_nonce')
  i18n: {
    removeLabel:  string,  // "Remove store credit"
    restoreLabel: string,  // "Re-apply store credit"
  }
}
```

**Events triggered after successful AJAX:**
- `update_checkout` — forces WC to recalculate the checkout totals.
- `wc_update_cart` — forces WC cart fragments to reload.

Both are triggered on `document.body`.

### `admin/js/sawe-msc-admin.js`

Manages tag-chip list managers.  Reads `data-target` from `.sawe-msc-add-tag-btn` to locate the
correct dropdown and list container:

| `data-target` | Dropdown ID | List ID | Input name |
|---------------|-------------|---------|------------|
| `roles` | `roles-dropdown` | `roles-list` | `sawe_msc_roles[]` |
| `cats` | `cats-dropdown` | `cats-list` | `sawe_msc_product_categories[]` |
| `products` | `products-dropdown` | `products-list` | `sawe_msc_products[]` |

---

## 12. How Credits Flow End-to-End

```
User visits store page / logs in
         │
         ▼
SAWE_MSC_User_Credits::sync_user()
  • For each published sawe_store_credit:
      – If user has matching role AND no DB row → award_credit()
      – If user has no matching role AND balance > 0 → remove_credit() (zero balance)
         │
         ▼
User adds qualifying product to cart
         │
         ▼
woocommerce_cart_calculate_fees (priority 20)
SAWE_MSC_Cart::apply_credits_to_cart()
  • Calculates qualifying_total from cart items
  • Determines discount = min(balance, qualifying_total)
  • Calls $cart->add_fee( label, -discount, taxable=false )
  • Saves { post_id: discount } to SESSION_APPLIED
         │
         ▼
User sees checkout form
SAWE_MSC_Checkout::display_credit_notice()
  • Renders .sawe-msc-credit-box for each active credit
  • Shows balance, amount applied, renewal date
  • Shows Remove button if discount > 0
         │
   ┌─────┴───────────────┐
   │ User removes credit │
   │ AJAX → ajax_remove  │
   │ Adds to SESSION_    │
   │ REMOVED, recalc     │
   └─────────────────────┘
         │
         ▼
User places order
woocommerce_checkout_order_created
SAWE_MSC_Checkout::finalise_deductions()
  • Reads SESSION_APPLIED
  • Calls SAWE_MSC_DB::deduct_credit() for each entry
  • Stores deductions in order meta _sawe_msc_credit_deductions
  • Clears session keys
         │
   ┌─────┴──────────────────┐
   │ Order cancelled/refund │
   │ restore_on_cancel()    │
   │ Reads order meta,      │
   │ calls restore_credit() │
   │ Deletes order meta     │
   └────────────────────────┘
         │
         ▼
Nightly cron: sawe_msc_daily_renewal
SAWE_MSC_User_Credits::process_renewals()
  • Checks if today == renewal month/day for each credit
  • For each eligible user → award_credit() (resets to initial_amount)
```

---

## 13. Common Maintenance Tasks

### Add a new eligible role to an existing credit

1. Go to **Store Credits → [credit name] → Edit**.
2. In the **Eligible Member Roles** section, pick the role from the dropdown and click **Add Role**.
3. Click **Update** to save.
4. New users with that role will receive the credit the next time they visit the store.  Existing
   users need to visit a store page or log in again to trigger `sync_user()`.

### Manually award a credit to a user who missed it

Use WP-CLI or a one-time script:
```php
SAWE_MSC_DB::award_credit( $credit_post_id, $user_id, $amount );
```
Or run via WP-CLI eval:
```bash
wp eval "SAWE_MSC_DB::award_credit( 42, 15, 100.00 );"
```

### Force a renewal now (test mode)

Trigger the cron event manually:
```bash
wp cron event run sawe_msc_daily_renewal
```
Or call from PHP:
```php
do_action( 'sawe_msc_daily_renewal' );
```

### Change the renewal date for an existing credit

1. Edit the credit post and change the **Renewal Date** month/day fields.
2. Save.  The cron will use the new date starting the next midnight check.
3. Note: changing the date does **not** immediately reset existing balances.

### Bulk-reset all balances for a credit

```sql
UPDATE wp_sawe_msc_user_credits
SET    balance = initial_amount, renewed_at = NOW()
WHERE  credit_post_id = <POST_ID>;
```

### Delete a store credit cleanly

1. Move it to Trash in the WordPress admin. This stops new awards and hides it from members.
2. Optionally delete the DB rows for that credit:
   ```sql
   DELETE FROM wp_sawe_msc_user_credits WHERE credit_post_id = <POST_ID>;
   ```
3. Permanently delete the post from Trash.

### Remove a specific user's balance manually

```bash
wp eval "SAWE_MSC_DB::remove_credit( 42, 15 );"
```

---

## 14. Extending the Plugin

### Add a custom hook after credit is awarded

The simplest approach is to extend `SAWE_MSC_User_Credits::sync_user()`.  Since the class
uses `::instance()`, hook into it via a plugin/theme action:

```php
// In your plugin or functions.php:
add_action( 'plugins_loaded', function() {
    // Re-open sync via a wrapper action you dispatch from a child class,
    // or hook the WC events that trigger sync and run your code after.
}, 20 );
```

Alternatively, monkey-patch by hooking `wp_login` at a later priority:

```php
add_action( 'wp_login', function( string $login, WP_User $user ) {
    // Your post-sync logic
    $credits = SAWE_MSC_User_Credits::get_active_credits_for_user( $user->ID );
    // ...
}, 20, 2 );
```

### Add a qualifying product type programmatically

Override `product_qualifies()` behaviour by hooking a filter.  (You'd need to add the filter
first — see the [Coding Standards](#17-coding-standards) section for how to submit a PR.)

### Show credits in a custom location

Call the static helper anywhere in your theme:
```php
if ( is_user_logged_in() ) {
    $credits = SAWE_MSC_User_Credits::get_active_credits_for_user( get_current_user_id() );
    foreach ( $credits as $credit ) {
        echo esc_html( $credit['post']->post_title ) . ': ' . wc_price( $credit['balance'] );
    }
}
```

### Add REST API support for credits

All 7 meta keys are registered with `show_in_rest => true`.  The CPT itself is registered with
`show_in_rest => true`.  Authenticated users with `edit_posts` capability can read/write meta via:

```
GET  /wp-json/wp/v2/sawe_store_credit
GET  /wp-json/wp/v2/sawe_store_credit/<id>
POST /wp-json/wp/v2/sawe_store_credit/<id>  (with meta payload)
```

---

## 15. Debugging Checklist

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| Credit not awarded after login | User role not in credit's Eligible Roles list | Check credit settings |
| Credit not awarded after login | Credit post is in Draft | Publish the credit post |
| Credit not awarded after login | WC session not initialised before sync | Usually resolves on next store page visit |
| Discount not showing at checkout | No qualifying products in cart | Verify product/category is in credit settings |
| Discount not showing at checkout | WC fee hook fired before session was set | Check hook priority; ours is 20 |
| Balance not deducted after order | Order was placed as guest (no `user_id`) | Expected — guest orders are not supported |
| Balance not restored after cancel | Order meta `_sawe_msc_credit_deductions` missing | The order may have been placed before plugin was active |
| "Available Store Credits" tab 404 | Rewrite rules stale | Go to Settings → Permalinks → Save |
| Cron not running | WP-Cron disabled (`DISABLE_WP_CRON = true`) | Set up a real cron job: `*/5 * * * * wp cron event run --due-now --path=/var/www/html` |
| Admin meta box missing | WC not active | Activate WooCommerce |
| Roles list empty in admin | No roles registered yet | Add roles via a membership plugin first |

### Enable debug logging

Add to `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```
Errors from `$wpdb->last_error` are not automatically logged; add temporary `error_log()` calls
inside the DB methods during debugging.

---

## 16. Upgrade Notes

### Upgrading the DB schema

If a future version adds columns, update `SAWE_MSC_DB::create_tables()` and bump
`DB_VERSION`.  `dbDelta()` handles `ALTER TABLE` automatically for new columns.

Add a version check on `plugins_loaded` to run migrations:
```php
if ( version_compare( get_option( SAWE_MSC_DB::DB_VERSION_KEY ), SAWE_MSC_DB::DB_VERSION, '<' ) ) {
    SAWE_MSC_DB::create_tables();
}
```

### Updating the plugin version

1. Bump `SAWE_MSC_VERSION` constant in the main plugin file.
2. Bump the `Version:` header in the main plugin file.
3. Update `DEVELOPER-GUIDE.md` header and `CHANGELOG.md`.
4. If schema changed, also bump `SAWE_MSC_DB::DB_VERSION`.

---

## 17. Coding Standards

- **WordPress Coding Standards** (WPCS) enforced — run `phpcs` before committing.
- All output escaped with `esc_html()`, `esc_attr()`, `wp_kses_post()`, or `wc_price()`.
- All DB queries use `$wpdb->prepare()`.
- Direct `phpcs:ignore` comments used only where WP's interpolation checker false-positives on
  table-name interpolation that is itself constructed from a safe function.
- PHP 8.0+ typed properties, union types, and named arguments are permitted.
- No global state — all state lives in WC session, DB, or post meta.
- Text domain: `sawe-msc`.  All user-visible strings wrapped in `__()` or `esc_html_e()`.
