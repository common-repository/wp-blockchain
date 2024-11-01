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

function wpbc_random_int($min, $max) {
	if (function_exists('random_int'))
		return random_int($min, $max);

	$range = $counter = $max - $min;
	$bits = 1;

	while ($counter >>= 1) {
		++$bits;
	}

	$bytes = (int)max(ceil($bits/8), 1);
	$bitmask = pow(2, $bits) - 1;

	if ($bitmask >= PHP_INT_MAX) {
		$bitmask = PHP_INT_MAX;
	}

	do {
		$result = hexdec(
			bin2hex(
				function_exists('mcrypt_create_iv')
				? mcrypt_create_iv($bytes, MCRYPT_DEV_URANDOM)
				: (
					function_exists('random_bytes')
					? random_bytes($bytes)
					: wpbc_random_string($bytes)
				)
			)
		) & $bitmask;
	} while ($result > $range);

	return $result + $min;
}

function wpbc_random_string($length){
	$bytes = '';
	while (strlen($bytes) < $length)
	  $bytes .= chr(mt_rand(0, 255));
	return $bytes;
}

if (!function_exists('wpbc_random_str')){
	function wpbc_random_str($length, $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'){
		$str = '';
		$max = mb_strlen($keyspace, '8bit') - 1;
		for ($i = 0; $i < $length; ++$i) {
			$str .= $keyspace[wpbc_random_int(0, $max)];
		}
		return $str;
	}
}

function wpbc_convert_to_live($live_id, $inner = null){

	$prepend = !empty($_GET['show_live']) && wpbc_is_admin() ? '<div class="wpbc-live-debug"><div>'.$live_id.'</div></div>' : '';

	return $prepend.'<div class="wpbc-stamp-live" data-wpbc-live-id="'.$live_id.'">'.($inner ? $inner : wpbc_get_live_return($live_id)).'</div>';
}

function wpbc_plural($arr, $last_sep = null, $reg_sep = ', '){
	if ($last_sep === null)
		$last_sep = ' '.__('and', 'wpbc').' ';
	if (count($arr) < 3)
		return implode($last_sep, $arr);
	$last = array_pop($arr);
	return implode($reg_sep, $arr).$last_sep.$last;
}

function wpbc_pretty_json($json){
	if (!is_string($json))
		$json = json_encode($json);

    $tc = 0;
    $r = '';
    $q = false;
    $t = "\t";
    $nl = "\n";

    for($i=0;$i<strlen($json);$i++){
        $c = $json[$i];
        if($c=='"' && $json[$i-1]!='\\') $q = !$q;
        if($q){
            $r .= $c;
            continue;
        }
        switch($c){
            case '{':
            case '[':
                $r .= $c . $nl . str_repeat($t, ++$tc);
                break;
            case '}':
            case ']':
                $r .= $nl . str_repeat($t, --$tc) . $c;
                break;
            case ',':
                $r .= $c.' ';
                if($json[$i+1]!='{' && $json[$i+1]!='[') $r .= $nl . str_repeat($t, $tc);
                break;
            case ':':
                $r .= $c . ' ';
                break;
            default:
                $r .= $c;
        }
    }
    return stripslashes(str_replace("\\r\\n", "\\\\r\\\\n", str_replace("\t", '<span style="width: 50px; display: inline-block;"></span>', nl2br(htmlentities($r)))));
}

function wpbc_print_copybox($str, $excerpt_mode = false, $excerpt_length = 20, $excerpt_from_end = -6){
	ob_start();
	?><span class="wpbc-copybox"><span onclick="jQuery(this).hide().next().show().focus().select(); return false;"><?php

		if ($excerpt_mode && strlen($str) > $excerpt_length)
			echo substr($str, 0, max($excerpt_length-8, 8)).'...'.($excerpt_from_end ? substr($str, $excerpt_from_end) : '');
		else
			echo $str;
	?></span><input style="display: none;" type="text" readonly="readonly" value="<?= esc_attr($str) ?>" onblur="jQuery(this).hide().prev().show(); " /></span><?php
	return ob_get_clean();
}

function wpbc_human_time_diff($time, $pattern = null, $pattern_negative = null, $multiple = null){
	if (!is_numeric($time))
		$time = time() - strtotime($time);

	if ($time < 0)
		return wpbc_human_time_diff(abs($time), $pattern_negative !== null ? $pattern_negative : __('in %s', 'wpbc'), null, $multiple);

	if ($pattern === null)
		$pattern = __('%s ago', 'wpbc');

	if ($time >= 60){

		if ($multiple !== null && $time / 60 > 10)
			$time = 10 * 60 * round($time / (10 * 60));

		$human = human_time_diff(time(), time()+$time);

	} else {

		if ($multiple !== null && $time > 20)
			$time = 10 * round($time / 10);

		$human = $time.'s';
	}
	return apply_filters('wpbc_human_time_diff', $pattern ? sprintf($pattern, $human) : $human, $time, $pattern, $pattern_negative, $multiple);
}

function wpbc_is_localhost(){
	static $is_localhost = null;
	if ($is_localhost === null)
		$is_localhost = apply_filters('wpbc_is_localhost', preg_match('#^https?://localhost[\./]#', site_url('/')));
	return $is_localhost;
}

function wpbc_is_admin(){
	return apply_filters('wpbc_is_admin', wpbc_user_can('admin_stamps'));
}

function wpbc_is_stampable_code($stamp_code){
	$parts = explode('-', $stamp_code);
	$post_id = intval(array_shift($parts));
	return apply_filters('wpbc_is_stampable_code', $post_id ? (array_shift($parts) == 'profile' ? wpbc_is_post_type_stampable('user') : wpbc_is_post_stampable($post_id)) : false, $stamp_code);
}

function wpbc_is_post_type_stampable($post_type){
	static $values = null;
	if ($values === null)
		$values = apply_filters('wpbc_post_types_stampable', wpbc_get_post_types());
	return isset($values[$post_type]) && apply_filters('wpbc_is_post_type_stampable', in_array($values[$post_type]['mode'], array('manual', 'auto')), $post_type);
}

function wpbc_set_ajax_headers(){
	header('Content-type: application/json');
	do_action('wpbc_ajax_headers');
}

function wpbc_user_can_stamp_code($stamp_code){
	$parts = explode('-', $stamp_code);
	$post_id = intval(array_shift($parts));

	return apply_filters('wpbc_user_can_stamp_code', $post_id

		? (
			array_shift($parts) == 'profile'
			? current_user_can('edit_users')
			: current_user_can('edit_post', $post_id)
		)

		: wpbc_is_admin(),

		 $stamp_code);
}




function wpbc_hash($str, $method = 'SHA256'){
	return strtoupper(hash($method, $str));
}

function wpbc_live_delay_anchor($delay){
	return $delay ? apply_filters('wpbc_live_delay_anchor', '<span style="display: none" class="wpbc-live-delay" data-wpbc-delay="'.$delay.'"></span>', $delay) : '';
}

function wpbc_log($type, $str){

	if (WPBC_LOG_LEVEL == 'none' || !WPBC_LOG_LEVEL)
		return;

	$str = apply_filters('wpbc_log', $str, $type);

	$intro = '['.date('Y-m-d H:i:s').']['.$type.'] ';
	if ((defined('WPBC_CRON') && $type != 'debug') || $type == 'force')
		echo $intro.$str.'<br>';

	$error_types = array('debug', 'info', 'error', 'api_error', 'force');
	if (array_search(WPBC_LOG_LEVEL, $error_types) <= array_search($type, $error_types) || in_array($type, array('force', 'error')))
		@file_put_contents(WPBC_LOG_PATH, $intro.$str."\n\n", FILE_APPEND);
}

function wpbc_add_metadata($meta_type, $object_id, $meta_key, $meta_value, $unique = false){
	$added = apply_filters('wpbc_add_metadata', null, $meta_type, $object_id, $meta_key, $meta_value, $unique);
	if ($added !== null)
		return $added;

	if ($meta_type == 'user')
		return add_user_meta($object_id, $meta_key, $meta_value, $unique);
	else
		return add_post_meta($object_id, $meta_key, $meta_value, $unique);
}

function wpbc_get_status_labels($location = ''){

	$labels = array();
	switch ($location){
		case 'states':
			$labels = array(
				'ready' => '<i class="fa fa-link"></i> '.__('Stamp it!', 'wpbc').' <span class="wpbc-settings-inline-desc">('.__('even if checked, such state will only be displayed to users that can stamp the item', 'wpbc').')</span>',
				'waiting' => '<i class="fa fa-clock-o"></i> '.__('Queued for stamping..', 'wpbc'),
				'stamping' => '<i class="fa fa-clock-o"></i> '.__('Stamping..', 'wpbc'),

				'stamped' => '<i class="fa fa-check"></i> '.__('Stamped', 'wpbc'),
			);
			break;

		case 'api':
			$labels = array(
				'api_result' => '<i class="fa fa-plug"></i> '.__('API result (%s)', 'wpbc'),
				'raw_result' => __('see raw result', 'wpbc'),
				'error' => __('Error', 'wpbc'),
				'unknown_stamp' => __('No such stamp', 'wpbc'),
				'unknown_action' => __('Action not recognized', 'wpbc')
			);
			break;

		case 'button':
			$labels = array(
				'stamp_it' => '<i class="fa fa-link"></i> '.__('Stamp it!', 'wpbc'),
				'stamping_send' => '<i class="fa fa-spinner fa-pulse fa-fw"></i> '.__('Queuing..', 'wpbc'),
				'queued' => '<i class="fa fa-clock-o"></i> '.__('Queued for stamping..', 'wpbc'),
				'stamping' => '<i class="fa fa-clock-o"></i> '.__('Stamping..', 'wpbc'),
				'stamped' => '<i class="fa fa-check"></i> '.__('Stamped', 'wpbc'),
				'stamped_legend' => __('in %s', 'wpbc'),
			);
			break;

		case 'settings':
			$labels = array(
				'queued' => __('queued for stamping..', 'wpbc'),
				'service_pending' => __('stamping..', 'wpbc'),
				'waiting' => __('stamping %s..', 'wpbc'),
				'pending' => __('waiting for %d blocks..', 'wpbc'),
				'pending_last_block' => __('waiting for 1 last block..', 'wpbc'),
				'stamping_failed' => __('stamping failed', 'wpbc'),
				'restamping_pending' => __('restamp pending..', 'wpbc'),
				'stamped' => __('confirmed', 'wpbc'),
				'done' => __('done', 'wpbc'),
				'unknown' => __('unknown', 'wpbc'),

				'unknown_context' => '<i class="fa fa-warning"></i> '.__('unknown context', 'wpbc'),
				'stamped_at_legend' => __('created at %s, stamped at %s, confirmed at %s (%s confirmations)', 'wpbc'),
			);
			break;

		case 'blockchain_status':
			$labels = array(
				'pending' => __('Waiting for %d blocks.. (%s left)', 'wpbc'),
				'waiting' => __('Stamping %s..', 'wpbc'),
				'service_pending' => __('Stamping..', 'wpbc'),
				'pending_last_block' => __('Waiting for one last block.. (%s left)', 'wpbc'),
				'stamped' => __('Stamped', 'wpbc'),
				'unknown' => __('Unknown state', 'wpbc'),
				'transaction_error' => __('Error stamping', 'wpbc'),
				'confirmations' => __('%s confirmations', 'wpbc'),
			);
			break;

		case 'api_return':
			$labels = array(
				'confirmed' => '<i class="fa fa-check"></i> '.__('Stamped%s', 'wpbc'),
				'pending' => '<i class="fa fa-clock-o"></i> '.__('Waiting for enough blocks..%s', 'wpbc'),
				'service_pending' => '<i class="fa fa-clock-o"></i> '.__('Queued for stamping..%s', 'wpbc'),
				'unknown' => '<i class="fa fa-warning"></i> '.__('Unknown%s', 'wpbc'),
				'not_found' => '<i class="fa fa-warning"></i> '.__('Not found', 'wpbc'),

				'in_particule' => __('in %s', 'wpbc'),
			);
			break;
	}
	return apply_filters('wpbc_get_status_labels', $labels, $location);
}

function wpbc_user_can($right, $arg = null, $arg2 = null){
	$can = false;
	switch ($right){
		case 'see_state':

			global $wpbc_tobar;
			$is_backend = wpbc_is_backend();

			if ($is_backend || !empty($wpbc_tobar))
				$can = true;
			else {
				$visible = get_option('wpbc_visible_states', array());
				$can = in_array($arg, $visible) || wpbc_user_can('edit_stamp_object', $arg, $arg2);
			}
			break;

		case 'edit_stamp_object':
			if (!wpbc_user_can('see_stamps'))
				$can = false;
			else
				$can = $arg == 'user' ? current_user_can('edit_users') : current_user_can('edit_post', $arg2);
			break;

		case 'see_stamp':
			if (!wpbc_user_can('see_stamps'))
				$can = false;

			else {

				$config = wpbc_get_post_types();
				if (!isset($config[$arg]) || $config[$arg]['mode'] === '')
					$can = false;
				else {
					switch ($config[$arg]['visibility']){
						case 'logged':
							$can = is_user_logged_in();
							break;

						case 'authors':
							$can = wpbc_is_admin() || (is_user_logged_in() && (!$arg2 || in_array(get_current_user_id(), wpbc_get_authors($arg2))));
							break;

						case 'public':
							$can = true;
							break;

						default:
							$can = wpbc_is_admin();
							break;
					}
				}

				$can = apply_filters('wpbc_can_see_stamp', $can, $arg, $arg2);
			}
			break;

		case 'see_stamps':
			$can = !get_option('wpbc_visible_only_admins', true) || wpbc_is_admin();
			break;

		case 'admin_stamps':
			$can = current_user_can(WPBC_SETTINGS_ROLE);
			break;

		case 'save_settings':
			$can = current_user_can(WPBC_SETTINGS_ROLE);
			break;
	}
	return apply_filters('wpbc_user_can', $can, $right, $arg, $arg2);
}

function wpbc_get_authors($post_id){
	$authors = array();
	if ($post_id && wpbc_post_type_supports(get_post_type($post_id), 'author') && ($p = get_post($post_id))){
		foreach (function_exists('get_coauthors') ? get_coauthors($post_id) : array(get_userdata($p->post_author)) as $author)
			if ($author)
				$authors[] = $author->ID;
	}
	return apply_filters('wpbc_get_authors', $authors, $post_id);
}

function wpbc_can_stamp_show_as($meta_type, $show_as, $post_id = null){
	$config = wpbc_get_post_types();

	$can = false;
	if (!isset($config[$meta_type]) || $config[$meta_type]['mode'] === '')
		$can = false;

	else if ($config[$meta_type]['backend'] == 'none' && in_array($show_as, array('adminbar', 'listing', 'edit')))
		$can = false;

	else if (!wpbc_user_can('see_stamp', $meta_type, $post_id))
		$can = false;

	else {
		$all = $config[$meta_type]['backend'] == 'all' || (current_user_can('manage_options') && !empty($_GET['wpbc_show_as_all']));

		switch ($show_as){
			case 'frontend':
			case 'certificate':

				if ($meta_type != 'user' && $post_id && get_post_status($post_id) != 'publish' && !current_user_can('read', $post_id))
					$can = false;

				else
					$can = true;

				break;

			case 'adminbar':
				$can = $all || strpos($config[$meta_type]['backend'], 'adminbar') !== false;
				break;

			case 'listing':
				$can = $all || strpos($config[$meta_type]['backend'], 'listing') !== false;
				break;

			case 'edit':
				$can = $all || strpos($config[$meta_type]['backend'], 'edit') !== false;
				break;
		}
	}
	return apply_filters('wpbc_can_stamp_show_as', $can, $meta_type, $show_as, $post_id);
}

function wpbc_get_name($user_id){
	$ret = trim(get_userdata($user_id)->display_name);
	if ($ret == ''){
		$str = array();
		$str[] = trim(get_userdata($user_id)->first_name);
		$str[] = trim(get_userdata($user_id)->last_name);
		$ret = implode(' ', $str);
	}
	return apply_filters('wpbc_get_name', $ret, $user_id);
}

function wpbc_lock($hash, $id = null){
	global $wpdb;
	$lock = wpbc_hash(wpbc_random_str(20).@session_id().time());
	@ignore_user_abort(true);
	$wpdb->insert(WPBC_DB_PREFIX.'locks', array(
		'lock_key' => $lock,
		'lock_hash' => $hash,
		'locked_at' => date('Y-m-d H:i:s')
	));
	$active_lock = (string) $wpdb->get_var($wpdb->prepare('SELECT lock_key FROM '.WPBC_DB_PREFIX.'locks WHERE lock_hash = %s ORDER BY id ASC LIMIT 1', $hash));

	if ($active_lock !== $lock){
		$wpdb->query($wpdb->prepare('DELETE FROM '.WPBC_DB_PREFIX.'locks WHERE lock_hash = %s AND lock_key = %s', $hash, $lock));
		wpbc_log('info', 'can\'t get a lock for hash/code '.$hash.' (id: '.$id.')');
		@ignore_user_abort(false);
		return false;
	}
	wpbc_log('debug', 'managed to get a lock for hash/code '.$hash.' (id: '.$id.')');
	return true;
}

function wpbc_unlock($hash){
	global $wpdb;
	$wpdb->query($wpdb->prepare('DELETE FROM '.WPBC_DB_PREFIX.'locks WHERE lock_hash = %s', $hash));
	wpbc_log('debug', 'unlocked lock for hash/code '.$hash);
	@ignore_user_abort(false);
}

function wpbc_check_index($table, $index_name, $index_columns){
	global $wpdb;
	if (!wpbc_has_index($table, $index_name))
		$wpdb->query("ALTER TABLE ".WPBC_DB_PREFIX.$table." ADD INDEX ".$index_name." (".implode(", ", $index_columns).")");
}

function wpbc_has_index($table, $index_name){
	global $wpdb;
	$indexes = $wpdb->get_results("SHOW INDEX FROM ".WPBC_DB_PREFIX.$table);
	foreach ($indexes as $i)
		if ($i->Key_name == $index_name)
			return true;
	return false;
}

function wpbc_get_merkle_hash($left, $hash, $right = ''){
	return strtoupper(wpbc_hash(hex2bin(strtoupper($left)).hex2bin(strtoupper($hash)).($right ? hex2bin(strtoupper($right)) : '')));
}

function wpbc_unsynced_by_base($stamp_base){
	$stamp = wpbc_get_stamp_by_code($stamp_base.'-last', false, true);
	$content = wpbc_get_stamp_content_by_code($stamp_base.'-last');
	if (empty($content))
		return false;
	if (!$stamp)
		return true;
	return apply_filters('wpbc_unsynced_by_base', $stamp['hash'] !== wpbc_hash($content), $stamp_base);
}

function wpbc_queue_code($code){

	$done = apply_filters('wpbc_queue_code', null, $code);
	if ($done !== null)
		return $done;

	$content = wpbc_get_stamp_content_by_code($code);
	$api = wpbc_get_api();
	$hash = $api->get_hash($content);
	$parts = explode('-', $code);
	$hash_or_last = array_pop($parts);

	if ($api->is_hash_stamped($hash, $stamp))
		return false;

	if (!wpbc_lock($hash, 'wpbc_queue_code'))
		return false;

	$stamp = array(
		'content' => $content,
		'hash' => $hash,
		'hash_method' => $api->hash_method,
		'provider' => $api->provider_id,
		'status' => 'queued',
		'stamp_base' => implode('-', $parts),
	);

	if (!$api->save_stamp($stamp)){
		wpbc_unlock($hash);
		return false;
	}
	wpbc_unlock($hash);
	return true;

}

function wpbc_live_ind(){
	return apply_filters('wpbc_live_ind', is_user_logged_in() ? '<span class="wpbc-live-ind" title="'.esc_attr(__('click to pause/resume the auto-refresh', 'wpbc')).'"></span>' : '');
}

function wpbc_fetch($url, $return_json = false, $type = 'get', $data = array(), $headers = array(), $timeout = 10, $allow_wait = true){

	if ($type == 'get' && $data)
		$url .= '?'.http_build_query($data);

	//wpbc_log('debug', 'fetching URL '.$url);

	$process = curl_init();
	curl_setopt($process, CURLOPT_URL, $url);

	$headers = apply_filters('wpbc_fetch_headers', $headers, $url, $type, $data);
	if ($headers)
		curl_setopt($process, CURLOPT_HTTPHEADER, $headers);

	curl_setopt($process, CURLOPT_TIMEOUT, $timeout);
	curl_setopt($process, CURLOPT_FOLLOWLOCATION, true);
	if ($type == 'post' && $data)
		curl_setopt($process, CURLOPT_POSTFIELDS, $data);
		
	curl_setopt($process, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($process, CURLOPT_HEADER, 1);

	if ($allow_wait && ($wait = apply_filters('wpbc_fetch_wait', 0, $process, $url, $type, $data, $headers))){
		curl_close($process);
		return false;
	}
	/*
	curl_setopt($process, CURLOPT_HEADERFUNCTION,
		function($curl, $header) use (&$headers){
			$len = strlen($header);
			$header = explode(':', $header, 2);
			if (count($header) < 2) // ignore invalid headers
			return $len;

			$name = strtolower(trim($header[0]));
			if (!array_key_exists($name, $headers))
			$headers[$name] = [trim($header[1])];
			else
			$headers[$name][] = trim($header[1]);

			return $len;
		}
	);*/
	
	$useragents = array(
		'Mozilla/5.0 (X11; Linux i586; rv:63.0) Gecko/20100101 Firefox/63.0',
		'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13',
		'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:17.0) Gecko/20100101 Firefox/17.0.6',
		'Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/44.0.2403.155 Safari/537.36',
		'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/37.0.2062.124 Safari/537.36'
	);
		
	// use a random user-agent for the cUrl request
	curl_setopt($process, CURLOPT_USERAGENT, $useragents[array_rand($useragents, 1)]);

	$ret = curl_exec($process);
	
	list($header, $body) = explode("\r\n\r\n", $ret, 2);
	
	$header = explode("\n", $header);
	
	$httpcode = substr($header[0], 9, 3);
	
	$ret = apply_filters('wpbc_fetch_return', $body, $process, $url, $httpcode, $type, $data, $headers);

	curl_close($process);

	if ($httpcode < 200 || $httpcode >= 300){ // NOT 2**
		wpbc_trigger_fetch_error($url, $httpcode, 'bad return code');
		//print_r($header);
		return false;

	} else if (empty($ret)){
		wpbc_trigger_fetch_error($url, $httpcode, 'NO_BODY');
		return false;

	}

	wpbc_log('info', 'fetched URL '.$url.' => '.$httpcode);

	if (!$return_json)
		return $body;

	try {
		$json = json_decode($body);
	} catch (Exception $e){
		wpbc_trigger_fetch_error($url, $httpcode, 'INVALID_JSON');
		return false;
	}
	return $json;
}

function wpbc_trigger_fetch_error($url, $httpcode, $error_str_or_code = null){
	do_action('wpbc_api_error', $url, $httpcode, $error_str_or_code);
	wpbc_log('api_error', 'FETCH ERROR FOR URL '.$url.' ('.$httpcode.')'.($error_str_or_code ? ': '.$error_str_or_code : ''));
}


function wpbc_get_blockchains($filtered = false){
	static $blockchains = null;
	if ($blockchains === null){
		$blockchains = apply_filters('wpbc_blockchains', array(
			'btc' => array(
				'name' => __('Bitcoin', 'wpbc'),
				'transaction_confirmation_provider' => 'blocktrail.com',
				'transaction_base_url' => 'https://www.blocktrail.com/BTC/tx/%s',
				'safe_confirmations_amount' => 6,
				'block_duration' => 10*60,
				'block_duration_warning' => 15*60,
				'block_duration_error' => 20*60,
				'transaction_warning_late' => 40*60,
				'transaction_error_delay' => 60*60,
				'transaction_restamp_delay' => 1.5*60*60,
				'min_virtual_confirmations' => 15
			),
		));

	}
	if ($filtered){
		static $filtered_blockchains = null;
		if ($filtered_blockchains === null){

			$filtered_blockchains = array();
			$allowed = get_option('wpbc_blockchains', array());
			foreach ($blockchains as $bc_id => $bc_config)
				if (in_array($bc_id, $allowed)){
					$filtered_blockchains[$bc_id] = $bc_config;
				}
			$filtered_blockchains = apply_filters('wpbc_blockchains_filtered', $filtered_blockchains);
		}

		return $filtered_blockchains;
	}
	return $blockchains;
}

function wpbc_convert_bytes($bytes){
	$si_prefix = array('B', 'KB', 'MB', 'GB', 'TB', 'EB', 'ZB', 'YB');
	$base = 1024;
	$class = min((int)log($bytes, $base), count($si_prefix) - 1);
	return sprintf('%1.1f', $bytes / pow($base,$class)).' '.$si_prefix[$class];
}

function wpbc_get_api_folders(){
	return apply_filters('wpbc_api_folders', array(WPBC_PATH.'/inc/api'));
}

function wpbc_get_template_folders(){
	return apply_filters('wpbc_template_folders', array(WPBC_PATH.'/templates'));
}

function wpbc_post_type_supports($post_type = null, $feature = null){
	static $cache = array();
	if (!$post_type){
		foreach (array_merge(get_post_types(array(
			'public' => true,
		)), get_post_types(array(
			'public' => false,
		))) as $post_type)
			$cache[$post_type] = array(
				'author' => post_type_supports($post_type, 'author'),
			);
	} else
		return isset($cache[$post_type], $cache[$post_type][$feature])
			? $cache[$post_type][$feature]
			: post_type_supports($post_type, $feature);
}





add_filter('wpbc_fetch_headers', 'wpbc_fetch_headers_filter', 0, 4);
function wpbc_fetch_headers_filter($headers, $url, $type, $data){
	// log domain
	if ($d = wpbc_get_domain($url)){
		$calls = get_option('wpbc_domain_calls', array());
		if (!isset($calls[$d]))
			$calls[$d] = array('count' => 0, 'last_begin' => time());
		else
			$calls[$d]['last_begin'] = time();
		if (empty($calls[$d]['last_frame_begin']))
			$calls[$d]['last_frame_begin'] = time();
		update_option('wpbc_domain_calls', $calls);
	}
	return $headers;
}

add_filter('wpbc_fetch_wait', 'wpbc_fetch_wait_filter', 0, 6);
function wpbc_fetch_wait_filter($wait, $process, $url, $type, $data, $headers){
	if ($d = wpbc_get_domain($url)){
		$calls = get_option('wpbc_domain_calls', array());

		if (isset($calls[$d]) && !empty($calls[$d]['wait'])){
			$wait = $calls[$d]['wait_from'] + $calls[$d]['wait'] - time();
			if ($wait > 0){
				wpbc_log('info', 'should wait '.$wait.'s to fetch '.$url);
				return $wait; // postpone call
			}
			$calls[$d]['wait'] = 0;
		}

		$calls[$d]['last_call'] = $calls[$d]['last_begin'];

		if (!isset($calls[$d]))
			$calls[$d] = array('count' => 1, 'wait' => 0);
		else
			$calls[$d]['count']++;
		update_option('wpbc_domain_calls', $calls);
	}
	return $wait;
}

add_action('wpbc_api_error', 'wpbc_api_error_filter', 0, 3);
function wpbc_api_error_filter($url, $httpcode, $error_str_or_code = null){
	if ($d = wpbc_get_domain($url)){
		if (!$error_str_or_code)
			switch ($httpcode){
				case 409:
					$error_str_or_code = 'CONFLICT';
					break;
				case 429:
					$error_str_or_code = 'TOO_MANY_REQUESTS';
					break;
				default:
					$error_str_or_code = 'BAD_RETCODE';
			}

		$calls = get_option('wpbc_domain_calls', array());

		switch ($httpcode){
			case 409:
			case 200:
				$wait = 60; // wait for 1min
				break;
			case 429:
				$wait = 15 * 60; // wait for 15min
				break;
			default:
				$wait = 10 * 60; // wait for 10min
		}

		if (!isset($calls[$d]))
			$calls[$d] = array('count' => 0, 'wait' => $wait);
		else
			$calls[$d]['wait'] = $wait;

		$calls[$d]['wait_from'] = time();

		$calls[$d]['last_error'] = $error_str_or_code;

		$calls[$d]['last_frame_count'] = $calls[$d]['count'];
		$calls[$d]['count'] = 0;
		$calls[$d]['last_frame_duration'] = time() - $calls[$d]['last_frame_begin'];
		$calls[$d]['last_frame_begin'] = time();

		update_option('wpbc_domain_calls', $calls);
	}
}

add_filter('wpbc_fetch_return', 'wpbc_fetch_return_filter', 0, 7);
function wpbc_fetch_return_filter($ret, $process, $url, $httpcode, $type, $data, $headers){
	if ($d = wpbc_get_domain($url)){
		$calls = get_option('wpbc_domain_calls', array());
		$calls[$d]['last_code'] = $httpcode;
		if (wpbc_is_code_ok($httpcode))
			$calls[$d]['last_error'] = null;
		$calls[$d]['last_duration'] = time() - $calls[$d]['last_begin'];
		update_option('wpbc_domain_calls', $calls);
	}
	return $ret;
}

function wpbc_get_domain($url, $full = true){
	return preg_match('#^https?://((?:[a-z0-9-_]+\.)*([a-z0-9-_]+\.[a-z0-9-_]+))(?:/.*)?$#iu', $url, $m) ? $m[$full ? 1 : 2] : false;
}

function wpbc_is_code_ok($code){
	return in_array($code, array(200, 409));
}
