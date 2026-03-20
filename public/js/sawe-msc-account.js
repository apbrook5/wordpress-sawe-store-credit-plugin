/**
 * SAWE Store Credits — My Account JavaScript
 *
 * Provides client-side filtering for the "Available Store Credits" tab.
 * Typing in the search box shows only cards whose title or description
 * contains the query (case-insensitive). A "no results" notice is shown
 * when nothing matches.
 *
 * ============================================================================
 * DEPENDENCIES
 * ============================================================================
 *
 * Registered handle:  sawe-msc-account
 * Script dependencies: (none — vanilla JS only)
 *
 * Enqueued by: SAWE_MSC_Account::enqueue_scripts() (My Account pages only)
 *
 * ============================================================================
 * DOM STRUCTURE ASSUMED
 * ============================================================================
 *
 *  <input id="sawe-msc-credit-search" …>
 *  <div class="sawe-msc-credit-notice-wrap">
 *    <div class="sawe-msc-credit-box">
 *      <h4 class="sawe-msc-credit-title">…</h4>
 *      <p  class="sawe-msc-credit-desc">…</p>    ← optional
 *      …
 *    </div>
 *    …
 *    <p id="sawe-msc-credit-no-results" …>…</p>  ← injected by this script
 *  </div>
 */

( function () {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {
        var input = document.getElementById( 'sawe-msc-credit-search' );
        if ( ! input ) {
            return;
        }

        var wrap  = document.querySelector( '.sawe-msc-credit-notice-wrap' );
        var cards = wrap ? wrap.querySelectorAll( '.sawe-msc-credit-box' ) : [];

        if ( ! cards.length ) {
            return;
        }

        // Inject a hidden "no results" paragraph at the end of the wrap.
        var noResults = document.createElement( 'p' );
        noResults.id        = 'sawe-msc-credit-no-results';
        noResults.className = 'sawe-msc-credit-no-results';
        noResults.textContent = input.getAttribute( 'data-no-results' ) || 'No credits match your search.';
        noResults.style.display = 'none';
        wrap.appendChild( noResults );

        input.addEventListener( 'input', function () {
            var query   = input.value.trim().toLowerCase();
            var visible = 0;

            cards.forEach( function ( card ) {
                var title = ( card.querySelector( '.sawe-msc-credit-title' ) || {} ).textContent || '';
                var desc  = ( card.querySelector( '.sawe-msc-credit-desc'  ) || {} ).textContent || '';
                var match = ! query || title.toLowerCase().indexOf( query ) !== -1
                                    || desc.toLowerCase().indexOf( query )  !== -1;

                card.style.display = match ? '' : 'none';
                if ( match ) {
                    visible++;
                }
            } );

            noResults.style.display = visible === 0 ? '' : 'none';
        } );
    } );
} )();
