<?php
/**
 * DEV ONLY (mounted by dev/start.sh, never deployed): points the binder plugin
 * at the render service running on this machine.
 */
if ( ! defined( 'BINDER_RENDER_URL' ) ) {
	define( 'BINDER_RENDER_URL', 'http://127.0.0.1:8787' );
}
if ( ! defined( 'BINDER_RENDER_SECRET' ) ) {
	define( 'BINDER_RENDER_SECRET', 'dev-secret-binder' );
}
