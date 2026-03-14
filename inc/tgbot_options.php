<?php

/**
 * Register plugin options and add the option page
 */

$wpt_settings_model = [
	'id'          => 'tgbot_options',
	'page_title'  => __( 'Telegram Integration Options', 'tgbot' ),
	//It is the title, will appear as header of the options page
	'menu_title'  => __( 'Telegram settings', 'tgbot' ),
	//It will appear in the admin menu
	'save_button' => __( 'Save', 'tgbot' ),
	//It is the caption for the "Save" button
	'groups'      => [
		'gen' => [
			'sections' => [
				'tg' => [
					'title'  => __( 'Telegram options', 'tgbot' ),
					'fields' => [
						'token'       => [
							'title' => __( 'Telegram Token', 'tgbot' ),
							'type'  => 'text',
						],
						'endpoint'    => [
							'title' => __( 'Telegram endpoint', 'tgbot' ),
							'type'  => 'text',
						],
						'set_webhook' => [
							'title' => __( 'Automatically set the webhook when save', 'tgbot' ),
							'type'  => 'checkbox',
						]
						//Can add other fields here
					],
				]//Can add other sections here
			],
		],
		'upd' => [
			'sections' => [
				'bitbucket' => [
					'title'  => __( 'Bitbucket options', 'tgbot' ),
					'fields' => [
						'token' => [
							'title' => __( 'Bitbucket Token', 'tgbot' ),
							'type'  => 'text',
						],
						//Can add other fields here
					],
				]//Can add other sections here
			],
		]//Can add other groups here
	],
];

global $tgbot_options;
$tgbot_options = new WPT_Options( $wpt_settings_model );

/**
 * Update Telegram endpoint on options save
 *
 * @param $old_value
 * @param $new_value
 *
 * @return void
 */
function tgbot_options_save( $old_value, $new_value ): void {
	//error_log('Run tgbot_options_save');
	$token        = $new_value['gen_tg_token'] ?? false;
	$endpoint     = $new_value['gen_tg_endpoint'] ?? false;
	$old_endpoint = $old_value['gen_tg_endpoint'] ?? false;

	$set_webhook = $new_value['gen_tg_set_webhook'] ?? false;

	if ( ! empty( $endpoint ) && $old_endpoint != $endpoint ) {
		TGBot\Init::custom_rewrite_rule();
		flush_rewrite_rules();
		//error_log( '[Debug] Flushing endpoint: ' . $endpoint );
	}

	if ( $token && $endpoint ) {
		TGBot\Core::set_tg_webhook( $endpoint );
		$full_endpoint = get_home_url( null, $endpoint );

		if ( $set_webhook ) {
			$bot = new Simple_Tg_Bot( $token, false );
			$bot->set_webhook( $full_endpoint );
		} else {
			// Just write log if it is not Production
			error_log( 'Emulate set Telegram webhook: ' . $full_endpoint );
		}
	}
}

add_action( 'update_option_tgbot_options', 'tgbot_options_save', 10, 2 );
