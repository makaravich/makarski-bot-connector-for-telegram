<?php

/**
 * Register plugin settings and add the options page.
 *
 * Options are stored as a single array under option_name 'tgbot_options':
 *   gen_tg_token       — Telegram bot token
 *   gen_tg_endpoint    — webhook URL path (relative)
 *   gen_tg_set_webhook — auto-set webhook on save (checkbox)
 */

if ( ! function_exists( 'tgbot_get_option' ) ) {
	function tgbot_get_option( string $key ): mixed {
		$options = get_option( 'tgbot_options' );

		return is_array( $options ) ? ( $options[ $key ] ?? null ) : null;
	}
}

add_action( 'admin_menu', 'tgbot_add_options_page' );
add_action( 'admin_init', 'tgbot_register_settings' );
add_action( 'update_option_tgbot_options', 'tgbot_options_save', 10, 2 );

function tgbot_add_options_page(): void {
	add_submenu_page(
		'options-general.php',
		__( 'Telegram Integration Options', 'tgbot' ),
		__( 'Telegram settings', 'tgbot' ),
		'manage_options',
		'tgbot_options-options',
		'tgbot_options_page_output'
	);
}

function tgbot_register_settings(): void {
	register_setting(
		'tgbot_options_group',
		'tgbot_options',
		[ 'sanitize_callback' => 'tgbot_sanitize_options' ]
	);

	add_settings_section(
		'tgbot_section_telegram',
		__( 'Telegram options', 'tgbot' ),
		'__return_false',
		'tgbot_options_page'
	);

	add_settings_field(
		'gen_tg_token',
		__( 'Telegram Token', 'tgbot' ),
		'tgbot_field_token',
		'tgbot_options_page',
		'tgbot_section_telegram',
		[ 'key' => 'gen_tg_token' ]
	);

	add_settings_field(
		'gen_tg_endpoint',
		__( 'Telegram endpoint', 'tgbot' ),
		'tgbot_field_text',
		'tgbot_options_page',
		'tgbot_section_telegram',
		[ 'key' => 'gen_tg_endpoint' ]
	);

	add_settings_field(
		'gen_tg_set_webhook',
		__( 'Automatically set the webhook when save', 'tgbot' ),
		'tgbot_field_checkbox',
		'tgbot_options_page',
		'tgbot_section_telegram',
		[ 'key' => 'gen_tg_set_webhook' ]
	);
}

function tgbot_options_page_output(): void {
	?>
	<div class="wrap">
		<h2><?php echo esc_html( get_admin_page_title() ); ?></h2>
		<form action="options.php" method="POST" class="wpt-form">
			<?php
			settings_fields( 'tgbot_options_group' );
			do_settings_sections( 'tgbot_options_page' );
			submit_button( __( 'Save', 'tgbot' ) );
			?>
		</form>
	</div>
	<?php
}

function tgbot_field_text( array $args ): void {
	$val = tgbot_get_option( $args['key'] );
	printf(
		'<input class="tgbot_options" type="text" name="tgbot_options[%s]" value="%s" />',
		esc_attr( $args['key'] ),
		esc_attr( $val ?? '' )
	);
}

function tgbot_field_token( array $args ): void {
	$val = tgbot_get_option( $args['key'] );
	$id  = 'tgbot_token_field';
	?>
	<div class="tgbot-token-wrap">
		<input
			id="<?php echo esc_attr( $id ); ?>"
			class="tgbot_options tgbot-token-input"
			type="password"
			name="tgbot_options[<?php echo esc_attr( $args['key'] ); ?>]"
			value="<?php echo esc_attr( $val ?? '' ); ?>"
			autocomplete="new-password"
		/>
		<button type="button" class="button tgbot-token-toggle" data-target="<?php echo esc_attr( $id ); ?>" aria-label="<?php esc_attr_e( 'Show/hide token', 'tgbot' ); ?>">
			<span class="dashicons dashicons-visibility"></span>
		</button>
	</div>
	<?php
}

function tgbot_field_checkbox( array $args ): void {
	$val = tgbot_get_option( $args['key'] );
	printf(
		'<input class="tgbot_options-input" type="checkbox" name="tgbot_options[%s]" %s />',
		esc_attr( $args['key'] ),
		checked( 'on', $val, false )
	);
}

function tgbot_sanitize_options( $input ): array {
	$clean = [];

	if ( isset( $input['gen_tg_token'] ) ) {
		$clean['gen_tg_token'] = sanitize_text_field( $input['gen_tg_token'] );
	}

	if ( isset( $input['gen_tg_endpoint'] ) ) {
		$clean['gen_tg_endpoint'] = sanitize_text_field( $input['gen_tg_endpoint'] );
	}

	$clean['gen_tg_set_webhook'] = isset( $input['gen_tg_set_webhook'] ) ? 'on' : '';

	return $clean;
}

function tgbot_options_save( $old_value, $new_value ): void {
	$token        = $new_value['gen_tg_token'] ?? false;
	$endpoint     = $new_value['gen_tg_endpoint'] ?? false;
	$old_endpoint = $old_value['gen_tg_endpoint'] ?? false;
	$set_webhook  = $new_value['gen_tg_set_webhook'] ?? false;

	if ( ! empty( $endpoint ) && $old_endpoint !== $endpoint ) {
		\TGBot\Init::custom_rewrite_rule();
		flush_rewrite_rules();
	}

	if ( $token && $endpoint ) {
		$full_endpoint = get_home_url( null, $endpoint );

		if ( $set_webhook ) {
			\TGBot\Core::set_tg_webhook( $endpoint, $token );
		}
	}
}
