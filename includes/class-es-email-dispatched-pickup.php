<?php
defined( 'ABSPATH' ) || exit;

class ES_Email_Dispatched_Pickup extends WC_Email {

    public function __construct() {
        $this->id             = 'es_dispatched_pickup';
        $this->customer_email = true;
        $this->title          = __( 'Dispatched to Pickup', 'erpnext-shipping' );
        $this->description    = __( 'Sent when a pickup order has been dispatched to the collection location.', 'erpnext-shipping' );
        $this->heading        = __( 'Your order is on its way to the pickup location', 'erpnext-shipping' );
        $this->subject        = __( 'Your order #{order_number} has been dispatched for pickup', 'erpnext-shipping' );
        $this->template_html  = '';
        $this->template_plain = '';
        $this->placeholders   = array( '{order_number}' => '', '{order_date}' => '' );

        add_action( 'woocommerce_order_status_dispatched-pickup', array( $this, 'trigger' ), 10, 2 );
        parent::__construct();
    }

    public function trigger( $order_id, $order = null ) {
        $this->setup_locale();
        if ( ! $order ) { $order = wc_get_order( $order_id ); }
        if ( ! $order ) { return; }

        // Resolve pickup location for the email body.
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
        $order = $this->object;
        ob_start();
        do_action( 'woocommerce_email_header', $this->get_heading(), $this );
        echo '<p>' . sprintf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) ) . '</p>';
        echo '<p>' . esc_html__( 'Your order has been dispatched to your selected pickup location. We\'ll notify you when it\'s ready to collect.', 'erpnext-shipping' ) . '</p>';
        $this->render_pickup_location( $order );
        do_action( 'woocommerce_email_footer', $this );
        return ob_get_clean();
    }

    public function get_content_plain() {
        $order = $this->object;
        ob_start();
        echo sprintf( esc_html__( 'Hi %s,', 'erpnext-shipping' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
        echo esc_html__( 'Your order has been dispatched to your selected pickup location. We\'ll notify you when it\'s ready to collect.', 'erpnext-shipping' ) . "\n\n";
        $this->render_pickup_location( $order, true );
        return ob_get_clean();
    }

    private function render_pickup_location( $order, $plain = false ) {
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

        $message = $loc['customer_message'] ?? '';

        if ( $plain ) {
            echo esc_html__( 'Pickup location:', 'erpnext-shipping' ) . "\n";
            echo implode( ', ', array_map( 'esc_html', $parts ) ) . "\n";
            if ( $message ) {
                echo "\n" . esc_html( $message ) . "\n";
            }
        } else {
            echo '<p style="background:#f8f8f8; padding:12px; border-left:4px solid #5b9bd5; margin:16px 0;">';
            echo '<strong>' . esc_html( $loc['name'] ?? '' ) . '</strong><br>';
            echo esc_html( implode( ', ', array_slice( $parts, 1 ) ) );
            if ( $message ) {
                echo '<br><em style="color:#666;">' . esc_html( $message ) . '</em>';
            }
            echo '</p>';
        }
    }
}
