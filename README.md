# Telegram Messenger Integration

WordPress plugin that connects your site to a Telegram bot. Handles all Telegram Bot API communication so you can focus on your bot's logic using familiar WordPress hooks and filters.

**Version:** 0.3.2 · **Requires:** WordPress 6.2+, PHP 8.0+ · **License:** GPLv2

---

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Connection Modes](#connection-modes)
- [Broadcast](#broadcast)
- [Registering Commands](#registering-commands)
- [Action Hooks](#action-hooks)
- [Message Object](#message-object)
- [BotApi Methods](#botapi-methods)
- [Server Requirements](#server-requirements)

---

## Installation

1. Upload the `makarski-bot-connector-for-telegram` folder to `wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. Go to **Telegram Bot → Settings**
4. Paste your bot token (from [@BotFather](https://t.me/BotFather)) and click **Save**
5. Choose [connection mode](#connection-modes) and configure it

---

## Quick Start

A minimal echo bot in ~10 lines:

```php
// In your theme's functions.php or a custom plugin:

add_action( 'init', function () {
    TGBot\register_bot_command( 'start', function ( $bot ) {
        $bot->send_message( 'Hello! Send me any message.' );
    } );
} );

add_action( 'tgbot_message', function ( $bot, $user_id, $message ) {
    if ( $message->type === 'text' ) {
        $bot->send_message( 'You said: ' . esc_html( $message->text ) );
    }
}, 10, 3 );
```

---

## Connection Modes

### Webhook

Telegram pushes updates to your site in real time. Requires a **public HTTPS URL**.

1. Save the token
2. Enter your endpoint slug (e.g. `tg-endpoint`)
3. Click **Set Webhook**

### Polling

Your site pulls updates from Telegram via WP-Cron. Works on **any hosting including localhost** — no public URL needed.

1. Save the token
2. Switch mode to **Polling** and click **Save**
3. WP-Cron will start fetching updates on the next page load

> **Shared hosting tip:** If WP-Cron has issues, add `define('ALTERNATE_WP_CRON', true)` to `wp-config.php`.

---

## Broadcast

The **Broadcast** page (**Telegram Bot → Broadcast**) lets site administrators send messages to WordPress users who have a Telegram username configured.

### Features

- **Recipient selection** — filter users by language, select all or individual recipients, see live selected count
- **Per-locale messages** — compose separate message texts for each language present in your user base
- **Format** — choose Plain text, HTML, or MarkdownV2 per broadcast
- **Batched delivery** — processed via WP-Cron in batches of 200 at ~20 msg/sec (within Telegram's 30/sec limit); safe for large user bases without blocking the site
- **Progress tracking** — real-time progress bar with sent/failed counts and estimated time remaining
- **History** — full broadcast history on the Broadcast page; per-user history visible on each user's profile page

### How to send a broadcast

1. Go to **Telegram Bot → Broadcast**
2. Optionally filter the list by language, then select recipients
3. Click **Send Broadcast** — a confirmation modal opens with one textarea per language
4. Enter your messages, choose a format, and confirm
5. The job is queued and processed in the background; the page shows progress automatically

> Only users with a Telegram username saved in their WordPress profile appear as recipients.

---

## Registering Commands

Use `TGBot\register_bot_command()` inside an `init` hook. The callback receives the `Bot` instance.

```php
add_action( 'init', function () {

    TGBot\register_bot_command( 'hello', function ( $bot ) {
        $bot->send_message( 'Hello, ' . $bot->chat_id . '!' );
    } );

    TGBot\register_bot_command( 'ping', function ( $bot ) {
        $bot->send_message( 'Pong 🏓' );
    } );

    // With an inline keyboard
    TGBot\register_bot_command( 'menu', function ( $bot ) {
        $bot->send_message( 'Choose:', $bot->chat_id, [
            'inline_keyboard' => [
                [ [ 'text' => '📋 Help',    'callback_data' => 'help' ] ],
                [ [ 'text' => '💰 Balance', 'callback_data' => 'balance' ] ],
            ],
        ] );
    } );

} );
```

> Commands can be sent **with or without a leading slash** (`/help` and `help` both dispatch correctly).

---

## Action Hooks

### `tgbot_message` *(primary)*

Fires for **every non-command message**: text, photo, voice, video, document, sticker, animation, and `callback_query` (inline button taps).

```php
add_action( 'tgbot_message', function ( $bot, $user_id, $message ) {
    // $message — see Message Object section below
    // $user_id — WordPress user ID (auto-created on first contact)
    // $bot     — TGBot\Bot instance

    switch ( $message->type ) {
        case 'voice':
            $bot->send_message( 'Got a voice! Attachment ID: ' . $message->files[0] );
            break;
        case 'text':
            $bot->send_message( 'Got text: ' . esc_html( $message->text ) );
            break;
        case 'callback_query':
            $bot->answer_callback_query( $message->callback_query->id );
            $bot->run_command( $message->text ); // dispatch the button command
            break;
    }
}, 10, 3 );
```

---

### `tgbot_bot_call`

Fires for **every incoming update**, including commands. Useful for logging, rate limiting, or cross-cutting concerns.

```php
add_action( 'tgbot_bot_call', function ( $bot ) {
    // $bot->chat_id           — Telegram chat ID
    // $bot->request_respond   — raw Telegram update object
}, 10, 1 );
```

---

### `tgbot_handle_custom_bot_commands`

Fires when a slash command is detected, **before** the built-in dispatcher. Use to override or extend command handling.

```php
add_action( 'tgbot_handle_custom_bot_commands', function ( $bot, $user_id, $command ) {
    // $command — e.g. '/start' or 'start'
    if ( $command === '/secret' ) {
        $bot->send_message( 'Shh! 🤫' );
    }
}, 10, 3 );
```

---

### `tgbot_raw_message`

Fires with the **raw Telegram update object** for advanced use cases (before normalization).

```php
add_action( 'tgbot_raw_message', function ( $bot, $user_id, $update ) {
    // $update — stdClass, direct from Telegram API
    if ( isset( $update->edited_message ) ) {
        $bot->send_message( 'You edited a message!' );
    }
}, 10, 3 );
```

---

### `tgbot_pre_checkout_query`

Fires when a Telegram Stars pre-checkout query arrives.

```php
add_action( 'tgbot_pre_checkout_query', function ( $bot, $query, $user_id ) {
    // Always answer — Telegram requires a response within 10 seconds
    $bot->answer_pre_checkout_query( $query->id, true );
}, 10, 3 );
```

---

### `tgbot_successful_payment`

Fires after a successful Telegram Stars payment.

```php
add_action( 'tgbot_successful_payment', function ( $bot, $payment, $user_id ) {
    // $payment->telegram_payment_charge_id — store this for refunds
    // $payment->total_amount               — amount in Stars
    // $payment->invoice_payload            — your custom payload string

    update_user_meta( $user_id, 'stars_charge_id', $payment->telegram_payment_charge_id );
    $bot->send_message( '✅ Payment received! Stars: ' . $payment->total_amount );
}, 10, 3 );
```

---

### Deprecated hook

| Old hook | Replacement | Notes |
|---|---|---|
| `tgbot_process_multimedia_message` | `tgbot_message` | Kept as alias — signature identical |
| `tgbot_process_message` | `tgbot_raw_message` | Fires with raw update |

---

## Message Object

The `$message` parameter in `tgbot_message` is a normalized `stdClass`:

| Property | Type | Description |
|---|---|---|
| `type` | `string` | `'text'` · `'image'` · `'voice'` · `'video'` · `'audio'` · `'document'` · `'sticker'` · `'video_note'` · `'callback_query'` |
| `text` | `string` | Message text, caption, or `callback_query.data` |
| `files` | `int[]` | WordPress attachment IDs of downloaded files (one per update) |
| `has_media_group` | `bool` | `true` when this update is part of a multi-file album |
| `media_group_id` | `string` | Telegram `media_group_id`, or `''` if not part of a group |
| `callback_query` | `object\|null` | Full Telegram `callback_query` object (only when `type = 'callback_query'`) |

### Media groups

When a user sends multiple files at once, Telegram delivers each file as a **separate update** with the same `media_group_id`. Process each file individually, or group them yourself:

```php
add_action( 'tgbot_message', function ( $bot, $user_id, $message ) {
    if ( $message->has_media_group ) {
        $group_id = $message->media_group_id;
        // Buffer attachment IDs by group, flush when the group is complete
        $existing = get_transient( 'tg_group_' . $group_id ) ?: [];
        $existing = array_merge( $existing, $message->files );
        set_transient( 'tg_group_' . $group_id, $existing, 5 );
    }
}, 10, 3 );
```

---

## BotApi Methods

All methods are available on the `$bot` instance passed to hooks and command callbacks.

### Sending messages

| Method | Description |
|---|---|
| `send_message( $text, $chat_id?, $reply_markup?, $reply_to_message_id? )` | Send HTML text message; pass `$reply_to_message_id` to reply to a specific message (Bot API 7.0+) |
| `send_plain_message( $text, $chat_id? )` | Send plain text message (no parse_mode) |
| `send_markdown_message( $text, $chat_id?, $reply_markup? )` | Send MarkdownV2 message |
| `send_chat_action( $action, $chat_id? )` | Show typing/upload indicator. Actions: `typing`, `upload_photo`, `record_voice`, `upload_voice`, `upload_document`, `find_location` |

### Sending media

| Method | Description |
|---|---|
| `send_photo( $path, $caption?, $chat_id? )` | Send image from local path |
| `send_document( $path, $caption?, $chat_id? )` | Send file from local path |
| `send_audio( $path, $caption?, $chat_id? )` | Send audio file |
| `send_voice( $path, $caption?, $chat_id? )` | Send voice message (OGG/Opus) |
| `send_video( $path, $caption?, $chat_id? )` | Send video file |
| `send_animation( $path, $caption?, $chat_id? )` | Send GIF or silent MP4 |
| `send_location( $lat, $lng, $chat_id? )` | Send geographic location |

### Managing messages

| Method | Description |
|---|---|
| `edit_message( $id, $text, $markup?, $parse_mode? )` | Edit message text |
| `edit_message_markup( $id, $reply_markup )` | Edit inline keyboard only |
| `delete_message( $id )` | Delete a single message |
| `delete_messages( $ids[], $chat_id? )` | Delete up to 100 messages at once |
| `forward_message( $from_chat, $id, $chat_id? )` | Forward a message |
| `copy_message( $from_chat, $id, $caption?, $chat_id? )` | Copy without "Forwarded" header |

### Buttons and queries

| Method | Description |
|---|---|
| `answer_callback_query( $id, $text?, $show_alert? )` | Acknowledge inline button tap |
| `run_command( $command )` | Dispatch a registered command programmatically. If `$command` contains a space (e.g. `start payload`), the part after the space is stored in `$bot->command_param` |

### Payments (Telegram Stars)

| Method | Description |
|---|---|
| `send_stars_invoice( $title, $desc, $payload, $amount, $chat_id? )` | Send Stars invoice |
| `answer_pre_checkout_query( $id, $ok, $error? )` | Approve or reject checkout |
| `refund_star_payment( $user_id, $charge_id )` | Refund a Stars payment |

### Bot configuration

| Method | Description |
|---|---|
| `set_my_commands( $commands[], $scope?, $lang? )` | Register commands in Telegram menu |
| `set_webhook( $url )` | Set webhook URL |
| `delete_webhook()` | Remove webhook |
| `get_webhook_info()` | Get current webhook status |
| `get_updates()` | Fetch pending updates (polling) |
| `get_me()` | Fetch bot info (`id`, `username`, etc.); result is cached per-token for 24 h |

### Downloading files

| Method | Description |
|---|---|
| `get_document_url( $message )` | Get download URL for the primary file in a message |
| `get_photo_url( $message )` | Get download URL for the highest-resolution photo |
| `get_last_request_response()` | Get the raw response from the last API call |

### Inline keyboard helper

Pass `reply_markup` as an array to any send method:

```php
$bot->send_message( 'Choose:', $bot->chat_id, [
    'inline_keyboard' => [
        [
            [ 'text' => '✅ Yes', 'callback_data' => 'confirm_yes' ],
            [ 'text' => '❌ No',  'callback_data' => 'confirm_no'  ],
        ],
    ],
] );
```

---

## Server Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.2 |
| PHP | 8.0 (8.1+ recommended) |
| Extension | `curl` (for file uploads) |
| HTTPS | Required for Webhook mode; not needed for Polling |

### WP-Cron

Polling mode uses WP-Cron. If your hosting blocks loopback HTTP requests, add to `wp-config.php`:

```php
define( 'ALTERNATE_WP_CRON', true );
```

Or set up a real system cron:

```bash
*/1 * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null
```

---

## Changelog

See [readme.txt](readme.txt) for full changelog.

### 0.3.2

- **Bugfix:** `update_chat_id()` now uses `intval()` instead of `absint()` — group chat IDs (which are negative) were being stripped of their sign, causing all responses to group chats to fail
- `send_message()`: new optional `$reply_to_message_id` parameter for threaded replies (Bot API 7.0+)
- New `BotApi::get_me()` — fetch bot info with per-token 24 h transient cache
- `run_command()`: command parameters are now parsed — `/start payload` stores `payload` in `$bot->command_param`

### 0.3.1

- **Broadcast API:** `campaign_key` deduplication column; `tgbot_broadcast()` helper for programmatic use from child plugins
- **Audience registry:** `tgbot_audiences` filter for registering named recipient segments; Broadcast UI shows a segment selector
- Locale: added `uk` → `uk_UA` mapping

### 0.3.0

- New **Broadcast** feature: send mass messages to bot users from a dedicated admin page (**Telegram Bot → Broadcast**)
- Per-locale message composition — separate texts for each language in your user base
- Format selector: Plain, HTML, or MarkdownV2
- Cron-batched delivery (200 messages/batch, ~20 msg/sec) — safe for large user bases
- Real-time progress bar with sent/failed counts and estimated completion time
- Broadcast history on the admin page; per-user history on the user profile
- New top-level **Telegram Bot** admin menu with Settings and Broadcast subpages
- New `BotApi::send_plain_message()` — send a message without any parse_mode
