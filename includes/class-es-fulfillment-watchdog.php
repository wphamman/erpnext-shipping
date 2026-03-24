<?php
defined( 'ABSPATH' ) || exit;

/**
 * Watchdog: daily cron that alerts on stale orders, sends pickup reminders,
 * and flags repeated courier API failures.
 */
class ES_Fulfillment_Watchdog {

    /**
     * Default thresholds (days). Overridden by saved options.
     */
    private static $default_thresholds = array(
        'processing_days'        => 3,
        'processing_lp_days'     => 3,
        'on_hold_days'           => 2,
        'dispatched_pickup_days' => 2,
        'shipped_days'           => 7,
        'pickup_reminder_1_days' => 3,
        'pickup_reminder_2_days' => 10,
        'api_fail_threshold'     => 3,
    );

    /**
     * Get watchdog settings merged with defaults.
     */
    private static function get_settings() {
        $saved = get_option( 'es_watchdog_settings', array() );
        return wp_parse_args( $saved, self::$default_thresholds );
    }

    /**
     * Main watchdog run — called by daily cron.
     */
    public static function run() {
        $settings = self::get_settings();
        $logger   = wc_get_logger();
        $ctx      = array( 'source' => 'erpnext-shipping-watchdog' );

        $admin_alerts = array();

        // 1. Stale order checks (admin alerts).
        $status_checks = array(
            array(
                'key'       => 'processing_days',
                'status'    => 'processing',
                'label'     => 'Processing',
                'enabled'   => 'alert_processing',
            ),
            array(
                'key'       => 'processing_lp_days',
                'status'    => 'processing-lp',
                'label'     => 'Processing LP',
                'enabled'   => 'alert_processing_lp',
            ),
            array(
                'key'       => 'on_hold_days',
                'status'    => 'on-hold',
                'label'     => 'On Hold',
                'enabled'   => 'alert_on_hold',
            ),
            array(
                'key'       => 'dispatched_pickup_days',
                'status'    => 'dispatched-pickup',
                'label'     => 'Dispatched to Pickup',
                'enabled'   => 'alert_dispatched_pickup',
            ),
            array(
                'key'       => 'shipped_days',
                'status'    => 'completed',
                'label'     => 'Shipped',
                'enabled'   => 'alert_shipped',
            ),
        );

        foreach ( $status_checks as $check ) {
            if ( 'no' === ( $settings[ $check['enabled'] ] ?? 'yes' ) ) {
                continue;
            }

            $days       = absint( $settings[ $check['key'] ] ?? 3 );
            $cutoff     = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
            $stale      = self::get_stale_orders( $check['status'], $cutoff, $days );

            if ( ! empty( $stale ) ) {
                $admin_alerts[] = array(
                    'label'  => $check['label'],
                    'days'   => $days,
                    'orders' => $stale,
                );
            }
        }

        // 2. API failure alerts.
        if ( 'no' !== ( $settings['alert_api_failures'] ?? 'yes' ) ) {
            $threshold    = absint( $settings['api_fail_threshold'] ?? 3 );
            $failed_orders = self::get_api_failure_orders( $threshold );
            if ( ! empty( $failed_orders ) ) {
                $admin_alerts[] = array(
                    'label'  => 'Courier API Failures (>' . $threshold . ' consecutive)',
                    'days'   => 0,
                    'orders' => $failed_orders,
                );
            }
        }

        // 3. Send admin digest if there are alerts.
        if ( ! empty( $admin_alerts ) ) {
            self::send_admin_digest( $admin_alerts );
            $logger->info( 'Watchdog sent admin digest with ' . count( $admin_alerts ) . ' alert group(s).', $ctx );
        }

        // 4. Customer pickup reminders.
        if ( 'no' !== ( $settings['alert_pickup_reminder'] ?? 'yes' ) ) {
            $reminder_1_days = absint( $settings['pickup_reminder_1_days'] ?? 3 );
            $reminder_2_days = absint( $settings['pickup_reminder_2_days'] ?? 10 );
            self::send_pickup_reminders( $reminder_1_days, $reminder_2_days, $logger, $ctx );
        }

        // Record last run.
        update_option( 'es_watchdog_last_run', time() );
    }

    /**
     * Get orders stuck in a status since before the cutoff date.
     * Uses date_modified (updated on status changes) rather than date_created
     * to avoid false positives for orders that recently changed status.
     */
    private static function get_stale_orders( $status, $cutoff, $days = 3 ) {
        $orders = wc_get_orders( array(
            'status'        => $status,
            'date_modified' => '<' . $cutoff,
            'limit'         => 50,
            'orderby'       => 'date',
            'order'         => 'ASC',
        ) );

        $result = array();
        foreach ( $orders as $order ) {
            $result[] = array(
                'id'      => $order->get_id(),
                'number'  => $order->get_order_number(),
                'date'    => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
                'total'   => $order->get_total(),
                'name'    => $order->get_formatted_billing_full_name(),
            );
        }
        return $result;
    }

    /**
     * Get orders with consecutive courier API poll failures.
     */
    private static function get_api_failure_orders( $threshold ) {
        $orders = wc_get_orders( array(
            'status'     => array( 'completed', 'partially-shipped' ),
            'meta_query' => array(
                array(
                    'key'     => '_es_poll_fail_count',
                    'value'   => $threshold,
                    'compare' => '>=',
                    'type'    => 'NUMERIC',
                ),
            ),
            'limit' => 50,
        ) );

        $result = array();
        foreach ( $orders as $order ) {
            $fail_count = absint( $order->get_meta( '_es_poll_fail_count', true ) );
            $result[]   = array(
                'id'         => $order->get_id(),
                'number'     => $order->get_order_number(),
                'date'       => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
                'total'      => $order->get_total(),
                'name'       => $order->get_formatted_billing_full_name(),
                'fail_count' => $fail_count,
            );
        }
        return $result;
    }

    /**
     * Send one admin digest email with all alert groups.
     */
    private static function send_admin_digest( $alert_groups ) {
        $admin_email = get_option( 'admin_email' );
        $site_name   = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
        $admin_url   = admin_url( 'admin.php?page=wc-orders' );

        $subject = sprintf( '[%s] Order Watchdog Alert — %d issue(s) found', $site_name, count( $alert_groups ) );

        $body  = '<div style="font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 600px;">';
        $body .= '<h2 style="color: #333;">Order Watchdog Alert</h2>';
        $body .= '<p>The following orders need attention:</p>';

        foreach ( $alert_groups as $group ) {
            $body .= '<h3 style="color: #d63638; margin: 20px 0 10px;">' . esc_html( $group['label'] );
            if ( $group['days'] > 0 ) {
                $body .= ' (>' . absint( $group['days'] ) . ' days)';
            }
            $body .= '</h3>';
            $body .= '<table style="border-collapse: collapse; width: 100%; font-size: 13px;">';
            $body .= '<tr style="background: #f0f0f1;"><th style="padding: 6px 10px; text-align: left;">Order</th><th style="padding: 6px 10px; text-align: left;">Customer</th><th style="padding: 6px 10px; text-align: left;">Date</th><th style="padding: 6px 10px; text-align: right;">Total</th></tr>';

            foreach ( $group['orders'] as $o ) {
                $order_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $o['id'] );
                $body .= '<tr>';
                $body .= '<td style="padding: 4px 10px;"><a href="' . esc_url( $order_url ) . '">#' . esc_html( $o['number'] ) . '</a></td>';
                $body .= '<td style="padding: 4px 10px;">' . esc_html( $o['name'] ) . '</td>';
                $body .= '<td style="padding: 4px 10px;">' . esc_html( $o['date'] ) . '</td>';
                $body .= '<td style="padding: 4px 10px; text-align: right;">' . wc_price( $o['total'] ) . '</td>';
                $body .= '</tr>';
            }

            $body .= '</table>';
        }

        $body .= '<p style="margin-top: 20px;"><a href="' . esc_url( $admin_url ) . '">View All Orders</a></p>';
        $body .= '</div>';

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        wp_mail( $admin_email, $subject, $body, $headers );
    }

    /**
     * Send pickup reminder emails to customers.
     * Uses meta _es_pickup_reminder_sent to track which reminders have been sent.
     * Value: 0 = none, 1 = first sent, 2 = second sent.
     */
    private static function send_pickup_reminders( $days_1, $days_2, $logger, $ctx ) {
        if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
            $logger->error( 'WooCommerce mailer not available — skipping pickup reminders.', $ctx );
            return;
        }

        $wc_emails = WC()->mailer()->get_emails();

        if ( ! isset( $wc_emails['ES_Email_Pickup_Reminder'] ) ) {
            $logger->error( 'ES_Email_Pickup_Reminder class not available — skipping reminders.', $ctx );
            return;
        }

        $reminder_email = $wc_emails['ES_Email_Pickup_Reminder'];

        // Reminder 1: orders in ready-pickup for >= $days_1 days, not yet reminded.
        $cutoff_1 = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_1} days" ) );
        $orders_1 = self::get_ready_pickup_orders( $cutoff_1, 0 );

        foreach ( $orders_1 as $order ) {
            $reminder_email->trigger( $order->get_id(), $order, 1 );
            $order->update_meta_data( '_es_pickup_reminder_sent', 1 );
            $order->save();
            $logger->info( 'Pickup reminder 1 sent for order #' . $order->get_order_number(), $ctx );
        }

        // Reminder 2: orders in ready-pickup for >= $days_2 days, only first reminder sent.
        $cutoff_2 = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_2} days" ) );
        $orders_2 = self::get_ready_pickup_orders( $cutoff_2, 1 );

        foreach ( $orders_2 as $order ) {
            $reminder_email->trigger( $order->get_id(), $order, 2 );
            $order->update_meta_data( '_es_pickup_reminder_sent', 2 );
            $order->save();
            $logger->info( 'Pickup reminder 2 sent for order #' . $order->get_order_number(), $ctx );
        }
    }

    /**
     * Get orders in ready-pickup status, created before cutoff, with specific reminder level.
     */
    private static function get_ready_pickup_orders( $cutoff, $reminder_level ) {
        $meta_query = array(
            'relation' => 'AND',
        );

        if ( 0 === $reminder_level ) {
            // No reminder sent yet: meta doesn't exist or is 0.
            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_es_pickup_reminder_sent',
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'     => '_es_pickup_reminder_sent',
                    'value'   => '0',
                    'compare' => '=',
                ),
            );
        } else {
            $meta_query[] = array(
                'key'     => '_es_pickup_reminder_sent',
                'value'   => (string) $reminder_level,
                'compare' => '=',
            );
        }

        return wc_get_orders( array(
            'status'        => 'ready-pickup',
            'date_modified' => '<' . $cutoff,
            'meta_query'    => $meta_query,
            'limit'         => 50,
        ) );
    }

    /**
     * Record a poll failure for an order. Called from the cron class.
     * NOTE: Caller must call $order->save() to persist the meta change.
     */
    public static function record_poll_failure( $order ) {
        $count = absint( $order->get_meta( '_es_poll_fail_count', true ) );
        $order->update_meta_data( '_es_poll_fail_count', $count + 1 );
    }

    /**
     * Reset poll failure count (called when a poll succeeds).
     * NOTE: Caller must call $order->save() to persist the meta change.
     */
    public static function reset_poll_failure( $order ) {
        $fail_count = $order->get_meta( '_es_poll_fail_count', true );
        if ( '' !== $fail_count && '0' !== $fail_count ) {
            $order->update_meta_data( '_es_poll_fail_count', 0 );
        }
    }
}
