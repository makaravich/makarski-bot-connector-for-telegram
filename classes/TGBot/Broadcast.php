<?php

namespace TGBot;

/**
 * Broadcast feature: send messages to all Telegram bot users.
 *
 * Tables:
 *   {prefix}tgbot_broadcasts           – broadcast jobs
 *   {prefix}tgbot_broadcast_recipients – per-user delivery records
 */
class Broadcast {

	const CRON_HOOK  = 'tgbot_process_broadcast';
	const BATCH_SIZE = 200;
	const DB_VERSION = '1.0';
	const DB_VERSION_OPTION = 'tgbot_broadcast_db_version';

	// ---------------------------------------------------------------------------
	// Table names
	// ---------------------------------------------------------------------------

	/** @return string */
	public static function jobs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tgbot_broadcasts';
	}

	/** @return string */
	public static function recipients_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tgbot_broadcast_recipients';
	}

	// ---------------------------------------------------------------------------
	// Schema
	// ---------------------------------------------------------------------------

	/**
	 * Create (or upgrade) both database tables via dbDelta.
	 * Called from the plugin activation hook.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$jobs            = self::jobs_table();
		$recipients      = self::recipients_table();

		$sql = "CREATE TABLE {$jobs} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			messages_json LONGTEXT      NOT NULL,
			format      VARCHAR(20)     NOT NULL DEFAULT 'plain',
			status      VARCHAR(20)     NOT NULL DEFAULT 'pending',
			total       INT             NOT NULL DEFAULT 0,
			sent        INT             NOT NULL DEFAULT 0,
			failed      INT             NOT NULL DEFAULT 0,
			PRIMARY KEY (id)
		) {$charset_collate};

		CREATE TABLE {$recipients} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			broadcast_id BIGINT UNSIGNED NOT NULL,
			user_id      BIGINT UNSIGNED NOT NULL,
			chat_id      VARCHAR(50)     NOT NULL,
			locale       VARCHAR(20)     NOT NULL DEFAULT 'en_US',
			status       VARCHAR(20)     NOT NULL DEFAULT 'pending',
			sent_at      DATETIME                 DEFAULT NULL,
			error        TEXT                     DEFAULT NULL,
			PRIMARY KEY (id),
			KEY broadcast_id (broadcast_id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Run create_tables() only when the stored DB version doesn't match.
	 * Safe to call on every plugins_loaded — skips the work when up to date.
	 */
	public static function maybe_upgrade_db(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	// ---------------------------------------------------------------------------
	// Job creation
	// ---------------------------------------------------------------------------

	/**
	 * Create a new broadcast job.
	 *
	 * @param array  $messages  Locale-keyed messages, e.g. ['en_US' => 'Hello!', 'ru_RU' => 'Привет!'].
	 * @param string $format    'plain' | 'html' | 'markdown'.
	 * @param array  $user_ids  WP user IDs to include.
	 *
	 * @return int|false  The new job ID, or false on failure.
	 */
	public static function create_job( array $messages, string $format, array $user_ids ): int|false {
		global $wpdb;

		if ( empty( $messages ) || empty( $user_ids ) ) {
			return false;
		}

		$allowed_formats = [ 'plain', 'html', 'markdown' ];
		if ( ! in_array( $format, $allowed_formats, true ) ) {
			$format = 'plain';
		}

		// Resolve recipients: only users with tg_nickname, and only if a message exists for their locale.
		$recipients = [];
		foreach ( $user_ids as $uid ) {
			$uid        = (int) $uid;
			$chat_id    = get_the_author_meta( 'user_login', $uid ); // login = chat_id
			$tg_nick    = get_user_meta( $uid, 'tg_nickname', true );
			$locale     = get_user_meta( $uid, 'locale', true ) ?: 'en_US';

			if ( empty( $tg_nick ) || empty( $chat_id ) ) {
				continue; // skip users without tg_nickname
			}

			// Message resolution: exact locale → en_US fallback → skip.
			if ( isset( $messages[ $locale ] ) ) {
				$resolved_locale = $locale;
			} elseif ( isset( $messages['en_US'] ) ) {
				$resolved_locale = 'en_US';
			} else {
				continue; // no message for this user
			}

			$recipients[] = [
				'user_id' => $uid,
				'chat_id' => $chat_id,
				'locale'  => $resolved_locale,
			];
		}

		if ( empty( $recipients ) ) {
			return false;
		}

		// Insert the job row.
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::jobs_table(),
			[
				'created_at'    => current_time( 'mysql' ),
				'messages_json' => wp_json_encode( $messages ),
				'format'        => $format,
				'status'        => 'pending',
				'total'         => count( $recipients ),
				'sent'          => 0,
				'failed'        => 0,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%d', '%d' ]
		);

		if ( ! $inserted ) {
			return false;
		}

		$job_id = (int) $wpdb->insert_id;

		// Bulk-insert recipients.
		$values       = [];
		$placeholders = [];
		foreach ( $recipients as $r ) {
			$placeholders[] = '(%d, %d, %s, %s, %s, %s)';
			array_push( $values, $job_id, $r['user_id'], $r['chat_id'], $r['locale'], 'pending', null );
		}

		$recipients_table = esc_sql( self::recipients_table() );
		$sql              = 'INSERT INTO `' . $recipients_table . '`' . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			' (broadcast_id, user_id, chat_id, locale, status, sent_at)' .
			' VALUES ' . implode( ', ', $placeholders ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare( $sql, $values ) );

		// Schedule cron batch if not already scheduled.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}

		return $job_id;
	}

	// ---------------------------------------------------------------------------
	// Batch processing (cron handler)
	// ---------------------------------------------------------------------------

	/**
	 * Process one batch of pending recipients.
	 * Chained automatically if more records remain.
	 */
	public static function process_batch(): void {
		global $wpdb;

		$jobs_table       = self::jobs_table();
		$recipients_table = self::recipients_table();

		$token = tgbot_get_option( 'gen_tg_token' );
		if ( ! $token ) {
			return;
		}

		$bot = new BotApi( $token, false );

		// Fetch a batch of pending recipients whose job is active.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.broadcast_id, r.chat_id, r.locale,
				        j.messages_json, j.format
				 FROM %i r
				 INNER JOIN %i j ON j.id = r.broadcast_id
				 WHERE r.status = %s
				   AND j.status IN ('pending','running')
				 LIMIT %d",
				$recipients_table,
				$jobs_table,
				'pending',
				self::BATCH_SIZE
			)
		);

		if ( empty( $rows ) ) {
			self::finalize_jobs();
			return;
		}

		// Mark jobs as running.
		$job_ids = array_unique( array_column( $rows, 'broadcast_id' ) );
		foreach ( $job_ids as $jid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = 'running' WHERE id = %d AND status = 'pending'",
					$jobs_table,
					(int) $jid
				)
			);
		}

		// Track per-job counters for this batch.
		$sent_counts   = [];
		$failed_counts = [];
		foreach ( $job_ids as $jid ) {
			$sent_counts[ $jid ]   = 0;
			$failed_counts[ $jid ] = 0;
		}

		foreach ( $rows as $row ) {
			$messages = json_decode( $row->messages_json, true );
			$locale   = $row->locale;
			$text     = $messages[ $locale ] ?? $messages['en_US'] ?? '';

			if ( '' === $text ) {
				// No message — mark failed.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET status = 'failed', error = %s WHERE id = %d",
						$recipients_table,
						'No message for locale',
						(int) $row->id
					)
				);
				$failed_counts[ $row->broadcast_id ]++;
				continue;
			}

			// Send via appropriate method.
			switch ( $row->format ) {
				case 'html':
					$result = $bot->send_message( $text, $row->chat_id );
					break;
				case 'markdown':
					$result = $bot->send_markdown_message( $text, $row->chat_id );
					break;
				default: // plain
					$result = $bot->send_plain_message( $text, $row->chat_id );
					break;
			}

			if ( ! empty( $result->ok ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET status = 'sent', sent_at = %s WHERE id = %d",
						$recipients_table,
						current_time( 'mysql' ),
						(int) $row->id
					)
				);
				$sent_counts[ $row->broadcast_id ]++;
			} else {
				$error_msg = $result->description ?? 'Unknown error';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET status = 'failed', error = %s WHERE id = %d",
						$recipients_table,
						$error_msg,
						(int) $row->id
					)
				);
				$failed_counts[ $row->broadcast_id ]++;
			}

			// Rate limiting: 20 msgs/sec (well under Telegram's 30/sec limit).
			usleep( 50000 );
		}

		// Update job counters.
		foreach ( $job_ids as $jid ) {
			if ( $sent_counts[ $jid ] > 0 || $failed_counts[ $jid ] > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE %i
						 SET sent   = sent   + %d,
						     failed = failed + %d
						 WHERE id = %d",
						$jobs_table,
						$sent_counts[ $jid ],
						$failed_counts[ $jid ],
						(int) $jid
					)
				);
			}
		}

		// Finalize completed jobs.
		self::finalize_jobs();

		// Chain next batch if pending recipients remain.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i r
				 INNER JOIN %i j ON j.id = r.broadcast_id
				 WHERE r.status = %s AND j.status IN ('pending','running')",
				$recipients_table,
				$jobs_table,
				'pending'
			)
		);

		if ( $remaining > 0 && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	/**
	 * Check for jobs where all recipients have been processed and mark them final.
	 */
	private static function finalize_jobs(): void {
		global $wpdb;

		$jobs_table       = self::jobs_table();
		$recipients_table = self::recipients_table();

		// Jobs with no pending recipients left.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$running_jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, total, sent, failed FROM %i WHERE status = 'running'",
				$jobs_table
			)
		);

		foreach ( $running_jobs as $job ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$pending_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE broadcast_id = %d AND status = 'pending'",
					$recipients_table,
					(int) $job->id
				)
			);

			if ( $pending_count > 0 ) {
				continue; // Still processing.
			}

			$sent   = (int) $job->sent;
			$failed = (int) $job->failed;
			$total  = (int) $job->total;

			if ( $failed === 0 && $sent === $total ) {
				$final_status = 'completed';
			} elseif ( $sent === 0 ) {
				$final_status = 'failed';
			} else {
				$final_status = 'partial';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = %s WHERE id = %d",
					$jobs_table,
					$final_status,
					(int) $job->id
				)
			);
		}
	}

	// ---------------------------------------------------------------------------
	// Progress & history
	// ---------------------------------------------------------------------------

	/**
	 * Get progress data for a single job.
	 *
	 * @param int $job_id
	 * @return array|null
	 */
	public static function get_job_progress( int $job_id ): ?array {
		global $wpdb;

		$jobs_table = self::jobs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$job = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, total, sent, failed FROM %i WHERE id = %d",
				$jobs_table,
				$job_id
			)
		);

		if ( ! $job ) {
			return null;
		}

		$total   = (int) $job->total;
		$sent    = (int) $job->sent;
		$failed  = (int) $job->failed;
		$done    = $sent + $failed;
		$percent = $total > 0 ? (int) round( $done / $total * 100 ) : 0;

		// Estimate remaining time: remaining_batches × 5 min (assumed cron interval).
		$remaining     = max( 0, $total - $done );
		$est_minutes   = (int) ceil( $remaining / self::BATCH_SIZE * 5 );

		return [
			'id'          => (int) $job->id,
			'status'      => $job->status,
			'total'       => $total,
			'sent'        => $sent,
			'failed'      => $failed,
			'percent'     => $percent,
			'est_minutes' => $est_minutes,
		];
	}

	/**
	 * Return a paginated list of all broadcast jobs.
	 *
	 * @param int $limit
	 * @param int $offset
	 * @return array
	 */
	public static function get_history( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$jobs_table = self::jobs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, messages_json, format, status, total, sent, failed
				 FROM %i
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$jobs_table,
				$limit,
				$offset
			)
		);

		return $rows ?: [];
	}

	/**
	 * Return delivery history for a specific user.
	 *
	 * @param int $user_id
	 * @param int $limit
	 * @return array
	 */
	public static function get_recipient_history( int $user_id, int $limit = 20 ): array {
		global $wpdb;

		$jobs_table       = self::jobs_table();
		$recipients_table = self::recipients_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.broadcast_id, r.status, r.sent_at, r.error,
				        j.created_at, j.format, j.messages_json
				 FROM %i r
				 INNER JOIN %i j ON j.id = r.broadcast_id
				 WHERE r.user_id = %d
				 ORDER BY j.created_at DESC
				 LIMIT %d",
				$recipients_table,
				$jobs_table,
				$user_id,
				$limit
			)
		);

		return $rows ?: [];
	}

	// ---------------------------------------------------------------------------
	// Init
	// ---------------------------------------------------------------------------

	/**
	 * Register the cron action handler.
	 * Called from Init::__construct().
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'process_batch' ] );
	}
}
