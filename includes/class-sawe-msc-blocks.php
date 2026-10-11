<?php
/**
 * Cart & Checkout Blocks Support
 *
 * Brings the store credit and coupon UI to the WooCommerce Cart and Checkout
 * *blocks*. The classic shortcode pages ([woocommerce_cart] /
 * [woocommerce_checkout]) are handled entirely by SAWE_MSC_Cart,
 * SAWE_MSC_Checkout, SAWE_MSC_Coupons and SAWE_MSC_User_Credits, and nothing
 * in this class runs on them.
 *
 * ============================================================================
 * WHY A SEPARATE CLASS?
 * ============================================================================
 *
 * The block cart/checkout is a React app that talks to the Store API. It never
 * fires the classic template hooks (woocommerce_before_cart,
 * woocommerce_before_checkout_form, woocommerce_before_cart_totals), and it
 * ignores the jQuery events (update_checkout, wc_update_cart) our classic JS
 * triggers. Without this class, on block pages:
 *   - Credit and coupon notice boxes (and their buttons) never render.
 *   - Auto-apply coupons never apply.
 *   - Newly eligible members are not synced when they reach the cart.
 *
 * Every hook below is either specific to blocks (render_block_*, Store API
 * extension points) or gated by is_block_page(), so the classic pages are
 * unaffected.
 *
 * ============================================================================
 * HOW IT WORKS
 * ============================================================================
 *
 * 1. Page load (template_redirect): on a block cart/checkout page, sync the
 *    user's credits and auto-apply coupons — the same work the classic hooks
 *    do — before the block reads the cart.
 *
 * 2. Rendering: the notice boxes are rendered with the exact same PHP methods
 *    as the classic pages (captured with output buffering) and:
 *      - prepended to the block's HTML via render_block_woocommerce/{cart,checkout};
 *      - added to every Store API cart response as extension data
 *        (extensions['sawe-msc'].notices_html).
 *    sawe-msc-blocks.js swaps in the fresh HTML whenever the block's cart
 *    store changes, so balances and buttons stay current after quantity
 *    changes, coupon changes, etc.
 *
 * 3. Buttons: clicks go through the Store API (extensionCartUpdate →
 *    handle_cart_update()), which calls the same session helpers as the
 *    classic AJAX handlers and returns the updated cart, so the block's
 *    totals refresh automatically.
 *
 * Deductions at order placement are handled in SAWE_MSC_Checkout via
 * woocommerce_store_api_checkout_order_processed.
 *
 * @package SAWE_MSC
 * @since   1.2.4
 */

defined( 'ABSPATH' ) || exit;

class SAWE_MSC_Blocks {

    /**
     * Store API namespace for our cart extension data and update callback.
     *
     * Must match NAMESPACE in public/js/sawe-msc-blocks.js.
     *
     * @var string
     */
    const STORE_API_NAMESPACE = 'sawe-msc';

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
     * Private constructor — registers hooks.
     */
    private function __construct() {
        // ── Page-load work the classic hooks do (sync + auto-apply) ──────────
        add_action( 'template_redirect', [ $this, 'prepare_block_page' ] );

        // ── Notice boxes above the Cart / Checkout blocks ─────────────────────
        // These filters only fire when the block itself is rendered.
        add_filter( 'render_block_woocommerce/cart',     [ $this, 'prepend_notices' ] );
        add_filter( 'render_block_woocommerce/checkout', [ $this, 'prepend_notices' ] );

        // ── Assets ────────────────────────────────────────────────────────────
        // Priority 20: after SAWE_MSC_Cart / SAWE_MSC_Coupons enqueue at 10,
        // so their classic scripts can be swapped out on block pages.
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ], 20 );

        // ── Store API: extension data + update callback ───────────────────────
        if ( did_action( 'woocommerce_blocks_loaded' ) ) {
            $this->register_store_api();
        } else {
            add_action( 'woocommerce_blocks_loaded', [ $this, 'register_store_api' ] );
        }
    }

    // =========================================================================
    // Page detection
    // =========================================================================

    /**
     * Is the current request the Cart or Checkout page built with blocks?
     *
     * False for the classic shortcode pages, and for checkout endpoints
     * (order-received, order-pay), which WooCommerce renders classically.
     *
     * @return bool
     */
    public static function is_block_page(): bool {
        if ( function_exists( 'is_cart' ) && is_cart() ) {
            return has_block( 'woocommerce/cart', wc_get_page_id( 'cart' ) );
        }

        if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url() ) {
            return has_block( 'woocommerce/checkout', wc_get_page_id( 'checkout' ) );
        }

        return false;
    }

    // =========================================================================
    // Page load
    // =========================================================================

    /**
     * Sync the user's credits and auto-apply coupons on a block cart/checkout
     * page — the work SAWE_MSC_User_Credits and SAWE_MSC_Coupons do on the
     * classic pages via woocommerce_before_cart / woocommerce_before_checkout_form.
     *
     * Runs on template_redirect, before the block renders and reads the cart.
     *
     * Hook: template_redirect
     *
     * @return void
     */
    public function prepare_block_page(): void {
        if ( ! is_user_logged_in() || ! self::is_block_page() ) {
            return;
        }

        SAWE_MSC_User_Credits::instance()->sync_current_user();
        SAWE_MSC_Coupons::instance()->maybe_auto_apply_coupons();
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    /**
     * Render the credit and coupon notice boxes for the current cart.
     *
     * Uses the same display methods as the classic cart page, so markup and
     * styling are identical. Returns '' for guests and empty carts (the
     * classic cart page shows no boxes for an empty cart either).
     *
     * @return string  HTML.
     */
    public static function get_notices_html(): string {
        if ( ! is_user_logged_in() || ! WC()->cart || WC()->cart->is_empty() ) {
            return '';
        }

        ob_start();
        SAWE_MSC_Cart::instance()->display_credit_notice_cart();
        SAWE_MSC_Coupons::instance()->display_coupon_notices();
        return (string) ob_get_clean();
    }

    /**
     * Prepend our notice container to the Cart / Checkout block's HTML.
     *
     * The container sits outside the block's own markup, so the block's React
     * app never overwrites it. sawe-msc-blocks.js keeps its contents current.
     *
     * Hooks: render_block_woocommerce/cart, render_block_woocommerce/checkout
     *
     * @param string $block_content  Rendered block HTML.
     *
     * @return string
     */
    public function prepend_notices( string $block_content ): string {
        if ( ! is_user_logged_in() ) {
            return $block_content;
        }

        return '<div class="sawe-msc-blocks-notices">' . self::get_notices_html() . '</div>' . $block_content;
    }

    // =========================================================================
    // Store API
    // =========================================================================

    /**
     * Register our cart extension data and update callback with the Store API.
     *
     * Hook: woocommerce_blocks_loaded
     *
     * @return void
     */
    public function register_store_api(): void {
        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' )
            || ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
            return;
        }

        // Adds extensions['sawe-msc'].notices_html to every cart response.
        woocommerce_store_api_register_endpoint_data( [
            'endpoint'        => 'cart', // CartSchema::IDENTIFIER
            'namespace'       => self::STORE_API_NAMESPACE,
            'data_callback'   => fn() => [ 'notices_html' => self::get_notices_html() ],
            'schema_callback' => fn() => [
                'notices_html' => [
                    'description' => __( 'Store credit and coupon notice boxes for the current cart.', 'sawe-msc' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
            ],
            'schema_type'     => ARRAY_A,
        ] );

        // Handles POST /wc/store/v1/cart/extensions with namespace 'sawe-msc'.
        woocommerce_store_api_register_update_callback( [
            'namespace' => self::STORE_API_NAMESPACE,
            'callback'  => [ $this, 'handle_cart_update' ],
        ] );
    }

    /**
     * Store API update callback for the notice box buttons on block pages.
     *
     * The block equivalent of the classic AJAX handlers
     * (sawe_msc_remove_credit, sawe_msc_restore_credit, sawe_msc_apply_coupon,
     * sawe_msc_remove_coupon) — it calls the same session helpers. The Store
     * API verifies its own nonce and returns the updated cart afterwards.
     *
     * Expected $data:
     *   action      (string) — remove_credit | restore_credit | apply_coupon | remove_coupon
     *   credit_id   (int)    — for the credit actions.
     *   coupon_code (string) — for the coupon actions.
     *
     * @param mixed $data  Data sent by extensionCartUpdate() (normally an array).
     *
     * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException  If a coupon can't be applied.
     *
     * @return void
     */
    public function handle_cart_update( $data ): void {
        // Mirror the classic handlers, which are registered for logged-in users only.
        if ( ! is_user_logged_in() ) {
            return;
        }

        $data   = is_array( $data ) ? $data : [];
        $action = sanitize_key( $data['action'] ?? '' );

        switch ( $action ) {
            case 'remove_credit':
            case 'restore_credit':
                SAWE_MSC_Cart::instance()->set_credit_removed(
                    (int) ( $data['credit_id'] ?? 0 ),
                    'remove_credit' === $action
                );
                break;

            case 'apply_coupon':
                $code = sanitize_text_field( (string) ( $data['coupon_code'] ?? '' ) );
                if ( ! $code ) {
                    break;
                }

                $applied = SAWE_MSC_Coupons::instance()->apply_coupon_for_session( $code );

                // WC reports the outcome as session notices, which the block
                // pages don't display. Take them back out so they don't
                // surface on some later classic page, and turn a failure into
                // a Store API error the JS can show.
                $errors = self::take_notices( 'error' );
                self::take_notices( 'success' );

                if ( ! $applied ) {
                    $message = $errors
                        ? wp_strip_all_tags( $errors[0]['notice'] )
                        : __( 'This coupon could not be applied.', 'sawe-msc' );

                    throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                        'sawe_msc_coupon_error',
                        $message,
                        400
                    );
                }
                break;

            case 'remove_coupon':
                $code = sanitize_text_field( (string) ( $data['coupon_code'] ?? '' ) );
                if ( $code ) {
                    SAWE_MSC_Coupons::instance()->remove_coupon_for_session( $code );
                    self::take_notices( 'success' );
                }
                break;
        }
    }

    /**
     * Remove and return all queued WC session notices of one type.
     *
     * @param string $type  'error', 'success' or 'notice'.
     *
     * @return array[]  Each entry: [ 'notice' => string, 'data' => array ].
     */
    private static function take_notices( string $type ): array {
        if ( ! function_exists( 'wc_get_notices' ) || ! WC()->session ) {
            return [];
        }

        $all   = wc_get_notices();
        $taken = $all[ $type ] ?? [];

        if ( $taken ) {
            unset( $all[ $type ] );
            wc_set_notices( $all );
        }

        return $taken;
    }

    // =========================================================================
    // Assets
    // =========================================================================

    /**
     * On block cart/checkout pages, swap the classic cart/coupon scripts for
     * sawe-msc-blocks.js.
     *
     * The classic scripts bind to the same button classes and refresh the
     * page with jQuery events the blocks ignore, so they must not run here.
     * The shared stylesheet (sawe-msc-public) stays enqueued.
     *
     * Hook: wp_enqueue_scripts (priority 20)
     *
     * @return void
     */
    public function enqueue_scripts(): void {
        if ( ! is_user_logged_in() || ! self::is_block_page() ) {
            return;
        }

        wp_dequeue_script( 'sawe-msc-cart' );
        wp_dequeue_script( 'sawe-msc-coupons' );

        wp_enqueue_style(
            'sawe-msc-public',
            SAWE_MSC_PLUGIN_URL . 'public/css/sawe-msc-public.css',
            [],
            SAWE_MSC_VERSION
        );

        // Depend on the WooCommerce Blocks scripts that provide the cart store
        // (wc/store/cart) and extensionCartUpdate(), when registered, so they
        // load first. A missing dependency would stop our script printing at
        // all, so only list handles that exist; the JS checks for both anyway.
        $deps = [ 'wp-data' ];
        foreach ( [ 'wc-blocks-data-store', 'wc-blocks-checkout' ] as $handle ) {
            if ( wp_script_is( $handle, 'registered' ) ) {
                $deps[] = $handle;
            }
        }

        wp_enqueue_script(
            'sawe-msc-blocks',
            SAWE_MSC_PLUGIN_URL . 'public/js/sawe-msc-blocks.js',
            $deps,
            SAWE_MSC_VERSION,
            true
        );

        wp_localize_script( 'sawe-msc-blocks', 'saweMscBlocksData', [
            'namespace' => self::STORE_API_NAMESPACE,
            'context'   => is_cart() ? 'wc/cart' : 'wc/checkout', // Block notice context.
            'i18n'      => [
                'working' => __( 'Updating…', 'sawe-msc' ),
                'error'   => __( 'Something went wrong. Please try again.', 'sawe-msc' ),
            ],
        ] );
    }
}
