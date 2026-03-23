<?php
/**
 * Picked Up email template.
 *
 * Override by copying to: yourtheme/woocommerce/emails/es-picked-up.php
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var WC_Email $email
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php printf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<p><?php esc_html_e( 'This confirms that your order has been picked up. Thank you for shopping with us!', 'erpnext-shipping' ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_footer', $email );
