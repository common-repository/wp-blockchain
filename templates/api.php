<?php
/*
Template file for the stamp certificate pages.

License: GPLv2 or later

Copyright (c) 2018, Good Rebels Inc.

WP Blockchain is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <https://www.gnu.org/licenses/gpl-2.0.html>.

Please feel free to contact us at wp-blockchain@goodrebels.com.
*/



if (!defined('ABSPATH'))
	exit();

?>
<div class="wpbc-api-ret-status"><?= wpbc_convert_to_live('stamp_status_icon-'.$stamp_code.'-'.($last_hash ? 1 : '0')) ?> <?php

	echo apply_filters('wpbc_certificate_title', __('Blockchain Certificate', 'wpbc'), $stamp_code, $args);

	do_action('wpbc_certificate_after_title', $stamp_code, $args);

	echo wpbc_live_ind();

?></div>
<?php

$intro = apply_filters('wpbc_api_intro', false, $args);
if (!$intro)
	$intro = get_option('wpbc_intro');
if (!empty($intro)){
	?>
	<div class="wpbc-api-ret-status-intro"><?= nl2br($intro) ?></div>
	<?php
}
?>
<div class="wpbc-api-ret-status-ind"><i class="fa fa-certificate"></i> <?= __('Stamp summary', 'wpbc') ?>:</div>

<div class="wpbc-api-ret-vars wpbc-api-ret-vars-basic"><table cellspacing="0" cellpadding="0">
	<colgroup>
		<col class="wpbc-api-ret-col-name" />
		<col class="wpbc-api-ret-col-extend" />
	</colgroup>
	<tr><td class="wpbc-api-ret-name"><?php
		echo apply_filters('wpbc_api_title_label', __('Stamp title', 'wpbc'), $args);

		?>: </td><td class="wpbc-cut-ellipsis"><?= $ret['context_name'] ?></td></tr>
	<tr><td class="wpbc-api-ret-name"><?= __('Stamp type', 'wpbc') ?>: </td><td class="wpbc-cut-ellipsis"><?php
		echo wpbc_convert_to_live('stamp_type-'.$stamp_code.'-'.($last_hash ? 1 : '0'));
		do_action('wpbc_api_other_stamps', $args, $api);
	?></td></tr>
	<?php

		$has_public_url = $ret['stamp_type'] == 'profile' || (($pt_config = get_post_type_object(get_post_type($ret['context_id']))) && ($pt_config->publicly_queryable || $pt_config->public));

		if ($has_public_url || current_user_can('edit_page', $context_id)){
			?>
			<tr><td class="wpbc-api-ret-name"><?= __('Context URL', 'wpbc') ?>: </td><td class="wpbc-cut-ellipsis"><?php

				if ($has_public_url)
					echo '<a target="_blank" href="'.$ret['context_url'].'" target="_blank">'.$ret['context_url'].'</a>';
				else
					echo '<span class="wpbc-no-context">'.__('No public context', 'wpbc').'</span>';

				if (current_user_can('edit_page', $context_id))
					echo ' <a target="_blank" rel="nofollow" class="wpbc-cert-edit" href="'.get_edit_post_link($context_id).'">&mdash; '.__('Edit', 'wpbc').'</a>';

			?></td></tr>
			<?php
		}

		if (wpbc_user_can_see_code_content($stamp_code)){
			?>
			<tr><td class="wpbc-api-ret-name"><?= __('Decoded content', 'wpbc') ?>:</td><td class="wpbc-api-ret-long-td wpbc-cut-ellipsis"><div class="wpbc-api-ret-long"><?php

				echo wpbc_convert_to_live('stamp_content_decoded-'.$stamp_code);

			?></div></td></tr>
			<?php
		}
	?>
	<tr><td class="wpbc-api-ret-name"><?= __('Hash', 'wpbc') ?>: </td><td class="wpbc-api-ret-hash wpbc-cut-ellipsis"><?php
		echo wpbc_convert_to_live('stamp_hash-'.$stamp_code.'-'.($is_page_last ? 1 : '0'));
	?> (<?= $ret['hash_method'] ?>)</td></tr>
	<tr><td class="wpbc-api-ret-name"><?= __('Stamp status', 'wpbc') ?>: </td><td class="wpbc-cut-ellipsis"><?php
		echo wpbc_convert_to_live('stamp_status-'.$stamp_code);
	?></td></tr>
	<tr><td class="wpbc-api-ret-name"><?= __('Stamp time', 'wpbc') ?>: </td><td class="wpbc-cut-ellipsis"><?php
		echo wpbc_convert_to_live('stamp_confirmed_at-'.$stamp_code);
	?></td></tr>
</table></div>

<?php
echo wpbc_convert_to_live('stamp_blockchain_statuses-'.$stamp_code);

echo wpbc_convert_to_live('stamp_history-'.$stamp_code.'-'.($is_page_last ? 1 : '0'));

