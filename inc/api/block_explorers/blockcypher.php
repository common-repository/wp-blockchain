<?php
/*
WP Blockchain
https://wp-blockchain.com
License: GPLv2 or later

Copyright (c) 2018, Good Rebels Inc.
Please feel free to contact us at wp-blockchain@goodrebels.com.
*/


class WPBC_API_Blockcypher extends WPBC_API {

	public function get_public_args(){
		return array(
			'fields' => array(
			)
		);
	}

	function get_block_height_btc(){
		if (!($ret = wpbc_fetch('https://api.blockcypher.com/v1/btc/main', true)))
			return false;

		try {
			if (property_exists($ret, 'error') && $ret->error){
				wpbc_log('api_error', 'BlockCypher Error: '.$ret->error);
				return false;
			}
			$block_height = $ret->height;
		} catch (Exception $e){
			return false;
		}
		return $block_height ? $block_height : null;
	}

	function get_block_time_btc(){
		$url = 'https://api.blockcypher.com/v1/btc/main';

		if (!($ret = wpbc_fetch($url, true)))
			return false;

		try {
			if (property_exists($ret, 'error') && $ret->error){
				wpbc_trigger_fetch_error($url, $httpcode, $ret->error);
				return false;
			}
			$time = strtotime(substr($ret->time, 0, 19));
		} catch (Exception $e){
			return false;
		}
		return $time ? date('Y-m-d H:i:s', $time) : null;
	}

}
