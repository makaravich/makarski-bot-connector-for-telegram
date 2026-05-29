<?php

namespace TGBot;

class Core {
	/**
	 * Parse a received message to separate command for the bot and its data
	 *
	 * @param $bot
	 *
	 * @return object
	 */
	public static function parse_data_command( $bot ): object {
		$data_command = json_decode( $bot->request_respond->callback_query->data ?? '' );
		$data         = [];

		// If empty, then maybe it is just a string?
		if ( ! $data_command ) {
			$data_command = $bot->request_respond->callback_query->data ?? '';
		}

		if ( $data_command ) {
			if ( is_object( $data_command ) ) {
				$data           = (object) $data_command;
				$button_command = ltrim( $data_command->command, '/' );
				$data->command  = $button_command;
			} elseif ( is_string( $data_command ) ) {
				$button_command  = ltrim( $data_command, '/' );
				$data['command'] = $button_command;
			} else {
				$data['command'] = '';
			}
		}

		return (object) $data;
	}

	public static function prepare_message_nav_buttons( object $posts_obj, int $page, int $message_id ): array {
		$nav_buttons = [];

		if ( $posts_obj->has_previous ) {
			$prev_button   = [
				'text'          => '◀️ ' . __( 'Back', 'tgbot' ),
				'callback_data' => json_encode( [
					'command'    => 'links',
					'page'       => $page - 1,
					'message_id' => $message_id
				] )
			];
			$nav_buttons[] = $prev_button;
		}

		if ( $posts_obj->has_next ) {
			$next_button   = [
				'text'          => __( 'Next', 'tgbot' ) . ' ▶️',
				'callback_data' => json_encode( [
					'command'    => 'links',
					'page'       => $page + 1,
					'message_id' => $message_id
				] )
			];
			$nav_buttons[] = $next_button;
		}

		return $nav_buttons;
	}


	public static function set_current_user( $user_id ): void {
		wp_set_current_user( $user_id );
		//wp_set_auth_cookie( $user_id );

		// Set user language as current
		$current_lang = get_user_meta( $user_id, 'locale', true ) ?? 'en_US';

		// Set current language
		switch_to_locale( $current_lang );

		// Reload translations for the user's locale
		add_action( 'init', function () {
			load_plugin_textdomain( 'tgbot', false, dirname( plugin_basename( TGBOT_PLUGIN_MAIN_FILE ) ) . '/languages/' );
		} );
	}


	/**
	 * Set the TG webhook
	 *
	 * @param string $relative_url
	 * @param string $token
	 *
	 * @return void
	 */
	public static function set_tg_webhook( string $relative_url, string $token = '' ): void {
		if ( empty( $token ) ) {
			$token = tgbot_get_option( 'gen_tg_token' );
		}

		if ( $token ) {
			$full_endpoint = get_home_url( null, $relative_url );

			$bot = new BotApi( $token, false );
			$bot->set_webhook( $full_endpoint );
		} else {
			error_log( '{error} There is no endpoint value when registration new TG Endpoint' );
		}
	}
}