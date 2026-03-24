<?php
/**
 * Pickup Reminder plain text email.
 *
 * Override by copying to: yourtheme/woocommerce/emails/plain/es-pickup-reminder.php
 */
defined( 'ABSPATH' ) || exit;

echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
printf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) );
echo "\n\n";

if ( 2 === $reminder_number ) {
	esc_html_e( 'This is a final reminder that your order is still waiting to be collected. Please arrange to pick it up as soon as possible.', 'erpnext-shipping' );
} else {
	esc_html_e( 'Just a friendly reminder that your order is ready and waiting for you to collect.', 'erpnext-shipping' );
}
echo "\n\n";

if ( ! empty( $pickup_location ) ) {
	$parts = array_filter( array(
		$pickup_location['name'] ?? '',
		$pickup_location['street'] ?? '',
		$pickup_location['suburb'] ?? '',
		$pickup_location['city'] ?? '',
		$pickup_location['province'] ?? '',
		$pickup_location['postcode'] ?? '',
	) );
	echo implode( ', ', array_map( 'esc_html', $parts ) ) . "\n";
	$message = $pickup_location['customer_message'] ?? '';
	if ( $message ) {
		echo "\n" . esc_html( $message ) . "\n";
	}
	echo "\n";
}

esc_html_e( 'Please bring your order confirmation or ID when collecting.', 'erpnext-shipping' );
echo "\n\n";
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
echo "\n";
echo wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
