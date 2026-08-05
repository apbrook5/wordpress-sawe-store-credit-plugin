# SAWE MembershipWorks Role Sync

**Version:** 1.2.0
**Requires WordPress:** 6.4+
**Requires PHP:** 8.0+
**Depends on:** MembershipWorks (`memberfindme`) plugin being active for real membership checks. Degrades gracefully (logs an error, skips role changes) if it isn't.

This plugin lives in the same repository as [SAWE Membership Store Credits](../README.md) and the two are versioned and tagged together (see [Versioning & Releases](#versioning--releases) below). They are still fully independent WordPress plugins — see [Independence from SAWE Membership Store Credits](#independence-from-sawe-membership-store-credits).

## Overview

Replaces the old **"Add WordPress Roles based on MembershipWorks"** code snippet (`add-wordpress-roles-based-on-membershipworks.code-snippets.php`) with a proper plugin. It keeps the exact same MembershipWorks membership check (via `sf_shortcode()` from the `memberfindme` plugin) and the same two WordPress roles it assigns — `member` and `member-company` — but changes how throttling, diagnostics, and admin visibility work.

### What changed vs. the code snippet

| | Old snippet | This plugin |
|---|---|---|
| Throttle storage | `user_meta` (`sawe_check_date`) | Dedicated DB table, `{$wpdb->prefix}sawe_mwr_check_log` |
| Throttle granularity | Once per day for everyone | Once per day for members, **every 5 minutes minimum for non-members** |
| Diagnostics | `error_log()` / `write_log()` only, on missing plugin only | Every check (success or failure) logged with the raw MembershipWorks response and a human-readable error message |
| Admin visibility | None | "MembershipWorks Sync Log" screen under **SAWE Coupons and Credits**, searchable/sortable/filterable |
| Error handling | Ambiguous/error responses caused roles to be silently removed | Ambiguous/error responses leave roles untouched and are flagged for review |

## Installation

1. Upload the `sawe-membershipworks-roles` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**. This creates the `sawe_mwr_check_log` table.
3. Make sure the MembershipWorks (`memberfindme`) plugin is installed, active, and configured with an Organization ID.
4. Visit **SAWE Coupons and Credits → MembershipWorks Sync Log** to confirm checks are being logged as members log in / browse the site.

If the SAWE Membership Store Credits plugin (which owns the "SAWE Coupons and Credits" top-level menu) is not installed, this plugin registers its own top-level menu of the same name so the log screen is still reachable.

## How It Works

### Trigger points

A check is attempted (subject to throttling — see below) on:

- `wp_login` and `profile_update` (matches the original snippet)
- `woocommerce_before_cart`, `woocommerce_before_checkout_form`, `woocommerce_before_account_navigation` (matches the original snippet)
- Every front-end page load for a logged-in user (`wp` hook), so the 5-minute non-member throttle actually gets exercised as new page views happen, not just on the specific WooCommerce pages above

Administrators are always skipped, exactly as in the original snippet.

### Throttling

Before calling the MembershipWorks API, the plugin looks up the user's existing row in `sawe_mwr_check_log`:

- If the row's `is_member` flag is true (from the last successful check), the user is re-checked at most **once every 24 hours**.
- Otherwise (never checked, or currently not a member, or last check ended in an error), the user is re-checked at most **once every 5 minutes**.

No MembershipWorks API call is made when the relevant interval hasn't elapsed — the check is a single indexed `SELECT` against the log table.

### Interpreting the MembershipWorks response

The plugin calls `sf_shortcode()` with the same two shortcode strings as the original snippet:

```
[memberonly message="no" nologin]yes[/memberonly]
[memberonly label="Corporate Member" message="no" nologin]yes[/memberonly]
```

A matching member gets back the literal string `yes`; a non-member gets back the literal string `no`. Anything else (e.g. `Organization ID not setup. Please update settings.`, or a MembershipWorks connectivity error) is treated as **an error**, not as "not a member" — in that case roles are left untouched and the row is logged with `status = error` so an admin can investigate. This is a deliberate improvement over the original snippet, which would silently strip a member's role on any non-`yes` response, including a transient API failure.

## Diagnostic Log Table

Table: `{$wpdb->prefix}sawe_mwr_check_log` — one row per WordPress user, upserted on every check.

| Column | Description |
|---|---|
| `user_id` | WordPress user ID (unique) |
| `user_login` | Username snapshot as of the last check |
| `display_name` | Display name snapshot as of the last check |
| `is_member` / `is_corporate` | Last known role flags |
| `status` | `ok` or `error` |
| `api_response` | JSON of the raw `member`/`corporate` shortcode responses |
| `error_message` | Diagnostic text when `status = error` (e.g. missing plugin function, unexpected API response) |
| `last_checked_at` | Datetime of the last check — this is the throttle clock |
| `created_at` | Datetime the row was first created |

## Admin Screen

**SAWE Coupons and Credits → MembershipWorks Sync Log**

- Search box matches username, display name, and error message.
- "All / OK / Errors" status views above the table.
- "Error message" dropdown filter for narrowing to one exact diagnostic message.
- Sortable columns: Username, Display Name, Member, Status, Last Checked.
- Username links directly to that user's WordPress profile edit screen.
- Raw MembershipWorks response viewable per-row via an expandable "View" control.
- A **Settings** section to opt in to removing the log table when the plugin is deleted (off by default — history is preserved through plugin deletion unless you check this box).

## File Structure

```
sawe-membershipworks-roles/
├── sawe-membershipworks-roles.php     Main plugin file
├── uninstall.php                       Cleanup on delete (opt-in)
├── includes/
│   ├── class-sawe-mwr-db.php           Table creation + CRUD/query layer
│   └── class-sawe-mwr-role-sync.php    Membership check, throttling, role assignment, logging
├── admin/
│   ├── class-sawe-mwr-admin.php        Admin menu + settings
│   └── class-sawe-mwr-list-table.php   WP_List_Table for the diagnostics screen
└── docs/
    ├── CHANGELOG.md                    Full version history for this plugin
    └── DEVELOPER-GUIDE.md              Architecture, schema, and hook reference
```

## Uninstall

By default, deleting the plugin **keeps** the `sawe_mwr_check_log` table and its history. To remove it, check **"Remove database table on uninstall"** under Settings on the MembershipWorks Sync Log screen before deleting the plugin.

## Independence from SAWE Membership Store Credits

This plugin has no hard dependency on SAWE Membership Store Credits or WooCommerce:

- The DB layer and role-sync logic (`includes/`) load unconditionally and work with only this plugin active.
- The WooCommerce trigger hooks (`woocommerce_before_cart`, etc.) are plain `add_action()` registrations — if WooCommerce isn't active, they simply never fire; nothing errors.
- The admin menu registration checks whether `SAWE_MSC_Admin` (from the Store Credits plugin) is loaded. If it is, "MembershipWorks Sync Log" nests under the existing "SAWE Coupons and Credits" menu. If it isn't, this plugin registers its own top-level "SAWE Coupons and Credits" menu so the log screen is still reachable.

You can activate, deactivate, or delete either plugin independently of the other.

## Versioning & Releases

Starting at v1.2.0, this plugin is released from the same GitHub repository and under the same git tag as SAWE Membership Store Credits, so both always report a matching version number and there is one changelog per plugin plus one shared release. Each tagged release (`vX.Y.Z`) attaches **two** separate, independently installable zip assets to the same GitHub Release:

- `sawe-membership-store-credits-{version}.zip`
- `sawe-membershipworks-roles-{version}.zip`

See [docs/CHANGELOG.md](docs/CHANGELOG.md) for this plugin's own change history, and [docs/DEVELOPER-GUIDE.md](docs/DEVELOPER-GUIDE.md) for the full architecture reference.
