<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Admin menu registration
// ---------------------------------------------------------------------------

add_action( 'admin_menu', 'tgbot_add_broadcast_page' );

function tgbot_add_broadcast_page(): void {
	add_submenu_page(
		'tgbot_options-options',
		__( 'Broadcast', 'makarski-bot-connector-for-telegram' ),
		__( 'Broadcast', 'makarski-bot-connector-for-telegram' ),
		'manage_options',
		'tgbot_broadcast',
		'tgbot_broadcast_page_output'
	);
}

// ---------------------------------------------------------------------------
// AJAX handlers
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_tgbot_broadcast_send', 'tgbot_ajax_broadcast_send' );
add_action( 'wp_ajax_tgbot_broadcast_progress', 'tgbot_ajax_broadcast_progress' );

/**
 * AJAX: create a new broadcast job.
 */
function tgbot_ajax_broadcast_send(): void {
	check_ajax_referer( 'tgbot_broadcast', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Forbidden', 'makarski-bot-connector-for-telegram' ) ], 403 );
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	$raw_ids  = isset( $_POST['user_ids'] ) ? (array) wp_unslash( $_POST['user_ids'] ) : [];
	$user_ids = array_map( 'absint', $raw_ids );
	$user_ids = array_filter( $user_ids );

	if ( empty( $user_ids ) ) {
		wp_send_json_error( [ 'message' => __( 'No users selected.', 'makarski-bot-connector-for-telegram' ) ] );
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	$raw_messages = isset( $_POST['messages'] ) ? (array) wp_unslash( $_POST['messages'] ) : [];
	$messages     = [];
	foreach ( $raw_messages as $locale => $text ) {
		$locale              = sanitize_text_field( $locale );
		$messages[ $locale ] = sanitize_textarea_field( $text );
	}

	if ( empty( $messages ) ) {
		wp_send_json_error( [ 'message' => __( 'No message text provided.', 'makarski-bot-connector-for-telegram' ) ] );
	}

	$allowed_formats = [ 'plain', 'html', 'markdown' ];
	$format          = sanitize_text_field( wp_unslash( $_POST['format'] ?? 'plain' ) );
	if ( ! in_array( $format, $allowed_formats, true ) ) {
		$format = 'plain';
	}

	$job_id = \TGBot\Broadcast::create_job( $messages, $format, array_values( $user_ids ) );

	if ( false === $job_id ) {
		wp_send_json_error( [ 'message' => __( 'Failed to create broadcast job. Check that selected users have Telegram usernames.', 'makarski-bot-connector-for-telegram' ) ] );
	}

	$progress = \TGBot\Broadcast::get_job_progress( $job_id );

	wp_send_json_success(
		[
			'job_id' => $job_id,
			'total'  => $progress['total'] ?? 0,
		]
	);
}

/**
 * AJAX: get progress for a running broadcast job.
 */
function tgbot_ajax_broadcast_progress(): void {
	check_ajax_referer( 'tgbot_broadcast', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Forbidden', 'makarski-bot-connector-for-telegram' ) ], 403 );
	}

	$job_id = absint( wp_unslash( $_GET['job_id'] ?? 0 ) );

	if ( ! $job_id ) {
		wp_send_json_error( [ 'message' => __( 'Missing job_id.', 'makarski-bot-connector-for-telegram' ) ] );
	}

	$progress = \TGBot\Broadcast::get_job_progress( $job_id );

	if ( null === $progress ) {
		wp_send_json_error( [ 'message' => __( 'Job not found.', 'makarski-bot-connector-for-telegram' ) ] );
	}

	wp_send_json_success( $progress );
}

// ---------------------------------------------------------------------------
// Helper: locale display name
// ---------------------------------------------------------------------------

/**
 * Convert a WP locale code to a human-readable string with flag emoji.
 *
 * @param string $locale  e.g. 'ru_RU', 'en_US'.
 * @return string
 */
function tgbot_locale_label( string $locale ): string {
	$map = [
		'en_US' => '&#127482;&#127480; English',
		'en_GB' => '&#127468;&#127463; English (UK)',
		'ru_RU' => '&#127479;&#127482; Russian',
		'he_IL' => '&#127470;&#127473; Hebrew',
		'de_DE' => '&#127465;&#127466; German',
		'fr_FR' => '&#127467;&#127479; French',
		'es_ES' => '&#127466;&#127480; Spanish',
		'it_IT' => '&#127470;&#127481; Italian',
		'pt_BR' => '&#127463;&#127479; Portuguese (BR)',
		'pt_PT' => '&#127477;&#127481; Portuguese',
		'nl_NL' => '&#127475;&#127473; Dutch',
		'pl_PL' => '&#127477;&#127473; Polish',
		'uk_UA' => '&#127482;&#127462; Ukrainian',
		'tr_TR' => '&#127481;&#127479; Turkish',
		'zh_CN' => '&#127464;&#127475; Chinese (CN)',
		'zh_TW' => '&#127481;&#127484; Chinese (TW)',
		'ja'    => '&#127471;&#127477; Japanese',
		'ko_KR' => '&#127472;&#127479; Korean',
		'ar'    => '&#127462;&#127462; Arabic',
	];

	return $map[ $locale ] ?? esc_html( $locale );
}

// ---------------------------------------------------------------------------
// Page output
// ---------------------------------------------------------------------------

function tgbot_broadcast_page_output(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Active jobs.
	$all_jobs    = \TGBot\Broadcast::get_history( 100 );
	$active_jobs = array_filter(
		$all_jobs,
		fn( $j ) => in_array( $j->status, [ 'pending', 'running' ], true )
	);

	// Bot users (those with tg_nickname set).
	$bot_users = get_users(
		[
			'meta_key'     => 'tg_nickname',
			'meta_compare' => '!=',
			'meta_value'   => '',
			'number'       => -1,
		]
	);

	// Build unique locale list.
	$locales = [];
	foreach ( $bot_users as $user ) {
		$locale = get_user_meta( $user->ID, 'locale', true ) ?: 'en_US';
		if ( ! in_array( $locale, $locales, true ) ) {
			$locales[] = $locale;
		}
	}
	sort( $locales );

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Telegram Broadcast', 'makarski-bot-connector-for-telegram' ); ?></h1>

		<?php if ( ! empty( $active_jobs ) ) : ?>
		<div id="tgbot-broadcast-active" class="tgbot-broadcast-bar">
			<p><?php esc_html_e( 'Sending…', 'makarski-bot-connector-for-telegram' ); ?></p>
			<div id="tgbot-progress-bar-wrap" class="tgbot-progress-bar-wrap">
				<div id="tgbot-progress-bar" class="tgbot-progress-bar" style="width:0%"></div>
			</div>
			<p id="tgbot-progress-text"></p>
		</div>
		<?php endif; ?>

		<div class="tgbot-broadcast-compose">
			<h2><?php esc_html_e( 'Select Recipients', 'makarski-bot-connector-for-telegram' ); ?></h2>

			<?php if ( empty( $bot_users ) ) : ?>
				<p><?php esc_html_e( 'No Telegram bot users found. Users need to have a Telegram username configured.', 'makarski-bot-connector-for-telegram' ); ?></p>
			<?php else : ?>

			<div class="tgbot-broadcast-filters">
				<label for="tgbot-lang-filter">
					<?php esc_html_e( 'Filter by language:', 'makarski-bot-connector-for-telegram' ); ?>
				</label>
				<select id="tgbot-lang-filter">
					<option value=""><?php esc_html_e( 'All languages', 'makarski-bot-connector-for-telegram' ); ?></option>
					<?php foreach ( $locales as $loc ) : ?>
						<option value="<?php echo esc_attr( $loc ); ?>">
							<?php echo wp_kses_post( tgbot_locale_label( $loc ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<p class="tgbot-select-actions">
				<a href="#" id="tgbot-select-all"><?php esc_html_e( 'Select all', 'makarski-bot-connector-for-telegram' ); ?></a>
				&nbsp;/&nbsp;
				<a href="#" id="tgbot-deselect-all"><?php esc_html_e( 'Deselect all', 'makarski-bot-connector-for-telegram' ); ?></a>
				&nbsp;&nbsp;
				<span id="tgbot-selected-count">
					<?php
					printf(
						/* translators: %d: number of selected users */
						esc_html__( 'Selected: %d', 'makarski-bot-connector-for-telegram' ),
						0
					);
					?>
				</span>
			</p>

			<table class="wp-list-table widefat striped tgbot-user-table" id="tgbot-user-table">
				<thead>
					<tr>
						<th class="check-column"><input type="checkbox" id="tgbot-check-all" /></th>
						<th><?php esc_html_e( 'Display Name', 'makarski-bot-connector-for-telegram' ); ?></th>
						<th><?php esc_html_e( 'Telegram Username', 'makarski-bot-connector-for-telegram' ); ?></th>
						<th class="column-lang"><?php esc_html_e( 'Language', 'makarski-bot-connector-for-telegram' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $bot_users as $user ) :
						$tg_nick = get_user_meta( $user->ID, 'tg_nickname', true );
						$locale  = get_user_meta( $user->ID, 'locale', true ) ?: 'en_US';
					?>
					<tr data-locale="<?php echo esc_attr( $locale ); ?>">
						<td class="check-column">
							<input
								type="checkbox"
								class="tgbot-user-cb"
								value="<?php echo esc_attr( $user->ID ); ?>"
								data-locale="<?php echo esc_attr( $locale ); ?>"
							/>
						</td>
						<td><?php echo esc_html( $user->display_name ); ?></td>
						<td>@<?php echo esc_html( $tg_nick ); ?></td>
						<td class="column-lang"><?php echo wp_kses_post( tgbot_locale_label( $locale ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<button id="tgbot-broadcast-btn" class="button button-primary" disabled>
					<?php
					printf(
						/* translators: %d: number of selected users */
						esc_html__( 'Send Broadcast (%d)', 'makarski-bot-connector-for-telegram' ),
						0
					);
					?>
				</button>
			</p>

			<?php endif; ?>
		</div>

		<?php if ( ! empty( $all_jobs ) ) : ?>
		<div class="tgbot-broadcast-history">
			<h2><?php esc_html_e( 'Broadcast History', 'makarski-bot-connector-for-telegram' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'makarski-bot-connector-for-telegram' ); ?></th>
						<th><?php esc_html_e( 'Total', 'makarski-bot-connector-for-telegram' ); ?></th>
						<th><?php esc_html_e( 'Sent', 'makarski-bot-connector-for-telegram' ); ?></th>
						<th><?php esc_html_e( 'Failed', 'makarski-bot-connector-for-telegram' ); ?></th>
						<th><?php esc_html_e( 'Status', 'makarski-bot-connector-for-telegram' ); ?></th>
						<th><?php esc_html_e( 'Message preview', 'makarski-bot-connector-for-telegram' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $all_jobs as $job ) :
						$msgs    = json_decode( $job->messages_json, true );
						$preview = '';
						if ( is_array( $msgs ) ) {
							$first   = reset( $msgs );
							$preview = mb_substr( $first, 0, 60 );
							if ( mb_strlen( $first ) > 60 ) {
								$preview .= '…';
							}
						}
					?>
					<tr>
						<td><?php echo esc_html( $job->created_at ); ?></td>
						<td><?php echo (int) $job->total; ?></td>
						<td><?php echo (int) $job->sent; ?></td>
						<td><?php echo (int) $job->failed; ?></td>
						<td><span class="tgbot-status-badge tgbot-status-<?php echo esc_attr( $job->status ); ?>"><?php echo esc_html( $job->status ); ?></span></td>
						<td><?php echo esc_html( $preview ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>

	</div><!-- .wrap -->
	<?php
}
