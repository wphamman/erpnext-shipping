<?php
defined( 'ABSPATH' ) || exit;

class ES_Email_Ready_Pickup extends WC_Email {

    public function __construct() {
        $this->id             = 'es_ready_pickup';
        $this->customer_email = true;
        $this->title          = __( 'Ready For Pickup', 'erpnext-shipping' );
        $this->description    = __( 'Sent when a pickup order is ready to collect.', 'erpnext-shipping' );
        $this->heading        = __( 'Your order is ready for pickup', 'erpnext-shipping' );
        $this->subject        = __( 'Your order #{order_number} is ready for pickup', 'erpnext-shipping' );
        $this->template_html  = '';
        $this->template_plain = '';
        $this->placeholders   = array( '{order_number}' => '', '{order_date}' => '' );

        add_action( 'woocommerce_order_status_ready-pickup', array( $this, 'trigger' ), 10, 2 );
        parent::__construct();
    }

    public function trigger( $order_id, $order = null ) {
        $this->setup_locale();
        if ( ! $order ) { $order = wc_get_order( $order_id ); }
        if ( ! $order ) { return; }

        // Suppress if no pickup location is set.
        $pickup_loc = $order->get_meta( '_es_pickup_location_id', true );
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
        $order = $this->object;
        ob_start();
        do_action( 'woocommerce_email_header', $this->get_heading(), $this );
        echo '<p>' . sprintf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) ) . '</p>';
        echo '<p>' . esc_html__( 'Your order is ready for pickup at:', 'erpnext-shipping' ) . '</p>';
        $this->render_pickup_address( $order );
        echo '<p>' . esc_html__( 'Please bring your order confirmation or ID when collecting.', 'erpnext-shipping' ) . '</p>';
        do_action( 'woocommerce_email_footer', $this );
        return ob_get_clean();
    }

    public function get_content_plain() {
        $order = $this->object;
        ob_start();
        echo sprintf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
        echo esc_html__( 'Your order is ready for pickup at:', 'erpnext-shipping' ) . "\n";
        $this->render_pickup_address( $order, true );
        echo "\n" . esc_html__( 'Please bring your order confirmation or ID when collecting.', 'erpnext-shipping' ) . "\n";
        return ob_get_clean();
    }

    private function render_pickup_address( $order, $plain = false ) {
        $loc_id    = $order->get_meta( '_es_pickup_location_id', true );
        $locations = get_option( 'es_shipping_locations', array() );
        $loc       = null;

        foreach ( $locations as $l ) {
            if ( $l['id'] === $loc_id ) {
                $loc = $l;
                break;
            }
        }

        if ( ! $loc ) {
            return;
        }

        $parts = array_filter( array(
            $loc['name'] ?? '',
            $loc['street'] ?? '',
            $loc['suburb'] ?? '',
            $loc['city'] ?? '',
            $loc['province'] ?? '',
            $loc['postcode'] ?? '',
        ) );

        if ( $plain ) {
            echo implode( ', ', array_map( 'esc_html', $parts ) ) . "\n";
        } else {
            echo '<p style="background:#f8f8f8; padding:12px; border-left:4px solid #7ad03a; margin:16px 0;">';
            echo '<strong>' . esc_html( $loc['name'] ?? '' ) . '</strong><br>';
            echo esc_html( implode( ', ', array_slice( $parts, 1 ) ) );
            echo '</p>';
        }
    }
}
