/**
 * SAWE Membership Store Credits — Cart / Checkout Blocks JavaScript
 *
 * Block-page counterpart of sawe-msc-cart.js and sawe-msc-coupons.js. Handles
 * the store credit and coupon buttons, and keeps the notice boxes current,
 * on the WooCommerce Cart and Checkout *blocks*. Never loaded on the classic
 * shortcode pages.
 *
 * ============================================================================
 * DEPENDENCIES
 * ============================================================================
 *
 * Registered handle:  sawe-msc-blocks
 * Script dependencies: wp-data, plus wc-blocks-data-store and
 *                      wc-blocks-checkout when registered.
 *   - wp.data                         — read / subscribe to the block cart store.
 *   - wc.blocksCheckout.extensionCartUpdate — POST to the Store API so the
 *                                       block refreshes its totals itself.
 *
 * Enqueued by: SAWE_MSC_Blocks::enqueue_scripts() (block cart + checkout only)
 *
 * ============================================================================
 * LOCALISED DATA  (window.saweMscBlocksData)
 * ============================================================================
 *
 *   saweMscBlocksData.namespace     (string) — Store API namespace ('sawe-msc')
 *   saweMscBlocksData.context       (string) — block notice context ('wc/cart' | 'wc/checkout')
 *   saweMscBlocksData.i18n.working  (string) — "Updating…"
 *   saweMscBlocksData.i18n.error    (string) — generic error message
 *
 * ============================================================================
 * HOW IT WORKS
 * ============================================================================
 *
 * PHP renders the notice boxes into .sawe-msc-blocks-notices (just above the
 * block) and also adds the same HTML to every Store API cart response as
 * extensions['sawe-msc'].notices_html. Whenever the block's cart store
 * changes, we swap that HTML in — so the boxes update after our own buttons,
 * quantity changes, and native coupon changes alike.
 *
 * Button clicks call extensionCartUpdate(), handled server-side by
 * SAWE_MSC_Blocks::handle_cart_update(). The response is the updated cart,
 * which the block applies to its totals and which triggers the swap above.
 *
 * @since 1.2.4
 */

/* global saweMscBlocksData */
( function () {
    'use strict';

    var CART_STORE = 'wc/store/cart';
    var NOTICE_ID  = 'sawe-msc-error';
    var data       = window.saweMscBlocksData || {};
    var i18n       = data.i18n || {};

    // Button class → Store API action. Classes match the PHP markup shared
    // with the classic pages.
    var ACTIONS = {
        'sawe-msc-remove-btn':        'remove_credit',
        'sawe-msc-restore-btn':       'restore_credit',
        'sawe-msc-coupon-apply-btn':  'apply_coupon',
        'sawe-msc-coupon-remove-btn': 'remove_coupon',
    };

    // =========================================================================
    // Keep the notice boxes in sync with the block cart store
    // =========================================================================

    var lastHtml = null;

    /**
     * Read our notice HTML from the cart store, or null if the store or our
     * extension data isn't available yet.
     *
     * @return {string|null}
     */
    function getNoticesHtml() {
        if ( ! window.wp || ! window.wp.data ) {
            return null;
        }

        var store = window.wp.data.select( CART_STORE );
        if ( ! store || 'function' !== typeof store.getCartData ) {
            return null;
        }

        var cart = store.getCartData();
        var ext  = cart && cart.extensions && cart.extensions[ data.namespace ];

        return ext && 'string' === typeof ext.notices_html ? ext.notices_html : null;
    }

    /**
     * Replace the contents of every notice container when the HTML changes.
     *
     * @return {void}
     */
    function render() {
        var html = getNoticesHtml();
        if ( null === html || html === lastHtml ) {
            return;
        }

        lastHtml = html;

        document.querySelectorAll( '.sawe-msc-blocks-notices' ).forEach( function ( el ) {
            el.innerHTML = html;
        } );
    }

    // =========================================================================
    // Button clicks
    // =========================================================================

    /**
     * Show an error in the block's own notice area.
     *
     * @param {string} message
     *
     * @return {void}
     */
    function showError( message ) {
        var notices = window.wp && window.wp.data && window.wp.data.dispatch( 'core/notices' );
        if ( notices ) {
            notices.createErrorNotice( message, { id: NOTICE_ID, context: data.context } );
        }
    }

    /**
     * Clear an error shown by showError().
     *
     * @return {void}
     */
    function clearError() {
        var notices = window.wp && window.wp.data && window.wp.data.dispatch( 'core/notices' );
        if ( notices ) {
            notices.removeNotice( NOTICE_ID, data.context );
        }
    }

    /**
     * Delegated click handler for the notice box buttons. Delegated because
     * render() replaces the buttons whenever the cart changes.
     */
    document.addEventListener( 'click', function ( event ) {
        var btn = event.target.closest( '.sawe-msc-blocks-notices button' );
        if ( ! btn || btn.disabled ) {
            return;
        }

        var action = null;
        Object.keys( ACTIONS ).forEach( function ( cls ) {
            if ( btn.classList.contains( cls ) ) {
                action = ACTIONS[ cls ];
            }
        } );

        var blocksCheckout = window.wc && window.wc.blocksCheckout;
        if ( ! action || ! blocksCheckout || 'function' !== typeof blocksCheckout.extensionCartUpdate ) {
            return;
        }

        event.preventDefault();

        var label = btn.textContent;

        // Prevent double-clicks while the request is in flight.
        btn.disabled    = true;
        btn.textContent = i18n.working || label;

        clearError();

        blocksCheckout.extensionCartUpdate( {
            namespace: data.namespace,
            data: {
                action:      action,
                credit_id:   parseInt( btn.getAttribute( 'data-credit-id' ), 10 ) || 0,
                coupon_code: btn.getAttribute( 'data-coupon-code' ) || '',
            },
        } ).then( function () {
            // The cart store now holds the updated cart. render() normally runs
            // via the subscription below; call it directly in case the HTML is
            // unchanged and the button needs re-enabling.
            lastHtml = null;
            render();
        } ).catch( function ( error ) {
            btn.disabled    = false;
            btn.textContent = label;
            showError( ( error && error.message ) || i18n.error );
        } );
    } );

    // =========================================================================
    // Start
    // =========================================================================

    if ( window.wp && window.wp.data && 'function' === typeof window.wp.data.subscribe ) {
        window.wp.data.subscribe( render );
    }

    render();
} )();
