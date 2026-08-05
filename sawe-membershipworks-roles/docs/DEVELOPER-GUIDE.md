# SAWE MembershipWorks Role Sync — Developer Guide

> **Audience:** Developers who need to install, configure, extend, debug, or upgrade this plugin.
> **Plugin version documented:** 1.2.0
> **Last updated:** 2026-08-05

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [File Map](#2-file-map)
3. [Database Schema](#3-database-schema)
4. [Class Reference](#4-class-reference)
5. [Hook Reference](#5-hook-reference)
6. [Constants](#6-constants)
7. [WordPress Option Keys](#7-wordpress-option-keys)
8. [How a Check Flows End-to-End](#8-how-a-check-flows-end-to-end)
9. [Common Maintenance Tasks](#9-common-maintenance-tasks)
10. [Extending the Plugin](#10-extending-the-plugin)
11. [Debugging Checklist](#11-debugging-checklist)
12. [Relationship to SAWE Membership Store Credits](#12-relationship-to-sawe-membership-store-credits)
13. [Upgrade Notes](#13-upgrade-notes)
14. [Coding Standards](#14-coding-standards)

---

## 1. Architecture Overview

```
WordPress request
       │
       ▼
SAWE_MembershipWorks_Role_Sync   (bootstrap singleton, includes, activation hook)
       │
       ├── SAWE_MWR_DB           Pure static DB layer — table create/drop, upsert, query, counts
       ├── SAWE_MWR_Role_Sync    Hook registration, throttling decision, sf_shortcode() call,
       │                         response interpretation, role add/remove, logging
       └── SAWE_MWR_Admin        Admin menu registration + settings (only on is_admin())
              └── SAWE_MWR_List_Table   WP_List_Table subclass for the diagnostics screen
```

**Design principles:**

- **Static DB layer** — `SAWE_MWR_DB` has no constructor; every method is called statically, matching the pattern used by `SAWE_MSC_DB` in the sibling Store Credits plugin.
- **One row per user** — the log table is upserted (`INSERT ... ON DUPLICATE KEY UPDATE` semantics via a manual existence check), not appended to. This is deliberate: the table doubles as the throttle clock, so history-per-check was traded for a much simpler, smaller table. If you need a full audit trail of every check ever made, see [§10 Extending the Plugin](#10-extending-the-plugin).
- **Fail safe, not fail silent** — any ambiguous or erroring MembershipWorks response leaves existing roles untouched (see `SAWE_MWR_Role_Sync::interpret_result()`), and is always logged with `status = 'error'` so it's visible on the admin screen rather than swallowed.
- **No hard dependency on WooCommerce or the Store Credits plugin** — see [§12](#12-relationship-to-sawe-membership-store-credits).

---

## 2. File Map

| File | Responsibility |
|---|---|
| `sawe-membershipworks-roles.php` | Plugin header, constants, `SAWE_MembershipWorks_Role_Sync` bootstrap singleton, activation hook (creates the table). |
| `includes/class-sawe-mwr-db.php` | `SAWE_MWR_DB` — table DDL (`create_tables()`, `drop_table()`), `get_log_for_user()`, `upsert_log()`, `query_rows()` (search/filter/sort/paginate), `get_distinct_errors()`, `get_status_counts()`. |
| `includes/class-sawe-mwr-role-sync.php` | `SAWE_MWR_Role_Sync` — hook registration, `maybe_sync_current_user()` (throttle gate), `perform_check()` (calls `sf_shortcode()`, updates roles), `interpret_result()` (yes/no/error classification), `log_result()`. |
| `admin/class-sawe-mwr-admin.php` | `SAWE_MWR_Admin` — `admin_menu` registration (nests under, or falls back to creating, `sawe-msc-settings`), Settings API registration for the uninstall option, renders the log page. |
| `admin/class-sawe-mwr-list-table.php` | `SAWE_MWR_List_Table` — `WP_List_Table` subclass: columns, sortable columns, views, error-filter dropdown, column renderers. |
| `uninstall.php` | Drops the log table only if `sawe_mwr_remove_table_on_uninstall` option is truthy. |

---

## 3. Database Schema

Table: `{$wpdb->prefix}sawe_mwr_check_log` (see `SAWE_MWR_DB::table_name()`).

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint(20) unsigned` | Primary key. |
| `user_id` | `bigint(20) unsigned` | **Unique.** One row per WordPress user. |
| `user_login` | `varchar(60)` | Snapshot as of last check. |
| `display_name` | `varchar(250)` | Snapshot as of last check. |
| `is_member` | `tinyint(1)` | Last known `member` role state. Drives the throttle interval (§8). |
| `is_corporate` | `tinyint(1)` | Last known `member-company` role state. |
| `status` | `varchar(20)` | `'ok'` or `'error'`. Indexed. |
| `api_response` | `longtext` | `wp_json_encode()`'d `{ "member": "...", "corporate": "..." }` — the raw, `strip_tags()`'d `sf_shortcode()` output for each check. |
| `error_message` | `text` | Empty string when `status = 'ok'`. |
| `last_checked_at` | `datetime` | Indexed. The throttle clock. |
| `created_at` | `datetime` | Set once, preserved across updates. |

Created/altered via `dbDelta()` in `SAWE_MWR_DB::create_tables()`, called from `register_activation_hook()`. `dbDelta()` is idempotent — safe to re-run if the schema changes in a future version (add new columns to the `CREATE TABLE` string and bump the plugin version; dbDelta will `ALTER TABLE` existing installs on next activation).

---

## 4. Class Reference

### `SAWE_MembershipWorks_Role_Sync` (main file)
Singleton bootstrap. `includes()` requires all class files; `init_components()` (on `plugins_loaded`, priority 6) instantiates `SAWE_MWR_Role_Sync` always, and `SAWE_MWR_Admin` only when `is_admin()`.

### `SAWE_MWR_DB`
Static-only. See [§3](#3-database-schema) for the table it owns. `query_rows( array $args )` is the single method the admin list table calls; it whitelists `orderby` against a fixed map to prevent SQL injection.

### `SAWE_MWR_Role_Sync`
- `MEMBER_CHECK_INTERVAL` = `DAY_IN_SECONDS`, `NONMEMBER_CHECK_INTERVAL` = `5 * MINUTE_IN_SECONDS`.
- `maybe_sync_current_user()` — the throttle gate. Reads the user's existing log row, picks the interval based on `is_member`, and only calls `perform_check()` if enough time has elapsed.
- `perform_check( WP_User $user, ?object $existing )` — guards for `sf_shortcode()` missing and for thrown exceptions, then calls it twice (general + corporate) and hands both raw strings to `interpret_result()`.
- `interpret_result( string $raw )` — returns `[ is_yes, is_error, raw_text ]`. `stripos( $raw, 'yes' )` → member; empty or case-insensitive `'no'` → non-member; anything else → error.
- `log_result(...)` — the only place that calls `SAWE_MWR_DB::upsert_log()`.

### `SAWE_MWR_Admin`
`register_menu()` is the integration point with the Store Credits plugin — see [§12](#12-relationship-to-sawe-membership-store-credits). `register_settings()` registers the single `sawe_mwr_remove_table_on_uninstall` option via the Settings API.

### `SAWE_MWR_List_Table extends WP_List_Table`
Standard WP admin list table. `prepare_items()` reads `$_GET`/`$_REQUEST` (`s`, `status`, `error_filter`, `orderby`, `order`, page number) and calls `SAWE_MWR_DB::query_rows()`. `get_views()` renders the All/OK/Errors links. `extra_tablenav( 'top' )` renders the error-message dropdown.

---

## 5. Hook Reference

### Actions this plugin registers callbacks on
| Hook | Priority | Callback |
|---|---|---|
| `plugins_loaded` | 6 | Boots `SAWE_MembershipWorks_Role_Sync::instance()` |
| `wp_login` | 10 | `SAWE_MWR_Role_Sync::maybe_sync_current_user()` |
| `profile_update` | 10 | `SAWE_MWR_Role_Sync::maybe_sync_current_user()` |
| `woocommerce_before_cart` | 5 | `SAWE_MWR_Role_Sync::maybe_sync_current_user()` |
| `woocommerce_before_checkout_form` | 5 | `SAWE_MWR_Role_Sync::maybe_sync_current_user()` |
| `woocommerce_before_account_navigation` | 5 | `SAWE_MWR_Role_Sync::maybe_sync_current_user()` |
| `wp` | default | `SAWE_MWR_Role_Sync::maybe_sync_on_front_end()` (guards for admin/AJAX/REST/cron/logged-out, then delegates to the same method above) |
| `admin_menu` | default | `SAWE_MWR_Admin::register_menu()` |
| `admin_init` | default | `SAWE_MWR_Admin::register_settings()` |

### Activation / deactivation
- `register_activation_hook` → `SAWE_MWR_DB::create_tables()`.
- `register_deactivation_hook` → no-op (data is intentionally preserved; see `uninstall.php`).

This plugin does not currently expose its own custom actions/filters for third-party extension. See [§10](#10-extending-the-plugin) if you need to add some.

---

## 6. Constants

| Constant | Value | Purpose |
|---|---|---|
| `SAWE_MWR_VERSION` | `'1.2.0'` | Bump in sync with the `Version:` header on every release. |
| `SAWE_MWR_PLUGIN_FILE` | `__FILE__` of main file | Passed to `register_activation_hook()`. |
| `SAWE_MWR_PLUGIN_DIR` | `plugin_dir_path( __FILE__ )` | Used for `require_once` includes. |
| `SAWE_MWR_PLUGIN_URL` | `plugin_dir_url( __FILE__ )` | Reserved for future enqueued assets (none as of 1.2.0). |
| `SAWE_MWR_TEXT_DOMAIN` | `'sawe-mwr'` | i18n text domain. |
| `SAWE_MWR_PARENT_MENU_SLUG` | `'sawe-msc-settings'` | The Store Credits plugin's top-level menu slug — see [§12](#12-relationship-to-sawe-membership-store-credits). |

---

## 7. WordPress Option Keys

| Option | Type | Default | Purpose |
|---|---|---|---|
| `sawe_mwr_remove_table_on_uninstall` | boolean | `false` | When true, `uninstall.php` drops `sawe_mwr_check_log` on plugin deletion. |

---

## 8. How a Check Flows End-to-End

1. A hook fires (login, profile update, a WooCommerce page, or any front-end page load) for a logged-in, non-administrator user.
2. `maybe_sync_current_user()` loads the user's row from `sawe_mwr_check_log`, if any.
3. If a row exists: `elapsed = now - last_checked_at`; `interval = is_member ? 24h : 5min`. If `elapsed < interval`, **return — no API call.**
4. Otherwise, `perform_check()` runs:
   - If `sf_shortcode()` doesn't exist → log `status = 'error'`, roles untouched, return.
   - Call `sf_shortcode()` for the general and corporate shortcodes; catch `\Throwable` → log error, roles untouched, return.
   - `interpret_result()` each raw response.
   - If either is ambiguous/error → log `status = 'error'` with a combined message, **roles untouched**.
   - Otherwise → `add_role()`/`remove_role()` for `member` and `member-company` based on the results, log `status = 'ok'`.
5. Either way, `last_checked_at` is refreshed to now, so the throttle window restarts from this request regardless of outcome.

---

## 9. Common Maintenance Tasks

**Force an immediate re-check for one user** (e.g. they just paid their MembershipWorks dues and don't want to wait):
```sql
DELETE FROM wp_sawe_mwr_check_log WHERE user_id = 123;
```
The next qualifying page load/hook will treat them as never-checked and call the API immediately.

**Force a re-check for everyone** (e.g. after fixing a MembershipWorks Organization ID misconfiguration):
```sql
TRUNCATE TABLE wp_sawe_mwr_check_log;
```
This is safe — it only clears the diagnostic/throttle table, not WordPress roles or user accounts.

**See who's currently flagged as an error:**
Visit **SAWE Coupons and Credits → MembershipWorks Sync Log** and click the **Errors** view, or filter by the specific error message.

**Change the throttle intervals:**
Edit the `MEMBER_CHECK_INTERVAL` / `NONMEMBER_CHECK_INTERVAL` constants in `includes/class-sawe-mwr-role-sync.php`.

---

## 10. Extending the Plugin

- **Full audit history instead of one row per user:** remove the `UNIQUE KEY user_id` constraint in `SAWE_MWR_DB::create_tables()` and change `upsert_log()` to always `$wpdb->insert()`. Update `get_log_for_user()` and `query_rows()` to select the most recent row per user (`ORDER BY last_checked_at DESC LIMIT 1` per user, or a `GROUP BY` subquery) if you still want the list table to show "current state" by default.
- **Custom actions for third-party integration:** add `do_action( 'sawe_mwr_after_check', $user, $is_member, $is_corporate, $status )` at the end of `perform_check()` so other plugins/snippets can react to a fresh result without touching this plugin's code.
- **Additional MembershipWorks labels beyond "Corporate Member":** generalize `SAWE_MWR_Role_Sync` to accept an array of `[ role_slug => shortcode_label ]` pairs (filterable via a new `sawe_mwr_role_map` filter) instead of the two hard-coded shortcodes.

---

## 11. Debugging Checklist

1. **No log rows are appearing at all** — confirm the plugin is active and the table exists (`SHOW TABLES LIKE '%sawe_mwr_check_log%'`). Re-activate the plugin to re-run `create_tables()` if needed.
2. **Every row shows `status = error`, "sf_shortcode() function not found"** — the MembershipWorks (`memberfindme`) plugin isn't active, or loads after this plugin in a way that `function_exists()` fails at the time of the check. Confirm MembershipWorks is active in **Plugins**.
3. **Every row shows `status = error` with the raw org message** — MembershipWorks itself isn't configured (missing Organization ID under its own **Plugin Settings**).
4. **A known member shows `is_member = No`** — check their row's `error_message`. If it's an error row, their role was deliberately left untouched, not demoted; look at `api_response` for the raw shortcode output MembershipWorks actually returned.
5. **Checks seem to never re-run** — remember the throttle: members wait up to 24h, non-members/errors up to 5 minutes, measured from `last_checked_at`. Use the `DELETE` from [§9](#9-common-maintenance-tasks) to force it.

---

## 12. Relationship to SAWE Membership Store Credits

This plugin and SAWE Membership Store Credits share this repository and, from v1.2.0 onward, a common version number and git tag — but they remain **independent plugins** with no code dependency in either direction:

- `SAWE_MWR_Admin::register_menu()` checks `class_exists( 'SAWE_MSC_Admin' )`. If the Store Credits plugin is active, this plugin's "MembershipWorks Sync Log" page nests under its existing `sawe-msc-settings` ("SAWE Coupons and Credits") menu. If not, this plugin registers that same top-level menu itself.
- Nothing in `includes/` references any `SAWE_MSC_*` class or WooCommerce class.
- The release workflow (`.github/workflows/release.yml`) builds and attaches two separate zip files per tag — each plugin can be installed/updated/removed on a WordPress site independently of the other.

---

## 13. Upgrade Notes

### Upgrading from 1.0.0 → 1.2.0
No schema or behavior changes — version bump only, to align with the shared repo/tag release scheme. No action needed beyond updating the plugin files.

---

## 14. Coding Standards

- PHP 8.0+ syntax is assumed (typed properties, nullable types, short list destructuring).
- All SQL goes through `$wpdb->prepare()` except for hard-coded table/column identifiers (never user input) — see the `phpcs:ignore` annotations in `class-sawe-mwr-db.php` for exactly which lines and why.
- All output is escaped at render time (`esc_html()`, `esc_attr()`, `esc_url()`) in `class-sawe-mwr-list-table.php` and `class-sawe-mwr-admin.php`.
- Singletons throughout (`::instance()`), matching the convention in SAWE Membership Store Credits.
