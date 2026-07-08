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
} )( jQuery );
