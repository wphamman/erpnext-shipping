<?php
defined( 'ABSPATH' ) || exit;

class ES_Email_Pickup_Reminder extends WC_Email {

    /** @var int Reminder number (1 or 2). */
    private $reminder_number = 1;

    public function __construct() {
        $this->id             = 'es_pickup_reminder';
        $this->customer_email = true;
        $this->title          = __( 'Pickup Reminder', 'erpnext-shipping' );
        $this->description    = __( 'Sent automatically to remind customers to collect their order.', 'erpnext-shipping' );
        $this->heading        = __( 'Reminder: your order is waiting for pickup', 'erpnext-shipping' );
        $this->subject        = __( 'Reminder: order #{order_number} is ready for pickup', 'erpnext-shipping' );
        $this->template_html  = 'emails/es-pickup-reminder.php';
        $this->template_plain = 'emails/plain/es-pickup-reminder.php';
        $this->template_base  = ES_SHIPPING_PATH . 'templates/';
        $this->placeholders   = array( '{order_number}' => '', '{order_date}' => '' );

        // No status trigger — watchdog calls trigger() directly.
        parent::__construct();
    }

    /**
     * @param int           $order_id
     * @param WC_Order|null $order
     * @param int           $reminder_number  1 = first reminder, 2 = second reminder.
     */
    public function trigger( $order_id, $order = null, $reminder_number = 1 ) {
        $this->setup_locale();
        if ( ! $order ) { $order = wc_get_order( $order_id ); }
        if ( ! $order ) { $this->restore_locale(); return; }

        $this->reminder_number = $reminder_number;
        $this->object    = $order;
        $this->recipient = $order->get_billing_email();
        $this->placeholders['{order_number}'] = $order->get_order_number();
        $this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );

        if ( $this->is_enabled() && $this->get_recipient() ) {
            $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
        }
        $this->restore_locale();
    }

    private function get_pickup_location() {
        $loc_id    = $this->object->get_meta( '_es_pickup_location_id', true );
        $locations = get_option( 'es_shipping_locations', array() );
        foreach ( $locations as $loc ) {
            if ( ( $loc['id'] ?? '' ) === $loc_id ) {
                return $loc;
            }
        }
        return array();
    }

    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            array(
                'order'            => $this->object,
                'email_heading'    => $this->get_heading(),
                'pickup_location'  => $this->get_pickup_location(),
                'reminder_number'  => $this->reminder_number,
                'sent_to_admin'    => false,
                'plain_text'       => false,
                'email'            => $this,
            ),
            '',
            $this->template_base
        );
    }

    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            array(
                'order'            => $this->object,
                'email_heading'    => $this->get_heading(),
                'pickup_location'  => $this->get_pickup_location(),
                'reminder_number'  => $this->reminder_number,
                'sent_to_admin'    => false,
                'plain_text'       => true,
                'email'            => $this,
            ),
            '',
            $this->template_base
        );
    }
}
