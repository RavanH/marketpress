jQuery( document ).ready( function( $ ) {

    $( '#poststuff' ).append( '<div class="mp-admin-overlay"><div class="mp-variation-loading-spin"></div><div class="mp-variation-loading-message">' + mp_product_admin_i18n.creating_vatiations_message + '</div></div>' );

    function mp_variation_message( ) {
        $( '.mp-variation-loading-spin' ).css( {
            position: 'fixed',
            left: ( $( '.mp-admin-overlay' ).width( ) - $( '.mp-variation-loading-spin' ).outerWidth( ) ) / 2,
            top: ( $( '.mp-admin-overlay' ).height( ) - $( '.mp-variation-loading-spin' ).outerHeight( ) ) / 2
        } );
        var new_top = parseInt( $( '.mp-variation-loading-spin' ).css( 'top' ) );
        new_top = new_top + 50;
        $( '.mp-variation-loading-message' ).css( {
            position: 'absolute',
            left: ( $( '.mp-admin-overlay' ).width( ) - $( '.mp-variation-loading-message' ).outerWidth( ) ) / 2,
            top: new_top
        } );
    }

    $( window ).resize( function( ) {
        mp_variation_message( );
    } );

    $( window ).resize( );
    /* Variations product name set */
    $( '.mp_variations_product_name' ).html( $( '#title' ).val( ) );
    $( '#title' ).keyup( function( ) {
        $( '.mp_variations_product_name' ).html( $( '#title' ).val( ) );
    } );

    $( '.repeat' ).each( function( ) {
        $( this ).repeatable_fields( );
    } );

    $( '.mp_product_attributes_select' ).live( 'change', function( ) {
        if ( $( this ).val( ) == '-1' ) {
            $( this ).parent( ).find( '.mp-variation-attribute-name' ).show( );
        } else {
            $( this ).parent( ).find( '.mp-variation-attribute-name' ).hide( );
        }
    } );

    $( '.select_attributes_filter a' ).live( 'click', function( event ) {
        $( '.select_attributes_filter a' ).removeClass( 'selected' );
        if ( $( this ).hasClass( 'selected' ) ) {
            $( this ).removeClass( 'selected' );
        } else {
            $( this ).addClass( 'selected' );
        }

//Select All link clicked
        if ( $( this ).hasClass( 'select_all_link' ) ) {
            $( '#cb-select-all' ).prop( "checked", true );
            $( '.check-column .check-column-box' ).prop( "checked", true );
        }

//Select None link clicked
        if ( $( this ).hasClass( 'select_none_link' ) ) {
            $( '#cb-select-all' ).prop( "checked", false );
            $( '.check-column .check-column-box' ).prop( "checked", false );
        }

//Variation filter clicked
        if ( !$( this ).hasClass( 'select_none_link' ) && !$( this ).hasClass( 'select_all_link' ) ) {
            var term_id = $( this ).parent( ).data( 'term-id' );
            $( '.check-column .check-column-box' ).prop( "checked", false );
            $( '.variation_term_' + term_id ).each( function( index ) {
                $( this ).closest( 'tr' ).find( '.check-column .check-column-box' ).prop( "checked", true );
            } );
        }

        event.preventDefault( );
    } );
    $( ".select_attributes_filter a" ).live( 'focus', function( event ) {
        $( this ).blur( );
    } );
    $( '#mp_make_combinations' ).live( 'click', function( event ) {

//alert($( '#original_publish' ).val());
        if ( $( '#original_publish' ).val( ) == 'Publish' ) {
//$( '.mp-admin-overlay' ).show();
            $( '#save-post' ).removeAttr( 'dasabled' );
            //$( '#save-post' ).prop( 'disabled', false );
            $( '#save-post' ).click( );
            //mp_variation_message();
        }

        if ( $( '#original_publish' ).val( ) == 'Update' ) {
//$( '.mp-admin-overlay' ).show();
            $( '#publish' ).removeAttr( 'dasabled' );
            //$( '#publish' ).prop( 'disabled', false );
            $( '#publish' ).click( );
            //mp_variation_message();
        }

//$( 'form#post' ).submit();

        event.preventDefault( );
    } );
    /*$( '#mp_make_combinations' ).live( 'click', function( event ) {
     $( '.mp-admin-overlay' ).show();
     mp_variation_message();
     
     var data = $( 'form#post' ).serialize();
     data['action'] = 'save_init_product_variations';
     
     $.post(
     mp_product_admin_i18n.ajaxurl, data
     ).done( function( data, status ) {
     if(status == 'success'){
     $( '.mp-admin-overlay' ).hide();
     }else{
     //an error occured
     }
     } );
     
     event.preventDefault();
     } );*/
    $( '.mp-add-new-variation' ).click( );
    //$( '.variation-row' ).css( 'border-bottom', '1px' );
    //$( '.variation-row:last-child' ).css( 'border-bottom', '0px' );    

} );
/* INLINE EDIT */

jQuery( document ).ready( function( $ ) {

    $.fn.selectRange = function( start, end ) {
        return this.each( function() {
            if ( this.setSelectionRange ) {
                this.focus();
                this.setSelectionRange( start, end );
            } else if ( this.createTextRange ) {
                var range = this.createTextRange();
                range.collapse( true );
                range.moveEnd( 'character', end );
                range.moveStart( 'character', start );
                range.select();
            }
        } );
    };

    $.fn.inlineEdit = function( replaceWith, connectWith ) {

        $( this ).hover( function( ) {
            $( this ).append( '<span class="inline-edit-icon"><i class="fa fa-pencil fa-lg"></i></span>' );
        }, function( ) {
            $( this ).find( '.inline-edit-icon' ).remove( );
        } );
        $( this ).click( function( ) {

            var post_id = $( this ).closest( 'tr' ).find( '.check-column .check-column-box' ).val( );
            var data_meta = $( this ).attr( 'data-meta' );
            var data_type = $( this ).closest( 'td' ).attr( 'data-field-type' );
            var data_default = $( this ).attr( 'data-default' );

            var elem = $( this );

            elem.hide( );
            elem.after( replaceWith );
            replaceWith.focus( );
            var len = $( replaceWith ).val().length * 2;//has to be * 2 because how Opera counts carriage returns
//            /console.log( $( replaceWith ) );
            //$( replaceWith ).setCursorPosition( 4 );
            $( replaceWith ).selectRange(len,len);

            replaceWith.blur( function( ) {

                if ( $( this ).val( ) != "" ) {
                    connectWith.val( $( this ).val( ) ).change( );
                    if ( data_type == 'number' ) {
                        var numeric_value = $( this ).val();
                        numeric_value = numeric_value.replace( ",", "" );
                        if ( $.isNumeric( numeric_value ) ) {
                            elem.text( numeric_value );
                        } else {
                            elem.text( 0 );
                        }
                        save_inline_post_data( post_id, data_meta, numeric_value );
                    } else {
                        elem.text( $( this ).val( ) );
                        save_inline_post_data( post_id, data_meta, $( this ).val( ) );
                    }
                } else {
                    elem.text( data_default );
                    save_inline_post_data( post_id, data_meta, '' );
                }

                $( this ).remove( );
                elem.show( );
            } );
        } );
    };

    $( ".original_value" ).each( function( index ) {
        $( this ).inlineEdit( $( '<input name="temp" class="mp_inline_temp_value" type="text" value="' + $.trim( $( this ).html( ) ) + '" />' ), $( 'input.editable_value' ) );
    } );

    $( ".mp_inline_temp_value" ).live( 'keyup', function( e ) {
        if ( e.keyCode == 13 ) {
            $( this ).blur( );
        }
        e.preventDefault( );
    } );

    $( window ).keydown( function( event ) {
        if ( event.keyCode == 13 ) {
            event.preventDefault( );
            return false;
        }
    } );

    function save_inline_post_data( post_id, meta_name, meta_value ) {
        var data = {
            action: 'save_inline_post_data',
            post_id: post_id,
            meta_name: meta_name,
            meta_value: meta_value,
            ajax_nonce: mp_product_admin_i18n.ajax_nonce
        }

        $.post(
            mp_product_admin_i18n.ajaxurl, data
            ).done( function( data, status ) {
            if ( status == 'success' ) {
                //alert( 'success!' );
            } else {
                //alert( 'fail!' );
                //an error occured
            }
        } );
    }

} );