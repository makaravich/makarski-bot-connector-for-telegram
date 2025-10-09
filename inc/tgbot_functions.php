<?php

namespace TGBot;

global $tg_bot_commands;
$tg_bot_commands = [];

if (!function_exists('register_bot_command')) {
    /**
     * Add bot command
     *
     * @param string $command
     * @param callable $callback
     * @return void
     */
    function register_bot_command(string $command, callable $callback): void {
        global $tg_bot_commands;

        $callback = apply_filters('tgbot_register_bot_command', $callback, $command);

        if ($callback) {
            $tg_bot_commands[$command] = $callback;
        }
    }
}

if (!function_exists('get_registered_bot_commands')) {
    /**
     * Returns array of registered commands for the Telegram Bot
     *
     * @return array
     */
    function get_registered_bot_commands(): array {
        global $tg_bot_commands;

        if (!empty($tg_bot_commands) && is_array($tg_bot_commands)) {
            return $tg_bot_commands;
        } else {
            return [];
        }
    }
}
