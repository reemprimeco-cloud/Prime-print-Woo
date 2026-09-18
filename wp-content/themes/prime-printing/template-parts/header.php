<?php
/**
 * The site header: announcement topbar, wordmark, primary nav, language and cart.
 *
 * Two visual modes, both driven by the .prime-has-hero body class rather than by
 * anything in this file:
 *   default      sticky white bar (every inner page)
 *   over-hero    fixed and transparent, solidifying on scroll (homepage)
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

$prime_announcement = get_theme_mod(
	'prime_announcement',
	__( 'Delivery within 48 hours across Kuwait', 'prime-printing' )
);
?>

<?php if ( $prime_announcement ) : ?>
	<div class="prime-topbar">
		<div class="prime-wrap">
			<?php prime_mark(); ?>
			<span><?php echo esc_html( $prime_announcement ); ?></span>
		</div>
	</div>
<?php endif; ?>

<nav class="prime-nav" id="prime-nav" aria-label="<?php esc_attr_e( 'Primary', 'prime-printing' ); ?>">
	<div class="prime-wrap">
		<button
			class="prime-burger"
			type="button"
			aria-label="<?php esc_attr_e( 'Open menu', 'prime-printing' ); ?>"
			aria-expanded="false"
			aria-controls="prime-menu"
			data-prime-menu-open
		>
			<span></span><span></span><span></span>
		</button>

		<?php prime_logo(); ?>

		<?php
		prime_nav_menu(
			'primary',
			array(
				'menu_class' => 'prime-navlinks',
				'items_wrap' => '<ul id="%1$s" class="%2$s">%3$s</ul>',
			)
		);
		?>

		<div class="prime-navtools">
			<?php
			prime_language_switcher( 'button' );
			prime_cart_link();
			?>
		</div>
	</div>
</nav>
