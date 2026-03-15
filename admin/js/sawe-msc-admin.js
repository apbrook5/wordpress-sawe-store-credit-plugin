/**
 * SAWE Membership Store Credits — Admin JavaScript
 *
 * Powers the tag-chip list managers on the sawe_store_credit post edit screen.
 * A "tag-chip list manager" is the UI pattern used for the Eligible Roles,
 * Qualifying Product Categories, and Qualifying Individual Products fields:
 *
 *   [ chip A × ]  [ chip B × ]  [ chip C × ]
 *   ┌────────────────────────────────┐  [ Add Role ]
 *   │ — Add a role —            ▼  │
 *   └────────────────────────────────┘
 *
 * ============================================================================
 * DEPENDENCIES
 * ============================================================================
 *
 * Registered handle: sawe-msc-admin
 * Script dependencies: jQuery
 * Enqueued by: SAWE_MSC_Admin::enqueue_scripts() — sawe_store_credit screens only.
 *
 * ============================================================================
 * DATA MODEL
 * ============================================================================
 *
 * The `managers` object maps each list manager's `data-target` attribute value
 * to its DOM configuration. This is the single place to update if you add a
 * new list-manager field to the meta box.
 *
 *   managers[target].listId     — ID of the .sawe-msc-selected-list container div.
 *   managers[target].dropdownId — ID of the <select> dropdown.
 *   managers[target].inputName  — The name="" attribute for the hidden <input>
 *                                 that carries the value on form submit.
 *
 * ============================================================================
 * HOW PHP AND JS STAY IN SYNC
 * ============================================================================
 *
 * PHP renders existing chips on page load (from saved post meta).
 * JS handles adding and removing chips after page load.
 * On form submit, the hidden <input> elements inside each chip provide the
 * submitted values — PHP reads them as $_POST['sawe_msc_roles'][] etc.
 *
 * There is intentionally no AJAX in this file. All data is sent as a normal
 * form POST when the admin saves the post.
 *
 * ============================================================================
 * ADDING A NEW LIST MANAGER
 * ============================================================================
 *
 * 1. Add a new entry to the `managers` object below.
 * 2. Add the corresponding PHP HTML in SAWE_MSC_Admin::render_settings_metabox()
 *    with matching IDs and a data-target attribute on the "Add" button.
 * 3. Add sanitisation in SAWE_MSC_Admin::save_meta_boxes().
 * 4. Register the meta key in SAWE_MSC_Credit_Post_Type::register_meta().
 *
 * @since 1.0.0
 */

/* global jQuery */
( function ( $ ) {
    'use strict';

    // =========================================================================
    // Manager configuration
    // =========================================================================

    /**
     * Maps each list manager's `data-target` value to its DOM element IDs
     * and form input name.
     *
     * To add a new manager, add a new key here and the corresponding HTML in
     * the PHP meta box template (render_settings_metabox).
     *
     * @type {Object.<string, {listId: string, dropdownId: string, inputName: string}>}
     */
    var managers = {

        /**
         * Eligible Member Roles manager.
         * Saves to $_POST['sawe_msc_roles'][]
         */
        roles: {
            listId:    'roles-list',
            dropdownId:'roles-dropdown',
            inputName: 'sawe_msc_roles[]',
        },

        /**
         * Qualifying Product Categories manager.
         * Saves to $_POST['sawe_msc_product_categories'][]
         */
        cats: {
            listId:    'cats-list',
            dropdownId:'cats-dropdown',
            inputName: 'sawe_msc_product_categories[]',
        },

        /**
         * Qualifying Individual Products manager.
         * Saves to $_POST['sawe_msc_products'][]
         */
        products: {
            listId:    'products-list',
            dropdownId:'products-dropdown',
            inputName: 'sawe_msc_products[]',
        },
    };


    // =========================================================================
    // Add chip — "Add Role / Category / Product" button click
    // =========================================================================

    /**
     * Delegated click handler for all .sawe-msc-add-tag-btn buttons.
     *
     * Steps:
     *  1. Read the `data-target` attribute to identify which manager was clicked.
     *  2. Look up the dropdown's currently selected value and label.
     *  3. Bail if nothing is selected (empty placeholder option).
     *  4. Bail if this value is already in the chip list (prevent duplicates).
     *  5. Build a new chip <span> with the label, × button, and hidden input.
     *  6. Append to the chip list and reset the dropdown to the placeholder.
     *
     * HTML structure of an appended chip:
     *   <span class="sawe-msc-tag" data-value="{value}">
     *     {label}
     *     <button type="button" class="sawe-msc-remove-tag" aria-label="Remove">×</button>
     *     <input type="hidden" name="{inputName}" value="{value}">
     *   </span>
     *
     * The `data-value` attribute on the chip is used by the duplicate-check
     * selector: [data-value="..."].
     *
     * Security: both the value and label are passed through escAttr/escHtml
     * before being injected into the DOM as HTML strings.
     */
    $( document ).on( 'click', '.sawe-msc-add-tag-btn', function () {
        var target = $( this ).data( 'target' );
        var cfg    = managers[ target ];

        // Guard: unknown data-target (config not registered above).
        if ( ! cfg ) {
            return;
        }

        var $select = $( '#' + cfg.dropdownId );
        var val     = $select.val();
        var label   = $select.find( ':selected' ).data( 'label' );

        // Guard: nothing selected (user clicked Add without choosing an item).
        if ( ! val ) {
            return;
        }

        var $list = $( '#' + cfg.listId );

        // Guard: duplicate — this value is already in the chip list.
        if ( $list.find( '[data-value="' + val + '"]' ).length ) {
            return;
        }

        // Build the chip HTML. Values are escaped before DOM injection.
        var $tag = $(
            '<span class="sawe-msc-tag" data-value="' + escAttr( val ) + '">' +
                escHtml( label ) +
                '<button type="button" class="sawe-msc-remove-tag" aria-label="Remove">\u00d7</button>' +
                '<input type="hidden" name="' + escAttr( cfg.inputName ) + '" value="' + escAttr( val ) + '">' +
            '</span>'
        );

        $list.append( $tag );

        // Reset the dropdown to the placeholder "— Add a …—" option.
        $select.val( '' );
    } );


    // =========================================================================
    // Remove chip — × button click inside a chip
    // =========================================================================

    /**
     * Delegated click handler for all .sawe-msc-remove-tag buttons.
     *
     * Finds the closest .sawe-msc-tag ancestor and removes it from the DOM.
     * Because the hidden <input> lives inside the chip, removing the chip
     * also removes the input, so the value will not be submitted on save.
     *
     * No AJAX needed — the change takes effect when the admin saves the post.
     */
    $( document ).on( 'click', '.sawe-msc-remove-tag', function () {
        $( this ).closest( '.sawe-msc-tag' ).remove();
    } );


    // =========================================================================
    // HTML escape helpers
    // =========================================================================

    /**
     * Escape a string for safe insertion as HTML text content.
     *
     * Replaces & < > " with their HTML entity equivalents.
     * Used for the chip label (visible text).
     *
     * @param  {*}      str  Value to escape (coerced to string).
     * @return {string}      HTML-safe string.
     */
    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;'  )
            .replace( /</g, '&lt;'   )
            .replace( />/g, '&gt;'   )
            .replace( /"/g, '&quot;' );
    }

    /**
     * Escape a string for safe use inside an HTML attribute value.
     *
     * Delegates to escHtml — for attribute contexts enclosed in double-quotes,
     * the same four entities cover all injection vectors.
     *
     * Used for: data-value=, name=, value= attributes in chip HTML.
     *
     * @param  {*}      str  Value to escape.
     * @return {string}      Attribute-safe string.
     */
    function escAttr( str ) {
        return escHtml( str );
    }

} )( jQuery );
