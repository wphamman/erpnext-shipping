/* global jQuery, esOrderActions */
( function ( $ ) {
    'use strict';

    function postAction( action, button, $resultBox ) {
        var orderId = button.dataset.orderId;
        var nonce   = button.dataset.nonce;
        if ( ! orderId || ! nonce ) {
            return;
        }
        var $btn = $( button );
        var originalText = $btn.text();
        $btn.prop( 'disabled', true ).text( 'Working…' );
        if ( $resultBox ) {
            $resultBox.text( '' );
        }
        $.post( esOrderActions.ajaxurl, {
            action: action,
            order_id: orderId,
            nonce: nonce
        } )
            .done( function ( resp ) {
                var msg = ( resp && resp.data && resp.data.message ) ? resp.data.message : 'Done.';
                if ( $resultBox ) {
                    $resultBox.css( 'color', '#5b8a72' ).text( msg );
                } else {
                    window.alert( msg );
                }
            } )
            .fail( function ( xhr ) {
                var msg = 'Request failed.';
                try {
                    var r = xhr.responseJSON;
                    if ( r && r.data && r.data.message ) {
                        msg = r.data.message;
                    }
                } catch ( e ) { /* noop */ }
                if ( $resultBox ) {
                    $resultBox.css( 'color', '#b32d2e' ).text( msg );
                } else {
                    window.alert( msg );
                }
            } )
            .always( function () {
                $btn.prop( 'disabled', false ).text( originalText );
            } );
    }

    $( document ).on( 'click', '.es-action-repoll', function ( e ) {
        e.preventDefault();
        postAction( 'es_repoll_order', this, $( this ).closest( '.inside' ).find( '.es-action-result' ) );
    } );

    $( document ).on( 'click', '.es-action-force-sync', function ( e ) {
        e.preventDefault();
        postAction( 'es_force_sync', this, $( this ).closest( '.inside' ).find( '.es-action-result' ) );
    } );

    // Row-action variants: data attributes are injected at render time by class-es-fulfillment-admin filter.
    // Currently the WooCommerce row-action API only supplies a name + class string, so we look up the
    // order id from the row's data-id attribute on click.
    $( document ).on( 'click', '.es-action-repoll-row, .wc-action-button-es-action-repoll-row', function ( e ) {
        e.preventDefault();
        var $row = $( this ).closest( 'tr' );
        var orderId = $row.data( 'id' ) || $row.attr( 'id' ).replace( /[^0-9]/g, '' );
        if ( ! orderId ) {
            return;
        }
        // Row actions don't carry nonces — bounce to the meta box instead by opening the order.
        var editUrl = $row.find( 'a.order-view, a.row-title' ).attr( 'href' );
        if ( editUrl ) {
            window.location.href = editUrl + '#es-erpnext-actions';
        }
    } );

    $( document ).on( 'click', '.es-action-force-sync-row, .wc-action-button-es-action-force-sync-row', function ( e ) {
        e.preventDefault();
        var $row = $( this ).closest( 'tr' );
        var editUrl = $row.find( 'a.order-view, a.row-title' ).attr( 'href' );
        if ( editUrl ) {
            window.location.href = editUrl + '#es-erpnext-actions';
        }
    } );
}( jQuery ) );
