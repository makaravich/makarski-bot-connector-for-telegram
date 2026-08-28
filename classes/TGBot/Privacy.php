<?php

namespace TGBot;

/**
 * Keeps connector-created bot users off the public site.
 *
 * The connector creates a WordPress user for every Telegram account that
 * talks to the bot, with the chat_id as user_login/user_nicename. That is
 * enough for WordPress to publish an author archive at /author/<chat_id>/
 * (and answer ?author=<ID>), letting anyone confirm from the outside whether
 * a given Telegram account has contacted the bot on this site. The REST
 * users collection and the core users sitemap only list users with published
 * posts, so bot users stay out of them today — but that is a side effect of
 * them not posting, not a guarantee.
 *
 * Unlike a site-specific blanket fix, only users created by the connector
 * are hidden: the plugin may run on a site with a real blog where human
 * author pages must keep working. Disable entirely with:
 *
 *     add_filter( 'tgbot_protect_bot_users', '__return_false' );
 */
class Privacy {

	/** User meta flag set on every connector-created user. */
	const MARKER_META = 'tgbot_user';

	/** Option flag: legacy users (created before the marker) were back-filled. */
	const BACKFILL_OPTION = 'tgbot_bot_user_marker_backfilled';

	public static function init(): void {
		// Priority 0 puts the archive block ahead of redirect_canonical()
		// (template_redirect at 10) — otherwise ?author=<ID> answers with a
		// 301 to /author/<chat_id>/, leaking the nicename in the Location
		// header before anything can refuse the request.
		add_action( 'template_redirect', [ self::class, 'block_bot_author_archive' ], 0 );
		add_filter( 'wp_sitemaps_users_query_args', [ self::class, 'exclude_from_users_sitemap' ] );
		add_filter( 'rest_user_query', [ self::class, 'exclude_from_rest_collection' ] );
		add_filter( 'rest_prepare_user', [ self::class, 'hide_in_rest_response' ], 10, 2 );
	}

	/**
	 * Whether bot-user hiding is active. Evaluated lazily inside each hook so
	 * consumer plugins can register the filter at any point during load.
	 */
	private static function enabled(): bool {
		return (bool) apply_filters( 'tgbot_protect_bot_users', true );
	}

	/**
	 * Whether the user was created by the connector for a Telegram chat.
	 *
	 * Primarily the explicit marker meta; accounts created before the marker
	 * existed are recognized by the combination the connector always produced:
	 * a purely numeric user_login (the chat_id, negative for groups) plus the
	 * tg_nickname meta it stores on creation.
	 *
	 * @param int $user_id User ID.
	 */
	public static function is_bot_user( int $user_id ): bool {
		if ( get_user_meta( $user_id, self::MARKER_META, true ) ) {
			return true;
		}

		$user = get_userdata( $user_id );

		return $user
			&& preg_match( '/^-?\d+$/', $user->user_login )
			&& metadata_exists( 'user', $user_id, 'tg_nickname' );
	}

	/**
	 * Serve a genuine 404 (not a redirect) for a bot user's author archive —
	 * both /author/<chat_id>/ and the ?author=<ID> form used to enumerate IDs.
	 */
	public static function block_bot_author_archive(): void {
		if ( ! self::enabled() || ! is_author() ) {
			return;
		}

		$author = get_queried_object();

		if ( ! ( $author instanceof \WP_User ) || ! self::is_bot_user( $author->ID ) ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Keep bot users out of the core users sitemap. They only appear there
	 * once they have a published post — one post attributed to a bot user
	 * would otherwise advertise its chat_id to search engines.
	 *
	 * @param array $args WP_User_Query arguments.
	 * @return array
	 */
	public static function exclude_from_users_sitemap( array $args ): array {
		return self::enabled() ? self::add_marker_exclusion( $args ) : $args;
	}

	/**
	 * Keep bot users out of the public REST users collection (/wp/v2/users).
	 * Users who may list users in wp-admin keep seeing everything.
	 *
	 * @param array $prepared_args WP_User_Query arguments.
	 * @return array
	 */
	public static function exclude_from_rest_collection( array $prepared_args ): array {
		if ( ! self::enabled() || current_user_can( 'list_users' ) ) {
			return $prepared_args;
		}

		return self::add_marker_exclusion( $prepared_args );
	}

	/**
	 * Refuse single-user REST reads of bot users (also catches legacy
	 * accounts the meta-query exclusion cannot see before the back-fill ran).
	 *
	 * @param mixed    $response Prepared response.
	 * @param \WP_User $user     The requested user.
	 * @return mixed WP_REST_Response or WP_Error.
	 */
	public static function hide_in_rest_response( $response, $user ) {
		if ( ! self::enabled() || current_user_can( 'list_users' ) ) {
			return $response;
		}

		if ( self::is_bot_user( (int) $user->ID ) ) {
			return new \WP_Error(
				'rest_user_cannot_view',
				__( 'Sorry, you are not allowed to view this user.', 'makarski-bot-connector-for-telegram' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return $response;
	}

	/**
	 * One-time back-fill: stamp the marker meta on bot users created before
	 * the marker existed, so meta-query based exclusions cover them too.
	 */
	public static function maybe_backfill_marker(): void {
		if ( get_option( self::BACKFILL_OPTION ) ) {
			return;
		}

		$paged = 1;

		do {
			$users = get_users(
				[
					'meta_key'     => 'tg_nickname', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time migration
					'meta_compare' => 'EXISTS',
					'fields'       => [ 'ID', 'user_login' ],
					'number'       => 500,
					'paged'        => $paged,
					'orderby'      => 'ID',
					'order'        => 'ASC',
				]
			);

			foreach ( $users as $user ) {
				if ( preg_match( '/^-?\d+$/', $user->user_login ) ) {
					update_user_meta( (int) $user->ID, self::MARKER_META, 1 );
				}
			}

			$paged++;
		} while ( count( $users ) === 500 );

		update_option( self::BACKFILL_OPTION, 1 );
	}

	/**
	 * Append the marker NOT EXISTS clause to a WP_User_Query args array.
	 *
	 * @param array $args WP_User_Query arguments.
	 * @return array
	 */
	private static function add_marker_exclusion( array $args ): array {
		$clause = [
			'key'     => self::MARKER_META,
			'compare' => 'NOT EXISTS',
		];

		if ( empty( $args['meta_query'] ) ) {
			$args['meta_query'] = [ $clause ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- NOT EXISTS on an indexed meta table, public surfaces only
		} else {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- NOT EXISTS on the indexed meta table, public surfaces only
				'relation' => 'AND',
				$args['meta_query'],
				$clause,
			];
		}

		return $args;
	}
}
