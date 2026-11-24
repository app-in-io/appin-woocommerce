/**
 * "AI Search Button" settings: WordPress colour pickers and the icon chooser
 * (media library, images only). The icon is saved as an attachment ID.
 */
( function ( $ ) {
	'use strict';

	var i18n = window.appinioButtonSettings || {};

	$( function () {
		$( '.appinio-color-field' ).wpColorPicker();

		var $input = $( '#appinio_button_icon' );
		var $preview = $( '#appinio_button_icon-preview' );
		var $remove = $( '.appinio-remove-icon' );
		var frame;

		$( '.appinio-choose-icon' ).on( 'click', function ( event ) {
			event.preventDefault();

			if ( ! window.wp || ! window.wp.media ) {
				return;
			}

			if ( ! frame ) {
				frame = window.wp.media( {
					title: i18n.title,
					button: { text: i18n.select },
					multiple: false,
					library: { type: 'image' },
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					var url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;

					$input.val( attachment.id );
					$preview.empty().append( $( '<img>', { src: url, alt: '', width: 32, height: 32 } ) );
					$remove.show();
				} );
			}

			frame.open();
		} );

		$remove.on( 'click', function ( event ) {
			event.preventDefault();
			$input.val( '' );
			$preview.empty();
			$remove.hide();
		} );
	} );
} )( jQuery );
