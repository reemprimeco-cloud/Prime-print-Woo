<?php
/**
 * Runs when the plugin is DELETED from wp-admin (not merely deactivated).
 *
 * Deactivating keeps the design table on purpose: it holds every customer's
 * saved artwork and the link to each order's print file, and losing it because
 * someone switched the plugin off to debug would orphan paid orders. Only an
 * explicit delete removes it.
 *
 * @package PrimeBinderDesigner
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-db.php';

Binder_DB::drop_table();

delete_option( Binder_DB::VERSION_OPTION );
