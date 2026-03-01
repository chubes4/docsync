<?php
/**
 * Table of Contents — Heading ID Injection
 *
 * Ensures all h2 and h3 elements in documentation content have IDs
 * so the docsync/navigation block can link to them in TOC mode.
 *
 * The TOC rendering itself has moved to the NavigationBlock. This class
 * now only provides the heading ID injection content filter.
 *
 * @package DocSync\Templates
 * @since 1.0.0
 */

namespace DocSync\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TableOfContents {

	/**
	 * Initialize heading ID injection.
	 *
	 * Adds id attributes to h2/h3 elements that don't have one,
	 * so the navigation block's TOC mode can link to them.
	 */
	public static function init(): void {
		add_filter( 'the_content', [ __CLASS__, 'ensure_heading_ids' ], 5 );
	}

	/**
	 * Ensure all h2 and h3 elements in documentation content have IDs.
	 *
	 * Adds slug-based IDs to headings that don't already have one,
	 * so the TOC can link to them.
	 *
	 * @param string $content Post content.
	 * @return string Content with heading IDs ensured.
	 */
	public static function ensure_heading_ids( string $content ): string {
		if ( ! is_singular( 'documentation' ) ) {
			return $content;
		}

		return preg_replace_callback(
			'/<(h[23])([^>]*)>(.*?)<\/\1>/i',
			function( $matches ) {
				$tag   = $matches[1];
				$attrs = $matches[2];
				$text  = $matches[3];

				// Already has an ID — leave it alone.
				if ( preg_match( '/id=["\']/', $attrs ) ) {
					return $matches[0];
				}

				$id = sanitize_title( wp_strip_all_tags( $text ) );
				return sprintf( '<%s%s id="%s">%s</%s>', $tag, $attrs, esc_attr( $id ), $text, $tag );
			},
			$content
		);
	}
}
