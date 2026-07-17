<?php
/**
 * "Parcel In Locker" email template.
 *
 * Override by copying to: yourtheme/woocommerce/emails/es-locker-arrived.php
 *
 * Deliberately does NOT restate the collection PIN — the provider's own message
 * carries it, we do not hold it, and a second source of truth for a security
 * credential is a liability.
 *
 * @var WC_Order $order
 * @var string   $locker_name
 * @var string   $locker_address
 * @var string   $deadline_text   Localised deadline, or '' when arrival is unknown.
 * @var float    $collect_hours   Operator-set collection window.
 * @var string   $email_heading
 * @var WC_Email $email
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php printf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<p><?php esc_html_e( 'Good news — your order has arrived and is waiting for you in the locker:', 'erpnext-shipping' ); ?></p>

<p style="background:#f8f8f8; padding:12px; border-left:4px solid #7ad03a; margin:16px 0;">
	<strong><?php echo esc_html( $locker_name ); ?></strong>
	<?php if ( '' !== $locker_address ) : ?>
		<br><?php echo esc_html( $locker_address ); ?>
	<?php endif; ?>
</p>

<?php if ( '' !== $deadline_text ) : ?>
	<p style="background:#fff8e5; padding:12px; border-left:4px solid #f0ad4e; margin:16px 0;">
		<strong><?php esc_html_e( 'Please collect by:', 'erpnext-shipping' ); ?></strong>
		<?php echo esc_html( $deadline_text ); ?><br>
		<span style="color:#666;">
			<?php
			printf(
				/* translators: %s: number of hours */
				esc_html__( 'Parcels are held in the locker for %s hours. After that it is returned and we will need to arrange redelivery.', 'erpnext-shipping' ),
				esc_html( rtrim( rtrim( number_format( (float) $collect_hours, 1 ), '0' ), '.' ) )
			);
			?>
		</span>
	</p>
<?php else : ?>
	<p style="background:#fff8e5; padding:12px; border-left:4px solid #f0ad4e; margin:16px 0;">
		<?php
		printf(
			/* translators: %s: number of hours */
			esc_html__( 'Please collect within %s hours — parcels left longer than that are returned.', 'erpnext-shipping' ),
			esc_html( rtrim( rtrim( number_format( (float) $collect_hours, 1 ), '0' ), '.' ) )
		);
		?>
	</p>
<?php endif; ?>

<p><?php esc_html_e( 'Your collection PIN was sent to you separately by the locker provider — you will need it to open the door.', 'erpnext-shipping' ); ?></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
