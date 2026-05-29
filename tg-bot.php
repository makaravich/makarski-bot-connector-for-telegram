<?php
/**
 * Telegram Messenger Integration
 *
 * @author        Dzmitry Makarski
 * @version       0.2.16
 *
 * @wordpress-plugin
 * Plugin Name:       Telegram Messenger Integration
 * Description:       Allows you to manage your Telegram bot via WordPress
 * Version:           0.2.16
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * License:           GPLv2
 * Author:            Dzmitry Makarski
 * Text Domain:       tgbot
 * Domain Path:       /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TGBOT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'TGBOT_PLUGIN_MAIN_FILE', __FILE__ );
define( 'TGBOT_PLUGIN_BASEPATH', plugin_dir_path( __FILE__ ) );
define( 'TGBOT_PLUGIN_BASEURI', plugin_dir_url( __FILE__ ) );

/**
 * Autoload files of classes from includes folder
 *
 * @param $class_name
 *
 * @return void
 */
function tgbot_autoload_classes( $class_name ): void {
	// Set base folder for the classes
	$base_dir = TGBOT_PLUGIN_BASEPATH . 'classes/';

	// Convert classname into a file path
	$file = wp_normalize_path( $base_dir . str_replace( '\\', '/', $class_name ) . '.php' );

	// Include file if exists
	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

// Register Autoload
spl_autoload_register( 'tgbot_autoload_classes' );

// Plugin Options
require_once TGBOT_PLUGIN_BASEPATH . '/inc/tgbot_options.php';

// Plugin Functions
require_once TGBOT_PLUGIN_BASEPATH . '/inc/tgbot_functions.php';

// Deactivation: stop polling cron
register_deactivation_hook( __FILE__, function () {
	\TGBot\Polling::unschedule();
} );

// Run the bot
new \TGBot\Init();
