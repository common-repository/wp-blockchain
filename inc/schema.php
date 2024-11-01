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

global $wpdb;
$tables = array();

$tables['stamps'] =
	"stamp_id BIGINT(11) NOT NULL AUTO_INCREMENT,
	hash VARCHAR(200) NOT NULL,
	hash_method VARCHAR(50) NOT NULL,
	content TEXT NOT NULL,
	created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
	stamped_at datetime DEFAULT '0000-00-00 00:00:00',
	confirmed_at datetime DEFAULT '0000-00-00 00:00:00',
	updated_at datetime DEFAULT '0000-00-00 00:00:00',
	restamped_at datetime DEFAULT '0000-00-00 00:00:00',
	next_update datetime,
	status VARCHAR(30) NOT NULL,
	provider VARCHAR(30) NOT NULL,
	provider_id VARCHAR(200),
	stamp_parent VARCHAR(200),
	pending_time TEXT,
	stamp_base VARCHAR(200),
	restamps SMALLINT(2) DEFAULT 0,
	PRIMARY KEY  (stamp_id)";

$tables['stamp_trees'] =
	"tree_id BIGINT(11) NOT NULL AUTO_INCREMENT,
	hash VARCHAR(200) NOT NULL,
	hash_method VARCHAR(200) NOT NULL,
	siblings TEXT NOT NULL,
	merkle_root VARCHAR(200) NOT NULL,
	parent_tree BIGINT(11),
	PRIMARY KEY  (tree_id)";

$tables['stamp_txs'] =
	"id BIGINT(11) NOT NULL AUTO_INCREMENT,
	tx_id VARCHAR(200) NOT NULL,
	hash VARCHAR(200) NOT NULL,
	prefix VARCHAR(100),
	bc_id VARCHAR(10) NOT NULL,
	created_at datetime NOT NULL,
	status VARCHAR(50) NOT NULL,
	PRIMARY KEY  (id)";

$tables['stamp_tx_confirmations'] =
	"id bigint(11) NOT NULL AUTO_INCREMENT,
	tx_id VARCHAR(200) NOT NULL,
	provider VARCHAR(200) NOT NULL,
	confirmations BIGINT(11),
	confirmed_at datetime,
	block_number BIGINT(11),
	PRIMARY KEY  (id)";

$tables['locks'] =
	"id bigint(11) NOT NULL AUTO_INCREMENT,
	lock_key VARCHAR(200),
	lock_hash VARCHAR(200),
	locked_at datetime NOT NULL,
	PRIMARY KEY  (id)";

require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
foreach ($tables as $table_name => $table_def)
	dbDelta( "CREATE TABLE ".WPBC_DB_PREFIX.$table_name." (
	".$table_def."
	)
	COLLATE ".$wpdb->collate );

wpbc_check_index('stamps', 'hash', array('hash'));
wpbc_check_index('stamps', 'hash_plus', array('hash', 'hash_method'));
wpbc_check_index('stamps', 'updated_at', array('updated_at'));
wpbc_check_index('stamps', 'next_update', array('next_update'));
wpbc_check_index('stamps', 'status', array('status'));

wpbc_check_index('stamp_trees', 'hash', array('hash'));
wpbc_check_index('stamp_trees', 'hash_method', array('hash', 'hash_method'));
wpbc_check_index('stamp_trees', 'merkle_root', array('merkle_root'));

wpbc_check_index('stamp_txs', 'hash', array('hash'));
wpbc_check_index('stamp_txs', 'tx_id', array('tx_id'));

wpbc_check_index('stamp_tx_confirmations', 'tx_id', array('tx_id'));

wpbc_check_index('locks', 'lock_hash', array('lock_hash'));
wpbc_check_index('locks', 'lock_key', array('lock_key'));

