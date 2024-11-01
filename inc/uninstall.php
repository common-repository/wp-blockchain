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

function wpbc_delete_all_stamps(){
	global $wpdb;

	$wpdb->query('DELETE FROM '.WPBC_DB_PREFIX.'stamps WHERE 1=1');
	$wpdb->query('DELETE FROM '.WPBC_DB_PREFIX.'stamp_txs WHERE 1=1');
	$wpdb->query('DELETE FROM '.WPBC_DB_PREFIX.'stamp_tx_confirmations WHERE 1=1');
	$wpdb->query('DELETE FROM '.WPBC_DB_PREFIX.'locks WHERE 1=1');

	$wpdb->query('DELETE FROM '.$wpdb->postmeta.' WHERE meta_key LIKE "wpbc%"');
	$wpdb->query('DELETE FROM '.$wpdb->usermeta.' WHERE meta_key LIKE "wpbc%"');

	$wpdb->query('DELETE FROM '.$wpdb->options.' WHERE option_name = "wpbc_restamps"');
	$wpdb->query('DELETE FROM '.$wpdb->options.' WHERE option_name LIKE "wpbc_get_block_%"');
	$wpdb->query('DELETE FROM '.$wpdb->options.' WHERE option_name LIKE "wpbc_get_disk_%"');
	$wpdb->query('DELETE FROM '.$wpdb->options.' WHERE option_name LIKE "wpbc_get_db_%"');

}

function wpbc_reset_settings(){
	global $wpdb;

	$wpdb->query('DELETE FROM '.$wpdb->options.' WHERE option_name LIKE "wpbc%" AND option_name != "wpbc_restamps"');
}
