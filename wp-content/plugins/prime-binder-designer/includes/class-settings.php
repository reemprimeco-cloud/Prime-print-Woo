<?php
/**
 * Settings → Prime Designer: where the render service lives (§2, §7).
 *
 * The URL and shared secret can also be fixed in wp-config.php with
 * BINDER_RENDER_URL / BINDER_RENDER_SECRET, which win over the saved options.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Settings {

	const OPT_URL    = 'binder_render_url';
	const OPT_SECRET = 'binder_render_secret';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_binder_test_connection', array( __CLASS__, 'test_connection' ) );
	}

	public static function menu() {
		add_options_page(
			__( 'Prime Designer', 'prime-binder-designer' ),
			__( 'Prime Designer', 'prime-binder-designer' ),
			'manage_options',
			'binder-designer',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register() {
		register_setting(
			'binder_designer',
			self::OPT_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => static function ( $v ) {
					return untrailingslashit( esc_url_raw( trim( (string) $v ) ) );
				},
				'autoload'          => false,
			)
		);
		register_setting(
			'binder_designer',
			self::OPT_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => static function ( $v ) {
					$v = trim( (string) $v );
					// Blank keeps the saved secret; the field is never pre-filled.
					return '' === $v ? (string) get_option( Binder_Settings::OPT_SECRET, '' ) : $v;
				},
				'autoload'          => false,
			)
		);
	}

	public static function test_connection() {
		check_admin_referer( 'binder_test_connection' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'prime-binder-designer' ) );
		}

		$result = Binder_Render_Client::health();
		$msg    = is_wp_error( $result )
			? $result->get_error_message()
			: sprintf(
				/* translators: 1: yes/no, 2: yes/no, 3: yes/no */
				__( 'Connected. Ghostscript: %1$s, ICC profile: %2$s, print page: %3$s.', 'prime-binder-designer' ),
				! empty( $result['ghostscript'] ) ? 'yes' : 'NO',
				! empty( $result['icc_profile'] ) ? 'yes' : 'NO',
				! empty( $result['print_page'] ) ? 'yes' : 'NO'
			);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'binder-designer',
					'binder_test' => rawurlencode( $msg ),
					'binder_ok'   => is_wp_error( $result ) ? '0' : '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * The newest rows of the designs table, for the diagnosis list.
	 *
	 * @return array[]
	 */
	private static function recent_designs() {
		global $wpdb;

		$table = Binder_DB::table();

		return (array) $wpdb->get_results( "SELECT id, product_id, template, status, updated_at, order_id, render_error FROM {$table} ORDER BY id DESC LIMIT 20", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$url_locked    = defined( 'BINDER_RENDER_URL' );
		$secret_locked = defined( 'BINDER_RENDER_SECRET' );
		$has_secret    = '' !== Binder_Render_Client::secret();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Prime Designer', 'prime-binder-designer' ); ?></h1>

			<?php if ( isset( $_GET['binder_test'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-<?php echo '1' === ( $_GET['binder_ok'] ?? '' ) ? 'success' : 'error'; // phpcs:ignore WordPress.Security ?>"><p><?php echo esc_html( rawurldecode( wp_unslash( $_GET['binder_test'] ) ) ); // phpcs:ignore WordPress.Security ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'binder_designer' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="binder_render_url"><?php esc_html_e( 'Render service URL', 'prime-binder-designer' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="binder_render_url" name="<?php echo esc_attr( self::OPT_URL ); ?>" value="<?php echo esc_attr( Binder_Render_Client::url() ); ?>" <?php disabled( $url_locked ); ?> placeholder="https://binder-render.example.com">
							<p class="description"><?php esc_html_e( 'Where the Node render service (Playwright + Ghostscript) runs.', 'prime-binder-designer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="binder_render_secret"><?php esc_html_e( 'Shared secret', 'prime-binder-designer' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="binder_render_secret" name="<?php echo esc_attr( self::OPT_SECRET ); ?>" value="" autocomplete="new-password" <?php disabled( $secret_locked ); ?> placeholder="<?php echo $has_secret ? esc_attr__( '(saved — leave blank to keep)', 'prime-binder-designer' ) : ''; ?>">
							<p class="description"><?php esc_html_e( 'Must equal BINDER_SECRET on the render service.', 'prime-binder-designer' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Connection', 'prime-binder-designer' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="binder_test_connection">
				<?php wp_nonce_field( 'binder_test_connection' ); ?>
				<?php submit_button( __( 'Test connection', 'prime-binder-designer' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent designs', 'prime-binder-designer' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The last 20 designs and what happened to each. "Error" is the reason a render did not finish, as the service or the connection reported it.', 'prime-binder-designer' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th>#</th>
					<th><?php esc_html_e( 'Product', 'prime-binder-designer' ); ?></th>
					<th><?php esc_html_e( 'Template', 'prime-binder-designer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'prime-binder-designer' ); ?></th>
					<th><?php esc_html_e( 'Updated (UTC)', 'prime-binder-designer' ); ?></th>
					<th><?php esc_html_e( 'Order', 'prime-binder-designer' ); ?></th>
					<th><?php esc_html_e( 'Error', 'prime-binder-designer' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( self::recent_designs() as $d ) : ?>
					<tr>
						<td><?php echo (int) $d['id']; ?></td>
						<td><?php echo esc_html( get_the_title( (int) $d['product_id'] ) ); ?></td>
						<td><?php echo esc_html( $d['template'] ); ?></td>
						<td><?php echo esc_html( $d['status'] ); ?></td>
						<td><?php echo esc_html( $d['updated_at'] ); ?></td>
						<td><?php echo $d['order_id'] ? (int) $d['order_id'] : '—'; ?></td>
						<td style="font-family:monospace;font-size:11px;max-width:420px;word-break:break-all"><?php echo esc_html( (string) $d['render_error'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
