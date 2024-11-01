<?php
/*
WP Blockchain
https://wp-blockchain.com
License: GPLv2 or later

Copyright (c) 2018, Good Rebels Inc.
Please feel free to contact us at wp-blockchain@goodrebels.com.
*/


if (!defined('ABSPATH'))
	exit();


add_action('admin_init', 'wpbc_free_init');
add_action('init', 'wpbc_free_init');

function wpbc_free_init(){
	include_once ABSPATH.'wp-admin/includes/plugin.php';
	wpbc_free_init_do();
}

register_activation_hook(WPBC_PLUGIN, 'wpbc_free_install');
function wpbc_free_install(){
	wpbc_free_install_do();
}
function wpbc_free_install_do($force = false){
	define('WPBC_INSTALL', true);

	if (wpbc_free_init_do() || $force)
		require WPBC_PATH.'/inc/schema.php';
}

function wpbc_free_init_do(){
	if (defined('WPBC_INITED'))
		return false;
	define('WPBC_INITED', true);

	$err = null;

	if (version_compare(PHP_VERSION, '5.4') < 0)
		$err = sprintf(__('The WP Blockchain plugin needs PHP 5.4 or later, and the PHP version installed is %s. Please contact your hosting administrator and ask for a PHP upgrade.', 'wpbc'), PHP_VERSION);

	else if (!function_exists('mb_strtolower'))
		$err = __('WP Blockchain requires the PHP Multibytes extension ("php-mbstring") to work properly. Please install it or contact your hosting administrator.', 'wpbc');

	else if (!function_exists('curl_init'))
		$err = __('WP Blockchain requires the PHP cURL extension ("php-curl") to work properly. Please install it or contact your hosting administrator.', 'wpbc');

	if ($err){
		deactivate_plugins('wp-blockchain/wp-blockchain.php', true);
		wpbc_trigger_error($err, E_USER_ERROR);

	} else {
		require WPBC_PATH.'/inc/init.php';
		return true;
	}
	return false;
}


function wpbc_trigger_error($message, $errno) {
	if (!defined('WPBC_INSTALL') && !current_user_can('manage_options'))
		return false;

    if(isset($_GET['action']) && $_GET['action'] == 'error_scrape') {
        echo '<strong>' . $message . '</strong>';

        if (defined('WPBC_INSTALL'))
			exit;

    } else if (defined('WPBC_INSTALL')){
        trigger_error($message, $errno);
        return true;
	}

	return false;
}
