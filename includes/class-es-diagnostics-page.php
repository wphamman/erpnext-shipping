<?php
defined( 'ABSPATH' ) || exit;

/**
 * Diagnostics tab — surfaces connectivity, recent rate quotes, cron polling
 * status, fusion sync errors, and cache controls in one admin view.
 *
 * Reached via: WP Admin > WooCommerce > ES Shipping > Diagnostics tab.
 */
class ES_Diagnostics_Page {

    const SLUG = 'es-shipping';

    public static function init() {
        // AJAX handlers for the in-tab buttons.
        add_action( 'wp_ajax_es_diag_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_es_diag_run_poll', array( __CLASS__, 'ajax_run_poll' ) );
        add_action( 'wp_ajax_es_diag_flush_cache', array( __CLASS__, 'ajax_flush_cache' ) );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.' );
        }

        $nonce = wp_create_nonce( 'es_diag' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'ERPNext Shipping', 'erpnext-shipping' ); ?></h1>
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>" class="nav-tab"><?php esc_html_e( 'Settings', 'erpnext-shipping' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=diagnostics' ) ); ?>" class="nav-tab nav-tab-active"><?php esc_html_e( 'Diagnostics', 'erpnext-shipping' ); ?></a>
            </h2>

            <div class="es-diag" data-nonce="<?php echo esc_attr( $nonce ); ?>" style="max-width:900px;">
                <?php
                self::section_connectivity( $nonce );
                self::section_rate_quotes();
                self::section_cron_polling( $nonce );
                self::section_sync_events();
                self::section_cache_maintenance( $nonce );
                ?>
            </div>

            <script>
            (function($){
                var nonce = $('.es-diag').data('nonce');
                function call(action, btn, args) {
                    var $b = $(btn);
                    var orig = $b.text();
                    $b.prop('disabled', true).text('Working…');
                    return $.post(ajaxurl, $.extend({ action: action, nonce: nonce }, args || {}))
                        .always(function(){ $b.prop('disabled', false).text(orig); });
                }
                $(document).on('click', '.es-diag-test-conn', function(e){
                    e.preventDefault();
                    var service = $(this).data('service');
                    var $row = $(this).closest('tr').find('.es-diag-result');
                    $row.text('…');
                    call('es_diag_test_connection', this, { service: service })
                        .done(function(r){ $row.css('color', r.success ? '#5b8a72' : '#b32d2e').text(r.data && r.data.message ? r.data.message : (r.success ? 'OK' : 'Failed')); })
                        .fail(function(x){ $row.css('color','#b32d2e').text((x.responseJSON && x.responseJSON.data && x.responseJSON.data.message) || 'Request failed'); });
                });
                $(document).on('click', '.es-diag-run-poll', function(e){
                    e.preventDefault();
                    var $r = $('.es-diag-poll-result');
                    $r.text('Running…');
                    call('es_diag_run_poll', this).done(function(r){
                        $r.text(r.data && r.data.message ? r.data.message : 'Done.');
                        setTimeout(function(){ window.location.reload(); }, 800);
                    }).fail(function(x){ $r.css('color','#b32d2e').text((x.responseJSON && x.responseJSON.data && x.responseJSON.data.message) || 'Failed'); });
                });
                $(document).on('click', '.es-diag-flush', function(e){
                    e.preventDefault();
                    var which = $(this).data('cache');
                    var $r = $(this).closest('tr').find('.es-diag-result');
                    call('es_diag_flush_cache', this, { which: which }).done(function(r){
                        $r.css('color','#5b8a72').text(r.data && r.data.message ? r.data.message : 'Flushed.');
                    }).fail(function(x){ $r.css('color','#b32d2e').text((x.responseJSON && x.responseJSON.data && x.responseJSON.data.message) || 'Failed'); });
                });
            })(jQuery);
            </script>
            <style>
                .es-diag .card { max-width: none; padding: 15px 20px; margin-bottom: 18px; }
                .es-diag table.widefat td, .es-diag table.widefat th { padding: 6px 10px; }
                .es-diag .es-diag-result { font-size: 12px; color: #5b8a72; }
                .es-diag .es-diag-fail { color: #b32d2e; }
            </style>
        </div>
        <?php
    }

    // ── Sections ──────────────────────────────────────────────────────────

    private static function section_connectivity( $nonce ) {
        $erp_url   = '';
        $erp_ok    = '—';
        if ( class_exists( 'ES_ERPNext_Client' ) ) {
            $client = ES_ERPNext_Client::from_settings( 5 );
            if ( $client ) {
                $erp_url = $client->get_base_url();
            }
        }
        $tcg_last = ES_Quote_Log::last_success( 'the-courier-guy' );
        $mds_last = ES_Quote_Log::last_success( 'mds-collivery' );
        ?>
        <div class="card">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Connectivity', 'erpnext-shipping' ); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Service', 'erpnext-shipping' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'erpnext-shipping' ); ?></th>
                    <th><?php esc_html_e( 'Last Success', 'erpnext-shipping' ); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><strong>ERPNext</strong><br><span style="color:#666;font-size:11px;"><?php echo esc_html( $erp_url ?: 'Not configured' ); ?></span></td>
                        <td><span class="es-diag-result">—</span></td>
                        <td><?php esc_html_e( '(test to check)', 'erpnext-shipping' ); ?></td>
                        <td><button type="button" class="button button-secondary es-diag-test-conn" data-service="erpnext"><?php esc_html_e( 'Test', 'erpnext-shipping' ); ?></button></td>
                    </tr>
                    <tr>
                        <td><strong>The Courier Guy</strong></td>
                        <td><?php echo $tcg_last ? '<span style="color:#5b8a72;">OK</span>' : '<span style="color:#aaa;">—</span>'; ?></td>
                        <td><?php echo $tcg_last ? esc_html( self::ago( $tcg_last['ts'] ) . ' · ' . $tcg_last['ms'] . 'ms' ) : '—'; ?></td>
                        <td><span class="es-diag-result">&nbsp;</span></td>
                    </tr>
                    <tr>
                        <td><strong>MDS Collivery</strong></td>
                        <td><?php echo $mds_last ? '<span style="color:#5b8a72;">OK</span>' : '<span style="color:#aaa;">—</span>'; ?></td>
                        <td><?php echo $mds_last ? esc_html( self::ago( $mds_last['ts'] ) . ' · ' . $mds_last['ms'] . 'ms' ) : '—'; ?></td>
                        <td><span class="es-diag-result">&nbsp;</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function section_rate_quotes() {
        $entries = ES_Quote_Log::recent( 20 );
        ?>
        <div class="card">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Recent Rate Quotes', 'erpnext-shipping' ); ?></h2>
            <?php if ( empty( $entries ) ) : ?>
                <p style="color:#666;"><?php esc_html_e( 'No quotes recorded yet. Visit checkout with a cart to generate some.', 'erpnext-shipping' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'When', 'erpnext-shipping' ); ?></th>
                        <th><?php esc_html_e( 'Carrier', 'erpnext-shipping' ); ?></th>
                        <th><?php esc_html_e( 'Dest', 'erpnext-shipping' ); ?></th>
                        <th><?php esc_html_e( 'Parcels', 'erpnext-shipping' ); ?></th>
                        <th><?php esc_html_e( 'Latency', 'erpnext-shipping' ); ?></th>
                        <th><?php esc_html_e( 'Result', 'erpnext-shipping' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $entries as $e ) : ?>
                        <tr>
                            <td><?php echo esc_html( self::ago( $e['ts'] ) ); ?></td>
                            <td><?php echo esc_html( $e['carrier'] ); ?></td>
                            <td><?php echo esc_html( $e['dest'] ); ?></td>
                            <td><?php echo esc_html( $e['parcels'] ); ?></td>
                            <td><?php echo esc_html( $e['ms'] . 'ms' ); ?></td>
                            <td>
                                <?php if ( $e['success'] ) : ?>
                                    <span style="color:#5b8a72;">OK</span>
                                <?php else : ?>
                                    <span style="color:#b32d2e;" title="<?php echo esc_attr( $e['error'] ?? '' ); ?>"><?php esc_html_e( 'FAIL', 'erpnext-shipping' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function section_cron_polling( $nonce ) {
        $summary = get_option( 'es_last_poll_summary', null );
        $lock    = get_transient( 'es_poll_lock' );
        ?>
        <div class="card">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Courier Polling', 'erpnext-shipping' ); ?></h2>
            <?php if ( ! $summary ) : ?>
                <p style="color:#666;"><?php esc_html_e( 'No polling cycles recorded yet.', 'erpnext-shipping' ); ?></p>
            <?php else : ?>
                <table class="widefat" style="margin-bottom:10px;">
                    <tr>
                        <th><?php esc_html_e( 'Last cycle started', 'erpnext-shipping' ); ?></th>
                        <td><?php echo esc_html( self::ago( $summary['started_at'] ) ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Duration', 'erpnext-shipping' ); ?></th>
                        <td><?php echo esc_html( intval( $summary['duration_ms'] ) . ' ms' ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Orders processed', 'erpnext-shipping' ); ?></th>
                        <td><?php echo esc_html( $summary['orders_processed'] ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Orders with API errors', 'erpnext-shipping' ); ?></th>
                        <td><?php echo esc_html( $summary['errors'] ); ?></td>
                    </tr>
                </table>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'Provider', 'erpnext-shipping' ); ?></th>
                        <th><?php esc_html_e( 'Polled', 'erpnext-shipping' ); ?></th>
                        <th><?php esc_html_e( 'Successes', 'erpnext-shipping' ); ?></th>
                        <th><?php esc_html_e( 'Failures', 'erpnext-shipping' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $summary['per_provider'] as $slug => $stats ) : ?>
                        <tr>
                            <td><?php echo esc_html( $slug ); ?></td>
                            <td><?php echo esc_html( $stats['polled'] ); ?></td>
                            <td><?php echo esc_html( $stats['successes'] ); ?></td>
                            <td><?php echo esc_html( $stats['failures'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <p style="margin-top:12px;">
                <button type="button" class="button button-secondary es-diag-run-poll" <?php echo $lock ? 'disabled' : ''; ?>>
                    <?php esc_html_e( 'Run poll now', 'erpnext-shipping' ); ?>
                </button>
                <span class="es-diag-poll-result" style="margin-left:10px; font-size:12px; color:#555;">
                    <?php echo $lock ? esc_html__( 'Poll cycle already running…', 'erpnext-shipping' ) : ''; ?>
                </span>
            </p>
        </div>
        <?php
    }

    private static function section_sync_events() {
        ?>
        <div class="card">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Recent ERPNext Sync Errors', 'erpnext-shipping' ); ?></h2>
            <?php
            $client = class_exists( 'ES_ERPNext_Client' ) ? ES_ERPNext_Client::from_settings( 5 ) : null;
            if ( ! $client ) {
                echo '<p style="color:#aaa;">' . esc_html__( 'ERPNext not configured — cannot fetch error log.', 'erpnext-shipping' ) . '</p>';
                echo '</div>';
                return;
            }
            $body = $client->get( '/api/resource/Error Log', array(
                'fields'            => wp_json_encode( array( 'name', 'method', 'error', 'creation' ) ),
                'filters'           => wp_json_encode( array(
                    array( 'method', 'like', '%woocommerce_fusion%' ),
                ) ),
                'order_by'          => 'creation desc',
                'limit_page_length' => 20,
            ) );

            if ( is_wp_error( $body ) ) {
                echo '<p style="color:#b32d2e;">' . esc_html( $body->get_error_message() ) . '</p></div>';
                return;
            }
            $rows = $body['data'] ?? array();
            if ( empty( $rows ) ) {
                echo '<p style="color:#666;">' . esc_html__( 'No recent fusion errors. Good.', 'erpnext-shipping' ) . '</p>';
            } else {
                echo '<table class="widefat striped"><thead><tr>';
                echo '<th>' . esc_html__( 'When', 'erpnext-shipping' ) . '</th>';
                echo '<th>' . esc_html__( 'Method', 'erpnext-shipping' ) . '</th>';
                echo '<th>' . esc_html__( 'Error', 'erpnext-shipping' ) . '</th>';
                echo '</tr></thead><tbody>';
                $erp_base = $client->get_base_url();
                foreach ( $rows as $r ) {
                    $ts     = strtotime( $r['creation'] ?? '' );
                    $method = $r['method'] ?? '';
                    $err    = (string) ( $r['error'] ?? '' );
                    $err    = substr( trim( preg_replace( '/\s+/', ' ', $err ) ), 0, 200 );
                    $link   = $erp_base . '/app/error-log/' . rawurlencode( $r['name'] ?? '' );
                    echo '<tr>';
                    echo '<td>' . esc_html( $ts ? self::ago( $ts ) : '' ) . '</td>';
                    echo '<td style="font-family:monospace;font-size:11px;">' . esc_html( $method ) . '</td>';
                    echo '<td><a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $err ) . '</a></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
            ?>
        </div>
        <?php
    }

    private static function section_cache_maintenance( $nonce ) {
        $last_stock = get_option( 'es_shipping_stock_last_sync', 0 );
        ?>
        <div class="card">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Cache & Maintenance', 'erpnext-shipping' ); ?></h2>
            <table class="widefat striped">
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e( 'Rate-quote log', 'erpnext-shipping' ); ?></strong></td>
                        <td><?php echo esc_html( sprintf( '%d entries', count( get_option( 'es_recent_quotes', array() ) ) ) ); ?></td>
                        <td><button type="button" class="button es-diag-flush" data-cache="quotes"><?php esc_html_e( 'Clear', 'erpnext-shipping' ); ?></button> <span class="es-diag-result">&nbsp;</span></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'ERP sync-state cache', 'erpnext-shipping' ); ?></strong></td>
                        <td><?php esc_html_e( '5-min per-order transients', 'erpnext-shipping' ); ?></td>
                        <td><button type="button" class="button es-diag-flush" data-cache="sync_state"><?php esc_html_e( 'Flush', 'erpnext-shipping' ); ?></button> <span class="es-diag-result">&nbsp;</span></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Stock cache', 'erpnext-shipping' ); ?></strong></td>
                        <td><?php echo $last_stock ? esc_html( 'Last sync ' . self::ago( $last_stock ) ) : esc_html__( 'Never synced', 'erpnext-shipping' ); ?></td>
                        <td><button type="button" class="button es-diag-flush" data-cache="stock"><?php esc_html_e( 'Force sync', 'erpnext-shipping' ); ?></button> <span class="es-diag-result">&nbsp;</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────

    public static function ajax_test_connection() {
        self::check_auth();
        $service = sanitize_key( $_POST['service'] ?? '' );

        if ( 'erpnext' === $service ) {
            $client = ES_ERPNext_Client::from_settings( 5 );
            if ( ! $client ) {
                wp_send_json_error( array( 'message' => 'Not configured' ), 400 );
            }
            $r = $client->test_connection();
            if ( is_wp_error( $r ) ) {
                wp_send_json_error( array( 'message' => $r->get_error_message() ), 502 );
            }
            wp_send_json_success( array( 'message' => 'OK' ) );
        }
        wp_send_json_error( array( 'message' => 'Unknown service' ), 400 );
    }

    public static function ajax_run_poll() {
        self::check_auth();
        if ( get_transient( 'es_poll_lock' ) ) {
            wp_send_json_error( array( 'message' => 'A poll cycle is already in progress.' ), 429 );
        }
        ES_Fulfillment_Cron::poll();
        wp_send_json_success( array( 'message' => 'Poll cycle completed.' ) );
    }

    public static function ajax_flush_cache() {
        self::check_auth();
        $which = sanitize_key( $_POST['which'] ?? '' );
        if ( 'quotes' === $which ) {
            ES_Quote_Log::clear();
            wp_send_json_success( array( 'message' => 'Quote log cleared.' ) );
        } elseif ( 'sync_state' === $which ) {
            global $wpdb;
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_es_sync_state_%' OR option_name LIKE '_transient_timeout_es_sync_state_%'" );
            wp_send_json_success( array( 'message' => 'Sync-state transients flushed.' ) );
        } elseif ( 'stock' === $which ) {
            // Trigger the stock-sync hook synchronously.
            do_action( 'es_shipping_stock_sync_cron' );
            wp_send_json_success( array( 'message' => 'Stock sync triggered.' ) );
        }
        wp_send_json_error( array( 'message' => 'Unknown cache' ), 400 );
    }

    private static function check_auth() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }
        $nonce = $_POST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'es_diag' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 400 );
        }
    }

    private static function ago( $ts ) {
        if ( ! $ts ) {
            return '—';
        }
        return human_time_diff( $ts ) . ' ago';
    }
}
