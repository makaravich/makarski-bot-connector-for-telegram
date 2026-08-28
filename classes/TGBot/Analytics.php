<?php

namespace TGBot;

/**
 * Bot analytics: data layer.
 *
 * Keeps a lightweight per-chat registry plus three event logs, all written
 * from inside the connector, so every bot built on it gets analytics for
 * free. Deliberately NOT included: API token/cost accounting — that belongs
 * to bots that call LLM APIs, not to the connector.
 *
 * Tables:
 *  - tgbot_users       one row per chat (user or group): first-touch source,
 *                      arrival language, blocked_at, last_seen
 *  - tgbot_commands    one row per dispatched registered command
 *  - tgbot_deliveries  failed/skipped outgoing attempts (successes are not
 *                      logged to keep the table small; Broadcast keeps its
 *                      own full per-recipient log)
 *  - tgbot_referrals   who invited whom (reward granting is left to consumer
 *                      plugins via the 'tgbot_referral_completed' action)
 */
class Analytics {

	const DB_VERSION        = '1.0';
	const DB_VERSION_OPTION = 'tgbot_analytics_db_version';

	// ---------------------------------------------------------------------------
	// Table names
	// ---------------------------------------------------------------------------

	public static function users_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tgbot_users';
	}

	public static function commands_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tgbot_commands';
	}

	public static function deliveries_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tgbot_deliveries';
	}

	public static function referrals_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tgbot_referrals';
	}

	// ---------------------------------------------------------------------------
	// Schema
	// ---------------------------------------------------------------------------

	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$users           = self::users_table();
		$commands        = self::commands_table();
		$deliveries      = self::deliveries_table();
		$referrals       = self::referrals_table();

		$sql = "CREATE TABLE {$users} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			chat_id       BIGINT          NOT NULL,
			wp_user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source        VARCHAR(32)     NOT NULL DEFAULT '',
			tg_language   VARCHAR(16)     NOT NULL DEFAULT '',
			referral_code VARCHAR(16)     NOT NULL DEFAULT '',
			blocked_at    DATETIME                 DEFAULT NULL,
			last_seen     DATETIME                 DEFAULT NULL,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY chat_id (chat_id),
			KEY source (source),
			KEY created_at (created_at),
			KEY referral_code (referral_code)
		) {$charset_collate};

		CREATE TABLE {$commands} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			chat_id    BIGINT          NOT NULL,
			cmd        VARCHAR(64)     NOT NULL,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY cmd_created (cmd, created_at)
		) {$charset_collate};

		CREATE TABLE {$deliveries} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			chat_id    BIGINT          NOT NULL DEFAULT 0,
			method     VARCHAR(32)     NOT NULL DEFAULT '',
			status     VARCHAR(10)     NOT NULL DEFAULT 'failed',
			error      VARCHAR(255)    NOT NULL DEFAULT '',
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY chat_id (chat_id),
			KEY created_at (created_at)
		) {$charset_collate};

		CREATE TABLE {$referrals} (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			referrer_chat_id BIGINT          NOT NULL,
			referred_chat_id BIGINT          NOT NULL,
			created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY referred_chat_id (referred_chat_id),
			KEY referrer_chat_id (referrer_chat_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create/upgrade tables when the stored version differs, then back-fill
	 * registry rows for bot users created before this feature existed.
	 * Safe to call on every plugins_loaded.
	 */
	public static function maybe_upgrade_db(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::create_tables();
		self::backfill_users();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * One-time back-fill: a registry row for every already-existing bot user
	 * (recognized the same way Privacy does), with created_at taken from the
	 * WP account. Their source stays '' and is reported as "(pre-tracking)".
	 */
	private static function backfill_users(): void {
		global $wpdb;

		$paged = 1;

		do {
			$users = get_users(
				[
					'meta_key'     => 'tg_nickname', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time migration
					'meta_compare' => 'EXISTS',
					'fields'       => [ 'ID', 'user_login', 'user_registered' ],
					'number'       => 500,
					'paged'        => $paged,
					'orderby'      => 'ID',
					'order'        => 'ASC',
				]
			);

			foreach ( $users as $user ) {
				if ( ! preg_match( '/^-?\d+$/', $user->user_login ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						'INSERT IGNORE INTO %i (chat_id, wp_user_id, created_at) VALUES (%d, %d, %s)',
						self::users_table(),
						(int) $user->user_login,
						(int) $user->ID,
						$user->user_registered
					)
				);
			}

			$paged++;
		} while ( count( $users ) === 500 );
	}

	// ---------------------------------------------------------------------------
	// Writers
	// ---------------------------------------------------------------------------

	/** Current UTC timestamp in MySQL format (all analytics times are UTC). */
	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Register a chat in the analytics registry (first contact). Idempotent —
	 * an existing row is left untouched, preserving first-touch attribution.
	 *
	 * @param int         $chat_id    Telegram chat ID (negative for groups).
	 * @param int         $wp_user_id The auto-created WP user ID.
	 * @param string      $source     Normalized acquisition source.
	 * @param string      $language   Raw Telegram language_code on arrival.
	 */
	public static function record_user( int $chat_id, int $wp_user_id, string $source = '', string $language = '' ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (chat_id, wp_user_id, source, tg_language, last_seen, created_at)
				 VALUES (%d, %d, %s, %s, %s, %s)',
				self::users_table(),
				$chat_id,
				$wp_user_id,
				substr( $source, 0, 32 ),
				substr( $language, 0, 16 ),
				self::now(),
				self::now()
			)
		);
	}

	/**
	 * Mark a chat as active: bump last_seen, clear blocked_at (Telegram sends
	 * no "unblocked" event — the first incoming update IS that event), and
	 * capture the arrival language for rows that never had one.
	 *
	 * Also creates the row when missing, so accounts predating the registry
	 * get one on their next contact.
	 */
	public static function touch( int $chat_id, string $language = '', int $wp_user_id = 0 ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (chat_id, wp_user_id, tg_language, last_seen, created_at)
				 VALUES (%d, %d, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE
					last_seen   = VALUES(last_seen),
					blocked_at  = NULL,
					wp_user_id  = IF( wp_user_id = 0, VALUES(wp_user_id), wp_user_id ),
					tg_language = IF( tg_language = '', VALUES(tg_language), tg_language )",
				self::users_table(),
				$chat_id,
				$wp_user_id,
				substr( $language, 0, 16 ),
				self::now(),
				self::now()
			)
		);
	}

	/** Log one dispatched registered command. */
	public static function log_command( int $chat_id, string $cmd ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::commands_table(),
			[
				'chat_id'    => $chat_id,
				'cmd'        => substr( $cmd, 0, 64 ),
				'created_at' => self::now(),
			],
			[ '%d', '%s', '%s' ]
		);
	}

	/**
	 * Log a failed or suppressed outgoing attempt. Successful sends are not
	 * logged — the table records problems, not traffic.
	 */
	public static function log_delivery( int $chat_id, string $method, string $status, string $error = '' ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::deliveries_table(),
			[
				'chat_id'    => $chat_id,
				'method'     => substr( $method, 0, 32 ),
				'status'     => in_array( $status, [ 'failed', 'skipped' ], true ) ? $status : 'failed',
				'error'      => substr( $error, 0, 255 ),
				'created_at' => self::now(),
			],
			[ '%d', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Whether a Telegram error description means the chat is gone for good
	 * (blocked / deleted account / missing chat) rather than a transient
	 * failure. Allowlist on purpose — unknown errors must NOT mark anyone.
	 */
	public static function is_unreachable_error( string $description ): bool {
		foreach ( [ 'blocked by the user', 'user is deactivated', 'chat not found' ] as $needle ) {
			if ( false !== stripos( $description, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/** Stamp blocked_at on the chat's registry row (kept until the next incoming update). */
	public static function mark_blocked( int $chat_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET blocked_at = %s WHERE chat_id = %d AND blocked_at IS NULL',
				self::users_table(),
				self::now(),
				$chat_id
			)
		);
	}

	// ---------------------------------------------------------------------------
	// Sources & referrals
	// ---------------------------------------------------------------------------

	/**
	 * Normalize a /start deep-link payload into a source slug.
	 * '' → 'direct'; 'ref_*' → 'referral'; anything else is lowercased and
	 * stripped to [a-z0-9_-], capped at 32 chars.
	 */
	public static function normalize_source( string $payload ): string {
		$payload = strtolower( trim( $payload ) );

		if ( '' === $payload ) {
			return 'direct';
		}

		if ( str_starts_with( $payload, 'ref_' ) ) {
			return 'referral';
		}

		$payload = preg_replace( '/[^a-z0-9_-]/', '', $payload );

		return substr( $payload ?: 'other', 0, 32 );
	}

	/**
	 * Get (or lazily create) the chat's referral code for ?start=ref_<code>
	 * deep links. A random code, not the chat_id — chat_ids must not leak
	 * into shareable URLs.
	 */
	public static function referral_code( int $chat_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$code = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT referral_code FROM %i WHERE chat_id = %d', self::users_table(), $chat_id )
		);

		if ( '' !== $code ) {
			return $code;
		}

		$code = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET referral_code = %s WHERE chat_id = %d AND referral_code = ''",
				self::users_table(),
				$code,
				$chat_id
			)
		);

		// Lost a race or the row does not exist — read back the truth.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT referral_code FROM %i WHERE chat_id = %d', self::users_table(), $chat_id )
		);
	}

	/**
	 * Record "referrer invited referred" from a ref_<code> /start payload.
	 * Call ONLY at registration time — first-touch by construction.
	 * Fires 'tgbot_referral_completed' so consumer plugins can grant rewards.
	 *
	 * @return bool True when a new referral row was created.
	 */
	public static function record_referral( int $referred_chat_id, string $payload ): bool {
		global $wpdb;

		if ( ! preg_match( '/^ref_([a-z0-9]{4,16})$/i', trim( $payload ), $m ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$referrer = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT chat_id, wp_user_id FROM %i WHERE referral_code = %s',
				self::users_table(),
				strtolower( $m[1] )
			)
		);

		if ( ! $referrer || (int) $referrer->chat_id === $referred_chat_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (referrer_chat_id, referred_chat_id, created_at) VALUES (%d, %d, %s)',
				self::referrals_table(),
				(int) $referrer->chat_id,
				$referred_chat_id,
				self::now()
			)
		);

		if ( ! $inserted ) {
			return false;
		}

		/**
		 * A referral completed: someone registered via a ref_<code> link.
		 * Reward granting is up to the consumer plugin.
		 *
		 * @param int $referrer_chat_id
		 * @param int $referred_chat_id
		 * @param int $referrer_wp_user_id
		 */
		do_action( 'tgbot_referral_completed', (int) $referrer->chat_id, $referred_chat_id, (int) $referrer->wp_user_id );

		return true;
	}

	/**
	 * Extract the /start payload from a raw incoming message text.
	 * "/start ig_w32" → "ig_w32"; anything else → ''.
	 */
	public static function start_payload( string $text ): string {
		if ( preg_match( '#^/start(?:@\w+)?\s+(\S+)#i', trim( $text ), $m ) ) {
			return substr( $m[1], 0, 64 );
		}

		return '';
	}

	// ---------------------------------------------------------------------------
	// Readers (used by the Analytics admin page)
	// ---------------------------------------------------------------------------

	/**
	 * Start of "today" in the site timezone, converted to UTC (MySQL format) —
	 * so "new today" matches what the admin means by today.
	 */
	private static function today_start_utc(): string {
		$start = new \DateTimeImmutable( 'today', wp_timezone() );

		return $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/** @return array Overview counters for the dashboard tab. */
	public static function overview(): array {
		global $wpdb;

		$users = self::users_table();
		$today = self::today_start_utc();
		$week  = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$month = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*)                                        AS total,
				        SUM(chat_id < 0)                                AS groups_cnt,
				        SUM(created_at >= %s)                           AS new_today,
				        SUM(created_at >= %s)                           AS new_week,
				        SUM(created_at >= %s)                           AS new_month,
				        SUM(blocked_at IS NOT NULL)                     AS blocked,
				        SUM(last_seen IS NOT NULL AND last_seen >= %s)  AS active_week
				 FROM %i',
				$today,
				$week,
				$month,
				$week,
				$users
			),
			ARRAY_A
		);

		return array_map( 'intval', $row ?: [] );
	}

	/** @return array[] Command usage for the last $days days. */
	public static function command_stats( int $days = 30 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT cmd, COUNT(*) AS uses, COUNT(DISTINCT chat_id) AS users
				 FROM %i WHERE created_at >= %s
				 GROUP BY cmd ORDER BY uses DESC',
				self::commands_table(),
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		) ?: [];
	}

	/** @return array[] Recent failed/skipped deliveries. */
	public static function recent_deliveries( int $limit = 50 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT chat_id, method, status, error, created_at
				 FROM %i ORDER BY id DESC LIMIT %d',
				self::deliveries_table(),
				$limit
			)
		) ?: [];
	}

	/** @return array[] Chats currently marked blocked. */
	public static function blocked_users( int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT chat_id, wp_user_id, blocked_at, last_seen
				 FROM %i WHERE blocked_at IS NOT NULL
				 ORDER BY blocked_at DESC LIMIT %d',
				self::users_table(),
				$limit
			)
		) ?: [];
	}

	/** @return array[] Users per acquisition source, with blocked counts. */
	public static function source_stats(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source,
				        COUNT(*)                    AS users,
				        SUM(created_at >= %s)       AS new_month,
				        SUM(blocked_at IS NOT NULL) AS blocked
				 FROM %i GROUP BY source ORDER BY users DESC',
				gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
				self::users_table()
			)
		) ?: [];
	}

	/** @return array[] Arrival languages (raw Telegram language_code). */
	public static function language_stats(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT tg_language, COUNT(*) AS users
				 FROM %i GROUP BY tg_language ORDER BY users DESC',
				self::users_table()
			)
		) ?: [];
	}

	/** @return array Referral totals + top referrers. */
	public static function referral_stats(): array {
		global $wpdb;

		$referrals = self::referrals_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $referrals ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$month = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
				$referrals,
				gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$top = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT referrer_chat_id, COUNT(*) AS invited
				 FROM %i GROUP BY referrer_chat_id ORDER BY invited DESC LIMIT 10',
				$referrals
			)
		) ?: [];

		return [
			'total' => $total,
			'month' => $month,
			'top'   => $top,
		];
	}
}
