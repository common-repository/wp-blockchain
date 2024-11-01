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

add_action('wp_enqueue_scripts', 'wpbc_wp_enqueue_scripts', 9999);
add_action('admin_enqueue_scripts', 'wpbc_wp_enqueue_scripts', 9999);
function wpbc_wp_enqueue_scripts(){
	if (!wpbc_user_can('see_stamps'))
		return;

	wp_enqueue_script('wpbc-stamp', WPBC_URL.'/assets/js/stamp.js', array('jquery'), WPBC_VERSION);
	if (is_admin() && (is_wpbc_page() || has_wpbc_notice())){
		wp_enqueue_style('wp-color-picker');
		wp_enqueue_script('wpbc-settings', WPBC_URL.'/assets/js/settings.js', array('jquery', 'wpbc-stamp', 'wp-color-picker'), WPBC_VERSION);
	}
}

add_action('wp_head', 'wpbc_print_constants', -99999);
add_action('admin_head', 'wpbc_print_constants', -99999);
function wpbc_print_constants(){
	static $printed = false;
	if ($printed) return;
	$printed = true;
	?>
	<script type="text/javascript">
		var WPBC = <?= json_encode(apply_filters('wpbc_js_vars', array(

			'no_redirect' => wpbc_is_admin(),

			'ajaxurl' => admin_url('admin-ajax.php'),
			'ajaxurl_clean' => site_url('/'),

			'no_live_update' => !empty($_GET['no_live_update']),
			'permanent_ticking' => wpbc_is_admin() && !empty($_GET['permanent_ticking']),
			'max_ticking_period' => wpbc_is_admin() && !empty($_GET['ticking_period']) ? intval($_GET['ticking_period']) : 60000,
			'min_ticking_period' => 6000,

			'live_loading' => '<i class="fa fa-spinner fa-pulse fa-fw"></i> '.__('refreshing', 'wpbc').'..',
			'live_waiting' => __('refreshing in %ds', 'wpbc'),
			'live_paused' => '<i class="fa fa-pause wpbc-live-paused-icon"></i> '.__('paused', 'wpbc'),

			'ajax_extra_args' => array(
				'context' => wpbc_get_context(),
				'wpbc_locale' => get_locale()
			),

			'dev' => WPBC_IS_DEV,
		))) ?>;
	</script>
	<?php
}

function wpbc_get_context(){
	global $wpbc_api_ret, $pagenow, $user_id;

	if (defined('WPBC_IS_AJAX') && WPBC_IS_AJAX)
		return !empty($_POST['context']) ? $_POST['context'] : array();

	$context = array();
	if (!empty($wpbc_api_ret)){
		$context['is_api'] = true;
		$context['stamp_code'] = $wpbc_api_ret['stamp_code'];

		if (!empty($wpbc_api_ret['user_id']))
			$context['user_id'] = $wpbc_api_ret['user_id'];
		else
			$context['post_id'] = $wpbc_api_ret['page_id'];

	} else if (is_singular() || is_single() || in_the_loop())
		$context['post_id'] = get_the_ID();

	else if (is_author())
		$context['user_id'] = get_the_author_meta('ID');

	else if (!empty($user_id))
		$context['user_id'] = $user_id;

	$context['is_admin'] = is_admin();

	return $context;
}

function wpbc_is_backend(){
	$c = wpbc_get_context();
	return !empty($c['is_admin']) && $c['is_admin'] !== 'false';
}

add_action('wp_enqueue_scripts', 'wpbc_login_styles', 9999999);
function wpbc_login_styles(){
	if (defined('WPBC_IS_API_RETURN'))
		wp_dequeue_style('stylesheet');
}
