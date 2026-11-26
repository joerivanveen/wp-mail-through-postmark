<?php

declare( strict_types=1 );

require( __DIR__ . '/crypt.php' );
require( __DIR__ . '/mark.php' );

/*
Plugin Name: WP mail through Postmark
Plugin URI: https://github.com/joerivanveen/wp-mail-through-postmark
Description: Have WordPress mail function use Postmark for increased deliverability.
Version: 1.0.0
Requires at least: 6.9
Tested up to: 7.2
Requires PHP: 8.0
Author: Joeri van Veen
Author URI: https://wp-developer.eu
License: GPLv3
Text Domain: wp-mail-through-postmark
Domain Path: /languages/
*/
defined( 'ABSPATH' ) || die();
// This is plugin nr. 19 by Ruige hond. It identifies as: ruigehond019.
const RUIGEHOND019_VERSION = '1.0.0';

add_action( 'init', 'ruigehond019_run' );

function ruigehond019_run(): void {
	if ( is_admin() ) {
		load_plugin_textdomain( 'wp-mail-through-postmark', '', dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		add_action( 'admin_init', 'ruigehond019_settings' );
		add_action( 'admin_menu', 'ruigehond019_menuitem' );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ruigehond019_settingslink' ); // settings link on plugins page
	}

	add_filter( 'pre_wp_mail', 'ruigehond019_mail', 1, 2 );
}

/**
 * @param mixed $pre_wp_mail null, or the result of another plugin that already handled (or blocked) this mail
 * @param array $atts
 * Array of the `wp_mail()` arguments.
 *
 * @type string|string[] $to Array or comma-separated list of email addresses to send message.
 * @type string $subject Email subject.
 * @type string $message Message contents.
 * @type string|string[] $headers Additional headers.
 * @type string|string[] $attachments Paths to files to attach.
 * @type string|string[] $embeds Paths to files to embed.
 *
 * @return bool|null True if the email was sent successfully, false if it failed, null to skip and use the default wp_mail().
 */
function ruigehond019_mail( $pre_wp_mail, $atts ): ?bool {
	if ( null !== $pre_wp_mail ) {
		return (bool) $pre_wp_mail; // another plugin already handled (or blocked) this mail
	}
	$vars       = (array) get_option( 'ruigehond019' );
	$from_email = $from_name = $vars['verified_sender'] ?? '';
	$api_key    = ruigehond019_get_api_key( $vars );

	if ( false !== ( $start = strpos( $from_email, '<' ) ) ) {
		$end        = strrpos( $from_email, '>' );
		$from_name  = trim( substr( $from_name, 0, $start ) );
		$from_email = trim( substr( $from_email, $start + 1, $end - $start - 1 ) );
		if ( '' === $from_name ) {
			$from_name = $from_email;
		}
	}

	if ( '' === $api_key || '' === $from_email || ! filter_var( $from_email, FILTER_VALIDATE_EMAIL ) ) {
		error_log( 'WP mail through Postmark: missing server api token or invalid verified sender, skipping wp_mail override.' );

		return null;
	}

	$atts['from_name']  = $from_name;
	$atts['from_email'] = $from_email;

	$mail = new \Ruigehond019\Mark\Mail( $api_key );
	$mail->prepare( $atts );
	$sent = $mail->send();

	// Core wp_mail() does not fire these when pre_wp_mail short-circuits, mail logging plugins rely on them.
	$mail_data = array(
		'to'          => $atts['to'] ?? array(),
		'subject'     => $atts['subject'] ?? '',
		'message'     => $atts['message'] ?? '',
		'headers'     => $atts['headers'] ?? array(),
		'attachments' => $atts['attachments'] ?? array(),
		'embeds'      => $atts['embeds'] ?? array(),
	);
	if ( $sent ) {
		do_action( 'wp_mail_succeeded', $mail_data );
	} else {
		do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', $mail->getError(), $mail_data ) );
	}

	return $sent;
}

function ruigehond019_get_api_key( array $vars ): string {
	$api_key = $vars['api_key_encrypted'] ?? '';

	if ( '' !== $api_key ) {
		try {
			$api_key = \Ruigehond019\Crypt\decrypt( $api_key );
		} catch ( \Throwable $e ) {
			error_log( 'WP mail through Postmark: failed to decrypt server api token, check your NONCE_KEY. ' . $e->getMessage() );
			$api_key = '';
		}
	}

	return $api_key;
}

function ruigehond019_menuitem(): void {
	add_submenu_page(
		'', // this will hide the settings page in the "settings" menu
		'WP mail through Postmark Settings',
		'WP mail through Postmark',
		'administrator',
		'wp-mail-through-postmark',
		'ruigehond019_settingspage'
	);
}

function ruigehond019_settingspage(): void {
	if ( ! current_user_can( 'administrator' ) ) {
		return;
	}
	echo '<div class="wrap ruigehond019"><h1>WP mail through Postmark</h1><p>';
	echo esc_html__( 'Your WordPress NONCE_KEY is used to store the Postmark server api token encrypted in your database. If you change your NONCE_KEY you must save the server api token anew.', 'wp-mail-through-postmark' );
	echo '</p><form action="options.php" method="post" id="ruigehond019-settings-form" autocomplete="off">';
	// output security fields for the registered setting
	settings_fields( 'ruigehond019' );
	// output setting sections and their fields
	do_settings_sections( 'ruigehond019' );
	// output save settings button
	submit_button( esc_html__( 'Save Settings', 'wp-mail-through-postmark' ) );
	echo '</form></div>';
}

function ruigehond019_settings(): void {
	register_setting( 'ruigehond019', 'ruigehond019', 'ruigehond019_settings_validate' );
	// don’t bother with all this if we’re not even on the settings page
	if ( false === isset( $_GET['page'] ) || 'wp-mail-through-postmark' !== $_GET['page'] ) {
		return;
	}
	wp_enqueue_style( 'ruigehond019_stylesheet', plugin_dir_url( __FILE__ ) . 'admin.css', [], RUIGEHOND019_VERSION );
	// register a new section in the page
	add_settings_section(
		'wp_mail_through_postmark', // section id
		esc_html__( 'Settings', 'wp-mail-through-postmark' ), // title
		function () {
			echo '<p>';
			echo esc_html__( 'Enter the server api token of your Postmark server and the verified sender you wish to use.', 'wp-mail-through-postmark' );
			echo '<br><strong>';
			echo esc_html__( 'The email address you fill in here as sender must be a verified Sender Signature or on a verified domain in Postmark.', 'wp-mail-through-postmark' );
			echo '</strong><br>';
			echo esc_html__( 'You can use plain email address or this format: "Sender Name <sender-email@verified-domain.com>".', 'wp-mail-through-postmark' );
			echo '</p>';
		}, //callback
		'ruigehond019' // page
	);

	if ( false === ( get_option( 'ruigehond019' ) ) ) {
		add_option( 'ruigehond019', array(
			'api_key' => '',
		), '', true );
	}

	$vars = (array) get_option( 'ruigehond019' );

	add_settings_field(
		'ruigehond019_api_key',
		__( 'Postmark server api token', 'wp-mail-through-postmark' ),
		function ( $args ) {
			$name = $args['name'];
			echo '<input type="checkbox" name="show" class="toggle-text-security" title="', esc_html__( 'Show server api token', 'wp-mail-through-postmark' ), '" /> ';
			echo '<input type="text" name="ruigehond019[', esc_html( $name ), ']" id="', esc_html( $name ), '" value="';
			echo esc_html( $args['value'] );
			echo '" class="regular-text" style="-webkit-text-security: disc;"/>';
		},
		'ruigehond019',
		'wp_mail_through_postmark',
		array(
			'name'  => 'api_key',
			'value' => ruigehond019_get_api_key( $vars ),
		)
	);
	add_settings_field(
		'ruigehond019_verified_sender',
		__( 'Verified sender', 'wp-mail-through-postmark' ),
		function ( $args ) {
			$name = $args['name'];
			echo '<input type="text" name="ruigehond019[', esc_html( $name ), ']" id="', esc_html( $name ), '" value="';
			echo esc_html( $args['value'] );
			echo '" class="regular-text"/>';
		},
		'ruigehond019',
		'wp_mail_through_postmark',
		array(
			'name'  => 'verified_sender',
			'value' => $vars['verified_sender'] ?? '',
		)
	);
}

function ruigehond019_settings_validate( $input ): array {
	$options = get_option( 'ruigehond019' );

	if ( ! is_array( $options ) ) {
		$options = array();
	}

	if ( false === is_array( $input ) ) {
		return $options;
	}

	/**
	 * Because validation can occur multiple times, we use a separate field to store the encrypted api key.
	 */
	$api_key = trim( $input['api_key'] ?? '' );
	if ( '' === $api_key ) {
		unset( $options['api_key_encrypted'] ); // allow deletion of api key
	} else {
		try {
			$options['api_key_encrypted'] = \Ruigehond019\Crypt\encrypt( $api_key );
		} catch ( \Throwable $e ) {
			add_settings_error( 'ruigehond019', 'encrypt', __( 'The server api token was not saved: ', 'wp-mail-through-postmark' ) . $e->getMessage() );
		}
	}
	unset ( $options['api_key'] ); // this should never be saved no matter what

	$options['verified_sender'] = trim( $input['verified_sender'] ?? '' );

	return $options;
}

function ruigehond019_settingslink( $links ): array {
	$url           = get_admin_url();
	$link_text     = esc_html__( 'Settings', 'wp-mail-through-postmark' );
	$settings_link = "<a href=\"{$url}options-general.php?page=wp-mail-through-postmark\">$link_text</a>";
	array_unshift( $links, $settings_link );

	return $links;
}

