<?php
/**
 * Plugin Name: SAWE Membership Store Credits
 * Plugin URI:  https://southeast.sawe.org
 * Description: Provides renewable store credits to members based on WordPress roles, integrated with WooCommerce. Credits auto-apply at checkout, renew annually, and are fully configurable per role and qualifying product.
 * Version:     1.0.1
 * Author:      Southeast SAWE Chapter
 * Author URI:  https://southeast.sawe.org
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sawe-msc
 * Domain Path: /languages
 *
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * WC requires at least: 8.0
 * WC tested up to:   9.x
 *
 * @package SAWE_MSC
 *
 * ============================================================================
 * PLUGIN OVERVIEW
 * ============================================================================
 *
 * This file is the plugin entry point. It:
 *   1. Defines all global constants (paths, version, text domain).
 *   2. Declares WooCommerce HPOS compatibility.
 *   3. Defines the main bootstrap singleton class.
 *   4. Registers activation, deactivation, and uninstall hooks.
 *   5. Boots the plugin at plugins_loaded priority 5.
 *
 * All business logic lives in the classes under /includes/ and /admin/.
 * See docs/DEVELOPER-GUIDE.md for a full architecture and class reference.
 *
 * ============================================================================
 * CONSTANTS DEFINED HERE
 * ============================================================================
 *
 * SAWE_MSC_VERSION     (string) Plugin version, e.g. '1.0.0'. Bump on every release.
 *                               Also used as the cache-buster for enqueued CSS/JS.
 *
 * SAWE_MSC_PLUGIN_FILE (string) Absolute path to this file. Passed to
 *                               register_activation_hook() and used to build URLs.
 *
 * SAWE_MSC_PLUGIN_DIR  (string) Absolute path to the plugin root directory,
 *                               with trailing slash. Use for require_once includes.
 *
 * SAWE_MSC_PLUGIN_URL  (string) Full URL to the plugin root, with trailing slash.
 *                               Use for wp_enqueue_style/script src attributes.
 *
 * SAWE_MSC_TEXT_DOMAIN (string) 'sawe-msc'. All translatable strings use this domain.
 *                               .po/.mo files live in /languages/.
 */

defined( 'ABSPATH' ) || exit; // Block direct HTTP access to this file.

// ============================================================================
// Constants
// ============================================================================

/** Plugin semantic version. Bump in sync with the Version: header above. */
define( 'SAWE_MSC_VERSION',     '1.0.1' );

/** Absolute path to sawe-membership-store-credits.php (this file). */
define( 'SAWE_MSC_PLUGIN_FILE', __FILE__ );

/** Absolute path to the plugin root directory, with trailing slash. */
define( 'SAWE_MSC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );

/** Full HTTP/S URL to the plugin root directory, with trailing slash. */
define( 'SAWE_MSC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

/** Text domain string shared by all translatable strings in the plugin. */
define( 'SAWE_MSC_TEXT_DOMAIN', 'sawe-msc' );

// ============================================================================
// WooCommerce HPOS Compatibility Declaration
// ============================================================================
//
// WooCommerce 8.0+ offers High-Performance Order Storage (HPOS), which stores
// orders in custom tables instead of wp_posts. Plugins must explicitly declare
// compatibility or WC will show an admin warning. We declare TRUE (compatible)
// because we use the WC order API (get_user_id(), update_meta_data(), etc.)
// rather than raw wp_postmeta, so we work with either storage backend.
//
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', // HPOS feature slug
            __FILE__,
            true                   // true = compatible
        );
    }
} );

// ============================================================================
// Main Bootstrap Class
// ============================================================================

/**
 * SAWE_Membership_Store_Credits
 *
 * Singleton bootstrap class. Responsible for:
 *  - Requiring all class files.
 *  - Registering the plugins_loaded callbacks.
 *  - Instantiating component singletons once WooCommerce is confirmed active.
 *
 * USAGE:
 *   SAWE_Membership_Store_Credits::instance()
 *
 * DO NOT instantiate directly — use ::instance().
 * DO NOT add business logic here; put it in the appropriate component class.
 *
 * @package SAWE_MSC
 * @since   1.0.0
 */
final class SAWE_Membership_Store_Credits {

    /**
     * Singleton instance.
     *
     * @var SAWE_Membership_Store_Credits|null
     */
    private static ?self $instance = null;

    // -------------------------------------------------------------------------
    // Singleton accessor
    // -------------------------------------------------------------------------

    /**
     * Returns the single instance of this class, creating it on first call.
     *
     * Called from the plugins_loaded callback at the bottom of this file.
     *
     * @return self
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * Private constructor — use ::instance().
     *
     * Calls includes() then init_hooks() in that order so all class files
     * are loaded before any hooks are registered.
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    // -------------------------------------------------------------------------
    // File loading
    // -------------------------------------------------------------------------

    /**
     * Require all component class files.
     *
     * Files are loaded unconditionally here (before WC check) so that
     * SAWE_MSC_DB is available in the activation hook (which runs before
     * plugins_loaded in some contexts).
     *
     * Load order matters — DB must come before classes that call DB methods.
     *
     * @return void
     */
    private function includes(): void {
        // Database layer — pure static helpers, no WC dependency.
        require_once SAWE_MSC_PLUGIN_DIR . 'includes/class-sawe-msc-db.php';

        // CPT registration + meta helpers — used by almost everything else.
        require_once SAWE_MSC_PLUGIN_DIR . 'includes/class-sawe-msc-credit-post-type.php';

        // Award / revoke / renewal logic.
        require_once SAWE_MSC_PLUGIN_DIR . 'includes/class-sawe-msc-user-credits.php';

        // Cart fee injection and AJAX handlers.
        require_once SAWE_MSC_PLUGIN_DIR . 'includes/class-sawe-msc-cart.php';

        // Checkout notice display + order finalisation.
        require_once SAWE_MSC_PLUGIN_DIR . 'includes/class-sawe-msc-checkout.php';

        // My Account tab and dashboard widget.
        require_once SAWE_MSC_PLUGIN_DIR . 'includes/class-sawe-msc-account.php';

        // Admin menus, meta boxes, list columns.
        require_once SAWE_MSC_PLUGIN_DIR . 'admin/class-sawe-msc-admin.php';
    }

    // -------------------------------------------------------------------------
    // Hook registration
    // -------------------------------------------------------------------------

    /**
     * Register plugins_loaded callbacks.
     *
     * We use plugins_loaded (rather than init) so WooCommerce is guaranteed
     * to have loaded its own classes before we try to use them.
     *
     * @return void
     */
    private function init_hooks(): void {
        // Load .mo translation files from /languages/.
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );

        // Show admin notice if WooCommerce is missing.
        add_action( 'plugins_loaded', [ $this, 'check_woocommerce' ] );

        // Instantiate all component singletons (only if WC is active).
        add_action( 'plugins_loaded', [ $this, 'init_components' ] );
    }

    // -------------------------------------------------------------------------
    // Callback methods
    // -------------------------------------------------------------------------

    /**
     * Load the plugin's text domain for i18n.
     *
     * .po/.mo files must be named sawe-msc-{locale}.mo and placed in
     * wp-content/plugins/sawe-membership-store-credits/languages/.
     *
     * Hook: plugins_loaded
     *
     * @return void
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            SAWE_MSC_TEXT_DOMAIN,
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    /**
     * Check that WooCommerce is active and display an error notice if not.
     *
     * This is a soft check — the plugin is still "active" from WordPress's
     * perspective, but none of the WC-dependent components will be initialised.
     *
     * Hook: plugins_loaded
     *
     * @return void
     */
    public function check_woocommerce(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p>' .
                     esc_html__( 'SAWE Membership Store Credits requires WooCommerce to be installed and active.', 'sawe-msc' ) .
                     '</p></div>';
            } );
        }
    }

    /**
     * Instantiate all component singletons.
     *
     * This is the single place where every component class is booted.
     * Each class registers its own hooks internally in its constructor,
     * which is why we only need to call ::instance() here.
     *
     * Bails early if WooCommerce is not available.
     *
     * Hook: plugins_loaded
     *
     * @return void
     */
    public function init_components(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Register the sawe_store_credit CPT and post meta.
        SAWE_MSC_Credit_Post_Type::instance();

        // Award / sync / renew user credits (also schedules the daily cron).
        SAWE_MSC_User_Credits::instance();

        // Cart discount injection + AJAX remove/restore handlers.
        SAWE_MSC_Cart::instance();

        // Checkout notice + order finalisation + cancel restore.
        SAWE_MSC_Checkout::instance();

        // My Account tab + dashboard widget.
        SAWE_MSC_Account::instance();

        // Admin UI — only loaded on admin requests to avoid front-end overhead.
        if ( is_admin() ) {
            SAWE_MSC_Admin::instance();
        }
    }
}

// ============================================================================
// Activation Hook
// ============================================================================
//
// Runs when an admin clicks "Activate" for the plugin.
// Creates the custom DB table via dbDelta() and flushes rewrite rules so the
// My Account "store-credits" endpoint is immediately resolvable.
//
// NOTE: SAWE_MSC_DB is explicitly required here because the activation hook
// fires before plugins_loaded, so the includes() method above has not yet run.
//
register_activation_hook( __FILE__, function () {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-sawe-msc-db.php';
    SAWE_MSC_DB::create_tables();

    // Flush rewrite rules so the /store-credits/ endpoint resolves immediately.
    // SAWE_MSC_Account::add_endpoint() runs on init, which hasn't fired yet at
    // activation time, so we must add it manually here before flushing.
    add_rewrite_endpoint( 'store-credits', EP_ROOT | EP_PAGES );
    flush_rewrite_rules();
} );

// ============================================================================
// Deactivation Hook
// ============================================================================
//
// Runs when an admin clicks "Deactivate". We only flush rewrite rules to clean
// up the /store-credits/ endpoint. Data is intentionally NOT removed here —
// that is reserved for the uninstall hook so admins can reactivate without
// losing member balances.
//
register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

// NOTE: Uninstall logic lives in uninstall.php (which WordPress invokes when
// the plugin is deleted). It optionally drops the DB table depending on the
// admin setting 'sawe_msc_remove_tables_on_uninstall'.

// ============================================================================
// Bootstrap — plugins_loaded priority 5
// ============================================================================
//
// We use priority 5 (earlier than default 10) so our components are ready
// before any third-party code that might hook at default priority and expect
// our CPT or session keys to be available.
//
add_action( 'plugins_loaded', function () {
    SAWE_Membership_Store_Credits::instance();
}, 5 );
