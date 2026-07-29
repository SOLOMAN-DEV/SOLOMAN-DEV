( function ( $ ) {
	'use strict';

	function validateGstin( $input ) {
		var value = ( $input.val() || '' ).toUpperCase().trim();
		$input.val( value );

		if ( value.length !== 15 ) {
			$input.removeClass( 'wgt-valid' ).removeClass( 'wgt-invalid' );
			return;
		}

		if ( typeof wgtAdmin === 'undefined' || ! wgtAdmin.ajaxUrl ) {
			return;
		}

		$.post( wgtAdmin.ajaxUrl, {
			action: 'wgt_validate_gstin',
			nonce: wgtAdmin.nonce,
			gstin: value
		} ).done( function ( response ) {
			if ( response && response.valid ) {
				$input.removeClass( 'wgt-invalid' ).addClass( 'wgt-valid' );
				if ( response.state ) {
					var $form = $input.closest( 'form' );
					$form.find( '.wgt-state-select' ).val( response.state ).trigger( 'change' );
				}
			} else {
				$input.removeClass( 'wgt-valid' ).addClass( 'wgt-invalid' );
			}
		} );
	}

	$( document ).on( 'blur', '.wgt-gstin-input', function () {
		validateGstin( $( this ) );
	} );

	function toggleB2BFields() {
		var $checkbox = $( '#billing_is_business' );
		if ( ! $checkbox.length ) {
			return;
		}
		$( '.wgt-b2b-field' ).toggle( $checkbox.is( ':checked' ) );
	}

	$( document ).on( 'change', '#billing_is_business', toggleB2BFields );
	$( document.body ).on( 'updated_checkout', toggleB2BFields );
	$( function () {
		toggleB2BFields();
	} );

	function formatNumber( value ) {
		return ( value % 1 === 0 ) ? value.toFixed( 0 ) : String( value );
	}

	function updateTaxPreview( $select ) {
		var rate = parseFloat( $select.val() );
		var $preview = $select.nextAll( '.wgt-tax-preview' ).first();

		if ( ! $preview.length ) {
			$preview = $( '<p class="description wgt-tax-preview" style="margin-top:4px;"></p>' );
			$select.after( $preview );
		}

		if ( typeof wgtAdmin === 'undefined' ) {
			return;
		}

		if ( ! rate || isNaN( rate ) || rate <= 0 ) {
			$preview.text( wgtAdmin.taxExemptText || '' );
			return;
		}

		var half = Math.round( ( rate / 2 ) * 100 ) / 100;
		var text = ( wgtAdmin.taxPreviewTemplate || '' )
			.replace( /\{half\}/g, formatNumber( half ) )
			.replace( /\{rate\}/g, formatNumber( rate ) );
		$preview.text( text );
	}

	$( document ).on( 'change', '.wgt-gst-rate-select', function () {
		updateTaxPreview( $( this ) );
	} );
	$( function () {
		$( '.wgt-gst-rate-select' ).each( function () {
			updateTaxPreview( $( this ) );
		} );
	} );
} )( jQuery );
