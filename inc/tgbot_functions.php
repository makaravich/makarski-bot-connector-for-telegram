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

if (!function_exists(__NAMESPACE__ . '\create_broadcast')) {
    /**
     * Create a broadcast job programmatically (public API for child plugins).
     *
     * @param array  $messages     Locale-keyed messages, e.g. ['en_US' => 'Hello!', 'ru_RU' => 'Привет!'].
     * @param array  $user_ids     WP user IDs to include.
     * @param string $format       'plain' | 'html' | 'markdown'.
     * @param string $campaign_key Optional campaign identifier for deduplication of recurring campaigns.
     * @return int|false The new job ID, or false on failure.
     */
    function create_broadcast(array $messages, array $user_ids, string $format = 'plain', string $campaign_key = ''): int|false {
        return Broadcast::create_job($messages, $format, $user_ids, $campaign_key);
    }
}

if (!function_exists(__NAMESPACE__ . '\user_received_campaign')) {
    /**
     * Check whether a user already has a delivery record for the given campaign.
     *
     * @param int    $user_id      WP user ID.
     * @param string $campaign_key Campaign identifier passed to create_broadcast().
     * @return bool
     */
    function user_received_campaign(int $user_id, string $campaign_key): bool {
        return Broadcast::user_received_campaign($user_id, $campaign_key);
    }
}
