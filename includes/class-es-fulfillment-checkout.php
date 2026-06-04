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

        // Only offer pickup locations whose supplying warehouse(s) hold the ENTIRE
        // cart — otherwise collecting there would force a costly cross-branch transfer.
        $feasible = array();
        foreach ( $pickup_locations as $loc ) {
            if ( self::cart_collectable_at( $loc, $locations ) ) {
                $feasible[] = $loc;
            }
        }

        $chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods', array() ) : array();
        $is_selected    = in_array( $method->id, $chosen_methods, true );
        $saved_loc      = WC()->session ? WC()->session->get( 'es_pickup_location_id', '' ) : '';
        ?>
        <div class="es-pickup-selector" data-rate-id="<?php echo esc_attr( $method->id ); ?>" style="<?php echo $is_selected ? '' : 'display:none;'; ?>">
            <?php if ( empty( $feasible ) ) : ?>
                <div class="es-pickup-unavailable">
                    <?php esc_html_e( 'This order includes items from more than one branch, so it can’t be collected as a single pickup. Please choose a delivery option instead.', 'erpnext-shipping' ); ?>
                </div>
            <?php else : ?>
                <label for="es_pickup_location_<?php echo esc_attr( $index ); ?>" class="es-pickup-label">
                    <?php esc_html_e( 'Pickup Location', 'erpnext-shipping' ); ?>
                </label>
                <select name="es_pickup_location_id" id="es_pickup_location_<?php echo esc_attr( $index ); ?>" class="es-pickup-select">
                    <option value=""><?php esc_html_e( '— Select pickup location —', 'erpnext-shipping' ); ?></option>
                    <?php foreach ( $feasible as $loc ) : ?>
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
            <?php endif; ?>
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

                    // Save to session via AJAX so it persists across cart/checkout pages.
                    // Use woocommerce_params (available on both cart and checkout) with fallbacks.
                    var ajaxUrl = (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.ajax_url)
                        || (typeof woocommerce_params !== 'undefined' && woocommerce_params.ajax_url)
                        || (typeof wc_cart_params !== 'undefined' && wc_cart_params.ajax_url)
                        || '/wp-admin/admin-ajax.php';
                    $.post(ajaxUrl, {
                        action: 'es_save_checkout_pickup',
                        location_id: $(this).val() || '',
                        _wpnonce: '<?php echo wp_create_nonce( 'es_checkout_pickup' ); ?>'
                    });
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

        $all_locations = get_option( 'es_shipping_locations', array() );
        $feasible_ids  = array();
        foreach ( $all_locations as $loc ) {
            if ( ! empty( $loc['pickup_enabled'] ) && self::cart_collectable_at( $loc, $all_locations ) ) {
                $feasible_ids[] = $loc['id'];
            }
        }

        $location_id = sanitize_text_field( $_POST['es_pickup_location_id'] ?? '' );

        if ( empty( $feasible_ids ) ) {
            // No single pickup point can supply the whole cart → block (margin protection).
            wc_add_notice( __( 'Your order includes items from more than one branch, so it can’t be collected as a single pickup. Please choose a delivery option instead.', 'erpnext-shipping' ), 'error' );
        } elseif ( empty( $location_id ) ) {
            wc_add_notice( __( 'Please select a pickup location.', 'erpnext-shipping' ), 'error' );
        } elseif ( ! self::is_valid_pickup_location( $location_id ) ) {
            wc_add_notice( __( 'Invalid pickup location selected.', 'erpnext-shipping' ), 'error' );
        } elseif ( ! in_array( $location_id, $feasible_ids, true ) ) {
            wc_add_notice( __( 'Some items in your cart aren’t stocked at the selected pickup location and would need to be transferred. Please choose a different pickup location or a delivery option.', 'erpnext-shipping' ), 'error' );
        }
    }

    /**
     * The SLW term IDs of the warehouses that SUPPLY a given pickup location.
     *
     * - warehouse        → its own slw_term_id
     * - collection_point → the slw_term_id of each warehouse in its serviced_by list
     *                      (empty serviced_by → no terms → cannot fulfil → blocked,
     *                       per the "block until configured" policy)
     *
     * @param array $loc           The pickup location record.
     * @param array $all_locations All configured locations.
     * @return int[] Warehouse term IDs.
     */
    private static function get_pickup_source_terms( $loc, $all_locations ) {
        $type = $loc['type'] ?? 'warehouse';

        if ( 'collection_point' !== $type ) {
            $term = intval( $loc['slw_term_id'] ?? 0 );
            return $term > 0 ? array( $term ) : array();
        }

        $serviced = $loc['serviced_by'] ?? array();
        if ( empty( $serviced ) || ! is_array( $serviced ) ) {
            return array(); // not yet configured — block.
        }

        $by_id = array();
        foreach ( $all_locations as $l ) {
            $by_id[ $l['id'] ?? '' ] = $l;
        }

        $terms = array();
        foreach ( $serviced as $wid ) {
            $w = $by_id[ $wid ] ?? null;
            if ( $w ) {
                $term = intval( $w['slw_term_id'] ?? 0 );
                if ( $term > 0 ) {
                    $terms[] = $term;
                }
            }
        }
        return array_values( array_unique( $terms ) );
    }

    /**
     * Can the current cart be collected at this pickup location WITHOUT a
     * cross-branch transfer? True only if every warehoused item has enough
     * stock at one of the location's supplying warehouses.
     *
     * Items with no per-location stock data (fees, services) are ignored.
     *
     * @param array $loc           The pickup location record.
     * @param array $all_locations All configured locations.
     * @return bool
     */
    private static function cart_collectable_at( $loc, $all_locations ) {
        $type  = $loc['type'] ?? 'warehouse';
        $terms = self::get_pickup_source_terms( $loc, $all_locations );

        if ( empty( $terms ) ) {
            // Collection point with no serviced_by → block until configured (policy).
            // Warehouse with no SLW term → can't assess stock, so don't change
            // existing behaviour: allow pickup.
            return ( 'collection_point' !== $type );
        }

        if ( ! WC()->cart ) {
            return true;
        }

        foreach ( WC()->cart->get_cart() as $item ) {
            $pid = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
            $qty = intval( $item['quantity'] );

            // Non-warehoused item (no per-location stock data) → ignore.
            if ( ! self::product_has_location_stock( $pid ) ) {
                continue;
            }

            $available = 0;
            foreach ( $terms as $term ) {
                $available += intval( get_post_meta( $pid, '_stock_at_' . $term, true ) );
            }
            if ( $available < $qty ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a product carries any `_stock_at_<term>` meta for a configured
     * warehouse term (i.e. it is a warehoused/ERPNext-synced product).
     *
     * @param int $pid Product or variation ID.
     * @return bool
     */
    private static function product_has_location_stock( $pid ) {
        foreach ( self::all_warehouse_terms() as $term ) {
            if ( metadata_exists( 'post', $pid, '_stock_at_' . $term ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * All warehouse-type location term IDs.
     *
     * @return int[]
     */
    private static function all_warehouse_terms() {
        $locations = get_option( 'es_shipping_locations', array() );
        $terms     = array();
        foreach ( $locations as $loc ) {
            if ( ( $loc['type'] ?? 'warehouse' ) !== 'collection_point' ) {
                $term = intval( $loc['slw_term_id'] ?? 0 );
                if ( $term > 0 ) {
                    $terms[] = $term;
                }
            }
        }
        return array_values( array_unique( $terms ) );
    }

    /**
     * Save pickup location to order meta during checkout.
     * Only saves when the customer actually selected Local Pickup as the shipping method.
     */
    public static function save_pickup_location( $order, $data ) {
        // Only save pickup location if the order's shipping method is local_pickup.
        // This prevents session-leaked pickup selections from being saved on delivery orders.
        $is_pickup = false;
        $chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods', array() ) : array();
        foreach ( $chosen_methods as $method ) {
            if ( str_contains( $method, 'local_pickup' ) ) {
                $is_pickup = true;
                break;
            }
        }

        if ( ! $is_pickup ) {
            // Clear any session-leaked pickup location and don't save to order.
            if ( WC()->session ) {
                WC()->session->set( 'es_pickup_location_id', '' );
            }
            return;
        }

        $location_id = sanitize_text_field( $_POST['es_pickup_location_id'] ?? '' );
        if ( ! empty( $location_id ) && self::is_valid_pickup_location( $location_id ) ) {
            $order->update_meta_data( '_es_pickup_location_id', $location_id );
        }
    }

    /**
     * Auto-set Processing LP status for pickup orders.
     * Fires when order moves to 'processing' (after payment).
     * ONLY triggers when the shipping method is local_pickup — never from pickup meta alone,
     * as session-leaked meta can exist on delivery orders.
     */
    public static function maybe_set_processing_lp( $order_id, $order ) {
        if ( ! $order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        // Check if this is a pickup order by looking at shipping methods ONLY.
        // Do NOT fall back to checking _es_pickup_location_id meta — that can be
        // leaked from a previous session when customer browsed pickup then chose shipping.
        $shipping_methods = $order->get_shipping_methods();
        $is_pickup = false;
        foreach ( $shipping_methods as $method ) {
            if ( 'local_pickup' === $method->get_method_id() ) {
                $is_pickup = true;
                break;
            }
        }

        // Also handle orders with no shipping methods but local_pickup in chosen methods.
        if ( ! $is_pickup && empty( $shipping_methods ) ) {
            $chosen = $order->get_meta( '_chosen_shipping_methods', true );
            if ( is_array( $chosen ) ) {
                foreach ( $chosen as $m ) {
                    if ( str_contains( (string) $m, 'local_pickup' ) ) {
                        $is_pickup = true;
                        break;
                    }
                }
            }
        }

        if ( $is_pickup ) {
            $order->update_status( 'processing-lp', __( 'Pickup order — moved to Processing LP.', 'erpnext-shipping' ) );
        }
    }

    /**
     * AJAX: Save pickup location to WC session during checkout.
     */
    public static function ajax_save_checkout_pickup() {
        check_ajax_referer( 'es_checkout_pickup' );
        $location_id = sanitize_text_field( $_POST['location_id'] ?? '' );
        // Allow empty (clearing selection) or valid location IDs only.
        if ( ! empty( $location_id ) && ! self::is_valid_pickup_location( $location_id ) ) {
            wp_send_json_error( 'Invalid pickup location.' );
            return;
        }
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
        .es-pickup-unavailable { margin-top: 6px; padding: 10px 14px; background: #fcf0f1; border-left: 4px solid #d63638; color: #8a1f21; font-size: 13px; font-weight: 600; border-radius: 2px; }
        </style>
        <?php
    }

    /**
     * Check if a location ID is a valid, pickup-enabled location.
     */
    public static function is_valid_pickup_location( $location_id ) {
        $locations = get_option( 'es_shipping_locations', array() );
        foreach ( $locations as $loc ) {
            if ( ( $loc['id'] ?? '' ) === $location_id && ! empty( $loc['pickup_enabled'] ) ) {
                return true;
            }
        }
        return false;
    }
}

// Register AJAX handler (must be outside class for both logged-in and guest users).
add_action( 'wp_ajax_es_save_checkout_pickup', array( 'ES_Fulfillment_Checkout', 'ajax_save_checkout_pickup' ) );
add_action( 'wp_ajax_nopriv_es_save_checkout_pickup', array( 'ES_Fulfillment_Checkout', 'ajax_save_checkout_pickup' ) );

ES_Fulfillment_Checkout::init();
