<?php

namespace TGBot;

/**
 * Telegram Bot API wrapper.
 */
class BotApi {

	/** Telegram sendMessage hard limit, in UTF-16 code units. */
	public const TG_TEXT_LIMIT = 4096;

	/**
	 * Auto-split threshold, in UTF-16 code units. Kept below TG_TEXT_LIMIT to
	 * leave headroom for HTML tags re-opened/closed on chunk boundaries.
	 */
	private const SPLIT_LIMIT = 3800;

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

	/** @var string Parameter passed after a bot command (e.g. "grp_-100123" in "/start grp_-100123"). */
	public string $command_param = '';


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
		$text = sanitize_textarea_field( (string) $text );
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

		if ( strlen( $command ) > 200 ) {
			$this->send_message( __( 'Too long command', 'makarski-bot-connector-for-telegram' ) );
			return;
		}

		// Split "command param" into base command and optional parameter.
		$parts               = explode( ' ', $command, 2 );
		$base_command        = $parts[0];
		$this->command_param = isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '';

		if ( method_exists( $this, 'command_' . $base_command ) ) {
			call_user_func( array( $this, 'command_' . $base_command ) );
		} else {
			$this->send_message( 'Unknown command: ' . $base_command );
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
	 * Text longer than the Telegram limit (4096 UTF-16 code units) is split
	 * into several messages at paragraph/line/word boundaries; HTML tags left
	 * open at a boundary are closed and re-opened so each chunk stays valid.
	 * $reply_markup is attached to the last chunk, $reply_to_message_id to the
	 * first one. The returned response is the one of the last sent chunk.
	 *
	 * @param string      $message              Message text (HTML allowed).
	 * @param string      $chat_id              Target chat ID; defaults to current chat.
	 * @param array|null  $reply_markup         Optional inline keyboard markup.
	 * @param int|null    $reply_to_message_id  If set, sends the message as a reply to this message ID.
	 * @return mixed
	 */
	public function send_message( $message, string $chat_id = '', $reply_markup = null, ?int $reply_to_message_id = null ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		// Telegram rejects longer texts with "Bad Request: message is too
		// long" and the message would be silently lost — send in chunks.
		$chunks     = self::balance_html_chunks( self::split_long_text( (string) $message, self::SPLIT_LIMIT ) );
		$last_index = count( $chunks ) - 1;
		$result     = null;

		foreach ( $chunks as $i => $chunk ) {
			$data = array(
				'chat_id'    => $chat_id,
				'text'       => $chunk,
				'parse_mode' => 'HTML',
			);

			if ( $reply_markup && $i === $last_index ) {
				$data['reply_markup'] = wp_json_encode( $reply_markup );
			}

			if ( $reply_to_message_id && 0 === $i ) {
				$data['reply_parameters'] = wp_json_encode( array( 'message_id' => $reply_to_message_id ) );
			}

			$result = $this->send_request( $this->api_url . 'sendMessage', $data );
		}

		return $result;
	}

	/**
	 * Send a plain-text message (no parse_mode — Telegram treats it as plain text).
	 *
	 * @param string $message  Message text.
	 * @param string $chat_id  Target chat ID; defaults to current chat.
	 * @return mixed
	 */
	public function send_plain_message( $message, string $chat_id = '' ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		// Over-limit text is auto-split — see send_message().
		$result = null;

		foreach ( self::split_long_text( (string) $message, self::SPLIT_LIMIT ) as $chunk ) {
			$result = $this->send_request(
				$this->api_url . 'sendMessage',
				array(
					'chat_id' => $chat_id,
					'text'    => $chunk,
				)
			);
		}

		return $result;
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

		// Over-limit text is auto-split at paragraph/line/word boundaries
		// (a MarkdownV2 entity spanning a paragraph break may still be cut —
		// unlike HTML chunks, Markdown chunks are not re-balanced).
		$chunks     = self::split_long_text( $this->escape_markdown_v2( $message ), self::SPLIT_LIMIT );
		$last_index = count( $chunks ) - 1;
		$result     = null;

		foreach ( $chunks as $i => $chunk ) {
			$data = array(
				'chat_id'    => $chat_id,
				'text'       => $chunk,
				'parse_mode' => 'MarkdownV2',
			);

			if ( $reply_markup && $i === $last_index ) {
				$data['reply_markup'] = wp_json_encode( $reply_markup );
			}

			$result = $this->send_request( $this->api_url . 'sendMessage', $data );
		}

		return $result;
	}

	/**
	 * Text length in UTF-16 code units — the units Telegram's 4096 limit is
	 * measured in (an emoji outside the BMP counts as 2, unlike mb_strlen()).
	 *
	 * @param string $text Text to measure.
	 * @return int Length in UTF-16 code units.
	 */
	private static function utf16_length( string $text ): int {
		return (int) ( strlen( mb_convert_encoding( $text, 'UTF-16BE', 'UTF-8' ) ) / 2 );
	}

	/**
	 * Split text into chunks of at most $limit UTF-16 code units, preferring
	 * paragraph breaks, then line breaks, then spaces; a single oversized
	 * word is hard-cut as a last resort.
	 *
	 * @param string $text  Text to split.
	 * @param int    $limit Chunk limit in UTF-16 code units.
	 * @return string[] Chunks in original order.
	 */
	private static function split_long_text( string $text, int $limit ): array {
		return self::split_recursive( $text, $limit, array( "\n\n", "\n", ' ' ) );
	}

	/**
	 * @param string   $text       Text to split.
	 * @param int      $limit      Chunk limit in UTF-16 code units.
	 * @param string[] $separators Boundary preference order, best first.
	 * @return string[]
	 */
	private static function split_recursive( string $text, int $limit, array $separators ): array {
		if ( self::utf16_length( $text ) <= $limit ) {
			return array( $text );
		}

		if ( empty( $separators ) ) {
			return self::hard_cut( $text, $limit );
		}

		$sep   = array_shift( $separators );
		$parts = explode( $sep, $text );

		if ( ' ' === $sep ) {
			$parts = self::merge_broken_tags( $parts );
		}

		if ( count( $parts ) === 1 ) {
			return self::split_recursive( $text, $limit, $separators );
		}

		$chunks  = array();
		$current = '';

		foreach ( $parts as $part ) {
			$candidate = ( '' === $current ) ? $part : $current . $sep . $part;

			if ( self::utf16_length( $candidate ) <= $limit ) {
				$current = $candidate;
				continue;
			}

			if ( '' !== $current ) {
				$chunks[] = $current;
			}

			if ( self::utf16_length( $part ) <= $limit ) {
				$current = $part;
			} else {
				$sub     = self::split_recursive( $part, $limit, $separators );
				$current = array_pop( $sub );
				$chunks  = array_merge( $chunks, $sub );
			}
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Re-join space-separated tokens while the last '<' in the buffer is not
	 * yet closed by '>', so a word-level split can't cut inside an HTML tag
	 * such as <a href="…">.
	 *
	 * @param string[] $parts Tokens produced by explode( ' ', … ).
	 * @return string[]
	 */
	private static function merge_broken_tags( array $parts ): array {
		$merged = array();
		$buffer = '';

		foreach ( $parts as $part ) {
			$buffer = ( '' === $buffer ) ? $part : $buffer . ' ' . $part;

			$lt = strrpos( $buffer, '<' );
			$gt = strrpos( $buffer, '>' );

			if ( false !== $lt && ( false === $gt || $lt > $gt ) ) {
				continue; // Inside an unfinished tag — keep merging.
			}

			$merged[] = $buffer;
			$buffer   = '';
		}

		if ( '' !== $buffer ) {
			$merged[] = $buffer;
		}

		return $merged;
	}

	/**
	 * Cut text into chunks of at most $limit UTF-16 code units without
	 * splitting a surrogate pair (i.e. never inside a single character).
	 *
	 * @param string $text  Text to cut.
	 * @param int    $limit Chunk limit in UTF-16 code units.
	 * @return string[]
	 */
	private static function hard_cut( string $text, int $limit ): array {
		$chunks = array();
		$u16    = mb_convert_encoding( $text, 'UTF-16BE', 'UTF-8' );

		while ( strlen( $u16 ) > $limit * 2 ) {
			$bytes = $limit * 2;
			$unit  = unpack( 'n', substr( $u16, $bytes - 2, 2 ) )[1];

			if ( $unit >= 0xD800 && $unit <= 0xDBFF ) { // High surrogate — keep the pair together.
				$bytes -= 2;
			}

			$chunks[] = mb_convert_encoding( substr( $u16, 0, $bytes ), 'UTF-8', 'UTF-16BE' );
			$u16      = substr( $u16, $bytes );
		}

		$chunks[] = mb_convert_encoding( $u16, 'UTF-8', 'UTF-16BE' );

		return $chunks;
	}

	/**
	 * Close HTML tags left open at the end of each chunk and re-open them at
	 * the start of the next one, so parse_mode=HTML stays valid per message.
	 * No-op for a single chunk (unsplit text is sent byte-identical).
	 *
	 * @param string[] $chunks Chunks produced by split_long_text().
	 * @return string[]
	 */
	private static function balance_html_chunks( array $chunks ): array {
		if ( count( $chunks ) < 2 ) {
			return $chunks;
		}

		$supported = array( 'b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del', 'a', 'code', 'pre', 'span', 'tg-spoiler', 'blockquote' );
		$stack     = array(); // Open tags carried across chunks: name + full opening tag markup.

		foreach ( $chunks as $idx => $chunk ) {
			$prefix = implode( '', array_column( $stack, 'html' ) );

			if ( preg_match_all( '#<(/?)([a-z][a-z0-9-]*)(\s[^>]*)?>#i', $chunk, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $m ) {
					$name = strtolower( $m[2] );

					if ( ! in_array( $name, $supported, true ) ) {
						continue;
					}

					if ( '/' === $m[1] ) {
						for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
							if ( $stack[ $i ]['name'] === $name ) {
								array_splice( $stack, $i, 1 );
								break;
							}
						}
					} else {
						$stack[] = array(
							'name' => $name,
							'html' => $m[0],
						);
					}
				}
			}

			$suffix = '';
			for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
				$suffix .= '</' . $stack[ $i ]['name'] . '>';
			}

			$chunks[ $idx ] = $prefix . $chunk . $suffix;
		}

		return $chunks;
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
	 * @param string      $photo_path   Absolute path to the image file.
	 * @param string|null $caption      Optional caption (HTML supported).
	 * @param string      $chat_id      Target chat ID; defaults to current chat.
	 * @param array|null  $reply_markup Optional inline keyboard markup.
	 * @return mixed
	 */
	public function send_photo( $photo_path, $caption = null, string $chat_id = '', ?array $reply_markup = null ): mixed {
		if ( '' === $chat_id ) {
			$chat_id = $this->chat_id;
		}

		$data = array(
			'chat_id'    => $chat_id,
			'photo'      => new \CURLFile( realpath( $photo_path ) ),
			'caption'    => $caption,
			'parse_mode' => 'HTML',
		);

		if ( $reply_markup !== null ) {
			$data['reply_markup'] = wp_json_encode( $reply_markup );
		}

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
	public function send_document( string $document_path, ?string $caption = null, string $chat_id = '' ): mixed {
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
	public function set_webhook( string $url, string $secret_token = '' ): mixed {
		$params = array( 'url' => $url );
		if ( $secret_token ) {
			$params['secret_token'] = $secret_token;
		}
		return $this->send_request( $this->api_url . 'setWebhook', $params );
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
	 * Get the bot's own info (username, id, etc.). Result is cached for 24 hours.
	 *
	 * @return object|null Bot user object, or null on error.
	 */
	public function get_me(): ?object {
		$cache_key = 'tgbot_get_me_' . substr( md5( $this->token ), 0, 8 );
		$cached    = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}

		$response = $this->send_request( $this->api_url . 'getMe' );

		if ( ! empty( $response->ok ) && isset( $response->result ) ) {
			set_transient( $cache_key, $response->result, DAY_IN_SECONDS );
			return $response->result;
		}

		return null;
	}

	/**
	 * Get information about a member of a chat.
	 * Returns a ChatMember object, or null on failure.
	 * The `status` field is one of: creator, administrator, member, restricted, left, kicked.
	 *
	 * @param string $chat_id  Telegram chat ID (group: negative number as string).
	 * @param int    $user_id  Telegram user ID.
	 */
	public function get_chat_member( string $chat_id, int $user_id ): ?object {
		$response = $this->send_request( $this->api_url . 'getChatMember', [
			'chat_id' => $chat_id,
			'user_id' => $user_id,
		] );

		return ( ! empty( $response->ok ) && isset( $response->result ) ) ? $response->result : null;
	}

	/**
	 * Read and parse the incoming webhook request from Telegram.
	 *
	 * @return object|false
	 */
	public function get_request(): object|false {
		$secret = get_option( 'tgbot_webhook_secret', '' );
		if ( ! $secret ) {
			return false;
		}
		$received = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		if ( ! hash_equals( $secret, $received ) ) {
			return false;
		}

		$input = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( empty( $input ) ) {
			return false;
		}

		$this->request_respond = json_decode( $input );

		if ( ! is_object( $this->request_respond ) || ! isset( $this->request_respond->update_id ) ) {
			return false;
		}

		// Sanitize end-user text fields before they are exposed via action hooks.
		if ( isset( $this->request_respond->message->text ) ) {
			$this->request_respond->message->text = sanitize_textarea_field( $this->request_respond->message->text );
		}
		if ( isset( $this->request_respond->message->caption ) ) {
			$this->request_respond->message->caption = sanitize_textarea_field( $this->request_respond->message->caption );
		}
		if ( isset( $this->request_respond->callback_query->data ) ) {
			$this->request_respond->callback_query->data = sanitize_text_field( $this->request_respond->callback_query->data );
		}

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

		if ( ! $chat_id ) {
			$chat_id = $this->request_respond->my_chat_member->chat->id ?? null;
		}

		if ( $chat_id ) {
			// Use intval, not absint — group chat IDs are negative numbers.
			$this->chat_id = (string) intval( $chat_id );
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
	/**
	 * When the bot is disabled (gen_tg_enabled off / tgbot_can_send() false),
	 * suppress user-facing API calls and prime last_request_response with a
	 * distinguishable object: ok=false plus tgbot_disabled=true, so consumers
	 * can tell "bot is off" from "Telegram refused" (e.g. a user who blocked
	 * the bot) and not mislabel real people.
	 *
	 * Administrative calls (getMe, webhook management, getUpdates,
	 * setMyCommands, getChatMember, …) are never suppressed — the admin UI
	 * must keep working while the bot is off.
	 *
	 * @param string $url Full API URL of the call.
	 */
	private function is_sending_suppressed( string $url ): bool {
		if ( tgbot_can_send() || ! self::is_user_facing_method( $url ) ) {
			return false;
		}

		$this->last_request_response = (object) array(
			'ok'             => false,
			'error_code'     => 0,
			'tgbot_disabled' => true,
			'description'    => 'Sending suppressed: the bot is disabled in Telegram Bot settings (gen_tg_enabled).',
		);

		return true;
	}

	/**
	 * Whether the API method delivers content to chats (as opposed to
	 * administrative calls, which must work while the bot is disabled).
	 *
	 * @param string $url Full API URL of the call.
	 */
	private static function is_user_facing_method( string $url ): bool {
		$method = strtolower( (string) preg_replace( '#^.*/#', '', (string) strtok( $url, '?' ) ) );

		if ( 'deletewebhook' === $method ) {
			return false; // Administrative despite the prefix.
		}

		// refundStarPayment is intentionally not listed: refunding money is an
		// administrative action that must work while the bot is off.
		foreach ( array( 'send', 'edit', 'delete', 'forward', 'copy', 'answer', 'pin', 'unpin' ) as $prefix ) {
			if ( str_starts_with( $method, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	private function send_request( string $url, array $data = [] ): mixed {
		if ( $this->is_sending_suppressed( $url ) ) {
			return $this->last_request_response;
		}

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
	 * Send a multipart/form-data request for file uploads (send_photo, send_document, etc.).
	 * Uses wp_remote_post() with the http_api_curl hook to inject CURLFile data,
	 * as permitted by https://developer.wordpress.org/reference/hooks/http_api_curl/.
	 *
	 * @param string $url  Full API endpoint URL.
	 * @param array  $data Request parameters including CURLFile objects.
	 * @return mixed Decoded response object.
	 */
	private function send_multipart_request( string $url, array $data ): mixed {
		if ( $this->is_sending_suppressed( $url ) ) {
			return $this->last_request_response;
		}

		$multipart_data = $data;

		$inject_multipart = function ( $handle ) use ( $multipart_data ) {
			curl_setopt( $handle, CURLOPT_POSTFIELDS, $multipart_data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		};

		add_action( 'http_api_curl', $inject_multipart );

		$response = wp_remote_post( $url, array( 'timeout' => 30 ) );

		remove_action( 'http_api_curl', $inject_multipart );

		if ( is_wp_error( $response ) ) {
			error_log( '[TGBot ERROR] ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return (object) array( 'ok' => false, 'description' => $response->get_error_message() );
		}

		$body                        = wp_remote_retrieve_body( $response );
		$this->last_request_response = json_decode( $body );

		if ( empty( $this->last_request_response ) ) {
			$this->last_request_response = (object) array( 'ok' => false, 'description' => 'Invalid JSON response' );
			return $this->last_request_response;
		}

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

		if ( isset( $message->photo ) && is_array( $message->photo ) && ! empty( $message->photo ) ) {
			return $this->get_photo_url( $message );
		}

		$file_id = '';
		$types   = array( 'document', 'video', 'audio', 'voice', 'video_note', 'sticker' );

		foreach ( $types as $type ) {
			if ( isset( $message->$type ) ) {
				$file_id = $message->$type->file_id ?? '';
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

		if ( ! isset( $message->photo ) || ! is_array( $message->photo ) || empty( $message->photo ) ) {
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
