<?php
/**
 * Sync Email Notifier
 *
 * Queues documentation sync results and sends a single batched digest
 * email to the site admin once per day (via the docsync_digest_send cron),
 * instead of one email per project sync.
 */

namespace DocSync\Sync;

use DocSync\Core\Project;

class SyncNotifier {

	/**
	 * Option key for the pending digest queue.
	 *
	 * Stored as an array keyed by term ID. The latest result for a given
	 * term within the digest window overwrites the previous one.
	 */
	const OPTION_QUEUE = 'docsync_digest_queue';

	/**
	 * Cron hook that flushes the queue into a single digest email.
	 */
	const CRON_HOOK = 'docsync_digest_send';

	/**
	 * Register the daily digest cron.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'send_digest' ] );
		add_action( 'admin_init', [ __CLASS__, 'schedule' ] );
		register_deactivation_hook( DOCSYNC_PATH . 'docsync.php', [ __CLASS__, 'unschedule' ] );
	}

	/**
	 * Schedule the daily digest event if not already scheduled.
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time(), 'daily', self::CRON_HOOK );
	}

	/**
	 * Unschedule the digest event.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Queue a sync result for the next daily digest.
	 *
	 * Replaces the previous per-sync immediate email. Kept named send()
	 * so existing call sites continue to work unchanged.
	 *
	 * @param int   $term_id Project term ID.
	 * @param array $results Sync results from RepoSync.
	 */
	public function send( int $term_id, array $results ): void {
		$this->queue( $term_id, $results );
	}

	/**
	 * Add a sync result to the pending digest queue.
	 *
	 * @param int   $term_id Project term ID.
	 * @param array $results Sync results from RepoSync.
	 */
	public function queue( int $term_id, array $results ): void {
		if ( ! $this->has_changes( $results ) ) {
			return;
		}

		$term = get_term( $term_id, Project::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$queue = get_option( self::OPTION_QUEUE, [] );
		if ( ! is_array( $queue ) ) {
			$queue = [];
		}

		$queue[ $term_id ] = [
			'results'  => $results,
			'queued_at' => time(),
		];

		update_option( self::OPTION_QUEUE, $queue, false );
	}

	/**
	 * Flush the queue: send one digest email covering all queued projects,
	 * then clear the queue. Invoked by the daily cron.
	 */
	public static function send_digest(): void {
		$notifier = new self();
		$notifier->flush();
	}

	/**
	 * Build and send the digest email from the current queue, then clear it.
	 */
	public function flush(): void {
		$queue = get_option( self::OPTION_QUEUE, [] );
		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}

		$sections   = [];
		$project_names = [];

		foreach ( $queue as $term_id => $entry ) {
			$results = $entry['results'] ?? null;
			if ( empty( $results ) ) {
				continue;
			}

			$term = get_term( (int) $term_id, Project::TAXONOMY );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			$project_names[] = $term->name;
			$sections[]      = $this->build_project_section( $term, $results );
		}

		// Always clear the queue, even if every entry was stale/invalid.
		delete_option( self::OPTION_QUEUE );

		if ( empty( $sections ) ) {
			return;
		}

		$to      = get_option( 'admin_email' );
		$subject = $this->build_subject( count( $sections ) );
		$body    = $this->build_digest_body( $sections, $project_names );
		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Check if sync results contain actual changes.
	 *
	 * @param array $results Sync results.
	 * @return bool True if changes occurred.
	 */
	private function has_changes( array $results ): bool {
		return ! empty( $results['added'] ) ||
			   ! empty( $results['updated'] ) ||
			   ! empty( $results['removed'] ) ||
			   ! empty( $results['terms_created'] );
	}

	/**
	 * Build the digest email subject line.
	 *
	 * @param int $project_count Number of projects in the digest.
	 * @return string Email subject.
	 */
	private function build_subject( int $project_count ): string {
		$site_name = get_bloginfo( 'name' );
		$noun      = 1 === $project_count ? 'project' : 'projects';
		return sprintf( '[%s] Docs Sync Digest: %d %s updated', $site_name, $project_count, $noun );
	}

	/**
	 * Build the full digest body from per-project sections.
	 *
	 * @param string[] $sections      Rendered per-project sections.
	 * @param string[] $project_names Project names included in the digest.
	 * @return string Email body.
	 */
	private function build_digest_body( array $sections, array $project_names ): string {
		$lines = [];

		$lines[] = 'Documentation Sync Digest';
		$lines[] = str_repeat( '=', 40 );
		$lines[] = 'Time: ' . wp_date( 'M j, Y \a\t g:ia' );
		$lines[] = sprintf( 'Projects updated (%d): %s', count( $project_names ), implode( ', ', $project_names ) );
		$lines[] = '';
		$lines[] = implode( "\n\n" . str_repeat( '=', 40 ) . "\n\n", $sections );

		return implode( "\n", $lines );
	}

	/**
	 * Build the report section for a single project.
	 *
	 * @param \WP_Term $term    Project term.
	 * @param array    $results Sync results.
	 * @return string Project section text.
	 */
	private function build_project_section( \WP_Term $term, array $results ): string {
		$lines = [];

		$project_line = 'Project: ' . $term->name;
		$installs = $this->get_install_count( $term->term_id );
		if ( $installs > 0 ) {
			$project_line .= ' (' . $this->format_installs( $installs ) . ' active installs)';
		}
		$lines[] = $project_line;

		$github_url = get_term_meta( $term->term_id, 'project_github_url', true );
		if ( $github_url ) {
			$lines[] = 'Repository: ' . $github_url;
		}

		$lines[] = 'Status: ' . ( $results['success'] ? 'Success' : 'Failed' );

		if ( ! empty( $results['old_sha'] ) && ! empty( $results['new_sha'] ) ) {
			$old_short = substr( $results['old_sha'], 0, 7 );
			$new_short = substr( $results['new_sha'], 0, 7 );
			$lines[] = "Commit: {$old_short} -> {$new_short}";
		} elseif ( ! empty( $results['new_sha'] ) ) {
			$new_short = substr( $results['new_sha'], 0, 7 );
			$lines[] = "Commit: {$new_short} (initial sync)";
		}

		$lines[] = '';

		if ( ! empty( $results['added'] ) ) {
			$lines[] = 'Files Added (' . count( $results['added'] ) . '):';
			foreach ( $results['added'] as $file ) {
				$lines[] = '  - ' . $file;
			}
			$lines[] = '';
		}

		if ( ! empty( $results['updated'] ) ) {
			$lines[] = 'Files Updated (' . count( $results['updated'] ) . '):';
			foreach ( $results['updated'] as $file ) {
				$lines[] = '  - ' . $file;
			}
			$lines[] = '';
		}

		if ( ! empty( $results['removed'] ) ) {
			$lines[] = 'Files Removed (' . count( $results['removed'] ) . '):';
			foreach ( $results['removed'] as $file ) {
				$lines[] = '  - ' . $file;
			}
			$lines[] = '';
		}

		if ( ! empty( $results['terms_created'] ) ) {
			$lines[] = 'Terms Created (' . count( $results['terms_created'] ) . '):';
			foreach ( $results['terms_created'] as $term_name ) {
				$lines[] = '  - ' . $term_name;
			}
			$lines[] = '';
		}

		if ( ! empty( $results['error'] ) ) {
			$lines[] = 'Error: ' . $results['error'];
			$lines[] = '';
		}

		$docs_url = $this->get_docs_url( $term );
		if ( $docs_url ) {
			$lines[] = 'View documentation: ' . $docs_url;
		}

		return rtrim( implode( "\n", $lines ) );
	}

	/**
	 * Get install count for a term.
	 *
	 * @param int $term_id Term ID.
	 * @return int Install count.
	 */
	private function get_install_count( int $term_id ): int {
		$wp_url = get_term_meta( $term_id, 'project_wp_url', true );
		if ( empty( $wp_url ) ) {
			return 0;
		}
		return (int) get_term_meta( $term_id, 'project_installs', true );
	}

	/**
	 * Format install count for display.
	 *
	 * @param int $installs Install count.
	 * @return string Formatted string (e.g., "1,000+").
	 */
	private function format_installs( int $installs ): string {
		if ( $installs >= 1000000 ) {
			return number_format( floor( $installs / 1000000 ) ) . 'M+';
		}
		if ( $installs >= 1000 ) {
			return number_format( floor( $installs / 1000 ) ) . 'K+';
		}
		return number_format( $installs ) . '+';
	}

	/**
	 * Get the documentation URL for a project.
	 *
	 * @param \WP_Term $term Project term.
	 * @return string|null Documentation URL or null.
	 */
	private function get_docs_url( \WP_Term $term ): ?string {
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) ) {
			return null;
		}
		return $link;
	}
}
