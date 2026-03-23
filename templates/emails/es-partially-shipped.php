<?php
/**
 * Partially Shipped email template.
 *
 * Override by copying to: yourtheme/woocommerce/emails/es-partially-shipped.php
 *
 * @var WC_Order $order
 * @var array    $tracking_items  Array of tracking item arrays
 * @var string   $email_heading
 * @var WC_Email $email
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php printf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<p><?php esc_html_e( 'Part of your order has been shipped. Here are the tracking details:', 'erpnext-shipping' ); ?></p>

<?php if ( ! empty( $tracking_items ) ) : ?>
<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #e5e5e5; margin:16px 0;" border="1">
	<thead>
		<tr>
			<th style="text-align:left; padding:12px; border:1px solid #e5e5e5;"><?php esc_html_e( 'Carrier', 'erpnext-shipping' ); ?></th>
			<th style="text-align:left; padding:12px; border:1px solid #e5e5e5;"><?php esc_html_e( 'Tracking Number', 'erpnext-shipping' ); ?></th>
			<th style="text-align:left; padding:12px; border:1px solid #e5e5e5;"><?php esc_html_e( 'Date', 'erpnext-shipping' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $tracking_items as $item ) :
			$provider_name = ES_Fulfillment_Tracking::get_provider_name( $item['tracking_provider'] );
			$url           = ES_Fulfillment_Tracking::get_tracking_url( $item );
			$date          = date_i18n( get_option( 'date_format' ), $item['date_shipped'] );
		?>
		<tr>
			<td style="padding:12px; border:1px solid #e5e5e5;"><?php echo esc_html( $provider_name ); ?></td>
			<td style="padding:12px; border:1px solid #e5e5e5;">
				<?php if ( $url ) : ?>
					<a href="<?php echo esc_url( $url ); ?>" style="color:#7f54b3;"><?php echo esc_html( $item['tracking_number'] ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $item['tracking_number'] ); ?>
				<?php endif; ?>
			</td>
			<td style="padding:12px; border:1px solid #e5e5e5;"><?php echo esc_html( $date ); ?></td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<p><?php esc_html_e( 'We will notify you when the remaining items ship.', 'erpnext-shipping' ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_footer', $email );
