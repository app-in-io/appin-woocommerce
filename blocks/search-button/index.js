/**
 * Editor side of the "AI search button" block (appinio-search/search-button).
 *
 * Plain JS on the wp.* globals, no build step. Title, attributes and apiVersion come from
 * block.json, which WordPress registers in PHP and passes to the editor. The front end is
 * rendered by PHP (SearchButton::renderBlock), so the block saves no markup.
 *
 * The preview is a static look-alike: search.js (the real <semantic-search-button>) is
 * not loaded in the editor. window.appinioSearchButton carries the admin settings.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	const el = element.createElement;
	const __ = i18n.__;
	const settings = window.appinioSearchButton || {};

	const sparkle = el(
		'svg',
		{ width: 16, height: 16, viewBox: '0 0 24 24', fill: 'currentColor', 'aria-hidden': true },
		el( 'path', {
			d: 'M12 2c.6 5.4 4.6 9.4 10 10-5.4.6-9.4 4.6-10 10-.6-5.4-4.6-9.4-10-10 5.4-.6 9.4-4.6 10-10Z',
		} )
	);

	function preview( label, iconOnly ) {
		const icon = settings.iconUrl
			? el( 'img', { src: settings.iconUrl, alt: '', width: 16, height: 16 } )
			: sparkle;

		return el(
			'span',
			{
				title: iconOnly ? label : undefined,
				style: {
					display: 'inline-flex',
					alignItems: 'center',
					justifyContent: 'center',
					gap: '6px',
					minHeight: '32px',
					minWidth: '32px',
					padding: iconOnly ? '0' : '0 14px',
					borderRadius: '6px',
					background: settings.color || '#37b7ff',
					color: settings.textColor || '#ffffff',
					fontSize: '14px',
					fontWeight: 600,
					lineHeight: 1,
					whiteSpace: 'nowrap',
				},
			},
			icon,
			iconOnly ? null : el( 'span', null, label )
		);
	}

	blocks.registerBlockType( 'appinio-search/search-button', {
		edit: function ( props ) {
			const attributes = props.attributes;
			const iconOnly = attributes.variant === 'icon';
			const label = attributes.label || settings.label || __( 'AI search', 'appinio-search' );

			return el(
				'div',
				blockEditor.useBlockProps(),
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Button', 'appinio-search' ) },
						el( components.TextControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Label', 'appinio-search' ),
							help: __(
								'Leave empty to use the label from the Appinio Search settings, or "AI search" in the visitor\'s language.',
								'appinio-search'
							),
							value: attributes.label,
							onChange: function ( value ) {
								props.setAttributes( { label: value } );
							},
						} ),
						el( components.SelectControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Style', 'appinio-search' ),
							value: attributes.variant,
							options: [
								{ label: __( 'Icon and text', 'appinio-search' ), value: 'icon-text' },
								{ label: __( 'Icon only', 'appinio-search' ), value: 'icon' },
							],
							onChange: function ( value ) {
								props.setAttributes( { variant: value } );
							},
						} )
					)
				),
				settings.hasKey
					? null
					: el(
							components.Notice,
							{ status: 'warning', isDismissible: false },
							__(
								'Set the public search key in the Appinio Search settings. Until then, the button is not shown on the store.',
								'appinio-search'
							),
							' ',
							el( 'a', { href: settings.settingsUrl }, __( 'Open settings', 'appinio-search' ) )
					  ),
				preview( label, iconOnly )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
