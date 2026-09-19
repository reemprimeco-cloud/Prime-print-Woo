<?php
/**
 * Plugin Name:       Prime Binder Designer
 * Description:       Product-page design tool for the 4 Ring Binder: customers upload a finished design or design live in the browser, constrained to a locked print template. The order gets a print-ready CMYK PDF.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Prime Printing Co.
 * Text Domain:       prime-binder-designer
 *
 * Build spec: binder-shared/SPEC.md in the project repo (sections referenced
 * in the code as §n). Geometry is never hardcoded here — every dimension
 * comes from templates/*-spec.json.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

define( 'PRIME_BINDER_VERSION', '0.1.0' );
define( 'PRIME_BINDER_FILE', __FILE__ );
define( 'PRIME_BINDER_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRIME_BINDER_URL', plugin_dir_url( __FILE__ ) );

require_once PRIME_BINDER_DIR . 'includes/class-db.php';
require_once PRIME_BINDER_DIR . 'includes/class-templates.php';
require_once PRIME_BINDER_DIR . 'includes/class-settings.php';
require_once PRIME_BINDER_DIR . 'includes/class-files.php';
require_once PRIME_BINDER_DIR . 'includes/class-uploads.php';
require_once PRIME_BINDER_DIR . 'includes/class-rest-api.php';
require_once PRIME_BINDER_DIR . 'includes/class-product-meta.php';
require_once PRIME_BINDER_DIR . 'includes/class-order-integration.php';
require_once PRIME_BINDER_DIR . 'includes/class-render-client.php';

register_activation_hook( __FILE__, array( 'Binder_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Binder_DB', 'deactivate' ) );

/**
 * Boot the plugin once every plugin (WooCommerce included) has loaded.
 */
function prime_binder_boot() {
	Binder_DB::maybe_upgrade();
	Binder_Settings::init();
	Binder_Files::init();
	Binder_Rest_API::init();
	Binder_Product_Meta::init();
	Binder_Order_Integration::init();
}
add_action( 'plugins_loaded', 'prime_binder_boot' );
