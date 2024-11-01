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

require WPBC_PATH.'/inc/helpers.php';

define('WPBC_IS_DEV', wpbc_is_localhost() && current_user_can('manage_options') && !empty($_GET['wpbc_dev']));
define('WPBC_USE_TESTNET', false);

define('WPBC_VERSION', '3.2.1');
define('WPBC_DB_PREFIX', $GLOBALS['wpdb']->prefix.'wpbc_');
define('WPBC_LOG_PATH', WPBC_PATH.'/error.log');
define('WPBC_SETTINGS_ROLE', 'manage_options');

$protocol = is_ssl() ? 'https' : 'http';
define('WPBC_URL', preg_replace('#^(https?)(://.*)$#iu', $protocol.'$2',
	wpbc_is_localhost()
	? rtrim(defined('WP_HOME') ? WP_HOME : home_url(), '/').'/'.str_replace(ABSPATH, '', WPBC_PATH)
	: plugins_url('', WPBC_PLUGIN)
));

define('WPBC_CRON_DETECT_DELAY', '20 minutes');
define('WPBC_MIN_UPDATE_PERIOD', '3 days');
define('WPBC_MIN_ERROR_CHECK', '1 hour');
define('WPBC_LOG_LEVEL', 'error');
define('WPBC_ALLOW_RESTAMP', true);
define('WPBC_CACHE_PATH', WP_CONTENT_DIR.'/cache');
define('WPBC_CACHE_URL', preg_replace('#^(https?)(://.*)$#iu', $protocol.'$2',
	wpbc_is_localhost()
	? rtrim(defined('WP_HOME') ? WP_HOME : home_url(), '/').'/'.preg_replace('#^([^/]+)/.*$#', '$1', str_replace(ABSPATH, '', WPBC_PATH)).'/cache'
	: WP_CONTENT_URL.'/cache'
));

define('WPBC_SCHEMA_VERSION', 4);

add_filter('plugin_locale', 'wpbc_change_locale', 0, 2);
function wpbc_change_locale($locale, $domain = null){
	return $domain == 'wpbc' && !empty($_POST['wpbc_locale']) ? $_POST['wpbc_locale'] : $locale;
}

load_plugin_textdomain('wpbc', false, basename(WPBC_PATH).'/languages');

wpbc_post_type_supports(); // precalculate support by post_type


// load core files

require WPBC_PATH.'/inc/api.php';
require WPBC_PATH.'/inc/js.php';
require WPBC_PATH.'/inc/css.php';
require WPBC_PATH.'/inc/stamp.php';
require WPBC_PATH.'/inc/settings.php';
require WPBC_PATH.'/inc/cron.php';
require WPBC_PATH.'/inc/donate.php';

// load mods

require WPBC_PATH.'/inc/mods/wprocket.php';
require WPBC_PATH.'/inc/mods/yoast.php';


wpbc_custom_rewrite();

function wpbc_check_schema_version(){
	$cversion = get_option('wpbc_schema_version', 0);
	if ($cversion < WPBC_SCHEMA_VERSION && wpbc_lock('schema_version_install', 'schema_version_install')){

		wpbc_free_install();
		update_option('wpbc_schema_version', WPBC_SCHEMA_VERSION);
		wpbc_unlock('schema_version_install');
	}
}

add_action('template_redirect', 'wpbc_ajax', 9999);
function wpbc_ajax(){

	if (!empty($_REQUEST['wpbc'])
		&& preg_match('#^[a-z0-9_]+$#', $_REQUEST['wpbc'])
		&& function_exists('wpbc_ajax_'.$_REQUEST['wpbc'])){

		define('WPBC_IS_AJAX', true);

		ob_start();
		call_user_func('wpbc_ajax_'.$_REQUEST['wpbc']);

		echo ob_get_clean();
		exit();
	}
}

function wpbc_json_ret($ret){
	$debug = ob_get_clean();
	if (wpbc_is_admin() && !empty($debug))
		$ret['debug'] = $debug;

	wpbc_set_ajax_headers();
	header('HTTP/1.1 200 OK');
	header('Access-Control-Allow-Credentials: true');

	echo json_encode($ret);
	exit();
}

