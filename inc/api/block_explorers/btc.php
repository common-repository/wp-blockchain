<?php
/*
WP Blockchain
https://wp-blockchain.com
License: GPLv2 or later

Copyright (c) 2018, Good Rebels Inc.
Please feel free to contact us at wp-blockchain@goodrebels.com.
*/



class WPBC_API_Btc extends WPBC_API {

	public $api_url = 'https://chain.api.btc.com/v3/tx/%s';

	public function get_api_config(){
		return array(
			'name' => 'BTC.com',
		);
	}

	public function get_public_args(){
		return array(
			'fields' => array(
				'username' => __('Client ID', 'wpbc'),
				'password' => __('Client secret', 'wpbc')
			),
			'info' => sprintf(__('API keys can be generated %s or %s to Btc.com, then going to %s', 'wpbc'),
				'<a rel="nofollow" href="https://dev.btc.com/register" target="_blank">'.__('creating an account', 'wpbc').'</a>',
				'<a rel="nofollow" href="https://dev.btc.com/login" target="_blank">'.__('logging in', 'wpbc').'</a>',
				'<a rel="nofollow" href="https://dev.btc.com/" target="_blank">'.__('its API dashboard', 'wpbc').'</a>'
			)
		);
	}

	function check_transaction_btc(&$stamp, $bc_config, &$tx, &$api){
		global $wpdb;
		$api_key = get_option('wpbc_provider_btc_username');
		$api_pass = get_option('wpbc_provider_btc_password');

		$ret = wpbc_fetch(sprintf($this->api_url, $tx['tx_id']).'?api_key='.$api_key, true);

		if (!$ret || !is_object($ret) || !property_exists($ret, 'data') || !is_object($ret->data)
			|| !property_exists($ret->data, 'block_height') || empty($ret->data->block_height)
			|| !property_exists($ret->data, 'confirmations'))
			return false;

		$confirmed_at = date("Y-m-d H:i:s");
		return $api->declare_confirmation_amount($stamp, $tx, $bc_config, $ret->data->confirmations, $confirmed_at, $ret->data->block_height);
	}

	function get_block_time_btc(){

		if (!($ret = wpbc_fetch('https://chain.api.btc.com/v3/block/latest', true))
			|| !is_object($ret)
			|| !property_exists($ret, 'data')
			|| !is_object($ret->data)
			|| !property_exists($ret->data, 'timestamp')
			|| empty($ret->data->timestamp))
			return null;

		return date('Y-m-d H:i:s', $ret->data->timestamp);
	}

	function get_block_height_btc(){

		if (!($ret = wpbc_fetch('https://chain.api.btc.com/v3/block/latest', true))
			|| !is_object($ret)
			|| !property_exists($ret, 'data')
			|| !is_object($ret->data)
			|| !property_exists($ret->data, 'height')
			|| empty($ret->data->height))
			return null;

		return $ret->data->height;
	}

}

