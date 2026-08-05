<?php
/**
 * WP_List_Table subclass for the "MembershipWorks Sync Log" admin screen.
 *
 * Renders SAWE_MWR_DB rows as a standard WordPress admin list table with:
 *   - A search box (matches username, display name, and error message).
 *   - Status views ("All / OK / Errors") above the table, like the built-in
 *     post list screens.
 *   - A dropdown filter for an exact error message (see extra_tablenav()).
 *   - Sortable columns: Username, Display Name, Last Checked, Status.
 *   - A username column that links to that user's WordPress profile edit
 *     screen (get_edit_user_link()).
 *
 * @package SAWE_MWR
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class SAWE_MWR_List_Table extends \WP_List_Table {

	/**
	 * Rows-per-page for the list table (there is no per-user "screen option"
	 * for this simple diagnostics screen — kept as a constant for clarity).
	 */
	const PER_PAGE = 25;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'sawe_mwr_log_row',
				'plural'   => 'sawe_mwr_log_rows',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'cb'              => '<input type="checkbox" />',
			'user_login'      => __( 'Username', 'sawe-mwr' ),
			'display_name'    => __( 'Display Name', 'sawe-mwr' ),
			'is_member'       => __( 'Member', 'sawe-mwr' ),
			'is_corporate'    => __( 'Corporate', 'sawe-mwr' ),
			'status'          => __( 'Status', 'sawe-mwr' ),
			'error_message'   => __( 'Error / Diagnostic', 'sawe-mwr' ),
			'api_response'    => __( 'Raw Response', 'sawe-mwr' ),
			'last_checked_at' => __( 'Last Checked', 'sawe-mwr' ),
		);
	}

	/**
	 * Sortable columns. Keys must exist in get_columns(); values are
	 * [ orderby_key, is_default_descending ].
	 *
	 * @return array<string,array>
	 */
	public function get_sortable_columns(): array {
		return array(
			'user_login'      => array( 'user_login', false ),
			'display_name'    => array( 'display_name', false ),
			'is_member'       => array( 'is_member', false ),
			'status'          => array( 'status', false ),
			'last_checked_at' => array( 'last_checked_at', true ),
		);
	}

	/**
	 * Bulk actions available above/below the table.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete', 'sawe-mwr' ),
		);
	}

	/**
	 * Status views shown above the table ("All | OK | Errors"), mirroring the
	 * built-in post-list "All | Published | Draft" pattern.
	 *
	 * @return array<string,string>
	 */
	protected function get_views(): array {
		$counts       = SAWE_MWR_DB::get_status_counts();
		$current      = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base_url     = remove_query_arg( array( 'status', 'paged' ) );

		$views = array();

		$views['all'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
			esc_url( remove_query_arg( 'status', $base_url ) ),
			'' === $current ? 'current' : '',
			esc_html__( 'All', 'sawe-mwr' ),
			$counts['all']
		);

		$views['ok'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
			esc_url( add_query_arg( 'status', 'ok', $base_url ) ),
			'ok' === $current ? 'current' : '',
			esc_html__( 'OK', 'sawe-mwr' ),
			$counts['ok']
		);

		$views['error'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
			esc_url( add_query_arg( 'status', 'error', $base_url ) ),
			'error' === $current ? 'current' : '',
			esc_html__( 'Errors', 'sawe-mwr' ),
			$counts['error']
		);

		return $views;
	}

	/**
	 * Render the "Error message" exact-match filter dropdown, plus a
	 * "Clear filter" link when one is active. Hooked into the table's own
	 * extra_tablenav (top position only, to avoid duplicate controls).
	 *
	 * @param string $which 'top' or 'bottom'.
	 *
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$errors         = SAWE_MWR_DB::get_distinct_errors();
		$current_error  = isset( $_GET['error_filter'] ) ? wp_unslash( $_GET['error_filter'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( empty( $errors ) && '' === $current_error ) {
			return;
		}
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="sawe-mwr-error-filter"><?php esc_html_e( 'Filter by error message', 'sawe-mwr' ); ?></label>
			<select name="error_filter" id="sawe-mwr-error-filter">
				<option value=""><?php esc_html_e( 'All error messages', 'sawe-mwr' ); ?></option>
				<?php foreach ( $errors as $error ) : ?>
					<option value="<?php echo esc_attr( $error ); ?>" <?php selected( $current_error, $error ); ?>>
						<?php echo esc_html( mb_strimwidth( $error, 0, 80, '…' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'sawe-mwr' ), '', 'sawe_mwr_filter_action', false ); ?>
			<?php if ( '' !== $current_error ) : ?>
				<a class="button" href="<?php echo esc_url( remove_query_arg( array( 'error_filter', 'paged' ) ) ); ?>">
					<?php esc_html_e( 'Clear filter', 'sawe-mwr' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Message shown when there are no rows to display (e.g. no MembershipWorks
	 * checks have run yet, or a filter/search matched nothing).
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No MembershipWorks check log entries found.', 'sawe-mwr' );
	}

	/**
	 * Fetch data from SAWE_MWR_DB based on the current request's search,
	 * filter, sort, and pagination parameters, and populate $this->items.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$per_page = self::PER_PAGE;

		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error  = isset( $_GET['error_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['error_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'last_checked_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged   = $this->get_pagenum();

		$result = SAWE_MWR_DB::query_rows(
			array(
				'search'   => $search,
				'status'   => $status,
				'error'    => $error,
				'orderby'  => $orderby,
				'order'    => $order,
				'paged'    => $paged,
				'per_page' => $per_page,
			)
		);

		$this->items = $result['rows'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * Default column renderer, used for any column without a dedicated
	 * column_{name}() method below.
	 *
	 * @param object $item        Current row.
	 * @param string $column_name Column key.
	 *
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}

	/**
	 * Checkbox column, used for bulk actions (currently just "Delete").
	 *
	 * @param object $item Current row.
	 *
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="sawe_mwr_log_row[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Username column: links to the user's WordPress profile edit screen, and
	 * carries the row's "Delete" action (see SAWE_MWR_Admin::handle_list_table_actions()).
	 * Deleting the row forces the user to be re-checked on their next
	 * login/page load, since maybe_sync_current_user() throttles only when a
	 * log row already exists.
	 *
	 * @param object $item Current row.
	 *
	 * @return string
	 */
	protected function column_user_login( $item ): string {
		$edit_link = get_edit_user_link( (int) $item->user_id );

		if ( empty( $edit_link ) ) {
			// User account may have been deleted since the last check.
			$label = sprintf(
				'%s <span class="description">(%s)</span>',
				esc_html( $item->user_login ),
				esc_html__( 'user not found', 'sawe-mwr' )
			);
		} else {
			$label = sprintf(
				'<a href="%s"><strong>%s</strong></a>',
				esc_url( $edit_link ),
				esc_html( $item->user_login )
			);
		}

		$delete_url = wp_nonce_url(
			add_query_arg( array( 'action' => 'delete', 'id' => (int) $item->id ) ),
			'sawe_mwr_delete_log_' . (int) $item->id
		);

		$actions = array(
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this log entry? The user will be re-checked on their next login.', 'sawe-mwr' ) ),
				esc_html__( 'Delete', 'sawe-mwr' )
			),
		);

		return $label . $this->row_actions( $actions );
	}

	/**
	 * Member column: a simple yes/no badge.
	 *
	 * @param object $item Current row.
	 *
	 * @return string
	 */
	protected function column_is_member( $item ): string {
		return $this->render_bool_badge( ! empty( $item->is_member ) );
	}

	/**
	 * Corporate column: a simple yes/no badge.
	 *
	 * @param object $item Current row.
	 *
	 * @return string
	 */
	protected function column_is_corporate( $item ): string {
		return $this->render_bool_badge( ! empty( $item->is_corporate ) );
	}

	/**
	 * Render a small colored Yes/No indicator.
	 *
	 * @param bool $value True for "Yes".
	 *
	 * @return string
	 */
	private function render_bool_badge( bool $value ): string {
		return $value
			? '<span style="color:#1a7a1a;font-weight:600;">' . esc_html__( 'Yes', 'sawe-mwr' ) . '</span>'
			: '<span style="color:#666;">' . esc_html__( 'No', 'sawe-mwr' ) . '</span>';
	}

	/**
	 * Status column: OK in green, Error in red, each clickable to pre-filter
	 * the table to that status.
	 *
	 * @param object $item Current row.
	 *
	 * @return string
	 */
	protected function column_status( $item ): string {
		$is_error = 'error' === $item->status;
		$url      = add_query_arg( 'status', $item->status );

		return sprintf(
			'<a href="%s" style="color:%s;font-weight:600;text-decoration:none;">%s</a>',
			esc_url( $url ),
			$is_error ? '#b32d2e' : '#1a7a1a',
			$is_error ? esc_html__( 'Error', 'sawe-mwr' ) : esc_html__( 'OK', 'sawe-mwr' )
		);
	}

	/**
	 * Error / diagnostic column: shows the stored error_message, truncated
	 * with the full text available via the title attribute, and a link that
	 * filters the whole table down to that exact error message.
	 *
	 * @param object $item Current row.
	 *
	 * @return string
	 */
	protected function column_error_message( $item ): string {
		if ( empty( $item->error_message ) ) {
			return '<span class="description">&#8212;</span>';
		}

		$full      = (string) $item->error_message;
		$truncated = mb_strimwidth( $full, 0, 100, '…' );
		$filter_url = add_query_arg( 'error_filter', rawurlencode( $full ) );

		return sprintf(
			'<span title="%s">%s</span><br><a href="%s">%s</a>',
			esc_attr( $full ),
			esc_html( $truncated ),
			esc_url( $filter_url ),
			esc_html__( 'Filter to this error', 'sawe-mwr' )
		);
	}

	/**
	 * Raw response column: pretty-prints the stored JSON inside a
	 * collapsible <details> element so the table stays scannable.
	 *
	 * @param object $item Current row.
	 *
	 * @return string
	 */
	protected function column_api_response( $item ): string {
		if ( empty( $item->api_response ) ) {
			return '<span class="description">&#8212;</span>';
		}

		$decoded = json_decode( (string) $item->api_response, true );

		if ( ! is_array( $decoded ) || empty( array_filter( $decoded ) ) ) {
			return '<span class="description">&#8212;</span>';
		}

		$lines = array();
		foreach ( $decoded as $key => $value ) {
			$lines[] = esc_html( ucfirst( $key ) ) . ': ' . esc_html( (string) $value );
		}

		return '<details><summary>' . esc_html__( 'View', 'sawe-mwr' ) . '</summary>'
			. '<div style="max-width:320px;white-space:pre-wrap;">' . implode( '<br>', $lines ) . '</div>'
			. '</details>';
	}

	/**
	 * Last Checked column: formatted using the site's date/time format
	 * settings so admins see a familiar local timestamp.
	 *
	 * @param object $item Current row.
	 *
	 * @return string
	 */
	protected function column_last_checked_at( $item ): string {
		if ( empty( $item->last_checked_at ) ) {
			return '<span class="description">&#8212;</span>';
		}

		$timestamp = strtotime( (string) $item->last_checked_at );

		if ( ! $timestamp ) {
			return esc_html( $item->last_checked_at );
		}

		$format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' );

		return esc_html( date_i18n( $format, $timestamp ) );
	}
}
