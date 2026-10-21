<?php
/**
 * "Get the apps" band, directly under the hero.
 *
 * Tells every browser visitor that Prime has two iPhone apps, links to both on
 * the App Store, and points them at the notification permission prompt the app
 * shows on first open. Not rendered inside the app itself.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'prime_apps' ) || prime_is_native_app_request() ) {
	return;
}

$prime_rtl = is_rtl();

$prime_copy = array(
	'printing' => array(
		$prime_rtl ? 'اطلب وتابع طلبات الطباعة من جوالك.' : 'Order and follow your print jobs from your phone.',
	),
	'rewards'  => array(
		$prime_rtl ? 'مكافآت برايم في مكان واحد.' : 'Your Prime rewards in one place.',
	),
);

?>

<section class="prime-apps" aria-labelledby="prime-apps-title">
	<div class="prime-wrap prime-apps__wrap">
		<div class="prime-apps__intro">
			<h2 id="prime-apps-title"><?php echo esc_html( $prime_rtl ? 'حمّل تطبيقات برايم' : 'Get the Prime apps' ); ?></h2>
			<p>
				<?php echo esc_html( $prime_rtl ? 'حمّل التطبيقات وفعّل الإشعارات لتصلك تحديثات الطلبات والعروض.' : 'Download the apps and turn on notifications to get order updates and offers.' ); ?>
			</p>
		</div>

		<ul class="prime-apps__list">
			<?php foreach ( prime_apps() as $prime_app ) : ?>
				<li class="prime-apps__item">
					<span class="prime-apps__name">
						<?php prime_mark(); ?>
						<?php echo esc_html( $prime_app['name'] ); ?>
					</span>
					<span class="prime-apps__line"><?php echo esc_html( $prime_copy[ $prime_app['key'] ][0] ); ?></span>
					<a class="prime-apps__badge" href="<?php echo esc_url( $prime_app['url'] ); ?>" rel="noopener">
						<img
							src="<?php echo esc_url( get_theme_file_uri( 'assets/img/app-store-badge.png' ) ); ?>"
							alt="<?php echo esc_attr( ( $prime_rtl ? 'حمّل ' : 'Download ' ) . $prime_app['name'] . ( $prime_rtl ? ' من App Store' : ' on the App Store' ) ); ?>"
							width="480"
							height="142"
							loading="lazy"
							decoding="async"
						>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<p class="prime-apps__note">
			<?php echo esc_html( $prime_rtl ? 'عند فتح التطبيق لأول مرة اضغط «سماح» لتفعيل الإشعارات.' : 'When you first open the app, tap Allow to turn on notifications.' ); ?>
		</p>
	</div>
</section>
