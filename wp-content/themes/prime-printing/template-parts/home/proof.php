<?php
/**
 * Proof strip and category row, directly under the hero.
 *
 * Three real capabilities (each already live on the site) and the six biggest
 * categories, so a first-time visitor sees what Prime does and has a route in
 * before the wall. Nothing here is a claim the shop cannot back up.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

$prime_rtl = is_rtl();

$prime_proof = array(
	array(
		$prime_rtl ? 'مصمّم جاهز للطباعة' : 'Print-ready designer',
		$prime_rtl ? 'صمّم أونلاين أو ارفع تصميمك، ونجهّز ملف الطباعة لك.' : 'Design online or upload yours. We prepare the print file.',
	),
	array(
		$prime_rtl ? 'سعر فوري أو عرض سعر' : 'Instant price or quote',
		$prime_rtl ? 'احسب السعر مباشرة، أو اطلب عرض سعر للأعمال الخاصة.' : 'Calculate the price now, or request a quote for custom jobs.',
	),
	array(
		$prime_rtl ? 'الشحن خلال 48 ساعة' : 'Ships within 48 hours',
		$prime_rtl ? 'بعد اعتماد التصميم.' : 'After your design is approved.',
	),
);

$prime_cats = prime_product_categories( 6 );
?>

<section class="prime-proof" aria-label="<?php echo esc_attr( $prime_rtl ? 'لماذا برايم' : 'Why Prime' ); ?>">
	<div class="prime-wrap">
		<ul class="prime-proof__list">
			<?php foreach ( $prime_proof as $prime_item ) : ?>
				<li class="prime-proof__item">
					<?php prime_mark(); ?>
					<span class="prime-proof__title"><?php echo esc_html( $prime_item[0] ); ?></span>
					<span class="prime-proof__line"><?php echo esc_html( $prime_item[1] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>

<?php if ( $prime_cats ) : ?>
	<section class="prime-home-cats" aria-label="<?php echo esc_attr( $prime_rtl ? 'التصنيفات' : 'Categories' ); ?>">
		<div class="prime-wrap">
			<div class="prime-cats prime-cats--home">
				<?php
				foreach ( $prime_cats as $prime_cat ) {
					get_template_part( 'template-parts/shop/category-card', null, array( 'term' => $prime_cat ) );
				}
				?>
			</div>
		</div>
	</section>
<?php endif; ?>
