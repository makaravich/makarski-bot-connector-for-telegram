<?php

namespace TGBot;

use WP_Error;

class ProcessMessages {
    private static Bot $bot;

    public static function init(): void {
        add_action('tgbot_bot_call', [__CLASS__, 'process_bot_call']);
    }

    /**
     * Processing of a Telegram message
     *
     * @param $bot
     *
     * @return void
     */
    public static function process_bot_call($bot): void {
        self::$bot = $bot;
        $chat_id = $bot->chat_id;

        // Return if it is a direct call
        if (!$chat_id) {
            return;
        }

        $user_data = self::get_user_from_chat_id($chat_id);

        if (is_array($user_data) && !$user_data['success']) {
            $bot->send_message($user_data['error'] ?? __('Error', 'tg-bot'));

            return;
        } elseif (is_int($user_data) && $user_data != 0) {
            $user_id = $user_data;

            Core::set_current_user($user_id);

            // Re-create the bot to update translations
            $token = tgbot_get_option( 'gen_tg_token' );
            $bot_map = $bot->get_map();
            $bot_map['request_respond'] = $bot->request_respond;

            unset($bot);

            $bot = new Bot($token, false, $bot_map);

            // Get command from the bot
            $command = $bot->get_last_received_text();

            /**
             * Try to run bot command
             */
            // Try to parse JSON
            $data_command = Core::parse_data_command($bot);

            if (is_callable(['BotCommands', $data_command->command ?? ''])) {
                call_user_func(['BotCommands', $data_command->command], $bot);
            }

            // Return if the message is empty
            if (empty($command)) {
                // return;
            }

            $clean_command    = ltrim( $command, '/' );
            $is_slash_command = str_starts_with( $command, '/' );
            $is_registered    = ! empty( $clean_command ) && isset( get_registered_bot_commands()[ $clean_command ] );

            if ( $is_slash_command || $is_registered ) {
                // Do command
                do_action( 'tgbot_handle_custom_bot_commands', $bot, $user_id, $command );

                $bot->run_command($command);
            } else {
                $message_array = $bot->get_request();

                $message = self::process_message($bot, $user_id, $message_array);
                /*                if ($message['message']) {
                                    $bot->send_message($message['message']);

                                    return;
                                }*/
            }
        } else {
            $bot->send_message(__('Error', 'tg-bot'));
        }

    }

    /**
     * Return user ID by chat_it. Create user if not exists
     *
     * @param $chat_id
     *
     * @return int|mixed
     */
    private static function get_user_from_chat_id($chat_id): mixed {
        $user = self::get_user_by_chat_id($chat_id);

        if (!$user) {
            $user_data = self::create_user_by_chat_id($chat_id);
            if ($user_data['success']) {
                return $user_data['user_id'];
            } else {
                return $user_data;
            }
        } else {
            return $user;
        }
    }

    /**
     * Create a user from TG ID
     *
     * @param $tg_id
     *
     * @return array
     */
    private static function create_user_by_chat_id($tg_id): array {
        if (!$tg_id) {
            return [
                'success' => false,
                'error' => __('The Chat ID is empty.', 'tg-bot')
            ];
        }

        if (!function_exists('username_exists') || !function_exists('wp_create_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $user_data = self::$bot->request_respond->message->from ?? null;

        $username = sanitize_user($tg_id);
        $password = wp_generate_password();
        $email    = 'tg-' . $tg_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST );

        if (!username_exists($username)) {
            $user_id = wp_create_user($username, $password, $email);

            if (!is_wp_error($user_id)) {
                wp_update_user([
                    'ID' => $user_id,
                    'first_name' => sanitize_text_field($user_data->first_name ?? 'null'),
                    'last_name' => sanitize_text_field($user_data->last_name ?? 'null'),
                    'locale' => self::get_user_locale($user_data),
                ]);

                update_user_meta($user_id, 'tg_nickname', sanitize_user($user_data->username ?? null));

                return [
                    'success' => true,
                    'user_id' => $user_id,
                    'password' => $password // Can be removed if the password not needed
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $user_id->get_error_message()
                ];
            }
        } else {
            return [
                'success' => false,
                'error' => __('User already exists.', 'tg-bot')
            ];
        }
    }

    /**
     * Find the existing user by its username (chat_id)
     *
     * @param $chat_id
     *
     * @return int
     */
    public static function get_user_by_chat_id($chat_id): int {
        $username = sanitize_user($chat_id);
        $user = get_user_by('login', $username);

        return $user ? $user->ID : false;
    }

    /**
     * Checks if user can make a new short link
     *
     * @param $bot
     * @param $user_id
     * @param object|string $message
     *
     * @return array|bool
     */
    private static function process_message($bot, $user_id, object|string $message): array|bool {
        $normalized = self::prepare_message( $bot, $user_id, $message );

        // Primary hook — fires for all message types (text, media, callback_query).
        do_action( 'tgbot_message', $bot, $user_id, $normalized );

        // Backward-compatibility alias — deprecated, use tgbot_message instead.
        if ( has_action( 'tgbot_process_multimedia_message' ) ) {
            do_action( 'tgbot_process_multimedia_message', $bot, $user_id, $normalized );
        }

        // Raw update hook for advanced use cases.
        do_action( 'tgbot_raw_message', $bot, $user_id, $message );

        return false;
    }

    /**
     * Normalize any incoming update into a consistent message object.
     *
     * Returns an object with:
     *   - type           (string)  'text' | 'image' | 'video' | 'audio' | 'voice' |
     *                              'video_note' | 'sticker' | 'document' | 'callback_query'
     *   - text           (string)  message text, caption, or callback data
     *   - files          (int[])   WP attachment IDs of downloaded files (one per update)
     *   - has_media_group (bool)   true when this update is part of a multi-file album
     *   - media_group_id (string)  Telegram media_group_id, or '' if not part of a group
     *
     * Note: each file in a media group arrives as a separate update with the same
     * media_group_id. Handle each update individually or group them yourself using
     * media_group_id as the key.
     *
     * @param $bot
     * @param $user_id
     * @param object|string $original_message
     * @return object
     */
    private static function prepare_message( $bot, $user_id, object|string $original_message ): object {
        $message       = $original_message->message ?? (object) [];
        $document_type = self::detect_media_type( $message );

        if ( $document_type ) {
            return self::prepare_media_message( $message, $document_type, $original_message );
        }

        if ( ! empty( $original_message->callback_query?->data ) ) {
            return self::prepare_callback_query_message( $original_message );
        }

        return self::prepare_text_message( $message );
    }

    /**
     * Detect media type from message
     */
    private static function detect_media_type($message): string {
        $media_types = [
            'photo' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            'voice' => 'voice',
            'video_note' => 'video_note',
            'sticker' => 'sticker',
            'document' => 'document',
        ];

        return array_reduce(
            array_keys($media_types),
            fn($carry, $type) => $carry ?: (!empty($message->$type) ? $media_types[$type] : ''),
            ''
        );
    }

    /**
     * Prepare media message object
     */
    private static function prepare_media_message($message, string $document_type, $original_message): object {
        $group_id = $message->media_group_id ?? '';
        $files    = self::download_media_file($original_message);

        return (object)[
            'type'            => $document_type,
            'text'            => $message->caption ?? '',
            'files'           => $files,
            'has_media_group' => $group_id !== '',
            'media_group_id'  => (string) $group_id,
        ];
    }

    /**
     * Download media file and return attachment IDs
     */
    private static function download_media_file($original_message): array {
        $files = [];
        $document_url = self::$bot->get_document_url($original_message);

        if (!empty(trim($document_url))) {
            $document_wp = self::download_remote_file_to_media_library($document_url);

            if (!empty($document_wp['attachment_id'])) {
                $files[] = $document_wp['attachment_id'];
            }
        }

        return $files;
    }

    /**
     * Prepare text-only message object
     */
    private static function prepare_text_message($message): object {
        return (object)[
            'type'            => 'text',
            'text'            => $message->text ?? '',
            'files'           => [],
            'has_media_group' => false,
            'media_group_id'  => '',
        ];
    }

    private static function prepare_callback_query_message($message): object {
        return (object)[
            'type'            => 'callback_query',
            'text'            => $message->callback_query->data ?? '',
            'files'           => [],
            'has_media_group' => false,
            'media_group_id'  => '',
            'callback_query'  => $message->callback_query,
        ];
    }

    /**
     * Downloads a remote file and saves it to the WordPress media library
     *
     * @param string|null $remote_url URL of the remote file
     * @param int $post_id Post ID to attach the file to (optional)
     * @param string $description File description (optional)
     *
     * @return array|WP_Error Array with file data or error object
     */
    public static function download_remote_file_to_media_library(?string $remote_url, int $post_id = 0, string $description = ''): WP_Error|array {
        // Check if URL is not empty
        if (empty($remote_url)) {
            return new WP_Error('empty_url', 'File URL cannot be empty');
        }

        // Include required WordPress files
        if (!function_exists('media_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }

        // Get filename from URL
        $file_name = basename( wp_parse_url( $remote_url, PHP_URL_PATH ) );

        // Generate filename if empty
        if (empty($file_name) || strpos($file_name, '.') === false) {
            $file_name = 'remote_file_' . time() . '.jpg';
        }

        // Download the file
        $response = wp_remote_get($remote_url, array(
            'timeout' => 60,
            'user-agent' => 'WordPress/' . get_bloginfo('version')
        ));

        // Check response
        if (is_wp_error($response)) {
            return new WP_Error('download_failed', 'Failed to download file: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new WP_Error('http_error', 'HTTP error: ' . $response_code);
        }

        // Get file content
        $file_content = wp_remote_retrieve_body($response);
        if (empty($file_content)) {
            return new WP_Error('empty_file', 'File is empty or not found');
        }

        // Create temporary file
        $temp_file = wp_tempnam($file_name);
        if (!$temp_file) {
            return new WP_Error('temp_file_failed', 'Failed to create temporary file');
        }

        // Write content to temporary file
        $file_written = file_put_contents($temp_file, $file_content);
        if ($file_written === false) {
            return new WP_Error('file_write_failed', 'Failed to write file');
        }

        // Prepare file array for upload
        $file_array = array(
            'name' => $file_name,
            'tmp_name' => $temp_file,
            'size' => filesize($temp_file)
        );

        // Determine MIME type
        $wp_filetype = wp_check_filetype_and_ext($temp_file, $file_name);
        if (!$wp_filetype['ext'] || !$wp_filetype['type']) {
            wp_delete_file( $temp_file );

            return new WP_Error('invalid_file_type', 'Unsupported file type');
        }

        $file_array['type'] = $wp_filetype['type'];

        // Upload the file to the media library
        $attachment_id = media_handle_sideload($file_array, $post_id, $description);

        // Remove temporary file
        if (file_exists($temp_file)) {
            wp_delete_file( $temp_file );
        }

        // Check if upload was successful
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        // Get URL of the uploaded file
        $attachment_url = wp_get_attachment_url($attachment_id);

        // Return file data
        return array(
            'attachment_id' => $attachment_id,
            'url' => $attachment_url,
            'file_name' => $file_name,
            'file_type' => $wp_filetype['type']
        );
    }


    /**
     * Simplified function, returns only URL of the downloaded file
     *
     * @param string|null $remote_url URL of the remote file to download
     * @param int $post_id ID of post to attach the file to (optional)
     *
     * @return string|false URL of the new file or false on error
     */
    public static function download_remote_file_get_url(?string $remote_url, int $post_id = 0): bool|string {
        $result = self::download_remote_file_to_media_library($remote_url, $post_id);

        if (is_wp_error($result)) {
            error_log( 'File downloading error: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

            return false;
        }

        return $result['url'];
    }


    /**
     * Returns locale for WordPress based on TG User Data
     *
     * @param $user_data
     *
     * @return string
     */
    private static function get_user_locale($user_data): string {
        $locale = sanitize_text_field($user_data->language_code);

        $locales = [
            'en' => 'en_US',
            'fr' => 'fr_FR',
            'es' => 'es_ES',
            'de' => 'de_DE',
            'it' => 'it_IT',
            'nl' => 'nl_NL',
            'pl' => 'pl_PL',
            'ru' => 'ru_RU',
            'be' => 'bel',
        ];

        if ($locale && array_key_exists($locale, $locales)) {
            return $locales[$locale];
        } else {
            return 'en_US';
        }

    }

}