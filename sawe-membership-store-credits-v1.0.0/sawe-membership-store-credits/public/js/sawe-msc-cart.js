/**
 * SAWE Membership Store Credits — Cart / Checkout JavaScript
 *
 * Handles the "Remove Store Credit" and "Re-apply Store Credit" button clicks
 * on the WooCommerce cart and checkout pages.
 *
 * ============================================================================
 * DEPENDENCIES
 * ============================================================================
 *
 * Registered handle:  sawe-msc-cart
 * Script dependencies: jQuery, wc-cart
 *   - jQuery    — DOM manipulation and $.ajax.
 *   - wc-cart   — Ensures WooCommerce's cart/checkout AJAX infrastructure
 *                 (fragment loading, update_checkout events) is available before
 *                 our script runs.
 *
 * Enqueued by: SAWE_MSC_Cart::enqueue_scripts()  (only on cart + checkout pages)
 *
 * ============================================================================
 * LOCALISED DATA  (window.saweMscData)
 * ============================================================================
 *
 * PHP passes the following object via wp_localize_script():
 *
 *   saweMscData.ajaxUrl       (string) — URL of wp-admin/admin-ajax.php
 *   saweMscData.nonce         (string) — wp_create_nonce('sawe_msc_nonce')
 *   saweMscData.i18n.removeLabel  (string) — "Remove store credit" (translatable)
 *   saweMscData.i18n.restoreLabel (string) — "Re-apply store credit" (translatable)
 *
 * ============================================================================
 * WHY DELEGATED EVENTS?
 * ============================================================================
 *
 * WooCommerce uses AJAX to reload the checkout order summary and cart totals
 * after every change. This replaces the DOM elements that contain our buttons,
 * meaning event listeners attached directly to button elements would be lost
 * after each reload.
 *
 * By binding to document.body with delegated selectors, the listener survives
 * DOM replacements — it watches for clicks that BUBBLE UP to document.body
 * and match the selector at event time, not at bind time.
 *
 * ============================================================================
 * SERVER-SIDE HANDLERS
 * ============================================================================
 *
 * Remove:   wp_ajax_sawe_msc_remove_credit  → SAWE_MSC_Cart::ajax_remove_credit()
 * Restore:  wp_ajax_sawe_msc_restore_credit → SAWE_MSC_Cart::ajax_restore_credit()
 *
 * Both handlers check the nonce (check_ajax_referer) and update the WC session,
 * then respond with wp_send_json_success() so we can trigger WC refresh.
 *
 * ============================================================================
 * WC REFRESH EVENTS
 * ============================================================================
 *
 * update_checkout   — Tells WC to re-run checkout AJAX and refresh the order
 *                     summary, payment methods, and shipping totals.
 * wc_update_cart    — Tells WC to reload cart fragment HTML (used on the cart
 *                     page where update_checkout is not available).
 *
 * Triggering both covers both pages safely.
 *
 * @since 1.0.0
 */

/* global jQuery, saweMscData */
( function ( $ ) {
    'use strict';

    // =========================================================================
    // "Remove Store Credit" button
    // =========================================================================

    /**
     * Delegated click handler for .sawe-msc-remove-btn.
     *
     * Workflow:
     *  1. Disable the button and show a loading indicator (…).
     *  2. POST to wp-ajax with action=sawe_msc_remove_credit.
     *  3. On success: trigger WC cart/checkout refresh (the server has already
     *     updated SESSION_REMOVED, so the next fee calculation will skip this credit).
     *  4. On error: re-enable the button so the user can try again.
     *
     * The button's data-credit-id attribute is set by PHP from the post ID and
     * is the identifier passed to the AJAX handler.
     */
    $( document.body ).on( 'click', '.sawe-msc-remove-btn', function () {
        var $btn     = $( this );
        var creditId = parseInt( $btn.data( 'credit-id' ), 10 );

        // Prevent double-clicks while the request is in flight.
        $btn.prop( 'disabled', true ).text( saweMscData.i18n.removeLabel + '\u2026' );

        $.ajax( {
            url:  saweMscData.ajaxUrl,
            type: 'POST',
            data: {
                action:    'sawe_msc_remove_credit', // wp_ajax_ hook suffix
                nonce:     saweMscData.nonce,         // check_ajax_referer value
                credit_id: creditId,
            },
            success: function () {
                // Ask WC to recalculate totals and re-render the checkout/cart UI.
                // The updated SESSION_REMOVED means the credit fee will not appear
                // in the next fee calculation pass.
                $( document.body ).trigger( 'update_checkout' );
                $( document.body ).trigger( 'wc_update_cart' );
            },
            error: function () {
                // Restore the button so the user can retry.
                $btn.prop( 'disabled', false ).text( saweMscData.i18n.removeLabel );
            },
        } );
    } );


    // =========================================================================
    // "Re-apply Store Credit" button
    // =========================================================================

    /**
     * Delegated click handler for .sawe-msc-restore-btn.
     *
     * Mirrors the remove handler above, but POSTs to sawe_msc_restore_credit.
     * The server removes the credit's post ID from SESSION_REMOVED, so the next
     * fee calculation will re-include this credit.
     */
    $( document.body ).on( 'click', '.sawe-msc-restore-btn', function () {
        var $btn     = $( this );
        var creditId = parseInt( $btn.data( 'credit-id' ), 10 );

        $btn.prop( 'disabled', true ).text( saweMscData.i18n.restoreLabel + '\u2026' );

        $.ajax( {
            url:  saweMscData.ajaxUrl,
            type: 'POST',
            data: {
                action:    'sawe_msc_restore_credit',
                nonce:     saweMscData.nonce,
                credit_id: creditId,
            },
            success: function () {
                $( document.body ).trigger( 'update_checkout' );
                $( document.body ).trigger( 'wc_update_cart' );
            },
            error: function () {
                $btn.prop( 'disabled', false ).text( saweMscData.i18n.restoreLabel );
            },
        } );
    } );

} )( jQuery );
