<?php
/**
 * Admin interface for SAWE MembershipWorks Role Sync.
 *
 * Adds a single admin screen, "MembershipWorks Sync Log", nested under the
 * existing "SAWE Coupons and Credits" top-level menu (slug: sawe-msc-settings,
 * see SAWE_MWR_PARENT_MENU_SLUG) that the SAWE Membership Store Credits
 * plugin registers. If that plugin is not active, this class registers its
 * own top-level "SAWE Coupons and Credits" menu using the same slug so the
 * diagnostics screen is never unreachable.
 *
 * The screen itself is rendered by SAWE_MWR_List_Table (a WP_List_Table
 * subclass) and includes a small "Settings" section for the one plugin-wide
 * option this plugin exposes: whether to drop the log table on uninstall.
 *
 * @package SAWE_MWR
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class SAWE_MWR_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var SAWE_MWR_Admin|null
	 */
	private static ?self $instance = null;

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
	 * Private constructor — registers all admin hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	// =========================================================================
	// Admin menu
	// =========================================================================

	/**
	 * Register the "MembershipWorks Sync Log" sub-menu under the "SAWE
	 * Coupons and Credits" top-level menu. If that top-level menu's owning
	 * plugin (SAWE Membership Store Credits, class SAWE_MSC_Admin) is not
	 * loaded, register our own top-level menu of the same slug/label first so
	 * this screen still appears in the admin sidebar.
	 *
	 * Capability: 'manage_options'. This plugin has no WooCommerce dependency
	 * (unlike SAWE Membership Store Credits), so it uses the standard
	 * administrator capability rather than 'manage_woocommerce'.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		if ( ! class_exists( 'SAWE_MSC_Admin' ) ) {
			add_menu_page(
				__( 'SAWE Coupons and Credits', 'sawe-mwr' ),
				__( 'SAWE Coupons and Credits', 'sawe-mwr' ),
				'manage_options',
				SAWE_MWR_PARENT_MENU_SLUG,
				array( $this, 'render_log_page' ),
				'dashicons-tickets-alt',
				58
			);
		}

		add_submenu_page(
			SAWE_MWR_PARENT_MENU_SLUG,
			__( 'MembershipWorks Sync Log', 'sawe-mwr' ),
			__( 'MembershipWorks Sync Log', 'sawe-mwr' ),
			'manage_options',
			'sawe-mwr-log',
			array( $this, 'render_log_page' )
		);
	}

	// =========================================================================
	// Settings (uninstall option)
	// =========================================================================

	/**
	 * Register the single plugin-wide option via the WP Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'sawe_mwr_settings',
			'sawe_mwr_remove_table_on_uninstall',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
	}

	// =========================================================================
	// Render
	// =========================================================================

	/**
	 * Render the "MembershipWorks Sync Log" admin page: the diagnostics
	 * list table plus a small settings section underneath.
	 *
	 * @return void
	 */
	public function render_log_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'sawe-mwr' ) );
		}

		$list_table = new SAWE_MWR_List_Table();
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MembershipWorks Sync Log', 'sawe-mwr' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Every MembershipWorks membership check performed by SAWE MembershipWorks Role Sync is logged here — one row per user, updated on each re-check. Members are re-checked at most once every 24 hours; non-members (or users with an unresolved error) are re-checked at most once every 5 minutes.', 'sawe-mwr' ); ?>
			</p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'sawe-mwr-log' ); ?>">
				<?php
				$list_table->views();
				$list_table->search_box( __( 'Search users / errors', 'sawe-mwr' ), 'sawe-mwr-search' );
				$list_table->display();
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Settings', 'sawe-mwr' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'sawe_mwr_settings' );
				$remove_on_uninstall = get_option( 'sawe_mwr_remove_table_on_uninstall', false );
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Remove database table on uninstall', 'sawe-mwr' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="sawe_mwr_remove_table_on_uninstall" value="1" <?php checked( 1, (int) $remove_on_uninstall ); ?>>
								<?php esc_html_e( 'When this plugin is deleted, remove the MembershipWorks sync log table from the database. All diagnostic history will be permanently lost.', 'sawe-mwr' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
