<?php
/**
 * User Credit Management
 *
 * This class is the bridge between the store credit *definitions* (CPT) and the
 * per-user *balances* (DB table). It answers the question: "Which credits should
 * this user have, and are their balances current?"
 *
 * ============================================================================
 * RESPONSIBILITIES
 * ============================================================================
 *
 * 1. SYNC (award / revoke):
 *    When a logged-in user visits the store or logs in, sync_user() is called.
 *    It checks every published credit definition and either:
 *      a. Awards the credit (inserts a DB row) if the user holds a required role
 *         and has never received the credit before.
 *      b. Zeros the balance if the user no longer holds any required role.
 *
 * 2. RENEWAL (cron):
 *    A daily WP-Cron job fires at midnight. On the configured renewal date
 *    (Month/Day), process_renewals() resets every eligible user's balance to
 *    the credit's initial_amount.
 *
 * 3. HELPERS (used by Cart, Checkout, Account):
 *    get_active_credits_for_user() returns an enriched array suitable for
 *    display and discount calculation.
 *    product_qualifies() checks whether a given product ID is in the
 *    qualifying products or qualifying categories for a credit definition.
 *
 * ============================================================================
 * SYNC TRIGGERS
 * ============================================================================
 *
 * Sync is triggered on these WooCommerce/WP hooks (all call sync_current_user):
 *   - woocommerce_before_shop_loop       (shop/archive page)
 *   - woocommerce_before_single_product  (single product page)
 *   - woocommerce_before_cart            (cart page)
 *   - woocommerce_before_checkout_form   (checkout page)
 *   - wp_login                           (login event)
 *
 * This ensures credits are always current before any purchase interaction,
 * without the overhead of checking on every single WordPress request.
 *
 * @package SAWE_MSC
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class SAWE_MSC_User_Credits {

    /**
     * Singleton instance.
     *
     * @var SAWE_MSC_User_Credits|null
     */
    private static ?self $instance = null;

    // =========================================================================
    // Singleton
    // =========================================================================

    /**
     * Return the singleton instance, creating it on first call.
     *
     * @return self
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor — registers all hooks and schedules cron.
     */
    private function __construct() {
        // ── Sync triggers (front-end WooCommerce pages) ──────────────────────
        // These all call sync_current_user(), which bails if no one is logged in.
        add_action( 'woocommerce_before_shop_loop',      [ $this, 'sync_current_user' ] );
        add_action( 'woocommerce_before_single_product', [ $this, 'sync_current_user' ] );
        add_action( 'woocommerce_before_cart',           [ $this, 'sync_current_user' ] );
        add_action( 'woocommerce_before_checkout_form',  [ $this, 'sync_current_user' ] );

        // ── Sync on login ────────────────────────────────────────────────────
        // wp_login fires after authentication is confirmed. Priority 10 (default)
        // ensures WP has fully loaded the user's role data before we check it.
        add_action( 'wp_login', [ $this, 'sync_user_on_login' ], 10, 2 );

        // ── Daily renewal cron ───────────────────────────────────────────────
        // Register the callback for the custom cron event.
        add_action( 'sawe_msc_daily_renewal', [ $this, 'process_renewals' ] );

        // Schedule the event if it hasn't been scheduled yet.
        // 'tomorrow midnight' = start of next day in the server's timezone.
        // The event then recurs daily at that time.
        // NOTE: If you need a specific timezone for renewals, consider using
        // wp_schedule_event with a custom recurrence and an absolute timestamp.
        if ( ! wp_next_scheduled( 'sawe_msc_daily_renewal' ) ) {
            wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', 'sawe_msc_daily_renewal' );
        }
    }

    // =========================================================================
    // Sync triggers
    // =========================================================================

    /**
     * Hook callback: sync the currently logged-in user.
     *
     * Bails silently if no user is logged in (e.g. a guest browsing the shop).
     *
     * Hook: woocommerce_before_shop_loop, woocommerce_before_single_product,
     *       woocommerce_before_cart, woocommerce_before_checkout_form
     *
     * @return void
     */
    public function sync_current_user(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }
        $this->sync_user( get_current_user_id() );
    }

    /**
     * Hook callback: sync a user who just logged in.
     *
     * Called at priority 10 by wp_login, which passes the username string
     * and the fully-loaded WP_User object.
     *
     * Hook: wp_login (priority 10)
     *
     * @param string   $user_login  The user's login name (unused — we use the object).
     * @param \WP_User $user        The user object with roles populated.
     *
     * @return void
     */
    public function sync_user_on_login( string $user_login, \WP_User $user ): void {
        $this->sync_user( $user->ID );
    }

    // =========================================================================
    // Core sync logic
    // =========================================================================

    /**
     * Award or revoke credits for a specific user based on their current roles.
     *
     * This is the central business logic for credit eligibility. It runs
     * against ALL published credit definitions on every call.
     *
     * For each credit definition:
     *   - If user HAS a required role AND has no DB row → award_credit()
     *   - If user HAS a required role AND already has a row → do nothing
     *     (balance is managed by cart/checkout, not here)
     *   - If user LACKS all required roles AND has a positive balance → remove_credit()
     *
     * This method is intentionally idempotent — calling it multiple times
     * for the same user in the same request is safe (though slightly wasteful).
     * Production code should avoid doing so.
     *
     * @param int $user_id  WordPress user ID to sync.
     *
     * @return void
     */
    public function sync_user( int $user_id ): void {
        $credits = SAWE_MSC_Credit_Post_Type::get_published_credits();
        if ( empty( $credits ) ) {
            return; // Nothing to sync — no published credit definitions exist.
        }

        $user       = get_userdata( $user_id );
        $user_roles = $user ? (array) $user->roles : [];

        foreach ( $credits as $credit_post ) {
            $meta           = SAWE_MSC_Credit_Post_Type::get_credit_meta( $credit_post->ID );
            $required_roles = (array) $meta['roles'];

            // array_intersect returns non-empty if user holds at least one required role.
            $has_role = ! empty( array_intersect( $user_roles, $required_roles ) );

            $row = SAWE_MSC_DB::get_user_credit( $credit_post->ID, $user_id );

            if ( $has_role ) {
                if ( ! $row ) {
                    // User is newly eligible. Create their balance row.
                    SAWE_MSC_DB::award_credit( $credit_post->ID, $user_id, (float) $meta['initial_amount'] );
                }
                // If a row exists, leave it alone — their ongoing balance is correct.
            } else {
                if ( $row && (float) $row->balance > 0 ) {
                    // User was eligible before but isn't anymore. Zero out their balance.
                    SAWE_MSC_DB::remove_credit( $credit_post->ID, $user_id );
                }
            }
        }
    }

    // =========================================================================
    // Daily renewal cron
    // =========================================================================

    /**
     * Process annual renewals for all published credit definitions.
     *
     * Runs daily via the WP-Cron 'sawe_msc_daily_renewal' event. On any given
     * day, it checks every credit to see if today is its renewal day. If so,
     * it iterates every user who has ever been awarded that credit and resets
     * their balance to the initial_amount — provided they still hold a required
     * role.
     *
     * Users who have lost the required role are NOT renewed (their balance stays
     * at zero).
     *
     * NOTE: This runs at server midnight. If your membership site spans timezones
     * and you need renewals to fire at a specific local time, replace the cron
     * schedule in __construct() with a calculated UTC timestamp.
     *
     * Hook: sawe_msc_daily_renewal (WP-Cron)
     *
     * @return void
     */
    public function process_renewals(): void {
        $credits = SAWE_MSC_Credit_Post_Type::get_published_credits();
        $now     = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

        foreach ( $credits as $credit_post ) {
            $meta  = SAWE_MSC_Credit_Post_Type::get_credit_meta( $credit_post->ID );
            $month = $meta['expiry_month'];
            $day   = $meta['expiry_day'];

            // Only process credits whose renewal day matches today.
            if ( (int) $now->format( 'n' ) !== $month || (int) $now->format( 'j' ) !== $day ) {
                continue;
            }

            // Renewal day! Reset balances for all eligible users of this credit.
            $rows = SAWE_MSC_DB::get_all_rows_for_credit( $credit_post->ID );

            foreach ( $rows as $row ) {
                $user       = get_userdata( (int) $row->user_id );
                $user_roles = $user ? (array) $user->roles : [];
                $has_role   = ! empty( array_intersect( $user_roles, (array) $meta['roles'] ) );

                if ( $has_role ) {
                    // award_credit() with an existing row resets balance to initial_amount
                    // and records the renewed_at timestamp. See SAWE_MSC_DB::award_credit().
                    SAWE_MSC_DB::award_credit(
                        $credit_post->ID,
                        (int) $row->user_id,
                        (float) $meta['initial_amount']
                    );
                }
                // Users without the role are left as-is (balance already 0 from sync_user).
            }
        }
    }

    // =========================================================================
    // Public helpers — used by Cart, Checkout, Account
    // =========================================================================

    /**
     * Return an enriched array of credit data for a given user.
     *
     * Combines the DB balance row, the CPT post object, the typed meta array,
     * and the calculated next renewal date into a single, convenient structure
     * that all display and calculation code can consume.
     *
     * Filtering: includes ALL rows from the DB (even balance = 0) as long as the
     * associated credit post is published. This allows the Account page to show
     * spent credits. Cart code checks balance > 0 itself before injecting a fee.
     *
     * Return shape per entry:
     * [
     *   'row'     => object,    — Raw DB row (stdClass) from sawe_msc_user_credits.
     *   'post'    => WP_Post,   — The sawe_store_credit post object.
     *   'meta'    => array,     — Output of get_credit_meta() (typed, decoded).
     *   'balance' => float,     — Current balance in dollars (from row->balance).
     *   'renewal' => DateTime,  — Next renewal date (UTC DateTime object).
     * ]
     *
     * @param int $user_id  WordPress user ID.
     *
     * @return array[]  Array of enriched credit arrays. Empty if user has no credits.
     */
    public static function get_active_credits_for_user( int $user_id ): array {
        $rows   = SAWE_MSC_DB::get_credits_for_user( $user_id );
        $result = [];

        foreach ( $rows as $row ) {
            // Skip credits whose post has been trashed, moved to draft, etc.
            $post = get_post( (int) $row->credit_post_id );
            if ( ! $post || $post->post_status !== 'publish' ) {
                continue;
            }

            $meta     = SAWE_MSC_Credit_Post_Type::get_credit_meta( (int) $row->credit_post_id );
            $result[] = [
                'row'     => $row,
                'post'    => $post,
                'meta'    => $meta,
                'balance' => (float) $row->balance,
                'renewal' => SAWE_MSC_Credit_Post_Type::get_next_renewal_date( (int) $row->credit_post_id ),
            ];
        }

        return $result;
    }

    /**
     * Check whether a given product qualifies for a specific credit's discount.
     *
     * Qualification rules (either condition is sufficient):
     *   1. The product's ID appears in $meta['products'] (direct product match).
     *   2. The product belongs to a product_cat term listed in $meta['product_categories'].
     *
     * For variable products, pass the variation ID (not the parent) so that
     * category lookup works correctly via wc_get_product_term_ids().
     *
     * @param int   $product_id  The WooCommerce product or variation post ID.
     * @param array $meta        Output of SAWE_MSC_Credit_Post_Type::get_credit_meta().
     *
     * @return bool  True if the product qualifies, false otherwise.
     */
    public static function product_qualifies( int $product_id, array $meta ): bool {
        // Check 1: is this exact product ID in the qualifying products list?
        if ( ! empty( $meta['products'] ) &&
             in_array( $product_id, array_map( 'intval', $meta['products'] ), true ) ) {
            return true;
        }

        // Check 2: does the product belong to any qualifying category?
        if ( ! empty( $meta['product_categories'] ) ) {
            // wc_get_product_term_ids returns all term IDs for a given taxonomy.
            $terms = wc_get_product_term_ids( $product_id, 'product_cat' );
            if ( ! empty( array_intersect( array_map( 'intval', $meta['product_categories'] ), $terms ) ) ) {
                return true;
            }
        }

        return false;
    }
}
