<?php
/**
 * "PRIME Image Lab" band: a link to the free image tool.
 *
 * Shown only while the Image Lab page exists and is published, so the link can
 * never lead to a missing page. The tool itself lives in the PRIME Image Lab plugin.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

$prime_lab_page = get_page_by_path( 'image-lab' );

if ( ! $prime_lab_page || 'publish' !== $prime_lab_page->post_status || prime_is_native_app_request() ) {
	return;
}

$prime_rtl = is_rtl();
?>

<section class="prime-lab" aria-labelledby="prime-lab-title">
	<div class="prime-wrap prime-lab__wrap">
		<div class="prime-lab__copy">
			<p class="prime-label">
				<?php prime_mark(); ?>
				<span><?php echo esc_html( $prime_rtl ? 'أداة مجانية' : 'Free tool' ); ?></span>
			</p>
			<h2 id="prime-lab-title"><?php echo esc_html( $prime_rtl ? 'مختبر الصور من برايم' : 'PRIME Image Lab' ); ?></h2>
			<p class="prime-lab__text">
				<?php echo esc_html( $prime_rtl ? 'كبّر صورتك، أزل الخلفية، وجهّز ملفاً جاهزاً للطباعة. مجاناً وبدون حساب، وصورتك تبقى على جهازك.' : 'Make your photo bigger, remove the background and get a print-ready file. Free, no account, and your photo stays on your device.' ); ?>
			</p>
		</div>
		<a class="prime-btn prime-btn--navy" href="<?php echo esc_url( get_permalink( $prime_lab_page ) ); ?>">
			<?php prime_mark( 'white' ); ?>
			<span><?php echo esc_html( $prime_rtl ? 'جرّبها مجاناً' : 'Try it free' ); ?></span>
		</a>
	</div>
</section>
