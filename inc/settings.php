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

add_action('admin_menu', 'wpbc_register_menu_pages');
function wpbc_register_menu_pages(){
	if (apply_filters('wpbc_add_menu', true)){
		add_submenu_page('options-general.php', __('WP Blockchain - Settings', 'wpbc'), __('WP Blockchain', 'wpbc'), WPBC_SETTINGS_ROLE, 'wp-blockchain-settings', 'wpbc_settings_page');
	}
}

function has_wpbc_notice(){
	return !wpbc_is_cron_detected($last_cron) || !is_wpbc_notice_dismissed('ad') || !is_wpbc_notice_dismissed('cron');
}

function is_wpbc_notice_dismissed($notice_id){
	return in_array($notice_id, get_user_meta(get_current_user_id(), 'wpbc_notice_dismissed', false));
}

function is_wpbc_page($page = null){
	return is_admin() && !empty($_GET['page']) && ($page ? $page == $_GET['page'] : in_array($_GET['page'], array('wp-blockchain', 'wp-blockchain-settings')));
}

function is_wpbc_blockchain_enabled($blockchain_id){
	return !is_wpbc_configured() || in_array($blockchain_id, get_option('wpbc_blockchains', array()));
}

function is_wpbc_configured($with_stamppable = false){
	static $cache = array();
	$with_stamppable = $with_stamppable ? 1 : 0;
	if (!isset($cache[$with_stamppable])){
		$cache[$with_stamppable] = get_option('wpbc_blockchains', null) !== null && get_option('wpbc_provider_username', '') != '';
		if ($cache[$with_stamppable] && $with_stamppable){
			$cache[$with_stamppable] = false;
			foreach (wpbc_get_post_types() as $pt)
				if (!empty($pt['mode']))
					$cache[$with_stamppable] = true;
		}
	}
	return $cache[$with_stamppable];
}

function wpbc_get_post_types(){
	return apply_filters('wpbc_post_types', get_option('wpbc_post_types', array()));
}

add_action('admin_notices', 'wpbc_settings_page_header');
function wpbc_settings_page_header(){

	if (!current_user_can('manage_options'))
		return;

	if (!file_exists(WPBC_CACHE_PATH) && !wp_mkdir_p(WPBC_CACHE_PATH)){

		if (!is_wpbc_notice_dismissed('cache_folder')){
			?><div class="wpbc-notice wpbc-settings-message wpbc-settings-message-warning notice notice-warning is-dismissible" data-wpbc-notice-id="cache_folder"><i class="fa fa-warning"></i> <?php
				echo __('Please create the following folder and make it writable:', 'wpbc');
				echo '<div><code>'.WPBC_CACHE_PATH.'</code></div>';
			?></div><?php
		}

	} else if (!is_writable(WPBC_CACHE_PATH)){

		if (!is_wpbc_notice_dismissed('cache_writable')){
			?><div class="wpbc-notice wpbc-settings-message wpbc-settings-message-warning notice notice-warning is-dismissible" data-wpbc-notice-id="cache_writable"><i class="fa fa-warning"></i> <?php
				echo __('Please make following folder writable:', 'wpbc');
				echo '<div><code>'.WPBC_CACHE_PATH.'</code></div>';
			?></div><?php
		}
	}

	if (is_wpbc_configured() && !wpbc_is_cron_detected($last_cron) && get_option('wpbc_blockchains') && (!is_wpbc_notice_dismissed('cron') || is_wpbc_page())){

		?><div class="wpbc-notice wpbc-settings-message wpbc-settings-message-failure notice notice-error<?php
			if (!is_wpbc_page())
				echo ' is-dismissible" data-wpbc-notice-id="cron';
		?>"><i class="fa fa-warning"></i> <?php

			if (empty($last_cron))
				echo __('Please set up a CRON task every minute to the following URL to allow more efficient background blockchain stamping:', 'wpbc');
			else
				echo sprintf(__('It seems that the WP Blockchain\'s CRON task has not been called in the last %s (%s). Please set up a CRON task every minute to:', 'wpbc'),
					wpbc_human_time_diff(strtotime('+'.WPBC_CRON_DETECT_DELAY) - time(), false),
					sprintf(__('last call %s', 'wpbc'), wpbc_human_time_diff(time() - strtotime($last_cron)))
				);

		?><div class="wpbc-settings-message-code"><code><?= site_url('/?wpbc_cron='.wpbc_get_cron_key()) ?></code></div></div><?php
	}
}

function wpbc_settings_url(){
	return apply_filters('wpbc_settings_url', admin_url('options-general.php?page=wp-blockchain-settings'));
}

add_action( 'admin_notices', 'print_wpbc_ad' );
function print_wpbc_ad(){
	if (!current_user_can('manage_options'))
		return;

//	load_plugin_textdomain('wpbc', false, basename(WPBC_PATH).'/languages');

	if (!is_wpbc_page() && (is_wpbc_configured() || is_wpbc_notice_dismissed('ad'))){

		if (!get_option('wpbc_blockchains')){
			?>
			<div class="wpbc-notice notice notice-warning wpbc-settings-message wpbc-settings-message-warning">
				<?php
					echo sprintf(__('Please %s to stamp your content', 'wpbc'), '<a rel="nofollow" href="'.wpbc_settings_url().'">'.__('configure WP Blockchain', 'wpbc').'</a>');
				?>
			</div>
			<?php
		}
		return;
	}

	if (is_wpbc_notice_dismissed('ad') || is_wpbc_configured())
		return;

	?><div class="wpbc-ad wpbc-notice notice notice-success is-dismissible" data-wpbc-notice-id="ad">
		<div class="wpbc-ad-logo">
			<a rel="nofollow" href="https://www.goodrebels.com/about/" target="_blank" title="<?= esc_attr(__('Good Rebels is a digital strategy and creative company. We work with our clients to build a legacy of performance and innovation, enabling them to thrive whatever the future holds.', 'wpbc')) ?>">
				<img class="wpbc-ad-logo-img" src="<?= WPBC_URL ?>/assets/images/goodrebels-logo.png" />
				<img class="wpbc-ad-logo-img-wide" src="<?= WPBC_URL ?>/assets/images/goodrebels-logo-wide.png" />
			</a>
		</div>
		<div class="wpbc-ad-content">

			<?php if (is_wpbc_page('wp-blockchain')){ ?>
				<header><?= __('Welcome to the blockchain era!', 'wpbc') ?></header>
			<?php } else { ?>
				<header><?= __('Congratulations! You\'ve just entered the blockchain era!', 'wpbc') ?></header>
			<?php } ?>

			<?php if (is_wpbc_page('wp-blockchain-settings')){
				?>
				<content><?= __('Please take a few minutes to configure WP Blockchain and start stamping all your content!', 'wpbc') ?>
			<?php
			} else { ?>
				<content><?= sprintf(__('Please take a few minutes to %s and start stamping all your content!', 'wpbc'), '<a href="'.wpbc_settings_url().'" rel="nofollow">'.__('configure WP Blockchain', 'wpbc').'</a>') ?>
			<?php } ?>

			<?php
				if (!apply_filters('wpbc_has_addon', false))
					echo ' '.sprintf(__('For %s like automatic stamping or stamps customization, please consider buying a %s.', 'wpbc'), '<a href="https://wp-blockchain.com/download/" target="_blank">'.__('advanced features', 'wpbc').'</a>', '<a href="https://wp-blockchain.com/download/" target="_blank">'.__('Premium License', 'wpbc').'</a>');
				?>
				<div class="wpbc-ad-links">
					<?php if (!is_wpbc_page('wp-blockchain-settings')){ ?>
						<a rel="nofollow" href="<?= wpbc_settings_url() ?>" class="button-primary"><?= __('Configure WP Blockchain', 'wpbc') ?></a>
					<?php } ?>
					<?php
						if (!apply_filters('wpbc_has_addon', false)){
							?>
							<a rel="nofollow" href="https://wp-blockchain.com/download/" target="_blank" class="<?= (is_wpbc_page() ? 'button-primary' : 'button-secondary') ?>"><?= __('Premium features', 'wpbc') ?></a>
							<?php
						}
					?>
					<a rel="nofollow" href="https://wp-blockchain.com/support/" target="_blank" class="<?= (is_wpbc_page() ? 'button-secondary' : 'wpbc-ad-link') ?>"><?= __('Plugin support', 'wpbc') ?> &raquo;</a>
					<a rel="nofollow" href="https://www.goodrebels.com/about/" target="_blank" class="wpbc-ad-link"><?= __('About Good Rebels', 'wpbc') ?> &raquo;</a>
				</div>
			</content>
		</div>
	</div><?php
}

function wpbc_ajax_dismiss_ad(){
	if (is_user_logged_in() && isset($_REQUEST['notice_id']) && preg_match('#^[a-z0-9_]+$#', $_REQUEST['notice_id'])){
		add_user_meta(get_current_user_id(), 'wpbc_notice_dismissed', $_REQUEST['notice_id']);
		wpbc_json_ret(array('success' => true));
	}
	wpbc_json_ret(array('success' => false, 'reauth' => true));
}

function wpbc_settings_page(){
	global $wpdb;
	if (!current_user_can(WPBC_SETTINGS_ROLE))
		wp_die();

	$post_types = apply_filters('wpbc_post_types', array_merge(
		array((object) array('name' => 'user', 'label' => __('Users', 'wpbc'), 'public' => true, 'admin_url' => admin_url('users.php'), 'append_disabled' => true)),
		get_post_types(array('public' => true, '_builtin' => true), 'objects'),
		get_post_types(array('public' => true, '_builtin' => false), 'objects'),
		get_post_types(array('public' => false, '_builtin' => false), 'objects')
	));

	$success = null;
	$args = array();
	$requeued_all = false;

	if (!empty($_POST['wpbc_submit']) && wpbc_user_can('save_settings') && wp_verify_nonce(@$_POST['wpbc_nonce'], 'wpbc_settings')){
		do_action('wpbc_settings_before_form');

		if (@$_POST['wpbc_reset_stamps'] === 'on'){
			do_action('wpbc_settings_before_reset_stamps');
			require_once(WPBC_PATH.'/inc/uninstall.php');
			wpbc_delete_all_stamps();
			?><div class="wpbc-settings-message wpbc-settings-message-success is-dismissible notice notice-success"><i class="fa fa-check"></i> <?= __('Stamps were all deleted successfuly', 'wpbc') ?> </div><?php
		}

		if (@$_POST['wpbc_reset_settings'] === 'on'){
			do_action('wpbc_settings_before_reset_settings');
			require_once(WPBC_PATH.'/inc/uninstall.php');
			wpbc_reset_settings();
			?><div class="wpbc-settings-message wpbc-settings-message-success is-dismissible notice notice-success"><i class="fa fa-check"></i> <?= __('Plugin settings were reset successfuly', 'wpbc') ?> </div><?php
		}

		$keys = array('provider');
		foreach (array('' => null) + wpbc_get_blockchains() as $bc_id => $bc_config){

			$prefix = !empty($bc_id) ? 'provider_'.$bc_id : 'provider';

			$provider_id = sanitize_text_field(@$_POST['wpbc_'.$prefix]);

			if (empty($bc_id))
				$args['provider'] = $provider_id;
			update_option('wpbc_'.$prefix, $provider_id);

			$public_args = null;
			if (!empty($provider_id)){
				$api = wpbc_get_api($provider_id, !empty($bc_id) ? 'block_explorers' : 'stamp_services');
				$public_args = $api ? $api->get_public_args() : array('fields' => array());
			}

			foreach (array('username', 'password') as $k){

				$val = $public_args && isset($public_args['fields'], $public_args['fields'][$k]) ? sanitize_text_field(stripslashes(@$_POST['wpbc_'.$prefix.'_'.$k])) : '';

				$args[$prefix.'_'.$k] = $val;
				update_option('wpbc_'.$prefix.'_'.$k, $val);
			}
		}

		$config = array();
		foreach ($post_types as $p){
			$config[$p->name] = array(
				'mode' => sanitize_text_field(@$_POST['wpbc_stamp_mode_'.$p->name]),
				'append' => sanitize_text_field(@$_POST['wpbc_append_stamp_'.$p->name]),
				'visibility' => sanitize_text_field(@$_POST['wpbc_stamp_visibility_'.$p->name]),
				'backend' => sanitize_text_field(@$_POST['wpbc_stamp_backend_'.$p->name]),
			);
		}
		update_option('wpbc_post_types', $config);

		foreach (apply_filters('wpbc_save_html_fields', array('intro', 'stamping_priv')) as $k)
			update_option('wpbc_'.$k, wp_kses(stripslashes(@$_POST['wpbc_'.$k]), wpbc_get_allowed_tags()));

		if (isset($_POST['wpbc_donate_btc'])){
			if (trim($_POST['wpbc_donate_btc']) == '' || preg_match('#^(1[a-km-zA-HJ-Z1-9]{24,33})$#', trim($_POST['wpbc_donate_btc'])))
				update_option('wpbc_donate_btc', trim(sanitize_text_field(stripslashes($_POST['wpbc_donate_btc']))));
			else {
				?><div class="wpbc-settings-message wpbc-settings-message-failure notice notice-error is-dismissible"><i class="fa fa-warning"></i> <?= __('Bad format given for the Bitcoin donation address', 'wpbc') ?><?php if (get_option('wpbc_donate_btc', '') !== '') echo ' '.sprintf(__('Previous address <code>%s</code> was preserved', 'wpbc'), get_option('wpbc_donate_btc')) ?></div><?php
			}
		}

		$bcs = array();
		foreach (wpbc_get_blockchains() as $k => $conf)
			if (@$_POST['wpbc_blockchains_'.$k] === 'on')
				$bcs[] = $k;

		$cur_bcs = get_option('wpbc_blockchains', false);
		if ($cur_bcs)
			foreach ($bcs as $bc)
				if (!in_array($bc, $cur_bcs)){
					// requeue all stamps for restamp in new blockchains

					$wpdb->query($wpdb->prepare('UPDATE '.WPBC_DB_PREFIX.'stamps SET status = "pending", restamps = 0, next_update = %s, pending_time = null, confirmed_at = null WHERE 1 = 1', date('Y-m-d H:i:s')));
					$requeued_all = true;
					break;
				}
		update_option('wpbc_blockchains', $bcs);

		$vis_states = array();
		foreach (wpbc_get_status_labels('states') as $k => $v)
			if (@$_POST['wpbc_visible_states_'.$k] === 'on')
				$vis_states[] = $k;
		$vis_states[] = 'stamped';
		update_option('wpbc_visible_states', array_unique($vis_states));

		foreach (array('visible_only_admins', 'has_fontawesome', 'allow_poorman', 'block_cron') as $k)
			update_option('wpbc_'.$k, @$_POST['wpbc_'.$k] === 'on' ? true : '');

		if (!empty($args['provider'])){
			$was_ok = get_option('wpbc_provider_ok_'.$args['provider']);
			$changed = wpbc_hash(serialize($args)) !== get_option('wpbc_provider_check');

			if (!$was_ok || $changed){
				if (!$changed)
					$success = $was_ok === true;
				else {
					$api = wpbc_get_api($args['provider']);
					$api_config = $api->get_api_config();
					$success = $api->test();
					update_option('wpbc_provider_check', serialize($args));
				}
			}
		}

		do_action('wpbc_settings_save');
		update_option('wpbc_settings_saved', time());
		do_action('wpbc_settings_saved');

		?><div class="wpbc-settings-message wpbc-settings-message-success notice notice-success is-dismissible"><i class="fa fa-check"></i> <?php
			echo __('Settings saved successfuly', 'wpbc');
		?></div><?php

		if ($requeued_all){

			?><div class="wpbc-settings-message wpbc-settings-message-info notice notice-info is-dismissible"><i class="fa fa-info-circle"></i> <?php
				echo __('All stamps were requeued to restamp them in new blockchains', 'wpbc');
			?></div><?php

		}

	} else
		$args['provider'] = get_option('wpbc_provider');

	wpbc_custom_rewrite();
	flush_rewrite_rules();

	if ($success === null && !empty($args['provider'])){
		$api = wpbc_get_api($args['provider']);
		$api_config = $api->get_api_config();
		$was_ok = intval(get_option('wpbc_provider_ok_'.$args['provider'])) === 1;
		if (!$was_ok)
			$success = false;
	}

	if ($success !== null){
		do_action('wpbc_settings_stamping_test', $success);
		if ($success)
			$message = '<i class="fa fa-check"></i> '.sprintf(__('%s API access checked successfully', 'wpbc'), $api_config['name']);
		else
			$message = '<i class="fa fa-warning"></i> '.sprintf(__('%s API access check failed', 'wpbc'), $api_config['name']);

		if ($was_ok !== $success)
			update_option('wpbc_provider_ok_'.$args['provider'], $success ? 1 : 0);
	}

	do_action('wpbc_settings_page_header');

	if ($success !== null){
		?><div class="wpbc-settings-message wpbc-settings-message-<?= ($success ? 'success' : 'failure') ?> notice notice-<?= ($success ? 'success' : 'error') ?> is-dismissible"><?= $message ?> </div><?php
	}

	?>
	<h2 class="wpbc-settings-h2"><?= __('WP Blockchain', 'wpbc') ?> - <?= __('Settings', 'wpbc') ?></h2>

	<?php
	if (is_wpbc_configured() && get_option('wpbc_blockchains', false)){
		?>
		<div class="wpbc-settings-h3-wrap">
			<?php do_action('wpbc_before_stamps_overview'); ?>
			<h3><?= __('Stamps state overview', 'wpbc') ?></h3>
		</div>
		<?php echo wpbc_convert_to_live('stamps_status'); ?>

	<?php }
	?>
	<?php do_action('wpbc_settings_before_form'); ?>
	<form action="<?= admin_url('admin.php?page=wp-blockchain-settings') ?>" method="POST" class="wpbc-settings-form">
		<?php do_action('wpbc_settings_before'); ?>

		<h3 class="wpbc-settings-h3"><?= __('Global settings', 'wpbc') ?></h3>

		<?php do_action('wpbc_settings_before_fields'); ?>

		<fieldset>
			<?php
				$enabled_blockchains = get_option('wpbc_blockchains', null);
			?>

			<div class="wpbc-settings-field" style="margin-bottom: 20px">
				<label><?= __('Stamp in blockchains', 'wpbc') ?>:</label>
				<div class="wpbc-settings-field-inner wpbc-blockchain-cbs">
					<?php
					foreach (wpbc_get_blockchains() as $k => $conf){
						?>
						<label class="wpbc-settings-cb"><input type="checkbox" name="wpbc_blockchains_<?= $k ?>" id="wpbc_blockchains_<?= $k ?>" data-wpbc-bc-id="<?= $k ?>" <?php if ($enabled_blockchains === null || in_array($k, $enabled_blockchains)) echo ' checked'; ?> /> <?= $conf['name'] ?> (<?= strtoupper($k) ?>)</label>
					<?php }

					$enabled_blockchains = array();
					?>
				</div>
				<script>
					jQuery(document).ready(function(){
						jQuery('.wpbc-blockchain-cbs input').on('change', function(){
							var enabled = jQuery('');
							jQuery('.wpbc-blockchain-cbs input').each(function(){
								if (jQuery(this).is(':checked'))
									enabled = enabled.add(jQuery('.wpbc-blockchain-settings-bc-'+jQuery(this).data('wpbc-bc-id')));
							});

							var blocks = jQuery('.wpbc-blockchain-settings');
							blocks.not(enabled).hide();
							enabled.show();
						});
					});
				</script>
			</div>

			<?php do_action('wpbc_settings_after_fields_blockchains'); ?>

			<?php
				$is_only_admins = get_option('wpbc_visible_only_admins', true);
			?>
			<div class="wpbc-settings-field" style="margin-top: 10px">
				<label for="wpbc_visible_only_admins"><?= __('Hidden mode', 'wpbc') ?>:</label>
				<div class="wpbc-settings-field-inner">
					<div><label class="wpbc-settings-cb"><input type="checkbox" name="wpbc_visible_only_admins" id="wpbc_visible_only_admins"<?php if ($is_only_admins) echo ' checked'; ?> /> <?= __('Only show stamps to admins', 'wpbc').' <span class="wpbc-settings-inline-desc">('.__('this will hide any stamp to non-admins, whatever the following parameters are set to', 'wpbc').')</span>' ?></label></div>
				</div>
			</div>

			<?php do_action('wpbc_settings_after_fields_only_admins'); ?>

			<?php $config = get_option('wpbc_post_types', array()); ?>
			<div class="wpbc-settings-field" style="margin-top: 30px">
				<label><?= __('Stamppable items', 'wpbc') ?>:</label>
				<div class="wpbc-settings-field-inner">
					<table border="0" class="wpbc-stamps-table">
						<tr><th></th><th><?= __('Item type', 'wpbc') ?></th><th><?= __('Published <br/>items', 'wpbc') ?></th><th><?= __('Stamping policy', 'wpbc') ?></th><th><?= __('Show in backend in', 'wpbc') ?></th><th><?= __('Auto append <br/>on frontend', 'wpbc') ?></th><th><?= __('Stamp <br>visibility', 'wpbc') ?></th></tr>
						<?php
							foreach ($post_types as $p){
								$cconfig = isset($config[$p->name]) ? $config[$p->name] : array(
									'mode' => '',
									'append' => '',
									'public' => true,
									'backend' => '',
									'visibility' => ''
								);
								?>
								<tr class="wpbc-setting-type wpbc-setting-type-state-<?= ($cconfig['mode'] !== '' ? 'enabled' : 'disabled') ?>">
									<td class="wpbc-setting-type-icon"><i title="<?= esc_attr($p->public ? __('Public', 'wpbc') : __('Private', 'wpbc')) ?>" class="fa fa-<?= ($p->public ? 'globe' : 'lock') ?>"></i></td>
									<td class="wpbc-setting-type-label"><a rel="nofollow" href="<?= (property_exists($p, 'admin_url') ? $p->admin_url : admin_url('edit.php?post_type='.$p->name)) ?>" target="_blank"><?= $p->label ?></a></td>
									<td class="wpbc-setting-type-count"><?php
										$count = apply_filters('wpbc_settings_type_count', null, $p);
										if ($count === null)
											$count = $wpdb->get_var(
												$p->name == 'user'
												? 'SELECT COUNT(ID) FROM '.$wpdb->users
												: $wpdb->prepare('SELECT COUNT(ID) FROM '.$wpdb->posts.' WHERE post_type = %s AND post_status IN ( "publish", "private" )', $p->name));
										echo $count ? $count : 0;
									?></td>
									<td class="wpbc-td-nopad wpbc-setting-type-mode">
										<select name="wpbc_stamp_mode_<?= $p->name ?>">
											<?php
											$automodes = apply_filters('wpbc_settings_stamp_modes', array(
												'' => __('Do not stamp', 'wpbc'),
												'manual' => __('Allow stamping manually', 'wpbc'),

											), $p);
											if ($cconfig['mode'] != '' && !isset($automodes[$cconfig['mode']]))
												$cconfig['mode'] = 'manual';

											foreach ($automodes as $k => $v){
												?>
												<option value="<?= $k ?>"<?php if ($cconfig['mode'] == $k) echo ' selected'; ?>><?= $v ?></option>
												<?php
											}
											?>
										</select>
									</td>
									<td class="wpbc-td-nopad wpbc-setting-type-backend">
										<select name="wpbc_stamp_backend_<?= $p->name ?>">
											<?php foreach (apply_filters('wpbc_settings_backend_options', array(
												'all' => __('Admin bar, listing and edition pages', 'wpbc'),
												'adminbar' => __('Only in admin bar', 'wpbc'),
												'adminbar_edit' => __('Admin bar and edition pages', 'wpbc'),
												'adminbar_listing' => __('Admin bar and listing page', 'wpbc'),
												'listing' => __('Only in listing', 'wpbc'),
												'listing_edit' => __('Listing and edition pages', 'wpbc'),
												'edit' => __('Only in edition pages', 'wpbc'),
												'none' => __('Do not show anywhere', 'wpbc'),
											), $p) as $k => $v){ ?>
											<option value="<?= $k ?>"<?php if ($cconfig['backend'] == $k) echo ' selected'; ?>><?= $v ?></option>
											<?php
										}
										?>
										</select>
									</td>
									<?php if (!apply_filters('wpbc_settings_stamp_modes_disabled', property_exists($p, 'append_disabled') && $p->append_disabled, $p)){ ?>
										<td class="wpbc-td-nopad wpbc-setting-type-append">
											<select name="wpbc_append_stamp_<?= $p->name ?>">
												<?php foreach (apply_filters('wpbc_settings_stamp_append_options', array(
													'' => __('Do nothing', 'wpbc'),
													'singular' => __('In singular pages', 'wpbc'),
													'loop' => __('When in a loop', 'wpbc'),
													'both' => __('In singular pages and in loops', 'wpbc')
												), $p) as $k => $v){ ?>
												<option value="<?= $k ?>"<?php if ($cconfig['append'] == $k) echo ' selected'; ?>><?= $v ?></option>
												<?php
											}
											?>
											</select>
										</td>
										<?php
									} else { ?>
										<td class="wpbc-setting-type-append">
											<?= __('Not available', 'wpbc') ?>
										</td>
										<?php
										}
									?>
									<td class="wpbc-td-nopad wpbc-setting-type-visibility">
										<select name="wpbc_stamp_visibility_<?= $p->name ?>">
											<?php foreach (apply_filters('wpbc_settings_stamp_visibility_options', array(
												'' => __('Only admins', 'wpbc'),
												'logged' => __('Logged in users', 'wpbc'),
												'authors' => __('Item\'s authors and admins only', 'wpbc'),
												'public' => __('Anyone if the item is public', 'wpbc'),
											), $p) as $k => $v){ ?>
											<option value="<?= $k ?>"<?php if ($cconfig['visibility'] == $k) echo ' selected'; ?>><?= $v ?></option>
											<?php
										}
										?>
										</select>
									</td>
								</tr>
								<?php
							}
						?>
					</table>

					<div class="wpbc-settings-tip"><i class="fa fa-info-circle"></i> <?= __('Appending stamps is realized throught the <code>the_content</code> filter. To display a stamp from a template, use the following PHP code: <code>do_action("wpbc");</code>. To display a stamp from a filtered content (like a post or a page), use the shortcode <code>[wpbc]</code>.', 'wpbc') ?></div>
				</div>
			</div>

			<?php do_action('wpbc_settings_after_fields_stampable_content'); ?>

		</fieldset>

		<?php do_action('wpbc_settings_after_fields_settings'); ?>

		<h3 class="wpbc-settings-h3"><?= __('Stamp service', 'wpbc') ?></h3>

		<div class="wpbc-settings-instructions"><?= __('Please select a stamping service and fill the corresponding API credentials (required):', 'wpbc') ?></div>

		<fieldset class="wpbc-settings-bc-credentials">
			<?php
				$val = get_option('wpbc_provider');
				$current_public_args = null;
			?>
			<div class="wpbc-settings-field">
				<label for="wpbc_provider"><?= __('Stamp service', 'wpbc') ?>: </label>
				<div class="wpbc-settings-field-inner">
					<select name="wpbc_provider" id="wpbc_provider">
						<option value=""><?= __('Please select a stamp service..', 'wpbc') ?></option>
						<?php
							if ($dir = opendir(WPBC_PATH.'/inc/api/stamp_services')){
								$i = 0;
								while (false !== ($filename = readdir($dir))){
									$provider_id = preg_replace('#(\.php)$#i', '', $filename);
									if ($provider_id == $filename)
										continue;

									$api = wpbc_get_api($provider_id);
									if (!$api)
										continue;

									$api_config = $api->get_api_config();
									$public_args = $api->get_public_args();

									if (!$i && empty($val))
										$val = $provider_id;

									if ($val == $provider_id)
										$current_public_args = $public_args + array(
											'api_config' => $api_config
										);

									?><option value="<?= $provider_id ?>" data-wpbc-provider-arg="<?= esc_attr(json_encode($public_args)) ?>"<?php if ($val == $provider_id) echo ' selected'; ?>><?= $api_config['name'] ?></option><?php

									$i++;
								}
								closedir($dir);
							}
						?>
					</select>
				</div>
			</div>
			<?php $val = get_option('wpbc_provider_username'); ?>
			<div class="wpbc-settings-field-credential wpbc-settings-field-credential-username wpbc-settings-field<?php if (!$current_public_args || !isset($current_public_args['fields']['username'])) echo ' wpbc-settings-field-hidden'; ?>">
				<label for="wpbc_provider_username"><?= ($current_public_args && isset($current_public_args['fields'], $current_public_args['fields']['username']) ? $current_public_args['fields']['username'] : __('Service client ID', 'wpbc')) ?>: </label>
				<div class="wpbc-settings-field-inner">
					<input type="text" name="wpbc_provider_username" id="wpbc_provider_username" value="<?php if ($val) echo $val; ?>" />
				</div>
			</div>
			<?php $val = get_option('wpbc_provider_password'); ?>
			<div class="wpbc-settings-field-credential wpbc-settings-field-credential-password wpbc-settings-field<?php if (!$current_public_args || !isset($current_public_args['fields']['password'])) echo ' wpbc-settings-field-hidden'; ?>">
				<label for="wpbc_provider_password"><?= ($current_public_args && isset($current_public_args['fields'], $current_public_args['fields']['password']) ? $current_public_args['fields']['password'] : __('Service secret', 'wpbc')) ?>: </label>
				<div class="wpbc-settings-field-inner">
					<input type="text" name="wpbc_provider_password" id="wpbc_provider_password" value="<?php if ($val) echo $val; ?>" />
				</div>
			</div>
			<div class="wpbc-settings-field wpbc-settings-tip-wrap<?php if (!$current_public_args) echo ' wpbc-settings-field-hidden'; ?>">
				<div class="wpbc-settings-field-inner">
					<div class="wpbc-settings-tip"><i class="fa fa-info-circle"></i> <span class="wpbc-settings-tip-inner"><?php if ($current_public_args && isset($current_public_args['info'])) echo $current_public_args['info']; ?></span></div>
				</div>
			</div>
		</fieldset>

		<?php do_action('wpbc_settings_after_fields_stamping'); ?>

		<?php foreach (wpbc_get_blockchains(false) as $bc_id => $config){

			$val = get_option('wpbc_provider_'.$bc_id);
			$current_public_args = null;

			$explorers_count = $explorers_avail_count = 0;
			$explorers_str = array();

			ob_start();
			?>
				<h3 class="wpbc-settings-h3"><?= sprintf(__('%s block explorer', 'wpbc'), $config['name']) ?></h3>

				<div class="wpbc-settings-instructions"><?= __('Please select a block explorer and fill the corresponding API credentials (required):', 'wpbc') ?></div>
				<?php
				if (!empty($_GET['debug']))
					echo 'Current selection: '.$val.'<br>';
				?>

				<fieldset class="wpbc-settings-bc-credentials">
					<div class="wpbc-settings-field">
						<label for="wpbc_provider_<?= $bc_id ?>"><?= __('Service', 'wpbc') ?>: </label>
						<div class="wpbc-settings-field-inner">
							<select name="wpbc_provider_<?= $bc_id ?>" id="wpbc_provider_<?= $bc_id ?>">
								<option value=""><?= sprintf(__('Please select a block explorer for %s..', 'wpbc'), $config['name']) ?></option>
								<?php
								$printed = false;
								foreach (wpbc_get_api_folders() as $folder){
									if ($dir = opendir($folder.'/block_explorers')){
										$i = 0;
										while (false !== ($filename = readdir($dir))){
											$provider_id = preg_replace('#(\.php)$#i', '', $filename);
											if ($provider_id == $filename)
												continue;

											$api = wpbc_get_api($provider_id, 'block_explorers');
											if (!$api || !method_exists($api, 'check_transaction_'.$bc_id))
												continue;

											$api_config = $api->get_api_config();
											$public_args = $api->get_public_args();

											if (!$i && empty($val))
												$val = $provider_id;
											if ($val == $provider_id)
												$current_public_args = $public_args + array(
													'api_config' => $api_config
												);

											?><option value="<?= $provider_id ?>" data-wpbc-provider-arg="<?= esc_attr(json_encode($public_args)) ?>"<?php if ($val == $provider_id) echo ' selected'; ?>><?= $api_config['name'] ?></option><?php

											$i++;

											if ($current_public_args && (
												isset($current_public_args['fields']['username'])
												|| isset($current_public_args['fields']['password'])
											))
												$explorers_count++;

											$explorers_avail_count++;
											$explorers_str[] = '<a href="'.$api_config['url'].'" target="_blank">'.$api_config['name'].'</a>';
										}
										closedir($dir);
									}
								}
								?>
							</select>
						</div>
					</div>
					<?php $val = get_option('wpbc_provider_'.$bc_id.'_username'); ?>
					<div class="wpbc-settings-field-credential wpbc-settings-field-credential-username wpbc-settings-field<?php if (!$current_public_args || !isset($current_public_args['fields']['username'])) echo ' wpbc-settings-field-hidden'; ?>">
						<label for="wpbc_provider_<?= $bc_id ?>_username"><?= ($current_public_args && isset($current_public_args['fields'], $current_public_args['fields']['username']) ? $current_public_args['fields']['username'] : __('Service client ID', 'wpbc')) ?>: </label>
						<div class="wpbc-settings-field-inner">
							<input type="text" name="wpbc_provider_<?= $bc_id ?>_username" id="wpbc_provider_<?= $bc_id ?>_username" value="<?php if ($val) echo $val; ?>" />
						</div>
					</div>
					<?php $val = get_option('wpbc_provider_'.$bc_id.'_password'); ?>
					<div class="wpbc-settings-field-credential wpbc-settings-field-credential-password wpbc-settings-field<?php if (!$current_public_args || !isset($current_public_args['fields']['password'])) echo ' wpbc-settings-field-hidden'; ?>">
						<label for="wpbc_provider_<?= $bc_id ?>_password"><?= ($current_public_args && isset($current_public_args['fields'], $current_public_args['fields']['password']) ? $current_public_args['fields']['password'] : __('Service secret', 'wpbc')) ?>: </label>
						<div class="wpbc-settings-field-inner">
							<input type="text" name="wpbc_provider_<?= $bc_id ?>_password" id="wpbc_provider_<?= $bc_id ?>_password" value="<?php if ($val) echo $val; ?>" />
						</div>
					</div>
					<div class="wpbc-settings-field wpbc-settings-tip-wrap<?php if (!$current_public_args || !isset($current_public_args['info'])) echo ' wpbc-settings-field-hidden'; ?>">
						<div class="wpbc-settings-field-inner">
							<div class="wpbc-settings-tip"><i class="fa fa-info-circle"></i> <span class="wpbc-settings-tip-inner"><?php if ($current_public_args && isset($current_public_args['info'])) echo $current_public_args['info']; ?></span></div>
						</div>
					</div>
				</fieldset>
			<?php

			$output = ob_get_clean();

			$hidden = !is_wpbc_blockchain_enabled($bc_id) || !$explorers_count;
			if ($hidden){
				?>
				<h3 class="wpbc-settings-h3"><?= sprintf(__('%s block explorer', 'wpbc'), $config['name']) ?></h3>
				<div class="wpbc-settings-instructions"><?php

					echo strtr($explorers_avail_count < 2
						? __('[connector] will be used as a block explorer for [blockchain] (no configuration needed).', 'wpbc')
						: __('[connector] may be used as block explorers for [blockchain] (no configuration needed).', 'wpbc'), array(
						'[connector]' => wpbc_plural($explorers_str),
						'[blockchain]' => $config['name']
					));

					?>
				</div>
				<?php
			}
			?>
			<div class="wpbc-blockchain-settings wpbc-blockchain-settings-bc-<?= $bc_id ?>"<?php
				if ($hidden && empty($_GET['debug']))
					echo ' style="display: none"';
			?>>
				<?php echo $output; ?>
			</div>
			<?php
		}
		?>
		<?php do_action('wpbc_settings_after_fields_credentials'); ?>

		<h3 class="wpbc-settings-h3"><?= __('Styling', 'wpbc') ?></h3>

		<fieldset>

			<?php
				$values = get_option('wpbc_visible_states');
			?>
			<div class="wpbc-settings-field" style="margin-top: 10px; margin-bottom: 30px">
				<label><?= __('Show these states on frontend stamps', 'wpbc') ?>: <br/>(<?= __('whenever a stamp may be displayed', 'wpbc') ?>)</label>
				<div class="wpbc-settings-field-inner">
					<?php
					if (empty($values)) $values = array();
					foreach (wpbc_get_status_labels('states') as $k => $v){ ?>
						<div><label class="wpbc-settings-cb"><input type="checkbox" name="wpbc_visible_states_<?= $k ?>" id="wpbc_visible_states_<?= $k ?>"<?php if (empty($values) || in_array($k, $values) || $k == 'stamped') echo ' checked'; if ($k == 'stamped') echo ' disabled="disabled"'; ?> /> <?= $v ?></label></div>
					<?php } ?>
				</div>
			</div>

			<?php do_action('wpbc_settings_fields_theming'); ?>

		</fieldset>

		<?php do_action('wpbc_settings_after_fields_theming'); ?>

		<fieldset>
			<div class="wpbc-settings-field">
				<label for="wpbc_intro"><?= __('Certificate introduction', 'wpbc') ?>: </label>
				<div class="wpbc-settings-field-inner">
					<div class="wpbc-settings-wpeditor-wrap">
						<?php wp_editor(get_option('wpbc_intro'), 'wpbc_intro'); ?>
					</div>
				</div>
			</div>
		</fieldset>

		<?php do_action('wpbc_settings_after_fields_certificate'); ?>

		<h3 class="wpbc-settings-h3"><?= __('Donation settings', 'wpbc') ?></h3>

		<fieldset>
			<div class="wpbc-settings-field">
				<label for="wpbc_donate_btc"><?= __('Bitcoin wallet address (public key)', 'wpbc') ?>: </label>
				<div class="wpbc-settings-field-inner">
					<div><input type="text" name="wpbc_donate_btc" id="wpbc_donate_btc" value="<?= esc_attr(get_option('wpbc_donate_btc', '')) ?>" /></div>
					<div class="wpbc-settings-inline-desc"><?= sprintf(__('Entering a Bitcoin wallet address here will add the <code>bitcoin</code> and <code>microtip</code> metatags to every pages, so that you can accept Bitcoin donations from services like %s and %s (no account needed). You can create a new Bitcoin wallet on platforms like %s or %s. You can print a donate button with the %s shortcode or %s PHP code.', 'wpbc'), '<a href="https://bitprops.com/faq" target="_blank">BitProps.com</a>', '<a href="https://protip.is/" target="_blank">ProTip.is</a>', '<a href="https://coinbase.com" target="_blank">Coinbase</a>', '<a href="https://blockchain.info" target="_blank">Blockchain.info</a>', '<code>[wpbc_donate]</code>', '<code>do_action(\'wpbc_donate\');</code>') ?></div>
				</div>
			</div>
		</fieldset>

		<?php do_action('wpbc_settings_after_fields_donate'); ?>

		<h3 class="wpbc-settings-h3"><?= __('Miscellaneous', 'wpbc') ?></h3>

		<fieldset>

			<div class="wpbc-settings-field">
				<label for="wpbc_allow_poorman"><?= __('CRON settings', 'wpbc') ?>:</label>
				<div class="wpbc-settings-field-inner">
					<div><label class="wpbc-settings-cb"><input type="checkbox" name="wpbc_allow_poorman" id="wpbc_allow_poorman"<?php if (get_option('wpbc_allow_poorman', true)) echo ' checked'; ?> /> <?= __('Allow Poorman\'s CRON', 'wpbc').' <span class="wpbc-settings-inline-desc">('.sprintf(__('only executed on ajax and if CRON has not been trigger for %s', 'wpbc'), wpbc_human_time_diff(time() - strtotime('-'.WPBC_CRON_DETECT_DELAY), false)).')</span>' ?></label></div>
				</div>
			</div>

			<div class="wpbc-settings-field">
				<label for="wpbc_block_cron" class="wpbc-label-empty">&nbsp;</label>
				<div class="wpbc-settings-field-inner">
					<div><label class="wpbc-settings-cb"><input type="checkbox" name="wpbc_block_cron" id="wpbc_block_cron"<?php if (get_option('wpbc_block_cron', false)) echo ' checked'; ?> /> <?= __('Block CRON', 'wpbc').' <span class="wpbc-settings-inline-desc">('.sprintf(__('useful to %s', 'wpbc'), '<a href="'.site_url('/?wpbc_cron='.urlencode(wpbc_get_cron_key()).'&manual=1&frequency=5').'" target="_blank" rel="nofollow">'.__('debug CRON', 'wpbc').'</a>').')</span>' ?></label></div>
				</div>
			</div>

			<?php $has_fontawesome = get_option('wpbc_has_fontawesome', false); ?>
			<div class="wpbc-settings-field">
				<label for="wpbc_has_fontawesome"><?= __('FontAwesome icons', 'wpbc') ?>:</label>
				<div class="wpbc-settings-field-inner">
					<div><label class="wpbc-settings-cb"><input type="checkbox" name="wpbc_has_fontawesome" id="wpbc_has_fontawesome"<?php if ($has_fontawesome) echo ' checked'; ?> /> <?= __('Do not add FontAwesome, it is already included in all pages', 'wpbc') ?></label></div>
				</div>
			</div>

			<div class="wpbc-settings-field">
				<label><?= __('Admin features', 'wpbc') ?>:</label>
				<div class="wpbc-settings-field-inner">
					<div><span class="wpbc-settings-cb"><input type="checkbox" class="wpbc-settings-reset-button" name="wpbc_reset_stamps" id="wpbc_reset_stamps" data-wpbc-confirm="<?= esc_attr(__('Are you sure you want to DELETE ALL STAMPS? This CANNOT BE REVERTED!', 'wpbc')) ?>" /> <?= __('Delete all stamps', 'wpbc') ?></span></div>
				</div>
			</div>

			<div class="wpbc-settings-field">
				<label class="wpbc-label-empty">&nbsp;</label>
				<div class="wpbc-settings-field-inner">
					<div><span class="wpbc-settings-cb"><input type="checkbox" class="wpbc-settings-reset-button" name="wpbc_reset_settings" id="wpbc_reset_settings" data-wpbc-confirm="<?= esc_attr(__('Are you sure you want to RESET PLUGIN SETTINGS? This CANNOT BE REVERTED!', 'wpbc')) ?>" /> <?= __('Reset plugin settings', 'wpbc') ?></span></div>
				</div>
			</div>

		</fieldset>

		<?php do_action('wpbc_settings_after_fields'); ?>

		<div class="wpbc-settings-field">
			<input type="hidden" name="wpbc_nonce" value="<?= esc_attr(wp_create_nonce('wpbc_settings')) ?>" />
			<input type="submit" id="submit" class="button button-primary" name="wpbc_submit" id="wpbc_submit" value="<?= esc_attr(__('Save settings', 'wpbc')) ?>" />
		</div>

		<?php do_action('wpbc_settings_after'); ?>
	</form>
	<?php do_action('wpbc_settings_after_form'); ?>
	<?php
}

add_action( 'profile_update', 'wpbc_user_box_fields_save', 10, 2 );
function wpbc_user_box_fields_save($user_id, $old_user_data){
	if (!current_user_can('edit_users')
		|| !wpbc_is_post_type_stampable('user')

	)
			return;

	$create = wpbc_must_autostamp(null, null, 'user');
	foreach (wpbc_get_user_stamps($user_id) as $c)
		if (wpbc_unsynced_by_base($c['base']))
			wpbc_queue_code($c['base'].'-last');

}

add_action( 'save_post', 'wpbc_box_fields_save' );
function wpbc_box_fields_save($post_id){
	if (!current_user_can('edit_post', $post_id)
		|| wp_is_post_autosave($post_id)
		|| wp_is_post_revision($post_id)
		|| !wpbc_is_post_stampable($post_id)
	)
		return;

	if ($autostamp = wpbc_must_autostamp($post_id))
		foreach (wpbc_get_stamps($post_id) as $c)
			if (wpbc_unsynced_by_base($c['base']))
				wpbc_queue_code($c['base'].'-last');

	do_action('wpbc_save_post', $post_id);
}

function wpbc_is_post_stampable($post_id){
	return in_array(get_post_status($post_id), array('publish', 'private')) && wpbc_is_post_type_stampable(get_post_type($post_id));
}

add_action( 'add_meta_boxes', 'wpbc_register_meta_boxes' );
function wpbc_register_meta_boxes(){
	if (!wpbc_show_metabox())
		return;

	add_meta_box('wpbc_stamp_child', __('Blockchain stamping', 'wpbc'), 'wpbc_box_stamp_child', null, 'side', 'default');
}

function wpbc_show_metabox(){
	global $pagenow, $post;
	return $post && $pagenow != 'post-new.php' && wpbc_is_post_stampable($post->ID) && wpbc_can_stamp_show_as(get_post_type($post->ID), 'edit');
}

add_action( 'show_user_profile', 'wpbc_show_extra_profile_fields' );
add_action( 'edit_user_profile', 'wpbc_show_extra_profile_fields' );
function wpbc_show_extra_profile_fields($user){
	if (!wpbc_is_post_type_stampable('user')
		|| !wpbc_can_stamp_show_as('user', 'edit', $user->ID))
		return;

	?><h3><?= __('Blockchain stamping', 'wpbc') ?></h3>
	<table class="form-table">
		<tr>
			<td>
				<?php do_action('wpbc'); ?>
			</td>
		</tr>
	</table>
	<?php
}

function wpbc_get_stamps($for_post, $display_mode = false, $extra_argument = array()){
	$default = array(
		'label' => __('Title and content', 'wpbc'),
		'last' => null,
		'base' => $for_post.'-default'
	);

	$stamps = array(
		'default' => $default
	);

	$stamps = apply_filters('wpbc_stamps', $stamps, $for_post, $display_mode, $extra_argument);

	return $stamps;
}

function wpbc_get_user_stamps($for_user, $display_mode = false, $extra_argument = array()){
	return array(
		'profile' => array(
			'label' => __('Name and biography', 'wpbc'),
			'last' => null,
			'base' => $for_user.'-profile'
		)
	);
}

add_filter('the_content', 'wpbc_autoshow_stamp', 0, 1);
function wpbc_autoshow_stamp($content){
	static $config = null;
	global $post;

	if (empty($post) || !wpbc_can_stamp_show_as(get_post_type($post->ID), 'frontend', $post->ID))
		return $content;

	if ($config === null)
		$config = wpbc_get_post_types();

	$post_type = get_post_type($post->ID);
	if (isset($config[$post_type]) && in_array($config[$post_type]['append'], array(apply_filters('wpbc_is_single', is_single() || is_attachment()) ? 'singular' : 'loop', 'both')))
		$content = $content.wpbc_shortcode_stamp();

	return $content;
}

add_filter('manage_posts_columns', 'wpbc_stamp_post_columns', 10);
add_filter('manage_pages_columns', 'wpbc_stamp_page_columns', 10);
add_filter('manage_users_columns', 'wpbc_stamp_user_columns', 10);
add_action('manage_posts_custom_column', 'wpbc_stamp_columns_print', 10, 2);
add_action('manage_pages_custom_column', 'wpbc_stamp_columns_print', 10, 2);
add_filter('manage_users_custom_column', 'wpbc_stamp_user_columns_print', 10, 3);

function wpbc_stamp_post_columns($defaults) {
	if (wpbc_user_can('see_stamps') && ($api = wpbc_get_api()) && wpbc_is_post_type_stampable(get_post_type()) && wpbc_can_stamp_show_as(get_post_type(), 'listing'))
		$defaults['wpbcs'] = 'Stamps';
    return $defaults;
}

function wpbc_stamp_page_columns($defaults) {
	if (wpbc_user_can('see_stamps') && ($api = wpbc_get_api()) && wpbc_is_post_type_stampable('page') && wpbc_can_stamp_show_as('page', 'listing'))
		$defaults['wpbcs'] = __('Stamps', 'wpbc');
    return $defaults;
}

function wpbc_stamp_user_columns($defaults) {
	if (wpbc_user_can('see_stamps') && ($api = wpbc_get_api()) && wpbc_is_post_type_stampable('user') && wpbc_can_stamp_show_as('user', 'listing'))
		$defaults['wpbcs'] = __('Stamps', 'wpbc');
    return $defaults;
}

function wpbc_stamp_columns_print($column_name, $post_ID) {
    if (wpbc_is_post_stampable($post_ID) && $column_name == 'wpbcs' && ($stamps = wpbc_get_stamps($post_ID)) && wpbc_can_stamp_show_as(get_post_type($post_ID), 'listing', $post_ID))
		echo wpbc_print_stamps($stamps, count($stamps) > 1, 'wpbc-stamping-status-col');
}

function wpbc_stamp_user_columns_print($output, $column_name, $user_ID){
	if (wpbc_is_post_type_stampable('user') && $column_name == 'wpbcs' && ($stamps = wpbc_get_user_stamps($user_ID)) && wpbc_can_stamp_show_as('user', 'listing', $user_ID))
		return wpbc_print_stamps($stamps, count($stamps) > 1, 'wpbc-stamping-status-col');
	return $output;
}

add_action('admin_bar_menu', 'wpbc_topbar', 999);
function wpbc_topbar( $wp_admin_bar ){
	global $wpbc_api_ret, $wpbc_tobar;

	if (!wpbc_user_can('see_stamps'))
		return;
	if (!empty($wpbc_api_ret))
		$stamp_code = $wpbc_api_ret['stamp_code'];
	else if (!($stamp_code = wpbc_get_current_stamp_code()))
		return;
	else if (!wpbc_is_stampable_code($stamp_code))
		return;

	$parts = explode('-', $stamp_code);
	if (!wpbc_can_stamp_show_as($parts[1] == 'profile' ? 'user' : get_post_type($parts[0]), 'adminbar'))
		return;

	$wpbc_tobar = true;
	if (!empty($wpbc_api_ret)){

		$singular_name = wpbc_get_type_label_from_ret($wpbc_api_ret['stamp_code']);

		if ($wpbc_api_ret['stamp_type'] == 'profile'
			? current_user_can('edit_user', $wpbc_api_ret['ret']['context_id'])
			: current_user_can('edit_post', $wpbc_api_ret['ret']['context_id']))

			$wp_admin_bar->add_node(array(
				'id'    => 'wpbc_topbar_stamp_origin_edit',
				'title' => '<i class="fa fa-pencil"></i> '.sprintf(__('Edit %s', 'wpbc'), $singular_name),
				'href'  => $wpbc_api_ret['stamp_type'] == 'profile'
					? get_edit_user_link($wpbc_api_ret['ret']['context_id'])
					: get_edit_post_link($wpbc_api_ret['ret']['context_id']),
				'meta'  => array( 'class' => 'wpbc-topbar-stamp' )
			));
	}
	$wp_admin_bar->add_node(array(
		'id'    => 'wpbc_topbar_stamp',
		'title' => wpbc_convert_to_live('stamp_button_light-'.$stamp_code),
		'href'  => null,
		'meta'  => array( 'class' => 'wpbc-topbar-stamp', 'target' => '_blank' )
	));

	if (!empty($wpbc_api_ret)){
		$wp_admin_bar->add_node(array(
			'id'    => 'wpbc_topbar_stamp_origin',
			'title' => sprintf(__('View %s', 'wpbc'), $singular_name).' <i class="fa fa-angle-right"></i>',
			'href'  => $wpbc_api_ret['ret']['context_url'],
			'meta'  => array( 'class' => 'wpbc-topbar-stamp')
		));
	}
	$wpbc_tobar = false;
}

function wpbc_is_last_from_api_ret($ret, $stamp_base){
	return wpbc_hash(wpbc_get_stamp_content_by_code($stamp_base), $ret['hash_method']) == $ret['hash'];
}

add_filter('pre_get_document_title', 'wpbc_document_title', 99999);
add_filter('wp_title', 'wpbc_document_title', 99999);
function wpbc_document_title($title){
	global $wpbc_api_ret;
	if (!empty($wpbc_api_ret)){
		$title = __('Blockchain Certificate', 'wpbc');
		if ($context = wpbc_get_context()){
			if (!empty($context['post_id']))
				$title .= ': '.get_the_title($context['post_id']);
			else if (!empty($context['user_id']))
				$title .= ': '.wpbc_get_name($context['user_id']);
		}
	}
	return $title;
}

if (!defined('WPBC_EMBED') || !WPBC_EMBED)
	add_filter('plugin_action_links_'.basename(WPBC_PATH).'/'.basename(WPBC_PATH).'.php', 'wpbc_plugin_add_settings_link' );

function wpbc_plugin_add_settings_link($links){
    array_unshift( $links, '<a rel="nofollow" href="https://www.goodrebels.com/about/" target="_blank">Good Rebels</a>' );

    if (!apply_filters('wpbc_has_addon', false))
		array_unshift( $links, '<a rel="nofollow" href="https://wp-blockchain.com" target="_blank">' . __('Upgrade to Premium') . '</a>' );

    array_unshift( $links, '<a rel="nofollow" href="'.wpbc_settings_url().'">' . __('Settings') . '</a>' );
  	return $links;
}

function wpbc_get_type_label_from_ret($stamp_code){
	$parts = explode('-', $stamp_code);
	$post_id = intval(array_shift($parts));
	$stamp_type = array_shift($parts);
	if ($post_id)
		return $stamp_type == 'profile' ? __('User', 'wpbc') : get_post_type_object(get_post_type($post_id))->labels->singular_name;
	else
		return __('Unknown type', 'wpbc');
}

function wpbc_get_colors(){
	return apply_filters('wpbc_colors', array(
		'primary' => array(
			'std' => '#4FFF21',
		),
		'secondary' => array(
			'std' => '#FFA500',
		),
	));
}

function wpbc_get_allowed_tags(){
	return array(
		'a' => array(
			'href' => array(),
			'title' => array(),
			'target' => array()
		),
		'br' => array(),
		'div' => array(),
		'span' => array(),
		'p' => array(),
		'em' => array(),
		'b' => array(),
		'u' => array(),
		'i' => array(),
		'strong' => array(),
	);
}

add_action('admin_head', 'wpbc_admin_tab_modify');
function wpbc_admin_tab_modify(){
	?>
	<script>
		jQuery(document).ready(function(){
			jQuery('.menu-top.toplevel_page_wp-blockchain .wp-submenu-wrap a.wp-first-item').html('Blockchain Stamps');
		});
	</script>
	<?php
}
