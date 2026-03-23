<?php
/**
 * Ready for Pickup email template.
 *
 * Override by copying to: yourtheme/woocommerce/emails/es-ready-pickup.php
 *
 * @var WC_Order $order
 * @var array    $pickup_location  Location data array
 * @var string   $email_heading
 * @var WC_Email $email
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php printf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<p><?php esc_html_e( 'Your order is ready for pickup at:', 'erpnext-shipping' ); ?></p>

<?php if ( ! empty( $pickup_location ) ) :
	$parts = array_filter( array(
		$pickup_location['street'] ?? '',
		$pickup_location['suburb'] ?? '',
		$pickup_location['city'] ?? '',
		$pickup_location['province'] ?? '',
		$pickup_location['postcode'] ?? '',
	) );
	$message = $pickup_location['customer_message'] ?? '';
?>
<p style="background:#f8f8f8; padding:12px; border-left:4px solid #7ad03a; margin:16px 0;">
	<strong><?php echo esc_html( $pickup_location['name'] ?? '' ); ?></strong><br>
	<?php echo esc_html( implode( ', ', $parts ) ); ?>
	<?php if ( $message ) : ?>
		<br><em style="color:#666;"><?php echo esc_html( $message ); ?></em>
	<?php endif; ?>
</p>
<?php endif; ?>

<p><?php esc_html_e( 'Please bring your order confirmation or ID when collecting.', 'erpnext-shipping' ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_footer', $email );
