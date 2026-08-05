<?php
/**
 * Uninstall handler for SAWE MembershipWorks Role Sync.
 *
 * WordPress calls this file automatically when the plugin is deleted from
 * the Plugins screen (not on simple deactivation). It removes the
 * 'sawe_mwr_remove_table_on_uninstall' / settings options, and — only if the
 * admin opted in via the checkbox on the MembershipWorks Sync Log screen —
 * drops the diagnostic log table.
 *
 * @package SAWE_MWR
 */

// Guard against direct access; WordPress defines WP_UNINSTALL_PLUGIN when
// legitimately invoking this file during plugin deletion.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-sawe-mwr-db.php';

if ( get_option( 'sawe_mwr_remove_table_on_uninstall' ) ) {
	SAWE_MWR_DB::drop_table();
}

delete_option( 'sawe_mwr_remove_table_on_uninstall' );
