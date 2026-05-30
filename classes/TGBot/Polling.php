<?php

namespace TGBot;

class Polling {

	const CRON_HOOK     = 'tgbot_polling_tick';
	const CRON_SCHEDULE = 'tgbot_polling_interval';
	const OFFSET_OPTION = 'tgbot_polling_offset';

	public static function init(): void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedule' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'tick' ] );
	}

	/**
	 * Register a custom cron interval from settings.
	 */
	public static function add_cron_schedule( array $schedules ): array {
		$interval = (int) ( tgbot_get_option( 'gen_tg_polling_interval' ) ?: 30 );

		$schedules[ self::CRON_SCHEDULE ] = [
			'interval' => max( 5, $interval ),
			/* translators: %d is the polling interval in seconds */
			'display'  => sprintf( __( 'Every %d seconds', 'tg-bot' ), $interval ),
		];

		return $schedules;
	}

	/**
	 * Schedule or unschedule cron depending on mode.
	 * When switching to polling, also deletes the Telegram webhook.
	 */
	public static function reschedule( string $mode, int $interval ): void {
		self::unschedule();

		if ( $mode === 'polling' ) {
			// Remove webhook so Telegram sends updates to getUpdates instead
			$token = tgbot_get_option( 'gen_tg_token' );
			if ( $token ) {
				$bot = new BotApi( $token, false );
				$bot->delete_webhook();
			}

			// Register the schedule inline so it's available immediately
			// (cron_schedules filter may not be registered yet during option save)
			add_filter( 'cron_schedules', function ( $schedules ) use ( $interval ) {
				$schedules[ self::CRON_SCHEDULE ] = [
					'interval' => max( 5, $interval ),
					'display'  => sprintf( 'Every %d seconds', $interval ),
				];
				return $schedules;
			} );

			$result = wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK, [], true );
			if ( is_wp_error( $result ) ) {
				error_log( '[TGBot Polling] wp_schedule_event failed: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * One polling tick: fetch updates from Telegram and process each one.
	 */
	public static function tick(): void {
		if ( ! ( tgbot_get_option( 'gen_tg_enabled' ) ?? true ) ) {
			return;
		}

		$token = tgbot_get_option( 'gen_tg_token' );
		if ( ! $token ) {
			return;
		}

		$offset = (int) get_option( self::OFFSET_OPTION, 0 );
		$bot    = new BotApi( $token, false );

		$url  = "https://api.telegram.org/bot{$token}/getUpdates?timeout=0&limit=100"
			. ( $offset > 0 ? '&offset=' . $offset : '' );
		$resp = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $resp ) ) {
			error_log( '[TGBot Polling] wp_remote_get error: ' . $resp->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			error_log( '[TGBot Polling] unexpected HTTP code: ' . wp_remote_retrieve_response_code( $resp ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$data = json_decode( wp_remote_retrieve_body( $resp ) );

		if ( empty( $data->ok ) || empty( $data->result ) ) {
			return;
		}

		foreach ( $data->result as $update ) {
			self::process_update( $bot, $update );

			// Advance offset past this update
			update_option( self::OFFSET_OPTION, $update->update_id + 1, false );
		}
	}

	/**
	 * Inject one update into the bot and fire the same hook as webhook mode.
	 */
	private static function process_update( BotApi $bot, object $update ): void {
		$bot_map = [
			'auto_exec'       => false,
			'help_message'    => Init::get_help_message(),
			'request_respond' => $update,
		];

		$token   = tgbot_get_option( 'gen_tg_token' );
		$new_bot = new Bot( $token, false, $bot_map );

		do_action( 'tgbot_bot_call', $new_bot );

		if ( ! empty( $update->pre_checkout_query ) ) {
			do_action( 'tgbot_pre_checkout_query', $new_bot, $update->pre_checkout_query, $update->pre_checkout_query->from->id );
		}

		if ( ! empty( $update->message->successful_payment ) ) {
			$chat_id = $update->message->chat->id ?? 0;
			$user_id = (int) ProcessMessages::get_user_by_chat_id( $chat_id );
			do_action( 'tgbot_successful_payment', $new_bot, $update->message->successful_payment, $user_id );
		}
	}
}
