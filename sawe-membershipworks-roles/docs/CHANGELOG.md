# Changelog — SAWE MembershipWorks Role Sync

All notable changes to this plugin are documented here.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
This project uses [Semantic Versioning](https://semver.org/).

This plugin is released from the same repository and under the same git tag as [SAWE Membership Store Credits](../../docs/CHANGELOG.md), but is versioned here independently in terms of *content* — an entry only appears below when this plugin itself changed.

---

## [1.2.2] — 2026-08-05

### Added
- Member and non-member MembershipWorks check intervals are now admin-configurable (value + minutes/hours unit) from the "Settings" section of the "MembershipWorks Sync Log" screen, instead of requiring a code change to the `MEMBER_CHECK_INTERVAL`/`NONMEMBER_CHECK_INTERVAL` constants. Defaults remain 24 hours / 5 minutes.

---

## [1.2.1] — 2026-08-05

### Added
- "MembershipWorks Sync Log" admin screen: log rows can now be deleted, individually (row "Delete" action) or in bulk (checkbox + bulk action). Deleting a row clears the throttle/diagnostic record for that user, so `SAWE_MWR_Role_Sync` treats them as never checked and re-evaluates their MembershipWorks status on their next login or page load.

---

## [1.2.0] — 2026-08-05

### Added
- Initial public release under the shared-repo, shared-tag versioning scheme (jumped straight to 1.2.0 to match the paired SAWE Membership Store Credits release rather than starting a separate 1.0.x line).
- `docs/CHANGELOG.md` and `docs/DEVELOPER-GUIDE.md` added, mirroring the documentation conventions already used by SAWE Membership Store Credits, so future changes to this plugin have a clear place to be recorded.
- README expanded with "Independence from SAWE Membership Store Credits" and "Versioning & Releases" sections.

---

## [1.0.0] — 2026-08-05

### Added
- Initial release. Replaces the "Add WordPress Roles based on MembershipWorks" code snippet.
- `sawe_mwr_check_log` database table — one row per user, storing user ID, username, display name, last-checked timestamp, last-known membership/corporate flags, status, raw MembershipWorks API response, and error message.
- `SAWE_MWR_Role_Sync` — throttled membership checking (once/day for members, every 5 minutes minimum for non-members), role assignment (`member`, `member-company`), and diagnostic logging on every check.
- `SAWE_MWR_Admin` + `SAWE_MWR_List_Table` — "MembershipWorks Sync Log" admin screen nested under "SAWE Coupons and Credits", with search, status views (All/OK/Errors), an exact-match error message filter, sortable columns, and a linked username column.
- Ambiguous/ unexpected MembershipWorks responses (missing plugin function, configuration errors, connectivity errors) are logged as `status = error` and leave existing roles untouched, rather than silently removing a member's role as the original snippet did.
- Optional "Remove database table on uninstall" setting (off by default).
