<?php
/**
 * Processing LP (Pickup) email template.
 *
 * Override by copying to: yourtheme/woocommerce/emails/es-processing-lp.php
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var WC_Email $email
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php printf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<p><?php esc_html_e( "We're preparing your order for pickup. We'll let you know when it's ready to collect.", 'erpnext-shipping' ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_footer', $email );
