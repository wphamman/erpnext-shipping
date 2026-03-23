<?php
defined( 'ABSPATH' ) || exit;

/**
 * Checkout integration: pickup location selector.
 * Shows a location dropdown when customer selects Local Pickup shipping,
 * saves the selection to order meta, and validates at checkout.
 */
class ES_Fulfillment_Checkout {

    public static function init() {
        // Show pickup location dropdown after the Local Pickup shipping rate.
        add_action( 'woocommerce_after_shipping_rate', array( __CLASS__, 'render_pickup_selector' ), 10, 2 );

        // Validate pickup location is selected before order is placed.
        add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_pickup_location' ) );

        // Save pickup location to order meta.
        add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_pickup_location' ), 10, 2 );

        // Update order status to Processing LP for pickup orders (after payment).
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_set_processing_lp' ), 10, 2 );

        // Frontend styles.
        add_action( 'wp_head', array( __CLASS__, 'checkout_css' ) );
    }

    /**
     * Render pickup location dropdown after the Local Pickup rate option.
     */
    public static function render_pickup_selector( $method, $index ) {
        // Only show for local_pickup methods.
        if ( 'local_pickup' !== $method->method_id ) {
            return;
        }

        $locations = get_option( 'es_shipping_locations', array() );
        $pickup_locations = array_filter( $locations, function( $loc ) {
            return ! empty( $loc['pickup_enabled'] );
        } );

        if ( empty( $pickup_locations ) ) {
            return;
        }

        $chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods', array() ) : array();
        $is_selected    = in_array( $method->id, $chosen_methods, true );
        $saved_loc      = WC()->session ? WC()->session->get( 'es_pickup_location_id', '' ) : '';
        ?>
        <div class="es-pickup-selector" data-rate-id="<?php echo esc_attr( $method->id ); ?>" style="<?php echo $is_selected ? '' : 'display:none;'; ?>">
            <label for="es_pickup_location_<?php echo esc_attr( $index ); ?>" class="es-pickup-label">
                <?php esc_html_e( 'Pickup Location', 'erpnext-shipping' ); ?>
            </label>
            <select name="es_pickup_location_id" id="es_pickup_location_<?php echo esc_attr( $index ); ?>" class="es-pickup-select">
                <option value=""><?php esc_html_e( '— Select pickup location —', 'erpnext-shipping' ); ?></option>
                <?php foreach ( $pickup_locations as $loc ) : ?>
                    <option value="<?php echo esc_attr( $loc['id'] ); ?>"
                            data-message="<?php echo esc_attr( $loc['customer_message'] ?? '' ); ?>"
                            data-address="<?php echo esc_attr( self::format_address( $loc ) ); ?>"
                            <?php selected( $saved_loc, $loc['id'] ); ?>>
                        <?php echo esc_html( $loc['name'] . ' — ' . ( $loc['city'] ?? '' ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="es-pickup-details" style="display:none;">
                <p class="es-pickup-address"></p>
                <p class="es-pickup-message"></p>
            </div>
        </div>
        <script>
        (function() {
            if (window.esPickupInitialized) return;
            window.esPickupInitialized = true;

            jQuery(function($) {
                // Show/hide pickup selector when shipping method changes.
                $(document.body).on('updated_checkout updated_shipping_method', function() {
                    var chosen = $('input.shipping_method:checked, input.shipping_method[type="hidden"]').val() || '';
                    var isPickup = chosen.indexOf('local_pickup') !== -1;

                    $('.es-pickup-selector').each(function() {
                        var $sel = $(this);
                        var rateId = $sel.data('rate-id');
                        $sel.toggle(chosen === rateId);
                    });
                });

                // Show location details when selection changes.
                $(document.body).on('change', '.es-pickup-select', function() {
                    var $opt = $(this).find(':selected');
                    var $details = $(this).siblings('.es-pickup-details');
                    var address = $opt.data('address') || '';
                    var message = $opt.data('message') || '';

                    if (address || message) {
                        $details.show();
                        $details.find('.es-pickup-address').html(address ? '<strong>' + address + '</strong>' : '');
                        $details.find('.es-pickup-message').html(message || '').toggle(!!message);
                    } else {
                        $details.hide();
                    }

                    // Save to session via AJAX so it persists across checkout updates.
                    if ($(this).val()) {
                        $.post(wc_checkout_params.ajax_url, {
                            action: 'es_save_checkout_pickup',
                            location_id: $(this).val(),
                            _wpnonce: '<?php echo wp_create_nonce( 'es_checkout_pickup' ); ?>'
                        });
                    }
                });

                // Trigger initial state.
                $(document.body).trigger('updated_checkout');

                // Show details for pre-selected location.
                $('.es-pickup-select').each(function() {
                    if ($(this).val()) $(this).trigger('change');
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Format a location address for display.
     */
    private static function format_address( $loc ) {
        $parts = array_filter( array(
            $loc['street'] ?? '',
            $loc['suburb'] ?? '',
            $loc['city'] ?? '',
            $loc['province'] ?? '',
            $loc['postcode'] ?? '',
        ) );
        return implode( ', ', $parts );
    }

    /**
     * Validate that a pickup location was selected.
     */
    public static function validate_pickup_location() {
        $chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods', array() ) : array();
        $is_pickup = false;
        foreach ( $chosen_methods as $method ) {
            if ( str_contains( $method, 'local_pickup' ) ) {
                $is_pickup = true;
                break;
            }
        }

        if ( ! $is_pickup ) {
            return;
        }

        $location_id = sanitize_text_field( $_POST['es_pickup_location_id'] ?? '' );
        if ( empty( $location_id ) ) {
            wc_add_notice( __( 'Please select a pickup location.', 'erpnext-shipping' ), 'error' );
        }
    }

    /**
     * Save pickup location to order meta during checkout.
     */
    public static function save_pickup_location( $order, $data ) {
        $location_id = sanitize_text_field( $_POST['es_pickup_location_id'] ?? '' );
        if ( ! empty( $location_id ) ) {
            $order->update_meta_data( '_es_pickup_location_id', $location_id );
        }
    }

    /**
     * Auto-set Processing LP status for pickup orders.
     * Fires when order moves to 'processing' (after payment).
     */
    public static function maybe_set_processing_lp( $order_id, $order ) {
        if ( ! $order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        // Check if this is a pickup order by looking at shipping methods.
        $shipping_methods = $order->get_shipping_methods();
        $is_pickup = false;
        foreach ( $shipping_methods as $method ) {
            if ( 'local_pickup' === $method->get_method_id() ) {
                $is_pickup = true;
                break;
            }
        }

        // Also check if a pickup location was set.
        if ( ! $is_pickup ) {
            $pickup_loc = $order->get_meta( '_es_pickup_location_id', true );
            if ( ! empty( $pickup_loc ) ) {
                $is_pickup = true;
            }
        }

        if ( $is_pickup ) {
            // Change from processing to processing-lp.
            $order->update_status( 'processing-lp', __( 'Pickup order — moved to Processing LP.', 'erpnext-shipping' ) );
        }
    }

    /**
     * AJAX: Save pickup location to WC session during checkout.
     */
    public static function ajax_save_checkout_pickup() {
        check_ajax_referer( 'es_checkout_pickup' );
        $location_id = sanitize_text_field( $_POST['location_id'] ?? '' );
        if ( WC()->session ) {
            WC()->session->set( 'es_pickup_location_id', $location_id );
        }
        wp_send_json_success();
    }

    /**
     * Minimal checkout CSS.
     */
    public static function checkout_css() {
        if ( ! is_checkout() && ! is_cart() ) {
            return;
        }
        ?>
        <style>
        .es-pickup-selector { margin: 8px 0 4px 24px; }
        .es-pickup-label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 4px; }
        .es-pickup-select { width: 100%; max-width: 350px; }
        .es-pickup-details { margin-top: 6px; padding: 8px 12px; background: #f8f8f8; border-left: 3px solid #7ad03a; font-size: 13px; }
        .es-pickup-message { color: #666; font-style: italic; margin-top: 4px; }
        </style>
        <?php
    }
}

// Register AJAX handler (must be outside class for both logged-in and guest users).
add_action( 'wp_ajax_es_save_checkout_pickup', array( 'ES_Fulfillment_Checkout', 'ajax_save_checkout_pickup' ) );
add_action( 'wp_ajax_nopriv_es_save_checkout_pickup', array( 'ES_Fulfillment_Checkout', 'ajax_save_checkout_pickup' ) );

ES_Fulfillment_Checkout::init();
