<?php

namespace TGBot;

class BotCommands {

	public function start( $bot ): void {
		$bot_map   = $bot->get_map();
		$user_data = $bot_map->request_respond->message->from ?? null;

		$bot->send_message( __( 'Start command', 'makarski-bot-connector-for-telegram' ) );
	}
}
