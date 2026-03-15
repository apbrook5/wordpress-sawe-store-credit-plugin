<?php
/**
 * Plugin Uninstall Script
 *
 * WordPress executes this file when an administrator deletes the plugin via
 * Plugins → Delete (after first deactivating it). It does NOT run on simple
 * deactivation — only on permanent deletion.
 *
 * ============================================================================
 * WHAT THIS FILE DOES
 * ============================================================================
 *
 * 1. Verifies it was invoked via WordPress's uninstall mechanism (not directly).
 * 2. Checks the admin setting 'sawe_msc_remove_tables_on_uninstall'.
 * 3. If opted in: drops the wp_sawe_msc_user_credits custom table.
 * 4. Removes all plugin-owned wp_options rows.
 *
 * ============================================================================
 * DATA PRESERVATION POLICY
 * ============================================================================
 *
 * By default (setting = false) the DB table is PRESERVED on uninstall.
 * This is intentional — it means an admin can:
 *   a. Deactivate → delete → reinstall the plugin without losing member balances.
 *   b. Change their mind about removing the plugin.
 *
 * If the admin explicitly checked "Remove database tables on uninstall" in
 * Store Credits → Settings before deleting, SAWE_MSC_DB::drop_tables() is
 * called and all balance history is permanently lost. There is no undo.
 *
 * ============================================================================
 * POST META AND ORDER META
 * ============================================================================
 *
 * sawe_store_credit CPT posts and their post meta (_sawe_msc_*) are NOT
 * removed here. WordPress automatically moves them to trash when the plugin
 * is deleted; the admin can permanently delete them from Media/Posts if desired.
 *
 * Order meta (_sawe_msc_credit_deductions) is left on orders so that order
 * history remains intact and auditable.
 *
 * ============================================================================
 * SECURITY
 * ============================================================================
 *
 * The WP_UNINSTALL_PLUGIN constant is defined by WordPress core immediately
 * before including this file. If this file is accessed directly via HTTP,
 * the constant is absent and exit() is called immediately.
 *
 * @package SAWE_MSC
 * @since   1.0.0
 */

// Block direct HTTP access. WordPress defines WP_UNINSTALL_PLUGIN before
// including this file; if it's missing, someone is hitting the file directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// ============================================================================
// Conditional table removal
// ============================================================================

$remove_tables = get_option( 'sawe_msc_remove_tables_on_uninstall', false );

if ( $remove_tables ) {
    // Include the DB class so we can call drop_tables().
    // Note: the main plugin file has not been loaded at uninstall time, so
    // we must require this manually. ABSPATH is available because WordPress
    // core has already bootstrapped before calling this file.
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-sawe-msc-db.php';

    // Drops the wp_sawe_msc_user_credits table. Irreversible.
    SAWE_MSC_DB::drop_tables();
}

// ============================================================================
// Clean up plugin options from wp_options
// ============================================================================

// These are the only options this plugin writes directly to wp_options.
// Post meta (_sawe_msc_*) is associated with CPT posts and is handled
// separately — see the data preservation policy note above.

$options_to_remove = [
    'sawe_msc_db_version',                  // Schema version string (set on activation).
    'sawe_msc_remove_tables_on_uninstall',  // The checkbox setting itself.
];

foreach ( $options_to_remove as $option ) {
    delete_option( $option );
}
