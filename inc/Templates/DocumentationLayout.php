<?php
/**
 * Documentation Layout
 *
 * Wraps single documentation content in a two-column layout with a sidebar.
 * The sidebar renders the docsync/navigation block via do_blocks(), which
 * auto-detects context and shows a table of contents on single doc pages.
 *
 * @package DocSync\Templates
 * @since 1.0.0
 */

namespace DocSync\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DocumentationLayout {

	/**
	 * Initialize hooks.
	 *
	 * Uses a late priority on the_content to wrap after all content filters
	 * have run (blocks, shortcodes, embeds, etc).
	 */
	public static function init(): void {
		add_filter( 'the_content', [ __CLASS__, 'wrap_content' ], 999 );
	}

	/**
	 * Wrap single documentation content with sidebar layout.
	 *
	 * Renders the docsync/navigation block in the sidebar via do_blocks().
	 * The block auto-detects that we're on a single doc page and renders
	 * the table of contents.
	 *
	 * @param string $content The post content.
	 * @return string Wrapped content with sidebar, or original content.
	 */
	public static function wrap_content( string $content ): string {
		if ( ! is_singular( 'documentation' ) ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		// Render the navigation block — it auto-detects single doc context.
		$sidebar = do_blocks( '<!-- wp:docsync/navigation {"mode":"auto"} /-->' );

		if ( empty( trim( $sidebar ) ) ) {
			return $content;
		}

		$toggle = '<button class="docsync-sidebar-toggle" aria-expanded="false" aria-controls="docsync-sidebar">'
			. '<span class="docsync-sidebar-toggle-icon">&#9654;</span> '
			. esc_html__( 'Navigation', 'docsync' )
			. '</button>';

		return '<div class="docsync-doc-layout">'
			. '<div class="docsync-doc-content">' . $content . '</div>'
			. '<aside class="docsync-doc-sidebar" id="docsync-sidebar">' . $toggle . $sidebar . '</aside>'
			. '</div>';
	}
}
