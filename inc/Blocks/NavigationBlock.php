<?php
/**
 * Navigation Block
 *
 * Context-aware documentation navigation block. Renders as a table of
 * contents (h2/h3 heading links) on single documentation pages, or as
 * a hierarchical project tree on archives and landing pages.
 *
 * Replaces the old filter-based ProjectTree and TableOfContents sidebar
 * components with a single reusable Gutenberg block.
 *
 * @package DocSync\Blocks
 * @since 1.1.0
 */

namespace DocSync\Blocks;

use DocSync\Api\Controllers\ProjectController;
use DocSync\Core\Project;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NavigationBlock {

	/**
	 * Register the block with WordPress.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
	}

	/**
	 * Register the block type from block.json metadata.
	 */
	public static function register(): void {
		register_block_type( DOCSYNC_PATH . 'blocks/navigation' );
	}

	/**
	 * Render the navigation block.
	 *
	 * Determines the appropriate mode (TOC or Tree) based on block
	 * attributes and page context, then delegates to the renderer.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	public static function render( array $attributes ): string {
		$mode         = $attributes['mode'] ?? 'auto';
		$project_slug = $attributes['projectSlug'] ?? '';

		$resolved_mode = self::resolve_mode( $mode );

		if ( $resolved_mode === 'toc' ) {
			return self::render_toc();
		}

		return self::render_tree( $project_slug );
	}

	/**
	 * Resolve the rendering mode from attributes and page context.
	 *
	 * @param string $mode Block mode attribute: auto, toc, or tree.
	 * @return string Resolved mode: toc or tree.
	 */
	private static function resolve_mode( string $mode ): string {
		if ( $mode === 'toc' ) {
			return 'toc';
		}

		if ( $mode === 'tree' ) {
			return 'tree';
		}

		// Auto-detect: single doc → TOC, everything else → tree.
		if ( is_singular( 'documentation' ) ) {
			return 'toc';
		}

		return 'tree';
	}

	/**
	 * Render the Table of Contents for the current post.
	 *
	 * Extracts h2/h3 headings from the post content and builds
	 * a linked list with scroll-spy support via docsync-toc.js.
	 *
	 * Processes headings in two steps:
	 * 1. Renders blocks to HTML via do_blocks()
	 * 2. Generates IDs for headings that lack them (matching ensure_heading_ids)
	 *
	 * @return string TOC HTML or empty string if no headings.
	 */
	private static function render_toc(): string {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$content = get_post_field( 'post_content', $post_id );
		if ( empty( $content ) ) {
			return '';
		}

		// Render blocks to HTML, then apply any custom TOC filters.
		$rendered = apply_filters( 'docsync_toc_content', do_blocks( $content ) );

		// Extract all h2 and h3 headings (with or without IDs).
		preg_match_all(
			'/<(h[23])([^>]*)>(.*?)<\/\1>/i',
			$rendered,
			$raw_matches,
			PREG_SET_ORDER
		);

		if ( empty( $raw_matches ) ) {
			return '';
		}

		// Build headers array, generating IDs for headings that lack them.
		// This mirrors TableOfContents::ensure_heading_ids() which runs on
		// the_content to add IDs to the actual rendered page.
		$headers = [];
		foreach ( $raw_matches as $match ) {
			$attrs = $match[2];
			$text  = wp_strip_all_tags( $match[3] );

			// Extract existing ID or generate one.
			if ( preg_match( '/id=["\']([^"\']+)["\']/', $attrs, $id_match ) ) {
				$id = $id_match[1];
			} else {
				$id = sanitize_title( $text );
			}

			if ( empty( $id ) || empty( $text ) ) {
				continue;
			}

			$headers[] = [
				'level' => $match[1],
				'id'    => $id,
				'text'  => $text,
			];
		}

		// Enqueue TOC assets.
		self::enqueue_toc_assets();

		ob_start();
		?>
		<nav class="docsync-toc" aria-label="<?php esc_attr_e( 'Table of Contents', 'docsync' ); ?>">
			<h3 class="docsync-toc-title"><span><?php esc_html_e( 'On This Page', 'docsync' ); ?></span></h3>
			<?php echo self::build_toc_list( $headers ); ?>
		</nav>
		<?php
		return ob_get_clean();
	}

	/**
	 * Build the TOC list HTML from headers array.
	 *
	 * @param array $headers Array of header data with level, id, text.
	 * @return string HTML list.
	 */
	private static function build_toc_list( array $headers ): string {
		$html = '<ul class="docsync-toc-list">';

		foreach ( $headers as $header ) {
			$indent_class = $header['level'] === 'h3' ? ' docsync-toc-nested' : '';
			$html .= sprintf(
				'<li class="docsync-toc-item%s"><a href="#%s" class="docsync-toc-link">%s</a></li>',
				$indent_class,
				esc_attr( $header['id'] ),
				esc_html( $header['text'] )
			);
		}

		$html .= '</ul>';

		return $html;
	}

	/**
	 * Render the project tree navigation.
	 *
	 * Shows the full hierarchical documentation tree for a project.
	 * On single doc pages, highlights the current page and auto-expands
	 * the containing section.
	 *
	 * @param string $project_slug Explicit project slug, or empty for auto-detect.
	 * @return string Tree HTML or empty string.
	 */
	private static function render_tree( string $project_slug = '' ): string {
		$project_term = self::resolve_project_term( $project_slug );
		if ( ! $project_term ) {
			return '';
		}

		$tree = ProjectController::build_doc_tree( $project_term->term_id );
		if ( empty( $tree ) ) {
			return '';
		}

		$post_id     = get_the_ID() ?: 0;
		$project_url = get_term_link( $project_term );

		// Enqueue tree assets.
		self::enqueue_tree_assets();

		ob_start();
		?>
		<nav class="docsync-project-tree" aria-label="<?php echo esc_attr( sprintf( __( '%s documentation', 'docsync' ), $project_term->name ) ); ?>">
			<h3 class="docsync-tree-title">
				<a href="<?php echo esc_url( $project_url ); ?>"><?php echo esc_html( $project_term->name ); ?></a>
			</h3>
			<?php echo self::render_tree_sections( $tree, $post_id ); ?>
		</nav>
		<?php
		return ob_get_clean();
	}

	/**
	 * Resolve a project term from slug or page context.
	 *
	 * @param string $slug Explicit slug or empty for auto-detect.
	 * @return \WP_Term|null Project term or null.
	 */
	private static function resolve_project_term( string $slug ): ?\WP_Term {
		// Explicit slug provided.
		if ( ! empty( $slug ) ) {
			$term = get_term_by( 'slug', $slug, Project::TAXONOMY );
			return ( $term && ! is_wp_error( $term ) ) ? $term : null;
		}

		// Auto-detect from current single doc.
		if ( is_singular( 'documentation' ) ) {
			$post_id = get_the_ID();
			if ( $post_id ) {
				$terms = get_the_terms( $post_id, Project::TAXONOMY );
				if ( $terms && ! is_wp_error( $terms ) ) {
					return Project::get_top_level_term( $terms );
				}
			}
		}

		// Auto-detect from taxonomy archive.
		$queried = get_queried_object();
		if ( $queried instanceof \WP_Term && $queried->taxonomy === Project::TAXONOMY ) {
			return Project::get_top_level_term( [ $queried ] );
		}

		return null;
	}

	/**
	 * Render tree sections recursively.
	 *
	 * @param array $sections Tree sections from ProjectController::build_doc_tree().
	 * @param int   $post_id  Current post ID for highlighting.
	 * @return string HTML.
	 */
	private static function render_tree_sections( array $sections, int $post_id ): string {
		$html = '<ul class="docsync-tree-list">';

		foreach ( $sections as $section ) {
			$term     = $section['term'] ?? null;
			$docs     = $section['docs'] ?? [];
			$children = $section['children'] ?? [];

			if ( $term ) {
				$has_children = ! empty( $docs ) || ! empty( $children );
				$is_expanded  = $has_children && self::section_contains_post( $section, $post_id );
				$state_class  = $is_expanded ? 'docsync-tree-expanded' : 'docsync-tree-collapsed';

				$html .= '<li class="docsync-tree-section ' . $state_class . '">';
				$html .= '<button class="docsync-tree-toggle" aria-expanded="' . ( $is_expanded ? 'true' : 'false' ) . '">';
				$html .= '<span class="docsync-tree-icon"></span>';
				$html .= esc_html( $term['name'] );
				$html .= '</button>';

				if ( $has_children ) {
					$html .= '<ul class="docsync-tree-children">';

					foreach ( $docs as $doc ) {
						$html .= self::render_doc_item( $doc, $post_id );
					}

					if ( ! empty( $children ) ) {
						foreach ( $children as $child_section ) {
							$child_term     = $child_section['term'] ?? null;
							$child_docs     = $child_section['docs'] ?? [];
							$child_children = $child_section['children'] ?? [];

							if ( $child_term ) {
								$child_expanded = self::section_contains_post( $child_section, $post_id );
								$child_state    = $child_expanded ? 'docsync-tree-expanded' : 'docsync-tree-collapsed';

								$html .= '<li class="docsync-tree-section ' . $child_state . '">';
								$html .= '<button class="docsync-tree-toggle" aria-expanded="' . ( $child_expanded ? 'true' : 'false' ) . '">';
								$html .= '<span class="docsync-tree-icon"></span>';
								$html .= esc_html( $child_term['name'] );
								$html .= '</button>';

								$html .= '<ul class="docsync-tree-children">';
								foreach ( $child_docs as $doc ) {
									$html .= self::render_doc_item( $doc, $post_id );
								}
								$html .= '</ul></li>';
							} else {
								foreach ( $child_docs as $doc ) {
									$html .= self::render_doc_item( $doc, $post_id );
								}
							}
						}
					}

					$html .= '</ul>';
				}

				$html .= '</li>';
			} else {
				foreach ( $docs as $doc ) {
					$html .= self::render_doc_item( $doc, $post_id );
				}
			}
		}

		$html .= '</ul>';

		return $html;
	}

	/**
	 * Render a single doc item.
	 *
	 * @param array $doc     Doc data with id, title, slug, url.
	 * @param int   $post_id Current post ID.
	 * @return string HTML list item.
	 */
	private static function render_doc_item( array $doc, int $post_id ): string {
		$is_current = ( (int) $doc['id'] === $post_id );
		$classes    = 'docsync-tree-doc';

		if ( $is_current ) {
			$classes .= ' docsync-tree-current';
		}

		return sprintf(
			'<li class="%s"><a href="%s"%s>%s</a></li>',
			esc_attr( $classes ),
			esc_url( $doc['url'] ),
			$is_current ? ' aria-current="page"' : '',
			esc_html( $doc['title'] )
		);
	}

	/**
	 * Check if a section contains the given post ID.
	 *
	 * @param array $section Section data.
	 * @param int   $post_id Post ID to find.
	 * @return bool
	 */
	private static function section_contains_post( array $section, int $post_id ): bool {
		foreach ( $section['docs'] ?? [] as $doc ) {
			if ( (int) $doc['id'] === $post_id ) {
				return true;
			}
		}

		foreach ( $section['children'] ?? [] as $child ) {
			if ( self::section_contains_post( $child, $post_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enqueue TOC assets when the block renders in TOC mode.
	 */
	private static function enqueue_toc_assets(): void {
		$css_path = DOCSYNC_PATH . 'assets/css/toc.css';
		$js_path  = DOCSYNC_PATH . 'assets/js/docsync-toc.js';

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'docsync-toc',
				DOCSYNC_URL . 'assets/css/toc.css',
				[ 'docsync-tokens' ],
				filemtime( $css_path )
			);
		}

		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'docsync-toc',
				DOCSYNC_URL . 'assets/js/docsync-toc.js',
				[],
				filemtime( $js_path ),
				true
			);
		}
	}

	/**
	 * Enqueue project tree assets when the block renders in tree mode.
	 */
	private static function enqueue_tree_assets(): void {
		$css_path = DOCSYNC_PATH . 'assets/css/project-tree.css';
		$js_path  = DOCSYNC_PATH . 'assets/js/docsync-project-tree.js';

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'docsync-project-tree',
				DOCSYNC_URL . 'assets/css/project-tree.css',
				[ 'docsync-tokens' ],
				filemtime( $css_path )
			);
		}

		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'docsync-project-tree',
				DOCSYNC_URL . 'assets/js/docsync-project-tree.js',
				[],
				filemtime( $js_path ),
				true
			);
		}
	}
}
