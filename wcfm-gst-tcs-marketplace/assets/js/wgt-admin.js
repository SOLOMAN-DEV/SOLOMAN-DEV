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
} )( jQuery );
