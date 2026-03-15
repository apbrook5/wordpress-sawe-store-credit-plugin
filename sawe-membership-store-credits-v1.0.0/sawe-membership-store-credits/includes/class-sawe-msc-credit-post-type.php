<?php
/**
 * Custom Post Type: sawe_store_credit
 *
 * This class handles everything related to the store credit *definition* —
 * the admin-configured template that says "members with role X get $Y credit
 * for product categories A, B, C, renewing on date D."
 *
 * It does NOT manage per-user balances. For balances, see SAWE_MSC_DB and
 * SAWE_MSC_User_Credits.
 *
 * ============================================================================
 * POST TYPE: sawe_store_credit
 * ============================================================================
 *
 * Visibility: non-public (no front-end URL, not in search results).
 * Admin menu: appears under the "Store Credits" top-level menu.
 * Supports:   title (credit name), editor (member-facing description),
 *             revisions, custom-fields (for REST access to meta).
 *
 * Post status drives activation:
 *   publish  → Credit is active. Users with matching roles receive it.
 *   draft    → Credit is inactive. No awards or cron renewals.
 *   (any other status, including trash) → Treated as inactive.
 *
 * ============================================================================
 * POST META KEYS (all prefixed _sawe_msc_)
 * ============================================================================
 *
 * Key                         Type    Stored   Description
 * --------------------------  ------  -------  ---------------------------------
 * _sawe_msc_admin_notes       string  text     Internal notes, never shown to members.
 * _sawe_msc_initial_amount    float   numeric  Dollar value awarded/renewed each cycle.
 * _sawe_msc_expiry_month      int     1–12     Month component of annual renewal date.
 * _sawe_msc_expiry_day        int     1–31     Day component of annual renewal date.
 * _sawe_msc_roles             string  JSON     WP role slugs whose users get this credit.
 * _sawe_msc_product_categories string JSON    product_cat term IDs that qualify.
 * _sawe_msc_products          string  JSON     Individual product post IDs that qualify.
 *
 * Use get_credit_meta( $post_id ) to retrieve all meta as a typed PHP array.
 *
 * @package SAWE_MSC
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class SAWE_MSC_Credit_Post_Type {

    /**
     * Singleton instance.
     *
     * @var SAWE_MSC_Credit_Post_Type|null
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
     * Private constructor — registers init hooks.
     */
    private function __construct() {
        // Both callbacks run on 'init' (default priority 10).
        add_action( 'init', [ $this, 'register_post_type' ] );
        add_action( 'init', [ $this, 'register_meta' ] );
    }

    // =========================================================================
    // Post type registration
    // =========================================================================

    /**
     * Register the sawe_store_credit custom post type.
     *
     * Key decisions:
     *   - public => false: No front-end URL. Credits are admin-only objects.
     *   - show_in_rest => true: Enables Gutenberg editor and REST API access.
     *   - show_in_menu => 'sawe-msc-settings': Attaches to our custom admin menu
     *     rather than the default "Posts" menu, so all credit admin is in one place.
     *   - supports 'editor': The post body is used as the member-facing description.
     *   - supports 'revisions': Allows admins to roll back accidental changes.
     *
     * Hook: init
     *
     * @return void
     */
    public function register_post_type(): void {
        register_post_type( 'sawe_store_credit', [
            'label'               => __( 'Store Credits', 'sawe-msc' ),
            'labels'              => [
                'name'               => __( 'Store Credits',           'sawe-msc' ),
                'singular_name'      => __( 'Store Credit',            'sawe-msc' ),
                'add_new'            => __( 'Add New',                 'sawe-msc' ),
                'add_new_item'       => __( 'Add New Store Credit',    'sawe-msc' ),
                'edit_item'          => __( 'Edit Store Credit',       'sawe-msc' ),
                'new_item'           => __( 'New Store Credit',        'sawe-msc' ),
                'view_item'          => __( 'View Store Credit',       'sawe-msc' ),
                'search_items'       => __( 'Search Store Credits',    'sawe-msc' ),
                'not_found'          => __( 'No store credits found.', 'sawe-msc' ),
                'not_found_in_trash' => __( 'No store credits in trash.', 'sawe-msc' ),
            ],
            'public'          => false,  // No front-end URL or search inclusion.
            'show_ui'         => true,   // Show in WP admin.
            'show_in_menu'    => 'sawe-msc-settings', // Parent menu slug from SAWE_MSC_Admin.
            'show_in_rest'    => true,   // Required for Gutenberg + REST API.
            'supports'        => [ 'title', 'editor', 'revisions', 'custom-fields' ],
            'capability_type' => 'post', // Uses standard post capabilities (edit_post, etc.).
            'map_meta_cap'    => true,
            'rewrite'         => false,  // No front-end URL, so no rewrite rules needed.
            'query_var'       => false,
            'has_archive'     => false,
        ] );
    }

    // =========================================================================
    // Post meta registration
    // =========================================================================

    /**
     * Register all sawe_store_credit post meta keys.
     *
     * Registering meta explicitly:
     *   - Exposes fields via the REST API (required for Gutenberg block editor).
     *   - Provides sanitisation type hints.
     *   - Documents the data contract for all developers.
     *   - Enables the auth_callback security check.
     *
     * All fields are registered with show_in_rest => true. This means they
     * appear in GET /wp-json/wp/v2/sawe_store_credit/<id> and can be written
     * via the REST API by users with edit_posts capability.
     *
     * Hook: init
     *
     * @return void
     */
    public function register_meta(): void {
        // Shared args applied to every meta registration below.
        $base_args = [
            'object_subtype' => 'sawe_store_credit',
            'single'         => true,   // Only one value per post (not an array of values).
            'show_in_rest'   => true,   // Expose via REST API / Gutenberg.
            // Auth callback: only users who can edit posts can read/write meta via REST.
            'auth_callback'  => fn() => current_user_can( 'edit_posts' ),
        ];

        // Admin-only notes. Never shown to members.
        register_post_meta( 'sawe_store_credit', '_sawe_msc_admin_notes', array_merge( $base_args, [
            'type'        => 'string',
            'description' => 'Admin notes (not shown to users)',
            'default'     => '',
        ] ) );

        // Dollar amount awarded at creation and on each annual renewal.
        register_post_meta( 'sawe_store_credit', '_sawe_msc_initial_amount', array_merge( $base_args, [
            'type'        => 'number',
            'description' => 'Initial credit amount in dollars',
            'default'     => 0,
        ] ) );

        // Month number (1–12) for the annual renewal date.
        register_post_meta( 'sawe_store_credit', '_sawe_msc_expiry_month', array_merge( $base_args, [
            'type'        => 'integer',
            'description' => 'Renewal month (1-12)',
            'default'     => 1,
        ] ) );

        // Day of month (1–31) for the annual renewal date.
        register_post_meta( 'sawe_store_credit', '_sawe_msc_expiry_day', array_merge( $base_args, [
            'type'        => 'integer',
            'description' => 'Renewal day (1-31)',
            'default'     => 1,
        ] ) );

        // JSON-encoded array of WP role slugs, e.g. '["sawe_member","subscriber"]'.
        // Users holding ANY of these roles receive the credit.
        register_post_meta( 'sawe_store_credit', '_sawe_msc_roles', array_merge( $base_args, [
            'type'        => 'string',
            'description' => 'JSON-encoded array of WP role slugs',
            'default'     => '[]',
        ] ) );

        // JSON-encoded array of product_cat term IDs.
        // Products in any of these categories qualify for the credit discount.
        register_post_meta( 'sawe_store_credit', '_sawe_msc_product_categories', array_merge( $base_args, [
            'type'        => 'string',
            'description' => 'JSON-encoded array of product_cat term IDs',
            'default'     => '[]',
        ] ) );

        // JSON-encoded array of product post IDs.
        // These specific products qualify regardless of their category.
        register_post_meta( 'sawe_store_credit', '_sawe_msc_products', array_merge( $base_args, [
            'type'        => 'string',
            'description' => 'JSON-encoded array of product post IDs',
            'default'     => '[]',
        ] ) );
    }

    // =========================================================================
    // Static helpers (used by all other classes)
    // =========================================================================

    /**
     * Get all published (active) store credit definitions.
     *
     * Only posts with post_status = 'publish' are returned. Draft, pending,
     * and trashed credits are ignored by every business logic class.
     *
     * Called by:
     *   - SAWE_MSC_User_Credits::sync_user()
     *   - SAWE_MSC_User_Credits::process_renewals()
     *
     * @return WP_Post[]  Array of post objects, ordered by title ASC. Empty array if none.
     */
    public static function get_published_credits(): array {
        return get_posts( [
            'post_type'      => 'sawe_store_credit',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] ) ?: [];
    }

    /**
     * Retrieve all settings meta for a given credit post as a typed PHP array.
     *
     * This is the canonical way to read credit configuration throughout the
     * plugin. It handles type casting, JSON decoding, and default fallbacks
     * so callers do not need to do any of that themselves.
     *
     * Return shape:
     * [
     *   'admin_notes'        => string,   — Admin-only notes.
     *   'initial_amount'     => float,    — Dollar amount to award/renew.
     *   'expiry_month'       => int,      — 1–12. Defaults to 1 if not set.
     *   'expiry_day'         => int,      — 1–31. Defaults to 1 if not set.
     *   'roles'              => string[], — WP role slugs. Empty array if none.
     *   'product_categories' => int[],   — product_cat term IDs. Empty if none.
     *   'products'           => int[],   — Product post IDs. Empty if none.
     * ]
     *
     * @param int $post_id  The sawe_store_credit post ID.
     *
     * @return array  Typed, decoded meta array.
     */
    public static function get_credit_meta( int $post_id ): array {
        return [
            'admin_notes'        => (string) get_post_meta( $post_id, '_sawe_msc_admin_notes',        true ),
            'initial_amount'     => (float)  get_post_meta( $post_id, '_sawe_msc_initial_amount',     true ),
            // Fallback to 1 if the meta is missing (e.g. newly created post before save).
            'expiry_month'       => (int)    get_post_meta( $post_id, '_sawe_msc_expiry_month',       true ) ?: 1,
            'expiry_day'         => (int)    get_post_meta( $post_id, '_sawe_msc_expiry_day',         true ) ?: 1,
            'roles'              => json_decode( (string) get_post_meta( $post_id, '_sawe_msc_roles',              true ), true ) ?: [],
            'product_categories' => json_decode( (string) get_post_meta( $post_id, '_sawe_msc_product_categories', true ), true ) ?: [],
            'products'           => json_decode( (string) get_post_meta( $post_id, '_sawe_msc_products',           true ), true ) ?: [],
        ];
    }

    /**
     * Calculate the next annual renewal date for a credit definition.
     *
     * Logic:
     *   1. Build a DateTime for the configured Month/Day in the current year.
     *   2. If that date is today or already past, advance to next year.
     *
     * All calculations are in UTC to match WordPress's `current_time( 'mysql', true )`
     * format used in the DB.
     *
     * Called by SAWE_MSC_User_Credits::get_active_credits_for_user() to populate
     * the 'renewal' key in the display array.
     *
     * Example: Credit configured for May 1.
     *   Called on March 15 → returns DateTime for May 1 this year.
     *   Called on June 5  → returns DateTime for May 1 next year.
     *
     * @param int $post_id  The sawe_store_credit post ID.
     *
     * @return \DateTime  Next renewal date at midnight UTC.
     */
    public static function get_next_renewal_date( int $post_id ): \DateTime {
        $meta  = self::get_credit_meta( $post_id );
        $month = $meta['expiry_month'];
        $day   = $meta['expiry_day'];

        $now       = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
        $year      = (int) $now->format( 'Y' );

        // Try this calendar year first.
        $candidate = \DateTime::createFromFormat( 'Y-n-j', "{$year}-{$month}-{$day}", new \DateTimeZone( 'UTC' ) );

        // If the date is today or in the past, move to next year.
        if ( ! $candidate || $candidate <= $now ) {
            $candidate = \DateTime::createFromFormat( 'Y-n-j', ( $year + 1 ) . "-{$month}-{$day}", new \DateTimeZone( 'UTC' ) );
        }

        return $candidate;
    }
}
