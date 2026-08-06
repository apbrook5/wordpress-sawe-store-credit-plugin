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
 * subclass) and includes a "Settings" section for this plugin's options:
 * the member / non-member MembershipWorks check intervals, and whether to
 * drop the log table on uninstall.
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

		$hook = add_submenu_page(
			SAWE_MWR_PARENT_MENU_SLUG,
			__( 'MembershipWorks Sync Log', 'sawe-mwr' ),
			__( 'MembershipWorks Sync Log', 'sawe-mwr' ),
			'manage_options',
			'sawe-mwr-log',
			array( $this, 'render_log_page' )
		);

		// Process row/bulk delete actions on the page's own 'load-' hook, which
		// fires before any admin chrome is output — so we can still
		// wp_safe_redirect() afterward (render_log_page() itself runs too late
		// for that, since WordPress has already sent the admin header HTML by
		// the time a page callback runs).
		if ( $hook ) {
			add_action( "load-{$hook}", array( $this, 'handle_list_table_actions' ) );
		}
	}

	// =========================================================================
	// List table row/bulk actions (delete)
	// =========================================================================

	/**
	 * Handle the "Delete" row action and "Delete" bulk action from the
	 * MembershipWorks Sync Log list table. Deleting a log row simply removes
	 * the throttle/diagnostic record for that user — SAWE_MWR_Role_Sync treats
	 * a missing row as "never checked", so the user is re-evaluated against
	 * MembershipWorks on their next login or page load.
	 *
	 * Redirects back to a clean URL (stripping the action/nonce query args)
	 * afterward so a page refresh can't resubmit the deletion.
	 *
	 * @return void
	 */
	public function handle_list_table_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = '';
		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		} elseif ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}

		if ( 'delete' !== $action ) {
			return;
		}

		$deleted = 0;

		if ( isset( $_REQUEST['sawe_mwr_log_row'] ) ) {
			// Bulk delete, submitted via the list table's own bulk-action form.
			check_admin_referer( 'bulk-sawe_mwr_log_rows' );
			$ids     = array_map( 'absint', (array) wp_unslash( $_REQUEST['sawe_mwr_log_row'] ) );
			$deleted = SAWE_MWR_DB::delete_logs( $ids );
		} elseif ( isset( $_GET['id'] ) ) {
			// Single-row delete, via the row action link.
			$id = absint( $_GET['id'] );
			check_admin_referer( 'sawe_mwr_delete_log_' . $id );
			$deleted = SAWE_MWR_DB::delete_logs( array( $id ) );
		} else {
			return;
		}

		$redirect = remove_query_arg( array( 'action', 'action2', 'id', '_wpnonce', '_wp_http_referer', 'sawe_mwr_log_row' ) );
		$redirect = add_query_arg( 'sawe_mwr_deleted', $deleted, $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}

	// =========================================================================
	// Settings (check intervals + uninstall option)
	// =========================================================================

	/**
	 * Register this plugin's options via the WP Settings API: the uninstall
	 * behaviour flag, plus the admin-configurable member / non-member check
	 * intervals consumed by SAWE_MWR_Role_Sync::get_member_check_interval()
	 * and ::get_nonmember_check_interval().
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

		register_setting(
			'sawe_mwr_settings',
			SAWE_MWR_Role_Sync::OPTION_MEMBER_INTERVAL_VALUE,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_interval_value' ),
				'default'           => SAWE_MWR_Role_Sync::DEFAULT_MEMBER_INTERVAL_VALUE,
			)
		);

		register_setting(
			'sawe_mwr_settings',
			SAWE_MWR_Role_Sync::OPTION_MEMBER_INTERVAL_UNIT,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_interval_unit' ),
				'default'           => SAWE_MWR_Role_Sync::DEFAULT_MEMBER_INTERVAL_UNIT,
			)
		);

		register_setting(
			'sawe_mwr_settings',
			SAWE_MWR_Role_Sync::OPTION_NONMEMBER_INTERVAL_VALUE,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_interval_value' ),
				'default'           => SAWE_MWR_Role_Sync::DEFAULT_NONMEMBER_INTERVAL_VALUE,
			)
		);

		register_setting(
			'sawe_mwr_settings',
			SAWE_MWR_Role_Sync::OPTION_NONMEMBER_INTERVAL_UNIT,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_interval_unit' ),
				'default'           => SAWE_MWR_Role_Sync::DEFAULT_NONMEMBER_INTERVAL_UNIT,
			)
		);
	}

	/**
	 * Sanitize an interval value field: a positive integer, minimum 1, so a
	 * blank or zero submission can never disable throttling entirely.
	 *
	 * @param mixed $value Raw submitted value.
	 *
	 * @return int
	 */
	public static function sanitize_interval_value( $value ): int {
		return max( 1, absint( $value ) );
	}

	/**
	 * Sanitize an interval unit field to one of 'minutes' or 'hours',
	 * defaulting to 'minutes' for any other submitted value.
	 *
	 * @param mixed $unit Raw submitted value.
	 *
	 * @return string
	 */
	public static function sanitize_interval_unit( $unit ): string {
		return ( 'hours' === $unit ) ? 'hours' : 'minutes';
	}

	/**
	 * Human-readable "N minutes"/"N hours" description of a stored interval
	 * option pair, used in the introductory copy at the top of the log page.
	 *
	 * @param string $value_option   Option name holding the interval magnitude.
	 * @param string $unit_option    Option name holding the interval unit.
	 * @param int    $default_value  Fallback magnitude if the option is unset.
	 * @param string $default_unit   Fallback unit if the option is unset.
	 *
	 * @return string
	 */
	private static function format_interval_description( string $value_option, string $unit_option, int $default_value, string $default_unit ): string {
		$value = max( 1, absint( get_option( $value_option, $default_value ) ) );
		$unit  = ( 'hours' === get_option( $unit_option, $default_unit ) ) ? 'hours' : 'minutes';

		if ( 'hours' === $unit ) {
			/* translators: %d: number of hours */
			return sprintf( _n( '%d hour', '%d hours', $value, 'sawe-mwr' ), $value );
		}

		/* translators: %d: number of minutes */
		return sprintf( _n( '%d minute', '%d minutes', $value, 'sawe-mwr' ), $value );
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
				<?php
				printf(
					/* translators: 1: member check interval description (e.g. "24 hours"), 2: non-member check interval description (e.g. "5 minutes") */
					esc_html__( 'Every MembershipWorks membership check performed by SAWE MembershipWorks Role Sync is logged here — one row per user, updated on each re-check. Members are re-checked at most once every %1$s; non-members (or users with an unresolved error) are re-checked at most once every %2$s. Adjust these intervals in the Settings section below.', 'sawe-mwr' ),
					esc_html( self::format_interval_description( SAWE_MWR_Role_Sync::OPTION_MEMBER_INTERVAL_VALUE, SAWE_MWR_Role_Sync::OPTION_MEMBER_INTERVAL_UNIT, SAWE_MWR_Role_Sync::DEFAULT_MEMBER_INTERVAL_VALUE, SAWE_MWR_Role_Sync::DEFAULT_MEMBER_INTERVAL_UNIT ) ),
					esc_html( self::format_interval_description( SAWE_MWR_Role_Sync::OPTION_NONMEMBER_INTERVAL_VALUE, SAWE_MWR_Role_Sync::OPTION_NONMEMBER_INTERVAL_UNIT, SAWE_MWR_Role_Sync::DEFAULT_NONMEMBER_INTERVAL_VALUE, SAWE_MWR_Role_Sync::DEFAULT_NONMEMBER_INTERVAL_UNIT ) )
				);
				?>
			</p>

			<?php if ( isset( $_GET['sawe_mwr_deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag, actual deletion was already nonce-verified in handle_list_table_actions(). ?>
				<?php $deleted_count = (int) $_GET['sawe_mwr_deleted']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of log entries deleted. */
								_n(
									'%d log entry deleted. The affected user will be re-checked against MembershipWorks on their next login.',
									'%d log entries deleted. Affected users will be re-checked against MembershipWorks on their next login.',
									$deleted_count,
									'sawe-mwr'
								),
								$deleted_count
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

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
				$remove_on_uninstall  = get_option( 'sawe_mwr_remove_table_on_uninstall', false );
				$member_value         = get_option( SAWE_MWR_Role_Sync::OPTION_MEMBER_INTERVAL_VALUE, SAWE_MWR_Role_Sync::DEFAULT_MEMBER_INTERVAL_VALUE );
				$member_unit          = get_option( SAWE_MWR_Role_Sync::OPTION_MEMBER_INTERVAL_UNIT, SAWE_MWR_Role_Sync::DEFAULT_MEMBER_INTERVAL_UNIT );
				$nonmember_value      = get_option( SAWE_MWR_Role_Sync::OPTION_NONMEMBER_INTERVAL_VALUE, SAWE_MWR_Role_Sync::DEFAULT_NONMEMBER_INTERVAL_VALUE );
				$nonmember_unit       = get_option( SAWE_MWR_Role_Sync::OPTION_NONMEMBER_INTERVAL_UNIT, SAWE_MWR_Role_Sync::DEFAULT_NONMEMBER_INTERVAL_UNIT );
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label for="sawe_mwr_member_interval_value"><?php esc_html_e( 'Member check interval', 'sawe-mwr' ); ?></label>
						</th>
						<td>
							<input type="number" min="1" step="1" id="sawe_mwr_member_interval_value" name="<?php echo esc_attr( SAWE_MWR_Role_Sync::OPTION_MEMBER_INTERVAL_VALUE ); ?>" value="<?php echo esc_attr( $member_value ); ?>" class="small-text">
							<select name="<?php echo esc_attr( SAWE_MWR_Role_Sync::OPTION_MEMBER_INTERVAL_UNIT ); ?>">
								<option value="minutes" <?php selected( 'minutes', $member_unit ); ?>><?php esc_html_e( 'Minutes', 'sawe-mwr' ); ?></option>
								<option value="hours" <?php selected( 'hours', $member_unit ); ?>><?php esc_html_e( 'Hours', 'sawe-mwr' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Minimum time between MembershipWorks API checks for a user currently known to be a member. Default: 24 hours.', 'sawe-mwr' ); ?></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="sawe_mwr_nonmember_interval_value"><?php esc_html_e( 'Non-member check interval', 'sawe-mwr' ); ?></label>
						</th>
						<td>
							<input type="number" min="1" step="1" id="sawe_mwr_nonmember_interval_value" name="<?php echo esc_attr( SAWE_MWR_Role_Sync::OPTION_NONMEMBER_INTERVAL_VALUE ); ?>" value="<?php echo esc_attr( $nonmember_value ); ?>" class="small-text">
							<select name="<?php echo esc_attr( SAWE_MWR_Role_Sync::OPTION_NONMEMBER_INTERVAL_UNIT ); ?>">
								<option value="minutes" <?php selected( 'minutes', $nonmember_unit ); ?>><?php esc_html_e( 'Minutes', 'sawe-mwr' ); ?></option>
								<option value="hours" <?php selected( 'hours', $nonmember_unit ); ?>><?php esc_html_e( 'Hours', 'sawe-mwr' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Minimum time between MembershipWorks API checks for a user who is not currently known to be a member (including users never checked, or whose last check errored). Default: 5 minutes.', 'sawe-mwr' ); ?></p>
						</td>
					</tr>
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
