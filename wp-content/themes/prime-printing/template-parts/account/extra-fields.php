<?php
/**
 * Account → Details tab — extra fields injected into WooCommerce's own
 * edit-account form (see prime_account_extra_fields() in inc/account.php).
 *
 * WooCommerce's edit-account form has exactly one submit button covering the
 * whole form; the reference mockup shows two separately-saved cards
 * ("Save changes" / "Save preferences"), but splitting that into two real
 * forms would mean overriding the entire form-edit-account.php template just
 * to move a hook. Everything here saves together with WooCommerce's own
 * "Save changes" button instead — one click, not a pixel-for-pixel copy of
 * the mockup's two-button layout.
 *
 * @package PrimePrinting
 *
 * @var string $company_name
 * @var string $tax_number
 * @var string $notify_whatsapp
 * @var string $notify_invoice
 * @var string $notify_marketing
 */

defined( 'ABSPATH' ) || exit;
?>

<fieldset class="prime-account-extra">
	<legend><?php esc_html_e( 'Company billing details', 'prime-printing' ); ?></legend>

	<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
		<label for="prime_company_name"><?php esc_html_e( 'Company name (optional)', 'prime-printing' ); ?></label>
		<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="prime_company_name" id="prime_company_name" value="<?php echo esc_attr( $company_name ); ?>" />
	</p>
	<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
		<label for="prime_tax_number"><?php esc_html_e( 'Tax number (optional)', 'prime-printing' ); ?></label>
		<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="prime_tax_number" id="prime_tax_number" value="<?php echo esc_attr( $tax_number ); ?>" />
	</p>
	<div class="clear"></div>
	<p class="prime-account-extra__hint">
		<?php esc_html_e( 'Used on your invoices when set.', 'prime-printing' ); ?>
	</p>
</fieldset>

<fieldset class="prime-account-extra">
	<legend><?php esc_html_e( 'Notifications', 'prime-printing' ); ?></legend>

	<p class="prime-account-extra__checkbox">
		<label>
			<input type="checkbox" name="prime_notify_whatsapp" value="1" <?php checked( $notify_whatsapp, 'yes' ); ?> />
			<?php esc_html_e( 'Order updates on WhatsApp', 'prime-printing' ); ?>
		</label>
	</p>
	<p class="prime-account-extra__checkbox">
		<label>
			<input type="checkbox" name="prime_notify_invoice_email" value="1" <?php checked( $notify_invoice, 'yes' ); ?> />
			<?php esc_html_e( 'Invoices by email', 'prime-printing' ); ?>
		</label>
	</p>
	<p class="prime-account-extra__checkbox">
		<label>
			<input type="checkbox" name="prime_notify_marketing" value="1" <?php checked( $notify_marketing, 'yes' ); ?> />
			<?php esc_html_e( 'Offers and seasonal products', 'prime-printing' ); ?>
		</label>
	</p>
</fieldset>

<?php wp_nonce_field( 'prime_account_extra_fields', 'prime_account_nonce' ); ?>
