<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register plugin settings and add the options page.
 *
 * Options are stored as a single array under option_name 'tgbot_options':
 *   gen_tg_token    — Telegram bot token
 *   gen_tg_endpoint — webhook URL path (relative)
 */

if (!function_exists('tgbot_get_option')) {
    function tgbot_get_option(string $key): mixed {
        $options = get_option('tgbot_options');

        return is_array($options) ? ($options[$key] ?? null) : null;
    }
}

add_action('admin_menu', 'tgbot_add_options_page');
add_action('admin_init', 'tgbot_register_settings');
add_action('update_option_tgbot_options', 'tgbot_options_save', 10, 2);
add_action('add_option_tgbot_options', function( $option, $value ) { tgbot_options_save( [], $value ); }, 10, 2);
add_action('wp_ajax_tgbot_webhook_action', 'tgbot_ajax_webhook_action');

function tgbot_add_options_page(): void {
    add_submenu_page(
            'options-general.php',
            __('Telegram Integration Options', 'tg-bot'),
            __('Telegram settings', 'tg-bot'),
            'manage_options',
            'tgbot_options-options',
            'tgbot_options_page_output'
    );
}

function tgbot_register_settings(): void {
    register_setting(
            'tgbot_options_group',
            'tgbot_options',
            ['sanitize_callback' => 'tgbot_sanitize_options']
    );

    // Section: Bot status
    add_settings_section(
            'tgbot_section_status',
            __('Bot status', 'tg-bot'),
            '__return_false',
            'tgbot_options_page'
    );

    add_settings_field(
            'gen_tg_enabled',
            __('Enable bot', 'tg-bot'),
            'tgbot_field_enabled',
            'tgbot_options_page',
            'tgbot_section_status'
    );

    // Section: Telegram
    add_settings_section(
            'tgbot_section_telegram',
            __('Telegram options', 'tg-bot'),
            '__return_false',
            'tgbot_options_page'
    );

    add_settings_field(
            'gen_tg_token',
            __('Telegram Token', 'tg-bot'),
            'tgbot_field_token',
            'tgbot_options_page',
            'tgbot_section_telegram',
            ['key' => 'gen_tg_token']
    );

    add_settings_field(
            'gen_tg_endpoint',
            __('Telegram endpoint', 'tg-bot'),
            function ($args) {
                echo '<span id="tgbot-endpoint-marker"></span>';
                tgbot_field_text($args);
            },
            'tgbot_options_page',
            'tgbot_section_telegram',
            ['key' => 'gen_tg_endpoint']
    );

    // Section: Connection mode
    add_settings_section(
            'tgbot_section_mode',
            __('Connection mode', 'tg-bot'),
            '__return_false',
            'tgbot_options_page'
    );

    add_settings_field(
            'gen_tg_mode',
            __('Mode', 'tg-bot'),
            'tgbot_field_mode',
            'tgbot_options_page',
            'tgbot_section_mode'
    );

    add_settings_field(
            'gen_tg_polling_interval',
            __('Polling interval (sec)', 'tg-bot'),
            'tgbot_field_polling_interval',
            'tgbot_options_page',
            'tgbot_section_mode'
    );

    // Section: Webhook — marker span between h2 and table, used by JS toggle
    add_settings_section(
            'tgbot_section_webhook',
            __('Webhook', 'tg-bot'),
            function () {
                echo '<span id="tgbot-webhook-section-marker"></span>';
            },
            'tgbot_options_page'
    );

    add_settings_field(
            'tgbot_webhook_panel',
            __('Webhook status', 'tg-bot'),
            'tgbot_field_webhook_panel',
            'tgbot_options_page',
            'tgbot_section_webhook'
    );
}

function tgbot_options_page_output(): void {
    $has_token = ! empty( tgbot_get_option( 'gen_tg_token' ) );
    ?>
    <div class="wrap">
        <h2><?php echo esc_html( get_admin_page_title() ); ?></h2>

        <?php if ( ! $has_token ) : ?>
        <div class="notice notice-info tgbot-quickstart">
            <h3 style="margin:.5em 0 .4em;"><?php esc_html_e( '👋 Quick start', 'tg-bot' ); ?></h3>
            <ol style="margin:.3em 0 .6em 1.4em;line-height:1.7;">
                <li><?php printf(
                    /* translators: %s: link to BotFather */
                    esc_html__( 'Create a bot and copy its token from %s.', 'tg-bot' ),
                    '<a href="https://t.me/BotFather" target="_blank">@BotFather</a>'
                ); ?></li>
                <li><?php esc_html_e( 'Paste the token into the "Telegram Token" field below and click Save.', 'tg-bot' ); ?></li>
                <li><?php esc_html_e( 'Choose a connection mode:', 'tg-bot' ); ?>
                    <ul style="margin:.2em 0 0 1.2em;list-style:disc;">
                        <li><?php esc_html_e( 'Webhook — requires a public HTTPS URL. After saving, click Set Webhook.', 'tg-bot' ); ?></li>
                        <li><?php esc_html_e( 'Polling — works on any hosting including localhost. Starts automatically on Save.', 'tg-bot' ); ?></li>
                    </ul>
                </li>
            </ol>
        </div>
        <?php endif; ?>

        <form action="options.php" method="POST" class="wpt-form">
            <?php
            settings_fields('tgbot_options_group');
            do_settings_sections('tgbot_options_page');
            submit_button(__('Save', 'tg-bot'));
            ?>
        </form>
    </div>
    <?php
}

function tgbot_field_text(array $args): void {
    $val = tgbot_get_option($args['key']);
    printf(
            '<input class="tgbot_options" type="text" name="tgbot_options[%s]" value="%s" />',
            esc_attr($args['key']),
            esc_attr($val ?? '')
    );
}

function tgbot_field_token(array $args): void {
    $val = tgbot_get_option($args['key']);
    $id = 'tgbot_token_field';
    ?>
    <div class="tgbot-token-wrap">
        <input
                id="<?php echo esc_attr($id); ?>"
                class="tgbot_options tgbot-token-input"
                type="password"
                name="tgbot_options[<?php echo esc_attr($args['key']); ?>]"
                value="<?php echo esc_attr($val ?? ''); ?>"
                autocomplete="new-password"
        />
        <button type="button" class="button tgbot-token-toggle" data-target="<?php echo esc_attr($id); ?>"
                aria-label="<?php esc_attr_e('Show/hide token', 'tg-bot'); ?>">
            <span class="dashicons dashicons-visibility"></span>
        </button>
    </div>
    <?php
}

function tgbot_field_webhook_panel(): void {
    ?>
    <div class="tgbot-webhook-panel">
        <div class="tgbot-webhook-status" id="tgbot-webhook-status">
            <span class="tgbot-webhook-spinner spinner is-active"></span>
        </div>
        <div class="tgbot-webhook-actions">
            <button type="button" class="button button-primary" id="tgbot-set-webhook">
                <?php esc_html_e('Set Webhook', 'tg-bot'); ?>
            </button>
            <button type="button" class="button" id="tgbot-check-webhook">
                <?php esc_html_e('Check Status', 'tg-bot'); ?>
            </button>
            <button type="button" class="button tgbot-delete-webhook" id="tgbot-delete-webhook">
                <?php esc_html_e('Delete Webhook', 'tg-bot'); ?>
            </button>
        </div>
    </div>
    <?php
}

function tgbot_field_mode(): void {
    $val = tgbot_get_option('gen_tg_mode') ?: 'webhook';
    ?>
    <fieldset>
        <label>
            <input type="radio" name="tgbot_options[gen_tg_mode]" value="webhook" <?php checked($val, 'webhook'); ?> />
            <?php esc_html_e('Webhook', 'tg-bot'); ?>
        </label>
        &nbsp;&nbsp;
        <label>
            <input type="radio" name="tgbot_options[gen_tg_mode]" value="polling" <?php checked($val, 'polling'); ?> />
            <?php esc_html_e('Polling (getUpdates)', 'tg-bot'); ?>
        </label>
    </fieldset>
    <p class="description"><?php esc_html_e('Webhook requires a public HTTPS URL. Polling can work on localhost.', 'tg-bot'); ?></p>
    <?php
}

function tgbot_field_polling_interval(): void {
    $val = (int)(tgbot_get_option('gen_tg_polling_interval') ?: 30);
    echo '<span id="tgbot-polling-interval-marker"></span>';
    printf(
            '<input class="small-text" type="number" min="5" max="3600" name="tgbot_options[gen_tg_polling_interval]" value="%d" /> %s',
            (int) $val,
            esc_html__( 'sec', 'tg-bot' )
    );
    echo '<p class="description">' . esc_html__('Minimum: 5 sec. Requires WP-Cron or system cron.', 'tg-bot') . '</p>';
}

function tgbot_field_checkbox(array $args): void {
    $val = tgbot_get_option($args['key']);
    printf(
            '<input class="tgbot_options-input" type="checkbox" name="tgbot_options[%s]" %s />',
            esc_attr($args['key']),
            checked('on', $val, false)
    );
}

function tgbot_field_enabled(): void {
    $enabled = (bool) ( tgbot_get_option( 'gen_tg_enabled' ) ?? true );
    $id      = 'tgbot_enabled_toggle';
    ?>
    <label class="tgbot-toggle" for="<?php echo esc_attr( $id ); ?>">
        <input type="hidden"   name="tgbot_options[gen_tg_enabled]" value="0" />
        <input type="checkbox" name="tgbot_options[gen_tg_enabled]" value="1"
               id="<?php echo esc_attr( $id ); ?>"
               <?php checked( $enabled ); ?> />
        <span class="tgbot-toggle__track"></span>
        <span class="tgbot-toggle__label">
            <?php echo $enabled ? esc_html__( 'Active', 'tg-bot' ) : esc_html__( 'Disabled', 'tg-bot' ); ?>
        </span>
    </label>
    <?php
}

function tgbot_sanitize_options($input): array {
    $clean = [];

    $clean['gen_tg_enabled'] = ! empty( $input['gen_tg_enabled'] );

    if (isset($input['gen_tg_token'])) {
        $clean['gen_tg_token'] = sanitize_text_field($input['gen_tg_token']);
    }

    if (isset($input['gen_tg_endpoint'])) {
        $clean['gen_tg_endpoint'] = sanitize_text_field($input['gen_tg_endpoint']);
    }

    $clean['gen_tg_mode'] = in_array($input['gen_tg_mode'] ?? '', ['webhook', 'polling'], true)
            ? $input['gen_tg_mode']
            : 'webhook';

    $clean['gen_tg_polling_interval'] = max(5, min(3600, (int)($input['gen_tg_polling_interval'] ?? 30)));

    // Reschedule on every Save when mode=polling — sanitize_callback always fires,
    // unlike update_option hooks which are skipped when value is unchanged.
    $old      = get_option( 'tgbot_options', [] );
    $new_mode = $clean['gen_tg_mode'];
    $interval = $clean['gen_tg_polling_interval'];
    $old_mode = $old['gen_tg_mode'] ?? 'webhook';

    if ( ! $clean['gen_tg_enabled'] ) {
        // Bot disabled — stop everything.
        \TGBot\Polling::unschedule();
    } elseif ( $new_mode === 'polling' || $new_mode !== $old_mode ) {
        \TGBot\Polling::reschedule( $new_mode, $interval );
    }

    return $clean;
}

function tgbot_options_save($old_value, $new_value): void {
    $endpoint     = $new_value['gen_tg_endpoint'] ?? false;
    $old_endpoint = $old_value['gen_tg_endpoint'] ?? false;

    if ( ! empty( $endpoint ) && $old_endpoint !== $endpoint ) {
        \TGBot\Init::custom_rewrite_rule();
        flush_rewrite_rules();
    }
}

/**
 * AJAX handler for webhook actions: check / set / delete
 */
function tgbot_ajax_webhook_action(): void {
    check_ajax_referer('tgbot_admin', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Forbidden', 'tg-bot')], 403);
    }

    $action = sanitize_text_field( wp_unslash( $_POST['webhook_action'] ?? '' ) );
    $token = tgbot_get_option('gen_tg_token');

    if (!$token) {
        wp_send_json_error(['message' => __('Telegram token is not configured.', 'tg-bot')]);
    }

    $bot = new \TGBot\BotApi( $token, false );

    switch ($action) {
        case 'check':
            $result = $bot->get_webhook_info();
            break;

        case 'set':
            $endpoint = tgbot_get_option('gen_tg_endpoint');
            if (!$endpoint) {
                wp_send_json_error(['message' => __('Telegram endpoint is not configured.', 'tg-bot')]);
            }
            $result = $bot->set_webhook(get_home_url(null, $endpoint));
            break;

        case 'delete':
            $result = $bot->delete_webhook();
            break;

        default:
            wp_send_json_error(['message' => 'Unknown action.']);
    }

    if (empty($result) || !isset($result->ok)) {
        wp_send_json_error(['message' => __('No response from Telegram API.', 'tg-bot')]);
    }

    if ($result->ok) {
        wp_send_json_success((array)($result->result ?? []));
    } else {
        wp_send_json_error(['message' => $result->description ?? __('Telegram API error.', 'tg-bot')]);
    }
}
