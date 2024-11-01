<?php
/*
WP Blockchain
https://wp-blockchain.com
License: GPLv2 or later

Copyright (c) 2018, Good Rebels Inc.
Please feel free to contact us at wp-blockchain@goodrebels.com.
*/


class WPBC_API_Stampery extends WPBC_API_Stamp {

	public $api_url = 'https://api-prod.stampery.com';
	public $api_url_testnet = 'https://api-beta.stampery.com';

	public $hash_method = 'SHA256';
	public $check_frequency = '10 seconds';

	public function get_api_config($attr = null){
		$config = array(
			'name' => 'Stampery.io',
			'stamp_frequency' => 60,
			'queued_extra_delay' => 30,
			'stamping_duration' => 14
		);
		return $attr ? (isset($config[$attr]) ? $config[$attr] : null) : $config;
	}

	public function get_public_args(){
		return array(
			'fields' => array(
				'username' => __('Client ID', 'wpbc'),
				'password' => __('Client secret', 'wpbc'),
			),
			'info' => sprintf(__('API keys can be generated from %s', 'wpbc'), '<a rel="nofollow" href="https://api-dashboard.stampery.com/tokens" target="_blank">'.sprintf(__('the %s\'s API dashboard', 'wpbc'), 'Stampery.io').'</a>')
		);
	}

	public function get_stamp_url_from_code($stamp_code, $get_vars = false){
		$parts = explode('-', $stamp_code);
		$post_id = intval(array_shift($parts));
		$stamp_type = array_shift($parts);
		if (empty($parts))
			$parts = array('last');

		$uri = untrailingslashit(str_replace(site_url('/'), '', $stamp_type == 'profile' ? get_userdata($post_id)->user_nicename : get_permalink($post_id)));
		if ($uri == '')
			$uri = '-';

		$url = site_url('api/stamps/'.$uri.'/'.$stamp_type.'/'.implode('/', $parts).'/');
		if ($get_vars !== false){
			if ($get_vars === true)
				$get_vars = !empty($_GET) ? $_GET : array();
			if ($get_vars)
				$url .= '?'.http_build_query($get_vars);

		}
		return $url;
	}

	public function test(){
		$ret = $this->list_all();
		return !empty($ret) && !$ret->error;
	}

	public function list_all(){
		return $this->get('stamps');
	}

	public function create_stamp($str, $stamp_base, $restamping = false, $debug = false){
		global $wpdb;

		if (empty($str) || !($hash = $this->get_hash($str)))
			return false;

		$lock_key = $hash.($restamping ? '-restamp' : '');
		if (!wpbc_lock($lock_key, 'create_stamp'))
			return false;

		if ($restamping)
			wpbc_log('info', 'restamping hash '.$hash);
		else
			wpbc_log('info', 'creating stamp for hash '.$hash);

		$p = $this->post('stamps', array('hash' => $hash));

		if (!$p || $p->error){
			wpbc_log('error', 'error from Stampery: '.($p ? $p->error : 'unknown'));
			wpbc_unlock($lock_key);
			return false;
		}

		$this->save_receipts($hash, $p->result, $pending, false, $debug);

		$stamp = array(
			'confirmed_at' => null,
			'provider' => $this->provider_id,
			'provider_id' => $p->result->id,
			'status' => 'pending',
			'pending_time' => $pending,
			'next_update' => date('Y-m-d H:i:s', strtotime('+5 minute')),
			'updated_at' => date('Y-m-d H:i:s'),
		);
		if ($restamping)
			$stamp += array(
				'restamped_at' => date('Y-m-d H:i:s', strtotime($p->result->time)),
			);
		else
			$stamp += array(
				'stamped_at' => date('Y-m-d H:i:s', strtotime($p->result->time)),
				'restamped_at' => null,
				'hash' => $hash,
				'content' => $str,
				'hash_method' => $this->hash_method,
				'stamp_base' => $stamp_base,
			);

		if (!$this->save_stamp($stamp, $restamping)){
			wpbc_unlock($lock_key);
			return false;
		}

		if ($restamping){
			$stamp += $wpdb->get_row($wpdb->prepare('SELECT content, created_at, hash, hash_method, stamp_base FROM '.WPBC_DB_PREFIX.'stamps WHERE stamp_id = %d', $restamping), ARRAY_A);
		}

		wpbc_unlock($lock_key);
		wpbc_log('info', $restamping ? 'restamped '.$hash : 'stamped '.$hash);

		$this->stamp_updated($stamp);

		$this->check_transactions($stamp, true, $debug);

		return $stamp;
	}

	function stamp_from_queue($stamp_base, $hash){
		global $wpdb;

		if (!wpbc_lock($hash, 'stamp_from_queue'))
			return false;

		if (!($stamp = wpbc_get_stamp_by_hash($hash)))
			return false;

		$httpcode = null;
		$p = $this->post('stamps', array('hash' => $hash), array(), 10, $httpcode);

		if (!$p || $p->error){
			wpbc_log('error', 'error from Stampery: '.($p ? $p->error : ($httpcode ? $httpcode : 'unknown')));
			wpbc_unlock($hash);
			return false;
		}

		$this->save_receipts($hash, $p->result, $pending_time);

		$update = array(
			'stamped_at' => $p->result->time,
			'provider_id' => $p->result->id,
			'status' => 'pending',
			'pending_time' => $pending_time,
		);

		$update_args = array(
			'pending_time' => maybe_serialize($pending_time),
			'next_update' => $this->get_next_update($hash)
		) + $update;

		wpbc_log('info', 'updating '.$stamp_base.' with hash '.$hash.' with next update on '.$update_args['next_update']);

		if (!$wpdb->update(WPBC_DB_PREFIX.'stamps', $update_args, array(
			'stamp_base' => $stamp_base,
			'hash' => $hash
		))){
			wpbc_unlock($hash);
			return false;
		}

		wpbc_unlock($hash);
		$this->check_transactions($stamp, true);
		return true;
	}

	function save_receipts($hash, $result, &$pending = array(), $save_pending = false, $debug = false){
		global $wpdb;
		
		if (!$result || !is_array($result))
			return;
			
		wpbc_log('debug', 'saving '.count($result).' receipts for hash '.$hash);

		foreach ($result as $r){
			if (property_exists($r, 'receipts') && $r->receipts && !strcasecmp($r->hash, $hash)){
				$pending['time'] = date('Y-m-d H:i:s');
				$pending['pending'] = array();
				foreach (wpbc_get_blockchains(true) as $cbc_id => $config){
					if (property_exists($r->receipts, $cbc_id)){
						$bc_receipt = $r->receipts->{$cbc_id};

						if (is_numeric($bc_receipt)){

							$pending['pending'][$cbc_id] = isset($pending['pending'][$cbc_id]) ? min($pending['pending'][$cbc_id], $bc_receipt) : $bc_receipt;

						} else {

							$merkle_ok = $this->get_merkle_path($hash, $bc_receipt->merkleRoot, property_exists($bc_receipt, 'proof') ? $bc_receipt->proof : array());

							if (!$merkle_ok){
								wpbc_log('error', 'error with HASH '.$hash.' and ROOT '.$bc_receipt->merkleRoot);
								continue;
							} else
								wpbc_log('debug', 'confirmed merkle ROOT '.$bc_receipt->merkleRoot.' for HASH '.$hash);

							foreach ($bc_receipt->anchors as $a){

								if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.WPBC_DB_PREFIX.'stamp_txs WHERE bc_id = %s AND tx_id = %s AND hash = %s', $cbc_id, $a->sourceId, $bc_receipt->merkleRoot)))
									$wpdb->insert(WPBC_DB_PREFIX.'stamp_txs', array(
										'tx_id' => $a->sourceId,
										'hash' => $bc_receipt->merkleRoot,
										'prefix' => $a->prefix,
										'bc_id' => $cbc_id,
										'created_at' => date("Y-m-d H:i:s"),
										'status' => 'pending'
									));
							}
						}

					} else
						wpbc_log('info', 'no receipt for '.$cbc_id);
				}
			}
		}

		if ($save_pending){
			$wpdb->query($wpdb->prepare('UPDATE '.WPBC_DB_PREFIX.'stamps SET pending_time = %s WHERE hash = %s AND hash_method = %s', maybe_serialize($pending), $hash, $this->hash_method));

		}
	}

	function get_merkle_path($hash, $merkle_root, $proof){
		global $wpdb;

		if ($hash == $merkle_root)
			return true;

		$curhash = $hash;
		wpbc_log('debug', 'testing HASH '.$hash.' for ROOT '.$merkle_root);

		if ($tree = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.WPBC_DB_PREFIX.'stamp_trees WHERE hash = %s AND hash_method = %s AND merkle_root = %s', $curhash, $this->hash_method, $merkle_root), ARRAY_A)){
			wpbc_log('debug', 'success recalculating tree for HASH '.$hash.' and ROOT '.$merkle_root.' (already in DB)');
			return maybe_unserialize($tree['siblings']);
		}

		if (empty($proof)){
			wpbc_log('error', 'failed recalculating tree for HASH '.$hash.' and ROOT '.$merkle_root.' (no proof given!)');
			return false;
		}

		$checked_proof = array();
		foreach ($proof as $p){
			$p = (array) $p;
			$curhash = wpbc_get_merkle_hash(
				!empty($p['left']) ? $p['left'] : null,
				$curhash,
				!empty($p['right']) ? $p['right'] : null
			);
			$checked_proof[] = $p;
		}

		if ($curhash !== $merkle_root){
			wpbc_log('error', 'failed recalculating tree for HASH '.$hash.' and ROOT '.$merkle_root.'. Siblings are: '.json_encode($proof));
			return false;
		}

		$insert = array(
			'hash' => $hash,
			'hash_method' => $this->hash_method,
			'siblings' => maybe_serialize($checked_proof),
			'merkle_root' => $merkle_root,
			'parent_tree' => null
		);

		if (!($last_tree_id = $wpdb->get_var($wpdb->prepare('SELECT tree_id FROM '.WPBC_DB_PREFIX.'stamp_trees WHERE hash = %s AND hash_method = %s AND merkle_root = %s', $insert['hash'], $insert['hash_method'], $insert['merkle_root'])))){
			$wpdb->insert(WPBC_DB_PREFIX.'stamp_trees', $insert);
			$last_tree_id = $wpdb->insert_id;
		}
		wpbc_log('debug', 'success recalculating tree for HASH '.$hash.' and ROOT '.$merkle_root);
		return $checked_proof;
	}

	function declare_confirmation_amount(&$stamp, &$tx, $bc_config, $amount, $confirmed_at, $block_number = null){
		global $wpdb;
		$is_confirmed = $amount !== null && ($amount >= $bc_config['safe_confirmations_amount']);

		$exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM '.WPBC_DB_PREFIX.'stamp_tx_confirmations WHERE tx_id = %s', $tx['tx_id']));

		if (empty($tx['block_number']) && $block_number)
			$tx['block_number'] = $block_number;

		if ($exists){

			$wpdb->query($wpdb->prepare('UPDATE '.WPBC_DB_PREFIX.'stamp_tx_confirmations SET confirmations = %d, provider = %s WHERE tx_id = %s', $amount, $bc_config['transaction_confirmation_provider'], $tx['tx_id']));
			if ($block_number)
				$wpdb->query($wpdb->prepare('UPDATE '.WPBC_DB_PREFIX.'stamp_tx_confirmations SET block_number = %s WHERE tx_id = %s', $block_number, $tx['tx_id']));

			if ($is_confirmed)
				$wpdb->query($wpdb->prepare('UPDATE '.WPBC_DB_PREFIX.'stamp_tx_confirmations SET confirmed_at = %s WHERE tx_id = %s AND confirmed_at IS NULL', $confirmed_at, $tx['tx_id']));

		} else {

			$wpdb->insert(WPBC_DB_PREFIX.'stamp_tx_confirmations', array(
				'tx_id' => $tx['tx_id'],
				'confirmations' => $amount,
				'provider' => $bc_config['transaction_confirmation_provider'],
				'confirmed_at' => $is_confirmed ? $confirmed_at : null,
				'block_number' => $block_number
			));
		}

		$tx['confirmations'] = $amount;

		if ($amount !== null && $tx['status'] != 'confirmed'){
			$tx['status'] = 'confirmed';
			$tx['confirmed_at'] = $confirmed_at;
			$wpdb->query($wpdb->prepare('UPDATE '.WPBC_DB_PREFIX.'stamp_txs SET status = %s WHERE tx_id = %s AND bc_id = %s', 'confirmed', $tx['tx_id'], $tx['bc_id']));
		}

		return $is_confirmed ? $confirmed_at : null;
	}

	function api(&$stamp, $stamp_code, $update = false, $cache = false){
		static $cache;
		if (!$stamp)
			return false;
		if (is_string($stamp) || !isset($stamp['stamp_id'])){
			return false;
			//print_r($stamp);
			//die("BAD");
		}

		$id = $stamp_code.'-'.$stamp['stamp_id'];

		$parts = explode('-', $stamp_code);
		$post_id = intval(array_shift($parts));

		if (empty($parts))
			return false;

		$stamp_type = array_shift($parts);
		$stamp_base = $post_id.'-'.$stamp_type;

		if ($cache && isset($cache[$id]) && (!$update || $cache[$id]['updated']))
			return $cache[$id]['ret'];

		if ($update)
			$this->check_transactions($stamp, true);

		$txs = $pending = array();
		$blockchains = wpbc_get_blockchains(true);

		if ($stamp_type == 'profile')
			$context_name = wpbc_get_name($post_id);
		else
			$context_name = get_the_title($post_id);

		$vars = apply_filters('wpbc_stamp_api', array(
			'context_id' => $post_id,
			'context_name' => $context_name,
			'url' => $stamp_type == 'profile' ? get_author_posts_url($post_id) : get_permalink($post_id),
			'stamp_type' => $stamp_type,
			'stamp_code' => $stamp_code,
			'stamps_argument' => array()
		), $stamp);
		extract($vars);

		$stamps = $stamp_type == 'profile'
			? wpbc_get_user_stamps($context_id, true, $stamps_argument)
			: wpbc_get_stamps($context_id, true, $stamps_argument);

		$stamp_title = isset($stamps[$stamp_type]) ? (isset($stamps[$stamp_type]['stamp_label']) ? $stamps[$stamp_type]['stamp_label'] : $stamps[$stamp_type]['label']) : $stamp_type;

		$stamp_title = apply_filters('wpbc_stamp_api_title', $stamp_title, $vars);

		$content_decoded = @json_decode($stamp['content']);
		if ($content_decoded === null)
			$content_decoded = $stamp['content'];

		$ret = array(
			'context_id' => $context_id,
			'context_name' => $context_name,
			'context_url' => $url,
			'stamp_id' => intval($stamp['stamp_id']),
			'stamp_type' => $stamp_type,
			'stamp_code' => $stamp_code,
			'stamp_name' => $stamp_title,
			'hash' => $stamp['hash'],
			'hash_method' => strtoupper($stamp['hash_method']),
			'status' => $stamp['status'],
			'confirmations' => 0,
			'pending_time' => 0,
			'created_at_gmt' => $stamp['created_at'],
			'created_at' => $stamp['created_at'] ? get_date_from_gmt($stamp['created_at']) : null,
			'stamped_at_gmt' => !empty($stamp['stamped_at']) ? $stamp['stamped_at'] : null,
			'stamped_at' => !empty($stamp['stamped_at']) ? get_date_from_gmt($stamp['stamped_at']) : null,
			'restamped_at_gmt' => $stamp['restamped_at'],
			'restamped_at' => $stamp['restamped_at'] ? get_date_from_gmt($stamp['restamped_at']) : null,
			'confirmed_at_gmt' => $stamp['confirmed_at'],
			'confirmed_at' => $stamp['confirmed_at'] ? get_date_from_gmt($stamp['confirmed_at']) : null,
			'updated_at_gmt' => $stamp['updated_at'],
			'updated_at' => $stamp['updated_at'] ? get_date_from_gmt($stamp['updated_at']) : null,
			'valid_until_gmt' => null,
			'valid_until' => null,
			'gmt_offset' => intval(get_option('gmt_offset')),
			'blockchains' => array(),
			'content_decoded' => $content_decoded,
			'content' => $stamp['content']
		);

		if (($api = wpbc_get_api()) && ($history = $api->get_history($stamp_base))){
			foreach ($history as $h)
				if ($h['stamp_id'] == $stamp['stamp_id']){
					if ($h['until'] != 'now'){
						$ret['valid_until_gmt'] = date('Y-m-d H:i:s', $h['until']);
						$ret['valid_until'] = get_date_from_gmt(date('Y-m-d H:i:s', $h['until']));
					}
					break;
				}
		}

		$api_config = $this->get_api_config();

		foreach ($blockchains as $bc_id => $config){
			if (!is_wpbc_blockchain_enabled($bc_id))
				continue;

			$last_block_number = wpbc_get_block_height($bc_id);

			$txs = $this->get_receipts_for_api_ret($stamp, $bc_id);
			foreach ($txs as &$tx){
				if (is_string($tx['confirmations']))
					$tx['confirmations'] = intval($tx['confirmations']);

				if ($tx['confirmations'] && $tx['confirmations'] >= $config['min_virtual_confirmations'] && !empty($tx['block_number']) && $last_block_number)
					$tx['confirmations'] = max($tx['confirmations'], $last_block_number - $tx['block_number']);

				$tx['siblings'] = maybe_unserialize($tx['siblings']);
			}
			unset($tx);

			$pending_time = 0;

			if (!empty($stamp['pending_time']) && isset($stamp['pending_time']['pending'], $stamp['pending_time']['pending'][$bc_id]))
				$pending_time = ($stamp['pending_time']['pending'][$bc_id] - (time() - strtotime($stamp['pending_time']['time'])));

			$ret_status = empty($txs) && !$pending_time && !empty($stamp['updated_at']) ? 'unknown' : 'pending';

			//if ($ret_status !== 'confirmed' && $ret['status'] == 'confirmed')
			//	$ret['status'] = 'pending';

			$cbc = array(
				'blockchain' => array(
					'id' => $bc_id,
					'name' => $config['name'],
					'block_duration' => $config['block_duration'],
					'safe_confirmations_amount' => $config['safe_confirmations_amount'],
					'last_block' => wpbc_get_block_height($bc_id),
					'last_block_time' => ($time = wpbc_get_block_time($bc_id)) ? get_date_from_gmt($time) : null,
					'last_block_time_gmt' => $time,
					'transaction_base_url' => $config['transaction_base_url'],
				),
				'status' => $ret_status,
				'confirmed_at_gmt' => null,
				'confirmed_at' => null,
				'confirmations' => 0,
			);

			foreach ($txs as $tx){
				if ($tx['status'] == 'confirmed' && $tx['confirmations'] >= $config['safe_confirmations_amount']){
					$ret_status = $cbc['status'] = 'confirmed';
					$cbc['confirmed_at_gmt'] = $tx['confirmed_at'];
					$cbc['confirmed_at'] = get_date_from_gmt($tx['confirmed_at']);
				}
				$cbc['confirmations'] += $tx['confirmations'];
				$ret['confirmations'] += $tx['confirmations'];
			}

			$cbc += array(
				'stamped_in' => $ret_status == 'confirmed' ? 0 : $pending_time,
				'pending_blocks' => 0,
				'pending_time' => $ret_status == 'confirmed' ? 0 : $pending_time,
				'transactions' => $txs,
			);

			if (empty($cbc['confirmations']) || $cbc['confirmations'] < $config['safe_confirmations_amount']){
				if (isset($config['block_duration'])){
					$pending_blocks = $cbc['pending_blocks'] = max($config['safe_confirmations_amount'] - $cbc['confirmations'], 0);
					$cbc['pending_time'] += $cbc['pending_blocks'] * $config['block_duration'];
				}
			}

			if ($pending_time && $cbc['status'] != 'confirmed'){
				$cbc['pending_time'] += $api_config['stamp_frequency'] / 2;
				$cbc['stamped_in'] += $api_config['stamp_frequency'] / 2;
			}

			$ret['blockchains'][$bc_id] = $cbc;

			if ($cbc['status'] != 'confirmed' && !empty($cbc['pending_time']) && (!$ret['pending_time'] || $ret['pending_time'] > $cbc['pending_time'])){
				$ret['pending_time'] = $cbc['pending_time'];
				$ret['pending_time'] += $api_config['queued_extra_delay'];
			}
		}

		/*
		$c = 0;
		foreach ($ret['blockchains'] as $bc_id => $config)
			if ($config['status'] == 'confirmed')
				$c++;
		if (!$c && $ret['status'] == 'confirmed'){
			$ret['status'] = 'pending';
			foreach ($ret['blockchains'] as $bc_id => $config){
				$ret['status'] = $config['status'];
				break;
			}
		}
		*/

		$cache[$id] = array(
			'ret' => $ret,
			'updated' => $update
		);

		return $ret;
	}

	function get_stamp_button($stamp, $stamp_code, $update = false, $theme = 'regular', $force_show = true){
		static $cache;

		global $wpbc_api_ret, $wpbc_tobar;
		$parts = explode('-', $stamp_code);
		$stamp_type = $parts[0];

		if ($msg = apply_filters('wpbc_button', false, array(
			'stamp' => $stamp,
			'stamp_code' => $stamp_code,
			'parts' => $parts,
			'stamp_type' => $stamp_type
		)))
			return $msg;
		if ($stamp && empty($stamp['content']))
			return wpbc_user_can_stamp_code($stamp_code) ? 'Nothing to stamp' : '';

//echo 'get_stamp_button: '.$stamp_code.'<br>';
//var_dump($stamp);
		$c = $this->api($stamp, $stamp_code);

		$use_button = false;
		$legend = null;

		$labels = wpbc_get_status_labels('button');

		$bc_count = 1;
		if (!$stamp){
			if (!wpbc_is_stampable_code($stamp_code))
				return '';

			if (!wpbc_user_can_stamp_code($stamp_code))
				return '';

			$use_button = true;
			$label = $labels['stamp_it'];
			$status = 'ready';
			$bc_count = 2;

			$update_delay = 50;

		} else if ($stamp['status'] == 'queued' || !($this->get_receipts($stamp, $update))){
			$label = $labels['queued'];
			$status = 'waiting';

			$legend = wpbc_human_time_diff($c['pending_time'], __('%s left', 'wpbc'), null, 10);
			$bc_count = 3;

			$update_delay = 6;

		} else if ($stamp['status'] == 'confirmed'){
			$label = $labels['stamped'];
			$status = 'stamped';
			$cstr = $confirmed_bcs = array();

			foreach ($c['blockchains'] as $bc_id => $config)
				if ($config['status'] == 'confirmed' && is_wpbc_blockchain_enabled($bc_id))
					$confirmed_bcs[] = $bc_id;

			$blockchains = wpbc_get_blockchains();
			foreach ($confirmed_bcs as $bc_id)
				$cstr[] = $theme == 'light' ? strtoupper($bc_id) : $blockchains[$bc_id]['name'];

			$cstr = apply_filters('wpbc_blockchains_label', $cstr, $confirmed_bcs, $theme);

			$legend = sprintf($labels['stamped_legend'], $theme == 'light' ? implode('/', $cstr) : wpbc_plural($cstr, ' & '));
			$bc_count = count($cstr) + 1;

			$update_delay = (count($cstr) == count(wpbc_get_blockchains()) ? 60 : 10);

		} else if ($stamp['status'] == 'pending'){
			$label = $labels['stamping'];
			$status = 'stamping';

			$legend = $c['pending_time'] > 0
				? wpbc_human_time_diff($c['pending_time'], __('%s left', 'wpbc'), null, 10)
				: null;

			$update_delay = 10;
		} else {

			$label = $labels['unknown'];
			$status = 'unknown';

			$update_delay = 30;
		}

		$context = wpbc_get_context();

		$target = !empty($wpbc_api_ret) ? '' : ' target="_blank"';

		if (!empty($wpbc_api_ret) && !empty($wpbc_tobar))
			$url = null;
		else if ($stamp && $stamp['status'] == 'queued')
			$url = null;
		else
			$url = $stamp ? $this->get_stamp_url_from_code($stamp_code) : null;

		if ($context && !empty($context['stamp_code']) && $context['stamp_code'] == $stamp_code)
			$url = null;

		$label_class = 'wpbc-status-label';
		if (empty($legend))
			$label_class .= ' wpbc-status-label-nolegend';

		$label = '<span class="'.$label_class.'">'.$label.'</span>';

		$extra_class = $theme == 'light' ? ' ab-item' : '';

		$ret = array(
			'use_button' => $use_button,
			'label' => $label,
			'legend' => $legend,
			'stamp_code' => $stamp_code,
			'url' => $url
		);

		if (!wpbc_user_can('see_state', $status) && !$force_show)
			return $ret + array('html' => wpbc_live_delay_anchor(30));

		ob_start();
		if ($use_button){

			?><a href="#" rel="nofollow" onclick="wpbc_stamp(this); return false;" data-wpbc-saving-label="<?= esc_attr('<span class="wpbc-status-label">'.$labels['stamping_send'].'</span>') ?>" class="wpbc-stamping-stamp wpbc-status<?= $extra_class ?>" data-wpbc-stamp_code="<?= $stamp_code ?>"><?= $label ?></a><?php

		} else {
			?><<?php if ($url) echo 'a rel="nofollow" href="'.$url.'"'.$target; else echo 'span'; ?> class="wpbc-status<?= $extra_class ?>" data-wpbc-stamp_code="<?= $stamp_code ?>"><?php
				echo $label;
				if ($legend){
					if ($theme == 'light')
						echo $status == 'stamped' ? ' '.$legend : ' ('.$legend.')';
					else
						echo '<span class="wpbc-status-legend">'.$legend.'</span>';
				}
			?></<?php if ($url) echo 'a'; else echo 'span'; ?>><?php
		}

		$ret['html'] = '<span class="wpbc-stamping-button wpbc-stamping-button-status-'.$status.' wpbc-stamping-button-code-'.$stamp_code.' wpbc-stamping-button-bccount-'.$bc_count.' wpbc-button-theme-'.$theme.'">'.ob_get_clean().'</span>'.wpbc_live_delay_anchor($update_delay);

		return $ret;
	}

	public function get_receipts_by_hash($hash, $update = false, $bc_id = null, $status = null, $select = null){
		global $wpdb;
		if (!$hash)
			return array();

		if ($update && wpbc_lock($hash, 'get_receipts_by_hash')){
			if (defined('WPBC_CRON_POORMAN') && WPBC_CRON_POORMAN)
				wpbc_log('info', 'checking receipts for hash '.$hash);

			$p = $this->get('stamps/'.$hash);

			if (!$p || $p->error || empty($p->result)){
				wpbc_unlock($hash);
				return array();
			}

			$pending = array();
			$this->save_receipts($hash, $p->result, $pending, true);

			wpbc_unlock($hash);
		}

		$q = $wpdb->prepare('SELECT '.($select ? $select : 'tx.*, MAX(tx_confirmations.confirmations) AS confirmations').' '
			.'FROM '.WPBC_DB_PREFIX.'stamp_txs AS tx '
			.'LEFT JOIN '.WPBC_DB_PREFIX.'stamp_trees AS tree ON tx.hash = tree.merkle_root '
			.'LEFT JOIN '.WPBC_DB_PREFIX.'stamp_tx_confirmations AS tx_confirmations ON tx.tx_id = tx_confirmations.tx_id '
			.'WHERE '
				.($bc_id ? 'tx.bc_id = "'.esc_sql($bc_id).'" AND ' : '')
				.($status ? 'tx.status = "'.esc_sql($status).'" AND ' : '')
				.'((tx.hash = %s) OR (tree.hash = %s AND tree.hash_method = %s)) '
			.'GROUP BY tx.id, tx_confirmations.id '
			.'ORDER BY tx_confirmations.block_number ASC',

			$hash, $hash, $this->hash_method);

		$ret = array();
		foreach ($wpdb->get_results($q, ARRAY_A) as $res){
			if (!empty($res['confirmations']))
				$res['confirmations'] = intval($res['confirmations']);
			$ret[] = $res;
		}
		return $ret;

	}

	public function get($uri, $data = array(), $headers = array(), $timeout = 10, &$httpcode = null){
		return $this->call('get', $uri, $data, $headers, $timeout, $httpcode);
	}

	public function post($uri, $data = array(), $headers = array(), $timeout = 10, &$httpcode = null){
		return $this->call('post', $uri, $data, $headers, $timeout, $httpcode);
	}

	public function call($type, $uri, $data = array(), $headers = array(), $timeout = null, &$httpcode = null){

		if (!($token = $this->get_token()))
			return false;

		$url = $this->get_api_url().'/'.ltrim($uri, '/');
		if ($type == 'get' && $data)
			$url .= '?'.http_build_query($data);

		$process = curl_init();
		curl_setopt($process, CURLOPT_URL, $url);

		$headers = apply_filters('wpbc_fetch_headers', $headers, $url, $type, $data);
		if ($headers)
			curl_setopt($process, CURLOPT_HTTPHEADER, $headers);

		curl_setopt($process, CURLOPT_USERPWD, $token['username'].':'.$token['password']);
		curl_setopt($process, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

		curl_setopt($process, CURLOPT_TIMEOUT, $timeout ? $timeout : (defined('WPBC_CRON') ? 10 : 2));
		if ($type == 'post' && $data)
			curl_setopt($process, CURLOPT_POSTFIELDS, $data);
		curl_setopt($process, CURLOPT_RETURNTRANSFER, TRUE);

		curl_setopt($process, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($process, CURLOPT_SSL_VERIFYHOST, false);

		if ($wait = apply_filters('wpbc_fetch_wait', 0, $process, $url, $type, $data, $headers)){
			curl_close($process);
			return false;
		}

		$ret = curl_exec($process);
		$httpcode = curl_getinfo($process, CURLINFO_HTTP_CODE);

		$ret = apply_filters('wpbc_fetch_return', $ret, $process, $url, $httpcode, $type, $data, $headers);
		curl_close($process);

		if (($httpcode < 200 || $httpcode >= 300) && $httpcode != 409){ // NOT 2**
			wpbc_trigger_fetch_error($url, $httpcode);
			return false;
		}

		try {
			$json = @json_decode($ret);
		} catch (Exception $e){
			return false;
		}
		return $json;
	}
}

