=== Telegram Messenger Integration ===
Contributors: mcarena77
Tags: telegram, bot, messenger, chatbot, notifications
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.2.30
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to a Telegram bot. Receive messages, handle commands, send notifications, and build custom bot logic using WordPress hooks.

== Description ==

**Telegram Messenger Integration** provides a clean foundation for building Telegram bots powered by WordPress. It handles all the low-level communication with the Telegram Bot API so you can focus on your bot's logic.

= What it does =

* Registers a Telegram bot webhook or runs in polling mode (via WP-Cron)
* Automatically creates WordPress users for incoming chat IDs
* Dispatches incoming messages and commands through WordPress action hooks
* Provides a full-featured BotApi class with 30+ methods

= Key features =

* **Webhook & Polling** — works on any hosting; polling mode requires no public HTTPS URL
* **Command routing** — register bot commands with `register_bot_command()`
* **Normalized message hook** — `tgbot_message` fires for all message types (text, photo, voice, video, document, callback_query) with a consistent object structure
* **Stars payments** — built-in support for Telegram Stars invoices, pre-checkout, and refunds
* **Multipart uploads** — send photos, audio, voice messages, videos, and documents
* **Internationalization** — translatable, ships with a .pot file

= Available BotApi methods =

`send_message`, `send_markdown_message`, `send_photo`, `send_document`, `send_audio`, `send_voice`, `send_video`, `send_animation`, `send_chat_action`, `send_location`, `send_stars_invoice`, `forward_message`, `copy_message`, `delete_message`, `delete_messages`, `edit_message`, `edit_message_markup`, `answer_callback_query`, `answer_pre_checkout_query`, `set_my_commands`, `set_webhook`, `delete_webhook`, `get_webhook_info`, `get_updates`, `get_document_url`, `get_photo_url`, `refund_star_payment`

= Available action hooks =

**`tgbot_message`** *(primary hook)*
Fires for every non-command incoming message (text, media, callback_query).

    add_action( 'tgbot_message', function( $bot, $user_id, $message ) {
        // $message->type   — 'text' | 'image' | 'voice' | 'video' | 'audio'
        //                     | 'document' | 'sticker' | 'video_note' | 'callback_query'
        // $message->text   — message text, caption, or callback data
        // $message->files  — array of WP attachment IDs (downloaded files)
        // $message->has_media_group — true if part of a multi-file album
        // $message->media_group_id  — Telegram media_group_id string
        if ( $message->type === 'voice' ) {
            $bot->send_message( 'Got a voice message!' );
        }
    }, 10, 3 );

**`tgbot_handle_custom_bot_commands`**
Fires when a slash command is received, before the built-in dispatcher.

    add_action( 'tgbot_handle_custom_bot_commands', function( $bot, $user_id, $command ) {
        // $command — e.g. '/mycommand'
    }, 10, 3 );

**`tgbot_bot_call`**
Fires for every incoming update, including commands. Useful for cross-cutting concerns.

**`tgbot_pre_checkout_query`**
Fires when a Telegram Stars pre-checkout query arrives.

    add_action( 'tgbot_pre_checkout_query', function( $bot, $query, $user_id ) {
        $bot->answer_pre_checkout_query( $query->id, true );
    }, 10, 3 );

**`tgbot_successful_payment`**
Fires after a successful Telegram Stars payment.

    add_action( 'tgbot_successful_payment', function( $bot, $payment, $user_id ) {
        // $payment->telegram_payment_charge_id — use for refunds
        // $payment->total_amount — amount in Stars
    }, 10, 3 );

**`tgbot_raw_message`**
Fires with the raw Telegram update object for advanced use cases.

= Registering bot commands =

Use `TGBot\register_bot_command()` inside an `init` hook:

    add_action( 'init', function() {
        TGBot\register_bot_command( 'hello', function( $bot ) {
            $bot->send_message( 'Hello, ' . $bot->chat_id . '!' );
        } );

        TGBot\register_bot_command( 'ping', function( $bot ) {
            $bot->send_message( 'Pong 🏓' );
        } );
    } );

= Minimal plugin example =

    add_action( 'init', function() {
        TGBot\register_bot_command( 'start', function( $bot ) {
            $bot->send_message( 'Welcome!' );
        } );
    } );

    add_action( 'tgbot_message', function( $bot, $user_id, $msg ) {
        if ( $msg->type === 'text' ) {
            $bot->send_message( 'You said: ' . esc_html( $msg->text ) );
        }
    }, 10, 3 );

== Installation ==

1. Upload the `tg-bot` folder to `/wp-content/plugins/`
2. Activate the plugin via **Plugins** in WordPress admin
3. Go to **Settings → Telegram settings**
4. Enter your bot token (get one from [@BotFather](https://t.me/BotFather))
5. Choose connection mode:
   * **Webhook** — paste your site's HTTPS URL, click *Set Webhook*. Requires a public HTTPS URL.
   * **Polling** — no public URL needed. The bot polls Telegram via WP-Cron. Works on localhost.

== Frequently Asked Questions ==

= Does this work on localhost? =

Yes — use **Polling** mode. Telegram cannot reach localhost for webhooks, but polling works anywhere.

= What PHP version is required? =

PHP 8.0 or higher. PHP 8.1+ recommended.

= Can I use this with shared hosting? =

Yes. Tested on Orangehost and similar shared environments. Use Polling mode if outgoing HTTPS connections are restricted, or configure `ALTERNATE_WP_CRON` if WP-Cron has issues.

= How do I handle multiple files sent at once (media groups)? =

Each file in a Telegram album arrives as a separate `tgbot_message` action with `has_media_group = true` and the same `media_group_id`. Handle each file individually or buffer them yourself using `media_group_id` as the grouping key.

= Is `tgbot_process_multimedia_message` still supported? =

Yes, as a deprecated alias for `tgbot_message`. Migrate to `tgbot_message` — the signature is identical.

== Screenshots ==

1. Settings page — configure token, webhook URL, and connection mode
2. Webhook management panel — check status, set or delete webhook

== Changelog ==

= 0.2.30 =
* Renamed `tgbot_process_multimedia_message` to `tgbot_message`; added deprecated alias for backward compatibility
* Added `tgbot_raw_message` hook for advanced use cases
* Added `media_group_id` field to normalized message object

= 0.2.28 =
* Fixed PHP 8.5 deprecation: removed `curl_close()` (no-op since PHP 8.0)
* Fixed implicit nullable parameter in `send_document()`

= 0.2.27 =
* Added 11 new BotApi methods: `send_chat_action`, `send_audio`, `send_voice`, `send_video`, `send_animation`, `forward_message`, `copy_message`, `send_location`, `delete_messages`, `set_my_commands`, `refund_star_payment`

= 0.2.26 =
* Fixed callback_query commands (inline buttons) not dispatching in polling mode

= 0.2.25 =
* Auto-created user email now uses `tg-{chat_id}@{site_domain}` instead of `{id}@example.com`

= 0.2.22 – 0.2.24 =
* Fixed polling reschedule logic: moved to `sanitize_callback` so Save always applies mode even without settings change
* Removed debug logging from production code

= 0.2.18 – 0.2.21 =
* Fixed textdomain loading order (PHP notice in WP 6.7+)
* Fixed `finish_request()` header order for correct webhook response
* Fixed polling cron scheduling bugs (namespace, `add_option` hook, missing cron detection)

= 0.2.17 =
* Fixed polling namespace bug (`\BotApi` → `BotApi`)
* Added error logging to `wp_schedule_event` and polling tick

= 0.1.0 – 0.2.16 =
* Initial implementation: webhook management, polling mode, user auto-creation, Stars payments, command routing, internationalization

== Upgrade Notice ==

= 0.2.30 =
The `tgbot_process_multimedia_message` hook is deprecated. Please migrate your code to use `tgbot_message` — the signature is identical.
