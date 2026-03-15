<?php
/**
 * Database layer for SAWE Membership Store Credits.
 *
 * This is a pure-static helper class. It has no constructor and holds no state.
 * Any other class in the plugin calls it directly without needing an instance.
 *
 * ============================================================================
 * TABLE: {prefix}sawe_msc_user_credits
 * ============================================================================
 *
 * Stores ONE ROW per (credit definition post, user) pair. The balance column
 * is decremented when an order is placed and restored on cancellation/refund.
 *
 * Columns:
 *   id              BIGINT UNSIGNED PK AI  — Internal row identifier.
 *   credit_post_id  BIGINT UNSIGNED        — FK → wp_posts.ID where post_type='sawe_store_credit'.
 *   user_id         BIGINT UNSIGNED        — FK → wp_users.ID.
 *   balance         DECIMAL(10,2)          — Current remaining credit in dollars.
 *   initial_amount  DECIMAL(10,2)          — Amount at last award or renewal (used to cap restores).
 *   awarded_at      DATETIME UTC           — When the row was first created.
 *   renewed_at      DATETIME UTC NULL      — When balance was last reset by the renewal cron.
 *   last_updated    DATETIME UTC           — Auto-updated on every write (ON UPDATE CURRENT_TIMESTAMP).
 *
 * Indexes:
 *   PRIMARY KEY  (id)
 *   UNIQUE KEY   credit_user (credit_post_id, user_id)  — Enforces one row per pair.
 *   KEY          user_id (user_id)
 *   KEY          credit_post_id (credit_post_id)
 *
 * ============================================================================
 * HOW TO EXTEND THE SCHEMA
 * ============================================================================
 *
 * 1. Add new columns to the $sql string inside create_tables().
 * 2. Bump DB_VERSION constant to a new semver string.
 * 3. Add a migration check in the plugin bootstrap:
 *      if ( version_compare( get_option( SAWE_MSC_DB::DB_VERSION_KEY ), SAWE_MSC_DB::DB_VERSION, '<' ) ) {
 *          SAWE_MSC_DB::create_tables(); // dbDelta handles ALTER TABLE for new columns.
 *      }
 *
 * @package SAWE_MSC
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class SAWE_MSC_DB {

    // =========================================================================
    // Constants
    // =========================================================================

    /**
     * Schema version string.
     *
     * Bump this whenever the table structure changes. The bootstrap should
     * compare this against the stored option DB_VERSION_KEY and call
     * create_tables() if they differ.
     *
     * @var string
     */
    const DB_VERSION = '1.0.0';

    /**
     * WordPress option key used to store the installed schema version.
     *
     * Used for upgrade comparisons:
     *   get_option( SAWE_MSC_DB::DB_VERSION_KEY ) !== SAWE_MSC_DB::DB_VERSION
     *
     * @var string
     */
    const DB_VERSION_KEY = 'sawe_msc_db_version';

    // =========================================================================
    // Table name helpers
    // =========================================================================

    /**
     * Returns the fully-qualified user credits table name, including the WP prefix.
     *
     * Always use this method rather than hard-coding the table name so that
     * multi-site installs and custom prefixes are handled correctly.
     *
     * Example return value: 'wp_sawe_msc_user_credits'
     *
     * @return string
     */
    public static function user_credits_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sawe_msc_user_credits';
    }

    // =========================================================================
    // Schema management
    // =========================================================================

    /**
     * Create (or upgrade) the plugin's custom database table.
     *
     * Uses WordPress's dbDelta() function, which is safe to call repeatedly:
     *   - If the table does not exist, it creates it.
     *   - If the table exists with missing columns, it adds them.
     *   - It will NOT remove columns or change existing column types.
     *
     * Called on plugin activation (register_activation_hook) and should also
     * be called when DB_VERSION is bumped to handle upgrades.
     *
     * @return void
     */
    public static function create_tables(): void {
        global $wpdb;

        // wp-admin/includes/upgrade.php provides dbDelta().
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table           = self::user_credits_table();

        // IMPORTANT: dbDelta() is sensitive to SQL formatting:
        //   - Two spaces between column name and data type.
        //   - INDEX definitions on their own lines.
        //   - No trailing commas before closing parenthesis.
        $sql = "CREATE TABLE {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            credit_post_id  BIGINT(20) UNSIGNED NOT NULL COMMENT 'post ID of the sawe_store_credit CPT',
            user_id         BIGINT(20) UNSIGNED NOT NULL,
            balance         DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
            initial_amount  DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
            awarded_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            renewed_at      DATETIME                     DEFAULT NULL,
            last_updated    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY  credit_user (credit_post_id, user_id),
            KEY         user_id (user_id),
            KEY         credit_post_id (credit_post_id)
        ) {$charset_collate};";

        dbDelta( $sql );

        // Store the installed version so future upgrades can compare against it.
        update_option( self::DB_VERSION_KEY, self::DB_VERSION );
    }

    /**
     * Drop the plugin's custom table.
     *
     * Called from uninstall.php ONLY when the admin has opted in via the
     * 'sawe_msc_remove_tables_on_uninstall' setting. This operation is
     * irreversible — all user balance history will be permanently deleted.
     *
     * @return void
     */
    public static function drop_tables(): void {
        global $wpdb;
        $table = self::user_credits_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Reason: table name is constructed from $wpdb->prefix (safe) + a hardcoded string.
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        delete_option( self::DB_VERSION_KEY );
    }

    // =========================================================================
    // CRUD helpers
    // =========================================================================

    /**
     * Fetch a single user-credit row by credit definition and user ID.
     *
     * Returns null if no row exists (i.e. the credit has never been awarded
     * to this user). A row with balance = 0 is still returned — it means the
     * credit was awarded at some point but has been spent or revoked.
     *
     * @param int $credit_post_id  Post ID of the sawe_store_credit CPT definition.
     * @param int $user_id         WordPress user ID.
     *
     * @return object|null  Database row as a stdClass, or null if not found.
     */
    public static function get_user_credit( int $credit_post_id, int $user_id ): ?object {
        global $wpdb;
        $table = self::user_credits_table();
        return $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table} WHERE credit_post_id = %d AND user_id = %d",
                $credit_post_id,
                $user_id
            )
        );
    }

    /**
     * Get ALL credit rows for a given user, across all credit definitions.
     *
     * Used by SAWE_MSC_User_Credits::get_active_credits_for_user() to build
     * the enriched display array for cart, checkout, and account pages.
     *
     * @param int $user_id  WordPress user ID.
     *
     * @return object[]  Array of stdClass rows (empty array if none).
     */
    public static function get_credits_for_user( int $user_id ): array {
        global $wpdb;
        $table = self::user_credits_table();
        return $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table} WHERE user_id = %d",
                $user_id
            )
        ) ?: [];
    }

    /**
     * Award a store credit to a user, or reset the balance on renewal.
     *
     * Behaviour:
     *   - If no row exists: INSERT a new row with balance = initial_amount = $amount.
     *   - If a row already exists: UPDATE balance and initial_amount to $amount,
     *     and set renewed_at to now. This covers the annual renewal case.
     *
     * After calling this, the user's balance equals exactly $amount regardless
     * of what it was before.
     *
     * @param int   $credit_post_id  Post ID of the sawe_store_credit definition.
     * @param int   $user_id         WordPress user ID.
     * @param float $amount          Dollar amount to set as the new balance.
     *
     * @return bool  True on success, false on DB error.
     */
    public static function award_credit( int $credit_post_id, int $user_id, float $amount ): bool {
        global $wpdb;
        $table    = self::user_credits_table();
        $existing = self::get_user_credit( $credit_post_id, $user_id );

        if ( $existing ) {
            // Row exists — this is a renewal. Reset balance and record the renewal timestamp.
            return (bool) $wpdb->update(
                $table,
                [
                    'balance'        => $amount,
                    'initial_amount' => $amount,
                    'renewed_at'     => current_time( 'mysql', true ), // UTC
                ],
                [ 'id' => $existing->id ],
                [ '%f', '%f', '%s' ],
                [ '%d' ]
            );
        }

        // No row yet — first time this credit is being awarded to this user.
        return (bool) $wpdb->insert(
            $table,
            [
                'credit_post_id' => $credit_post_id,
                'user_id'        => $user_id,
                'balance'        => $amount,
                'initial_amount' => $amount,
                'awarded_at'     => current_time( 'mysql', true ), // UTC
            ],
            [ '%d', '%d', '%f', '%f', '%s' ]
        );
    }

    /**
     * Deduct an amount from a user's credit balance.
     *
     * The resulting balance is clamped to zero — it will never go negative.
     * Called by SAWE_MSC_Checkout::finalise_deductions() when an order is placed.
     *
     * @param int   $credit_post_id  Post ID of the sawe_store_credit definition.
     * @param int   $user_id         WordPress user ID.
     * @param float $amount          Dollar amount to subtract from the balance.
     *
     * @return bool  True on success, false if the row doesn't exist or on DB error.
     */
    public static function deduct_credit( int $credit_post_id, int $user_id, float $amount ): bool {
        global $wpdb;
        $table    = self::user_credits_table();
        $existing = self::get_user_credit( $credit_post_id, $user_id );

        if ( ! $existing ) {
            return false; // Nothing to deduct from.
        }

        // Clamp: balance cannot go below zero.
        $new_balance = max( 0.0, (float) $existing->balance - $amount );

        return (bool) $wpdb->update(
            $table,
            [ 'balance' => $new_balance ],
            [ 'id'      => $existing->id ],
            [ '%f' ],
            [ '%d' ]
        );
    }

    /**
     * Restore an amount to a user's credit balance (e.g. on order cancellation).
     *
     * The resulting balance is capped at initial_amount — it will never exceed
     * the value set when the credit was last awarded or renewed.
     *
     * Called by SAWE_MSC_Checkout::restore_on_cancel().
     *
     * @param int   $credit_post_id  Post ID of the sawe_store_credit definition.
     * @param int   $user_id         WordPress user ID.
     * @param float $amount          Dollar amount to add back to the balance.
     *
     * @return bool  True on success, false if row doesn't exist or on DB error.
     */
    public static function restore_credit( int $credit_post_id, int $user_id, float $amount ): bool {
        global $wpdb;
        $table    = self::user_credits_table();
        $existing = self::get_user_credit( $credit_post_id, $user_id );

        if ( ! $existing ) {
            return false;
        }

        // Cap: balance cannot exceed what was originally awarded/renewed.
        $new_balance = min( (float) $existing->initial_amount, (float) $existing->balance + $amount );

        return (bool) $wpdb->update(
            $table,
            [ 'balance' => $new_balance ],
            [ 'id'      => $existing->id ],
            [ '%f' ],
            [ '%d' ]
        );
    }

    /**
     * Zero out a user's credit balance (called when the user loses the required role).
     *
     * This does NOT delete the row — it sets balance to 0.00. The row is kept
     * so the credit can be re-awarded (via award_credit) if the user regains
     * the role later.
     *
     * Called by SAWE_MSC_User_Credits::sync_user() when role check fails.
     *
     * @param int $credit_post_id  Post ID of the sawe_store_credit definition.
     * @param int $user_id         WordPress user ID.
     *
     * @return bool  True on success, false on DB error.
     */
    public static function remove_credit( int $credit_post_id, int $user_id ): bool {
        global $wpdb;
        $table = self::user_credits_table();

        return (bool) $wpdb->update(
            $table,
            [ 'balance' => 0.00 ],
            [ 'credit_post_id' => $credit_post_id, 'user_id' => $user_id ],
            [ '%f' ],
            [ '%d', '%d' ]
        );
    }

    /**
     * Get all user rows for a specific credit definition.
     *
     * Used by the daily renewal cron (SAWE_MSC_User_Credits::process_renewals())
     * to iterate every user who has ever been awarded a given credit and check
     * whether they still hold an eligible role.
     *
     * Also useful for admin reporting: see how many users hold a credit and
     * what their current balances are.
     *
     * @param int $credit_post_id  Post ID of the sawe_store_credit definition.
     *
     * @return object[]  Array of stdClass rows ordered by user_id ASC (empty if none).
     */
    public static function get_all_rows_for_credit( int $credit_post_id ): array {
        global $wpdb;
        $table = self::user_credits_table();
        return $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table} WHERE credit_post_id = %d ORDER BY user_id ASC",
                $credit_post_id
            )
        ) ?: [];
    }
}
