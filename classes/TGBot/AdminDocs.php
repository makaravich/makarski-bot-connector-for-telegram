<?php

namespace TGBot;

/**
 * Documentation admin page: renders the bundled README.md right in wp-admin,
 * with a link to the GitHub copy. Rendering the local file (instead of just
 * linking out) keeps the docs available to any WP.org user regardless of
 * where the code lives.
 *
 * The renderer is a deliberately small Markdown subset — exactly what
 * README.md uses: headings, fenced code, tables, lists, blockquotes,
 * bold/italic/inline code, links, horizontal rules. Everything is escaped
 * before inline markup is applied.
 */
class AdminDocs {

	const PAGE_SLUG  = 'tgbot_docs';
	const GITHUB_URL = 'https://github.com/makaravich/makarski-bot-connector-for-telegram#readme';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
	}

	public static function add_page(): void {
		add_submenu_page(
			'tgbot_options-options',
			__( 'Telegram Bot Documentation', 'makarski-bot-connector-for-telegram' ),
			__( 'Documentation', 'makarski-bot-connector-for-telegram' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$readme = TGBOT_PLUGIN_BASEPATH . 'README.md';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled file
		$markdown = is_readable( $readme ) ? (string) file_get_contents( $readme ) : '';
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<?php esc_html_e( 'Documentation', 'makarski-bot-connector-for-telegram' ); ?>
				<a href="<?php echo esc_url( self::GITHUB_URL ); ?>" target="_blank" rel="noopener noreferrer" class="button">
					<?php esc_html_e( 'Open on GitHub', 'makarski-bot-connector-for-telegram' ); ?> ↗
				</a>
			</h1>

			<?php if ( '' === $markdown ) : ?>
				<p><?php esc_html_e( 'README.md was not found in the plugin folder.', 'makarski-bot-connector-for-telegram' ); ?></p>
			<?php else : ?>
				<style>
					.tgbot-docs { max-width: 860px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 24px 32px; line-height: 1.6; }
					.tgbot-docs h1 { font-size: 26px; padding: 0; margin: 16px 0; }
					.tgbot-docs h2 { font-size: 20px; margin: 28px 0 12px; padding-bottom: 6px; border-bottom: 1px solid #dcdcde; }
					.tgbot-docs h3 { font-size: 16px; margin: 22px 0 8px; }
					.tgbot-docs pre { background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 16px; overflow-x: auto; }
					.tgbot-docs code { background: #f0f0f1; padding: 1px 5px; border-radius: 3px; font-size: 13px; }
					.tgbot-docs pre code { background: none; padding: 0; }
					.tgbot-docs table { border-collapse: collapse; margin: 12px 0; width: 100%; }
					.tgbot-docs th, .tgbot-docs td { border: 1px solid #dcdcde; padding: 6px 10px; text-align: left; vertical-align: top; }
					.tgbot-docs th { background: #f6f7f7; }
					.tgbot-docs blockquote { border-left: 4px solid #dcdcde; margin: 12px 0; padding: 4px 16px; color: #50575e; }
					.tgbot-docs hr { border: 0; border-top: 1px solid #dcdcde; margin: 24px 0; }
				</style>
				<div class="tgbot-docs">
					<?php echo self::render_markdown( $markdown ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from an escaped bundled file by render_markdown() ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Minimal Markdown renderer
	// ---------------------------------------------------------------------------

	/**
	 * Convert the Markdown subset used by README.md to HTML. All source text
	 * is HTML-escaped before inline markup is applied.
	 */
	public static function render_markdown( string $markdown ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $markdown );
		$html  = '';
		$i     = 0;
		$count = count( $lines );

		while ( $i < $count ) {
			$line = $lines[ $i ];

			// Fenced code block.
			if ( preg_match( '/^```/', $line ) ) {
				$code = [];
				$i++;
				while ( $i < $count && ! preg_match( '/^```/', $lines[ $i ] ) ) {
					$code[] = $lines[ $i ];
					$i++;
				}
				$i++; // Skip closing fence.
				$html .= '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>';
				continue;
			}

			// Table: consecutive lines starting with '|'.
			if ( str_starts_with( trim( $line ), '|' ) ) {
				$rows = [];
				while ( $i < $count && str_starts_with( trim( $lines[ $i ] ), '|' ) ) {
					$rows[] = trim( $lines[ $i ] );
					$i++;
				}
				$html .= self::render_table( $rows );
				continue;
			}

			// Horizontal rule.
			if ( preg_match( '/^-{3,}\s*$/', $line ) ) {
				$html .= '<hr />';
				$i++;
				continue;
			}

			// Heading.
			if ( preg_match( '/^(#{1,4})\s+(.*)$/', $line, $m ) ) {
				$level = strlen( $m[1] );
				$html .= "<h{$level}>" . self::inline( $m[2] ) . "</h{$level}>";
				$i++;
				continue;
			}

			// Blockquote.
			if ( preg_match( '/^>\s?(.*)$/', $line, $m ) ) {
				$quote = [];
				while ( $i < $count && preg_match( '/^>\s?(.*)$/', $lines[ $i ], $qm ) ) {
					$quote[] = $qm[1];
					$i++;
				}
				$html .= '<blockquote><p>' . self::inline( implode( ' ', $quote ) ) . '</p></blockquote>';
				continue;
			}

			// Lists (unordered '- ' / ordered '1. '), with simple indent nesting.
			if ( preg_match( '/^(\s*)(-|\d+\.)\s+/', $line ) ) {
				$items   = [];
				$ordered = (bool) preg_match( '/^\s*\d+\./', $line );
				while ( $i < $count && preg_match( '/^(\s*)(-|\d+\.)\s+(.*)$/', $lines[ $i ], $lm ) ) {
					$items[] = self::inline( $lm[3] );
					$i++;
					// A continuation line (indented, not a new item) joins the previous item.
					while ( $i < $count && preg_match( '/^\s{2,}(?![-\d])(\S.*)$/', $lines[ $i ], $cm ) && ! str_starts_with( trim( $lines[ $i ] ), '|' ) ) {
						$items[ count( $items ) - 1 ] .= ' ' . self::inline( trim( $cm[1] ) );
						$i++;
					}
				}
				$tag   = $ordered ? 'ol' : 'ul';
				$html .= "<{$tag}><li>" . implode( '</li><li>', $items ) . "</li></{$tag}>";
				continue;
			}

			// Blank line.
			if ( '' === trim( $line ) ) {
				$i++;
				continue;
			}

			// Paragraph: accumulate until a blank line or a block construct.
			$para = [];
			while ( $i < $count && '' !== trim( $lines[ $i ] )
				&& ! preg_match( '/^(#{1,4}\s|```|>|-{3,}\s*$|(\s*)(-|\d+\.)\s)/', $lines[ $i ] )
				&& ! str_starts_with( trim( $lines[ $i ] ), '|' ) ) {
				$para[] = trim( $lines[ $i ] );
				$i++;
			}

			if ( $para ) {
				$html .= '<p>' . self::inline( implode( ' ', $para ) ) . '</p>';
			} else {
				$i++; // Defensive: never stall on an unmatched line.
			}
		}

		return $html;
	}

	/** Render a Markdown table from its raw '|'-prefixed lines. */
	private static function render_table( array $rows ): string {
		$html   = '<table>';
		$header = true;

		foreach ( $rows as $row ) {
			// Separator row (|---|---|) switches header → body.
			if ( preg_match( '/^\|[\s:|-]+\|$/', $row ) ) {
				$header = false;
				continue;
			}

			$cells = array_map( 'trim', explode( '|', trim( $row, '|' ) ) );
			$tag   = $header ? 'th' : 'td';
			$html .= '<tr><' . $tag . '>' . implode( "</{$tag}><{$tag}>", array_map( [ self::class, 'inline' ], $cells ) ) . "</{$tag}></tr>";
		}

		return $html . '</table>';
	}

	/**
	 * Inline markup on one already-plain string: escape HTML first, then
	 * apply code / bold / italic / links.
	 */
	private static function inline( string $text ): string {
		$text = esc_html( $text );

		// Markdown escapes like \| inside table cells.
		$text = str_replace( [ '\|', '\*', '\`' ], [ '&#124;', '&#42;', '&#96;' ], $text );

		// Inline code first — its content must not get bold/link processing.
		$text = preg_replace_callback(
			'/`([^`]+)`/',
			static fn( $m ) => '<code>' . $m[1] . '</code>',
			$text
		);

		// Links: [text](url) — allow only http(s) URLs and #anchors.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( $m ) {
				$url = $m[2];
				if ( ! preg_match( '/^(https?:\/\/|#)/', $url ) ) {
					return $m[0];
				}
				$target = str_starts_with( $url, '#' ) ? '' : ' target="_blank" rel="noopener noreferrer"';
				return '<a href="' . esc_url( $url ) . '"' . $target . '>' . $m[1] . '</a>';
			},
			$text
		);

		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/(?<![\w*])\*([^*\s][^*]*)\*(?![\w*])/', '<em>$1</em>', $text );

		return $text;
	}
}
