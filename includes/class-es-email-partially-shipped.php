<?php
defined( 'ABSPATH' ) || exit;

class ES_Email_Partially_Shipped extends WC_Email {

    public function __construct() {
        $this->id             = 'es_partially_shipped';
        $this->customer_email = true;
        $this->title          = __( 'Partially Shipped', 'erpnext-shipping' );
        $this->description    = __( 'Sent when an order is marked as partially shipped.', 'erpnext-shipping' );
        $this->heading        = __( 'Part of your order has been shipped', 'erpnext-shipping' );
        $this->subject        = __( 'Part of your order #{order_number} has been shipped', 'erpnext-shipping' );
        $this->template_html  = '';
        $this->template_plain = '';
        $this->placeholders   = array(
            '{order_number}' => '',
            '{order_date}'   => '',
        );

        add_action( 'woocommerce_order_status_partially-shipped', array( $this, 'trigger' ), 10, 2 );

        parent::__construct();
    }

    public function trigger( $order_id, $order = null ) {
        $this->setup_locale();

        if ( ! $order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
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
        return $this->render_email_content( false );
    }

    public function get_content_plain() {
        return $this->render_email_content( true );
    }

    private function render_email_content( $plain = false ) {
        $order = $this->object;

        ob_start();
        if ( ! $plain ) {
            do_action( 'woocommerce_email_header', $this->get_heading(), $this );
        }

        echo '<p>';
        printf(
            /* translators: %s: customer first name */
            esc_html__( 'Hi %s,', 'erpnext-shipping' ),
            esc_html( $order->get_billing_first_name() )
        );
        echo '</p>';
        echo '<p>' . esc_html__( 'Part of your order has been shipped. Here are the tracking details:', 'erpnext-shipping' ) . '</p>';

        $this->render_tracking_table( $order, $plain );

        echo '<p>' . esc_html__( 'We will notify you when the remaining items ship.', 'erpnext-shipping' ) . '</p>';

        if ( ! $plain ) {
            do_action( 'woocommerce_email_footer', $this );
        }

        return ob_get_clean();
    }

    private function render_tracking_table( $order, $plain = false ) {
        if ( ! class_exists( 'ES_Fulfillment_Tracking' ) ) {
            return;
        }
        $items = ES_Fulfillment_Tracking::get_tracking_items( $order );
        if ( empty( $items ) ) {
            return;
        }

        if ( $plain ) {
            foreach ( $items as $item ) {
                $url = ES_Fulfillment_Tracking::get_tracking_url( $item );
                echo esc_html( ES_Fulfillment_Tracking::get_provider_name( $item['tracking_provider'] ) ) . ': ';
                echo esc_html( $item['tracking_number'] );
                if ( $url ) {
                    echo ' - ' . esc_url( $url );
                }
                echo "\n";
            }
        } else {
            echo '<table cellspacing="0" cellpadding="6" border="1" style="border-collapse:collapse; width:100%; margin:16px 0;" bordercolor="#e5e5e5">';
            echo '<tr><th style="text-align:left;">' . esc_html__( 'Carrier', 'erpnext-shipping' ) . '</th>';
            echo '<th style="text-align:left;">' . esc_html__( 'Tracking Number', 'erpnext-shipping' ) . '</th>';
            echo '<th style="text-align:left;">' . esc_html__( 'Date', 'erpnext-shipping' ) . '</th></tr>';
            foreach ( $items as $item ) {
                $url = ES_Fulfillment_Tracking::get_tracking_url( $item );
                echo '<tr>';
                echo '<td>' . esc_html( ES_Fulfillment_Tracking::get_provider_name( $item['tracking_provider'] ) ) . '</td>';
                echo '<td>';
                if ( $url ) {
                    echo '<a href="' . esc_url( $url ) . '">' . esc_html( $item['tracking_number'] ) . '</a>';
                } else {
                    echo esc_html( $item['tracking_number'] );
                }
                echo '</td>';
                echo '<td>' . esc_html( date_i18n( get_option( 'date_format' ), $item['date_shipped'] ) ) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
    }
}
