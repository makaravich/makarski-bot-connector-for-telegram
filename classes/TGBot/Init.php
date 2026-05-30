<?php

namespace TGBot;

class Init {
    public array $bot_map = [];

    public function __construct() {
        $this->bot_map = [
                'auto_exec' => false,
                'help_message' => self::get_help_message(),
        ];

        // Add styles and scripts on admin side
        add_action('admin_enqueue_scripts', [$this, 'add_admin_assets']);

        // Call "TG page"
        add_action('init', [$this, 'custom_rewrite_rule']);
        add_filter('query_vars', [$this, 'custom_query_vars']);
        add_action('template_redirect', [$this, 'custom_action_handler']);

        // Run required functions
        add_action('init', function () {
            \TGBot\ProcessMessages::init();
            \TGBot\Polling::init();
        }, 999);

        // User custom fields
        add_action('personal_options_update', [$this, 'save_tg_nickname_field']);
        add_action('edit_user_profile_update', [$this, 'save_tg_nickname_field']);
        add_action('show_user_profile', [$this, 'add_tg_nickname_field']);
        add_action('edit_user_profile', [$this, 'add_tg_nickname_field']);
        add_filter('manage_users_columns', [$this, 'add_tg_nickname_column']);
        add_filter('manage_users_custom_column', [$this, 'show_tg_nickname_column'], 10, 3);
        add_filter('manage_users_sortable_columns', [$this, 'make_tg_nickname_sortable']);
        add_action('pre_get_users', [$this, 'sort_tg_nickname_column']);
    }

    /**
     * Returns help message for the bot
     *
     * @return string
     */
    public static function get_help_message(): string {
        $default_message = __('This is an awesome Telegram bot! 😉', 'tg-bot');

        return apply_filters('tgbot_help_message', $default_message);
    }

    /**
     * Enqueue styles and scripts on admin side
     *
     * @param $hook
     *
     * @return void
     */
    public function add_admin_assets($hook): void {
        wp_enqueue_style( 'tgbot-admin-style', TGBOT_PLUGIN_BASEURI . '/admin/styles/admin.min.css', [], filemtime( TGBOT_PLUGIN_BASEPATH . '/admin/styles/admin.min.css' ) );

        $js_hooks = [ 'edit.php', 'settings_page_tgbot_options-options' ];
        if ( in_array( $hook, $js_hooks, true ) ) {
            wp_enqueue_script( 'tgbot-admin-script', TGBOT_PLUGIN_BASEURI . '/admin/js/admin.js', [ 'jquery' ], filemtime( TGBOT_PLUGIN_BASEPATH . '/admin/js/admin.js' ), true );
            wp_localize_script( 'tgbot-admin-script', 'tgbotAdmin', [
                'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'tgbot_admin' ),
                'siteUrl'  => get_home_url(),
                'endpoint' => tgbot_get_option( 'gen_tg_endpoint' ) ?? '',
            ] );
        }
    }

    /**
     * Define TG Page
     *
     * @return void
     */
    public static function custom_rewrite_rule(): void {
        $regex = '^' . tgbot_get_option( 'gen_tg_endpoint' ) . '/?$';

        add_rewrite_rule($regex, 'index.php?tgbot_action=tg_call', 'top');
    }


    /**
     * Define TG parameter
     *
     * @param $query_vars
     *
     * @return mixed
     */
    function custom_query_vars($query_vars): mixed {
        $query_vars[] = 'tgbot_action';

        return $query_vars;
    }

    public static function finish_request(): void {
        ob_start();
        echo 'OK';
        $size = ob_get_length();

        header( 'Content-Encoding: none' );
        header( 'Content-Length: ' . $size );
        header( 'Connection: close' );
        ignore_user_abort( true );

        ob_end_flush();
        flush();

        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        }
    }

    function custom_action_handler(): void {
        $custom_action = get_query_var('tgbot_action');

        if ($custom_action === 'tg_call') {
            self::finish_request();

            $bot = new Bot( tgbot_get_option( 'gen_tg_token' ), true, $this->bot_map );

            $request_respond = $bot->get_request();

            do_action('tgbot_bot_call', $bot);

            if (!empty($request_respond->pre_checkout_query)) {
                // It is possible to handle pre-checkout query here
                $user_id = $request_respond->pre_checkout_query->from->id;
                do_action('tgbot_pre_checkout_query', $bot, $request_respond->pre_checkout_query, $user_id);
            }

            if (!empty($request_respond->message->successful_payment)) {
                $chat_id = $request_respond->message->chat->id ?? 0;
                $user_id = (int) ProcessMessages::get_user_by_chat_id($chat_id);
                do_action('tgbot_successful_payment', $bot, $request_respond->message->successful_payment, $user_id);
            }

            exit; // Terminate execution to avoid loading theme
        }
    }

    /**
     * Save user custom fields
     *
     * @param $user_id
     *
     * @return false|void
     */
    function save_tg_nickname_field($user_id) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return false;
        }
        if ( ! isset( $_POST['tgbot_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tgbot_nonce'] ) ), 'tgbot_save_user_fields' ) ) {
            return false;
        }
        $nickname = isset( $_POST['tg_nickname'] ) ? sanitize_text_field( wp_unslash( $_POST['tg_nickname'] ) ) : '';
        update_user_meta( $user_id, 'tg_nickname', $nickname );
    }

    /**
     * Add custom fields into user profile
     *
     * @param $user
     *
     * @return void
     */
    function add_tg_nickname_field($user): void {
        ?>
        <h3>Telegram</h3>
        <table class="form-table">
            <tr>
                <th><label for="tg_nickname">Telegram Nickname</label></th>
                <td>
                    <input type="text" name="tg_nickname" id="tg_nickname"
                           value="<?php echo esc_attr(get_the_author_meta('tg_nickname', $user->ID)); ?>"
                           class="regular-text"/>
                    <?php wp_nonce_field( 'tgbot_save_user_fields', 'tgbot_nonce' ); ?>
                    <p class="description"><?php esc_html_e( 'Enter your nick in Telegram (without @)', 'tg-bot' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Custom column for users table
     *
     * @param $columns
     *
     * @return mixed
     */
    function add_tg_nickname_column($columns): mixed {
        $columns['tg_nickname'] = __('Telegram Nickname', 'tg-bot');

        return $columns;
    }

    /**
     * Content for the column
     *
     * @param $value
     * @param $column_name
     * @param $user_id
     *
     * @return mixed|string
     */
    function show_tg_nickname_column($value, $column_name, $user_id): mixed {
        if ($column_name == 'tg_nickname') {
            $tg_nickname = get_user_meta($user_id, 'tg_nickname', true);

            return $tg_nickname ? esc_html($tg_nickname) : '—';
        }

        return $value;
    }

    /**
     * Make the column sortable
     *
     * @param $columns
     *
     * @return mixed
     */
    function make_tg_nickname_sortable($columns): mixed {
        $columns['tg_nickname'] = 'tg_nickname';

        return $columns;
    }

    /**
     * Perform the sorting
     *
     * @param $query
     *
     * @return void
     */
    function sort_tg_nickname_column($query): void {
        if (!is_admin()) {
            return;
        }

        $orderby = $query->get('orderby');
        if ($orderby == 'tg_nickname') {
            $query->set('meta_key', 'tg_nickname');
            $query->set('orderby', 'meta_value');
        }
    }
}