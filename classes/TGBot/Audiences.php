<?php

namespace TGBot;

/**
 * Audience registry: named user segments for broadcasts.
 *
 * Child plugins register segments via the `tgbot_audiences` filter:
 *
 *   add_filter( 'tgbot_audiences', function ( array $audiences ): array {
 *       $audiences['my_segment'] = [
 *           'label'    => __( 'My segment', 'my-plugin' ),
 *           'callback' => fn(): array => [ 1, 2, 3 ], // WP user IDs
 *       ];
 *       return $audiences;
 *   } );
 */
class Audiences {

	const ALL_KEY = 'all';

	/**
	 * Return all registered audiences (built-in + filter), validated.
	 *
	 * @return array<string, array{label: string, callback: callable}>
	 */
	public static function get_all(): array {
		$audiences = [
			self::ALL_KEY => [
				'label'    => __( 'All bot users', 'makarski-bot-connector-for-telegram' ),
				'callback' => [ __CLASS__, 'all_bot_user_ids' ],
			],
		];

		$registered = apply_filters( 'tgbot_audiences', [] );

		foreach ( (array) $registered as $key => $audience ) {
			$key = sanitize_key( $key );
			if ( '' === $key || self::ALL_KEY === $key ) {
				continue;
			}
			if ( empty( $audience['label'] ) || empty( $audience['callback'] ) || ! is_callable( $audience['callback'] ) ) {
				continue;
			}
			$audiences[ $key ] = [
				'label'    => (string) $audience['label'],
				'callback' => $audience['callback'],
			];
		}

		return $audiences;
	}

	/**
	 * Resolve an audience key to an array of WP user IDs.
	 *
	 * @param string $key
	 * @return int[] Empty array for unknown keys.
	 */
	public static function resolve( string $key ): array {
		$audiences = self::get_all();
		$key       = sanitize_key( $key );

		if ( ! isset( $audiences[ $key ] ) ) {
			return [];
		}

		$ids = call_user_func( $audiences[ $key ]['callback'] );

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Built-in audience: every user with a Telegram nickname.
	 *
	 * @return int[]
	 */
	public static function all_bot_user_ids(): array {
		$ids = get_users(
			[
				'meta_key'     => 'tg_nickname', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => '!=',
				'meta_value'   => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'       => -1,
				'fields'       => 'ID',
			]
		);

		return array_map( 'intval', $ids );
	}
}
