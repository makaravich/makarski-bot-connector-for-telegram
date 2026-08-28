<?php

namespace TGBot;

/**
 * Bot analytics: one admin page, multiple tabs.
 *
 * Tabs are registered through the 'tgbot_analytics_tabs' filter, so consumer
 * plugins can add their own (funnel, revenue, …) next to the built-in ones.
 */
class AdminAnalytics {

	const PAGE_SLUG = 'tgbot_analytics';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
	}

	public static function add_page(): void {
		add_submenu_page(
			'tgbot_options-options',
			__( 'Telegram Bot Analytics', 'makarski-bot-connector-for-telegram' ),
			__( 'Analytics', 'makarski-bot-connector-for-telegram' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	/**
	 * Built-in tabs, extensible by consumer plugins:
	 *
	 *     add_filter( 'tgbot_analytics_tabs', function ( $tabs ) {
	 *         $tabs['funnel'] = [ 'label' => 'Funnel', 'render' => 'my_render_cb' ];
	 *         return $tabs;
	 *     } );
	 *
	 * @return array<string, array{label: string, render: callable}>
	 */
	private static function tabs(): array {
		$tabs = [
			'overview'  => [
				'label'  => __( 'Overview', 'makarski-bot-connector-for-telegram' ),
				'render' => [ self::class, 'render_overview' ],
			],
			'commands'  => [
				'label'  => __( 'Commands', 'makarski-bot-connector-for-telegram' ),
				'render' => [ self::class, 'render_commands' ],
			],
			'delivery'  => [
				'label'  => __( 'Delivery', 'makarski-bot-connector-for-telegram' ),
				'render' => [ self::class, 'render_delivery' ],
			],
			'sources'   => [
				'label'  => __( 'Sources', 'makarski-bot-connector-for-telegram' ),
				'render' => [ self::class, 'render_sources' ],
			],
			'languages' => [
				'label'  => __( 'Languages', 'makarski-bot-connector-for-telegram' ),
				'render' => [ self::class, 'render_languages' ],
			],
			'referrals' => [
				'label'  => __( 'Referrals', 'makarski-bot-connector-for-telegram' ),
				'render' => [ self::class, 'render_referrals' ],
			],
		];

		$tabs = apply_filters( 'tgbot_analytics_tabs', $tabs );

		// Keep only well-formed entries so a broken consumer filter can't fatal the page.
		return array_filter(
			$tabs,
			static fn( $tab ) => is_array( $tab ) && isset( $tab['label'], $tab['render'] ) && is_callable( $tab['render'] )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs   = self::tabs();
		$active = sanitize_key( wp_unslash( $_GET['tab'] ?? 'overview' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch

		if ( ! isset( $tabs[ $active ] ) ) {
			$active = (string) array_key_first( $tabs );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Telegram Bot Analytics', 'makarski-bot-connector-for-telegram' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo $slug === $active ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="tgbot-analytics-tab" style="margin-top:16px;">
				<?php call_user_func( $tabs[ $active ]['render'] ); ?>
			</div>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Rendering helpers — public API for consumer tabs
	//
	// Plugins that register their own tabs via 'tgbot_analytics_tabs' should
	// build them from these helpers so every tab looks consistent:
	//
	//     AdminAnalytics::cards( [ 'Paid users' => 42, 'MRR' => '€120' ] );
	//     AdminAnalytics::table(
	//         [ 'Step', 'Users', '%' ],
	//         array_map( fn( $r ) => [ $r->step, $r->users, $r->pct . '%' ], $rows )
	//     );
	// ---------------------------------------------------------------------------

	/** Render one stat card (label above a large value). Escapes both. */
	public static function card( string $label, string $value ): void {
		?>
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 16px;min-width:140px;">
			<div style="font-size:12px;color:#646970;"><?php echo esc_html( $label ); ?></div>
			<div style="font-size:24px;font-weight:600;line-height:1.4;"><?php echo esc_html( $value ); ?></div>
		</div>
		<?php
	}

	/**
	 * Render a row of stat cards from a label => value map.
	 *
	 * @param array<string, scalar> $stats Label => value.
	 */
	public static function cards( array $stats ): void {
		?>
		<div style="display:flex;flex-wrap:wrap;gap:12px;">
			<?php
			foreach ( $stats as $label => $value ) {
				self::card( (string) $label, (string) $value );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render a striped admin table. All cell content is escaped — pass plain
	 * strings/numbers, not markup.
	 *
	 * @param string[]   $headers   Column headers.
	 * @param array[]    $rows      List of rows; each row is a list of cells.
	 * @param string     $max_width Optional CSS max-width (e.g. '640px', '' = none).
	 */
	public static function table( array $headers, array $rows, string $max_width = '760px' ): void {
		$style = $max_width ? 'max-width:' . $max_width . ';' : '';
		?>
		<table class="wp-list-table widefat striped" style="<?php echo esc_attr( $style ); ?>">
			<thead><tr>
				<?php foreach ( $headers as $h ) : ?>
					<th><?php echo esc_html( (string) $h ); ?></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<?php foreach ( (array) $row as $cell ) : ?>
						<td><?php echo esc_html( (string) $cell ); ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Built-in tab renderers
	// ---------------------------------------------------------------------------

	public static function render_overview(): void {
		$o = Analytics::overview();
		?>
		<div style="display:flex;flex-wrap:wrap;gap:12px;">
			<?php
			self::card( __( 'Total chats', 'makarski-bot-connector-for-telegram' ), (string) ( $o['total'] ?? 0 ) );
			self::card( __( 'Groups', 'makarski-bot-connector-for-telegram' ), (string) ( $o['groups_cnt'] ?? 0 ) );
			self::card( __( 'New today', 'makarski-bot-connector-for-telegram' ), (string) ( $o['new_today'] ?? 0 ) );
			self::card( __( 'New (7 days)', 'makarski-bot-connector-for-telegram' ), (string) ( $o['new_week'] ?? 0 ) );
			self::card( __( 'New (30 days)', 'makarski-bot-connector-for-telegram' ), (string) ( $o['new_month'] ?? 0 ) );
			self::card( __( 'Active (7 days)', 'makarski-bot-connector-for-telegram' ), (string) ( $o['active_week'] ?? 0 ) );
			self::card( __( 'Blocked the bot', 'makarski-bot-connector-for-telegram' ), (string) ( $o['blocked'] ?? 0 ) );
			?>
		</div>
		<p class="description" style="margin-top:12px;">
			<?php esc_html_e( 'Counters come from the analytics registry, which fills as chats contact the bot. Times are stored in UTC; "today" follows the site timezone.', 'makarski-bot-connector-for-telegram' ); ?>
		</p>
		<?php
	}

	public static function render_commands(): void {
		$rows = Analytics::command_stats( 30 );

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No command usage recorded in the last 30 days.', 'makarski-bot-connector-for-telegram' ) . '</p>';
			return;
		}
		?>
		<h2><?php esc_html_e( 'Command usage — last 30 days', 'makarski-bot-connector-for-telegram' ); ?></h2>
		<table class="wp-list-table widefat striped" style="max-width:640px;">
			<thead><tr>
				<th><?php esc_html_e( 'Command', 'makarski-bot-connector-for-telegram' ); ?></th>
				<th><?php esc_html_e( 'Uses', 'makarski-bot-connector-for-telegram' ); ?></th>
				<th><?php esc_html_e( 'Unique chats', 'makarski-bot-connector-for-telegram' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><code>/<?php echo esc_html( $r->cmd ); ?></code></td>
					<td><?php echo esc_html( $r->uses ); ?></td>
					<td><?php echo esc_html( $r->users ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function render_delivery(): void {
		$blocked    = Analytics::blocked_users();
		$deliveries = Analytics::recent_deliveries();
		?>
		<h2><?php esc_html_e( 'Chats that blocked the bot', 'makarski-bot-connector-for-telegram' ); ?></h2>
		<?php if ( ! $blocked ) : ?>
			<p><?php esc_html_e( 'Nobody is currently marked as blocked.', 'makarski-bot-connector-for-telegram' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat striped" style="max-width:760px;">
				<thead><tr>
					<th><?php esc_html_e( 'Chat ID', 'makarski-bot-connector-for-telegram' ); ?></th>
					<th><?php esc_html_e( 'WP user', 'makarski-bot-connector-for-telegram' ); ?></th>
					<th><?php esc_html_e( 'Blocked at (UTC)', 'makarski-bot-connector-for-telegram' ); ?></th>
					<th><?php esc_html_e( 'Last seen (UTC)', 'makarski-bot-connector-for-telegram' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $blocked as $b ) : ?>
					<tr>
						<td><?php echo esc_html( $b->chat_id ); ?></td>
						<td>
							<?php if ( $b->wp_user_id ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( (int) $b->wp_user_id ) ); ?>">#<?php echo esc_html( $b->wp_user_id ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $b->blocked_at ); ?></td>
						<td><?php echo esc_html( $b->last_seen ?: '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 style="margin-top:24px;"><?php esc_html_e( 'Recent failed / suppressed sends', 'makarski-bot-connector-for-telegram' ); ?></h2>
		<?php if ( ! $deliveries ) : ?>
			<p><?php esc_html_e( 'No failed sends recorded. Successful sends are not logged.', 'makarski-bot-connector-for-telegram' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Time (UTC)', 'makarski-bot-connector-for-telegram' ); ?></th>
					<th><?php esc_html_e( 'Chat ID', 'makarski-bot-connector-for-telegram' ); ?></th>
					<th><?php esc_html_e( 'Method', 'makarski-bot-connector-for-telegram' ); ?></th>
					<th><?php esc_html_e( 'Status', 'makarski-bot-connector-for-telegram' ); ?></th>
					<th><?php esc_html_e( 'Error', 'makarski-bot-connector-for-telegram' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $deliveries as $d ) : ?>
					<tr>
						<td style="white-space:nowrap;"><?php echo esc_html( $d->created_at ); ?></td>
						<td><?php echo esc_html( $d->chat_id ); ?></td>
						<td><code><?php echo esc_html( $d->method ); ?></code></td>
						<td><?php echo esc_html( $d->status ); ?></td>
						<td><?php echo esc_html( $d->error ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;
	}

	public static function render_sources(): void {
		$rows = Analytics::source_stats();
		?>
		<h2><?php esc_html_e( 'Acquisition sources', 'makarski-bot-connector-for-telegram' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'First-touch attribution from deep links: t.me/<bot>?start=<code>. Recorded once at first contact and never overwritten. Chats registered before tracking existed show as "(pre-tracking)".', 'makarski-bot-connector-for-telegram' ); ?>
		</p>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'No chats in the registry yet.', 'makarski-bot-connector-for-telegram' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="wp-list-table widefat striped" style="max-width:760px;">
			<thead><tr>
				<th><?php esc_html_e( 'Source', 'makarski-bot-connector-for-telegram' ); ?></th>
				<th><?php esc_html_e( 'Chats', 'makarski-bot-connector-for-telegram' ); ?></th>
				<th><?php esc_html_e( 'New (30 days)', 'makarski-bot-connector-for-telegram' ); ?></th>
				<th><?php esc_html_e( 'Blocked', 'makarski-bot-connector-for-telegram' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><code><?php echo esc_html( '' === $r->source ? '(pre-tracking)' : $r->source ); ?></code></td>
					<td><?php echo esc_html( $r->users ); ?></td>
					<td><?php echo esc_html( (int) $r->new_month ); ?></td>
					<td><?php echo esc_html( (int) $r->blocked ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function render_languages(): void {
		$rows = Analytics::language_stats();
		?>
		<h2><?php esc_html_e( 'Arrival languages', 'makarski-bot-connector-for-telegram' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The raw Telegram language_code each chat arrived with, captured before any locale mapping — shows demand for languages the bot may not support yet.', 'makarski-bot-connector-for-telegram' ); ?>
		</p>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'No chats in the registry yet.', 'makarski-bot-connector-for-telegram' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="wp-list-table widefat striped" style="max-width:420px;">
			<thead><tr>
				<th><?php esc_html_e( 'Language code', 'makarski-bot-connector-for-telegram' ); ?></th>
				<th><?php esc_html_e( 'Chats', 'makarski-bot-connector-for-telegram' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><code><?php echo esc_html( '' === $r->tg_language ? '(unknown)' : $r->tg_language ); ?></code></td>
					<td><?php echo esc_html( $r->users ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function render_referrals(): void {
		$stats = Analytics::referral_stats();
		?>
		<div style="display:flex;flex-wrap:wrap;gap:12px;">
			<?php
			self::card( __( 'Total referrals', 'makarski-bot-connector-for-telegram' ), (string) $stats['total'] );
			self::card( __( 'Last 30 days', 'makarski-bot-connector-for-telegram' ), (string) $stats['month'] );
			?>
		</div>
		<p class="description" style="margin-top:12px;">
			<?php esc_html_e( 'Referrals are recorded when a new chat registers via a ?start=ref_<code> deep link. Grant rewards in your plugin via the tgbot_referral_completed action.', 'makarski-bot-connector-for-telegram' ); ?>
		</p>
		<?php if ( $stats['top'] ) : ?>
			<h2 style="margin-top:16px;"><?php esc_html_e( 'Top referrers', 'makarski-bot-connector-for-telegram' ); ?></h2>
			<table class="wp-list-table widefat striped" style="max-width:420px;">
				<thead><tr>
					<th><?php esc_html_e( 'Referrer chat ID', 'makarski-bot-connector-for-telegram' ); ?></th>
					<th><?php esc_html_e( 'Invited', 'makarski-bot-connector-for-telegram' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $stats['top'] as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r->referrer_chat_id ); ?></td>
						<td><?php echo esc_html( $r->invited ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;
	}
}
