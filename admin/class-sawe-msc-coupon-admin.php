<?php
/**
 * Coupon Admin — Meta Box for WooCommerce Coupons
 *
 * Adds a "SAWE Coupon Settings" meta box to the WooCommerce shop_coupon edit
 * screen. This meta box extends each WC coupon with:
 *
 *   1. Eligible Member Roles  — restricts which WordPress roles may use the
 *      coupon. If empty, the coupon is open to all users (WC default).
 *
 *   2. Display in My Account  — if checked, the coupon appears in the
 *      "Available Coupons" tab on the WooCommerce My Account page.
 *
 *   3. Display on Cart/Checkout — if checked, an info card for this coupon
 *      is shown on the Cart and Checkout pages when it applies to items in
 *      the current cart and the user has an eligible role.
 *
 *   4. Auto-apply            — if checked, the coupon is automatically applied
 *      to the cart when it is eligible. The user may still remove or re-apply
 *      it using the action buttons rendered by SAWE_MSC_Coupons.
 *
 * ============================================================================
 * META KEYS WRITTEN (all on shop_coupon post meta)
 * ============================================================================
 *
 *  _sawe_msc_coupon_roles             string  JSON array of WP role slugs
 *  _sawe_msc_coupon_display_account   string  'yes' | 'no'
 *  _sawe_msc_coupon_display_cart      string  'yes' | 'no'
 *  _sawe_msc_coupon_auto_apply        string  'yes' | 'no'
 *
 * ============================================================================
 * REUSING THE TAG-CHIP UI
 * ============================================================================
 *
 * The Eligible Member Roles field uses the same tag-chip list-manager pattern
 * as the sawe_store_credit meta box. The same admin CSS and JS handle it —
 * this class registers a new data-target='coupon-roles' manager in the JS
 * (see admin/js/sawe-msc-admin.js) and enqueues those assets on shop_coupon
 * edit screens.
 *
 * @package SAWE_MSC
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

class SAWE_MSC_Coupon_Admin {

    /**
     * Singleton instance.
     *
     * @var self|null
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
     * Private constructor — registers all admin hooks.
     */
    private function __construct() {
        // Add our meta box to the shop_coupon edit screen.
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );

        // Save meta when the coupon post is saved.
        add_action( 'save_post', [ $this, 'save_meta_box' ], 10, 2 );

        // Enqueue admin CSS + JS on shop_coupon screens.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    // =========================================================================
    // Meta box registration
    // =========================================================================

    /**
     * Register the "SAWE Coupon Settings" meta box on the shop_coupon screen.
     *
     * Placement: 'normal' context, 'default' priority so it appears after
     * WooCommerce's own coupon data meta boxes.
     *
     * Hook: add_meta_boxes
     *
     * @return void
     */
    public function add_meta_boxes(): void {
        add_meta_box(
            'sawe_msc_coupon_settings',
            __( 'SAWE Coupon Settings', 'sawe-msc' ),
            [ $this, 'render_meta_box' ],
            'shop_coupon',
            'normal',
            'default'
        );
    }

    // =========================================================================
    // Meta box render
    // =========================================================================

    /**
     * Render the "SAWE Coupon Settings" meta box HTML.
     *
     * Fields (in order):
     *   1. Eligible Member Roles     — tag-chip list manager (data-target="coupon-roles")
     *   2. Display in My Account     — checkbox
     *   3. Display on Cart/Checkout  — checkbox
     *   4. Auto-apply Coupon         — checkbox (depends on Display on Cart/Checkout)
     *
     * @param \WP_Post $post  The shop_coupon post being edited.
     *
     * @return void
     */
    public function render_meta_box( \WP_Post $post ): void {
        wp_nonce_field( 'sawe_msc_coupon_save_meta', 'sawe_msc_coupon_nonce' );

        // Load saved values.
        $saved_roles        = json_decode( get_post_meta( $post->ID, '_sawe_msc_coupon_roles',           true ) ?: '[]', true ) ?: [];
        $display_account    = get_post_meta( $post->ID, '_sawe_msc_coupon_display_account', true );
        $display_cart       = get_post_meta( $post->ID, '_sawe_msc_coupon_display_cart',    true );
        $auto_apply         = get_post_meta( $post->ID, '_sawe_msc_coupon_auto_apply',      true );

        // All registered WP roles (slug => display name).
        $all_roles = wp_roles()->get_names();
        ?>
        <div class="sawe-msc-metabox">

            <!-- ── Eligible Member Roles ──────────────────────────────────── -->
            <!--  Saved to: _sawe_msc_coupon_roles (JSON array)               -->
            <!--  If empty, coupon is available to all users.                 -->
            <!--  JS target key: 'coupon-roles'                               -->
            <div class="sawe-msc-field">
                <label><strong><?php esc_html_e( 'Eligible Member Roles', 'sawe-msc' ); ?></strong></label>
                <p class="description"><?php esc_html_e( 'Restrict this coupon to users with any of these WordPress roles. Leave empty to allow all users.', 'sawe-msc' ); ?></p>

                <div class="sawe-msc-list-manager" id="coupon-roles-manager">
                    <div class="sawe-msc-selected-list" id="coupon-roles-list">
                        <?php foreach ( $saved_roles as $role_slug ) : ?>
                            <span class="sawe-msc-tag" data-value="<?php echo esc_attr( $role_slug ); ?>">
                                <?php echo esc_html( $all_roles[ $role_slug ] ?? $role_slug ); ?>
                                <button type="button" class="sawe-msc-remove-tag" aria-label="<?php esc_attr_e( 'Remove', 'sawe-msc' ); ?>">×</button>
                                <input type="hidden" name="sawe_msc_coupon_roles[]" value="<?php echo esc_attr( $role_slug ); ?>">
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <select id="coupon-roles-dropdown" class="sawe-msc-add-dropdown">
                        <option value=""><?php esc_html_e( '— Add a role —', 'sawe-msc' ); ?></option>
                        <?php foreach ( $all_roles as $slug => $name ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>" data-label="<?php echo esc_attr( $name ); ?>">
                                <?php echo esc_html( $name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="button" class="button sawe-msc-add-tag-btn" data-target="coupon-roles">
                        <?php esc_html_e( 'Add Role', 'sawe-msc' ); ?>
                    </button>
                </div>
            </div>

            <hr>

            <!-- ── Display Options ────────────────────────────────────────── -->
            <div class="sawe-msc-field">
                <label><strong><?php esc_html_e( 'Display Options', 'sawe-msc' ); ?></strong></label>
                <p class="description"><?php esc_html_e( 'Choose where eligible users will see this coupon.', 'sawe-msc' ); ?></p>

                <!-- Display in My Account -->
                <p>
                    <label>
                        <input
                            type="checkbox"
                            name="sawe_msc_coupon_display_account"
                            value="yes"
                            <?php checked( $display_account, 'yes' ); ?>
                        >
                        <strong><?php esc_html_e( 'Show in My Account → Available Coupons', 'sawe-msc' ); ?></strong>
                    </label>
                    <br>
                    <span class="description"><?php esc_html_e( 'The coupon code and details are shown on the member\'s account page so they can copy and use it.', 'sawe-msc' ); ?></span>
                </p>

                <!-- Display on Cart / Checkout -->
                <p>
                    <label>
                        <input
                            type="checkbox"
                            name="sawe_msc_coupon_display_cart"
                            value="yes"
                            id="sawe_msc_coupon_display_cart"
                            <?php checked( $display_cart, 'yes' ); ?>
                        >
                        <strong><?php esc_html_e( 'Show on Cart and Checkout pages', 'sawe-msc' ); ?></strong>
                    </label>
                    <br>
                    <span class="description"><?php esc_html_e( 'An info card for this coupon is shown above the cart totals and checkout form, but only when the coupon applies to items in the cart and the user has an eligible role.', 'sawe-msc' ); ?></span>
                </p>
            </div>

            <hr>

            <!-- ── Auto-apply ─────────────────────────────────────────────── -->
            <!--  Requires Display on Cart/Checkout to be meaningful.         -->
            <div class="sawe-msc-field">
                <label><strong><?php esc_html_e( 'Auto-apply', 'sawe-msc' ); ?></strong></label>
                <p>
                    <label>
                        <input
                            type="checkbox"
                            name="sawe_msc_coupon_auto_apply"
                            value="yes"
                            <?php checked( $auto_apply, 'yes' ); ?>
                        >
                        <strong><?php esc_html_e( 'Automatically apply coupon when eligible', 'sawe-msc' ); ?></strong>
                    </label>
                    <br>
                    <span class="description"><?php esc_html_e( 'The coupon is automatically added to the cart when the user has an eligible role and qualifying items are present. The user may still remove or re-apply it using the action buttons.', 'sawe-msc' ); ?></span>
                </p>
            </div>

        </div><!-- .sawe-msc-metabox -->
        <?php
    }

    // =========================================================================
    // Save meta box
    // =========================================================================

    /**
     * Sanitise and persist the SAWE Coupon Settings meta box values.
     *
     * Security guards (checked in order before any DB write):
     *   1. Nonce missing / invalid  → bail.
     *   2. DOING_AUTOSAVE           → bail.
     *   3. Wrong post type          → bail.
     *   4. Insufficient capability  → bail.
     *
     * Hook: save_post (priority 10)
     *
     * @param int       $post_id  The post being saved.
     * @param \WP_Post  $post     The post object.
     *
     * @return void
     */
    public function save_meta_box( int $post_id, \WP_Post $post ): void {
        // Guard: nonce.
        if ( ! isset( $_POST['sawe_msc_coupon_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['sawe_msc_coupon_nonce'] ) ),
            'sawe_msc_coupon_save_meta'
        ) ) {
            return;
        }

        // Guard: autosave.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Guard: post type.
        if ( 'shop_coupon' !== $post->post_type ) {
            return;
        }

        // Guard: capability.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // ── Save: Eligible Roles ──────────────────────────────────────────────
        $roles = array_map(
            'sanitize_text_field',
            (array) ( $_POST['sawe_msc_coupon_roles'] ?? [] )
        );
        update_post_meta(
            $post_id,
            '_sawe_msc_coupon_roles',
            wp_json_encode( array_values( array_unique( $roles ) ) )
        );

        // ── Save: Display in My Account ───────────────────────────────────────
        update_post_meta(
            $post_id,
            '_sawe_msc_coupon_display_account',
            isset( $_POST['sawe_msc_coupon_display_account'] ) ? 'yes' : 'no'
        );

        // ── Save: Display on Cart / Checkout ──────────────────────────────────
        update_post_meta(
            $post_id,
            '_sawe_msc_coupon_display_cart',
            isset( $_POST['sawe_msc_coupon_display_cart'] ) ? 'yes' : 'no'
        );

        // ── Save: Auto-apply ──────────────────────────────────────────────────
        update_post_meta(
            $post_id,
            '_sawe_msc_coupon_auto_apply',
            isset( $_POST['sawe_msc_coupon_auto_apply'] ) ? 'yes' : 'no'
        );
    }

    // =========================================================================
    // Asset enqueueing
    // =========================================================================

    /**
     * Enqueue the shared admin CSS and JS on shop_coupon edit screens.
     *
     * The same assets that power the tag-chip UI on sawe_store_credit screens
     * are reused here. Both CSS and JS handles are identical; WP deduplicates
     * if SAWE_MSC_Admin also enqueues them on the same request.
     *
     * Hook: admin_enqueue_scripts
     *
     * @return void
     */
    public function enqueue_scripts(): void {
        $screen = get_current_screen();

        if ( ! $screen || 'shop_coupon' !== $screen->post_type ) {
            return;
        }

        wp_enqueue_style(
            'sawe-msc-admin',
            SAWE_MSC_PLUGIN_URL . 'admin/css/sawe-msc-admin.css',
            [],
            SAWE_MSC_VERSION
        );

        wp_enqueue_script(
            'sawe-msc-admin',
            SAWE_MSC_PLUGIN_URL . 'admin/js/sawe-msc-admin.js',
            [ 'jquery' ],
            SAWE_MSC_VERSION,
            true
        );
    }
}
