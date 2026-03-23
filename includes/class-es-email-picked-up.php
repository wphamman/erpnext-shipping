<?php
defined( 'ABSPATH' ) || exit;

class ES_Email_Picked_Up extends WC_Email {

    public function __construct() {
        $this->id             = 'es_picked_up';
        $this->customer_email = true;
        $this->title          = __( 'Order Picked Up', 'erpnext-shipping' );
        $this->description    = __( 'Sent when a pickup order has been collected by the customer.', 'erpnext-shipping' );
        $this->heading        = __( 'Your order has been picked up', 'erpnext-shipping' );
        $this->subject        = __( 'Your order #{order_number} has been picked up', 'erpnext-shipping' );
        $this->template_html  = 'emails/es-picked-up.php';
        $this->template_plain = 'emails/plain/es-picked-up.php';
        $this->template_base  = ES_SHIPPING_PATH . 'templates/';
        $this->placeholders   = array( '{order_number}' => '', '{order_date}' => '' );

        add_action( 'woocommerce_order_status_pickup', array( $this, 'trigger' ), 10, 2 );
        parent::__construct();
    }

    public function trigger( $order_id, $order = null ) {
        $this->setup_locale();
        if ( ! $order ) { $order = wc_get_order( $order_id ); }
        if ( ! $order ) { return; }

        $pickup_loc = ES_Fulfillment_Statuses::resolve_pickup_location( $order );
        if ( empty( $pickup_loc ) ) {
            $this->restore_locale();
            return;
        }

        $this->object    = $order;
        $this->recipient = $order->get_billing_email();
        $this->placeholders['{order_number}'] = $order->get_order_number();
        $this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );

        if ( $this->is_enabled() && $this->get_recipient() ) {
            $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
        }
        $this->restore_locale();
    }

    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            array(
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => false,
                'email'         => $this,
            ),
            '',
            $this->template_base
        );
    }

    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            array(
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => true,
                'email'         => $this,
            ),
            '',
            $this->template_base
        );
    }
}
