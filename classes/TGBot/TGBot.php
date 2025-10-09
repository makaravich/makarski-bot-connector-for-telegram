<?php

namespace TGBot;

use Simple_Tg_Bot;

class TGBot extends Simple_Tg_Bot {
    public function run_command($command): void {
        $command = ltrim($command, '/');

        if (strlen($command) > 100) {
            $this->send_message(__('Too long command', 'tgbot'));

            return;
        }

        $tg_bot_commands = get_registered_bot_commands();

        if (isset($tg_bot_commands[$command]) && is_callable($tg_bot_commands[$command])) { // Try running user defined functions
            call_user_func($tg_bot_commands[$command], $this);
        } else { // Try running predefined functions
            $commander = new TGBotCommands();

            // Check if the method is callable
            if (is_callable([$commander, $command])) {
                $commander->$command($this); // Then run it
            } else {
                $this->send_message(__('Unknown command: ', 'tgbot') . $command);
            }
        }
    }
}