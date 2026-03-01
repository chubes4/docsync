/**
 * DocSync Navigation Block — Editor Component
 *
 * Provides a simple block editor interface for the context-aware navigation.
 * The actual rendering happens server-side via render callback.
 */
( function( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl } = wp.components;
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;

	registerBlockType( 'docsync/navigation', {
		edit: function( props ) {
			const { attributes, setAttributes } = props;
			const blockProps = useBlockProps();

			const modeLabels = {
				auto: __( 'Auto (TOC on single, Tree on archive)', 'docsync' ),
				toc: __( 'Table of Contents', 'docsync' ),
				tree: __( 'Project Tree', 'docsync' ),
			};

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Navigation Settings', 'docsync' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Mode', 'docsync' ),
							value: attributes.mode,
							options: [
								{ label: __( 'Auto-detect', 'docsync' ), value: 'auto' },
								{ label: __( 'Table of Contents', 'docsync' ), value: 'toc' },
								{ label: __( 'Project Tree', 'docsync' ), value: 'tree' },
							],
							onChange: function( value ) {
								setAttributes( { mode: value } );
							},
							help: __( 'Auto mode shows TOC on single docs and project tree on archives.', 'docsync' ),
						} ),
						( attributes.mode === 'tree' || attributes.mode === 'auto' ) &&
							el( TextControl, {
								label: __( 'Project Slug', 'docsync' ),
								value: attributes.projectSlug,
								onChange: function( value ) {
									setAttributes( { projectSlug: value } );
								},
								help: __( 'Leave empty to auto-detect from the current page context.', 'docsync' ),
							} )
					)
				),
				el(
					'div',
					{ className: 'docsync-navigation-placeholder' },
					el(
						'div',
						{
							style: {
								padding: '1.5rem',
								background: '#f8f9fa',
								border: '1px dashed #ced4da',
								borderRadius: '8px',
								textAlign: 'center',
								color: '#6c757d',
								fontSize: '14px',
							},
						},
						el( 'strong', null, __( 'DocSync Navigation', 'docsync' ) ),
						el( 'br' ),
						el(
							'span',
							{ style: { fontSize: '12px' } },
							modeLabels[ attributes.mode ] || modeLabels.auto
						)
					)
				)
			);
		},

		save: function() {
			// Dynamic block — rendered server-side.
			return null;
		},
	} );
} )( window.wp );
