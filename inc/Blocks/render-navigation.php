<?php
/**
 * DocSync Navigation Block — Server-Side Render
 *
 * Context-aware rendering: detects whether we're on a single doc page
 * or an archive and renders the appropriate navigation mode.
 *
 * Modes:
 * - auto: TOC on single documentation, Tree on archives (default)
 * - toc:  Always render Table of Contents
 * - tree: Always render Project Tree
 *
 * @package DocSync\Blocks
 * @since 1.1.0
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block inner content (empty for dynamic blocks).
 * @var WP_Block $block      Block instance.
 */

use DocSync\Blocks\NavigationBlock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo NavigationBlock::render( $attributes );
