<?php

namespace TGBot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $tgbot_commands; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$tgbot_commands = [];

if (!function_exists('register_bot_command')) {
    /**
     * Add bot command
     *
     * @param string $command
     * @param callable $callback
     * @return void
     */
    function register_bot_command(string $command, callable $callback): void {
        global $tgbot_commands;

        $callback = apply_filters('tgbot_register_bot_command', $callback, $command);

        if ($callback) {
            $tgbot_commands[$command] = $callback;
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
        global $tgbot_commands;

        if (!empty($tgbot_commands) && is_array($tgbot_commands)) {
            return $tgbot_commands;
        } else {
            return [];
        }
    }
}
