<?php
/**
 * My Account → Dashboard — greeting + stat tiles, matching
 * prime-printing-account.html, instead of WooCommerce's default paragraph of
 * links to the other tabs (which are all one click away in the nav already).
 *
 * Overrides woocommerce/templates/myaccount/dashboard.php. $current_user
 * comes from WC core's woocommerce_account_content().
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

$stats = prime_account_stats( $current_user->ID );
?>

<div class="prime-account-hero">
	<p class="prime-account-hero__greeting">
		<?php
		printf(
			/* translators: %s: customer's first name */
			esc_html__( 'Hi %s', 'prime-printing' ),
			esc_html( $current_user->first_name ? $current_user->first_name : $current_user->display_name )
		);
		?>
	</p>
	<p class="prime-account-hero__since">
		<?php
		printf(
			/* translators: %s: formatted registration date */
			esc_html__( 'Customer since %s', 'prime-printing' ),
			esc_html( date_i18n( 'F Y', strtotime( $current_user->user_registered ) ) )
		);
		?>
	</p>
</div>

<div class="prime-account-stats">
	<div class="prime-account-stat">
		<span class="prime-account-stat__value"><?php echo esc_html( $stats['total_orders'] ); ?></span>
		<span class="prime-account-stat__label"><?php esc_html_e( 'Total orders', 'prime-printing' ); ?></span>
	</div>
	<div class="prime-account-stat">
		<span class="prime-account-stat__value"><?php echo esc_html( $stats['active_orders'] ); ?></span>
		<span class="prime-account-stat__label"><?php esc_html_e( 'Active orders', 'prime-printing' ); ?></span>
	</div>
	<div class="prime-account-stat">
		<span class="prime-account-stat__value">KWD <?php echo esc_html( $stats['total_spent'] ); ?></span>
		<span class="prime-account-stat__label"><?php esc_html_e( 'Total spent', 'prime-printing' ); ?></span>
	</div>
	<div class="prime-account-stat">
		<span class="prime-account-stat__value"><?php echo esc_html( $stats['saved_files'] ); ?></span>
		<span class="prime-account-stat__label"><?php esc_html_e( 'Saved files', 'prime-printing' ); ?></span>
	</div>
</div>

<?php
	/**
	 * My Account dashboard.
	 *
	 * @since 2.6.0
	 */
	do_action( 'woocommerce_account_dashboard' );
