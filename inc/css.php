<?php
/*
WP Blockchain
https://wp-blockchain.com
License: GPLv2 or later

Copyright (c) 2018, Good Rebels Inc.
Please feel free to contact us at wp-blockchain@goodrebels.com.
*/

// use "&wpbc_dev=1&wpbc_force_css=1" to write css's to the plugin's assets folder

if (!defined('ABSPATH'))
	exit();

function wpbc_enqueue_style($id, $dept = array(), $root_dir = null, $root_write = false){
	static $scss = null;
	if ($scss === null){
		require WPBC_PATH.'/inc/lib/scssphp-master/scss.inc.php';

		$scss = new \Leafo\ScssPhp\Compiler(ABSPATH);
	}

	if (WPBC_IS_DEV)
		$root_write = true;

	$file = ($root_dir ? $root_dir : WPBC_PATH.'/assets/scss').'/_'.$id.'.scss';

	$time = max(filemtime($file), get_option('wpbc_settings_saved', 0));

	$root_fallback = false;
	if (!$root_write && !wpbc_cache_writable() && !$root_dir)
		$root_fallback = true;

	$filename_dest = $root_write || $root_fallback ? $id.'.css' : 'wp-blockchain-css-'.$id.'.css';

	$base_dest = $root_write || $root_fallback ? WPBC_PATH.'/assets/css' : WPBC_CACHE_PATH;
	$base_url_dest = $root_write || $root_fallback ? WPBC_URL.'/assets/css' : WPBC_CACHE_URL;

	$file_dest = $base_dest.'/'.$filename_dest;
	if ((!$root_fallback && ($root_write || !file_exists($file_dest) || filemtime($file_dest) < $time)) || (WPBC_IS_DEV && !empty($_GET['wpbc_force_css']))){
		if (file_exists($file_dest) && !@unlink($file_dest))
			return false;

		if (!file_exists($base_dest) && !wp_mkdir_p($base_dest))
			return false;

		$content = '';
		$colors = apply_filters('wpbc_colors', wpbc_get_colors());

		foreach ($colors as $k => $color_config)
			$content .= '$wpbc-color-'.$k.': '.$color_config['std'].';'."\n";

		$content .= file_get_contents($file);

		if (!($content = $scss->compile($content)) || !@file_put_contents($file_dest, $content)){
			return false;
		}
	}

//	if (!$root_write)
		wp_enqueue_style('wpbc-'.$id, $base_url_dest.'/'.$filename_dest, $dept, WPBC_VERSION);
	return $file_dest;
}

add_action('wp_enqueue_scripts', 'wpbc_wp_enqueue_styles', 9999);
add_action('admin_enqueue_scripts', 'wpbc_wp_enqueue_styles', 9999);
function wpbc_wp_enqueue_styles(){
	static $done = false;
	if (!wpbc_user_can('see_stamps') || $done)
		return;
	$done = true;

	$dept = array();
	$no_fa = get_option('wpbc_has_fontawesome', false);
	if (empty($no_fa)){
		wp_enqueue_style('wpbc-fa', WPBC_URL.'/assets/lib/font-awesome-4.7.0/css/font-awesome.min.css');
		$dept = array('wpbc-fa');
	}

	wpbc_enqueue_style_if('certificate', defined('WPBC_IS_API_RETURN'), $dept);
	wpbc_enqueue_style_if('stamps', true, $dept);
	wpbc_enqueue_style_if('settings', is_admin() && (is_wpbc_page() || has_wpbc_notice()));
	wpbc_enqueue_style_if('panel', is_wpbc_page(), $dept);

	do_action('wpbc_enqueue_styles', $dept);
}

add_action('wp_print_styles', 'wpbc_remove_all_styles', 100);
function wpbc_remove_all_styles(){
	if (defined('WPBC_IS_API_RETURN')){
		global $wp_styles;
		$ids = $wp_styles->queue;
		$wp_styles->queue = array();
		foreach ($ids as $style_id)
			if (strpos($style_id, 'wpbc-') === 0)
				$wp_styles->queue[] = $style_id;
	}
}

function wpbc_enqueue_style_if($id, $if, $dept = array(), $root_dir = null){
	if (apply_filters('wpbc_enqueue_style', $if, $id, $dept, $root_dir))
		wpbc_enqueue_style($id, $dept, $root_dir);
}

function wpbc_cache_writable(){
	return (is_dir(WPBC_CACHE_PATH) && is_writable(WPBC_CACHE_PATH)) || wp_mkdir_p(WPBC_CACHE_PATH);
}
