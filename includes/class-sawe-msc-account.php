<?php
/**
 * WooCommerce My Account Integration
 *
 * Adds store credit visibility to two locations on the WooCommerce My Account
 * pages:
 *
 *   1. A dedicated "Available Store Credits" tab (full detail view).
 *   2. A compact summary widget on the My Account dashboard.
 *
 * ============================================================================
 * MY ACCOUNT ENDPOINT
 * ============================================================================
 *
 * WooCommerce uses rewrite endpoints to create sub-pages under the My Account
 * page (e.g. /my-account/orders/, /my-account/downloads/). We register a
 * custom endpoint 'store-credits' using the same mechanism.
 *
 * After activating (or re-saving permalinks), the URL:
 *   https://example.com/my-account/store-credits/
 * ...will render the endpoint_content() callback inside the My Account template.
 *
 * IMPORTANT: After this class is first loaded (i.e. after plugin activation),
 * rewrite rules must be flushed for the endpoint to resolve correctly. The
 * plugin's activation hook does this automatically. If the URL returns 404,
 * go to Settings → Permalinks and click Save Changes.
 *
 * ============================================================================
 * COMPACT vs. FULL VIEW
 * ============================================================================
 *
 * render_credit_card() accepts a $compact parameter:
 *   - $compact = false (full view, tab page): shows name, description, balance, renewal.
 *   - $compact = true  (dashboard widget): shows name, balance, renewal only.
 *     The description is omitted to keep the dashboard tidy.
 *
 * @package SAWE_MSC
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class SAWE_MSC_Account {

    /**
     * The WooCommerce rewrite endpoint slug.
     *
     * This becomes the URL segment: /my-account/store-credits/
     * It is also used to build the WC action hook name:
     *   'woocommerce_account_store-credits_endpoint'
     *
     * To change the URL slug, update this constant and flush rewrite rules.
     *
     * @var string
     */
    const ENDPOINT = 'store-credits';

    /**
     * Singleton instance.
     *
     * @var SAWE_MSC_Account|null
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
        // Register the rewrite endpoint with WordPress.
        add_action( 'init', [ $this, 'add_endpoint' ] );

        // Make WordPress aware of our endpoint as a valid query variable.
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );

        // Add our tab to the My Account navigation menu.
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );

        // Register the callback that renders the tab's page content.
        // WC dynamically constructs this action name from the endpoint slug.
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'endpoint_content' ] );

        // Also show a compact credit summary on the main My Account dashboard page.
        add_action( 'woocommerce_account_dashboard', [ $this, 'dashboard_widget' ] );

        // Load our public CSS on any My Account page (tab or dashboard).
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );

        // Load our public JS on any My Account page (search filter etc.).
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    // =========================================================================
    // Endpoint registration
    // =========================================================================

    /**
     * Register the 'store-credits' rewrite endpoint.
     *
     * EP_ROOT | EP_PAGES means the endpoint can be appended to the root
     * and to any page URL — which is how My Account pages work in WC.
     *
     * After calling this, WordPress needs its rewrite rules flushed before
     * the URL becomes resolvable. The plugin activation hook does this.
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
     * Without this, WordPress won't recognise 'store-credits' as a valid
     * query variable and will return a 404 for the endpoint URL.
     *
     * Filter: query_vars
     *
     * @param array $vars  Existing registered query variable names.
     *
     * @return array  Updated list with 'store-credits' appended.
     */
    public function add_query_vars( array $vars ): array {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    /**
     * Insert "Available Store Credits" into the My Account navigation menu.
     *
     * WooCommerce builds its My Account sidebar nav from this associative array
     * where the key is the endpoint slug (or a custom identifier) and the value
     * is the menu label.
     *
     * We insert our item just before "Logout" to keep logout visually last.
     *
     * Filter: woocommerce_account_menu_items
     *
     * @param array $items  Existing menu items: [ endpoint_slug => label, ... ]
     *
     * @return array  Updated menu items with our tab inserted before logout.
     */
    public function add_menu_item( array $items ): array {
        // Temporarily hold the logout item so we can append it last.
        $logout = [];
        if ( isset( $items['customer-logout'] ) ) {
            $logout = [ 'customer-logout' => $items['customer-logout'] ];
            unset( $items['customer-logout'] );
        }

        // Add our tab.
        $items[ self::ENDPOINT ] = __( 'Available Store Credits', 'sawe-msc' );

        // Re-append logout at the end.
        return array_merge( $items, $logout );
    }

    // =========================================================================
    // Tab page content
    // =========================================================================

    /**
     * Render the full "Available Store Credits" tab page inside My Account.
     *
     * This callback is triggered by WooCommerce when the 'store-credits'
     * endpoint is detected in the URL. WC wraps it in the My Account template
     * (sidebar nav + content area).
     *
     * Renders:
     *   - A heading.
     *   - One .sawe-msc-credit-box per credit (full view, with description).
     *   - A "no credits available" message if the user has none.
     *
     * Hook: woocommerce_account_store-credits_endpoint
     *
     * @return void
     */
    public function endpoint_content(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $credits = SAWE_MSC_User_Credits::get_active_credits_for_user( $user_id );

        echo '<h3>' . esc_html__( 'Available Store Credits', 'sawe-msc' ) . '</h3>';

        if ( empty( $credits ) ) {
            echo '<p>' . esc_html__( 'You currently have no store credits available.', 'sawe-msc' ) . '</p>';
            return;
        }

        printf(
            '<input type="search" id="sawe-msc-credit-search" class="sawe-msc-credit-search" placeholder="%s" data-no-results="%s">',
            esc_attr__( 'Search credits…', 'sawe-msc' ),
            esc_attr__( 'No credits match your search.', 'sawe-msc' )
        );

        echo '<div class="sawe-msc-credit-notice-wrap">';
        foreach ( $credits as $credit ) {
            // $compact = false: show full detail including description.
            $this->render_credit_card( $credit, false );
        }
        echo '</div>';
    }

    // =========================================================================
    // Dashboard widget
    // =========================================================================

    /**
     * Render a compact credit summary on the My Account dashboard page.
     *
     * The dashboard is the first page the user sees after logging in via
     * My Account. This widget gives them an at-a-glance balance view without
     * having to navigate to the dedicated tab.
     *
     * Hidden entirely if the user has no credit rows.
     *
     * Hook: woocommerce_account_dashboard
     *
     * @return void
     */
    public function dashboard_widget(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $credits = SAWE_MSC_User_Credits::get_active_credits_for_user( $user_id );

        if ( empty( $credits ) ) {
            return;
        }

        echo '<h3>' . esc_html__( 'Your Store Credits', 'sawe-msc' ) . '</h3>';
        echo '<div class="sawe-msc-credit-notice-wrap">';

        foreach ( $credits as $credit ) {
            // $compact = true: omit description to keep dashboard concise.
            $this->render_credit_card( $credit, true );
        }

        echo '</div>';
    }

    // =========================================================================
    // Shared render helper
    // =========================================================================

    /**
     * Render a single .sawe-msc-credit-box card.
     *
     * Shared by both endpoint_content() (full view) and dashboard_widget()
     * (compact view). The only difference is whether the post_content
     * (description) is rendered.
     *
     * Output:
     *   <div class="sawe-msc-credit-box">
     *     <h4 class="sawe-msc-credit-title">...</h4>
     *     [<p class="sawe-msc-credit-desc">...</p>]  ← full view only
     *     <ul class="sawe-msc-credit-details">
     *       <li>Remaining Balance: $X.XX</li>
     *       <li>Renews on: Month D, YYYY</li>
     *     </ul>
     *   </div>
     *
     * @param array $credit  One entry from get_active_credits_for_user() — must
     *                       contain 'post' (WP_Post), 'balance' (float), and
     *                       'renewal' (DateTime).
     * @param bool  $compact  If true, suppress the description paragraph.
     *
     * @return void
     */
    private function render_credit_card( array $credit, bool $compact ): void {
        $renewal = $credit['renewal']->format( 'F j, Y' ); // e.g. "May 1, 2027"

        echo '<div class="sawe-msc-credit-box">';

        echo '<h4 class="sawe-msc-credit-title">' . esc_html( $credit['post']->post_title ) . '</h4>';

        // Description: the post body text. Shown only in full (non-compact) view.
        if ( ! $compact && $credit['post']->post_content ) {
            echo '<p class="sawe-msc-credit-desc">' . wp_kses_post( $credit['post']->post_content ) . '</p>';
        }

        echo '<ul class="sawe-msc-credit-details">';

        printf(
            '<li><strong>%s</strong> %s</li>',
            esc_html__( 'Remaining Balance:', 'sawe-msc' ),
            wc_price( $credit['balance'] )
        );

        printf(
            '<li><strong>%s</strong> %s</li>',
            esc_html__( 'Renews on:', 'sawe-msc' ),
            esc_html( $renewal )
        );

        echo '</ul>';
        echo '</div>';
    }

    // =========================================================================
    // Styles
    // =========================================================================

    /**
     * Enqueue the public CSS stylesheet on My Account pages.
     *
     * is_account_page() returns true for the main My Account page AND all
     * endpoint sub-pages (/orders/, /store-credits/, etc.).
     *
     * Hook: wp_enqueue_scripts
     *
     * @return void
     */
    public function enqueue_styles(): void {
        if ( ! is_account_page() ) {
            return;
        }

        wp_enqueue_style(
            'sawe-msc-public',
            SAWE_MSC_PLUGIN_URL . 'public/css/sawe-msc-public.css',
            [],
            SAWE_MSC_VERSION
        );
    }

    /**
     * Enqueue the public JavaScript on My Account pages.
     *
     * Provides client-side credit search/filtering on the Store Credits tab.
     *
     * Hook: wp_enqueue_scripts
     *
     * @return void
     */
    public function enqueue_scripts(): void {
        if ( ! is_account_page() ) {
            return;
        }

        wp_enqueue_script(
            'sawe-msc-account',
            SAWE_MSC_PLUGIN_URL . 'public/js/sawe-msc-account.js',
            [],
            SAWE_MSC_VERSION,
            true // load in footer
        );
    }
}
