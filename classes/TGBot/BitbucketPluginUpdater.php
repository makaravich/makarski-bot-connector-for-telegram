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

	/**
	 * Конструктор класса
	 *
	 * @param string $plugin_file Полный путь к главному файлу плагина
	 * @param string $bitbucket_username Имя пользователя Bitbucket (workspace slug)
	 * @param string $bitbucket_repo Название репозитория
	 * @param string $bitbucket_token API token для Bitbucket API (опционально для публичных репо)
	 */
	public function __construct( $plugin_file, $bitbucket_username, $bitbucket_repo, $bitbucket_token = '' ) {
		$this->plugin_file        = $plugin_file;
		$this->plugin_slug        = plugin_basename( $plugin_file );
		$this->bitbucket_username = $bitbucket_username;
		$this->bitbucket_repo     = $bitbucket_repo;
		$this->bitbucket_token    = $bitbucket_token;
		$this->version            = $this->get_plugin_version();
		$this->cache_key          = 'bitbucket_updater_' . md5( $this->plugin_slug );

		// Регистрация хуков
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_source_folder' ], 10, 3 );
	}

	/**
	 * Получение текущей версии плагина
	 */
	private function get_plugin_version() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_data = get_plugin_data( $this->plugin_file );

		return $plugin_data['Version'];
	}

	/**
	 * Получение информации о последней версии из Bitbucket
	 */
	private function get_remote_info() {
		// Проверка кэша
		if ( $this->cache_allowed ) {
			$cached = get_transient( $this->cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// URL для получения тегов из Bitbucket API
		$api_url = sprintf(
			'https://api.bitbucket.org/2.0/repositories/%s/%s/refs/tags?sort=-name',
			$this->bitbucket_username,
			$this->bitbucket_repo
		);

		$args = [
			'headers' => [
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
				'Accept'     => 'application/json'
			]
		];

		// Добавление авторизации через API Token
		if ( ! empty( $this->bitbucket_token ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->bitbucket_token;
		}

		$response = wp_remote_get( $api_url, $args );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data['values'] ) ) {
			return false;
		}

		// Получение последнего тега
		$latest_tag = $data['values'][0];
		$tag_name   = ltrim( $latest_tag['name'], 'v' ); // Убираем 'v' если есть

		$info = [
			'version'      => $tag_name,
			'download_url' => sprintf(
				'https://bitbucket.org/%s/%s/get/%s.zip',
				$this->bitbucket_username,
				$this->bitbucket_repo,
				$latest_tag['name']
			),
			'tested'       => get_bloginfo( 'version' ),
			'requires'     => '5.0',
			'last_updated' => $latest_tag['date'] ?? date( 'Y-m-d H:i:s' ),
		];

		// Кэширование на 12 часов
		set_transient( $this->cache_key, $info, 12 * HOUR_IN_SECONDS );

		return $info;
	}

	/**
	 * Проверка обновлений
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote_info = $this->get_remote_info();

		if ( $remote_info && version_compare( $this->version, $remote_info['version'], '<' ) ) {
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
		}

		return $transient;
	}

	/**
	 * Информация о плагине для popup окна
	 */
	public function plugin_info( $false, $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $false;
		}

		if ( empty( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) {
			return $false;
		}

		$remote_info = $this->get_remote_info();

		if ( ! $remote_info ) {
			return $false;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_data = get_plugin_data( $this->plugin_file );

		$info = [
			'name'          => $plugin_data['Name'],
			'slug'          => dirname( $this->plugin_slug ),
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

		return (object) $info;
	}

	/**
	 * Исправление имени папки после распаковки архива
	 */
	public function fix_source_folder( $source, $remote_source, $upgrader ) {
		global $wp_filesystem;

		// Проверяем, что это обновление нашего плагина
		if ( ! isset( $upgrader->skin->plugin ) || $upgrader->skin->plugin !== $this->plugin_slug ) {
			return $source;
		}

		$desired_slug = dirname( $this->plugin_slug );

		// Bitbucket создает папки в формате username-reponame-hash
		// Нужно переименовать в правильное имя плагина
		if ( basename( $source ) !== $desired_slug ) {
			$new_source = trailingslashit( $remote_source ) . $desired_slug;
			$wp_filesystem->move( $source, $new_source );

			return $new_source;
		}

		return $source;
	}

	/**
	 * Очистка кэша
	 */
	public function clear_cache() {
		delete_transient( $this->cache_key );
	}
}

// Пример использования:
/*
// В главном файле вашего плагина добавьте:

require_once plugin_dir_path(__FILE__) . 'includes/class-bitbucket-updater.php';

function init_my_plugin_updater() {
    if (is_admin()) {
        new Bitbucket_Plugin_Updater(
            __FILE__, // Путь к главному файлу плагина
            'your-workspace-slug', // Workspace slug (например, 'mycompany')
            'your-repo-name', // Название репозитория
            'your-api-token' // API Token (опционально, для приватных репозиториев)
        );
    }
}
add_action('init', 'init_my_plugin_updater');

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