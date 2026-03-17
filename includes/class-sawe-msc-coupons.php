<?php
/**
 * SAWE Coupons — Frontend Integration
 *
 * Extends WooCommerce's native coupon system with role-based restrictions,
 * auto-apply behaviour, and contextual display on cart, checkout, and My Account.
 *
 * ============================================================================
 * HOW THIS DIFFERS FROM STORE CREDITS
 * ============================================================================
 *
 * Store credits are managed by this plugin's own CPT (sawe_store_credit) and
 * DB table. Coupons are WooCommerce-native (shop_coupon post type). We layer
 * extra metadata and behaviour on top of existing WC coupons:
 *
 *   _sawe_msc_coupon_roles               → JSON array of role slugs (optional)
 *   _sawe_msc_coupon_display_account     → 'yes'|'no' — show in My Account tab
 *   _sawe_msc_coupon_display_cart        → 'yes'|'no' — show on cart/checkout
 *   _sawe_msc_coupon_auto_apply          → 'yes'|'no' — auto-apply when eligible
 *
 * ============================================================================
 * ROLE RESTRICTION BEHAVIOUR
 * ============================================================================
 *
 * If _sawe_msc_coupon_roles is empty, the coupon is available to all users
 * (same as a standard WC coupon). If one or more roles are set, only users
 * holding ANY of those roles may use or see the coupon.
 *
 * Role restriction is enforced at WC validation time via the
 * 'woocommerce_coupon_is_valid' filter so that even manually entered coupon
 * codes are blocked for ineligible users.
 *
 * ============================================================================
 * AUTO-APPLY BEHAVIOUR
 * ============================================================================
 *
 * Coupons with auto_apply = 'yes' are automatically applied to the cart when:
 *   1. The user is logged in and has a matching role (or coupon has no role restriction).
 *   2. The coupon applies to at least one item in the current cart.
 *   3. The user has not manually removed the coupon this session.
 *
 * WC success notices are suppressed for auto-applied coupons so the user is
 * not flooded with messages on every page load.
 *
 * ============================================================================
 * SESSION STRATEGY
 * ============================================================================
 *
 * SESSION_COUPON_REMOVED ('sawe_msc_coupon_removed'):
 *   string[]  Coupon codes manually removed by the user this session.
 *   Populated by ajax_remove_coupon(); cleared on logout / thank-you page.
 *   Prevents re-auto-apply of coupons the user explicitly dismissed.
 *
 * @package SAWE_MSC
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

class SAWE_MSC_Coupons {

    // =========================================================================
    // Constants
    // =========================================================================

    /**
     * WC session key for coupons the user has manually removed this session.
     *
     * @var string
     */
    const SESSION_COUPON_REMOVED = 'sawe_msc_coupon_removed';

    /**
     * My Account rewrite endpoint slug for the "Available Coupons" tab.
     *
     * @var string
     */
    const ENDPOINT = 'available-coupons';

    // =========================================================================
    // Instance state
    // =========================================================================

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Set to true while auto-apply is in progress so success notices are
     * suppressed via the woocommerce_coupon_message filter.
     *
     * @var bool
     */
    private bool $auto_applying = false;

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
     * Private constructor — registers all hooks.
     */
    private function __construct() {
        // ── Role-based coupon validation ──────────────────────────────────────
        add_filter( 'woocommerce_coupon_is_valid', [ $this, 'check_role_restriction' ], 10, 2 );

        // ── Suppress WC success notice on auto-apply ──────────────────────────
        add_filter( 'woocommerce_coupon_message', [ $this, 'suppress_auto_apply_message' ], 10, 3 );

        // ── Auto-apply: priority 1 so it fires before display hooks ──────────
        add_action( 'woocommerce_before_cart',          [ $this, 'maybe_auto_apply_coupons' ], 1 );
        add_action( 'woocommerce_before_checkout_form', [ $this, 'maybe_auto_apply_coupons' ], 1 );

        // ── Cart display (after credits, which hook at default priority 10) ──
        add_action( 'woocommerce_before_cart_totals',   [ $this, 'display_coupon_notices' ], 15 );

        // ── Checkout display (after credits at priority 5) ────────────────────
        add_action( 'woocommerce_before_checkout_form', [ $this, 'display_coupon_notices' ], 6 );

        // ── My Account endpoint ───────────────────────────────────────────────
        add_action( 'init',                           [ $this, 'add_endpoint' ] );
        add_filter( 'query_vars',                     [ $this, 'add_query_vars' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
        add_action(
            'woocommerce_account_' . self::ENDPOINT . '_endpoint',
            [ $this, 'endpoint_content' ]
        );

        // ── Native WC coupon removal (e.g. [Remove] link in cart totals) ─────
        // Fires whenever WC removes a coupon by any means. Adding the code to
        // SESSION_COUPON_REMOVED here prevents auto-apply from re-adding it.
        add_action( 'woocommerce_removed_coupon', [ $this, 'on_coupon_removed' ] );

        // ── AJAX handlers (logged-in users only) ──────────────────────────────
        add_action( 'wp_ajax_sawe_msc_apply_coupon',  [ $this, 'ajax_apply_coupon' ] );
        add_action( 'wp_ajax_sawe_msc_remove_coupon', [ $this, 'ajax_remove_coupon' ] );

        // ── Asset enqueueing ──────────────────────────────────────────────────
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

        // ── Session cleanup ───────────────────────────────────────────────────
        add_action( 'woocommerce_thankyou', [ $this, 'clear_session' ] );
        add_action( 'wp_logout',            [ $this, 'clear_session' ] );
    }

    // =========================================================================
    // Role-based restriction
    // =========================================================================

    /**
     * Filter: woocommerce_coupon_is_valid
     *
     * Blocks WC coupon validation if the current user lacks a required role.
     * If _sawe_msc_coupon_roles is empty, the coupon passes through unchanged.
     *
     * Returning false causes WC to throw "Coupon is not valid." This applies
     * to both manually entered codes and programmatically applied coupons.
     *
     * @param mixed       $valid   Current validity (true or WP_Error from earlier filters).
     * @param \WC_Coupon  $coupon  The coupon being validated.
     *
     * @return mixed  Original $valid if no restriction applies; false if role check fails.
     */
    public function check_role_restriction( $valid, \WC_Coupon $coupon ) {
        // If already invalid, do not override.
        if ( is_wp_error( $valid ) || ! $valid ) {
            return $valid;
        }

        $roles = $this->get_coupon_roles( $coupon->get_id() );

        // No role restriction — coupon is available to everyone.
        if ( empty( $roles ) ) {
            return $valid;
        }

        // Restricted coupon, guest user.
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $user_roles = (array) wp_get_current_user()->roles;
        foreach ( $roles as $role ) {
            if ( in_array( $role, $user_roles, true ) ) {
                return $valid; // User has a qualifying role.
            }
        }

        return false; // No matching role.
    }

    /**
     * Filter: woocommerce_coupon_message
     *
     * Returns an empty string for COUPON_SUCCESS messages while auto-apply is
     * running. WC's wc_add_notice() skips empty messages, preventing the
     * "Coupon code applied successfully" banner from firing on every cart load.
     *
     * @param string      $msg       Original message text.
     * @param int         $msg_code  WC_Coupon message code constant.
     * @param \WC_Coupon  $coupon    The coupon being messaged.
     *
     * @return string
     */
    public function suppress_auto_apply_message( string $msg, int $msg_code, \WC_Coupon $coupon ): string {
        if ( $this->auto_applying && \WC_Coupon::WC_COUPON_SUCCESS === $msg_code ) {
            return ''; // wc_add_notice() skips empty strings.
        }
        return $msg;
    }

    // =========================================================================
    // Auto-apply
    // =========================================================================

    /**
     * Apply all eligible auto-apply coupons to the cart on cart/checkout load.
     *
     * Skips coupons that:
     *   - The user has manually removed this session (SESSION_COUPON_REMOVED).
     *   - Are already applied to the cart.
     *   - The current user doesn't have the required role for.
     *   - Don't apply to any item currently in the cart.
     *
     * WC success notices are suppressed via suppress_auto_apply_message() while
     * this method runs.
     *
     * Hooks: woocommerce_before_cart (priority 1)
     *        woocommerce_before_checkout_form (priority 1)
     *
     * @return void
     */
    public function maybe_auto_apply_coupons(): void {
        if ( ! is_user_logged_in() || is_admin() ) {
            return;
        }

        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }

        $removed_codes = $this->get_removed_codes();
        $coupon_ids    = $this->get_auto_apply_coupon_ids();

        if ( empty( $coupon_ids ) ) {
            return;
        }

        $this->auto_applying = true;

        foreach ( $coupon_ids as $coupon_id ) {
            $coupon = new \WC_Coupon( (int) $coupon_id );
            $code   = $coupon->get_code();

            if ( ! $code ) {
                continue;
            }

            // User explicitly removed this coupon this session — respect that.
            if ( in_array( $code, $removed_codes, true ) ) {
                continue;
            }

            // Already in the cart — nothing to do.
            if ( WC()->cart->has_discount( $code ) ) {
                continue;
            }

            // Role check (our restriction).
            if ( ! $this->user_has_required_role( (int) $coupon_id ) ) {
                continue;
            }

            // Product / category applicability check.
            if ( ! $this->coupon_applies_to_cart( $coupon, WC()->cart ) ) {
                continue;
            }

            WC()->cart->apply_coupon( $code );
        }

        $this->auto_applying = false;
    }

    // =========================================================================
    // Cart / Checkout display
    // =========================================================================

    /**
     * Render coupon notice boxes on the cart or checkout page.
     *
     * Shows each eligible coupon that applies to items in the current cart:
     *   • Applied → "Remove Coupon" button.
     *   • Not applied + was removed by user (auto-apply) → "Re-apply Coupon" button.
     *   • Not applied + not removed → "Apply Coupon" button.
     *
     * Only coupons with _sawe_msc_coupon_display_cart = 'yes' are shown,
     * and only when they apply to at least one item in the cart.
     *
     * Hooks: woocommerce_before_cart_totals (priority 15)
     *        woocommerce_before_checkout_form (priority 6)
     *
     * @return void
     */
    public function display_coupon_notices(): void {
        if ( ! is_user_logged_in() || is_admin() ) {
            return;
        }

        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }

        $coupons = $this->get_display_coupons_for_cart();

        if ( empty( $coupons ) ) {
            return;
        }

        $removed_codes = $this->get_removed_codes();

        echo '<div class="sawe-msc-coupon-notice-wrap">';
        echo '<h4 class="sawe-msc-coupon-section-title">' . esc_html__( 'Available Coupons', 'sawe-msc' ) . '</h4>';

        foreach ( $coupons as $item ) {
            /** @var \WC_Coupon $coupon */
            $coupon     = $item['coupon'];
            $code       = $coupon->get_code();
            $coupon_id  = $coupon->get_id();
            $auto_apply = $item['auto_apply'];
            $is_applied = WC()->cart->has_discount( $code );
            $is_removed = in_array( $code, $removed_codes, true );

            printf( '<div class="sawe-msc-coupon-box" data-coupon-code="%s">', esc_attr( $code ) );

            // Title (post title, or coupon code as fallback).
            $title = get_the_title( $coupon_id ) ?: strtoupper( $code );
            echo '<h4 class="sawe-msc-coupon-title">' . esc_html( $title ) . '</h4>';

            // Description.
            $desc = $coupon->get_description();
            if ( $desc ) {
                echo '<p class="sawe-msc-coupon-desc">' . wp_kses_post( $desc ) . '</p>';
            }

            // Details list.
            echo '<ul class="sawe-msc-coupon-details">';

            printf(
                '<li><strong>%s</strong> %s</li>',
                esc_html__( 'Discount:', 'sawe-msc' ),
                esc_html( $this->format_coupon_discount( $coupon ) )
            );

            printf(
                '<li><strong>%s</strong> <code class="sawe-msc-coupon-code">%s</code></li>',
                esc_html__( 'Code:', 'sawe-msc' ),
                esc_html( strtoupper( $code ) )
            );

            if ( $coupon->get_date_expires() ) {
                printf(
                    '<li><strong>%s</strong> %s</li>',
                    esc_html__( 'Expires:', 'sawe-msc' ),
                    esc_html( $coupon->get_date_expires()->date_i18n( get_option( 'date_format' ) ) )
                );
            }

            $usage_limit = $coupon->get_usage_limit();
            if ( $usage_limit > 0 ) {
                $remaining = max( 0, $usage_limit - (int) $coupon->get_usage_count() );
                printf(
                    '<li><strong>%s</strong> %s</li>',
                    esc_html__( 'Uses remaining:', 'sawe-msc' ),
                    esc_html( number_format_i18n( $remaining ) )
                );
            }

            $limit_per_user = $coupon->get_usage_limit_per_user();
            if ( $limit_per_user > 0 ) {
                $user_id       = get_current_user_id();
                $used_by       = $coupon->get_used_by();
                $user_used     = count( array_filter( $used_by, fn( $id ) => (int) $id === $user_id ) );
                $user_remaining = max( 0, $limit_per_user - $user_used );
                printf(
                    '<li><strong>%s</strong> %s</li>',
                    esc_html__( 'Your uses remaining:', 'sawe-msc' ),
                    esc_html( number_format_i18n( $user_remaining ) )
                );
            }

            echo '</ul>';

            // Action button.
            if ( $is_applied ) {
                printf(
                    '<button type="button" class="sawe-msc-coupon-remove-btn button" data-coupon-code="%s">%s</button>',
                    esc_attr( $code ),
                    esc_html__( 'Remove Coupon', 'sawe-msc' )
                );
            } elseif ( $auto_apply && $is_removed ) {
                // Auto-apply coupon that the user manually removed — offer Re-apply.
                printf(
                    '<button type="button" class="sawe-msc-coupon-apply-btn button" data-coupon-code="%s">%s</button>',
                    esc_attr( $code ),
                    esc_html__( 'Re-apply Coupon', 'sawe-msc' )
                );
            } else {
                // Not applied — offer Apply.
                printf(
                    '<button type="button" class="sawe-msc-coupon-apply-btn button" data-coupon-code="%s">%s</button>',
                    esc_attr( $code ),
                    esc_html__( 'Apply Coupon', 'sawe-msc' )
                );
            }

            echo '</div>'; // .sawe-msc-coupon-box
        }

        echo '</div>'; // .sawe-msc-coupon-notice-wrap
    }

    // =========================================================================
    // My Account endpoint
    // =========================================================================

    /**
     * Register the 'available-coupons' rewrite endpoint.
     *
     * Hook: init
     *
     * @return void
     */
    public function add_endpoint(): void {
        add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
    }

    /**
     * Add the endpoint slug to WordPress's registered query variables.
     *
     * Filter: query_vars
     *
     * @param array $vars  Existing query variable names.
     *
     * @return array
     */
    public function add_query_vars( array $vars ): array {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    /**
     * Insert "Available Coupons" into the My Account navigation menu, before logout.
     *
     * Filter: woocommerce_account_menu_items
     *
     * @param array $items  Existing menu items: [ slug => label, ... ]
     *
     * @return array
     */
    public function add_menu_item( array $items ): array {
        $logout = [];
        if ( isset( $items['customer-logout'] ) ) {
            $logout = [ 'customer-logout' => $items['customer-logout'] ];
            unset( $items['customer-logout'] );
        }

        $items[ self::ENDPOINT ] = __( 'Available Coupons', 'sawe-msc' );

        return array_merge( $items, $logout );
    }

    /**
     * Render the "Available Coupons" My Account tab content.
     *
     * Shows all eligible coupons with _sawe_msc_coupon_display_account = 'yes'.
     * Cart applicability is NOT checked here (the user may not have items in
     * their cart when they visit their account page).
     *
     * Hook: woocommerce_account_available-coupons_endpoint
     *
     * @return void
     */
    public function endpoint_content(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $coupons = $this->get_account_display_coupons();

        echo '<h3>' . esc_html__( 'Available Coupons', 'sawe-msc' ) . '</h3>';

        if ( empty( $coupons ) ) {
            echo '<p>' . esc_html__( 'You have no exclusive coupons available at this time.', 'sawe-msc' ) . '</p>';
            return;
        }

        echo '<div class="sawe-msc-coupon-notice-wrap">';

        foreach ( $coupons as $item ) {
            /** @var \WC_Coupon $coupon */
            $coupon    = $item['coupon'];
            $code      = $coupon->get_code();
            $coupon_id = $coupon->get_id();

            echo '<div class="sawe-msc-coupon-box">';

            $title = get_the_title( $coupon_id ) ?: strtoupper( $code );
            echo '<h4 class="sawe-msc-coupon-title">' . esc_html( $title ) . '</h4>';

            $desc = $coupon->get_description();
            if ( $desc ) {
                echo '<p class="sawe-msc-coupon-desc">' . wp_kses_post( $desc ) . '</p>';
            }

            echo '<ul class="sawe-msc-coupon-details">';

            printf(
                '<li><strong>%s</strong> %s</li>',
                esc_html__( 'Discount:', 'sawe-msc' ),
                esc_html( $this->format_coupon_discount( $coupon ) )
            );

            printf(
                '<li><strong>%s</strong> <code class="sawe-msc-coupon-code">%s</code></li>',
                esc_html__( 'Coupon Code:', 'sawe-msc' ),
                esc_html( strtoupper( $code ) )
            );

            if ( $coupon->get_date_expires() ) {
                printf(
                    '<li><strong>%s</strong> %s</li>',
                    esc_html__( 'Expires:', 'sawe-msc' ),
                    esc_html( $coupon->get_date_expires()->date_i18n( get_option( 'date_format' ) ) )
                );
            }

            $usage_limit = $coupon->get_usage_limit();
            if ( $usage_limit > 0 ) {
                $remaining = max( 0, $usage_limit - (int) $coupon->get_usage_count() );
                printf(
                    '<li><strong>%s</strong> %s</li>',
                    esc_html__( 'Uses remaining:', 'sawe-msc' ),
                    esc_html( number_format_i18n( $remaining ) )
                );
            }

            $limit_per_user = $coupon->get_usage_limit_per_user();
            if ( $limit_per_user > 0 ) {
                $user_id        = get_current_user_id();
                $used_by        = $coupon->get_used_by();
                $user_used      = count( array_filter( $used_by, fn( $id ) => (int) $id === $user_id ) );
                $user_remaining = max( 0, $limit_per_user - $user_used );
                printf(
                    '<li><strong>%s</strong> %s</li>',
                    esc_html__( 'Your uses remaining:', 'sawe-msc' ),
                    esc_html( number_format_i18n( $user_remaining ) )
                );
            }

            // Note if restricted to specific products/categories.
            $product_ids  = $coupon->get_product_ids();
            $category_ids = $coupon->get_product_categories();
            if ( ! empty( $product_ids ) || ! empty( $category_ids ) ) {
                echo '<li><em>' . esc_html__( 'Applies to specific products or categories only.', 'sawe-msc' ) . '</em></li>';
            }

            echo '</ul>';
            echo '</div>'; // .sawe-msc-coupon-box
        }

        echo '</div>'; // .sawe-msc-coupon-notice-wrap
    }

    // =========================================================================
    // AJAX handlers
    // =========================================================================

    /**
     * AJAX handler: apply a coupon to the cart and remove it from the
     * SESSION_COUPON_REMOVED list so auto-apply can work again.
     *
     * Expected POST params:
     *   coupon_code (string) — The WC coupon code to apply.
     *   nonce       (string) — wp_create_nonce( 'sawe_msc_nonce' ).
     *
     * Hook: wp_ajax_sawe_msc_apply_coupon
     *
     * @return void
     */
    public function ajax_apply_coupon(): void {
        check_ajax_referer( 'sawe_msc_nonce', 'nonce' );

        $code = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) );

        if ( ! $code ) {
            wp_send_json_error( [ 'message' => __( 'Invalid coupon code.', 'sawe-msc' ) ] );
        }

        // Remove from "removed" session so auto-apply can re-add it later if needed.
        $removed = $this->get_removed_codes();
        $removed = array_values( array_diff( $removed, [ $code ] ) );
        WC()->session->set( self::SESSION_COUPON_REMOVED, $removed );

        if ( ! WC()->cart->has_discount( $code ) ) {
            WC()->cart->apply_coupon( $code );
        }

        WC()->cart->calculate_totals();

        wp_send_json_success( [ 'message' => __( 'Coupon applied.', 'sawe-msc' ) ] );
    }

    /**
     * AJAX handler: remove a coupon from the cart and add it to the
     * SESSION_COUPON_REMOVED list so auto-apply skips it this session.
     *
     * Expected POST params:
     *   coupon_code (string) — The WC coupon code to remove.
     *   nonce       (string) — wp_create_nonce( 'sawe_msc_nonce' ).
     *
     * Hook: wp_ajax_sawe_msc_remove_coupon
     *
     * @return void
     */
    public function ajax_remove_coupon(): void {
        check_ajax_referer( 'sawe_msc_nonce', 'nonce' );

        $code = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) );

        if ( ! $code ) {
            wp_send_json_error( [ 'message' => __( 'Invalid coupon code.', 'sawe-msc' ) ] );
        }

        // Record that the user removed this coupon so auto-apply doesn't re-add it.
        $removed = $this->get_removed_codes();
        if ( ! in_array( $code, $removed, true ) ) {
            $removed[] = $code;
        }
        WC()->session->set( self::SESSION_COUPON_REMOVED, $removed );

        WC()->cart->remove_coupon( $code );
        WC()->cart->calculate_totals();

        wp_send_json_success( [ 'message' => __( 'Coupon removed from this order.', 'sawe-msc' ) ] );
    }

    // =========================================================================
    // Asset enqueueing
    // =========================================================================

    /**
     * Enqueue the coupon JS + public CSS on cart, checkout, and account pages.
     *
     * Hook: wp_enqueue_scripts
     *
     * @return void
     */
    public function enqueue_scripts(): void {
        if ( ! is_cart() && ! is_checkout() && ! is_account_page() ) {
            return;
        }

        // Public CSS (shared with credits; WP deduplicates by handle).
        wp_enqueue_style(
            'sawe-msc-public',
            SAWE_MSC_PLUGIN_URL . 'public/css/sawe-msc-public.css',
            [],
            SAWE_MSC_VERSION
        );

        // Coupon-specific JS only needed on cart/checkout.
        if ( is_cart() || is_checkout() ) {
            wp_enqueue_script(
                'sawe-msc-coupons',
                SAWE_MSC_PLUGIN_URL . 'public/js/sawe-msc-coupons.js',
                [ 'jquery', 'wc-cart' ],
                SAWE_MSC_VERSION,
                true
            );

            wp_localize_script( 'sawe-msc-coupons', 'saweMscCouponData', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'sawe_msc_nonce' ),
                'i18n'    => [
                    'applyLabel'    => __( 'Apply Coupon', 'sawe-msc' ),
                    'reapplyLabel'  => __( 'Re-apply Coupon', 'sawe-msc' ),
                    'removeLabel'   => __( 'Remove Coupon', 'sawe-msc' ),
                    'applyingLabel' => __( 'Applying…', 'sawe-msc' ),
                    'removingLabel' => __( 'Removing…', 'sawe-msc' ),
                ],
            ] );
        }
    }

    // =========================================================================
    // Session helpers
    // =========================================================================

    /**
     * Action: woocommerce_removed_coupon
     *
     * Called by WC whenever a coupon is removed by any means — including the
     * native [Remove] link in the cart totals table. Adds the coupon code to
     * SESSION_COUPON_REMOVED so maybe_auto_apply_coupons() does not re-apply
     * it on the next page load or AJAX cart update.
     *
     * @param string $coupon_code  The code that was removed (lowercased by WC).
     *
     * @return void
     */
    public function on_coupon_removed( string $coupon_code ): void {
        if ( ! WC()->session ) {
            return;
        }

        $removed = $this->get_removed_codes();

        if ( ! in_array( $coupon_code, $removed, true ) ) {
            $removed[] = $coupon_code;
            WC()->session->set( self::SESSION_COUPON_REMOVED, $removed );
        }
    }

    /**
     * Get the list of coupon codes the user has manually removed this session.
     *
     * @return string[]
     */
    private function get_removed_codes(): array {
        return (array) ( WC()->session ? WC()->session->get( self::SESSION_COUPON_REMOVED, [] ) : [] );
    }

    /**
     * Clear the coupon session key on logout and after order placement.
     *
     * @return void
     */
    public function clear_session(): void {
        if ( WC()->session ) {
            WC()->session->set( self::SESSION_COUPON_REMOVED, [] );
        }
    }

    // =========================================================================
    // Coupon query helpers
    // =========================================================================

    /**
     * Get IDs of published WC coupons with auto-apply enabled.
     *
     * @return int[]
     */
    private function get_auto_apply_coupon_ids(): array {
        return get_posts( [
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_sawe_msc_coupon_auto_apply',
                    'value' => 'yes',
                ],
            ],
        ] ) ?: [];
    }

    /**
     * Get eligible coupons to display on the cart / checkout page.
     *
     * Returns only coupons with _sawe_msc_coupon_display_cart = 'yes' that:
     *   - The current user has the required role for (or has no role restriction).
     *   - Apply to at least one item in the current cart.
     *
     * @return array[]  Each entry: [ 'coupon' => WC_Coupon, 'auto_apply' => bool ]
     */
    private function get_display_coupons_for_cart(): array {
        $coupon_ids = get_posts( [
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_sawe_msc_coupon_display_cart',
                    'value' => 'yes',
                ],
            ],
        ] ) ?: [];

        $result = [];

        foreach ( $coupon_ids as $id ) {
            if ( ! $this->user_has_required_role( (int) $id ) ) {
                continue;
            }

            $coupon = new \WC_Coupon( (int) $id );

            if ( ! $coupon->get_id() ) {
                continue;
            }

            // Only show if applicable to current cart.
            if ( ! $this->coupon_applies_to_cart( $coupon, WC()->cart ) ) {
                continue;
            }

            $auto_apply = 'yes' === get_post_meta( (int) $id, '_sawe_msc_coupon_auto_apply', true );

            $result[] = [
                'coupon'     => $coupon,
                'auto_apply' => $auto_apply,
            ];
        }

        return $result;
    }

    /**
     * Get eligible coupons to display on the My Account "Available Coupons" tab.
     *
     * Returns only coupons with _sawe_msc_coupon_display_account = 'yes' that
     * the current user has the required role for. Cart applicability is not
     * checked here — the user may have an empty cart.
     *
     * Expired coupons and those that have hit their global usage limit are
     * excluded to avoid showing unavailable offers.
     *
     * @return array[]  Each entry: [ 'coupon' => WC_Coupon ]
     */
    private function get_account_display_coupons(): array {
        $coupon_ids = get_posts( [
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_sawe_msc_coupon_display_account',
                    'value' => 'yes',
                ],
            ],
        ] ) ?: [];

        $now    = time();
        $result = [];

        foreach ( $coupon_ids as $id ) {
            if ( ! $this->user_has_required_role( (int) $id ) ) {
                continue;
            }

            $coupon = new \WC_Coupon( (int) $id );

            if ( ! $coupon->get_id() ) {
                continue;
            }

            // Skip expired coupons.
            $expires = $coupon->get_date_expires();
            if ( $expires && $expires->getTimestamp() < $now ) {
                continue;
            }

            // Skip coupons that have hit their global usage limit.
            $usage_limit = $coupon->get_usage_limit();
            if ( $usage_limit > 0 && $coupon->get_usage_count() >= $usage_limit ) {
                continue;
            }

            $result[] = [ 'coupon' => $coupon ];
        }

        return $result;
    }

    // =========================================================================
    // Role & cart helpers
    // =========================================================================

    /**
     * Return the role slugs configured for a coupon.
     *
     * Public so that SAWE_MSC_Coupon_Admin can read the same data.
     *
     * @param int $coupon_id  WC coupon (shop_coupon) post ID.
     *
     * @return string[]  Array of WP role slugs; empty if no restriction.
     */
    public function get_coupon_roles( int $coupon_id ): array {
        $json = get_post_meta( $coupon_id, '_sawe_msc_coupon_roles', true );
        return json_decode( $json ?: '[]', true ) ?: [];
    }

    /**
     * Check if the current user has a role required by this coupon.
     *
     * Returns true if the coupon has no role restriction.
     *
     * @param int $coupon_id
     *
     * @return bool
     */
    private function user_has_required_role( int $coupon_id ): bool {
        $roles = $this->get_coupon_roles( $coupon_id );

        if ( empty( $roles ) ) {
            return true; // No restriction.
        }

        if ( ! is_user_logged_in() ) {
            return false;
        }

        $user_roles = (array) wp_get_current_user()->roles;
        foreach ( $roles as $role ) {
            if ( in_array( $role, $user_roles, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a coupon applies to at least one item in the cart.
     *
     * A coupon "applies" if it has no product/category restriction, or if at
     * least one cart item matches the configured product IDs or category IDs.
     *
     * Excluded products/categories are handled by WC at application time and
     * are NOT checked here to avoid duplicate logic.
     *
     * @param \WC_Coupon  $coupon  The coupon to check.
     * @param \WC_Cart    $cart    The current cart.
     *
     * @return bool
     */
    private function coupon_applies_to_cart( \WC_Coupon $coupon, \WC_Cart $cart ): bool {
        $product_ids  = $coupon->get_product_ids();
        $category_ids = $coupon->get_product_categories();

        // No product/category restriction — applies to any cart.
        if ( empty( $product_ids ) && empty( $category_ids ) ) {
            return true;
        }

        foreach ( $cart->get_cart() as $item ) {
            $pid = (int) $item['product_id'];
            $vid = (int) ( $item['variation_id'] ?? 0 );

            // Match individual product or variation IDs.
            if ( ! empty( $product_ids ) ) {
                if ( in_array( $pid, $product_ids, true ) || ( $vid && in_array( $vid, $product_ids, true ) ) ) {
                    return true;
                }
            }

            // Match product categories.
            if ( ! empty( $category_ids ) ) {
                $terms = get_the_terms( $pid, 'product_cat' );
                if ( $terms && ! is_wp_error( $terms ) ) {
                    foreach ( $terms as $term ) {
                        if ( in_array( (int) $term->term_id, $category_ids, true ) ) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Format a coupon's discount as a human-readable string.
     *
     * Examples: "$10.00 off", "15% off", "Free shipping"
     *
     * @param \WC_Coupon $coupon
     *
     * @return string
     */
    private function format_coupon_discount( \WC_Coupon $coupon ): string {
        $type   = $coupon->get_discount_type();
        $amount = $coupon->get_amount();

        switch ( $type ) {
            case 'percent':
                return sprintf( '%s%%', wc_format_decimal( $amount, 0 ) ) . ' ' . __( 'off', 'sawe-msc' );

            case 'fixed_cart':
            case 'fixed_product':
                /* translators: %s = formatted price with currency symbol */
                return sprintf( __( '%s off', 'sawe-msc' ), wc_price( $amount ) );

            case 'free_shipping':
                return __( 'Free shipping', 'sawe-msc' );

            default:
                return esc_html( ucfirst( str_replace( '_', ' ', $type ) ) );
        }
    }
}
