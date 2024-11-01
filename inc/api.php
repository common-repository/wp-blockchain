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

function wpbc_custom_rewrite(){
  add_rewrite_rule('^api/(.+)/?$', 'index.php?wpbc_action=$matches[1]', 'top');
}

add_filter('query_vars', 'wpbc_query_vars');
function wpbc_query_vars($qvars){
  $qvars[] = 'wpbc_action';
  return $qvars;
}

add_action('pre_get_posts', 'wpbc_pre_get_posts', 0, 1);
function wpbc_pre_get_posts($query){
	if ($query->is_main_query() && !empty($query->query_vars['wpbc_action'])){
		if (!wpbc_user_can('see_stamps')){
			$query->set_404();
			status_header(404);
			return;
		}

		$parts = explode('/', rtrim($query->query_vars['wpbc_action'], '/'));

		$api_type = array_shift($parts);

		switch ($api_type){

			case 'stamps':
				$hash = array_pop($parts);
				$stamp_type = array_pop($parts);

				$vars = apply_filters('wpbc_pre_api', array(
					'stamp_type' => $stamp_type,
					'hash' => $hash,
					'parts' => $parts,
					'stamps_argument' => array()
				));

				if ($vars === false)
					wpbc_api_ret_unknown_stamp(1);

				extract($vars);

				$page_name = implode('/', $parts);
				$user_id = null;
				
				if (!empty($_GET['wpbc_print_pagename'])){
					echo 'pagename: '.$page_name;
					exit();
				}

				if ($stamp_type == 'profile'){
					$page_id = null;
					if (!($u = get_user_by('slug', $page_name)))
						wpbc_api_ret_unknown_stamp(9);

					$user_id = $u->ID;

				} else {
					if ($page_name == '-' && !($page_id = get_option( 'page_on_front' )))
						wpbc_api_ret_unknown_stamp(10);

					else if (!($page_id = wpbc_url_to_postid($page_name)))
						wpbc_api_ret_unknown_stamp(2);
				}

				if (!wpbc_can_stamp_show_as($stamp_type == 'profile' ? 'user' : get_post_type($page_id), 'certificate', $stamp_type == 'profile' ? $user_id : $page_id))
					wpbc_api_ret_unknown_stamp(12);

				$stamps = $stamp_type == 'profile'
					? wpbc_get_user_stamps($user_id, true, $stamps_argument)
					: wpbc_get_stamps($page_id, true, $stamps_argument);

				if (!isset($stamps[$stamp_type]))
					wpbc_api_ret_unknown_stamp(3);

				$stamp_base = $stamps[$stamp_type]['base'];
				$stamp_code = $stamp_base.'-'.$hash;

				if (empty($hash)){
					wp_redirect($api->get_stamp_url_from_code($stamp_base.'-last', true));
					exit;
				}

				$api = wpbc_get_api();
				$blockchains = wpbc_get_blockchains();

				$is_page_last = ($hash == 'last');
				$last_content = wpbc_get_stamp_content($stamp_base);
				$last_hash = $api->get_hash($last_content);

				if ($hash == 'last' || $hash == $last_hash){
					$hash = $last_hash;
					$last_hash = true;
				} else
					$is_page_last = $last_hash = false;
//echo $stamp_code;
				$stamp = wpbc_get_stamp_by_code($stamp_code, wpbc_must_autostamp($page_id, $stamp_code), true);

				if (!$stamp || is_string($stamp))
					wpbc_api_ret_unknown_stamp(is_string($stamp) && current_user_can('manage_options') ? '5: '.$stamp : 5);

				else if (!empty($stamp['status']) && $stamp['status'] == 'queued' && !wpbc_is_admin())
					wpbc_api_ret_unknown_stamp(7);

				else if ($stamp !== false){
					global $wpbc_api_ret;

					$ret = $api->api($stamp, $stamp_code, wpbc_is_admin() && !empty($_GET['update']));
					$wpbc_api_ret = array(
						'stamp_code' => $stamp_code,
						'page_id' => $page_id,
						'user_id' => $user_id,
						'stamp_type' => $stamp_type,
						'ret' => $ret
					);

					$api_vars = apply_filters('wpbc_pre_api_ret', array(
						'context_id' => $page_id,
						'stamp_code' => $stamp_code,
						'is_page_last' => $is_page_last,
						'hash' => $hash,
						'stamp_type' => $stamp_type,
						'stamp' => $stamp,
						'api' => $api,
						'last_hash' => $last_hash,
						'ret' => $ret,
						'stamp_base' => $stamp_base,
					));

					if ($api_vars === false)
						wpbc_api_ret_unknown_stamp(6);

					wpbc_api_ret(array(
						'results' => $ret
					), $api_vars, $stamp_code);
				}
		}

		$labels = wpbc_get_status_labels('api');
		wpbc_api_ret(array(
			'error' => array(
				'id' => 'bad_action',
				'label' => $labels['unknown_action']
			)
		), false);
	}
}

function wpbc_lint_ret($ret){
	// unset content variables on revision and private and draft items, if user is not authenticated
	if (isset($ret['stamp_code']) && !wpbc_user_can_see_code_content($ret['stamp_code'])){
		if (isset($ret['content']))
			unset($ret['content']);
		if (isset($ret['content_decoded']))
			unset($ret['content_decoded']);
	}
	return $ret;
}

function wpbc_api_ret($ret, $success_api_vars = true, $live_api_id = null){
	define('WPBC_IS_API_RETURN', true);

	if (!is_array($ret))
		$ret = array(
			'success' => !!$success_api_vars,
			'results' => $ret
		);
	else if (!isset($ret['success']))
		$ret = array('success' => !!$success_api_vars) + $ret;

	if (isset($ret['results']))
		$ret['results'] = wpbc_lint_ret($ret['results']);

	if (!isset($_GET['raw']) || strcmp($_GET['raw'], 1)){
		$labels = wpbc_get_status_labels('api');
		if (!$success_api_vars){
			$intro_text = '<div class="wpbc-api-ret-status"><i class="fa fa-warning"></i> '.strtoupper($labels['error']);
			if (!empty($ret['error']))
				$intro_text .= ': '.$ret['error']['label'];
			else
				$intro_text .= $labels['error'];
			$intro_text .= '</div>';
		} else
			$intro_text = wpbc_get_template('api', $success_api_vars);

		ob_start();
		?>
		<div class="wpbc-api-intro">
			<?= $intro_text ?>
			<div class="wpbc-api-ret-status-ind wpbc-api-ret-status-ind-api"><?php echo sprintf($labels['api_result'], '<a href="'.add_query_arg('raw', 1).'" rel="nofollow">'.$labels['raw_result'].'</a>'); ?>:</div>
		</div>
		<div class="wpbc-api-ret"><code><?php
			if ($live_api_id)
				echo wpbc_convert_to_live('api_human-'.$live_api_id);
			else
				echo wpbc_pretty_json($ret);
		?></code></div>
		<div class="wpbc-api-footer">
		<?php
			echo apply_filters('wpbc_certificate_footer', '<i class="fa fa-copyright"></i> '.date('Y').' <a href="'.home_url().'">'.get_bloginfo('name').'</a>');
		?>
		</div>
		<?php
		wpbc_output_page(ob_get_clean(), !empty($ret['error']));
	} else
		wpbc_json_ret($ret);
}

function wpbc_get_template($tpl, $args = array()){
	extract($args);
	ob_start();
	foreach (wpbc_get_template_folders() as $folder)
		if (file_exists($path = $folder.'/'.$tpl.'.php')){
			include $path;
			break;
		}
	return ob_get_clean();
}

function wpbc_api_ret_unknown_stamp($ind = null){
	$labels = wpbc_get_status_labels('api');
	wpbc_api_ret(array(
		'error' => array(
			'id' => 'unknown_stamp',
			'label' => $labels['unknown_stamp'].($ind && wpbc_is_admin() ? ' ('.$ind.')' : '')
		)
	), false);
}

function wpbc_noindex($string= "") {
	return 'noindex';
}

function wpbc_output_page($output, $is_error = false){
	show_admin_bar(true);

	do_action('wpbc_before_page');

	if ($is_error)
		header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found", true, 404);
	else
		header('HTTP/1.1 200 OK');

	?><!DOCTYPE html>
	<html <?php language_attributes(); ?> class="no-js no-svg">
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<link rel="profile" href="http://gmpg.org/xfn/11">
			<?php wp_head(); ?>
			<meta name="robots" content="noindex" />
		</head>
		<body <?php body_class(); ?>>
		<?php
			echo $output;
			wp_footer();
		?>
		</body>
	</html><?php
	exit();
}

add_action( 'init', 'wpbc_admin_bar_init' );
function wpbc_admin_bar_init(){
	global $wp_admin_bar;
	if (is_user_logged_in() && empty($wp_admin_bar) && function_exists('_wp_admin_bar_init'))
		_wp_admin_bar_init();
}

function &wpbc_get_api($type = null, $api_type = 'stamp_services'){
	$false = false;
	if (!$type && !($type = get_option('wpbc_provider')))
		return $false;
	static $apis = array();
	if (isset($apis[$api_type], $apis[$api_type][$type]))
		return $apis[$api_type][$type];

	try {
		foreach (wpbc_get_api_folders() as $folder){
			$path = $folder.'/'.$api_type.'/'.$type.'.php';
			if (file_exists($path)){
				require_once $path;
				break;
			}
		}

		$class = 'WPBC_API_'.ucfirst($type);
		$obj = new $class();
		$obj->provider_id = $type;
		if (!isset($apis[$api_type]))
			$apis[$api_type] = array();
		$apis[$api_type][$type] =& $obj;
		return $obj;

	} catch (Exception $e){
		return $false;
	}
}

function wpbc_url_to_postid($pagename){
	global $wpdb;
	if ($id = url_to_postid($pagename))
		return $id;

	$bits = explode('/', $pagename);
	$name = array_pop($bits);
	$q = $wpdb->prepare('SELECT ID FROM '.$wpdb->posts.' WHERE post_name = %s', $name);

	$id = $wpdb->get_var($q);
	if (!$id || !$pagename)
		return null;
	
	if (strstr(get_permalink($id), $pagename) !== false)
		return $id;

	return null;
}
