<?php

namespace TGBot;

class Bot extends BotApi {

	public function run_command( $command ): void {
		$command = ltrim( $command, '/' );

		if ( strlen( $command ) > 100 ) {
			$this->send_message( __( 'Too long command', 'tg-bot' ) );

			return;
		}

		$tg_bot_commands = get_registered_bot_commands();

		if ( isset( $tg_bot_commands[ $command ] ) && is_callable( $tg_bot_commands[ $command ] ) ) {
			call_user_func( $tg_bot_commands[ $command ], $this );
		} else {
			$commander = new BotCommands();

			if ( is_callable( array( $commander, $command ) ) ) {
				$commander->$command( $this );
			} else {
				$this->send_message( __( 'Unknown command: ', 'tg-bot' ) . $command );
			}
		}
	}
}
