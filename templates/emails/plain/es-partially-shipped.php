<?php
/**
 * Partially Shipped plain text email.
 *
 * Override by copying to: yourtheme/woocommerce/emails/plain/es-partially-shipped.php
 */
defined( 'ABSPATH' ) || exit;

echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
printf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) );
echo "\n\n";
esc_html_e( 'Part of your order has been shipped. Here are the tracking details:', 'erpnext-shipping' );
echo "\n\n";

if ( ! empty( $tracking_items ) ) {
	foreach ( $tracking_items as $item ) {
		$provider_name = ES_Fulfillment_Tracking::get_provider_name( $item['tracking_provider'] );
		$url           = ES_Fulfillment_Tracking::get_tracking_url( $item );
		$date          = date_i18n( get_option( 'date_format' ), $item['date_shipped'] );
		echo esc_html( $provider_name ) . ': ' . esc_html( $item['tracking_number'] ) . "\n";
		if ( $url ) {
			echo esc_html__( 'Track: ', 'erpnext-shipping' ) . esc_url( $url ) . "\n";
		}
		echo esc_html__( 'Shipped: ', 'erpnext-shipping' ) . esc_html( $date ) . "\n\n";
	}
}

esc_html_e( 'We will notify you when the remaining items ship.', 'erpnext-shipping' );
echo "\n\n";
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
echo "\n";
echo wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
