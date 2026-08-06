<?php
/**
 * Plugin Name: SAWE MembershipWorks Role Sync
 * Plugin URI:  https://github.com/apbrook5/wordpress-sawe-store-credit-plugin
 * Description: Assigns WordPress roles ('member', 'member-company') based on MembershipWorks membership status. Replaces the old "Add WordPress Roles based on MembershipWorks" code snippet with a database-backed diagnostic log, an admin screen under SAWE Coupons and Credits, and throttled MembershipWorks API checks.
 * Version:     1.2.3
 * Author:      Society of Allied Weight Engineers, Inc.
 * Author URI:  https://www.sawe.org
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sawe-mwr
 * Domain Path: /languages
 *
 * Requires at least: 6.4
 * Requires PHP:      8.0
 *
 * @package SAWE_MWR
 *
 * ============================================================================
 * PLUGIN OVERVIEW
 * ============================================================================
 *
 * This plugin is a replacement for the "Add WordPress Roles based on
 * MembershipWorks" code snippet (add-wordpress-roles-based-on-membershipworks
 * .code-snippets.php). That snippet:
 *   - Called sf_shortcode() from the MembershipWorks (memberfindme) plugin to
 *     check whether the current user is a member / corporate member.
 *   - Added or removed the 'member' and 'member-company' WordPress roles.
 *   - Stored the last check date in user meta ('sawe_check_date') to avoid
 *     checking more than once per day.
 *
 * This plugin keeps the same core membership-checking behaviour but changes
 * three things, per the SAWE request:
 *
 *   1. STORAGE  — Instead of a per-user meta timestamp, every check (success
 *      or failure) is logged to a dedicated database table,
 *      {$wpdb->prefix}sawe_mwr_check_log, containing the user ID, username,
 *      display name, last-checked date/time, and diagnostic information
 *      (the raw MembershipWorks shortcode response and/or an error message
 *      such as "sf_shortcode() function not found").
 *
 *   2. ADMIN UI — A new "MembershipWorks Sync Log" screen is added as a
 *      sub-menu under the existing "SAWE Coupons and Credits" top-level menu
 *      (slug: sawe-msc-settings, registered by the SAWE Membership Store
 *      Credits plugin). The screen is a sortable, searchable WP_List_Table
 *      that can filter by error message and links each username to that
 *      user's WordPress profile edit screen.
 *
 *   3. THROTTLING — Members are re-checked at most once every 24 hours.
 *      Non-members (or users whose status could not be confidently
 *      determined) are re-checked at most once every 5 minutes. Both
 *      intervals are enforced by comparing the current time against the
 *      last_checked_at column in the log table, so no MembershipWorks API
 *      call is made unless the relevant interval has elapsed.
 *
 * All business logic lives in the classes under /includes/ and /admin/.
 * See README.md for the full architecture reference.
 *
 * ============================================================================
 * CONSTANTS DEFINED HERE
 * ============================================================================
 *
 * SAWE_MWR_VERSION     (string) Plugin version. Bump on every release.
 * SAWE_MWR_PLUGIN_FILE (string) Absolute path to this file.
 * SAWE_MWR_PLUGIN_DIR  (string) Absolute path to the plugin root, trailing slash.
 * SAWE_MWR_PLUGIN_URL  (string) Full URL to the plugin root, trailing slash.
 * SAWE_MWR_TEXT_DOMAIN (string) 'sawe-mwr'. Shared by all translatable strings.
 * SAWE_MWR_PARENT_MENU_SLUG (string) 'sawe-msc-settings' — the top-level menu
 *                       slug registered by the SAWE Membership Store Credits
 *                       plugin ("SAWE Coupons and Credits"). This plugin adds
 *                       its diagnostics screen as a sub-menu of that slug. If
 *                       that plugin is not active, this plugin falls back to
 *                       registering its own top-level menu so the diagnostics
 *                       screen is never inaccessible.
 */

defined( 'ABSPATH' ) || exit; // Block direct HTTP access to this file.

// ============================================================================
// Constants
// ============================================================================

define( 'SAWE_MWR_VERSION',          '1.2.3' );
define( 'SAWE_MWR_PLUGIN_FILE',      __FILE__ );
define( 'SAWE_MWR_PLUGIN_DIR',       plugin_dir_path( __FILE__ ) );
define( 'SAWE_MWR_PLUGIN_URL',       plugin_dir_url( __FILE__ ) );
define( 'SAWE_MWR_TEXT_DOMAIN',      'sawe-mwr' );

/**
 * Slug of the top-level "SAWE Coupons and Credits" admin menu registered by
 * the SAWE Membership Store Credits plugin (SAWE_MSC_Admin::register_menus()).
 * Our admin screen nests under this slug so both diagnostic tools live in one
 * place. If that plugin isn't active, SAWE_MWR_Admin registers its own
 * fallback top-level menu using this same slug.
 */
define( 'SAWE_MWR_PARENT_MENU_SLUG', 'sawe-msc-settings' );

// ============================================================================
// Main Bootstrap Class
// ============================================================================

/**
 * SAWE_MembershipWorks_Role_Sync
 *
 * Singleton bootstrap class. Responsible for:
 *  - Requiring all class files.
 *  - Registering the plugins_loaded callback.
 *  - Instantiating component singletons.
 *
 * USAGE: SAWE_MembershipWorks_Role_Sync::instance()
 * DO NOT instantiate directly — use ::instance().
 *
 * @package SAWE_MWR
 * @since   1.0.0
 */
final class SAWE_MembershipWorks_Role_Sync {

	/**
	 * Singleton instance.
	 *
	 * @var SAWE_MembershipWorks_Role_Sync|null
	 */
	private static ?self $instance = null;

	/**
	 * Returns the single instance of this class, creating it on first call.
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
	 * Private constructor — use ::instance().
	 */
	private function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Require all component class files.
	 *
	 * The DB class is loaded unconditionally and first so it is available to
	 * the activation hook (which fires before plugins_loaded in some cases)
	 * and to every other component.
	 *
	 * @return void
	 */
	private function includes(): void {
		// Database layer — table creation + CRUD/query helpers. No other deps.
		require_once SAWE_MWR_PLUGIN_DIR . 'includes/class-sawe-mwr-db.php';

		// Core membership check + role assignment + throttling logic.
		require_once SAWE_MWR_PLUGIN_DIR . 'includes/class-sawe-mwr-role-sync.php';

		// Admin UI — only meaningfully used on admin requests, but the class
		// itself hooks admin_menu/admin_init, so it's safe to always load.
		if ( is_admin() ) {
			require_once SAWE_MWR_PLUGIN_DIR . 'admin/class-sawe-mwr-list-table.php';
			require_once SAWE_MWR_PLUGIN_DIR . 'admin/class-sawe-mwr-admin.php';
		}
	}

	/**
	 * Register plugins_loaded callback that boots every component.
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
		add_action( 'plugins_loaded', [ $this, 'init_components' ], 10 );
	}

	/**
	 * Load the plugin's text domain for i18n.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			SAWE_MWR_TEXT_DOMAIN,
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	/**
	 * Instantiate all component singletons.
	 *
	 * This is the single place every component class is booted. Each class
	 * registers its own hooks internally in its constructor.
	 *
	 * @return void
	 */
	public function init_components(): void {
		// Front-end + login/profile role checking, throttled and logged.
		SAWE_MWR_Role_Sync::instance();

		// Admin UI — only loaded on admin requests.
		if ( is_admin() ) {
			SAWE_MWR_Admin::instance();
		}
	}
}

// ============================================================================
// Activation Hook
// ============================================================================
//
// Creates the diagnostic log table via dbDelta(). Safe to run on every
// activation (dbDelta is idempotent and will alter existing tables in place
// if the schema defined in SAWE_MWR_DB::create_tables() ever changes).
//
// NOTE: SAWE_MWR_DB is explicitly required here because the activation hook
// fires before plugins_loaded, so includes() above has not necessarily run.
//
register_activation_hook( __FILE__, function () {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-sawe-mwr-db.php';
	SAWE_MWR_DB::create_tables();
} );

// ============================================================================
// Deactivation Hook
// ============================================================================
//
// Intentionally a no-op: deactivating should not delete diagnostic history.
// Data removal (if the admin opts in) happens only in uninstall.php.
//
register_deactivation_hook( __FILE__, function () {} );

// NOTE: Uninstall logic lives in uninstall.php (invoked by WordPress when the
// plugin is deleted from the Plugins screen). It optionally drops the log
// table depending on the 'sawe_mwr_remove_table_on_uninstall' option.

// ============================================================================
// Bootstrap — plugins_loaded priority 6
// ============================================================================
//
// Priority 6 (just after the SAWE Membership Store Credits plugin's priority
// 5 bootstrap) so that if both plugins are active, the parent "SAWE Coupons
// and Credits" menu is already registered before our admin_menu callback
// tries to nest a sub-menu under it. WordPress does not actually require this
// ordering for add_submenu_page() to work (see SAWE_MWR_Admin::register_menu()
// for the fallback logic), but keeping the load order deliberate avoids any
// ambiguity.
//
add_action( 'plugins_loaded', function () {
	SAWE_MembershipWorks_Role_Sync::instance();
}, 6 );
