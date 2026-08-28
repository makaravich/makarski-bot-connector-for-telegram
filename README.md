# Telegram Messenger Integration

WordPress plugin that connects your site to a Telegram bot. Handles all Telegram Bot API communication so you can focus on your bot's logic using familiar WordPress hooks and filters.

**Version:** 0.3.5 · **Requires:** WordPress 6.2+, PHP 8.0+ · **License:** GPLv2

**What you get:**

- **Webhook & polling** connection modes — works on any hosting, including localhost
- **Command routing** and a normalized `tgbot_message` hook for every message type
- **30+ BotApi methods** — messages, media, inline keyboards, Stars payments, groups
- **Auto-split** of messages over Telegram's 4096-unit limit (HTML tags kept valid per chunk)
- **Broadcast** — mass messages from wp-admin with per-locale texts, batching, progress, history
- **Analytics** — a tabbed admin page: overview, commands, delivery/blocks, sources, languages, referrals
- **Privacy** — auto-created bot users are hidden from public site surfaces
- **Send gating** — the Enable-bot toggle silences outgoing traffic too (`tgbot_can_send()`)
- **Documentation** right in wp-admin (**Telegram Bot → Documentation**)

---

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Connection Modes](#connection-modes)
- [Broadcast](#broadcast)
- [Analytics](#analytics)
- [Privacy](#privacy)
- [Registering Commands](#registering-commands)
- [Action Hooks](#action-hooks)
- [Filters](#filters)
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

The **Telegram Bot** admin menu then contains **Settings**, **Broadcast**, **Analytics**, and **Documentation** (this document, rendered in wp-admin).

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

### Programmatic API (for child plugins)

```php
// Create a broadcast job from code. $campaign_key deduplicates recurring campaigns.
$job_id = TGBot\create_broadcast(
    [ 'en_US' => 'Hello!', 'ru_RU' => 'Привет!' ], // locale-keyed messages
    $user_ids,                                     // WP user IDs
    'html',                                        // 'plain' | 'html' | 'markdown'
    'spring_promo'                                 // optional campaign key
);

// Has this user already received the campaign?
TGBot\user_received_campaign( $user_id, 'spring_promo' );

// Resolve a named audience (registered via the 'tgbot_audiences' filter) to user IDs.
$user_ids = TGBot\resolve_audience( 'premium' );

// Register your own audience segment — it also appears in the Broadcast UI selector.
add_filter( 'tgbot_audiences', function ( $audiences ) {
    $audiences['premium'] = [
        'label'    => 'Premium users',
        'callback' => fn() => get_users( [ 'meta_key' => 'is_premium', 'fields' => 'ID' ] ),
    ];
    return $audiences;
} );
```

---

## Analytics

The **Analytics** page (**Telegram Bot → Analytics**) gives every bot built on the connector basic product analytics with zero code:

| Tab | Shows |
|---|---|
| **Overview** | Total chats, groups, new today / 7 days / 30 days, active (7 days), blocked |
| **Commands** | Command usage over the last 30 days (uses + unique chats) |
| **Delivery** | Chats that blocked the bot; recent failed/suppressed sends |
| **Sources** | First-touch acquisition from `t.me/<bot>?start=<code>` deep links |
| **Languages** | Raw Telegram `language_code` each chat arrived with — demand for languages you may not support yet |
| **Referrals** | Referrals recorded from `?start=ref_<code>` links, top referrers |

Everything is recorded automatically inside the connector: registration, incoming activity (`last_seen`, unblock detection), dispatched commands, failed sends (a permanent Telegram refusal — *blocked by the user*, *user is deactivated*, *chat not found* — stamps `blocked_at`), sources, languages, and referrals. Successful sends are **not** logged. Times are stored in UTC.

### Related hooks

```php
// A WP user was just created for a new Telegram chat.
add_action( 'tgbot_user_registered', function ( $wp_user_id, $chat_id, $from ) {
    // $from — Telegram 'from' object (may be null)
}, 10, 3 );

// Someone registered via a ?start=ref_<code> link. Grant your reward here.
add_action( 'tgbot_referral_completed', function ( $referrer_chat_id, $referred_chat_id, $referrer_wp_user_id ) {
    // e.g. credit the referrer
}, 10, 3 );
```

`TGBot\Analytics::referral_code( $chat_id )` returns (lazily creating) the chat's code for building `https://t.me/<bot>?start=ref_<code>` links.

### Adding your own tabs

Consumer plugins can add tabs next to the built-in ones via the `tgbot_analytics_tabs` filter, and should build them from the public rendering helpers so every tab looks consistent:

```php
add_filter( 'tgbot_analytics_tabs', function ( $tabs ) {
    $tabs['funnel'] = [
        'label'  => __( 'Funnel', 'my-bot' ),
        'render' => function () {
            \TGBot\AdminAnalytics::cards( [
                'Arrived' => 276,
                'Engaged' => 91,
                'Paid'    => 7,
            ] );

            \TGBot\AdminAnalytics::table(
                [ 'Step', 'Users', '%' ],
                [
                    [ 'arrived', 276, '100%' ],
                    [ 'engaged',  91,  '33%' ],
                    [ 'paid',      7,   '3%' ],
                ]
            );
        },
    ];

    return $tabs;
} );
```

Helper contract: `cards( array $label_to_value )` renders a row of stat cards; `card( $label, $value )` renders one; `table( array $headers, array $rows, $max_width = '760px' )` renders a striped admin table. All content is escaped by the helpers — pass plain strings, not markup. Malformed tab entries (missing `label`/`render`, non-callable `render`) are silently dropped, so a broken consumer filter can't take the page down.

---

## Privacy

The connector creates a WordPress user for every Telegram chat, with the chat_id as the login. Left alone, WordPress would publish an **author archive** for each of them — `/author/<chat_id>/` answering 200 — letting anyone confirm from outside whether a given Telegram account has contacted your bot.

The connector closes this by default, **selectively — only for its own auto-created users** (real human author pages keep working):

- the author archive of a bot user (both `/author/<chat_id>/` and `?author=<ID>`) returns a genuine **404**, with no canonical redirect leaking the login first
- bot users are excluded from the **core users sitemap** and the public **REST users collection** (`/wp/v2/users`), including single-user reads
- bot users are recognized by a marker meta stamped at creation; accounts created by older plugin versions are marked automatically on upgrade

Opt out (e.g. if your bot users are intentionally public):

```php
add_filter( 'tgbot_protect_bot_users', '__return_false' );
```

Related: while the **Enable bot** toggle is off, all user-facing API calls are suppressed and return `ok = false` with `tgbot_disabled = true` — so a dev copy restored from a production database can't accidentally message real people. Administrative calls (webhook management, `get_me()`, refunds) keep working. Check `tgbot_can_send()` in your own sending paths.

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

### `tgbot_my_chat_member`

Fires when the bot is **added to or removed from a group** (Telegram `my_chat_member` update).

```php
add_action( 'tgbot_my_chat_member', function ( $bot, $group_wp_user_id, $mch ) {
    // $bot              — TGBot\Bot instance (chat_id = group chat_id)
    // $group_wp_user_id — WP user ID of the auto-created group account
    // $mch              — my_chat_member object from Telegram:
    //   $mch->chat                    — group info (id, title, type)
    //   $mch->from                    — who triggered the change (Telegram user)
    //   $mch->old_chat_member->status
    //   $mch->new_chat_member->status — 'member' = bot added, 'kicked'/'left' = removed

    if ( ( $mch->new_chat_member->status ?? '' ) === 'member' ) {
        $bot->send_message( 'Thanks for adding me to ' . esc_html( $mch->chat->title ) . '!' );
    }
}, 10, 3 );
```

Combine with `get_chat_member()` to verify that the person who added the bot is a group administrator.

---

### `tgbot_user_registered` / `tgbot_referral_completed`

Fire when a WP user is created for a new chat, and when someone registers via a `?start=ref_<code>` link — see [Analytics → Related hooks](#related-hooks) for signatures and examples.

---

### Deprecated hook

| Old hook | Replacement | Notes |
|---|---|---|
| `tgbot_process_multimedia_message` | `tgbot_message` | Kept as alias — signature identical |
| `tgbot_process_message` | `tgbot_raw_message` | Fires with raw update |

---

## Filters

| Filter | Purpose |
|---|---|
| `tgbot_help_message` | Change the text sent on `/start` and `/help`. Evaluated lazily at send time, in the current request locale |
| `tgbot_can_send` | Override the outgoing-send gate (default: the **Enable bot** toggle). Return `true` to force sending, `false` to silence the bot |
| `tgbot_protect_bot_users` | Return `false` to disable hiding bot users from public surfaces — see [Privacy](#privacy) |
| `tgbot_audiences` | Register named recipient segments for Broadcast — see [Broadcast → Programmatic API](#programmatic-api-for-child-plugins) |
| `tgbot_analytics_tabs` | Add your own tabs to the Analytics page — see [Analytics → Adding your own tabs](#adding-your-own-tabs) |
| `tgbot_register_bot_command` | Intercept command registration: receives `($callback, $command)`; return a modified callback, or a falsy value to drop the command |

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
| `send_message( $text, $chat_id?, $reply_markup?, $reply_to_message_id? )` | Send HTML text message; pass `$reply_to_message_id` to reply to a specific message (Bot API 7.0+). Text over the Telegram limit (4096 UTF-16 units) is auto-split into several messages at paragraph/line/word boundaries, HTML tags are closed/re-opened per chunk, `$reply_markup` goes on the last chunk |
| `send_plain_message( $text, $chat_id? )` | Send plain text message (no parse_mode); over-limit text is auto-split |
| `send_markdown_message( $text, $chat_id?, $reply_markup? )` | Send MarkdownV2 message; over-limit text is auto-split (`$reply_markup` on the last chunk) |
| `send_chat_action( $action, $chat_id? )` | Show typing/upload indicator. Actions: `typing`, `upload_photo`, `record_voice`, `upload_voice`, `upload_document`, `find_location` |

### Sending media

| Method | Description |
|---|---|
| `send_photo( $path, $caption?, $chat_id?, $reply_markup? )` | Send image from local path; pass `$reply_markup` to attach inline buttons |
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
| `get_chat_member( $chat_id, $user_id )` | Get chat member info (`status`: `creator` · `administrator` · `member` · `restricted` · `left` · `kicked`); returns `null` on failure |

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

### 0.3.5

- New **Analytics** admin page (**Telegram Bot → Analytics**) with tabs: Overview, Commands, Delivery (blocked chats + failed sends), Sources (first-touch `?start=` attribution), Languages (arrival `language_code`), Referrals
- New analytics registry and event tables (`tgbot_users`, `tgbot_commands`, `tgbot_deliveries`, `tgbot_referrals`), filled automatically; existing bot users back-filled on upgrade
- Blocked-chat detection: a permanent Telegram refusal stamps `blocked_at`; the next incoming update clears it
- Built-in referral tracking via `?start=ref_<code>` links: `Analytics::referral_code()`, rewards via the `tgbot_referral_completed` action
- New `tgbot_user_registered` action — fires when a WP user is created for a new chat
- **Tab API** for consumer plugins: `tgbot_analytics_tabs` filter + public rendering helpers `AdminAnalytics::card()` / `cards()` / `table()`
- New **Documentation** admin page (**Telegram Bot → Documentation**) — this README rendered in wp-admin, with a GitHub link

### 0.3.4

- **Privacy:** connector-created bot users are hidden from public surfaces — author archive (`/author/<chat_id>/`, `?author=<ID>`) returns a real 404, bot users are excluded from the users sitemap and the public REST users collection; human authors unaffected; disable with `add_filter( 'tgbot_protect_bot_users', '__return_false' )`
- `send_message()` / `send_plain_message()` / `send_markdown_message()`: text over 4096 UTF-16 units is auto-split into several messages (HTML tags balanced per chunk, `reply_markup` on the last one)
- Broadcast admin UI: HTML format no longer stripped to plain text (`wp_kses` with the Telegram tag whitelist)
- Fix: empty Date in user-profile Broadcast History for pending/failed rows
- "Enable bot" now gates outgoing messages too — while disabled, user-facing API calls return `ok=false` with `tgbot_disabled=true` (admin calls keep working); new `tgbot_can_send()` helper for child plugins (filter: `tgbot_can_send`)

### 0.3.3

- New `BotApi::get_chat_member()` — chat member info with `status` (creator / administrator / member / restricted / left / kicked), `null` on failure
- `update_chat_id()` falls back to `my_chat_member->chat->id` — bot-added-to-group updates are no longer dropped
- New `tgbot_my_chat_member` action hook — fires when the bot is added to or removed from a group
- `send_photo()`: new optional `$reply_markup` parameter — image + caption + inline buttons in one message
- Settings: webhook endpoint normalized on save — a trailing slash used to silently break the rewrite rule (webhook 404)
- Fix: empty endpoint no longer registers a rewrite rule that hijacks the homepage

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
