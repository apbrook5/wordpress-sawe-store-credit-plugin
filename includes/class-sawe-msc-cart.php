<?php
/**
 * Cart Integration — Discount Injection & Session Management
 *
 * This class is responsible for everything that happens between the user adding
 * a product to their cart and clicking "Place Order". It:
 *
 *   1. Injects store credit as a negative WooCommerce fee (the "discount").
 *   2. Tracks the pending deduction amount in the WC session (NOT the DB).
 *   3. Handles the "Remove Store Credit" / "Re-apply Store Credit" AJAX buttons.
 *   4. Clears session state on logout and after order completion.
 *
 * ============================================================================
 * WHY WC FEES, NOT COUPONS?
 * ============================================================================
 *
 * WooCommerce coupons require a unique coupon code per user per order. Using
 * the WC_Cart::add_fee() API with a negative amount achieves the same visual
 * result on the order summary and invoice (a labelled line item with a negative
 * dollar amount) without needing to create and manage individual coupon objects.
 *
 * ============================================================================
 * SESSION STRATEGY
 * ============================================================================
 *
 * The DB balance is NEVER decremented during cart/checkout browsing. This means:
 *   - Abandoned carts never burn a user's balance.
 *   - The user can freely add/remove items, apply/remove the credit, and
 *     refresh the page without any permanent effect.
 *   - The balance only changes in SAWE_MSC_Checkout::finalise_deductions()
 *     at the moment the order is actually placed.
 *
 * Two WC session keys track transient state:
 *
 * SESSION_APPLIED ('sawe_msc_applied'):
 *   array<int, float>  Maps credit post ID → discount amount reserved this load.
 *   Written by apply_credits_to_cart() each time fees are recalculated.
 *   Read by SAWE_MSC_Checkout to know how much to deduct.
 *
 * SESSION_REMOVED ('sawe_msc_removed'):
 *   int[]  List of credit post IDs the user has explicitly clicked "Remove" on.
 *   Written by ajax_remove_credit() / ajax_restore_credit().
 *   Read by apply_credits_to_cart() to skip credits the user doesn't want.
 *
 * ============================================================================
 * FEE ORDERING
 * ============================================================================
 *
 * We register our fee callback at priority 20, after WooCommerce's default
 * coupon processing (which typically runs at priority 10). This ensures that
 * our credit discount is calculated AFTER standard coupons are applied, so
 * the credit amount is based on the post-coupon product total.
 *
 * @package SAWE_MSC
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class SAWE_MSC_Cart {

    // =========================================================================
    // Session key constants
    // =========================================================================

    /**
     * WC session key: stores pending credit deductions for the current cart.
     *
     * Format: array<int, float>  e.g. [ 42 => 15.00, 57 => 8.50 ]
     * Key   = sawe_store_credit post ID.
     * Value = dollar amount reserved as discount in the current cart load.
     *
     * Read by SAWE_MSC_Checkout::finalise_deductions() at order placement.
     * Cleared by clear_session() on logout and thank-you page.
     *
     * @var string
     */
    const SESSION_APPLIED = 'sawe_msc_applied';

    /**
     * WC session key: stores credit post IDs the user has manually removed.
     *
     * Format: int[]  e.g. [ 42, 57 ]
     * Credits in this list are skipped by apply_credits_to_cart() until
     * the user clicks "Re-apply" or the session is cleared.
     *
     * @var string
     */
    const SESSION_REMOVED = 'sawe_msc_removed';

    /**
     * Singleton instance.
     *
     * @var SAWE_MSC_Cart|null
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
     * Private constructor — registers all hooks.
     */
    private function __construct() {
        // ── Fee injection ─────────────────────────────────────────────────────
        // Priority 20: runs after standard WC coupon processing (priority ~10).
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_credits_to_cart' ], 20 );

        // ── Cart change listeners ─────────────────────────────────────────────
        // Clear the cached applied amounts when the cart contents change so
        // they are freshly calculated on the next page load / AJAX update.
        add_action( 'woocommerce_cart_item_removed',                     [ $this, 'on_cart_item_removed' ], 10, 2 );
        add_action( 'woocommerce_update_cart_action_cart_updated',       [ $this, 'on_cart_updated' ] );

        // ── AJAX handlers ─────────────────────────────────────────────────────
        // wp_ajax_ prefix: only runs for logged-in users (correct — guests have no credits).
        add_action( 'wp_ajax_sawe_msc_remove_credit',  [ $this, 'ajax_remove_credit' ] );
        add_action( 'wp_ajax_sawe_msc_restore_credit', [ $this, 'ajax_restore_credit' ] );

        // ── Cart credit notice ────────────────────────────────────────────────
        // Display the remove/re-apply buttons above the cart totals section.
        add_action( 'woocommerce_before_cart_totals', [ $this, 'display_credit_notice_cart' ] );

        // ── Asset enqueueing ──────────────────────────────────────────────────
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

        // ── Session cleanup ───────────────────────────────────────────────────
        // Clear both session keys after a completed order so they don't affect
        // the user's next purchase.
        add_action( 'woocommerce_thankyou', [ $this, 'clear_session' ] );
        // Also clear on logout — the next login should start fresh.
        add_action( 'wp_logout',            [ $this, 'clear_session' ] );
    }

    // =========================================================================
    // Main discount calculation
    // =========================================================================

    /**
     * Calculate and inject store credit discounts as WooCommerce fees.
     *
     * Called by woocommerce_cart_calculate_fees on every cart/checkout
     * recalculation (page load, AJAX update, quantity change, coupon add, etc.).
     *
     * Algorithm per credit:
     *   1. Skip if the user has removed this credit this session.
     *   2. Skip if the user's balance is zero.
     *   3. Calculate the sum of qualifying item subtotals in the cart.
     *   4. Determine discount = min( balance, qualifying_total ).
     *   5. Add a negative WC fee with the credit's name as the label.
     *   6. Record the amount in SESSION_APPLIED so Checkout can finalise.
     *
     * Taxability: fees are added with taxable=false. Store credits are a
     * pre-tax discount on qualifying item prices, not a taxable service fee.
     *
     * Hook: woocommerce_cart_calculate_fees (priority 20)
     *
     * @param \WC_Cart $cart  The WooCommerce cart object (passed by WC).
     *
     * @return void
     */
    public function apply_credits_to_cart( \WC_Cart $cart ): void {
        // Guest users have no credits.
        if ( ! is_user_logged_in() || is_admin() ) {
            return;
        }

        $user_id     = get_current_user_id();
        $active      = SAWE_MSC_User_Credits::get_active_credits_for_user( $user_id );
        $removed_ids = $this->get_removed_ids();
        $new_applied = [];

        foreach ( $active as $credit ) {
            $post_id = (int) $credit['post']->ID;

            // User chose to skip this credit for the current order.
            if ( in_array( $post_id, $removed_ids, true ) ) {
                continue;
            }

            // Credit is exhausted — nothing to apply.
            $balance = $credit['balance'];
            if ( $balance <= 0 ) {
                continue;
            }

            // Sum the subtotals of all qualifying items in the cart.
            $qualifying_total = $this->get_qualifying_cart_total( $cart, $credit['meta'] );
            if ( $qualifying_total <= 0 ) {
                continue; // No qualifying products in cart.
            }

            // Discount = lesser of remaining balance or qualifying product total.
            // Round to 2 decimal places to match WC's currency precision.
            $discount = round( min( $balance, $qualifying_total ), 2 );

            if ( $discount <= 0 ) {
                continue;
            }

            // Label shown on cart/order summary: "Store Credit: Annual Member Credit"
            $label = sprintf(
                /* translators: %s = the store credit name configured by admin */
                __( 'Store Credit: %s', 'sawe-msc' ),
                $credit['post']->post_title
            );

            // add_fee( name, amount, taxable, tax_class )
            // Negative amount = discount/reduction.
            $cart->add_fee( $label, -$discount, false );

            // Record for Checkout to read at order placement.
            $new_applied[ $post_id ] = $discount;
        }

        // Overwrite the session value on every recalculation so stale amounts
        // from a previous page load don't persist.
        WC()->session->set( self::SESSION_APPLIED, $new_applied );
    }

    // =========================================================================
    // Cart credit notice (remove / re-apply buttons)
    // =========================================================================

    /**
     * Render store-credit notice boxes above the cart totals section.
     *
     * Mirrors SAWE_MSC_Checkout::display_credit_notice() but hooks into the
     * cart page via woocommerce_before_cart_totals.
     *
     * Hook: woocommerce_before_cart_totals
     *
     * @return void
     */
    public function display_credit_notice_cart(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $credits = SAWE_MSC_User_Credits::get_active_credits_for_user( $user_id );

        if ( empty( $credits ) ) {
            return;
        }

        $applied     = self::get_applied();
        $removed_ids = $this->get_removed_ids();

        echo '<div class="sawe-msc-credit-notice-wrap">';

        foreach ( $credits as $credit ) {
            $post_id        = (int) $credit['post']->ID;
            $applied_amount = $applied[ $post_id ] ?? 0;
            $is_removed     = in_array( $post_id, $removed_ids, true );
            $renewal        = $credit['renewal']->format( 'F j, Y' );

            printf( '<div class="sawe-msc-credit-box" data-credit-id="%d">', $post_id );

            echo '<h4 class="sawe-msc-credit-title">' . esc_html( $credit['post']->post_title ) . '</h4>';

            if ( $credit['post']->post_content ) {
                echo '<p class="sawe-msc-credit-desc">' . wp_kses_post( $credit['post']->post_content ) . '</p>';
            }

            echo '<ul class="sawe-msc-credit-details">';

            printf(
                '<li><strong>%s</strong> %s</li>',
                esc_html__( 'Available Balance:', 'sawe-msc' ),
                wc_price( $credit['balance'] )
            );

            if ( $applied_amount > 0 && ! $is_removed ) {
                printf(
                    '<li><strong>%s</strong> %s</li>',
                    esc_html__( 'Applied to this order:', 'sawe-msc' ),
                    wc_price( $applied_amount )
                );
            }

            printf(
                '<li><strong>%s</strong> %s</li>',
                esc_html__( 'Renews on:', 'sawe-msc' ),
                esc_html( $renewal )
            );

            echo '</ul>';

            if ( $applied_amount > 0 ) {
                if ( $is_removed ) {
                    printf(
                        '<button type="button" class="sawe-msc-restore-btn button" data-credit-id="%d">%s</button>',
                        $post_id,
                        esc_html__( 'Re-apply Store Credit', 'sawe-msc' )
                    );
                } else {
                    printf(
                        '<button type="button" class="sawe-msc-remove-btn button" data-credit-id="%d">%s</button>',
                        $post_id,
                        esc_html__( 'Remove Store Credit', 'sawe-msc' )
                    );
                }
            }

            echo '</div>'; // .sawe-msc-credit-box
        }

        echo '</div>'; // .sawe-msc-credit-notice-wrap
    }

    // =========================================================================
    // Qualifying cart total helper
    // =========================================================================

    /**
     * Calculate the total subtotal of all qualifying products in the cart.
     *
     * 'line_subtotal' is the pre-discount, pre-tax item total for that line.
     * Using this (rather than line_total which includes discounts) means the
     * store credit competes fairly with other applied coupons.
     *
     * For variable products: WC stores variation_id as a separate key when the
     * variation exists. We prefer variation_id over product_id so that category
     * lookups resolve against the correct term assignments.
     *
     * @param \WC_Cart $cart  The WooCommerce cart object.
     * @param array    $meta  Credit meta from get_credit_meta() — needs 'products'
     *                        and 'product_categories' keys.
     *
     * @return float  Sum in dollars. 0.0 if no qualifying items are present.
     */
    private function get_qualifying_cart_total( \WC_Cart $cart, array $meta ): float {
        $total = 0.0;

        foreach ( $cart->get_cart() as $item ) {
            // Pass the variation ID when present so that product_qualifies() can
            // check explicit variation IDs in the products list. product_qualifies()
            // itself handles the parent-ID fallback for category lookups.
            $product_id = (int) ( $item['variation_id'] ?: $item['product_id'] );

            if ( SAWE_MSC_User_Credits::product_qualifies( $product_id, $meta ) ) {
                $total += (float) $item['line_subtotal'];
            }
        }

        return $total;
    }

    // =========================================================================
    // Cart change listeners
    // =========================================================================

    /**
     * Clear the SESSION_APPLIED cache when a cart item is removed.
     *
     * WooCommerce will call calculate_totals() after removal, which will re-run
     * apply_credits_to_cart(). We clear the cache here so the next calculation
     * doesn't use stale values from before the item was removed.
     *
     * Hook: woocommerce_cart_item_removed (priority 10)
     *
     * @param string    $cart_item_key  The key of the removed cart item.
     * @param \WC_Cart  $cart           The cart object after removal.
     *
     * @return void
     */
    public function on_cart_item_removed( string $cart_item_key, \WC_Cart $cart ): void {
        WC()->session->set( self::SESSION_APPLIED, [] );
    }

    /**
     * Clear the SESSION_APPLIED cache when the cart is updated (quantity changes).
     *
     * Hook: woocommerce_update_cart_action_cart_updated
     *
     * @param bool $updated  True if the cart was actually modified.
     *
     * @return void
     */
    public function on_cart_updated( bool $updated ): void {
        if ( $updated ) {
            WC()->session->set( self::SESSION_APPLIED, [] );
        }
    }

    // =========================================================================
    // AJAX handlers
    // =========================================================================

    /**
     * AJAX handler: add a credit to the "removed" list for this session.
     *
     * The user clicked "Remove Store Credit". We add the credit's post ID to
     * SESSION_REMOVED, then tell WC to recalculate totals. The front-end JS
     * then triggers 'update_checkout' to refresh the order summary.
     *
     * Security: check_ajax_referer() verifies the nonce set in enqueue_scripts().
     * The action is registered only as wp_ajax_ (logged-in users only).
     *
     * Expected POST params:
     *   credit_id (int)   — Post ID of the sawe_store_credit to remove.
     *   nonce     (string) — Value of wp_create_nonce( 'sawe_msc_nonce' ).
     *
     * Hook: wp_ajax_sawe_msc_remove_credit
     *
     * @return void  Terminates with wp_send_json_success() or wp_send_json_error().
     */
    public function ajax_remove_credit(): void {
        check_ajax_referer( 'sawe_msc_nonce', 'nonce' );

        $post_id     = (int) ( $_POST['credit_id'] ?? 0 );
        $removed_ids = $this->get_removed_ids();

        // Avoid duplicates in the removed list.
        if ( ! in_array( $post_id, $removed_ids, true ) ) {
            $removed_ids[] = $post_id;
        }

        WC()->session->set( self::SESSION_REMOVED, $removed_ids );

        // Recalculate so SESSION_APPLIED is updated immediately (the checkout
        // JS uses update_checkout to re-render, but calculating now avoids
        // an extra round-trip).
        WC()->cart->calculate_totals();

        wp_send_json_success( [
            'message' => __( 'Store credit removed from this order.', 'sawe-msc' ),
        ] );
    }

    /**
     * AJAX handler: remove a credit from the "removed" list (re-apply it).
     *
     * The user clicked "Re-apply Store Credit". We remove the credit's post ID
     * from SESSION_REMOVED so apply_credits_to_cart() will include it again.
     *
     * Expected POST params:
     *   credit_id (int)    — Post ID of the sawe_store_credit to restore.
     *   nonce     (string) — Value of wp_create_nonce( 'sawe_msc_nonce' ).
     *
     * Hook: wp_ajax_sawe_msc_restore_credit
     *
     * @return void  Terminates with wp_send_json_success() or wp_send_json_error().
     */
    public function ajax_restore_credit(): void {
        check_ajax_referer( 'sawe_msc_nonce', 'nonce' );

        $post_id     = (int) ( $_POST['credit_id'] ?? 0 );
        $removed_ids = $this->get_removed_ids();

        // array_diff returns a new array without the given value; re-index with array_values.
        $removed_ids = array_values( array_diff( $removed_ids, [ $post_id ] ) );

        WC()->session->set( self::SESSION_REMOVED, $removed_ids );
        WC()->cart->calculate_totals();

        wp_send_json_success( [
            'message' => __( 'Store credit re-applied.', 'sawe-msc' ),
        ] );
    }

    // =========================================================================
    // Session helpers
    // =========================================================================

    /**
     * Get the list of credit post IDs the user has removed this session.
     *
     * Returns an empty array if the WC session has not been started yet
     * (e.g. called before WooCommerce initialises the session object).
     *
     * @return int[]
     */
    private function get_removed_ids(): array {
        return (array) ( WC()->session ? WC()->session->get( self::SESSION_REMOVED, [] ) : [] );
    }

    /**
     * Get the map of credit post IDs → applied discount amounts for this session.
     *
     * Public static so that SAWE_MSC_Checkout can read it without needing an
     * instance of this class. Returns an empty array if the session is not ready.
     *
     * @return array<int, float>  [ credit_post_id => discount_amount ]
     */
    public static function get_applied(): array {
        return (array) ( WC()->session ? WC()->session->get( self::SESSION_APPLIED, [] ) : [] );
    }

    /**
     * Clear both credit session keys.
     *
     * Called on 'woocommerce_thankyou' (after successful order placement) and
     * 'wp_logout' so that the user's next session starts clean.
     *
     * Also called directly by SAWE_MSC_Checkout::finalise_deductions() after
     * the DB deductions have been committed.
     *
     * @return void
     */
    public function clear_session(): void {
        if ( WC()->session ) {
            WC()->session->set( self::SESSION_APPLIED, [] );
            WC()->session->set( self::SESSION_REMOVED, [] );
        }
    }

    // =========================================================================
    // Asset enqueueing
    // =========================================================================

    /**
     * Enqueue front-end CSS and JavaScript on cart and checkout pages.
     *
     * Both assets use SAWE_MSC_VERSION as the cache-buster. When releasing a
     * new version that includes CSS/JS changes, bumping SAWE_MSC_VERSION
     * ensures browsers load the updated files.
     *
     * The JS file depends on 'jquery' and 'wc-cart'. 'wc-cart' provides the
     * wc_cart_params object and ensures cart fragment/AJAX infrastructure
     * is available before our script runs.
     *
     * Hook: wp_enqueue_scripts
     *
     * @return void
     */
    public function enqueue_scripts(): void {
        // Only load on pages where the discount UI is visible.
        if ( ! is_cart() && ! is_checkout() ) {
            return;
        }

        wp_enqueue_style(
            'sawe-msc-public',                                   // Handle
            SAWE_MSC_PLUGIN_URL . 'public/css/sawe-msc-public.css', // URL
            [],                                                   // No dependencies
            SAWE_MSC_VERSION                                      // Cache-busting version
        );

        wp_enqueue_script(
            'sawe-msc-cart',                                     // Handle
            SAWE_MSC_PLUGIN_URL . 'public/js/sawe-msc-cart.js', // URL
            [ 'jquery', 'wc-cart' ],                             // Dependencies
            SAWE_MSC_VERSION,                                    // Cache-busting version
            true                                                 // Load in footer
        );

        // Pass data to JS via wp_localize_script.
        // The object is available as window.saweMscData in the browser.
        wp_localize_script( 'sawe-msc-cart', 'saweMscData', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'sawe_msc_nonce' ),
            'i18n'    => [
                // Translatable button labels used by sawe-msc-cart.js.
                'removeLabel'  => __( 'Remove store credit', 'sawe-msc' ),
                'restoreLabel' => __( 'Re-apply store credit', 'sawe-msc' ),
            ],
        ] );
    }
}
