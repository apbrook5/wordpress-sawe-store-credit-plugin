<?php
/**
 * Checkout Integration
 *
 * This class owns the three checkout-related concerns:
 *
 *   1. DISPLAY — Renders the styled store-credit notice box above the checkout
 *      form so members can see their available credits and what will be applied.
 *
 *   2. FINALISE — When the customer places an order, commits the pending
 *      discount amounts from the WC session into the DB as permanent deductions.
 *
 *   3. RESTORE — When an order is cancelled or refunded, reverses the deductions
 *      so the member gets their credit back.
 *
 * ============================================================================
 * TIMING NOTE: WHY woocommerce_checkout_order_created?
 * ============================================================================
 *
 * WooCommerce fires several hooks during the checkout process. We use
 * 'woocommerce_checkout_order_created' (introduced in WC 3.0) rather than
 * 'woocommerce_payment_complete' or 'woocommerce_order_status_processing'
 * because it fires synchronously when the order row is first created — before
 * payment processing. This ensures the deduction happens even for free orders
 * (where no payment gateway fires), orders paid by bank transfer, etc.
 *
 * The risk of double-deduction (e.g. if the user somehow submits the form
 * twice) is mitigated by clearing SESSION_APPLIED immediately after the first
 * deduction in finalise_deductions().
 *
 * ============================================================================
 * ORDER META
 * ============================================================================
 *
 * We store the deduction map on the order itself:
 *   _sawe_msc_credit_deductions => array( $credit_post_id => $amount, ... )
 *
 * This is the "receipt" that restore_on_cancel() uses. The meta is deleted
 * after a restore to prevent double-restores if the order status changes twice.
 *
 * @package SAWE_MSC
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class SAWE_MSC_Checkout {

    /**
     * Singleton instance.
     *
     * @var SAWE_MSC_Checkout|null
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
        // Display the credit notice box before the checkout form fields.
        // Priority 5: render before WC's own checkout notices (priority 10).
        add_action( 'woocommerce_before_checkout_form', [ $this, 'display_credit_notice' ], 5 );

        // Commit deductions to DB when the order row is created.
        add_action( 'woocommerce_checkout_order_created', [ $this, 'finalise_deductions' ], 10, 1 );

        // Restore balance when an order is cancelled or refunded.
        // Both hooks pass the order ID as their first argument.
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'restore_on_cancel' ] );
        add_action( 'woocommerce_order_status_refunded',  [ $this, 'restore_on_cancel' ] );
    }

    // =========================================================================
    // Display: credit notice box above checkout
    // =========================================================================

    /**
     * Render the store-credit summary notice box(es) above the checkout form.
     *
     * Outputs one .sawe-msc-credit-box per active credit. Each box shows:
     *   - Credit name (post title) and description (post content).
     *   - Current balance.
     *   - Amount being applied to this specific order (from SESSION_APPLIED).
     *   - Renewal date.
     *   - "Remove Store Credit" button (if a discount is being applied).
     *     Changes to "Re-apply Store Credit" if the user already removed it.
     *
     * All output is escaped appropriately. wc_price() is used for monetary
     * values so the currency symbol and decimal format match WC settings.
     *
     * Hook: woocommerce_before_checkout_form (priority 5)
     *
     * @return void
     */
    public function display_credit_notice(): void {
        if ( ! is_user_logged_in() ) {
            return; // Guest checkout — no credits to display.
        }

        $user_id = get_current_user_id();
        $credits = SAWE_MSC_User_Credits::get_active_credits_for_user( $user_id );

        if ( empty( $credits ) ) {
            return; // User has no credit rows at all.
        }

        // Read the amounts that apply_credits_to_cart() calculated this page load.
        $applied     = SAWE_MSC_Cart::get_applied();
        // Read which credits the user has clicked "Remove" on this session.
        $removed_ids = (array) ( WC()->session ? WC()->session->get( SAWE_MSC_Cart::SESSION_REMOVED, [] ) : [] );

        echo '<div class="sawe-msc-credit-notice-wrap">';

        foreach ( $credits as $credit ) {
            $post_id        = (int) $credit['post']->ID;
            $applied_amount = $applied[ $post_id ] ?? 0;
            $is_removed     = in_array( $post_id, $removed_ids, true );
            $renewal        = $credit['renewal']->format( 'F j, Y' ); // e.g. "May 1, 2027"

            // data-credit-id is used by the JS for the remove/restore AJAX calls.
            printf( '<div class="sawe-msc-credit-box" data-credit-id="%d">', $post_id );

            // Credit name.
            echo '<h4 class="sawe-msc-credit-title">' . esc_html( $credit['post']->post_title ) . '</h4>';

            // Credit description (member-facing body text from the post editor).
            if ( $credit['post']->post_content ) {
                echo '<p class="sawe-msc-credit-desc">' . wp_kses_post( $credit['post']->post_content ) . '</p>';
            }

            // Details list: balance, applied amount (if any), renewal date.
            echo '<ul class="sawe-msc-credit-details">';

            printf(
                '<li><strong>%s</strong> %s</li>',
                esc_html__( 'Available Balance:', 'sawe-msc' ),
                wc_price( $credit['balance'] )
            );

            // Only show "Applied to this order" if a non-zero amount was calculated
            // AND the user hasn't removed this credit.
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

            // Show action button only if there's an applicable discount.
            if ( $applied_amount > 0 ) {
                if ( $is_removed ) {
                    // User previously clicked Remove — show Re-apply button.
                    printf(
                        '<button type="button" class="sawe-msc-restore-btn button" data-credit-id="%d">%s</button>',
                        $post_id,
                        esc_html__( 'Re-apply Store Credit', 'sawe-msc' )
                    );
                } else {
                    // Credit is currently applied — show Remove button.
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
    // Finalise deductions on order creation
    // =========================================================================

    /**
     * Commit pending credit deductions to the DB when the order is placed.
     *
     * This is the single point where SESSION_APPLIED amounts become permanent
     * DB deductions. It:
     *   1. Reads the pending amounts from SESSION_APPLIED.
     *   2. Calls SAWE_MSC_DB::deduct_credit() for each credit.
     *   3. Stores the deduction map on the order as '_sawe_msc_credit_deductions'
     *      so that restore_on_cancel() can reverse them if needed.
     *   4. Clears both session keys so the next cart starts fresh.
     *
     * Guest orders (user_id == 0) are skipped — guests have no credit accounts.
     *
     * Hook: woocommerce_checkout_order_created (priority 10)
     *
     * @param \WC_Order $order  The newly created WC_Order object.
     *
     * @return void
     */
    public function finalise_deductions( \WC_Order $order ): void {
        $user_id = $order->get_user_id();

        if ( ! $user_id ) {
            return; // Guest order — no user account to deduct from.
        }

        $applied = SAWE_MSC_Cart::get_applied();

        if ( empty( $applied ) ) {
            return; // No credits were applied to this order.
        }

        foreach ( $applied as $credit_post_id => $amount ) {
            $amount = (float) $amount;
            if ( $amount <= 0 ) {
                continue;
            }

            // Deduct from the DB balance. SAWE_MSC_DB::deduct_credit() clamps to 0.
            SAWE_MSC_DB::deduct_credit( (int) $credit_post_id, (int) $user_id, $amount );

            // Accumulate deductions on the order for potential reversal.
            // We use (existing + amount) to handle edge cases where this hook
            // might be called more than once for the same order.
            $existing                         = (array) $order->get_meta( '_sawe_msc_credit_deductions', true );
            $existing[ $credit_post_id ]      = ( $existing[ $credit_post_id ] ?? 0 ) + $amount;
            $order->update_meta_data( '_sawe_msc_credit_deductions', $existing );
        }

        $order->save(); // Persist the meta to the DB.

        // Clear session keys — the cart is now committed.
        if ( WC()->session ) {
            WC()->session->set( SAWE_MSC_Cart::SESSION_APPLIED, [] );
            WC()->session->set( SAWE_MSC_Cart::SESSION_REMOVED, [] );
        }
    }

    // =========================================================================
    // Restore on cancellation or refund
    // =========================================================================

    /**
     * Restore credit balances when an order is cancelled or fully refunded.
     *
     * Reads the '_sawe_msc_credit_deductions' meta that was written by
     * finalise_deductions(), calls restore_credit() for each entry, then
     * deletes the meta to prevent the restoration from happening twice if
     * the order status changes again (e.g. cancelled → on-hold → cancelled).
     *
     * SAWE_MSC_DB::restore_credit() caps the restored balance at initial_amount,
     * so a credit that was already partially renewed before the restore won't
     * exceed its configured value.
     *
     * Hook: woocommerce_order_status_cancelled, woocommerce_order_status_refunded
     *
     * @param int $order_id  The WooCommerce order ID.
     *
     * @return void
     */
    public function restore_on_cancel( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $user_id    = $order->get_user_id();
        $deductions = (array) $order->get_meta( '_sawe_msc_credit_deductions', true );

        // Nothing to restore — either a guest order or no credits were used.
        if ( ! $user_id || empty( $deductions ) ) {
            return;
        }

        foreach ( $deductions as $credit_post_id => $amount ) {
            SAWE_MSC_DB::restore_credit( (int) $credit_post_id, (int) $user_id, (float) $amount );
        }

        // Remove the meta so a second status change doesn't double-restore.
        $order->delete_meta_data( '_sawe_msc_credit_deductions' );
        $order->save();
    }
}
