<?php

namespace TGBot;

class Bot extends BotApi {

	public function run_command( $command ): void {
		$command = ltrim( $command, '/' );

		if ( strlen( $command ) > 200 ) {
			$this->send_message( __( 'Too long command', 'makarski-bot-connector-for-telegram' ) );
			return;
		}

		// Split "command param" into base command and optional parameter.
		$parts               = explode( ' ', $command, 2 );
		$base_command        = $parts[0];
		$this->command_param = isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '';

		$tg_bot_commands = get_registered_bot_commands();

		if ( isset( $tg_bot_commands[ $base_command ] ) && is_callable( $tg_bot_commands[ $base_command ] ) ) {
			call_user_func( $tg_bot_commands[ $base_command ], $this );
		} else {
			$commander = new BotCommands();

			if ( is_callable( array( $commander, $base_command ) ) ) {
				$commander->$base_command( $this );
			} else {
				$this->send_message( __( 'Unknown command: ', 'makarski-bot-connector-for-telegram' ) . $base_command );
			}
		}
	}
}
