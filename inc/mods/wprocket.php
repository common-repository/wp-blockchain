<?php
/*
WP Blockchain
https://wp-blockchain.com
License: GPLv2 or later

Copyright (c) 2018, Good Rebels Inc.
Please feel free to contact us at wp-blockchain@goodrebels.com.
*/

// you can also add /api/stamps/(.*) in WP Rocket settings

namespace WP_Rocket\Helpers\static_files\exclude\optimized_css;

if (!defined('ABSPATH'))
	exit();

function wpbc_wprocket_exclude_files( $excluded_files = array() ) {
	$base = str_replace(rtrim(ABSPATH, '/'), '', WPBC_PATH);
	$excluded_files[] = $base.'/assets/css/stamps.css';
	$excluded_files[] = $base.'/assets/css/certificate.css';
	$excluded_files[] = $base.'/assets/css/panel.css';
	return $excluded_files;
}

add_filter( 'rocket_exclude_async_css', __NAMESPACE__ . '\wpbc_wprocket_exclude_files' );

add_action( 'wpbc_before_page', function(){
	if (!defined('DONOTCACHE'))
		define('DONOTCACHE', true);
	add_filter('do_rocket_generate_caching_files','__return_false');
});
