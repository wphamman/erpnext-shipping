<?php
/**
 * Picked Up plain text email.
 *
 * Override by copying to: yourtheme/woocommerce/emails/plain/es-picked-up.php
 */
defined( 'ABSPATH' ) || exit;

echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
printf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) );
echo "\n\n";
esc_html_e( 'This confirms that your order has been picked up. Thank you for shopping with us!', 'erpnext-shipping' );
echo "\n\n";
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
echo "\n";
echo wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
