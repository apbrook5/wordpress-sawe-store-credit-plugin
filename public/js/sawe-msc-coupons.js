/**
 * SAWE Coupons — Cart / Checkout JavaScript
 *
 * Handles the "Apply Coupon" and "Remove Coupon" button clicks rendered by
 * SAWE_MSC_Coupons::display_coupon_notices() on the cart and checkout pages.
 *
 * ============================================================================
 * DEPENDENCIES
 * ============================================================================
 *
 * Registered handle:  sawe-msc-coupons
 * Script dependencies: jQuery, wc-cart
 *   - jQuery   — DOM manipulation and $.ajax.
 *   - wc-cart  — Ensures WooCommerce cart/checkout AJAX infrastructure
 *                (fragment loading, update_checkout events) is available.
 *
 * Enqueued by: SAWE_MSC_Coupons::enqueue_scripts() (cart + checkout pages only)
 *
 * ============================================================================
 * LOCALISED DATA  (window.saweMscCouponData)
 * ============================================================================
 *
 *   saweMscCouponData.ajaxUrl       (string) — wp-admin/admin-ajax.php
 *   saweMscCouponData.nonce         (string) — wp_create_nonce('sawe_msc_nonce')
 *   saweMscCouponData.i18n.applyLabel    — "Apply Coupon"
 *   saweMscCouponData.i18n.reapplyLabel  — "Re-apply Coupon"
 *   saweMscCouponData.i18n.removeLabel   — "Remove Coupon"
 *   saweMscCouponData.i18n.applyingLabel — "Applying…"
 *   saweMscCouponData.i18n.removingLabel — "Removing…"
 *
 * ============================================================================
 * SERVER-SIDE HANDLERS
 * ============================================================================
 *
 * Apply:  wp_ajax_sawe_msc_apply_coupon  → SAWE_MSC_Coupons::ajax_apply_coupon()
 * Remove: wp_ajax_sawe_msc_remove_coupon → SAWE_MSC_Coupons::ajax_remove_coupon()
 *
 * Both handlers verify the nonce, update the WC session + cart, then respond
 * with wp_send_json_success() so this script can trigger a WC refresh.
 *
 * ============================================================================
 * WHY DELEGATED EVENTS?
 * ============================================================================
 *
 * WooCommerce replaces the cart/checkout DOM on every AJAX update, so event
 * listeners bound directly to buttons are lost after each refresh.  Binding
 * to document.body with delegated selectors keeps the listeners alive across
 * DOM replacements.
 *
 * @since 1.1.0
 */

/* global jQuery, saweMscCouponData */
( function ( $ ) {
    'use strict';

    // =========================================================================
    // "Apply Coupon" button
    // =========================================================================

    /**
     * Delegated click handler for .sawe-msc-coupon-apply-btn.
     *
     * Reads the coupon code from data-coupon-code, disables the button to
     * prevent double-clicks, then POSTs to sawe_msc_apply_coupon.
     * On success: triggers WC cart/checkout refresh.
     * On error:   re-enables the button with the original label.
     */
    $( document.body ).on( 'click', '.sawe-msc-coupon-apply-btn', function () {
        var $btn = $( this );
        var code = $btn.data( 'coupon-code' );

        $btn.prop( 'disabled', true ).text( saweMscCouponData.i18n.applyingLabel );

        $.ajax( {
            url:  saweMscCouponData.ajaxUrl,
            type: 'POST',
            data: {
                action:      'sawe_msc_apply_coupon',
                nonce:       saweMscCouponData.nonce,
                coupon_code: code,
            },
            success: function () {
                // Trigger WC to recalculate totals and re-render the UI.
                $( document.body ).trigger( 'update_checkout' );
                $( document.body ).trigger( 'wc_update_cart' );
            },
            error: function () {
                // Restore button so the user can retry.
                $btn.prop( 'disabled', false ).text( saweMscCouponData.i18n.applyLabel );
            },
        } );
    } );


    // =========================================================================
    // "Remove Coupon" button
    // =========================================================================

    /**
     * Delegated click handler for .sawe-msc-coupon-remove-btn.
     *
     * Mirrors the apply handler above but POSTs to sawe_msc_remove_coupon.
     * The server removes the coupon from the WC cart and adds it to the
     * SESSION_COUPON_REMOVED list, so auto-apply will skip it this session.
     */
    $( document.body ).on( 'click', '.sawe-msc-coupon-remove-btn', function () {
        var $btn = $( this );
        var code = $btn.data( 'coupon-code' );

        $btn.prop( 'disabled', true ).text( saweMscCouponData.i18n.removingLabel );

        $.ajax( {
            url:  saweMscCouponData.ajaxUrl,
            type: 'POST',
            data: {
                action:      'sawe_msc_remove_coupon',
                nonce:       saweMscCouponData.nonce,
                coupon_code: code,
            },
            success: function () {
                $( document.body ).trigger( 'update_checkout' );
                $( document.body ).trigger( 'wc_update_cart' );
            },
            error: function () {
                $btn.prop( 'disabled', false ).text( saweMscCouponData.i18n.removeLabel );
            },
        } );
    } );

} )( jQuery );
