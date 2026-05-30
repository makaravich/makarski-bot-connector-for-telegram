<?php

namespace TGBot;

/**
 * Telegram Bot API wrapper.
 */
class BotApi {

	/** @var string Bot token. */
	private string $token;

	/** @var string Telegram API base URL. */
	private string $api_url;

	/** @var object Last raw response from Telegram API. */
	public object $request_respond;

	/** @var string Current chat ID. */
	public string $chat_id = '';

	/** @var object Last request response object. */
	private object $last_request_response;

	/** @var string Text of last received non-command message. */
	protected string $last_received_text = '';

	/** @var string Help message sent on /start and /help commands. */
	private string $help_message = 'Default help message';

	/** @var array Bot configuration map. */
	protected array $map = [];

	/** @var bool Whether to auto-execute commands from incoming messages. */
	private bool $auto_exec = true;


	public function __construct( $token, $do_get_request = true, $bot_map = [] ) {
		$this->token   = $token;
		$this->api_url = 'https://api.telegram.org/bot' . $this->token . '/';

		$this->set_map( $bot_map );

		if ( ( $this->map['auto_exec'] ?? true ) === false ) {
			$this->auto_exec = false;
		}

		if ( isset( $this->map['help_message'] ) ) {
			$this->help_message = $this->map['help_message'];
		}

		if ( $do_get_request && ! isset( $this->map['request_respond'] ) ) {
			$this->get_request();
		} elseif ( isset( $this->map['request_respond'] ) ) {
			$this->set_existing_request_respond( $this->map['request_respond'] );
		}
	}

	private function set_map( $map ): void {
		$this->map = $map;
	}

	public function get_map(): array {
		return $this->map;
	}

	public function get_last_received_text(): string {
		return $this->last_received_text;
	}

	private function set_last_received_text( $text ): void {
		if ( ! empty( $text ) && ! str_starts_with( $text, '/' ) ) {
			$this->last_received_text = $text;
		} else {
			$this->last_received_text = '';
			if ( ! empty( $text ) && $this->auto_exec ) {
				$this->run_command( $text );
			} elseif ( ! $this->auto_exec ) {
				$this->last_received_text = $text;
			}
		}
	}

	public function run_command( $command ): void {
		$command = ltrim( $command, '/' );

		if ( strlen( $command ) > 100 ) {
			$this->send_message( __( 'Too long command', 'tg-bot' ) );
		} else {
			if ( method_exists( $this, 'command_' . $command ) ) {
				call_user_func( array( $this, 'command_' . $command ) );
			} else {
				$this->send_message( 'Unknown command: ' . $command );
			}
		}
	}

	/** @return bool */
	public function command_start(): bool {
		$this->send_message( 'Hi!' );
		$this->send_message( $this->help_message );
		$this->send_message( 'Use command /help to get this tip again' );

		return true;
	}

	/** @return mixed */
	public function command_help(): mixed {
		return $this->send_message( $this->help_message );
	}

	/**
	 * Send a text message.
	 *
	 * @param string      $message      Message text (HTML allowed).
	 * @param string      $chat_id      Target chat ID; defaults to current chat.
	 * @param array|null  $reply_markup Optional inline keyboard markup.
	 * @return mixed
	 */
	public function send_message( $message, string $chat_id = '', $reply_markup = null ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		$data = array(
			'chat_id'    => $chat_id,
			'text'       => $message,
			'parse_mode' => 'HTML',
		);

		if ( $reply_markup ) {
			$data['reply_markup'] = wp_json_encode( $reply_markup );
		}

		return $this->send_request( $this->api_url . 'sendMessage', $data );
	}

	/**
	 * Send a MarkdownV2-formatted message.
	 *
	 * @param string      $message      Message text (Markdown).
	 * @param string      $chat_id      Target chat ID; defaults to current chat.
	 * @param array|null  $reply_markup Optional inline keyboard markup.
	 * @return mixed
	 */
	public function send_markdown_message( $message, string $chat_id = '', $reply_markup = null ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		$data = array(
			'chat_id'    => $chat_id,
			'text'       => $this->escape_markdown_v2( $message ),
			'parse_mode' => 'MarkdownV2',
		);

		if ( $reply_markup ) {
			$data['reply_markup'] = wp_json_encode( $reply_markup );
		}

		return $this->send_request( $this->api_url . 'sendMessage', $data );
	}

	/**
	 * Escape special characters for Telegram MarkdownV2 format.
	 *
	 * @param string $text Raw text.
	 * @return string Escaped text.
	 */
	private function escape_markdown_v2( string $text ): string {
		$result = '';
		$length = mb_strlen( $text );
		$states = array(
			'_' => false,
			'*' => false,
			'~' => false,
			'`' => false,
		);

		for ( $i = 0; $i < $length; $i++ ) {
			$char      = mb_substr( $text, $i, 1 );
			$remaining = mb_substr( $text, $i + 1 );

			switch ( $char ) {
				case '_':
				case '*':
				case '~':
				case '`':
					if ( $states[ $char ] ) {
						$result          .= $char;
						$states[ $char ]  = false;
					} else {
						if ( mb_strpos( $remaining, $char ) !== false ) {
							$result          .= $char;
							$states[ $char ]  = true;
						} else {
							$result .= '\\' . $char;
						}
					}
					break;

				case '[':
					$result .= ( mb_strpos( $remaining, ']' ) === false ) ? '\\' . $char : $char;
					break;

				case ']':
					$before  = mb_substr( $text, 0, $i );
					$result .= ( mb_strrpos( $before, '[' ) === false ) ? '\\' . $char : $char;
					break;

				case '(':
					$before           = mb_substr( $text, 0, $i );
					$last_bracket_pos = mb_strrpos( $before, ']' );
					$is_link          = false;

					if ( $last_bracket_pos !== false ) {
						$between = mb_substr( $before, $last_bracket_pos + 1 );
						if ( preg_match( '/^\s*$/', $between ) ) {
							$is_link = mb_strrpos( mb_substr( $before, 0, $last_bracket_pos ), '[' ) !== false;
						}
					}

					$result .= $is_link ? $char : '\\' . $char;
					break;

				case ')':
					$before         = mb_substr( $text, 0, $i );
					$last_paren_pos = mb_strrpos( $before, '(' );
					$is_link_end    = false;

					if ( $last_paren_pos !== false ) {
						$text_before_paren = mb_substr( $before, 0, $last_paren_pos );
						$last_bracket_pos  = mb_strrpos( $text_before_paren, ']' );
						if ( $last_bracket_pos !== false ) {
							$between = mb_substr( $text_before_paren, $last_bracket_pos + 1 );
							if ( preg_match( '/^\s*$/', $between ) ) {
								$is_link_end = mb_strrpos( mb_substr( $text_before_paren, 0, $last_bracket_pos ), '[' ) !== false;
							}
						}
					}

					$result .= $is_link_end ? $char : '\\' . $char;
					break;

				case '>':
					$before       = mb_substr( $text, 0, $i );
					$line_start   = ( 0 === $i ) || ( "\n" === mb_substr( $before, -1 ) );
					$result      .= $line_start ? $char : '\\' . $char;
					break;

				case '#':
				case '+':
				case '-':
				case '=':
				case '|':
				case '{':
				case '}':
				case '.':
				case '!':
					$result .= '\\' . $char;
					break;

				default:
					$result .= $char;
					break;
			}
		}

		return $result;
	}

	/**
	 * Send a photo from a local file path.
	 * Uses multipart/form-data via curl — wp_remote_post does not support CURLFile.
	 *
	 * @param string      $photo_path  Absolute path to the image file.
	 * @param string|null $caption     Optional caption.
	 * @param string      $chat_id     Target chat ID; defaults to current chat.
	 * @return mixed
	 */
	public function send_photo( $photo_path, $caption = null, string $chat_id = '' ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		$data = array(
			'chat_id' => $chat_id,
			'photo'   => new \CURLFile( realpath( $photo_path ) ),
			'caption' => $caption,
		);

		return $this->send_multipart_request( $this->api_url . 'sendPhoto', $data );
	}

	/**
	 * Send a document from a local file path.
	 * Uses multipart/form-data via curl — wp_remote_post does not support CURLFile.
	 *
	 * @param string      $document_path  Absolute path to the file.
	 * @param string|null $caption        Optional caption.
	 * @param string      $chat_id        Target chat ID; defaults to current chat.
	 * @return mixed
	 */
	public function send_document( string $document_path, string $caption = null, string $chat_id = '' ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		$data = array(
			'chat_id'  => $chat_id,
			'document' => new \CURLFile( $document_path ),
			'caption'  => $caption,
		);

		return $this->send_multipart_request( $this->api_url . 'sendDocument', $data );
	}

	/**
	 * Send a Telegram Stars invoice.
	 *
	 * @param string $title
	 * @param string $description
	 * @param string $payload       Internal payload string, e.g. "buy:premium_30d:user123".
	 * @param int    $stars_amount  Price in Telegram Stars.
	 * @param string $chat_id       Target chat ID; defaults to current chat.
	 * @return mixed
	 */
	public function send_stars_invoice( $title, $description, $payload, $stars_amount, string $chat_id = '' ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		$data = array(
			'chat_id'            => $chat_id,
			'title'              => $title,
			'description'        => $description,
			'payload'            => $payload,
			'currency'           => 'XTR',
			'prices'             => wp_json_encode(
				array(
					array(
						'label'  => $title,
						'amount' => $stars_amount,
					),
				)
			),
			'need_name'          => false,
			'need_phone_number'  => false,
			'need_email'         => false,
		);

		return $this->send_request( $this->api_url . 'sendInvoice', $data );
	}

	/**
	 * Answer a pre-checkout query.
	 *
	 * @param string      $pre_checkout_query_id
	 * @param bool        $ok
	 * @param string|null $error_message
	 * @return mixed
	 */
	public function answer_pre_checkout_query( $pre_checkout_query_id, bool $ok = true, $error_message = null ): mixed {
		$data = array(
			'pre_checkout_query_id' => $pre_checkout_query_id,
			'ok'                    => $ok,
		);

		if ( $error_message ) {
			$data['error_message'] = $error_message;
		}

		return $this->send_request( $this->api_url . 'answerPreCheckoutQuery', $data );
	}

	/**
	 * Answer a callback query (inline button tap).
	 *
	 * @param string      $callback_query_id
	 * @param string|null $text
	 * @param bool        $show_alert
	 * @return mixed
	 */
	public function answer_callback_query( $callback_query_id, $text = null, bool $show_alert = false ): mixed {
		$data = array( 'callback_query_id' => $callback_query_id );

		if ( null !== $text ) {
			$data['text'] = $text;
		}

		if ( $show_alert ) {
			$data['show_alert'] = true;
		}

		return $this->send_request( $this->api_url . 'answerCallbackQuery', $data );
	}

	/**
	 * Send a chat action indicator (typing, uploading, etc.).
	 *
	 * @param string $action  One of: typing, upload_photo, record_video, upload_video,
	 *                        record_voice, upload_voice, upload_document, choose_sticker, find_location.
	 * @param string $chat_id Target chat. Defaults to current chat.
	 * @return mixed
	 */
	public function send_chat_action( string $action, string $chat_id = '' ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		return $this->send_request(
			$this->api_url . 'sendChatAction',
			array(
				'chat_id' => $chat_id,
				'action'  => $action,
			)
		);
	}

	/**
	 * Send an audio file.
	 *
	 * @param string      $audio_path Local file path.
	 * @param string|null $caption    Optional caption (HTML).
	 * @param string      $chat_id    Target chat.
	 * @return mixed
	 */
	public function send_audio( string $audio_path, ?string $caption = null, string $chat_id = '' ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		$data = array(
			'chat_id' => $chat_id,
			'audio'   => new \CURLFile( $audio_path ),
		);

		if ( null !== $caption ) {
			$data['caption']    = $caption;
			$data['parse_mode'] = 'HTML';
		}

		return $this->send_multipart_request( $this->api_url . 'sendAudio', $data );
	}

	/**
	 * Send a voice message (OGG/Opus recommended).
	 *
	 * @param string      $voice_path Local file path.
	 * @param string|null $caption    Optional caption (HTML).
	 * @param string      $chat_id    Target chat.
	 * @return mixed
	 */
	public function send_voice( string $voice_path, ?string $caption = null, string $chat_id = '' ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		$data = array(
			'chat_id' => $chat_id,
			'voice'   => new \CURLFile( $voice_path ),
		);

		if ( null !== $caption ) {
			$data['caption']    = $caption;
			$data['parse_mode'] = 'HTML';
		}

		return $this->send_multipart_request( $this->api_url . 'sendVoice', $data );
	}

	/**
	 * Send a video file.
	 *
	 * @param string      $video_path Local file path.
	 * @param string|null $caption    Optional caption (HTML).
	 * @param string      $chat_id    Target chat.
	 * @return mixed
	 */
	public function send_video( string $video_path, ?string $caption = null, string $chat_id = '' ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		$data = array(
			'chat_id' => $chat_id,
			'video'   => new \CURLFile( $video_path ),
		);

		if ( null !== $caption ) {
			$data['caption']    = $caption;
			$data['parse_mode'] = 'HTML';
		}

		return $this->send_multipart_request( $this->api_url . 'sendVideo', $data );
	}

	/**
	 * Send an animation (GIF or MP4 without sound).
	 *
	 * @param string      $animation_path Local file path.
	 * @param string|null $caption        Optional caption (HTML).
	 * @param string      $chat_id        Target chat.
	 * @return mixed
	 */
	public function send_animation( string $animation_path, ?string $caption = null, string $chat_id = '' ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		$data = array(
			'chat_id'   => $chat_id,
			'animation' => new \CURLFile( $animation_path ),
		);

		if ( null !== $caption ) {
			$data['caption']    = $caption;
			$data['parse_mode'] = 'HTML';
		}

		return $this->send_multipart_request( $this->api_url . 'sendAnimation', $data );
	}

	/**
	 * Forward a message from another chat.
	 *
	 * @param int|string $from_chat_id Source chat ID.
	 * @param int        $message_id   Message ID to forward.
	 * @param string     $chat_id      Target chat.
	 * @return mixed
	 */
	public function forward_message( $from_chat_id, int $message_id, string $chat_id = '' ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		return $this->send_request(
			$this->api_url . 'forwardMessage',
			array(
				'chat_id'      => $chat_id,
				'from_chat_id' => $from_chat_id,
				'message_id'   => $message_id,
			)
		);
	}

	/**
	 * Copy a message without the "forwarded from" header.
	 *
	 * @param int|string  $from_chat_id Source chat ID.
	 * @param int         $message_id   Message ID to copy.
	 * @param string|null $caption      New caption (HTML). Null keeps the original.
	 * @param string      $chat_id      Target chat.
	 * @return mixed
	 */
	public function copy_message( $from_chat_id, int $message_id, ?string $caption = null, string $chat_id = '' ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		$data = array(
			'chat_id'      => $chat_id,
			'from_chat_id' => $from_chat_id,
			'message_id'   => $message_id,
		);

		if ( null !== $caption ) {
			$data['caption']    = $caption;
			$data['parse_mode'] = 'HTML';
		}

		return $this->send_request( $this->api_url . 'copyMessage', $data );
	}

	/**
	 * Send a geographic location.
	 *
	 * @param float  $latitude  Latitude (−90 to 90).
	 * @param float  $longitude Longitude (−180 to 180).
	 * @param string $chat_id   Target chat.
	 * @return mixed
	 */
	public function send_location( float $latitude, float $longitude, string $chat_id = '' ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		return $this->send_request(
			$this->api_url . 'sendLocation',
			array(
				'chat_id'   => $chat_id,
				'latitude'  => $latitude,
				'longitude' => $longitude,
			)
		);
	}

	/**
	 * Delete multiple messages at once (max 100 per call).
	 *
	 * @param int[]  $message_ids Array of message IDs to delete.
	 * @param string $chat_id     Target chat.
	 * @return mixed
	 */
	public function delete_messages( array $message_ids, string $chat_id = '' ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		return $this->send_request(
			$this->api_url . 'deleteMessages',
			array(
				'chat_id'     => $chat_id,
				'message_ids' => wp_json_encode( $message_ids ),
			)
		);
	}

	/**
	 * Register commands in the Telegram bot menu.
	 *
	 * @param array       $commands      Array of ['command' => 'name', 'description' => 'text'].
	 * @param string|null $scope_type    BotCommandScope type ('default', 'all_private_chats', etc.).
	 *                                   Null applies to all scopes.
	 * @param string      $language_code ISO 639-1 code, or '' for all languages.
	 * @return mixed
	 */
	public function set_my_commands( array $commands, ?string $scope_type = null, string $language_code = '' ): mixed {
		$data = array(
			'commands' => wp_json_encode( $commands ),
		);

		if ( null !== $scope_type ) {
			$data['scope'] = wp_json_encode( array( 'type' => $scope_type ) );
		}

		if ( '' !== $language_code ) {
			$data['language_code'] = $language_code;
		}

		return $this->send_request( $this->api_url . 'setMyCommands', $data );
	}

	/**
	 * Refund a Telegram Stars payment to the user.
	 *
	 * @param int|string $user_id                    Telegram user ID.
	 * @param string     $telegram_payment_charge_id Charge ID from the successful_payment object.
	 * @return mixed
	 */
	public function refund_star_payment( $user_id, string $telegram_payment_charge_id ): mixed {
		return $this->send_request(
			$this->api_url . 'refundStarPayment',
			array(
				'user_id'                    => $user_id,
				'telegram_payment_charge_id' => $telegram_payment_charge_id,
			)
		);
	}

	/**
	 * Set the webhook URL.
	 *
	 * @param string $url Full HTTPS URL for Telegram to deliver updates.
	 * @return mixed
	 */
	public function set_webhook( $url ): mixed {
		return $this->send_request( $this->api_url . 'setWebhook', array( 'url' => $url ) );
	}

	/**
	 * Delete the current webhook.
	 *
	 * @return mixed
	 */
	public function delete_webhook(): mixed {
		return $this->send_request( $this->api_url . 'deleteWebhook' );
	}

	/**
	 * Get current webhook info.
	 * Returns: url, pending_update_count, last_error_message, etc.
	 *
	 * @return mixed
	 */
	public function get_webhook_info(): mixed {
		return $this->send_request( $this->api_url . 'getWebhookInfo' );
	}

	/**
	 * Get pending updates (polling mode).
	 *
	 * @return mixed
	 */
	public function get_updates(): mixed {
		return $this->send_request( $this->api_url . 'getUpdates' );
	}

	/**
	 * Read and parse the incoming webhook request from Telegram.
	 *
	 * @return object|false
	 */
	public function get_request(): object|false {
		$input = file_get_contents( 'php://input' );

		if ( empty( $input ) ) {
			return false;
		}

		$this->request_respond = json_decode( $input );

		$this->update_chat_id();

		$callback_data = $this->request_respond->callback_query->data ?? null;
		$this->set_last_received_text(
			$callback_data ?? $this->request_respond->message->text ?? $this->request_respond->message->caption ?? ''
		);

		return $this->request_respond;
	}

	/**
	 * Inject an existing update object (used in polling mode to avoid re-reading php://input).
	 *
	 * @param object $request_respond Telegram update object.
	 */
	private function set_existing_request_respond( $request_respond ): void {
		$this->request_respond = $request_respond;

		$this->update_chat_id();

		// For callback queries, use callback_data as the command; otherwise use message text.
		$callback_data = $request_respond->callback_query->data ?? null;
		$this->set_last_received_text(
			$callback_data ?? $this->request_respond->message->text ?? $this->request_respond->message->caption ?? ''
		);
	}

	/** Refresh $this->chat_id from the current request_respond. */
	private function update_chat_id(): void {
		$chat_id = $this->request_respond->message->chat->id ?? null;

		if ( ! $chat_id ) {
			$chat_id = $this->request_respond->callback_query->from->id ?? null;
		}

		if ( $chat_id ) {
			$this->chat_id = $chat_id;
		}
	}

	/**
	 * Send a JSON-encoded POST request to the Telegram API.
	 * Uses WP HTTP API (wp_remote_post) for proxy/SSL compatibility.
	 *
	 * @param string $url  Full API endpoint URL.
	 * @param array  $data Request parameters.
	 * @return mixed Decoded response object.
	 */
	private function send_request( string $url, array $data = [] ): mixed {
		$response = wp_remote_post(
			$url,
			array(
				'body'    => $data,
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'[TGBot] send_request WP_Error: code=%s message=%s url=%s',
				$response->get_error_code(),
				$response->get_error_message(),
				$url
			) );
			$this->last_request_response = (object) array(
				'ok'          => false,
				'description' => $response->get_error_message(),
			);
			return $this->last_request_response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );

		if ( $http_code !== 200 ) {
			error_log( sprintf( '[TGBot] send_request unexpected HTTP %d for %s', $http_code, $url ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$this->last_request_response = json_decode( $body );

		if ( empty( $this->last_request_response ) ) {
			error_log( sprintf( '[TGBot] send_request empty/invalid JSON. HTTP=%d body=%s url=%s', $http_code, substr( $body, 0, 200 ), $url ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$this->last_request_response = (object) array( 'ok' => false, 'description' => 'Invalid JSON response' );
			return $this->last_request_response;
		}

		if ( ! $this->last_request_response->ok ) {
			error_log( sprintf( '[TGBot] send_request Telegram error: %s url=%s', $this->last_request_response->description ?? 'Unknown', $url ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $this->last_request_response;
	}

	/**
	 * Send a multipart/form-data request via curl.
	 * Used only for file uploads (send_photo, send_document) where CURLFile is required.
	 * wp_remote_post() does not support CURLFile/multipart uploads natively.
	 *
	 * @param string $url  Full API endpoint URL.
	 * @param array  $data Request parameters including CURLFile objects.
	 * @return mixed Decoded response object.
	 */
	private function send_multipart_request( string $url, array $data ): mixed {
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_close
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		$response = curl_exec( $ch );
		curl_close( $ch );
		// phpcs:enable

		$this->last_request_response = json_decode( $response );

		if ( ! $this->last_request_response->ok ) {
			error_log( '[TGBot ERROR] ' . ( $this->last_request_response->description ?? 'Unknown error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $this->last_request_response;
	}

	/**
	 * Edit text and/or markup of an existing message.
	 *
	 * @param int         $message_id
	 * @param string      $text
	 * @param array|null  $reply_markup
	 * @param string      $parse_mode
	 */
	public function edit_message( $message_id, string $text = '', $reply_markup = null, string $parse_mode = 'HTML' ): void {
		$data = array(
			'chat_id'    => $this->chat_id,
			'message_id' => $message_id,
			'parse_mode' => $parse_mode,
		);

		if ( $reply_markup ) {
			$data['reply_markup'] = wp_json_encode( $reply_markup );
		}

		if ( $text ) {
			$data['text'] = ( 'MarkdownV2' === $parse_mode ) ? $this->escape_markdown_v2( $text ) : $text;
		}

		$this->send_request( $this->api_url . 'editMessageText', $data );
	}

	/**
	 * Delete a message from the chat.
	 *
	 * @param int $message_id
	 */
	public function delete_message( int $message_id ): void {
		$this->send_request(
			$this->api_url . 'deleteMessage',
			array(
				'chat_id'    => $this->chat_id,
				'message_id' => $message_id,
			)
		);
	}

	/**
	 * Edit only the inline keyboard markup of an existing message.
	 *
	 * @param int   $message_id
	 * @param array $reply_markup
	 */
	public function edit_message_markup( $message_id, $reply_markup ): void {
		$this->send_request(
			$this->api_url . 'editMessageReplyMarkup',
			array(
				'chat_id'      => $this->chat_id,
				'message_id'   => $message_id,
				'reply_markup' => wp_json_encode( $reply_markup ),
			)
		);
	}

	/** @return object */
	public function get_last_request_response(): object {
		return $this->last_request_response;
	}

	/**
	 * Get the download URL for the primary media file in a message.
	 * Handles photos, video, audio, voice, video_note, sticker, document.
	 *
	 * @param object $message Telegram message or update object.
	 * @return string|null Download URL, or empty string if no file found.
	 */
	public function get_document_url( object $message ): ?string {
		$message = $message->message ?? $message;

		if ( is_array( $message->photo ) && ! empty( $message->photo ) ) {
			return $this->get_photo_url( $message );
		}

		$file_id = '';
		$types   = array( 'document', 'video', 'audio', 'voice', 'video_note', 'sticker' );

		foreach ( $types as $type ) {
			if ( isset( $message->$type ) ) {
				$file_id = $message->$type->file_id;
				break;
			}
		}

		if ( ! $file_id ) {
			return '';
		}

		$file_info = $this->get_file_info( $file_id );

		if ( ! $file_info || ! isset( $file_info['file_path'] ) ) {
			return '';
		}

		return 'https://api.telegram.org/file/bot' . $this->token . '/' . $file_info['file_path'];
	}

	/**
	 * Get the download URL for the highest-resolution photo in a message.
	 *
	 * @param object $message Telegram message object.
	 * @return string|null Download URL, or null if no photo found.
	 */
	public function get_photo_url( object $message ): ?string {
		$message = $message->message ?? $message;

		if ( ! is_array( $message->photo ) || empty( $message->photo ) ) {
			return null;
		}

		$max_photo = $this->get_max_resolution_photo( $message->photo );

		if ( ! $max_photo || ! isset( $max_photo->file_id ) ) {
			return null;
		}

		$file_info = $this->get_file_info( $max_photo->file_id );

		if ( ! $file_info || ! isset( $file_info['file_path'] ) ) {
			return null;
		}

		return 'https://api.telegram.org/file/bot' . $this->token . '/' . $file_info['file_path'];
	}

	/**
	 * Return the PhotoSize object with the largest dimensions.
	 *
	 * @param array $photos Array of PhotoSize objects.
	 * @return object|null
	 */
	private function get_max_resolution_photo( array $photos ): ?object {
		$max_photo = null;
		$max_size  = 0;

		foreach ( $photos as $photo ) {
			$current_size = ( $photo->width ?? 0 ) * ( $photo->height ?? 0 );
			if ( $current_size > $max_size ) {
				$max_size  = $current_size;
				$max_photo = $photo;
			}
		}

		return $max_photo;
	}

	/**
	 * Fetch file info from Telegram API (getFile).
	 * Uses wp_remote_get for WP HTTP API compatibility.
	 *
	 * @param string $file_id Telegram file_id.
	 * @return array|null File info array with 'file_path', or null on error.
	 */
	private function get_file_info( string $file_id ): ?array {
		$url      = 'https://api.telegram.org/bot' . $this->token . '/getFile?file_id=' . rawurlencode( $file_id );
		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['ok'] ) || ! isset( $data['result'] ) ) {
			return null;
		}

		return $data['result'];
	}
}
