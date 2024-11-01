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

class WPBC_API {

	public $provider_id = null;

	public function get_token($prefix_id = ''){
		$username = get_option('wpbc_provider_'.$prefix_id.'username');
		$password = get_option('wpbc_provider_'.$prefix_id.'password');
		return $username ? array(
			'username' => $username,
			'password' => $password
		) : false;
	}

	public function get_next_update($stamp){
		global $wpdb;
		if (empty($stamp) || (is_string($stamp) && !($stamp = wpbc_get_stamp_by_hash($stamp))))
			return null;

		$delay = 0;
		switch ($stamp['status']){

			case 'confirmed':

				$bc_done = $bc_safe = array();
				$bcs = wpbc_get_blockchains(true);
				foreach ($bcs as $bc_id => $bc_config){
					$receipts = $this->get_receipts_for_api_ret($stamp, $bc_id);

					if (!$receipts)
						return date('Y-m-d H:i:s', strtotime('+2 minutes'));

					foreach ($receipts as $tx)
						if (!empty($tx['confirmations'])){
							if ($tx['confirmations'] >= $bc_config['min_virtual_confirmations'])
								$bc_done[$bc_id] = true;
							if ($tx['confirmations'] >= $bc_config['safe_confirmations_amount'])
								$bc_safe[$bc_id] = true;
						}
				}
				if (count($bc_done) == count($bcs))
					return null;

				if (count($bc_safe) == count($bcs))
					$delay = 5 * 60;

				else
					$delay = 60;

				break;

			case 'pending':
			case 'waiting':

				if ($stamp['stamped_at']){
					$stamped_at = strtotime($stamp['restamped_at'] ? $stamp['restamped_at'] : $stamp['stamped_at']);
					if ($stamped_at < strtotime('-2 hours'))
						$delay = 180;
				}

				if (!$delay){
					$max = 120;
					if (!empty($stamp['pending_time']) && !empty($stamp['pending_time']['pending'])){
						foreach ($stamp['pending_time']['pending'] as $bc_id => $pending)
							$max = min($max, $pending - (time() - strtotime($stamp['pending_time']['time'])) + 3);
					}
					$delay = max($max, 10);
				}
				break;
		}
		if (!$delay)
			$delay = 10;

		return date('Y-m-d H:i:s', time() + $delay);
	}

	function get_api_url(){
		return WPBC_USE_TESTNET && property_exists($this, 'api_url_testnet') && $this->api_url_testnet ? $this->api_url_testnet : $this->api_url;
	}

	function get_block_api($bc_id){
		static $block_apis = null;
		if ($block_apis === null){
			$block_apis = array();
			foreach (wpbc_get_blockchains(true) as $cbc_id => $config)
				$block_apis[$cbc_id] = array();

			foreach (wpbc_get_api_folders() as $folder)
				if ($dir = opendir($folder.'/block_explorers'))
					while (false !== ($filename = readdir($dir))){
						$provider_id = preg_replace('#(\.php)$#i', '', $filename);
						if ($provider_id == $filename)
							continue;

						$api = wpbc_get_api($provider_id, 'block_explorers');
						if (!$api)
							continue;

						foreach (wpbc_get_blockchains() as $cbc_id => $config)
							if (method_exists($api, 'check_transaction_'.$cbc_id))
								$block_apis[$cbc_id][] = $api;
					}
		}
		return !empty($block_apis[$bc_id]) ? $block_apis[$bc_id][0] : null;
	}

}

class WPBC_API_Stamp extends WPBC_API {

	public function get_public_args(){
		return array(
			'fields' => array(
			)
		);
	}

	public function stamp($str, $stamp_base){
		return $this->is_hash_stamped($this->get_hash($str), $stamp) ? $stamp : $this->create_stamp($str, $stamp_base);
	}

	public function is_hash_stamped($hash, &$stamp){
		global $wpdb;
		$stamp_id = $wpdb->get_var($wpdb->prepare('SELECT stamp_id FROM '.WPBC_DB_PREFIX.'stamps WHERE hash = %s', $hash));
		if ($stamp_id){
			$stamp = wpbc_get_stamp($stamp_id);
			return true;
		}
		return false;
	}

	public function stamp_updated(&$stamp){
		$updated_at = date('Y-m-d H:i:s');
		$stamp['next_update'] = $this->hash_updated($stamp['hash'], $updated_at);
		$stamp['updated_at'] = $updated_at;
	}

	public function hash_updated($hash, $updated_at = null){
		global $wpdb;
		$next = $this->get_next_update($hash);
		$ret = $wpdb->update(WPBC_DB_PREFIX.'stamps', array(
			'updated_at' => $updated_at ? $updated_at : date('Y-m-d H:i:s'),
			'next_update' => $next
		), array(
			'hash' => $hash
		));
		wpbc_log('debug', 'updated hash\'s next_update for '.$hash.($next ? ' for '.wpbc_human_time_diff(time() - strtotime($next)) : ' to '.WPBC_MIN_UPDATE_PERIOD));
		return $next;
	}

	public function save_stamp(&$stamp, $restamping = false){
		global $wpdb;

		if ($restamping){
			$stamp['stamp_id'] = $restamping;

			if (!$wpdb->update(WPBC_DB_PREFIX.'stamps', array(
				'pending_time' => isset($stamp['pending_time']) ? maybe_serialize($stamp['pending_time']) : null
			) + $stamp, array(
				'stamp_id' => $restamping
			)))
				return false;

			$wpdb->query($wpdb->prepare('UPDATE '.WPBC_DB_PREFIX.'stamps SET restamps = restamps + 1 WHERE stamp_id = %d', $restamping));

		} else {

			$stamp += array(
				'created_at' => date('Y-m-d H:i:s'),
				'provider_id' => null,
				'stamped_at' => null,
				'confirmed_at' => null,
				'restamped_at' => null,
				'pending_time' => null,
				'next_update' => null,
				'updated_at' => null,
				'restamps' => 0
			);
			if (!$wpdb->insert(WPBC_DB_PREFIX.'stamps', array(
				'pending_time' => isset($stamp['pending_time']) ? maybe_serialize($stamp['pending_time']) : null
			) + $stamp))
				return false;

			$stamp['stamp_id'] = $wpdb->insert_id;
		}
		return true;
	}

	function get_receipts_for_api_ret(&$stamp, $bc_id = null){
		return $this->get_receipts($stamp, false, $bc_id, null, 'tx.tx_id AS id, tx.prefix, tx.hash, UPPER(tree.hash_method) AS hash_method, tree.siblings, MIN(tx_confirmations.block_number) AS block_number, tx.created_at, tx_confirmations.confirmed_at, tx.status, SUM(tx_confirmations.confirmations) AS confirmations');
	}

	function get_receipts(&$stamp, $update = false, $bc_id = null, $status = null, $select = '*'){
		global $wpdb;

		if ($bc_id || $status || $select || $update || !isset($stamp['receipts'])){
			$receipts = $this->get_receipts_by_hash($stamp['hash'], $update, $bc_id, $status, $select);
			if ($bc_id || $status || $select)
				return $receipts;

			$stamp['receipts'] = $receipts;
		}
		return $stamp['receipts'];
	}

	function get_hash($str){
		return wpbc_hash($str, $this->hash_method);
	}

	function get_trees($hash, $merkle_root){
		global $wpdb;
		return maybe_unserialize($wpdb->get_var($wpdb->prepare('SELECT siblings FROM '.WPBC_DB_PREFIX.'stamp_trees WHERE hash = %s AND hash_method = %s AND merkle_root = %s', $hash, $this->hash_method, $merkle_root)));
	}

	function get_pending_time_from_api_bc($config, $pattern = false, $pattern_negative = null, $multiple = null){
		$pending_time = !empty($config['stamped_in']) ? $config['stamped_in'] : $config['pending_time'];

		if (is_string($pending_time))
			$pending_time = intval($pending_time);

		return $pending_time < 0 ? null : wpbc_human_time_diff(-$pending_time, $pattern, $pattern_negative, $multiple);
	}

	function get_blockchain_statuses_from_api_ret($ret){

		$output = array();
		$blockchains = wpbc_get_blockchains(true);
		$labels = wpbc_get_status_labels('blockchain_status');

		foreach ($ret['blockchains'] as $bc_id => $config){
			if ($config['status'] == 'unknown' && !wpbc_is_admin())
				continue;
			if (!isset($blockchains[$bc_id]))
				continue;

			$blockchain = $blockchains[$bc_id];

			$status = '';
			$error = false;

			if ($config['status'] == 'pending'){

				$pending_time = $this->get_pending_time_from_api_bc($config, false, false, 10);
				$pending_time_particuled = $this->get_pending_time_from_api_bc($config, null, null, 10);
				$ext_url = $this->get_external_url_from_api_bc($config);

				if (!empty($config['transactions']) && $pending_time){
					if ($ext_url)
						$status .= '<a rel="nofollow" href="'.$ext_url.'" target="_blank">';

					if ($error = $this->is_tx_error_from_api_bc($config)){
						$status .= '<span class="bctamp-validating-error"><i class="fa fa-warning"></i> '.$labels['transaction_error'].'</span>';
						$error = true;

					} else if ($config['pending_blocks'] < 2)
						$status .= sprintf($labels['pending_last_block'], $pending_time);

					else
						$status .= sprintf($labels['pending'], $config['pending_blocks'], $pending_time);

					if ($ext_url)
						$status .= '</a>';

					if (!$error){
						foreach ($config['transactions'] as $tx){
							$status .= $this->get_merkle_tree_from_api_ret($ret, $tx, $bc_id);
							break;
						}

						if (!empty($config['pending_blocks'])){
							$last_time = wpbc_get_block_time($bc_id, true, '1 minute');

							if (strtotime($last_time) < time() - max($blockchain['block_duration_error'], 70))
								$status .= ' <span style="color: red; margin-left: 20px;"><i class="fa fa-warning"></i> '.__('stagnent blockchain', 'wpbc').'</span>';
							else if (strtotime($last_time) < time() - max($blockchain['block_duration_warning'], 70))
								$status .= ' <span style="color: orange; margin-left: 20px;"><i class="fa fa-warning"></i> '.__('slow blocks', 'wpbc').'</span>';

						}

					}

					$status = '<div class="wpbc-tx">'.$status.'</div>';

				} else if ($pending_time)
					$status .= sprintf($labels['waiting'], $pending_time_particuled);
				else
					$status .= $labels['service_pending'];

			} else if ($config['status'] == 'unknown'){
				$status .= '<div class="wpbc-tx" style="color: red"><i class="fa fa-warning"></i> '.$labels['unknown'].'</div>';

			} else {
				$show_all_tx = false;

				if ($show_all_tx){
					$confirmed_count = 0;
					foreach ($config['transactions'] as $tx){
						if ($tx['status'] != 'confirmed')
							continue;
						$confirmed_count++;
					}
				} else
					$confirmed_count = 1;

				$i = 0;
				$links = array();

				foreach ($config['transactions'] as $tx){

					if ($tx['status'] != 'confirmed')
						continue;

					$ext_url = sprintf($config['blockchain']['transaction_base_url'], $tx['id']);
					$clink = '';

					$clink .= '<a rel="nofollow" href="'.$ext_url.'" target="_blank" class="wpbc-tx-link'.($confirmed_count > 1 ? ' wpbc-tx-link-several' : '').($i ? '' : ' wpbc-tx-link-first').'"><span class="wpbc-tx-label">';
					if (!$i)
						$clink .= $labels['stamped'];

					if ($confirmed_count > 1){
						if (!$i)
							$clink .= ': </span>';
						$clink .= '['.($i+1).']';
					}

					$clink .= ' ('.sprintf($labels['confirmations'], number_format($tx['confirmations'])).')';
					if ($confirmed_count < 2){
						$clink .= '</span>';
					}
					$clink .= '</a>';

					$clink .= $this->get_merkle_tree_from_api_ret($ret, $tx, $bc_id);

					$status .= '<div class="wpbc-tx">'.$clink.'</div>';

					$i++;

					if (!$show_all_tx && $tx['status'] == 'confirmed' && !empty($tx['confirmations']) && $tx['confirmations'] >= $blockchain['safe_confirmations_amount'])
						break;
				}
			}

			if (!$error || wpbc_is_admin() || current_user_can('edit_post', $ret['context_id'])){
				$output[] = '<tr><td class="wpbc-api-ret-name">'.$config['blockchain']['name'].': </td><td>'.$status.'</td></tr>';
			}
		}

		if (!empty($output)){
			ob_start();
			?>
			<div class="wpbc-api-ret-status-ind"><i class="fa fa-dollar"></i> <?= __('Blockchain transactions', 'wpbc') ?>:</div>
			<div class="wpbc-api-ret-vars wpbc-api-ret-txs"><table cellspacing="0" cellpadding="0">
				<colgroup>
					<col class="wpbc-api-ret-col-name" />
					<col class="wpbc-api-ret-col-extend" />
				</colgroup>
				<?php
					echo implode('', $output);

				?>
			</table></div>
			<?php
			return ob_get_clean();
		}
		return '';
	}

	function get_merkle_tree_from_api_ret($ret, &$tx, $bc_id){
		$clink = '';
		if ($ret['hash'] != $tx['hash']){

			$js = 'return wpbc_toggle_tree(this);';

			$clink .= ' '.sprintf(__('as %s', 'wpbc'), wpbc_print_copybox($tx['hash'], true, 20, 0)).' '
				.'<span><a href="#" class="wpbc-hash-tree-link" onclick="'.esc_attr($js).'"><i class="fa fa-tree"></i> '.__('show tree', 'wpbc').'</a>'
				.'<a class="wpbc-hash-tree-link wpbc-hash-tree-link-hide" href="#" onclick="'.esc_attr($js).'"><i class="fa fa-tree"></i> '.__('hide tree', 'wpbc').'</a></span>';

			if ($trees = $this->get_trees($ret['hash'], $tx['hash'])){

				$clink .= '<div class="wpbc-merkle-tree" data-wpbc-bc_id="'.$bc_id.'">';
				$clink .= '<div class="wpbc-merkle-tree-inner">';

				$clink .= wpbc_print_merkle_tree($ret['hash'], $trees);
				$clink .= '</div>';
				$clink .= '</div>';
			} else
				wpbc_log('error', 'merkle tree not found while printing for HASH '.$ret['hash'].' and ROOT '.$tx['hash']);
		}
		return $clink;
	}

	function get_external_url_from_api_bc($config){
		$ext_url = $ext_date = $ext_conf = null;
		if (!empty($config['transactions'])){
			foreach (array(1, 0) as $only_confirmed){
				foreach ($config['transactions'] as $tx){
					if ($only_confirmed && $tx['status'] != 'confirmed')
						continue;
					if (!$ext_date || $ext_date < strtotime($tx['created_at']) && $ext_conf >= $tx['confirmations']){
						$ext_conf = $tx['confirmations'];
						$ext_date = strtotime($tx['created_at']);
						$ext_url = sprintf($config['blockchain']['transaction_base_url'], $tx['id']);
					}
				}
				if ($ext_url)
					return $ext_url;
			}
		}
		return $ext_url;
	}

	function is_tx_error_from_api_bc($config, $blockchain_config_attr = 'transaction_error_delay', $last_stamped_at = null){
		if ($config['status'] == 'unknown' && empty($config['transactions']) && empty($config['stamped_in']))
			return true;

		if (!in_array($config['status'], array('pending', 'queued')))
			return false;

		$blockchains = wpbc_get_blockchains(true);
		if (!isset($blockchains[$config['blockchain']['id']]))
			return false;

		$bc_config = $blockchains[$config['blockchain']['id']];

		if (empty($config['transactions']) && $last_stamped_at && strtotime($last_stamped_at) < time() - $bc_config[$blockchain_config_attr])
			return true;

		else if (empty($config['transactions']) || empty($config['pending_blocks']) || $config['pending_blocks'] != $bc_config['safe_confirmations_amount'] || !empty($tx['stamped_in']))
			return false;

		$has_confirmed = false;
		$updated_at = null;
		foreach ($config['transactions'] as $tx){

			if ($tx['status'] == 'confirmed' || !empty($tx['confirmations']))
				$has_confirmed = true;

			if ($tx['status'] == 'pending' && !empty($tx['created_at']) && (!$updated_at || strtotime($tx['created_at']) > $updated_at))
				$updated_at = strtotime($tx['created_at']);
		}

		$errored = !$has_confirmed && $updated_at && $updated_at < time() - $bc_config[$blockchain_config_attr];

		if ($errored){
			wpbc_log('info', 'tx_error detected with last updated at '.date('Y-m-d H:i:s', $updated_at).' on BC '.$config['blockchain']['id']);

		}
		return $errored;
	}

	function get_stamp_status_from_api_ret($ret){

		$labels = wpbc_get_status_labels('api_return');
		if (!$ret)
			return $labels['not_found'];

		$status = $ret['status'];
		if ($status == 'pending'){
			$tx = 0;
			$really_pending = false;
			foreach ($ret['blockchains'] as $bc_config)
				if (!empty($bc_config['transactions']))
					$tx += count($bc_config['transactions']);
				else if (!empty($bc_config['stamped_in']))
					$really_pending = true;
			if (!$tx || $really_pending)
				$status = 'service_pending';
		}

		$label = isset($labels[$status]) ? $labels[$status] : $status;

		$replace = '';
		$tx_url = null;
		$delay = null;

		if ($status == 'confirmed'){
			$c = 0;
			foreach ($ret['blockchains'] as $bc_id => $config)
				if ($config['status'] == 'confirmed')
					$c++;

			$str = array();
			foreach ($ret['blockchains'] as $bc_id => $config)
				if ($config['status'] == 'confirmed'){
					$url = sprintf($config['blockchain']['transaction_base_url'], $config['transactions'][0]['id']);
					if ($c > 1)
						$str[] = '<a rel="nofollow" href="'.$url.'" target="_blank">'.$config['blockchain']['name'].'</a>';
					else {
						$str[] = $config['blockchain']['name'];
						$tx_url = $url;
					}
				} else if (!empty($config['pending_time']))
					$delay = 15;

			$replace = ' '.sprintf($labels['in_particule'], wpbc_plural($str, ' & '));

		} else if (in_array($status, array('pending', 'service_pending'))){
			$replace .= ' ('.wpbc_human_time_diff($ret['pending_time'], __('%s left', 'wpbc'), null, 10).')';
			$delay = 15;

		} else if ($status == 'queued')
			return __('Queued for stamping..', 'wpbc');

		return '<'.($tx_url ? 'a href="'.$tx_url.'" rel="nofollow" target="_blank"' : 'span').' class="wpbc-status-ind wpbc-status-ind-status-'.$status.'">'.sprintf($label, $replace).($delay !== null ? wpbc_live_delay_anchor($delay) : '').'</'.($tx_url ? 'a' : 'span').'>';
	}

	public function declare_stamp_as_confirmed(&$stamp, $confirmed_at){
		global $wpdb;

		wpbc_log('info', 'declaring stamp '.$stamp['hash'].' (#'.$stamp['stamp_id'].') as confirmed on '.$confirmed_at);

		$stamp['status'] = 'confirmed';
		$stamp['confirmed_at'] = $confirmed_at;

		$wpdb->query($wpdb->prepare('UPDATE '.WPBC_DB_PREFIX.'stamps SET status = %s WHERE stamp_id = %d', 'confirmed', $stamp['stamp_id']));

		$wpdb->query($wpdb->prepare('UPDATE '.WPBC_DB_PREFIX.'stamps SET confirmed_at = %s WHERE stamp_id = %d AND confirmed_at IS NULL', $confirmed_at, $stamp['stamp_id']));

	}

	public function stamp_needs_receipts_update($stamp){
		if (!empty($stamp['pending_time']))
			return true;
		if ($stamp['status'] != 'confirmed')
			return true;
		$confirmed = array();
		$bcs = wpbc_get_blockchains(true);
		foreach ($this->get_receipts_by_hash($stamp['hash'], false) as $tx)
			if (!isset($bcs[$tx['bc_id']]))
				continue;
			else if ($tx['status'] == 'pending' || $tx['status'] == 'confirmed')
				$confirmed[$tx['bc_id']] = true;

		$ret = count($confirmed) != count($bcs);
		wpbc_log('info', 'stamp_needs_receipts_update: '.$ret);
		return $ret;
	}

	public function check_transactions(&$stamp, $force_update = false, $debug = false){
		global $wpdb;

		wpbc_log('debug', 'checking txs for '.$stamp['hash'].' (#'.$stamp['stamp_id'].')');

		//if (wpbc_unsynced_by_base($stamp['stamp_base']))
		//	wpbc_queue_code($stamp['stamp_base'].'-last');

		if ($stamp['status'] == 'queued' && $this->stamp_from_queue($stamp['stamp_base'], $stamp['hash'])){
			$stamp = wpbc_get_stamp_by_hash($stamp['hash']);
			return true;
		}

		static $checked = array();

		$need_update = $this->stamp_needs_receipts_update($stamp);
		//echo $need_update ? 'NEED' : 'NOT';

		$stamp['txs'] = $this->get_receipts_by_hash($stamp['hash'], $need_update);

		if (isset($checked[$stamp['stamp_id']]) && $force_update){
			wpbc_log('info', 'already checked stamp '.$stamp['hash'].' (#'.$stamp['stamp_id'].'), cancel update');
			$force_update = false;

		} else if (wpbc_lock($stamp['hash'], 'check_transactions')){
			$bcs = wpbc_get_blockchains(true);

			$stats = $this->get_bc_stats_from_stamp($stamp);

			$check_bcs = array();
			foreach ($stamp['txs'] as &$tx){
				if (!isset($bcs[$tx['bc_id']]))
					continue;

				if (($tx['status'] == 'pending' || $force_update) && empty($stats['done'][$tx['bc_id']]) && empty($check_bcs[$tx['bc_id']])){

					wpbc_log('info', 'really check '.$tx['bc_id'].' tx for stamp '.$stamp['hash'].' (#'.$stamp['stamp_id'].')');

					if ($api = $this->get_block_api($tx['bc_id'])){

						$api->{'check_transaction_'.$tx['bc_id']}($stamp, $bcs[$tx['bc_id']], $tx, $this);
					}
					if ($this->is_tx('safe', $tx))
						$check_bcs[$tx['bc_id']] = true;

				} else
					wpbc_log('info', 'won\'t check '.$tx['bc_id'].' tx for stamp '.$stamp['hash'].' (#'.$stamp['stamp_id'].')');
			}

			$stats = $this->get_bc_stats_from_stamp($stamp);

			if (!empty($stats['safe'])){
				if ($stamp['status'] != 'confirmed')
					$this->declare_stamp_as_confirmed($stamp, date('Y-m-d H:i:s'));
			}

			$have_restamped = null;

			if (WPBC_ALLOW_RESTAMP && defined('WPBC_CRON') && $stamp['next_update'] && $stamp['created_at'] && strtotime(!empty($stamp['restamped_at']) ? $stamp['restamped_at'] : $stamp['created_at']) < strtotime('-'.WPBC_MIN_ERROR_CHECK)){

				$ret = $this->api($stamp, $stamp['stamp_base'].'-'.$stamp['hash']);
				$do_restamp = false;

				foreach ($ret['blockchains'] as $bc_id => $config)
					if ($this->is_tx_error_from_api_bc($config, 'transaction_restamp_delay', $ret['restamped_at'] ? $ret['restamped_at'] : $ret['stamped_at'])){

						wpbc_log('error', 'late stamping detected for stamp #'.$stamp['stamp_id'].' and blockchain '.$bc_id.', will restamp (stamp_base: '.$stamp['stamp_base'].'-last)');

						$block_time = wpbc_get_block_time($bc_id, true, $bcs[$bc_id]['block_duration_error']);
						if ($block_time && strtotime($block_time) >= time() - $bcs[$bc_id]['block_duration_error']){

							$do_restamp = true;
						}
						break;

					}

				if (count($ret['blockchains']) !== count($bcs) || $do_restamp){

					if ($have_restamped = wpbc_lock($stamp['hash'].'-restamp-check', 'restamp_check')){
						wpbc_unlock($stamp['hash']);

						if (wpbc_stamp_by_code($stamp['stamp_base'].'-'.(!empty($stamp['hash']) ? $stamp['hash'] : 'last'), true, $debug))
							update_option('wpbc_restamps', get_option('wpbc_restamps', 0)+1);

						wpbc_unlock($stamp['hash'].'-restamp-check');
					}
				}
			}

			$this->stamp_updated($stamp);
			wpbc_log('info', 'stamp updated: '.$stamp['hash']);
			if (!$have_restamped){
				wpbc_unlock($stamp['hash']);
			}

			$checked[$stamp['stamp_id']] = true;

		}
		return !empty($stamp['txs']);
	}

	public function is_tx($what, &$tx){
		$bcs = wpbc_get_blockchains();
		switch ($what){

			case 'safe':
				return isset($bcs[$tx['bc_id']]) && $tx['status'] == 'confirmed' && !empty($tx['confirmations']) && $tx['confirmations'] >= $bcs[$tx['bc_id']]['safe_confirmations_amount'];

			case 'done':
				return isset($bcs[$tx['bc_id']]) && $tx['status'] == 'confirmed' && !empty($tx['confirmations']) && $tx['confirmations'] >= $bcs[$tx['bc_id']]['min_virtual_confirmations'];
		}
		return false;
	}

	public function get_bc_stats_from_stamp(&$stamp){
		$bcs = wpbc_get_blockchains(true);
		$stats = array(
			'safe' => array(),
			'done' => array(),
		);
		foreach ($stamp['txs'] as $tx){
			if (!isset($bcs[$tx['bc_id']]))
				continue;

			if (!empty($tx['confirmations']) && $tx['confirmations'] >= $bcs[$tx['bc_id']]['min_virtual_confirmations']){

				$stats['done'][$tx['bc_id']] = true;
			}

			if (!empty($tx['confirmations']) && $tx['confirmations'] >= $bcs[$tx['bc_id']]['safe_confirmations_amount'])
				$stats['safe'][$tx['bc_id']] = true;
		}

		return $stats;
	}

	public function get_history($stamp_base){

		global $wpdb;
		$history = array();

		$parts = explode('-', $stamp_base);
		$post_id = intval(array_shift($parts));
		$stamp_type = array_shift($parts);

		$content = wpbc_get_stamp_content_by_code($stamp_base.'-last');
		$current_hash = $this->get_hash($content);

		$stamp_ids = array();
		if (apply_filters_ref_array('wpbc_get_history', array(true, &$stamp_ids, &$parts, $stamp_type, $stamp_base, $content, $current_hash))){
			switch ($stamp_type){
				default:
					foreach ($wpdb->get_col($wpdb->prepare('SELECT meta_value FROM '.($stamp_type == 'profile' ? $wpdb->usermeta : $wpdb->postmeta).' WHERE '.($stamp_type == 'profile' ? 'user_id' : 'post_id').' = %d AND meta_key = %s ORDER BY '.($stamp_type == 'profile' ? 'umeta_id' : 'meta_id').' DESC', $post_id, 'wpbc_stamp_history_'.$stamp_type)) as $meta_value)
						$stamp_ids[] = maybe_unserialize($meta_value);

					if ($stamp_id = get_metadata($stamp_type == 'profile' ? 'user' : 'post', $post_id, 'wpbc_stamp_'.$stamp_type, true))
						array_unshift($stamp_ids, array(
							'stamp_id' => $stamp_id,
							'hash' => $current_hash,
							'until' => 'now',
						));

			}
		}

		$has = array();
		foreach ($stamp_ids as $s){
			if (!is_array($s) || in_array($s['stamp_id'], $has))
				continue;

			if ($stamp = wpbc_get_stamp($s['stamp_id'], false))
				$history[] = array(
					'type' => $stamp['hash'] == $current_hash ? 'current' : 'history',
					'until' => $stamp['hash'] == $current_hash ? 'now' : $s['until'],
					//'stamp' => $this->api($stamp, $stamp_base.'-'.$stamp['hash']),
					'stamp_id' => $s['stamp_id'],
					'hash' => $stamp['hash'],
					'history' => count($history)
				);

			$has[] = $s['stamp_id'];
		}
		// reorder and add history ranks
		if ($history && $history[0]['type'] != 'current'){
			$fhistory = array();
			foreach ($history as $h)
				if ($h['type'] == 'current')
					$fhistory[] = $h;
			foreach ($history as $h)
				if ($h['type'] != 'current')
					$fhistory[] = array('history' => count($fhistory))+$h;
			return $fhistory;
		}
		return $history;
	}

	function get_stamp_history_from_api_ret($ret, $stamp_code, $is_page_last){

		$parts = explode('-', $stamp_code);
		$post_id = intval(array_shift($parts));
		$hash = array_pop($parts);
		$stamp_base = $post_id.'-'.implode('-', $parts);

		$history = $this->get_history($stamp_base);

		if ($history){

			ob_start();
			?>
			<div class="wpbc-api-ret-status-ind"><i class="fa fa-history"></i> <?= __('Stamp history', 'wpbc') ?>:</div>

			<div class="wpbc-api-ret-vars wpbc-api-ret-revisions wpbc-history-table"><table cellspacing="0" cellpadding="0">
				<colgroup>
					<col class="wpbc-api-ret-col-name" />
					<col class="wpbc-api-ret-col-extend" />
				</colgroup>

				<?php
				$lines = array();
				foreach ($history as $i => $h){
					ob_start();
					?>
					<tr class="wpbc-history-tr"><td class="wpbc-api-ret-name"><?php

							if ($is_last = ($h['type'] == 'current'))
								echo __('Last stamp', 'wpbc');
							else
								echo __('Revision', 'wpbc').' -'.$h['history'];

						?>: </td><td class="wpbc-cut-ellipsis wpbc-no-lines-td">
							<table cellspacing="0" cellpadding="0" class="wpbc-no-lines">
								<colgroup>
									<col class="wpbc-api-ret-col-extend" />
									<col class="wpbc-api-ret-col-name" />
								</colgroup>
								<tr><td class="wpbc-cut-ellipsis">
									<?php

									$show_link = ($is_last ? !$is_page_last : ($h['hash'] != $ret['hash']));

									if ($show_link)
										echo '<a rel="nofollow" href="'.$this->get_stamp_url_from_code($stamp_base.'-'.($is_last ? 'last' : $h['hash'])).'">'.$h['hash'].'</a>';
									else
										echo wpbc_print_copybox($h['hash']);

							?>
						</td><td class="wpbc-api-ret-td-right"><?php
							if ($h['type'] != 'current'){
								if ($h['until'] != 'now')
									echo sprintf(__('active until %s', 'wpbc'), get_date_from_gmt(date("Y-m-d H:i:s", $h['until'])).' GMT'.($ret['gmt_offset'] <= 0 ? '-0' : '+').$ret['gmt_offset']).' ('.wpbc_human_time_diff((date("Y-m-d H:i:s", $h['until']))).')';
							} else
								echo __('active stamp', 'wpbc');
							?></td></tr></table></td></tr>
						<?php
					$lines[] = ob_get_clean();
				}

				if (count($lines) <= 4)
					echo implode('', $lines);
				else {
					echo implode('', array_splice($lines, 0, 3));
					echo '<tr class="wpbc-history-tr wpbc-history-tr-show"><td colspan="3"><a href="#" onclick="return wpbc_tr_unfold(this);">'.__('Show more history', 'wpbc').'</a></td></tr><tr style="display: none" class="wpbc-history-hidden"><td><table>'.implode('', $lines).'</table></td></tr>';
				}
				?>
			</table></div>
			<?php
			return ob_get_clean();
		}
		return '';
	}

	function get_status_icon_from_api_ret($ret, $last_hash){
		if (!$ret)
			return '<i class="fa fa-warning"></i>';
		return '<i title="'.($ret['status'] == 'confirmed' ? __('Confirmed stamp', 'wpbc') : __('Stamping.. please wait', 'wpbc')).'" class="fa fa-'.(!$last_hash ? 'history' : ($ret['status'] == 'confirmed' ? 'lock' : 'unlock-alt')).'"></i>';
	}
}

function wpbc_get_stamp($stamp_id, $receipts = true){
	global $wpdb;
	$stamp = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.WPBC_DB_PREFIX.'stamps WHERE stamp_id = %d', $stamp_id), ARRAY_A);
	if ($stamp){
		$stamp['pending_time'] = maybe_unserialize($stamp['pending_time']);
		if ($receipts && ($api = wpbc_get_api($stamp['provider'])))
			$api->get_receipts($stamp);
	}
	return $stamp;
}

function wpbc_get_stamp_by_hash($hash, $receipts = true){
	global $wpdb;
	$stamp_id = $wpdb->get_var($wpdb->prepare('SELECT stamp_id FROM '.WPBC_DB_PREFIX.'stamps WHERE hash = %s LIMIT 1', $hash));
	return $stamp_id ? wpbc_get_stamp($stamp_id, $receipts) : null;
}

function wpbc_get_stamp_by_code($stamp_code, $create = false, $return_from_memory = false, &$just_created = null, $force_restamp = false, $debug = false){
	global $wpdb;

	if (!($api = wpbc_get_api()))
		return 'no api configured';

	if ($debug)
		wpbc_log('info', '--------------------------------------------<br><b>stamp_code: '.$stamp_code.'; create: '.$create.'; return from memory: '.$return_from_memory.'; force_restamp: '.$force_restamp.'</b>');

	$parts = explode('-', $stamp_code);

	wpbc_log('debug', 'get_stamp_by_code for code '.$stamp_code);

	$hash = array_pop($parts);
	$stamp_base = implode('-', $parts);
	$post_id = intval(array_shift($parts));

	if (!$parts)
		$parts[] = 'default';

	$stamp_type = array_shift($parts);

	if (!($content = wpbc_get_stamp_content_by_code($stamp_code)))
		return 'content not found';

	$last_hash = $api->get_hash($content);

	$vars = apply_filters('wpbc_get_stamp_by_code', array(
		'stamp_type' => $stamp_type,
		'parts' => $parts,
		'hash' => $hash,
		'post_id' => $post_id,
		'done' => false,
		'stamp_id' => null
	));
	extract($vars);

	if ($hash == 'last'){
		$hash = $last_hash;
		$is_last = true;
	} else
		$is_last = $hash == $last_hash;

	$stamp = null;
	$cstamps = null;
	$from_history = false;

	if ($is_last && $debug)
		wpbc_log('info', 'IS LAST!');

	if (!$done)
		switch ($stamp_type){
			default:
				$meta_type = $stamp_type == 'profile' ? 'user' : 'post';

				if ($is_last){
					$stamp_id = get_metadata($meta_type, $post_id, 'wpbc_stamp_'.$stamp_type, true);

					if ($stamp_id){
						$stamp = wpbc_get_stamp($stamp_id);
						if (!$stamp || $stamp['hash'] != $hash){ // stamp not found in database or not with the right hash
							$stamp = $stamp_id = null;

							if ($debug)
								wpbc_log('info', 'LAST UNSYNCED');

						} else if ($debug)
							wpbc_log('info', 'LAST OK');

					} else if ($debug)
						wpbc_log('info', 'LAST NOT FOUND');
				}

				if (!$stamp){ // if not the last stamp, lookup in history
					$overwrite = false;
					$cstamps = get_metadata($meta_type, $post_id, 'wpbc_stamp_history_'.$stamp_type, false);

					if (!$cstamps){
						if ($debug)
							wpbc_log('info', 'NO HISTORY');

					} else {
						$stamps_new = array();
						// $stamps_done =
						foreach ($cstamps as $ov){
							$v = maybe_unserialize($ov);
							if (!empty($v['stamp_id'])){

								/*if (in_array($v['stamp_id'], $stamps_done)){ // history can hold doublons
									$overwrite = true;
									continue;
								}
								$stamps_done[] = $v['stamp_id'];
								* */

								$s = wpbc_get_stamp($v['stamp_id']);
					//			echo ' / hist-hash: '.$s['hash'];

								if (!$stamp_id && $s && $s['hash'] == $hash){

									// hash was found in history!
									$stamp_id = $v['stamp_id'];
									$stamp = $s;
									$from_history = true;

									if ($debug){
										wpbc_log('info', 'found hash (last: '.$is_last.') history: '.$stamp_id);
									}

								}
								if ($s)
									$stamps_new[] = $ov;
								else
									$overwrite = true;


								//wpbc_log('info', 'hash history mismatch for #'.$v['stamp_id'].': '.($s ? $s['hash'].' != '.$hash : 'not found'));
							} else
								$overwrite = true;
						}
						if ($overwrite){ // overwrite if many same stamp_id in metadata or any stamp_id not found from history

							if ($debug)
								wpbc_log('info', 'OVERWRITING HISTORY');
							delete_metadata($meta_type, $post_id, 'wpbc_stamp_history_'.$stamp_type);
							foreach ($stamps_new as $ov)
								wpbc_add_metadata($meta_type, $post_id, 'wpbc_stamp_history_'.$stamp_type, $ov);

						}
					}
				}
				break;
		}

	if (!$stamp_id)
		wpbc_log('info', 'stamp not found for '.$stamp_code);//.' -> '.print_r($cstamps, true));

	else if (!$from_history || !$is_last){
		if (!$stamp)
			$stamp = wpbc_get_stamp($stamp_id);

		if (!$force_restamp){

			wpbc_log('info', 'get_stamp_by_code has stamp_id '.$stamp_id.' for code '.$stamp_code);

			if ($stamp || $return_from_memory){
				if ($debug)
					wpbc_log('info', $stamp ? 'RETURNING STAMP' : 'RETURNING NO STAMP');
				return $stamp;
			}

			wpbc_log('info', 'finally no stamp for '.$stamp_code.' #'.$stamp_id);
		}
	}

	if ($force_restamp){
		// restamping

		wpbc_log('info', 'restamping code '.$stamp_code.' with stamp_id '.$stamp_id);

		//if (!$stamp_id || !($stamp = wpbc_get_stamp($stamp_id)))
		//	return 'restamping failed';

		if ($debug)
			wpbc_log('info', 'FORCING RESTAMP');
		$stamp = $api->create_stamp($content, $stamp_base, $stamp_id, $debug);

	} else if (!$create){
		// if not restamping nor creating
		if ($debug)
			wpbc_log('info', 'NOT STAMPING');
		return false;

	} else if ($return_from_memory)
		return $stamp_id ? wpbc_get_stamp($stamp_id) : 'couldn\'t create stamp '.$stamp_code.' #2';

	else if (!$is_last){

		// stamp not found, and not last -> deactivate the stamp if stamp_id

		wpbc_deactivate_stamp_failed($hash, $debug);
		return false;

	} else if (!$from_history){
		// normal stamping (first stamping)
		$stamp = $api->stamp($content, $stamp_base);

		wpbc_log('info', 'stamping '.$stamp_base);
	}
	if (!$stamp){
		if ($debug)
			wpbc_log('info', 'COULD NOT STAMP');
		return 'couldn\'t stamp';
	}
	$just_created = true;

	$vars = apply_filters('wpbc_save_history', array(
		'stamp_type' => $stamp_type,
		'hash' => $hash,
		'post_id' => $post_id,
		'stamp' => $stamp,
		'done' => false
	) + $vars);
	extract($vars);

	if (!$done){
		$meta_type = $stamp_type == 'profile' ? 'user' : 'post';

		// if it's a current stamp, insert into history
		if ($stamp['hash'] == $last_hash){

			if ($debug)
				wpbc_log('info', 'UPDATING HISTORY');
			$current_stamp_id = get_metadata($meta_type, $post_id, 'wpbc_stamp_'.$stamp_type, true);

			// if existing, add previous stamp as history
			if (!empty($current_stamp_id) && $current_stamp_id != $stamp['stamp_id']){

				wpbc_add_metadata($meta_type, $post_id, 'wpbc_stamp_history_'.$stamp_type, array(
					'until' => time(),
					'stamp_id' => $current_stamp_id
				));
				if ($debug)
					wpbc_log('info', 'inserted history with stamp_id: '.$current_stamp_id);
			}

			// update current stamp as post/user metadata if last hash
			update_metadata($meta_type, $post_id, 'wpbc_stamp_'.$stamp_type, $stamp['stamp_id']);

		} else if ($debug)
			wpbc_log('info', 'NO HISTORY UPDATE NEEDED');

		// update stamp_parent of previous stamp as the new stamp
//		$wpdb->query($wpdb->prepare('UPDATE '.WPBC_DB_PREFIX.'stamps SET stamp_parent = %s WHERE stamp_id = %d', $stamp['hash'], $current_stamp_id));
//		$wpdb->query($wpdb->prepare('UPDATE '.WPBC_DB_PREFIX.'stamps SET stamp_parent = %s WHERE stamp_id = %d', $current_stamp_id, $stamp['stamp_id']));

	}

	$receipts = $api->get_receipts($stamp, true);
	return $stamp;
}

function wpbc_get_stamp_content_by_code($stamp_base){ // base or code, does not matters
	static $cache = array();

	if (isset($cache[$stamp_base]))
		return $cache[$stamp_base];

	$str = null;
	$parts = explode('-', $stamp_base);
	$post_id = intval(array_shift($parts));

	if (!$parts){

		return false;
	}

	$stamp_type = array_shift($parts);

	$vars = apply_filters('wpbc_get_stamp_content_by_code', array(
		'stamp_type' => $stamp_type,
		'parts' => $parts,
		'post_id' => $post_id,
		'str' => $str,
		'done' => false
	));
	extract($vars);

	if (!$done){
		do_action('wpbc_before_get_stamp_content', $stamp_type, $post_id);
		switch ($stamp_type){
			case 'profile':
				if ($u = new WP_User($post_id)){
					$m = get_userdata($post_id);
					$str = array(
						'nickname' => wpbc_get_name($m->ID),
						'first_name' => $m->first_name,
						'last_name' => $m->last_name,
						'email' => wpbc_hash($m->user_email),
						'biography' => lint_wpbc_stamp_content($m->description),
						'url' => get_author_posts_url($post_id),
						'wp_registered_at_gmt' => $m->user_registered
					);
				}
				break;

			case 'default':
			case 'content':
				if ($p = get_post($post_id)){
					$str = array(
						'title' => lint_wpbc_stamp_content($p->post_title),
						'content' => lint_wpbc_stamp_content($p->post_content),
						'url' => get_permalink($post_id),
					);
					if (wpbc_post_type_supports(get_post_type($post_id), 'author')){
						$str['authors'] = array();
						foreach (wpbc_get_authors($post_id) as $author_id)
							$str['authors'][] = wpbc_get_name($author_id);
					}
				}
				break;
		}
	}

	if (!is_array($str))
		$str = array('content' => lint_wpbc_stamp_content($str));

	$str += array(
		'origin' => apply_filters('wpbc_stamp_origin', 'WP Blockchain'),
		'format' => apply_filters('wpbc_stamp_format', 'wpblockchain'),

	);

	$cache[$stamp_base] = json_encode($str);
	return $cache[$stamp_base];
}

function lint_wpbc_stamp_content($str){

	// remove all (real) shortcodes
	$str = preg_replace_callback('/'.get_shortcode_regex().'/s', 'lint_wpbc_stamp_content_shortcodes', $str);

	// remove all html tags (but leave inner content)
	$str = preg_replace('#</?[a-z][^>]*>#is', '', $str);

	return trim($str);
}

function lint_wpbc_stamp_content_shortcodes($m){
	return apply_filters('lint_wpbc_stamp_content_shortcodes', '', $m); // remove shortcodes
}

function wpbc_ajax_stamp(){
	global $wpdb;
	if (empty($_REQUEST['stamp_code']) || !is_user_logged_in())
		wp_die('bad arguments');

	$stamp_code = $_REQUEST['stamp_code'];
	$api = wpbc_get_api();
	$str = wpbc_get_stamp_content_by_code($stamp_code);

	if ($str === false)
		wp_die('bad stamp_type');

	if (!$str)
		wpbc_json_ret(array('success' => false, 'error' => __('Nothing to stamp', 'wpbc')));
	else {

		$parts = explode('-', $stamp_code);
		$hash = array_pop($parts);
		$post_id = intval(array_shift($parts));
		if (!$parts)
			$parts[] = 'default';
		$stamp_type = array_shift($parts);

		if (!wpbc_user_can('edit_stamp_object', $stamp_type == 'profile' ? 'user' : get_post_type($post_id), $post_id))
			wpbc_json_ret(array('success' => false, 'error' => __('Couldn\'t stamp', 'wpbc')));

		$stamp = wpbc_get_stamp_by_code($stamp_code, true);

		if (is_string($stamp))
			wpbc_json_ret(array('success' => false, 'error' => __('Couldn\'t stamp', 'wpbc').': '.$stamp));
		else {
			$statuses = array();
			foreach (array('regular', 'light') as $theme){
				$button = $api->get_stamp_button($stamp, $stamp_code, false, $theme, true);
				$statuses[$theme] = is_array($button) ? $button['html'] : '<div>'.$button.'</div>';
			}
			wpbc_json_ret(array(
				'success' => true,
				'stamp_code' => $stamp_code,
				'statuses' => $statuses
			));
		}
	}
}

function wpbc_get_stamp_content($stamp_type, $post_id = null, $use_proof = null){

	$vars = apply_filters('wpbc_get_stamp_content', array(
		'stamp_type' => $stamp_type,
		'post_id' => $post_id,
		'str' => false,
		'done' => false
	));
	extract($vars);

	if (!$done)
		switch ($stamp_type){

			case 'default':
				if (get_post_status($post_id) == 'publish'
					&& ($p = get_post($post_id))){

					$str = array(
						'title' => trim($p->post_title),
						'content' => trim($p->post_content),
						'url' => get_permalink($post_id)
					);
				}
				break;
		}

	if ($str === null || $str === false)
		return $str;
	if (is_string($str))
		$str = array(
			'content' => $str,
		);

	return json_encode($str);
}

function wpbc_ajax_stamp_live(){
	define('WPBC_IS_LIVE_AJAX', true);
	do_action('wpbc_poorman');

	$live = array();
	$live_ids = $_REQUEST['live_ids'];
	foreach ($live_ids as $live_id){
		$return = wpbc_get_live_return($live_id);
		if ($return !== null)
			$live[] = array(
				'live_id' => $live_id,
				'html' => $return
			);
	}
	wpbc_json_ret(array('success' => true, 'live_ids' => $live));
}

function wpbc_build_print_table($input){
	$trs = array();
	$is_assoc = !is_array($input) || !isset($input[0]);
	foreach ($input as $k => $v){
		if ($k == 'authors'){
			$inner = wpbc_plural($v);
		} else
			$inner = is_array($v) ? wpbc_build_print_table($v) : nl2br(htmlentities($v));
		$trs[] = '<tr>'.($is_assoc ? '<td class="wpbc-stamp-decoded-title">'.wpbc_decoded_attribute_label($k, $v).'</td>' : '').'<td>'.$inner.'</td></tr>';
	}
	return '<table class="wpbc-stamp-decoded-table">'.implode('', $trs).'</table>';
}

function wpbc_get_live_return($live_id, $update = null){
	global $wpdb;
	static $use_cache = false;

	$return = null;
	$parts = explode('-', $live_id);

	$action = array_shift($parts);
	if ($api = wpbc_get_api()){

		$stamp_code = implode('-', $parts);
		$post_id = $parts && is_numeric($parts[0]) ? intval($parts[0]) : null;
		$stamp_type = $post_id && count($parts) > 1 ? $parts[1] : null;

		if ($stamp_type == 'profile')
			$post_id = 0;
	}

	$last_bit = false;
	switch ($action){
		case 'stamp_history':
		case 'stamp_type':
		case 'stamp_status_icon':
		case 'stamp_hash':
			$last_bit = !strcmp(array_pop($parts), 1);
			$stamp_code = implode('-', $parts);
			break;

		case 'stamps_status':

			if (!get_option('wpbc_blockchains', false))
				return __('Please configure WP Blockchain to show stamps statistics here.', 'wpbc').wpbc_live_delay_anchor(30);

			else if (!is_wpbc_configured(true))
				return __('Please configure stamppable objects to show stamps statistics here.', 'wpbc').wpbc_live_delay_anchor(30);

			$str = array();
			$to_be_updated = $queued = 0;

			foreach (apply_filters('wpbc_stamps_statuses', array(
				'total' => array('certificate', __('stamps', 'wpbc')),
				'done' => array('check', __('stamped', 'wpbc'), 'green'),
				'pending' => array('clock-o', __('stamping', 'wpbc'), 'orange'),
			)) as $cstatus => $status_conf){

				if ($status_conf === null){
					$str[] = '<span class="wpbc-hsep"></span>';
					continue;
				}

				$after = '';
				$c = false;

				extract(apply_filters('wpbc_stamps_status', array(
					'api' => $api,
					'c' => $c,
					'after' => $after,
					'str' => $str,
					'cstatus' => $cstatus,
					'status_conf' => $status_conf,
					'to_be_updated' => $to_be_updated,
					'queued' => $queued,
					'done' => false,
				)));

				if (!$done){
					if ($cstatus == 'pending')
						$c = $wpdb->get_var('SELECT COUNT(stamp_id) FROM '.WPBC_DB_PREFIX.'stamps WHERE next_update IS NOT NULL');

					else if ($cstatus == 'total')
						$total = $c = intval($wpdb->get_var('SELECT COUNT(stamp_id) FROM '.WPBC_DB_PREFIX.'stamps'));

					else if ($cstatus == 'done'){
						$c = intval($wpdb->get_var('SELECT COUNT(stamp_id) FROM '.WPBC_DB_PREFIX.'stamps WHERE status = "confirmed" AND next_update IS NULL'));
						$status_conf[1] .= ' ('.($c == $total ? '100%' : round(min(100*$c/$total, 99)).'%').')';

					} else
						$c = $wpdb->get_var($wpdb->prepare('SELECT COUNT(stamp_id) FROM '.WPBC_DB_PREFIX.'stamps WHERE status = %s', $cstatus));
				}

				if ($c)
					$str[] = '<span class="wpbc-stamps-status">'.(!empty($status_conf[0]) ? '<i '.(!empty($status_conf[2]) ? 'style="color: '.$status_conf[2].'" ' : '').'class="fa fa-'.$status_conf[0].'"></i> ' : '').($c !== true ? (is_numeric($c) ? number_format($c) : $c).' ' : '').$status_conf[1].$after.'</span>';
			}

			if ($str)
				$return = '<div class="wpbc-stamps-summary">'.implode('', $str).'</div>';
			else {
				$stamppable = array();
				foreach (wpbc_get_post_types() as $post_type => $pt)
					if (!empty($pt['mode']))
						$stamppable[] = $post_type == 'user' ? __('users', 'wpbc') : mb_strtolower(get_post_type_object($post_type)->labels->name);
				$return = sprintf(__('No stamps statistics to show yet. Use the buttons in edition pages of stamppable objects (%s) to throw your first stamp!', 'wpbc'), wpbc_plural($stamppable));
			}

			$return = apply_filters('wpbc_stamps_status_return', $return, !!$str);

			$return .= wpbc_live_delay_anchor(20);

			return $return;
	}

	if (!$api)
		return false;

	$autostamp = wpbc_must_autostamp($post_id, $stamp_code);

	//## echo "MY wpbc_get_stamp_by_code: ".$stamp_code.'<br>';
//	echo 'LIVE_API: '.$stamp_code.'<br>';
	$stamp = wpbc_get_stamp_by_code($stamp_code, $autostamp);

	if (is_string($stamp))
		$stamp = false;

	if ($update === null){

		if (wpbc_is_admin() && !empty($_GET['update']))
			$update = true;
		else if (!$stamp && wpbc_must_autostamp($post_id, $stamp_code))
			$update = true;
		else if (!defined('WPBC_IS_AJAX') || !WPBC_IS_AJAX)
			$update = false;
		else if ($stamp && !empty($stamp['updated_at']) && strtotime($stamp['updated_at']) < strtotime('-'.$api->check_frequency))
			$update = true;
		else
			$update = false;

	}
//## echo " < PRE > ";
	$ret = $api->api($stamp, $stamp_code, $update, $use_cache);

	$use_cache = true;

	$return_now = apply_filters('wpbc_get_live_return', null, $action, $ret);
	if ($return_now !== null)
		return $return_now;

	switch ($action){
		case 'stamp_content_decoded':
			if ($ret){
				$ret = wpbc_lint_ret($ret);
				if (!isset($ret['content_decoded'])){
					if (isset($ret['content']))
						$return = $ret['content'];
					else
						$return = '';
				} else if (is_string($ret['content_decoded']))
					$return = nl2br(htmlentities($ret['content_decoded']));
				else
					$return = wpbc_build_print_table($ret['content_decoded']);

			}
			break;

		case 'stamp_history':
			if ($ret)
				$return = $api->get_stamp_history_from_api_ret($ret, $stamp_code, $last_bit);
			break;

		case 'api_human':
			if ($ret){
				$ret = wpbc_lint_ret($ret);
				$return = wpbc_pretty_json(array(
					'success' => true,
					'results' => $ret
				));

			} else
				$return = wpbc_pretty_json(array(
					'success' => false,
					'error' => __('Stamp not found', 'wpbc')
				));

			break;

		case 'stamp_hash':
			$parts = explode('-', $stamp_code);
			$hash = array_pop($parts);
			$stamp_base = implode('-', $parts);
			if ($last_bit)
				$return = '<a rel="nofollow" href="'.$api->get_stamp_url_from_code($stamp_base.'-'.$ret['hash']).'">'.$ret['hash'].'</a>';
			else
				$return = wpbc_print_copybox($ret['hash']);

			break;

		case 'stamp_type':
			if ($ret){
				$hash = array_pop($parts);
				$stamp_base = implode('-', $parts);

				$return = $ret['stamp_name'];
				if ($hash != 'last'){
					$content = wpbc_get_stamp_content_by_code($stamp_base.'-last');
					if ($api->get_hash($content) != $ret['hash'])
						$return .= '<div class="wpbc-api-ret-var-legend wpbc-api-ret-var-legend-warning"><i class="fa fa-warning"></i> '.sprintf(__('You are seeing a stamp revision, %s', 'wpbc'), '<a rel="nofollow" href="'.$api->get_stamp_url_from_code($stamp_base.'-last').'">'.__('see last stamp', 'wpbc').'</a>').'</div>';
				}
			}
			break;

		case 'stamp_button':
		case 'stamp_button_light':
			global $wpbc_tobar;
			$wpbc_tobar = true;//$action == 'stamp_button_light';
			$return = $api->get_stamp_button($stamp, $stamp_code, false, $action == 'stamp_button_light' ? 'light' : 'regular');
			$return = is_array($return) ? $return['html'] : $return;
			$wpbc_tobar = false;
			break;

		case 'stamp_confirmed_at':
			$return = !empty($ret['confirmed_at'])
					? '<span title="'.$ret['stamped_at'].' GMT-0">'.ucfirst(wpbc_human_time_diff(strtotime(date_i18n('Y-m-d H:i:s')) - strtotime($ret['stamped_at']))).' ('.get_date_from_gmt($ret['stamped_at']).' GMT'.($ret['gmt_offset'] < 0 ? '' : '+').$ret['gmt_offset'].')'
					: '-';
			if (wpbc_is_admin() && !empty($ret['stamped_at']))
				$return .= '<span style="color: #aaa"> &mdash; <span title="'.esc_attr(__('next fetch', 'wpbc').': '.($stamp['next_update'] ? get_date_from_gmt($stamp['next_update']).' GMT'.($ret['gmt_offset'] < 0 ? '' : '+').$ret['gmt_offset'] : sprintf(__('every %s', 'wpbc'), wpbc_human_time_diff(strtotime('+'.WPBC_MIN_UPDATE_PERIOD) - time(), false, false)))).'">'.__('next fetch', 'wpbc').' '.($stamp['next_update'] ? wpbc_human_time_diff(time() - strtotime($stamp['next_update'])) : sprintf(__('every %s', 'wpbc'), wpbc_human_time_diff(strtotime('+'.WPBC_MIN_UPDATE_PERIOD) - time(), false, false))).'</span></span>';

			if (!empty($ret['valid_until'])){
				$return .= '<br><span title="'.$ret['valid_until_gmt'].' GMT-0">'.__('Valid until', 'wpbc').' '.wpbc_human_time_diff($ret['valid_until_gmt']).' ('.$ret['valid_until'].' GMT'.($ret['gmt_offset'] < 0 ? '' : '+').$ret['gmt_offset'].')</span>';

				if (!empty($ret['stamped_at_gmt']))
					$return .= '<span style="color: #aaa"> &mdash; '.__('duration', 'wpbc').': '.wpbc_human_time_diff(strtotime($ret['valid_until_gmt']) - strtotime($ret['stamped_at_gmt']), false).'</span>';

			}
			break;

		case 'stamp_status':
			$return = $api->get_stamp_status_from_api_ret($ret);
			break;

		case 'stamp_status_icon':
			$return = $api->get_status_icon_from_api_ret($ret, $last_bit);
			break;

		case 'stamp_blockchain_statuses':
			if ($ret)
				$return = $api->get_blockchain_statuses_from_api_ret($ret);
			break;
	}
	return $return;
}

function wpbc_print_merkle_tree($hash, $tree, $is_root = true, $ik = 1){
	$t = array_shift($tree);

	$new_hash = wpbc_get_merkle_hash(!empty($t['left']) ? $t['left'] : null, $hash, !empty($t['right']) ? $t['right'] : null);

	ob_start();
	$excerpt_length = 20;
	$excerpt_from_end = 0;

	$title_circle = __('This (SHA256) hash is the concatenation of its two (uppercase, binary) children below it.', 'wpbc');

	$another = '<span class="wpbc-hash-legend-tip" title="'.esc_attr($title_circle).'">'.__('Another hash', 'wpbc').' <i class="fa fa-info-circle"></i></span>';

	$class = !empty($t['left']) ? ' wpbc-stamping-tree-left' : '';

	$class_left = !empty($t['left'])
		? ' wpbc-stamping-tree-branch-other'
		: ($is_root
			? ' wpbc-stamping-tree-branch-ours'
			: ' wpbc-stamping-tree-branch-other-sum'
		);

	$class_right = !empty($t['right'])
		? ' wpbc-stamping-tree-branch-other'
		: ($is_root
			? ' wpbc-stamping-tree-branch-ours'
			: ' wpbc-stamping-tree-branch-other-sum'
		);

	?><div class="wpbc-stamping-tree<?= $class ?>">
		<?php ?>
		<div class="wpbc-stamping-tree-cell"><?php
			if (empty($tree)){ ?>
				<div class="wpbc-stamping-tree-branch wpbc-stamping-tree-branch-root"><div>
					<div class="wpbc-stamping-hash"><?= wpbc_print_copybox($new_hash, true, $excerpt_length, $excerpt_from_end) ?></div>
					<div class="wpbc-stamping-hash-legend"><?php
						echo '<span class="wpbc-hash-legend-tip" title="'.esc_attr($title_circle.' '.__('This is the hash stamped into blockchain.', 'wpbc')).'">';
						echo __('Stamped hash', 'wpbc');
						echo ' <i class="fa fa-info-circle"></i></span>';
					?></div>
				</div></div>
				<?php
			} else
				echo wpbc_print_merkle_tree($new_hash, $tree, false, $ik);
		?></div>
		<div class="wpbc-stamping-tree-branches">
			<div class="wpbc-stamping-tree-branch<?= $class_left ?>"><div><?php
				if (empty($t['left'])){
					?>
					<div class="wpbc-stamping-hash"><?= wpbc_print_copybox($hash, true, $excerpt_length, $excerpt_from_end) ?></div>
					<div class="wpbc-stamping-hash-legend"><?= ($is_root ? __('Our hash', 'wpbc') : $another) ?></div><?php

				} else {
					?>
						<div class="wpbc-stamping-hash"><?= wpbc_print_copybox($t['left'], true, $excerpt_length, $excerpt_from_end) ?></div>
						<div class="wpbc-stamping-hash-legend"><?= __('Another hash', 'wpbc') ?></div>
					<?php
				}
			?></div></div>
			<div class="wpbc-stamping-tree-branch<?= $class_right ?>"><div><?php
				if (empty($t['right'])){
										?>
					<div class="wpbc-stamping-hash"><?= wpbc_print_copybox($hash, true, $excerpt_length, $excerpt_from_end) ?></div>
					<div class="wpbc-stamping-hash-legend"><?= ($is_root ? __('Our hash', 'wpbc') : $another) ?></div><?php

				} else {
					?>
						<div class="wpbc-stamping-hash"><?= wpbc_print_copybox($t['right'], true, $excerpt_length, $excerpt_from_end) ?></div>
						<div class="wpbc-stamping-hash-legend"><?= __('Another hash', 'wpbc') ?></div>
					<?php
				}
			?></div></div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

function wpbc_print_stamps($stamps, $print_labels = true, $extra_classes = ''){
	ob_start();
	?><div class="<?= $extra_classes ?> wpbc-stamping-status"><?php

	foreach ($stamps as $stamp_type => $s){
		echo '<div class="wpbc-stamp-wrap">';
		if ($print_labels && !empty($s['label']))
			echo '<div class="wpbc-stamping-status-intro">'.$s['label'].': </div>';
		//echo 'stamp_button-'.$s['base'].'-last';
		echo '<div class="wpbc-stamp-inner">';

		//echo $s['base'].'-last<br><br>';
		$stamp = wpbc_get_stamp_by_code($s['base'].'-last');
		echo wpbc_convert_to_live('stamp_button-'.$s['base'].'-last');
		echo '</div></div>';
	}

	?></div><?php
	return ob_get_clean();
}

function wpbc_box_stamp_child($post){
	if ($stamps = wpbc_get_stamps($post->ID))
		echo wpbc_print_stamps($stamps, true, 'wpbc-stamping-status-box');
	do_action('wpbc_metabox');
}

function wpbc_call_block_method($fn, $args = array()){
	static $apis = array();
	if (!isset($apis[$fn])){
		$apis[$fn] = array();

		foreach (wpbc_get_api_folders() as $folder){
			if ($dir = opendir($folder.'/block_explorers'))
				while (false !== ($filename = readdir($dir))){
					$provider_id = preg_replace('#(\.php)$#i', '', $filename);
					if ($provider_id == $filename)
						continue;

					$api = wpbc_get_api($provider_id, 'block_explorers');
					if (!$api)
						continue;

					if (method_exists($api, $fn))
						$apis[$fn][] = $api;
				}
		}
	}
	if (empty($apis[$fn]))
		return null;
	for ($i=0; $i<count($apis[$fn]); $i++){
		$ret = call_user_func_array(array($apis[$fn][$i], $fn), $args);
		if ($ret !== null && $ret !== false)
			break;
	}
	return $ret ? $ret : false;
}

function wpbc_get_block_cached_method($fn, $allow_fetch = false, $cache_duration = null){
	static $cache = array();
	if (!isset($cache[$fn])){
		$cache[$fn] = get_option('wpbc_get_'.$fn, null);

		if ($cache_duration && $cache[$fn] && $cache[$fn]['time'] > (is_string($cache_duration) ? strtotime('-'.$cache_duration) : time() - $cache_duration))
			$cache[$fn] = $cache[$fn]['value'];

		else if ($allow_fetch){

			$new_value = wpbc_call_block_method('get_'.$fn);
			if (!$new_value)
				$new_value = null;

			update_option('wpbc_get_'.$fn, array(
				'time' => current_time( 'timestamp' ),
				'value' => $new_value ? $new_value : ($cache[$fn] && !is_array($cache[$fn]['value']) ? $cache[$fn]['value'] : null)
			));

			if ($new_value)
				$cache[$fn] = $new_value;
			else
				$cache[$fn] = $cache[$fn] && !is_array($cache[$fn]['value']) ? $cache[$fn]['value'] : null;

		} else if ($cache[$fn])
			$cache[$fn] = $cache[$fn]['value'];
	}
	return $cache[$fn] ? $cache[$fn] : null;
}

function wpbc_get_block_height($bc_id, $allow_fetch = false, $cache_duration = 80){
	$height = wpbc_get_block_cached_method('block_height_'.$bc_id, $allow_fetch, $cache_duration);
	return $height && is_string($height) ? intval($height) : $height;
}

function wpbc_get_block_time($bc_id, $allow_fetch = false, $cache_duration = 80){
	return wpbc_get_block_cached_method('block_time_'.$bc_id, $allow_fetch, $cache_duration);
}

function wpbc_get_time_proof($allow_fetch = false, $cache_duration = null){
	return wpbc_get_block_cached_method('time_proof', $allow_fetch, $cache_duration);
}

function wpbc_get_current_stamp_code(){
	global $user_id;
	global $pagenow;

	if (($is_user_page = !empty($user_id)) || (!empty($pagenow) && in_array($pagenow, array('profile.php', 'user-edit.php'))))
		$post_id = !empty($user_id) ? $user_id : null;
	else if (is_single() || is_singular() || in_the_loop() || (!empty($pagenow) && in_array($pagenow, array('post.php'))))
		$post_id = get_the_ID();
	else
		return false;

	$vars = array(
		'post_id' => $post_id,
		'stamp_type' => isset($atts['type']) ? $atts['type'] : null,
		'hash' => isset($atts['hash']) ? $atts['hash'] : 'last',
		'stamp_code' => null
	);

	$vars = apply_filters('wpbc_current_stamp', $vars);
	extract($vars);

	if (empty($stamp_type))
		$stamp_type = $is_user_page ? 'profile' : 'default';

	if (empty($stamp_code))
		$stamp_code = $post_id.'-'.$stamp_type.'-'.$hash;

	return $stamp_code;
}

add_shortcode('wpbc', 'wpbc_shortcode_stamp');
add_shortcode('wpbc_stamp', 'wpbc_shortcode_stamp');
function wpbc_shortcode_stamp($atts = array(), $content = ''){
	if (!wpbc_user_can('see_stamps'))
		return '';

	if (empty($atts))
		$atts = array();
	$stamp_code = !empty($atts['code']) ? $atts['code'] : wpbc_get_current_stamp_code();

	ob_start();

	?><div class="wpbc-stamping-status"><?php

	if (!empty($content))
		$atts['intro'] = $content;

	if (!empty($atts['intro']))
		echo $atts['intro'];

	echo wpbc_convert_to_live('stamp_button-'.$stamp_code);

	?></div><?php

	return ob_get_clean();
}

add_action('wpbc', 'wpbc', 0, 1);
function wpbc($args = array()){
	if (!wpbc_user_can('see_stamps'))
		return;

	if (empty($args))
		$args = array();

	echo wpbc_shortcode_stamp($args);
}

function wpbc_deactivate_stamp_failed($hash, $debug = false){
	global $wpdb;
	if ($debug)
		wpbc_log('info', 'DELETING '.$hash);
	$wpdb->query($wpdb->prepare('DELETE FROM '.WPBC_DB_PREFIX.'stamps WHERE hash = %s', $hash));
}

function wpbc_stamp_by_code($stamp_code, $force_restamp = false, $debug = false){
	global $wpdb;

	$parts = explode('-', $stamp_code);
	$id = intval(array_shift($parts));
	$stamp_type = array_shift($parts);
	$meta_type = $stamp_type == 'profile' ? 'user' : 'post';

	$just_created = false;

	$stamp = wpbc_get_stamp_by_code($stamp_code, true, false, $just_created, $force_restamp, $debug);

	if (empty($stamp))
		wpbc_log('info', 'failure in stamp_by_code: empty return');

	else if (is_string($stamp))
		wpbc_log('info', 'error from wpbc_get_stamp_by_code: '.$stamp);

	else if ($just_created)
		wpbc_log('info', 'just created in stamp_by_code');

	else if (!($api = wpbc_get_api($stamp['provider'])))
		wpbc_log('info', 'failure in stamp_by_code: no such provider');

	else {

		wpbc_log('info', 'stamp_by_code found stamp '.$stamp['stamp_id'].' ('.$stamp['hash'].') for code '.$stamp_code);
		wpbc_log('info', 'will now check transactions..');

		$api->check_transactions($stamp, true, $debug);

	}

	return !empty($stamp) && !is_string($stamp);
}

function wpbc_ajax_delete_stamp(){
	global $wpdb;

	if (!wpbc_is_admin() || empty($_POST['stamp_id']) || !is_numeric($_POST['stamp_id']) || !($stamp = wpbc_get_stamp($_POST['stamp_id'], true)))
		wpbc_json_ret(array('success' => false, 'error' => __('stamp not found', 'wpbc')));

	$wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->usermeta.' WHERE meta_key LIKE "wpbc_%%" AND meta_value = %s', $_POST['stamp_id']));
	$wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->postmeta.' WHERE meta_key LIKE "wpbc_%%" AND meta_value = %s', $_POST['stamp_id']));
	$wpdb->query($wpdb->prepare('DELETE FROM '.WPBC_DB_PREFIX.'stamps WHERE stamp_id = %d', $_POST['stamp_id']));
	wpbc_json_ret(array('success' => true, 'msg' => sprintf(__('Stamp #%s was successfuly deleted', 'wpbc'), $_POST['stamp_id'])));
}

function wpbc_decoded_attribute_label($k, $value){
	switch ($k){

		case 'nickname': 				return __('Nickname', 'wpbc');
		case 'first_name': 				return __('First name', 'wpbc');
		case 'last_name': 				return __('Last name', 'wpbc');
		case 'email': 					return __('Email', 'wpbc');
		case 'biography': 				return __('Biography', 'wpbc');
		case 'url': 					return __('URL', 'wpbc');
		case 'wp_registered_at_gmt': 	return __('Registered at (GMT)', 'wpbc');

		case 'title':					return __('Title', 'wpbc');
		case 'content': 				return __('Content', 'wpbc');
		case 'authors':					return $value && count($value) > 1 ? __('Authors', 'wpbc') : __('Author', 'wpbc');

		case 'origin':					return __('Origin', 'wpbc');
		case 'format':					return __('Format', 'wpbc');

	}
	return $k;
}

function wpbc_user_can_see_code_content($stamp_code){
	$code = explode('-', $stamp_code);
	$is_last = empty($code[2]) || $code[2] == 'last';
	if ($code[1] == 'user')
		return $is_last && intval($code[0]) == get_current_user_id() || current_user_can('manage_options');

	return (get_post_status($code[0]) == 'publish' && $is_last) || current_user_can('manage_options') || current_user_can('read', $code[0]);
}

function wpbc_must_autostamp($post_id, $stamp_code = null, $post_type = null){
	return apply_filters('wpbc_must_autostamp', false, $post_id, $stamp_code, $post_type);
}

