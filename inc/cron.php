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

if (!function_exists('wpbc_cron')){
	
	add_filter( 'cron_schedules', 'wpbc_cron_add_every_minute' );
	function wpbc_cron_add_every_minute( $schedules ) {
		$schedules['wpbc_every_minute'] = array(
				'interval'  => 60,
				'display'   => __( 'Every Minute', 'wpbc' )
		);
		return $schedules;
	}

	if ( ! wp_next_scheduled( 'wpbc_cron_add_every_minute' ) ) {
		wp_schedule_event( time(), 'wpbc_every_minute', 'wpbc_cron_add_every_minute' );
	}

	add_action( 'wpbc_cron_add_every_minute', 'wpbc_cron_add_every_minute_event_func' );
	function wpbc_cron_add_every_minute_event_func() {
		//return;
		wpbc_cron_start();
	}

	add_action('template_redirect', 'wpbc_cron');
	function wpbc_cron(){
		if (!empty($_GET['wpbc_cron']) && $_GET['wpbc_cron'] === wpbc_get_cron_key()){
			wpbc_cron_start();
			exit;
		}
	}
	
	function wpbc_cron_start(){

		update_option('wpbc_last_cron', date('Y-m-d H:i:s'));
		if (!empty($_GET['manual']) || !get_option('wpbc_block_cron', false)){

			$begin = time();

			wpbc_do_cron(false);

			$time = time() - $begin;
			echo sprintf(__('executed in %s', 'wpbc'), $time.'s').($time > 60 ? ' ('.wpbc_human_time_diff($time, false).')' : '');

			if (!empty($_GET['repeat'])){

				$wait = WPBC_IS_DEV && wpbc_is_admin() && !empty($_GET['frequency']) && is_numeric($_GET['frequency']) ? min(intval($_GET['frequency']) / 2, 10) : 10;

				?>
				<br/><?= sprintf(__('reloading in %s', 'wpbc'), $wait.'s') ?>.. (<?= sprintf(__('at %s', 'wpbc'), date_i18n('H:i:s', strtotime(get_date_from_gmt(date('Y-m-d H:i:s', time() + $wait))))) ?>)
				<script type="text/javascript"> setTimeout(function(){ location.reload(); }, <?= ($wait * 1000) ?>); </script>
				<?php
			}
			echo '<br/><br/>';

		} else
			echo __('CRON blocked from settings', 'wpbc');
	}

	function wpbc_get_cron_key(){
		static $cron_pass = null;
		if ($cron_pass === null){
			$cron_pass = get_option('wpbc_cron_key');
			if (empty($cron_pass)){
				$cron_pass = wpbc_random_str(40);
				update_option('wpbc_cron_key', $cron_pass);
			}
		}
		return $cron_pass;
	}

	add_action('wpbc_poorman', 'wpbc_poorman');
	function wpbc_poorman(){

		global $wpbc_api_ret;
		if (defined('WPBC_IS_AJAX') && WPBC_IS_AJAX
			&& defined('WPBC_IS_LIVE_AJAX') && WPBC_IS_LIVE_AJAX
			&& empty($wpbc_api_ret)
			&& apply_filters('wpbc_allow_poorman', get_option('wpbc_allow_poorman', true))
			&& !wpbc_is_cron_detected()){

			wpbc_do_cron(true);
		}
	}

	function wpbc_is_cron_detected(&$last_cron = null){
		$last_cron = get_option('wpbc_last_cron');
		return !empty($last_cron) && strtotime($last_cron) >= strtotime('-'.WPBC_CRON_DETECT_DELAY);
	}

	function wpbc_do_cron($poorman){
		global $wpdb;
		$wpdb->query($wpdb->prepare('DELETE FROM '.WPBC_DB_PREFIX.'locks WHERE locked_at < %s LIMIT 1000', date('Y-m-d H:i:s', strtotime('-20 minute'))));

		if ((wpbc_is_admin() && !empty($_GET['manual'])) || wpbc_lock('wpbc_cron', 'wpbc_do_cron')){
			define('WPBC_CRON_BEGIN', time());

			if (!$poorman){
				define('WPBC_CRON', true);

				ini_set('max_execution_time', 110);
				set_time_limit(110);

				echo sprintf(__('starting CRON at %s', 'wpbc'), date('Y-m-d H:i:s', WPBC_CRON_BEGIN)).'<br>';
			}

			if ($poorman)
				$frequency = 3;
			else {
				$frequency = wpbc_is_admin() && !empty($_GET['frequency']) && is_numeric($_GET['frequency']) ? intval($_GET['frequency']) : false;

				if (!$frequency)
					$frequency = 50;
			}
			$max_time = strtotime('+'.$frequency.' seconds', WPBC_CRON_BEGIN);

			if ($poorman){
				define('WPBC_CRON_POORMAN', true);
				ob_start();
			}

			do {
				do_action('wpbc_cron', $poorman, $max_time);
				if ($poorman || time() > $max_time - 10)
					break;

				sleep(5);

			} while (1);

			if ($poorman)
				ob_get_clean();

			wpbc_unlock('wpbc_cron');
		}
	}

}

add_action('wpbc_cron', 'wpbc_cron_check_stamps', 0, 2);
function wpbc_cron_check_stamps($doing_poorman, $max_time){
	global $wpdb;

	$amount = $doing_poorman ? 3 : 50;

	$sites = array();
	if (0 && function_exists( 'get_sites' )){
		foreach (get_sites() as $s)
			$sites[] = $s->blog_id;
	} else
		$sites[] = true;

	foreach (wpbc_get_blockchains(true) as $cbc_id => $cbc_config){
		if (!$doing_poorman)
			wpbc_log('info', 'checking '.$cbc_id.' block height');
		wpbc_get_block_height($cbc_id, true);
	}


	foreach (wpbc_get_blockchains(true) as $cbc_id => $cbc_config){
		if (!$doing_poorman)
			wpbc_log('info', 'checking '.$cbc_id.' block time');
		wpbc_get_block_time($cbc_id, true);
	}

	$max_duration = 0;
	foreach ($sites as $site_id){
		if (time() >= $max_time)
			break;

		if ($site_id !== true)
			switch_to_blog( $site->blog_id );

		if (!$doing_poorman)
			wpbc_log('info', 'starting autostamping');

		do_action('wpbc_before_cron', $doing_poorman, $max_time);

		if ($site_id !== true)
			restore_current_blog();
	}

	$max_duration = 0;
	foreach ($sites as $site_id){
		if ($site_id !== true)
			switch_to_blog( $site->blog_id );

		if (!$doing_poorman)
			wpbc_log('info', 'stamping queued stamps');

		foreach ($wpdb->get_results('SELECT stamp_base, hash, provider FROM '.WPBC_DB_PREFIX.'stamps WHERE status = "queued" ORDER BY created_at ASC LIMIT 200') as $s){
			if (time() > $max_time - $max_duration)
				break;

			$begin = time();

			$api = wpbc_get_api($s->provider);
			$api->stamp_from_queue($s->stamp_base, $s->hash);

			if (!$doing_poorman)
				wpbc_log('info', 'stamped from queue '.$s->stamp_base.'-'.$s->hash);

			$duration = time() - $begin;
			$max_duration = max($max_duration, $duration);
		}
		if ($site_id !== true)
			restore_current_blog();
	}

	$max_duration = 0;
	foreach ($sites as $site_id){
		if (time() >= $max_time)
			break;

		if ($site_id !== true)
			switch_to_blog( $site->blog_id );

		$planned = $wpdb->get_results($wpdb->prepare('SELECT stamp_base, hash FROM '.WPBC_DB_PREFIX.'stamps WHERE (next_update IS NOT NULL AND next_update <= %s) ORDER BY next_update ASC LIMIT 30', date('Y-m-d H:i:s')));

		if (!$doing_poorman)
			wpbc_log('info', count($planned).' stamps with next update');

		foreach ($planned as $s){
			if (time() > $max_time - $max_duration)
				break;

			$begin = time();
			$stamp_code = $s->stamp_base.'-'.$s->hash;

//			if (!$doing_poorman)
//				wpbc_log('info', 'stamp_by_code: '.$stamp_code);

			wpbc_stamp_by_code($stamp_code, false, !$doing_poorman);

			if (!$doing_poorman)
				wpbc_log('info', 'updated pending stamp '.$stamp_code);

			usleep(500000); // sleep 0.5s

			$duration = time() - $begin;
			$max_duration = max($max_duration, $duration);
		}

		if ($site_id !== true)
			restore_current_blog();
	}

	foreach ($sites as $site_id){
		if (time() >= $max_time - $max_duration)
			break;
		if ($site_id !== true)
			switch_to_blog( $site->blog_id );

		if (!$doing_poorman)
			wpbc_log('info', 'starting regular updates');

		$max_duration = 0;
		foreach ($wpdb->get_results($wpdb->prepare('SELECT stamp_base, hash FROM '.WPBC_DB_PREFIX.'stamps WHERE status = "confirmed" AND next_update IS NULL AND updated_at IS NOT NULL AND updated_at < %s ORDER BY updated_at ASC LIMIT 30', date('Y-m-d H:i:s', strtotime('-'.WPBC_MIN_UPDATE_PERIOD)))) as $s){
			if (time() > $max_time - $max_duration)
				break;

			$begin = time();
			$stamp_code = $s->stamp_base.'-'.$s->hash;

			wpbc_stamp_by_code($stamp_code, false, !$doing_poorman);

			if (!$doing_poorman)
				wpbc_log('info', 'regular update for stamp '.$stamp_code);

			$duration = time() - $begin;
			$max_duration = max($max_duration, $duration);
		}

		if ($site_id !== true)
			restore_current_blog();
	}
}

