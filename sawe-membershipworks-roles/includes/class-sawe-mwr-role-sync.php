<?php
/**
 * Core membership-check and role-assignment logic for SAWE MembershipWorks
 * Role Sync.
 *
 * This is a direct replacement for the sawe_set_member_role() function in the
 * original "Add WordPress Roles based on MembershipWorks" code snippet. It:
 *
 *   - Calls sf_shortcode() from the MembershipWorks (memberfindme) plugin
 *     using the exact same two shortcode strings the original snippet used,
 *     to check general membership and "Corporate Member" label membership.
 *   - Adds/removes the 'member' and 'member-company' WordPress roles based on
 *     the result.
 *   - Skips administrators, exactly as the original snippet did.
 *
 * What's different from the original snippet:
 *
 *   - Throttling no longer uses get_user_meta( $user_id, 'sawe_check_date' ).
 *     Instead it reads/writes SAWE_MWR_DB, which also carries the last known
 *     membership status so the correct interval (member vs. non-member) can
 *     be chosen even before this request performs a fresh check.
 *   - Every check (success or failure) is logged with a status and, on
 *     failure, a human-readable diagnostic message plus the raw
 *     MembershipWorks response — see log_admin_notice()/perform_check().
 *   - If the check comes back ambiguous (neither a clear "yes" nor the
 *     expected "no" placeholder — e.g. a MembershipWorks connectivity error
 *     or a missing Organization ID setting), roles are left untouched rather
 *     than being blindly removed. This avoids accidentally revoking a
 *     member's role due to a transient API failure. The row is still logged
 *     with status = 'error' so admins can see it on the diagnostics screen.
 *
 * @package SAWE_MWR
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class SAWE_MWR_Role_Sync {

	/**
	 * Singleton instance.
	 *
	 * @var SAWE_MWR_Role_Sync|null
	 */
	private static ?self $instance = null;

	/**
	 * Default value/unit for the member check interval, used to seed the
	 * option defaults and as a fallback if the option is somehow missing.
	 * 24 hours, matching the original snippet's once-per-day throttle.
	 */
	const DEFAULT_MEMBER_INTERVAL_VALUE = 24;
	const DEFAULT_MEMBER_INTERVAL_UNIT  = 'hours';

	/**
	 * Default value/unit for the non-member check interval. 5 minutes, per
	 * SAWE's request to avoid hammering the MembershipWorks API for
	 * non-members while still picking up new signups reasonably quickly.
	 */
	const DEFAULT_NONMEMBER_INTERVAL_VALUE = 5;
	const DEFAULT_NONMEMBER_INTERVAL_UNIT  = 'minutes';

	/**
	 * Option names (admin-configurable via the "Settings" section of the
	 * MembershipWorks Sync Log screen — see SAWE_MWR_Admin::register_settings()).
	 */
	const OPTION_MEMBER_INTERVAL_VALUE    = 'sawe_mwr_member_interval_value';
	const OPTION_MEMBER_INTERVAL_UNIT     = 'sawe_mwr_member_interval_unit';
	const OPTION_NONMEMBER_INTERVAL_VALUE = 'sawe_mwr_nonmember_interval_value';
	const OPTION_NONMEMBER_INTERVAL_UNIT  = 'sawe_mwr_nonmember_interval_unit';

	/**
	 * The exact MembershipWorks shortcodes used by the original snippet.
	 * message="no" suppresses MembershipWorks' own login/expired messaging in
	 * favour of a bare "no" placeholder; nologin prevents redirect/embedding
	 * of the MembershipWorks sign-in widget. The literal "yes" between the
	 * tags is only echoed back by sf_shortcode() when the user matches.
	 */
	const MEMBER_SHORTCODE    = '[memberonly message="no" nologin]yes[/memberonly]';
	const CORPORATE_SHORTCODE = '[memberonly label="Corporate Member" message="no" nologin]yes[/memberonly]';

	/**
	 * Return the singleton instance, creating it on first call, and register
	 * all hooks.
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
	 * Private constructor — registers the same trigger points as the
	 * original code snippet, plus a general front-end 'wp' hook so logged-in
	 * users are re-evaluated on ordinary page loads (throttled as above),
	 * not only when they log in, update their profile, or visit a
	 * WooCommerce cart/checkout/account page.
	 */
	private function __construct() {
		// Respond to WordPress user actions (same as the original snippet).
		add_action( 'wp_login', array( $this, 'maybe_sync_current_user' ), 10, 0 );
		add_action( 'profile_update', array( $this, 'maybe_sync_current_user' ), 10, 0 );

		// Respond to WooCommerce actions (same as the original snippet).
		add_action( 'woocommerce_before_cart', array( $this, 'maybe_sync_current_user' ), 5 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'maybe_sync_current_user' ), 5 );
		add_action( 'woocommerce_before_account_navigation', array( $this, 'maybe_sync_current_user' ), 5 );

		// General front-end page load, so throttled re-checks happen even on
		// pages the hooks above don't cover. maybe_sync_current_user() is a
		// cheap no-op (one indexed SELECT) whenever the throttle window
		// hasn't elapsed, so this is safe to run broadly.
		add_action( 'wp', array( $this, 'maybe_sync_on_front_end' ) );
	}

	/**
	 * Wrapper for the 'wp' hook: only runs for logged-in users on genuine
	 * front-end requests (skips admin, AJAX, REST, and cron contexts, which
	 * are covered by their own more specific hooks where relevant).
	 *
	 * Hook: wp
	 *
	 * @return void
	 */
	public function maybe_sync_on_front_end(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		$this->maybe_sync_current_user();
	}

	/**
	 * Entry point used by every hook above. Looks up the current user,
	 * decides — based on the log table, not user meta — whether enough time
	 * has passed to justify calling the MembershipWorks API again, and if so
	 * performs the check and logs the result.
	 *
	 * @return void
	 */
	public function maybe_sync_current_user(): void {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			// No logged-in user — nothing to check.
			return;
		}

		$user = get_user_by( 'ID', $user_id );

		if ( empty( $user ) ) {
			// User not found — exit.
			return;
		}

		// Skip role assignment for administrators, exactly as the original snippet did.
		if ( user_can( $user_id, 'administrator' ) ) {
			return;
		}

		$existing = SAWE_MWR_DB::get_log_for_user( $user_id );

		if ( $existing && ! empty( $existing->last_checked_at ) ) {
			$elapsed  = current_time( 'timestamp' ) - strtotime( $existing->last_checked_at ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$interval = ! empty( $existing->is_member ) ? self::get_member_check_interval() : self::get_nonmember_check_interval();

			if ( $elapsed < $interval ) {
				// Throttled — do not call the MembershipWorks API this time.
				return;
			}
		}

		$this->perform_check( $user, $existing );
	}

	/**
	 * Current member check interval, in seconds, per the admin-configurable
	 * 'sawe_mwr_member_interval_value' / 'sawe_mwr_member_interval_unit'
	 * options (see SAWE_MWR_Admin::register_settings()).
	 *
	 * @return int
	 */
	public static function get_member_check_interval(): int {
		return self::interval_to_seconds(
			get_option( self::OPTION_MEMBER_INTERVAL_VALUE, self::DEFAULT_MEMBER_INTERVAL_VALUE ),
			get_option( self::OPTION_MEMBER_INTERVAL_UNIT, self::DEFAULT_MEMBER_INTERVAL_UNIT )
		);
	}

	/**
	 * Current non-member check interval, in seconds, per the
	 * admin-configurable 'sawe_mwr_nonmember_interval_value' /
	 * 'sawe_mwr_nonmember_interval_unit' options.
	 *
	 * @return int
	 */
	public static function get_nonmember_check_interval(): int {
		return self::interval_to_seconds(
			get_option( self::OPTION_NONMEMBER_INTERVAL_VALUE, self::DEFAULT_NONMEMBER_INTERVAL_VALUE ),
			get_option( self::OPTION_NONMEMBER_INTERVAL_UNIT, self::DEFAULT_NONMEMBER_INTERVAL_UNIT )
		);
	}

	/**
	 * Convert an admin-configured value/unit pair into seconds. Falls back to
	 * a minimum of 1 minute if the stored value is missing or invalid, so a
	 * misconfigured option can never disable throttling entirely.
	 *
	 * @param mixed  $value Raw option value (expected to be a positive integer).
	 * @param mixed  $unit  Raw option unit ('minutes' or 'hours').
	 *
	 * @return int
	 */
	private static function interval_to_seconds( $value, $unit ): int {
		$value = max( 1, absint( $value ) );
		$unit  = ( 'hours' === $unit ) ? 'hours' : 'minutes';

		return $value * ( 'hours' === $unit ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS );
	}

	/**
	 * Call the MembershipWorks API (via sf_shortcode()), update roles when
	 * the result is unambiguous, and log a row to SAWE_MWR_DB regardless of
	 * outcome.
	 *
	 * @param \WP_User   $user     The user being checked.
	 * @param object|null $existing The user's existing log row, if any — used
	 *                              to preserve last-known status when the
	 *                              check errors out.
	 *
	 * @return void
	 */
	private function perform_check( \WP_User $user, ?object $existing ): void {
		$is_member    = $existing ? (bool) $existing->is_member : false;
		$is_corporate = $existing ? (bool) $existing->is_corporate : false;

		// ── Guard: MembershipWorks plugin not active / function missing ────
		if ( ! function_exists( 'sf_shortcode' ) ) {
			$this->log_result(
				$user,
				$is_member,
				$is_corporate,
				'error',
				array(),
				__( 'MembershipWorks plugin not active: sf_shortcode() function not found. Is the MembershipWorks (memberfindme) plugin installed and activated?', 'sawe-mwr' )
			);
			return;
		}

		try {
			$member_raw    = strip_tags( sf_shortcode( self::MEMBER_SHORTCODE ) );
			$corporate_raw = strip_tags( sf_shortcode( self::CORPORATE_SHORTCODE ) );
		} catch ( \Throwable $e ) {
			$this->log_result(
				$user,
				$is_member,
				$is_corporate,
				'error',
				array(),
				sprintf(
					/* translators: %s: exception message */
					__( 'Exception thrown while calling sf_shortcode(): %s', 'sawe-mwr' ),
					$e->getMessage()
				)
			);
			return;
		}

		$api_response = array(
			'member'    => trim( (string) $member_raw ),
			'corporate' => trim( (string) $corporate_raw ),
		);

		[ $member_is_yes, $member_is_error, $member_err_text ]    = $this->interpret_result( $member_raw );
		[ $corporate_is_yes, $corporate_is_error, $corp_err_text ] = $this->interpret_result( $corporate_raw );

		if ( $member_is_error || $corporate_is_error ) {
			// Ambiguous / error response — leave roles untouched and log the
			// diagnostic detail so an admin can investigate. This is safer
			// than the original snippet's behaviour, which would have
			// stripped the role on any non-"yes" response, including
			// transient API failures.
			$messages = array();
			if ( $member_is_error ) {
				$messages[] = sprintf(
					/* translators: %s: raw MembershipWorks response text */
					__( 'Membership check returned an unexpected response: %s', 'sawe-mwr' ),
					$member_err_text
				);
			}
			if ( $corporate_is_error ) {
				$messages[] = sprintf(
					/* translators: %s: raw MembershipWorks response text */
					__( 'Corporate membership check returned an unexpected response: %s', 'sawe-mwr' ),
					$corp_err_text
				);
			}

			$this->log_result( $user, $is_member, $is_corporate, 'error', $api_response, implode( ' | ', $messages ) );
			return;
		}

		// ── Unambiguous result — update roles ──────────────────────────────
		if ( $member_is_yes ) {
			$user->add_role( 'member' );
		} else {
			$user->remove_role( 'member' );
		}

		if ( $corporate_is_yes ) {
			$user->add_role( 'member-company' );
		} else {
			$user->remove_role( 'member-company' );
		}

		$this->log_result( $user, $member_is_yes, $corporate_is_yes, 'ok', $api_response, '' );
	}

	/**
	 * Interpret a single stripped/raw sf_shortcode() response string.
	 *
	 * Because our shortcodes use message="no", a matched member gets the
	 * literal shortcode content ("yes") echoed back, while a non-member gets
	 * the literal string "no" (our override message). Anything else (an
	 * empty-org configuration notice, a MembershipWorks connectivity error
	 * string, etc.) is treated as an error condition worth surfacing to an
	 * admin rather than silently treating the user as a non-member.
	 *
	 * @param string $raw Raw (already strip_tags()'d) shortcode output.
	 *
	 * @return array{0: bool, 1: bool, 2: string} [ is_yes, is_error, raw_text ]
	 */
	private function interpret_result( string $raw ): array {
		$trimmed = trim( (string) $raw );

		if ( false !== stripos( $trimmed, 'yes' ) ) {
			return array( true, false, '' );
		}

		if ( '' === $trimmed || 0 === strcasecmp( $trimmed, 'no' ) ) {
			return array( false, false, '' );
		}

		// Anything else is unexpected — likely a config or connectivity error.
		return array( false, true, $trimmed );
	}

	/**
	 * Write one row to the diagnostic log table for the given user.
	 *
	 * @param \WP_User $user          The user checked.
	 * @param bool     $is_member     Membership flag to record.
	 * @param bool     $is_corporate  Corporate membership flag to record.
	 * @param string   $status        'ok' or 'error'.
	 * @param array    $api_response  Raw response data, JSON-encoded before storage.
	 * @param string   $error_message Diagnostic message, empty string if none.
	 *
	 * @return void
	 */
	private function log_result( \WP_User $user, bool $is_member, bool $is_corporate, string $status, array $api_response, string $error_message ): void {
		SAWE_MWR_DB::upsert_log(
			$user->ID,
			array(
				'user_login'      => $user->user_login,
				'display_name'    => $user->display_name,
				'is_member'       => $is_member,
				'is_corporate'    => $is_corporate,
				'status'          => $status,
				'api_response'    => wp_json_encode( $api_response ),
				'error_message'   => $error_message,
				'last_checked_at' => current_time( 'mysql' ),
			)
		);
	}
}
