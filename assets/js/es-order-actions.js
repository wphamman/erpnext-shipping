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

    // Row-action variants are real admin-post.php links with their own nonces;
    // they're handled server-side, no JS interception needed.
}( jQuery ) );
