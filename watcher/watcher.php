<?php
/**
 * WordPress Telegram Bot Wrapper
 * Скрипт для проверки новых сообщений в Telegram боте и вызова webhook
 */

// Путь к файлу wp-config.php (настройте под вашу структуру)
define( 'WP_CONFIG_PATH',  '../wp-config.php' );

// URL для вызова при получении новых сообщений
define( 'WEBHOOK_URL', 'https://eai-loc.com/' );

// Задержка между проверками (в секундах)
define( 'CHECK_INTERVAL', 5 );

class WPTelegramWrapper {
	private $dbHost;
	private $dbName;
	private $dbUser;
	private $dbPass;
	private $tablePrefix;
	private $pdo;
	private $lastUpdateId = 0;

	public function __construct() {
		$this->parseWpConfig();
		$this->connectDatabase();
	}

	/**
	 * Парсинг wp-config.php для получения данных подключения к БД
	 */
	private function parseWpConfig() {
		if ( ! file_exists( WP_CONFIG_PATH ) ) {
			throw new Exception( "Файл wp-config.php не найден: " . WP_CONFIG_PATH );
		}

		$config = file_get_contents( WP_CONFIG_PATH );

		// Извлекаем данные для подключения к БД
		if ( preg_match( "/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $config, $matches ) ) {
			$this->dbName = $matches[1];
		}

		if ( preg_match( "/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $config, $matches ) ) {
			$this->dbUser = $matches[1];
		}

		if ( preg_match( "/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $config, $matches ) ) {
			$this->dbPass = $matches[1];
		}

		if ( preg_match( "/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $config, $matches ) ) {
			$this->dbHost = $matches[1];
		}

		// Извлекаем префикс таблиц
		if ( preg_match( "/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]/", $config, $matches ) ) {
			$this->tablePrefix = $matches[1];
		}

		// Проверяем, что все данные получены
		if ( ! $this->dbName || ! $this->dbUser || ! $this->dbHost ) {
			throw new Exception( "Не удалось извлечь данные подключения из wp-config.php" );
		}

		if ( ! $this->tablePrefix ) {
			$this->tablePrefix = 'wp_'; // Префикс по умолчанию
		}

		echo "✓ Данные из wp-config.php получены\n";
		echo "  База: {$this->dbName}, Префикс: {$this->tablePrefix}\n";
	}

	/**
	 * Подключение к базе данных
	 */
	private function connectDatabase() {
		try {
			$dsn       = "mysql:host={$this->dbHost};dbname={$this->dbName};charset=utf8mb4";
			$this->pdo = new PDO( $dsn, $this->dbUser, $this->dbPass, [
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
			] );
			echo "✓ Подключение к базе данных установлено\n";
		} catch ( PDOException $e ) {
			throw new Exception( "Ошибка подключения к БД: " . $e->getMessage() );
		}
	}

	/**
	 * Получение токена Telegram бота из базы данных
	 */
	private function getTelegramToken() {
		$optionsTable = $this->tablePrefix . 'options';

		try {
			$stmt = $this->pdo->prepare(
				"SELECT option_value FROM {$optionsTable} WHERE option_name = :option_name"
			);
			$stmt->execute( [ 'option_name' => 'tgbot_options' ] );
			$result = $stmt->fetch();

			if ( ! $result ) {
				throw new Exception( "Опция tgbot_options не найдена в БД" );
			}

			$options = maybe_unserialize( $result['option_value'] );

			if ( ! is_array( $options ) || ! isset( $options['gen_tg_token'] ) ) {
				throw new Exception( "Токен gen_tg_token не найден в опции tgbot_options" );
			}

			return $options['gen_tg_token'];
		} catch ( PDOException $e ) {
			throw new Exception( "Ошибка при получении токена: " . $e->getMessage() );
		}
	}

	/**
	 * Проверка новых сообщений в Telegram боте
	 */
	private function checkTelegramUpdates( $token ) {
		$url = "https://api.telegram.org/bot{$token}/getUpdates";

		// Добавляем offset для получения только новых сообщений
		if ( $this->lastUpdateId > 0 ) {
			$url .= "?offset=" . ( $this->lastUpdateId + 1 );
		}

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $httpCode !== 200 ) {
			echo "! Ошибка при запросе к Telegram API (HTTP {$httpCode})\n";

			return false;
		}

		$data = json_decode( $response, true );

		if ( ! $data || ! isset( $data['ok'] ) || ! $data['ok'] ) {
			echo "! Некорректный ответ от Telegram API\n";

			return false;
		}

		$updates = $data['result'] ?? [];

		if ( count( $updates ) > 0 ) {
			// Обновляем ID последнего обработанного сообщения
			$lastUpdate         = end( $updates );
			$this->lastUpdateId = $lastUpdate['update_id'];

			return true;
		}

		return false;
	}

	/**
	 * Вызов webhook URL
	 */
	private function triggerWebhook() {
		$ch = curl_init( WEBHOOK_URL );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		echo "→ Webhook вызван (HTTP {$httpCode}): " . WEBHOOK_URL . "\n";
	}

	/**
	 * Основной цикл работы
	 */
	public function run() {
		echo "=== Запуск WordPress Telegram Bot Wrapper ===\n\n";

		// Получаем токен
		$token = $this->getTelegramToken();
		echo "✓ Токен Telegram бота получен\n";
		echo "  Токен: " . substr( $token, 0, 10 ) . "...\n\n";

		echo "Начинаю мониторинг сообщений...\n";
		echo "Нажмите Ctrl+C для остановки\n\n";

		// Основной цикл
		while ( true ) {
			try {
				$hasNewMessages = $this->checkTelegramUpdates( $token );

				if ( $hasNewMessages ) {
					echo "[" . date( 'Y-m-d H:i:s' ) . "] ✉ Обнаружены новые сообщения!\n";
					$this->triggerWebhook();
				} else {
					echo "[" . date( 'Y-m-d H:i:s' ) . "] ○ Нет новых сообщений\n";
				}

			} catch ( Exception $e ) {
				echo "[" . date( 'Y-m-d H:i:s' ) . "] ✗ Ошибка: " . $e->getMessage() . "\n";
			}

			// Задержка перед следующей проверкой
			sleep( CHECK_INTERVAL );
		}
	}
}

/**
 * Вспомогательная функция для десериализации данных WordPress
 */
function maybe_unserialize( $data ) {
	if ( is_serialized( $data ) ) {
		return @unserialize( $data );
	}

	return $data;
}

function is_serialized( $data ) {
	if ( ! is_string( $data ) ) {
		return false;
	}
	$data = trim( $data );
	if ( 'N;' === $data ) {
		return true;
	}
	if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
		return false;
	}
	$lastc = substr( $data, - 1 );
	if ( ';' !== $lastc && '}' !== $lastc ) {
		return false;
	}
	$token = $data[0];
	switch ( $token ) {
		case 's':
		case 'a':
		case 'O':
			return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
		case 'b':
		case 'i':
		case 'd':
			$end = substr( $data, 2, - 1 );

			return (bool) preg_match( "/^{$token}:[0-9.E+-]+;\$/", $data );
	}

	return false;
}

// Запуск скрипта
try {
	$wrapper = new WPTelegramWrapper();
	$wrapper->run();
} catch ( Exception $e ) {
	echo "✗ Критическая ошибка: " . $e->getMessage() . "\n";
	exit( 1 );
}