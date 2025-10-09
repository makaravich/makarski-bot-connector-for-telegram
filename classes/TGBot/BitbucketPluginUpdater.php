<?php

namespace TGBot;

/**
 * Bitbucket Plugin Updater
 *
 * Класс для проверки и установки обновлений WordPress плагина из Bitbucket репозитория
 */
class BitbucketPluginUpdater {

    private $plugin_slug;
    private $plugin_file;
    private $bitbucket_username;
    private $bitbucket_repo;
    private $bitbucket_token;
    private $version;
    private $cache_key;
    private $cache_allowed = true;
    private $enable_logging = true; // Включить/выключить логирование

    /**
     * Конструктор класса
     *
     * @param string $plugin_file Полный путь к главному файлу плагина
     * @param string $bitbucket_username Имя пользователя Bitbucket (workspace slug)
     * @param string $bitbucket_repo Название репозитория
     * @param string $bitbucket_token API token для Bitbucket API (опционально для публичных репо)
     * @param bool $enable_logging Включить логирование (по умолчанию true)
     */
    public function __construct( $plugin_file, $bitbucket_username, $bitbucket_repo, $bitbucket_token = '', $enable_logging = true ) {
        $this->plugin_file        = $plugin_file;
        $this->plugin_slug        = plugin_basename( $plugin_file );
        $this->bitbucket_username = $bitbucket_username;
        $this->bitbucket_repo     = $bitbucket_repo;
        $this->bitbucket_token    = $bitbucket_token;
        $this->version            = $this->get_plugin_version();
        $this->cache_key          = 'bitbucket_updater_' . md5( $this->plugin_slug );
        $this->enable_logging     = $enable_logging;

        $this->log( 'Инициализация BitbucketPluginUpdater', [
            'plugin_slug'        => $this->plugin_slug,
            'current_version'    => $this->version,
            'bitbucket_username' => $bitbucket_username,
            'bitbucket_repo'     => $bitbucket_repo,
            'has_token'          => ! empty( $bitbucket_token ),
            'cache_key'          => $this->cache_key
        ] );

        // Регистрация хуков
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_source_selection', [ $this, 'fix_source_folder' ], 10, 3 );

        $this->log( 'Хуки зарегистрированы успешно' );

        // Добавляем диагностические хуки для проверки
        add_action( 'admin_init', [ $this, 'diagnostic_check' ] );
    }

    /**
     * Диагностическая проверка (выполняется в админке)
     */
    public function diagnostic_check() {
        // Проверяем только раз в час, чтобы не спамить логи
        $diagnostic_key = $this->cache_key . '_diagnostic';
        if ( get_transient( $diagnostic_key ) ) {
            return;
        }
        set_transient( $diagnostic_key, true, HOUR_IN_SECONDS );

        $this->log( '=== ДИАГНОСТИКА ЗАПУЩЕНА ===' );
        $this->log( 'admin_init хук сработал', [
            'is_admin'     => is_admin(),
            'current_user' => get_current_user_id(),
            'current_page' => isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : 'не определена'
        ] );

        // Проверяем, есть ли плагин в списке установленных
        $all_plugins = get_plugins();
        $plugin_exists = isset( $all_plugins[ $this->plugin_slug ] );

        $this->log( 'Статус плагина в системе', [
            'plugin_slug'   => $this->plugin_slug,
            'plugin_exists' => $plugin_exists,
            'is_active'     => is_plugin_active( $this->plugin_slug ),
            'total_plugins' => count( $all_plugins )
        ] );

        // Проверяем transient с обновлениями
        $update_plugins = get_site_transient( 'update_plugins' );
        $this->log( 'Текущий transient update_plugins', [
            'exists'         => $update_plugins !== false,
            'has_response'   => isset( $update_plugins->response ),
            'has_checked'    => isset( $update_plugins->checked ),
            'response_count' => isset( $update_plugins->response ) ? count( (array) $update_plugins->response ) : 0,
            'our_plugin_in_response' => isset( $update_plugins->response[ $this->plugin_slug ] )
        ] );

        // Принудительная проверка обновления
        $this->log( '--- ПРИНУДИТЕЛЬНАЯ ПРОВЕРКА ОБНОВЛЕНИЯ ---' );
        $remote_info = $this->get_remote_info();

        if ( $remote_info ) {
            $this->log( 'Принудительная проверка: удалённая версия получена', $remote_info );
        } else {
            $this->log( 'ОШИБКА принудительной проверки: не удалось получить информацию' );
        }

        $this->log( '=== ДИАГНОСТИКА ЗАВЕРШЕНА ===' );
    }

    /**
     * Логирование сообщений
     *
     * @param string $message Сообщение для логирования
     * @param array $context Дополнительный контекст
     */
    private function log( $message, $context = [] ) {
        if ( ! $this->enable_logging ) {
            return;
        }

        $log_entry = sprintf(
            '[%s] [BitbucketUpdater] %s',
            current_time( 'Y-m-d H:i:s' ),
            $message
        );

        if ( ! empty( $context ) ) {
            $log_entry .= ' | Context: ' . json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        }

        error_log( $log_entry );
    }

    /**
     * Получение текущей версии плагина
     */
    private function get_plugin_version() {
        $this->log( 'Получение версии плагина' );

        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $this->log( 'Подключен файл plugin.php' );
        }

        $plugin_data = get_plugin_data( $this->plugin_file );
        $version     = $plugin_data['Version'];

        $this->log( 'Версия плагина получена', [
            'version'     => $version,
            'plugin_name' => $plugin_data['Name']
        ] );

        return $version;
    }

    /**
     * Получение информации о последней версии из Bitbucket
     */
    private function get_remote_info() {
        $this->log( 'Начало получения информации из Bitbucket' );

        // Проверка кэша
        if ( $this->cache_allowed ) {
            $cached = get_transient( $this->cache_key );
            if ( $cached !== false ) {
                $this->log( 'Информация получена из кэша', [ 'cached_data' => $cached ] );
                return $cached;
            }
            $this->log( 'Кэш пуст, запрашиваем API' );
        }

        // URL для получения тегов из Bitbucket API
        $api_url = sprintf(
            'https://api.bitbucket.org/2.0/repositories/%s/%s/refs/tags?sort=-name',
            $this->bitbucket_username,
            $this->bitbucket_repo
        );

        $this->log( 'Подготовлен URL для API запроса', [ 'api_url' => $api_url ] );

        $args = [
            'headers' => [
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
                'Accept'     => 'application/json'
            ],
            'timeout' => 15
        ];

        // Добавление авторизации через API Token
        if ( ! empty( $this->bitbucket_token ) ) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->bitbucket_token;
            $this->log( 'Добавлен Authorization токен' );
        } else {
            $this->log( 'Запрос без авторизации (публичный репозиторий)' );
        }

        $this->log( 'Отправка запроса к Bitbucket API' );
        $response = wp_remote_get( $api_url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log( 'ОШИБКА: Запрос к API завершился с ошибкой', [
                'error_message' => $response->get_error_message(),
                'error_code'    => $response->get_error_code()
            ] );
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = wp_remote_retrieve_body( $response );

        $this->log( 'Получен ответ от API', [
            'response_code' => $response_code,
            'body_length'   => strlen( $body )
        ] );

        if ( $response_code !== 200 ) {
            $this->log( 'ОШИБКА: Неожиданный код ответа', [
                'response_code' => $response_code,
                'body'          => substr( $body, 0, 500 ) // Первые 500 символов
            ] );
            return false;
        }

        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $this->log( 'ОШИБКА: Не удалось декодировать JSON', [
                'json_error' => json_last_error_msg(),
                'body'       => substr( $body, 0, 500 )
            ] );
            return false;
        }

        $this->log( 'JSON успешно декодирован', [
            'has_values' => isset( $data['values'] ),
            'values_count' => isset( $data['values'] ) ? count( $data['values'] ) : 0
        ] );

        if ( empty( $data['values'] ) ) {
            $this->log( 'ОШИБКА: Массив values пуст или отсутствует', [ 'data_keys' => array_keys( $data ) ] );
            return false;
        }

        // Получение последнего тега
        $latest_tag = $data['values'][0];
        $tag_name   = ltrim( $latest_tag['name'], 'v' ); // Убираем 'v' если есть

        $this->log( 'Получен последний тег', [
            'tag_name'     => $latest_tag['name'],
            'clean_version' => $tag_name,
            'tag_date'     => $latest_tag['date'] ?? 'не указана'
        ] );

        $download_url = sprintf(
            'https://bitbucket.org/%s/%s/get/%s.zip',
            $this->bitbucket_username,
            $this->bitbucket_repo,
            $latest_tag['name']
        );

        $info = [
            'version'      => $tag_name,
            'download_url' => $download_url,
            'tested'       => get_bloginfo( 'version' ),
            'requires'     => '5.0',
            'last_updated' => $latest_tag['date'] ?? date( 'Y-m-d H:i:s' ),
        ];

        $this->log( 'Информация о версии подготовлена', $info );

        // Кэширование на 12 часов
        set_transient( $this->cache_key, $info, 12 * HOUR_IN_SECONDS );
        $this->log( 'Информация сохранена в кэш на 12 часов' );

        return $info;
    }

    /**
     * Проверка обновлений
     */
    public function check_update( $transient ) {
        $this->log( 'Вызван check_update', [
            'has_checked' => ! empty( $transient->checked ),
            'checked_count' => ! empty( $transient->checked ) ? count( $transient->checked ) : 0
        ] );

        if ( empty( $transient->checked ) ) {
            $this->log( 'Пропуск проверки: transient->checked пуст' );
            return $transient;
        }

        $this->log( 'Запрос информации о удалённой версии' );
        $remote_info = $this->get_remote_info();

        if ( ! $remote_info ) {
            $this->log( 'ОШИБКА: Не удалось получить информацию о удалённой версии' );
            return $transient;
        }

        $this->log( 'Сравнение версий', [
            'current_version' => $this->version,
            'remote_version'  => $remote_info['version'],
            'needs_update'    => version_compare( $this->version, $remote_info['version'], '<' )
        ] );

        if ( version_compare( $this->version, $remote_info['version'], '<' ) ) {
            $plugin = [
                'slug'        => dirname( $this->plugin_slug ),
                'new_version' => $remote_info['version'],
                'url'         => sprintf(
                    'https://bitbucket.org/%s/%s',
                    $this->bitbucket_username,
                    $this->bitbucket_repo
                ),
                'package'     => $remote_info['download_url'],
                'tested'      => $remote_info['tested'],
                'requires'    => $remote_info['requires'],
            ];

            $transient->response[ $this->plugin_slug ] = (object) $plugin;

            $this->log( '✅ ОБНОВЛЕНИЕ ДОСТУПНО! Добавлено в transient->response', [
                'plugin_slug' => $this->plugin_slug,
                'plugin_data' => $plugin
            ] );
        } else {
            $this->log( 'Обновление не требуется, текущая версия актуальна или новее' );
        }

        return $transient;
    }

    /**
     * Информация о плагине для popup окна
     */
    public function plugin_info( $false, $action, $args ) {
        $this->log( 'Вызван plugin_info', [
            'action'   => $action,
            'args_slug' => isset( $args->slug ) ? $args->slug : 'не указан'
        ] );

        if ( $action !== 'plugin_information' ) {
            $this->log( 'Пропуск: action не равен plugin_information' );
            return $false;
        }

        $expected_slug = dirname( $this->plugin_slug );
        if ( empty( $args->slug ) || $args->slug !== $expected_slug ) {
            $this->log( 'Пропуск: slug не совпадает', [
                'expected_slug' => $expected_slug,
                'received_slug' => $args->slug ?? 'отсутствует'
            ] );
            return $false;
        }

        $this->log( 'Запрос информации о плагине для popup' );
        $remote_info = $this->get_remote_info();

        if ( ! $remote_info ) {
            $this->log( 'ОШИБКА: Не удалось получить информацию о удалённой версии для popup' );
            return $false;
        }

        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data( $this->plugin_file );

        $info = [
            'name'          => $plugin_data['Name'],
            'slug'          => $expected_slug,
            'version'       => $remote_info['version'],
            'author'        => $plugin_data['Author'],
            'homepage'      => sprintf(
                'https://bitbucket.org/%s/%s',
                $this->bitbucket_username,
                $this->bitbucket_repo
            ),
            'requires'      => $remote_info['requires'],
            'tested'        => $remote_info['tested'],
            'downloaded'    => 0,
            'last_updated'  => $remote_info['last_updated'],
            'sections'      => [
                'description' => $plugin_data['Description'],
            ],
            'download_link' => $remote_info['download_url'],
        ];

        $this->log( 'Информация для popup подготовлена', $info );

        return (object) $info;
    }

    /**
     * Исправление имени папки после распаковки архива
     */
    public function fix_source_folder( $source, $remote_source, $upgrader ) {
        $this->log( 'Вызван fix_source_folder', [
            'source'        => $source,
            'remote_source' => $remote_source,
            'has_skin'      => isset( $upgrader->skin ),
            'skin_plugin'   => isset( $upgrader->skin->plugin ) ? $upgrader->skin->plugin : 'не указан'
        ] );

        // Проверяем, что это обновление нашего плагина
        if ( ! isset( $upgrader->skin->plugin ) || $upgrader->skin->plugin !== $this->plugin_slug ) {
            $this->log( 'Пропуск: это не наш плагин', [
                'expected' => $this->plugin_slug,
                'received' => $upgrader->skin->plugin ?? 'отсутствует'
            ] );
            return $source;
        }

        global $wp_filesystem;

        $desired_slug = dirname( $this->plugin_slug );
        $current_slug = basename( $source );

        $this->log( 'Проверка имени папки', [
            'desired_slug' => $desired_slug,
            'current_slug' => $current_slug,
            'needs_rename' => $current_slug !== $desired_slug
        ] );

        // Bitbucket создает папки в формате username-reponame-hash
        // Нужно переименовать в правильное имя плагина
        if ( $current_slug !== $desired_slug ) {
            $new_source = trailingslashit( $remote_source ) . $desired_slug;

            $this->log( 'Переименование папки плагина', [
                'from' => $source,
                'to'   => $new_source
            ] );

            $move_result = $wp_filesystem->move( $source, $new_source );

            if ( $move_result ) {
                $this->log( '✅ Папка успешно переименована' );
                return $new_source;
            } else {
                $this->log( '❌ ОШИБКА: Не удалось переименовать папку' );
                return $source;
            }
        }

        $this->log( 'Переименование не требуется' );
        return $source;
    }

    /**
     * Очистка кэша
     */
    public function clear_cache() {
        $result = delete_transient( $this->cache_key );
        delete_transient( $this->cache_key . '_diagnostic' ); // Очищаем и диагностический кэш
        $this->log( 'Очистка кэша', [
            'cache_key' => $this->cache_key,
            'success'   => $result
        ] );
        return $result;
    }

    /**
     * Принудительная проверка обновлений (для ручного вызова)
     */
    public function force_check() {
        $this->log( '=== ПРИНУДИТЕЛЬНАЯ ПРОВЕРКА ЗАПУЩЕНА ВРУЧНУЮ ===' );

        // Очищаем кэш
        $this->clear_cache();
        delete_site_transient( 'update_plugins' );

        $this->log( 'Кэш очищен, запрашиваем информацию' );

        // Получаем информацию
        $remote_info = $this->get_remote_info();

        if ( $remote_info ) {
            $this->log( '✅ Информация получена успешно', $remote_info );

            // Сравниваем версии
            $needs_update = version_compare( $this->version, $remote_info['version'], '<' );
            $this->log( 'Результат сравнения версий', [
                'current'      => $this->version,
                'remote'       => $remote_info['version'],
                'needs_update' => $needs_update
            ] );

            return $remote_info;
        } else {
            $this->log( '❌ Не удалось получить информацию' );
            return false;
        }
    }

    /**
     * Получить логи (для отладки)
     * Возвращает последние записи из error_log, связанные с BitbucketUpdater
     */
    public function get_logs() {
        $log_file = ini_get( 'error_log' );

        if ( ! $log_file || ! file_exists( $log_file ) ) {
            return 'Лог-файл не найден. Проверьте настройки error_log в php.ini';
        }

        $logs = file( $log_file );
        $filtered_logs = array_filter( $logs, function( $line ) {
            return strpos( $line, '[BitbucketUpdater]' ) !== false;
        } );

        return implode( '', array_slice( $filtered_logs, -50 ) ); // Последние 50 записей
    }
}

// Пример использования:
/*
// В главном файле вашего плагина добавьте:

require_once plugin_dir_path(__FILE__) . 'includes/class-bitbucket-updater.php';

// ВАЖНО: Сохраните экземпляр в глобальную переменную для доступа
global $my_bitbucket_updater;

function init_my_plugin_updater() {
    if (is_admin()) {
        global $my_bitbucket_updater;
        $my_bitbucket_updater = new \TGBot\BitbucketPluginUpdater(
            __FILE__, // Путь к главному файлу плагина
            'your-workspace-slug', // Workspace slug (например, 'mycompany')
            'your-repo-name', // Название репозитория
            'your-api-token', // API Token (опционально, для приватных репозиториев)
            true // Включить логирование (по умолчанию true)
        );
    }
}
add_action('plugins_loaded', 'init_my_plugin_updater');

// Добавьте кнопку для принудительной проверки в админке
add_action('admin_notices', function() {
    $screen = get_current_screen();
    if ($screen->id === 'plugins' || $screen->id === 'update-core') {
        if (isset($_GET['bitbucket_force_check']) && $_GET['bitbucket_force_check'] === '1') {
            global $my_bitbucket_updater;
            if ($my_bitbucket_updater) {
                $result = $my_bitbucket_updater->force_check();
                echo '<div class="notice notice-info"><p>';
                echo $result ? '✅ Проверка обновлений выполнена. Проверьте логи.' : '❌ Ошибка при проверке. Проверьте логи.';
                echo '</p></div>';
            }
        }

        $check_url = add_query_arg('bitbucket_force_check', '1');
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>Bitbucket Updater:</strong> ';
        echo '<a href="' . esc_url($check_url) . '" class="button button-small">Принудительно проверить обновления</a> ';
        echo '| Проверьте логи в error_log';
        echo '</p></div>';
    }
});

// Для просмотра логов добавьте страницу в админке:
add_action('admin_menu', function() {
    add_submenu_page(
        'plugins.php',
        'Bitbucket Updater Logs',
        'Updater Logs',
        'manage_options',
        'bitbucket-updater-logs',
        function() {
            global $my_bitbucket_updater;
            echo '<div class="wrap">';
            echo '<h1>Bitbucket Updater Logs</h1>';

            if (isset($_GET['clear_logs'])) {
                // Очистка только диагностического кэша
                if ($my_bitbucket_updater) {
                    delete_transient($my_bitbucket_updater->cache_key . '_diagnostic');
                }
                echo '<div class="notice notice-success"><p>Диагностический кэш очищен. Перезагрузите страницу плагинов для новой диагностики.</p></div>';
            }

            echo '<p>';
            echo '<a href="' . admin_url('plugins.php?page=bitbucket-updater-logs&clear_logs=1') . '" class="button">Очистить диагностический кэш</a> ';
            echo '<a href="' . admin_url('plugins.php?bitbucket_force_check=1') . '" class="button button-primary">Принудительная проверка</a>';
            echo '</p>';

            if (isset($my_bitbucket_updater)) {
                echo '<pre style="background: #f5f5f5; padding: 15px; overflow: auto; max-height: 600px; border: 1px solid #ddd;">';
                $logs = $my_bitbucket_updater->get_logs();
                echo $logs ? esc_html($logs) : 'Логов пока нет. Попробуйте принудительную проверку.';
                echo '</pre>';
            } else {
                echo '<div class="notice notice-error"><p>Updater не инициализирован</p></div>';
            }
            echo '</div>';
        }
    );
});

// ОТЛАДКА: Что делать если логов нет после "Хуки зарегистрированы успешно":
// 1. Зайдите на страницу "Плагины" в админке - это запустит диагностику
// 2. Проверьте логи через "Плагины -> Updater Logs"
// 3. Нажмите кнопку "Принудительно проверить обновления"
// 4. Убедитесь что в Bitbucket есть хотя бы один тег (релиз)

// Как создать API Token в Bitbucket (новая система):
// 1. Зайдите в Bitbucket Settings -> Personal settings -> API tokens
// 2. Нажмите "Create token"
// 3. Выберите необходимые разрешения (scopes):
//    - Repositories: Read (обязательно)
// 4. Скопируйте сгенерированный токен
//
// Важно: Токен показывается только один раз! Сохраните его в безопасном месте.
//
// Примечание: Для публичных репозиториев токен не обязателен.
*/