<?php
defined( 'ABSPATH' ) || exit;

class ES_Email_Processing_LP extends WC_Email {

    public function __construct() {
        $this->id             = 'es_processing_lp';
        $this->customer_email = true;
        $this->title          = __( 'Processing Pickup', 'erpnext-shipping' );
        $this->description    = __( 'Sent when a pickup order starts being prepared.', 'erpnext-shipping' );
        $this->heading        = __( 'Your pickup order is being prepared', 'erpnext-shipping' );
        $this->subject        = __( 'Your order #{order_number} is being prepared for pickup', 'erpnext-shipping' );
        $this->template_html  = '';
        $this->template_plain = '';
        $this->placeholders   = array( '{order_number}' => '', '{order_date}' => '' );

        add_action( 'woocommerce_order_status_processing-lp', array( $this, 'trigger' ), 10, 2 );
        parent::__construct();
    }

    public function trigger( $order_id, $order = null ) {
        $this->setup_locale();
        if ( ! $order ) { $order = wc_get_order( $order_id ); }
        if ( ! $order ) { return; }

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
        $order = $this->object;
        ob_start();
        do_action( 'woocommerce_email_header', $this->get_heading(), $this );
        echo '<p>' . sprintf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) ) . '</p>';
        echo '<p>' . esc_html__( 'We\'re preparing your order for pickup. We\'ll let you know when it\'s ready to collect.', 'erpnext-shipping' ) . '</p>';
        do_action( 'woocommerce_email_footer', $this );
        return ob_get_clean();
    }

    public function get_content_plain() {
        $order = $this->object;
        ob_start();
        echo sprintf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
        echo esc_html__( 'We\'re preparing your order for pickup. We\'ll let you know when it\'s ready to collect.', 'erpnext-shipping' ) . "\n";
        return ob_get_clean();
    }
}
