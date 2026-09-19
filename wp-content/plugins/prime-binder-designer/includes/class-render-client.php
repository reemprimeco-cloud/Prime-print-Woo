<?php
/**
 * Calls the Node render service over HTTPS with the shared-secret header (§2, §5). Step 3/4.
 *
 * STEP 1: scaffold only.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Render_Client {

	public static function init() {
		// Hooks are added by the step that implements this class.
	}
}
