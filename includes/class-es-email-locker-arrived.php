<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Your parcel is in the locker" — sent once, when TCG reports the parcel has
 * landed in the customer's destination locker.
 *
 * Triggered by the `es_tcg_locker_arrived` action (fired from the tracking poll),
 * NOT by a WC status transition: `in-locker` maps to `completed`, but so do the
 * earlier transit states, so the order is already `completed` by the time it lands
 * and never transitions on arrival.
 *
 * This is additive to PUDO's own notification (which carries the collection PIN).
 * We deliberately do NOT restate the PIN — we do not hold it, and a second source
 * of truth for a security credential is a liability. We state where it is and by
 * when it must be collected.
 */
class ES_Email_Locker_Arrived extends WC_Email {

	public function __construct() {
		$this->id             = 'es_locker_arrived';
		$this->customer_email = true;
		$this->title          = __( 'Parcel In Locker', 'erpnext-shipping' );
		$this->description    = __( 'Sent when a locker parcel arrives in the customer\'s destination locker, with the collection deadline.', 'erpnext-shipping' );
		$this->heading        = __( 'Your parcel is in the locker', 'erpnext-shipping' );
		$this->subject        = __( 'Your order #{order_number} is ready to collect from {locker_name}', 'erpnext-shipping' );
		$this->template_html  = 'emails/es-locker-arrived.php';
		$this->template_plain = 'emails/plain/es-locker-arrived.php';
		$this->template_base  = ES_SHIPPING_PATH . 'templates/';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{order_date}'   => '',
			'{locker_name}'  => '',
		);

		add_action( 'es_tcg_locker_arrived', array( $this, 'trigger' ), 10, 2 );
		parent::__construct();
	}

	public function trigger( $order_id, $order = null ) {
		$this->setup_locale();
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			$this->restore_locale();
			return;
		}

		// ES_TCG_Locker_Rate (not ES_TCG_Locker_Admin) — the admin class is only
		// loaded behind is_admin(), and this fires from WP-Cron.
		$snapshot = ES_TCG_Locker_Rate::read_order_snapshot( $order );
		if ( empty( $snapshot ) ) {
			// No locker snapshot: nothing truthful to say about where it is.
			$this->restore_locale();
			return;
		}

		$this->object    = $order;
		$this->recipient = $order->get_billing_email();

		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{locker_name}']  = (string) ( $snapshot[ ES_TCG_Locker_Rate::M_DEST_NAME ] ?? '' );

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	/** Locker snapshot for the templates. */
	private function snapshot() {
		if ( ! $this->object ) {
			return array();
		}
		return (array) ES_TCG_Locker_Rate::read_order_snapshot( $this->object );
	}

	/**
	 * The collection window THIS order was sold on.
	 *
	 * Prefers the value snapshotted onto the shipping item at checkout, so that
	 * retuning the setting after the order was placed cannot move a deadline the
	 * customer was already promised. Falls back to the current setting for orders
	 * placed before the snapshot existed.
	 */
	private function collect_hours() {
		$snap = $this->snapshot();
		$sold = $snap[ ES_TCG_Locker_Rate::M_COLLECT_HOURS ] ?? null;
		if ( null !== $sold && '' !== $sold ) {
			return ES_TCG_Locker_Tracking::sane_collection_hours( $sold );
		}
		return es_tcg_locker_collection_hours();
	}

	/**
	 * Collection deadline as a site-local display string, or '' when unknown.
	 * Derived from the provider's own arrival event (see ES_Fulfillment_Cron::
	 * maybe_notify_locker_arrival) — never from poll time.
	 */
	private function deadline_text() {
		$arrival = (int) $this->object->get_meta( '_es_tcg_locker_in_locker_at', true );
		if ( $arrival <= 0 ) {
			return '';
		}
		$deadline = ES_TCG_Locker_Tracking::collection_deadline( $arrival, $this->collect_hours() );
		if ( $deadline <= 0 ) {
			return '';
		}
		// The arrival was read on the provider's clock and stored as a true UTC
		// timestamp, so rendering it in the site timezone shows the same instant.
		return wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $deadline );
	}

	private function template_args() {
		$snap = $this->snapshot();
		return array(
			'order'           => $this->object,
			'email_heading'   => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'locker_name'     => (string) ( $snap[ ES_TCG_Locker_Rate::M_DEST_NAME ] ?? '' ),
			'locker_address'  => (string) ( $snap[ ES_TCG_Locker_Rate::M_DEST_ADDRESS ] ?? '' ),
			'deadline_text'   => $this->deadline_text(),
			'collect_hours'   => $this->collect_hours(),
			'sent_to_admin'   => false,
			'plain_text'      => false,
			'email'           => $this,
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args(), '', $this->template_base );
	}

	public function get_content_plain() {
		$args               = $this->template_args();
		$args['plain_text'] = true;
		return wc_get_template_html( $this->template_plain, $args, '', $this->template_base );
	}
}
