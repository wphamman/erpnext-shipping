<?php
/**
 * "Parcel In Locker" plain text email.
 *
 * Override by copying to: yourtheme/woocommerce/emails/plain/es-locker-arrived.php
 */
defined( 'ABSPATH' ) || exit;

$hours_label = rtrim( rtrim( number_format( (float) $collect_hours, 1 ), '0' ), '.' );

echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
printf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) );
echo "\n\n";
esc_html_e( 'Good news — your order has arrived and is waiting for you in the locker:', 'erpnext-shipping' );
echo "\n\n";

echo esc_html( $locker_name ) . "\n";
if ( '' !== $locker_address ) {
	echo esc_html( $locker_address ) . "\n";
}
echo "\n";

if ( '' !== $deadline_text ) {
	esc_html_e( 'Please collect by:', 'erpnext-shipping' );
	echo ' ' . esc_html( $deadline_text ) . "\n";
	printf(
		/* translators: %s: number of hours */
		esc_html__( 'Parcels are held in the locker for %s hours. After that it is returned and we will need to arrange redelivery.', 'erpnext-shipping' ),
		esc_html( $hours_label )
	);
	echo "\n\n";
} else {
	printf(
		/* translators: %s: number of hours */
		esc_html__( 'Please collect within %s hours — parcels left longer than that are returned.', 'erpnext-shipping' ),
		esc_html( $hours_label )
	);
	echo "\n\n";
}

esc_html_e( 'Your collection PIN was sent to you separately by the locker provider — you will need it to open the door.', 'erpnext-shipping' );
echo "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo "\n----------------------------------------\n\n";
echo wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
