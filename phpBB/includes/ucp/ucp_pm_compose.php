<?php
/**
*
* @package ucp
* @version $Id$
* @copyright (c) 2005 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* Compose private message
* Called from ucp_pm with mode == 'compose'
*/
function compose_pm($id, $mode, $action)
{
	global $template, $db, $auth, $user;
	global $phpbb_root_path, $phpEx, $config;

	include($phpbb_root_path . 'includes/functions_posting.' . $phpEx);
	include($phpbb_root_path . 'includes/functions_display.' . $phpEx);
	include($phpbb_root_path . 'includes/message_parser.' . $phpEx);

	if (!$action)
	{
		$action = 'post';
	}

	// Grab only parameters needed here
	$to_user_id		= request_var('u', 0);
	$to_group_id	= request_var('g', 0);
	$msg_id			= request_var('p', 0);
	$draft_id		= request_var('d', 0);
	$lastclick		= request_var('lastclick', 0);

	// Do NOT use request_var or specialchars here
	$address_list	= isset($_REQUEST['address_list']) ? $_REQUEST['address_list'] : array();

	$submit		= (isset($_POST['post'])) ? true : false;
	$preview	= (isset($_POST['preview'])) ? true : false;
	$save		= (isset($_POST['save'])) ? true : false;
	$load		= (isset($_POST['load'])) ? true : false;
	$cancel		= (isset($_POST['cancel']) && !isset($_POST['save'])) ? true : false;
	$delete		= (isset($_POST['delete'])) ? true : false;

	$remove_u	= (isset($_REQUEST['remove_u'])) ? true : false;
	$remove_g	= (isset($_REQUEST['remove_g'])) ? true : false;
	$add_to		= (isset($_REQUEST['add_to'])) ? true : false;
	$add_bcc	= (isset($_REQUEST['add_bcc'])) ? true : false;

	$refresh	= isset($_POST['add_file']) || isset($_POST['delete_file']) || isset($_POST['edit_comment']) || $save || $load
		|| $remove_u || $remove_g || $add_to || $add_bcc;

	$action		= ($delete && !$preview && !$refresh && $submit) ? 'delete' : $action;

	$error = array();
	$current_time = time();

	// Was cancel pressed? If so then redirect to the appropriate page
	if ($cancel || ($current_time - $lastclick < 2 && $submit))
	{
		if ($msg_id)
		{
			redirect(append_sid("{$phpbb_root_path}ucp.$phpEx", 'i=pm&amp;mode=view&amp;action=view_message&amp;p=' . $msg_id));
		}
		redirect(append_sid("{$phpbb_root_path}ucp.$phpEx", 'i=pm'));
	}

	$sql = '';

	// What is all this following SQL for? Well, we need to know
	// some basic information in all cases before we do anything.
	switch ($action)
	{
		case 'post':
			if (!$auth->acl_get('u_sendpm'))
			{
				trigger_error('NO_AUTH_SEND_MESSAGE');
			}
		break;

		case 'reply':
		case 'quote':
		case 'forward':
		case 'quotepost':
			if (!$msg_id)
			{
				trigger_error('NO_MESSAGE');
			}

			if (!$auth->acl_get('u_sendpm'))
			{
				trigger_error('NO_AUTH_SEND_MESSAGE');
			}

			if ($action == 'quotepost')
			{
				$sql = 'SELECT p.post_id as msg_id, p.post_text as message_text, p.poster_id as author_id, p.post_time as message_time, p.bbcode_bitfield, p.bbcode_uid, p.enable_sig, p.enable_smilies, p.enable_magic_url, t.topic_title as message_subject, u.username as quote_username
					FROM ' . POSTS_TABLE . ' p, ' . TOPICS_TABLE . ' t, ' . USERS_TABLE . " u
					WHERE p.post_id = $msg_id
						AND t.topic_id = p.topic_id
						AND u.user_id = p.poster_id";
			}
			else
			{
				$sql = 'SELECT t.*, p.*, u.username as quote_username
					FROM ' . PRIVMSGS_TO_TABLE . ' t, ' . PRIVMSGS_TABLE . ' p, ' . USERS_TABLE . ' u
					WHERE t.user_id = ' . $user->data['user_id'] . "
						AND p.author_id = u.user_id
						AND t.msg_id = p.msg_id
						AND p.msg_id = $msg_id";
			}
		break;

		case 'edit':
			if (!$msg_id)
			{
				trigger_error('NO_MESSAGE');
			}

			// check for outbox (not read) status, we do not allow editing if one user already having the message
			$sql = 'SELECT p.*, t.*
				FROM ' . PRIVMSGS_TO_TABLE . ' t, ' . PRIVMSGS_TABLE . ' p
				WHERE t.user_id = ' . $user->data['user_id'] . '
					AND t.folder_id = ' . PRIVMSGS_OUTBOX . "
					AND t.msg_id = $msg_id
					AND t.msg_id = p.msg_id";
		break;

		case 'delete':
			if (!$auth->acl_get('u_pm_delete'))
			{
				trigger_error('NO_AUTH_DELETE_MESSAGE');
			}

			if (!$msg_id)
			{
				trigger_error('NO_MESSAGE');
			}

			$sql = 'SELECT msg_id, pm_unread, pm_new, author_id, folder_id
				FROM ' . PRIVMSGS_TO_TABLE . '
				WHERE user_id = ' . $user->data['user_id'] . "
					AND msg_id = $msg_id";
		break;

		case 'smilies':
			generate_smilies('window', 0);
		break;

		default:
			trigger_error('NO_ACTION_MODE');
	}

	if ($action == 'forward' && (!$config['forward_pm'] || !$auth->acl_get('u_pm_forward')))
	{
		trigger_error('NO_AUTH_FORWARD_MESSAGE');
	}

	if ($action == 'edit' && !$auth->acl_get('u_pm_edit'))
	{
		trigger_error('NO_AUTH_EDIT_MESSAGE');
	}

	if ($sql)
	{
		$result = $db->sql_query_limit($sql, 1);
		$post = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		if (!$post)
		{
			trigger_error('NO_MESSAGE');
		}

		$msg_id			= (int) $post['msg_id'];
		$folder_id		= (isset($post['folder_id'])) ? $post['folder_id'] : 0;
		$message_text	= (isset($post['message_text'])) ? $post['message_text'] : '';

		if (!$post['author_id'] && $msg_id)
		{
			trigger_error('NO_AUTHOR');
		}

		if ($action == 'quotepost')
		{
			// Decode text for message display
			decode_message($message_text, $post['bbcode_uid']);
		}

		if ($action != 'delete')
		{
			$enable_urls = $post['enable_magic_url'];
			$enable_sig = (isset($post['enable_sig'])) ? $post['enable_sig'] : 0;

			$message_attachment = (isset($post['message_attachement'])) ? $post['message_attachement'] : 0;
			$message_subject = $post['message_subject'];
			$message_time = $post['message_time'];
			$bbcode_uid = $post['bbcode_uid'];

			$quote_username = (isset($post['quote_username'])) ? $post['quote_username'] : '';
			$icon_id = (isset($post['icon_id'])) ? $post['icon_id'] : 0;

			if (($action == 'reply' || $action == 'quote' || $action == 'quotepost') && !sizeof($address_list) && !$refresh && !$submit && !$preview)
			{
				$address_list = array('u' => array($post['author_id'] => 'to'));
			}
			else if ($action == 'edit' && !sizeof($address_list) && !$refresh && !$submit && !$preview)
			{
				// Rebuild TO and BCC Header
				$address_list = rebuild_header(array('to' => $post['to_address'], 'bcc' => $post['bcc_address']));
			}

			if ($action == 'quotepost')
			{
				$check_value = 0;
			}
			else
			{
				$check_value = (($post['enable_bbcode']+1) << 8) + (($post['enable_smilies']+1) << 4) + (($enable_urls+1) << 2) + (($post['enable_sig']+1) << 1);
			}
		}
	}
	else
	{
		$message_attachment = 0;
		$message_text = $message_subject = '';

		if ($to_user_id && $action == 'post')
		{
			$address_list['u'][$to_user_id] = 'to';
		}
		else if ($to_group_id && $action == 'post')
		{
			$address_list['g'][$to_group_id] = 'to';
		}
		$check_value = 0;
	}

	if (($to_group_id || isset($address_list['g'])) && (!$config['allow_mass_pm'] || !$auth->acl_get('u_masspm')))
	{
		trigger_error('NO_AUTH_GROUP_MESSAGE');
	}

	if ($action == 'edit' && !$refresh && !$preview && !$submit)
	{
		if (!($message_time > time() - ($config['pm_edit_time'] * 60) || !$config['pm_edit_time']))
		{
			trigger_error('CANNOT_EDIT_MESSAGE_TIME');
		}
	}

	if (!isset($icon_id))
	{
		$icon_id = 0;
	}

	$message_parser = new parse_message();

	$message_parser->message = ($action == 'reply') ? '' : $message_text;
	unset($message_text);

	$s_action = append_sid("{$phpbb_root_path}ucp.$phpEx", "i=$id&amp;mode=$mode&amp;action=$action", true, $user->session_id);
	$s_action .= ($msg_id) ? "&amp;p=$msg_id" : '';

	// Delete triggered ?
	if ($action == 'delete')
	{
		// Folder id has been determined by the SQL Statement
		// $folder_id = request_var('f', PRIVMSGS_NO_BOX);

		// Do we need to confirm ?
		if (confirm_box(true))
		{
			delete_pm($user->data['user_id'], $msg_id, $folder_id);

			/**
			* @todo jump to next message in "history"?
			*/
			$meta_info = append_sid("{$phpbb_root_path}ucp.$phpEx", "i=pm&amp;folder=$folder_id");
			$message = $user->lang['MESSAGE_DELETED'];

			meta_refresh(3, $meta_info);
			$message .= '<br /><br />' . sprintf($user->lang['RETURN_FOLDER'], '<a href="' . $meta_info . '">', '</a>');
			trigger_error($message);
		}
		else
		{
			$s_hidden_fields = array(
				'p'			=> $msg_id,
				'f'			=> $folder_id,
				'action'	=> 'delete'
			);

			// "{$phpbb_root_path}ucp.$phpEx?i=pm&amp;mode=compose"
			confirm_box(false, 'DELETE_MESSAGE', build_hidden_fields($s_hidden_fields));
		}
	}

	// Handle User/Group adding/removing
	handle_message_list_actions($address_list, $remove_u, $remove_g, $add_to, $add_bcc);

	// Check for too many recipients
	if ((!$config['allow_mass_pm'] || !$auth->acl_get('u_masspm')) && num_recipients($address_list) > 1)
	{
		$address_list = get_recipient_pos($address_list, 1);
		$error[] = $user->lang['TOO_MANY_RECIPIENTS'];
	}

	$message_parser->get_submitted_attachment_data();

	if ($message_attachment && !$submit && !$refresh && !$preview && $action == 'edit')
	{
		$sql = 'SELECT attach_id, physical_filename, attach_comment, real_filename, extension, mimetype, filesize, filetime, thumbnail
			FROM ' . ATTACHMENTS_TABLE . "
			WHERE post_msg_id = $msg_id
				AND in_message = 1
				ORDER BY filetime " . ((!$config['display_order']) ? 'DESC' : 'ASC');
		$result = $db->sql_query($sql);

		$message_parser->attachment_data = array_merge($message_parser->attachment_data, $db->sql_fetchrowset($result));

		$db->sql_freeresult($result);
	}

	if (!in_array($action, array('quote', 'edit', 'delete', 'forward')))
	{
		$enable_sig		= ($config['allow_sig'] && $auth->acl_get('u_sig') && $user->optionget('attachsig'));
		$enable_smilies	= ($config['allow_smilies'] && $auth->acl_get('u_pm_smilies') && $user->optionget('smilies'));
		$enable_bbcode	= ($config['allow_bbcode'] && $auth->acl_get('u_pm_bbcode') && $user->optionget('bbcode'));
		$enable_urls	= true;
	}

	$enable_magic_url = $drafts = false;

	// User own some drafts?
	if ($auth->acl_get('u_savedrafts') && $action != 'delete')
	{
		$sql = 'SELECT draft_id
			FROM ' . DRAFTS_TABLE . '
			WHERE forum_id = 0
				AND topic_id = 0
				AND user_id = ' . $user->data['user_id'] .
				(($draft_id) ? " AND draft_id <> $draft_id" : '');
		$result = $db->sql_query_limit($sql, 1);
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		if ($row)
		{
			$drafts = true;
		}
	}

	if ($action == 'edit')
	{
		$message_parser->bbcode_uid = $bbcode_uid;
	}

	$bbcode_status	= ($config['allow_bbcode'] && $config['auth_bbcode_pm'] && $auth->acl_get('u_pm_bbcode')) ? true : false;
	$smilies_status	= ($config['allow_smilies'] && $config['auth_smilies_pm'] && $auth->acl_get('u_pm_smilies')) ? true : false;
	$img_status		= ($config['auth_img_pm'] && $auth->acl_get('u_pm_img')) ? true : false;
	$flash_status	= ($config['auth_flash_pm'] && $auth->acl_get('u_pm_flash')) ? true : false;

	// Save Draft
	if ($save && $auth->acl_get('u_savedrafts'))
	{
		$subject = request_var('subject', '', true);
		$subject = (!$subject && $action != 'post') ? $user->lang['NEW_MESSAGE'] : $subject;
		$message = request_var('message', '', true);

		if ($subject && $message)
		{
			if (confirm_box(true))
			{
				$sql = 'INSERT INTO ' . DRAFTS_TABLE . ' ' . $db->sql_build_array('INSERT', array(
					'user_id'		=> $user->data['user_id'],
					'topic_id'		=> 0,
					'forum_id'		=> 0,
					'save_time'		=> $current_time,
					'draft_subject'	=> $subject,
					'draft_message'	=> $message)
				);
				$db->sql_query($sql);

				$redirect_url = append_sid("{$phpbb_root_path}ucp.$phpEx", "i=pm&amp;mode=$mode");

				meta_refresh(3, $redirect_url);
				$message = $user->lang['DRAFT_SAVED'] . '<br /><br />' . sprintf($user->lang['RETURN_UCP'], '<a href="' . $redirect_url . '">', '</a>');

				trigger_error($message);
			}
			else
			{
				$s_hidden_fields = build_hidden_fields(array(
					'mode'		=> $mode,
					'action'	=> $action,
					'save'		=> true,
					'subject'	=> $subject,
					'message'	=> $message,
					'u'			=> $to_user_id,
					'g'			=> $to_group_id,
					'p'			=> $msg_id)
				);

				confirm_box(false, 'SAVE_DRAFT', $s_hidden_fields);
			}
		}

		unset($subject, $message);
	}

	// Load Draft
	if ($draft_id && $auth->acl_get('u_savedrafts'))
	{
		$sql = 'SELECT draft_subject, draft_message
			FROM ' . DRAFTS_TABLE . "
			WHERE draft_id = $draft_id
				AND topic_id = 0
				AND forum_id = 0
				AND user_id = " . $user->data['user_id'];
		$result = $db->sql_query_limit($sql, 1);

		if ($row = $db->sql_fetchrow($result))
		{
			$message_parser->message = $row['draft_message'];
			$message_subject = $row['draft_subject'];

			$template->assign_var('S_DRAFT_LOADED', true);
		}
		else
		{
			$draft_id = 0;
		}
		$db->sql_freeresult($result);
	}

	// Load Drafts
	if ($load && $drafts)
	{
		load_drafts(0, 0, $id);
	}

	if ($submit || $preview || $refresh)
	{
		$subject = request_var('subject', '', true);

		if (strcmp($subject, strtoupper($subject)) == 0 && $subject)
		{
			$subject = strtolower($subject);
		}

		$message_parser->message = request_var('message', '', true);

		$icon_id			= request_var('icon', 0);

		$enable_bbcode 		= (!$bbcode_status || isset($_POST['disable_bbcode'])) ? false : true;
		$enable_smilies		= (!$smilies_status || isset($_POST['disable_smilies'])) ? false : true;
		$enable_urls 		= (isset($_POST['disable_magic_url'])) ? 0 : 1;
		$enable_sig			= (!$config['allow_sig']) ? false : ((isset($_POST['attach_sig'])) ? true : false);

		if ($submit)
		{
			$status_switch	= (($enable_bbcode+1) << 8) + (($enable_smilies+1) << 4) + (($enable_urls+1) << 2) + (($enable_sig+1) << 1);
			$status_switch = ($status_switch != $check_value);
		}
		else
		{
			$status_switch = 1;
		}

		// Parse Attachments - before checksum is calculated
		$message_parser->parse_attachments('fileupload', $action, 0, $submit, $preview, $refresh, true);

		// Parse message
		$message_parser->parse($enable_bbcode, $enable_urls, $enable_smilies, $img_status, $flash_status, true);

		if ($action != 'edit' && !$preview && !$refresh && $config['flood_interval'] && !$auth->acl_get('u_ignoreflood'))
		{
			// Flood check
			$last_post_time = $user->data['user_lastpost_time'];

			if ($last_post_time)
			{
				if ($last_post_time && ($current_time - $last_post_time) < intval($config['flood_interval']))
				{
					$error[] = $user->lang['FLOOD_ERROR'];
				}
			}
		}

		// Subject defined
		if (!$subject && !($remove_u || $remove_g || $add_to || $add_bcc))
		{
			$error[] = $user->lang['EMPTY_SUBJECT'];
		}

		if (!sizeof($address_list))
		{
			$error[] = $user->lang['NO_RECIPIENT'];
		}

		if (sizeof($message_parser->warn_msg) && !($remove_u || $remove_g || $add_to || $add_bcc))
		{
			$error[] = implode('<br />', $message_parser->warn_msg);
		}

		// Store message, sync counters
		if (!sizeof($error) && $submit)
		{
			$pm_data = array(
				'msg_id'				=> (int) $msg_id,
				'from_user_id'			=> $user->data['user_id'],
				'from_user_ip'			=> $user->data['user_ip'],
				'from_username'			=> $user->data['username'],
				'reply_from_root_level'	=> (isset($root_level)) ? (int) $root_level : 0,
				'reply_from_msg_id'		=> (int) $msg_id,
				'icon_id'				=> (int) $icon_id,
				'enable_sig'			=> (bool) $enable_sig,
				'enable_bbcode'			=> (bool) $enable_bbcode,
				'enable_smilies'		=> (bool) $enable_smilies,
				'enable_urls'			=> (bool) $enable_urls,
				'bbcode_bitfield'		=> $message_parser->bbcode_bitfield,
				'bbcode_uid'			=> $message_parser->bbcode_uid,
				'message'				=> $message_parser->message,
				'attachment_data'		=> $message_parser->attachment_data,
				'filename_data'			=> $message_parser->filename_data,
				'address_list'			=> $address_list
			);
			unset($message_parser);

			// ((!$message_subject) ? $subject : $message_subject)
			$msg_id = submit_pm($action, $subject, $pm_data, true);

			$return_message_url = append_sid("{$phpbb_root_path}ucp.$phpEx", 'i=pm&amp;mode=view&amp;p=' . $msg_id);
			$return_folder_url = append_sid("{$phpbb_root_path}ucp.$phpEx", 'i=pm&amp;folder=outbox');
			meta_refresh(3, $return_message_url);

			$message = $user->lang['MESSAGE_STORED'] . '<br /><br />' . sprintf($user->lang['VIEW_MESSAGE'], '<a href="' . $return_message_url . '">', '</a>') . '<br /><br />' . sprintf($user->lang['CLICK_RETURN_FOLDER'], '<a href="' . $return_folder_url . '">', '</a>', $user->lang['PM_OUTBOX']);
			trigger_error($message);
		}

		$message_subject = $subject;
	}

	// Preview
	if (!sizeof($error) && $preview)
	{
		$post_time = ($action == 'edit') ? $post_time : $current_time;

		$preview_message = $message_parser->format_display($enable_bbcode, $enable_urls, $enable_smilies, false);

		$preview_signature = $user->data['user_sig'];
		$preview_signature_uid = $user->data['user_sig_bbcode_uid'];
		$preview_signature_bitfield = $user->data['user_sig_bbcode_bitfield'];

		// Signature
		if ($enable_sig && $config['allow_sig'] && $preview_signature)
		{
			$parse_sig = new parse_message($preview_signature);
			$parse_sig->bbcode_uid = $preview_signature_uid;
			$parse_sig->bbcode_bitfield = $preview_signature_bitfield;

			$parse_sig->format_display($enable_bbcode, $enable_urls, $enable_smilies);
			$preview_signature = $parse_sig->message;
			unset($parse_sig);
		}
		else
		{
			$preview_signature = '';
		}

		// Attachment Preview
		if (sizeof($message_parser->attachment_data))
		{
			$extensions = $update_count = array();

			$template->assign_var('S_HAS_ATTACHMENTS', true);
			display_attachments(0, 'attachment', $message_parser->attachment_data, $update_count);
		}

		$preview_subject = censor_text($subject);

		if (!sizeof($error))
		{
			$template->assign_vars(array(
				'PREVIEW_SUBJECT'		=> $preview_subject,
				'PREVIEW_MESSAGE'		=> $preview_message,
				'PREVIEW_SIGNATURE'		=> $preview_signature,

				'S_DISPLAY_PREVIEW'		=> true)
			);
		}
		unset($message_text);
	}

	// Decode text for message display
	$bbcode_uid = (($action == 'quote' || $action == 'forward') && !$preview && !$refresh && !sizeof($error)) ? $bbcode_uid : $message_parser->bbcode_uid;

	$message_parser->decode_message($bbcode_uid);

	if (($action == 'quote' || $action == 'quotepost') && !$preview && !$refresh)
	{
		if ($action == 'quotepost')
		{
			$post_id = request_var('p', 0);
			$message_link = "[url=" . generate_board_url() . "/viewtopic.$phpEx?p={$post_id}#p{$post_id}]{$message_subject}[/url]\n";
		}
		else 
		{
			$message_link = '';
		}
		$message_parser->message = $message_link . '[quote="' . $quote_username . '"]' . censor_text(trim($message_parser->message)) . "[/quote]\n";
	}

	if (($action == 'reply' || $action == 'quote' || $action == 'quotepost') && !$preview && !$refresh)
	{
		$message_subject = ((!preg_match('/^Re:/', $message_subject)) ? 'Re: ' : '') . censor_text($message_subject);
	}

	if ($action == 'forward' && !$preview && !$refresh && !$submit)
	{
		$fwd_to_field = write_pm_addresses(array('to' => $post['to_address']), 0, true);

		$forward_text = array();
		$forward_text[] = $user->lang['FWD_ORIGINAL_MESSAGE'];
		$forward_text[] = sprintf($user->lang['FWD_SUBJECT'], censor_text($message_subject));
		$forward_text[] = sprintf($user->lang['FWD_DATE'], $user->format_date($message_time));
		$forward_text[] = sprintf($user->lang['FWD_FROM'], $quote_username);
		$forward_text[] = sprintf($user->lang['FWD_TO'], implode(', ', $fwd_to_field['to']));

		$message_parser->message = implode("\n", $forward_text) . "\n\n[quote=\"[url=" . generate_board_url() . "/memberlist.$phpEx?mode=viewprofile&u={$post['author_id']}]{$quote_username}[/url]\"]\n" . censor_text(trim($message_parser->message)) . "\n[/quote]";
		$message_subject = ((!preg_match('/^Fwd:/', $message_subject)) ? 'Fwd: ' : '') . censor_text($message_subject);
	}

	$attachment_data = $message_parser->attachment_data;
	$filename_data = $message_parser->filename_data;
	$message_text = $message_parser->message;
	unset($message_parser);

	// MAIN PM PAGE BEGINS HERE

	// Generate smiley listing
	generate_smilies('inline', 0);

	// Generate PM Icons
	$s_pm_icons = false;
	if ($config['enable_pm_icons'])
	{
		$s_pm_icons = posting_gen_topic_icons($action, $icon_id);
	}

	// Generate inline attachment select box
	posting_gen_inline_attachments($attachment_data);

	// Build address list for display
	// array('u' => array($author_id => 'to'));
	if (sizeof($address_list))
	{
		// Get Usernames and Group Names
		$result = array();
		if (!empty($address_list['u']))
		{
			$sql = 'SELECT user_id as id, username as name, user_colour as colour
				FROM ' . USERS_TABLE . '
				WHERE user_id IN (' . implode(', ', array_map('intval', array_keys($address_list['u']))) . ')';
			$result['u'] = $db->sql_query($sql);
		}

		if (!empty($address_list['g']))
		{
			$sql = 'SELECT group_id as id, group_name as name, group_colour as colour, group_type
				FROM ' . GROUPS_TABLE . '
				WHERE group_receive_pm = 1
					AND group_id IN (' . implode(', ', array_map('intval', array_keys($address_list['g']))) . ')';
			$result['g'] = $db->sql_query($sql);
		}

		$u = $g = array();
		$_types = array('u', 'g');
		foreach ($_types as $type)
		{
			if (isset($result[$type]) && $result[$type])
			{
				while ($row = $db->sql_fetchrow($result[$type]))
				{
					if ($type == 'g')
					{
						$row['name'] = ($row['group_type'] == GROUP_SPECIAL) ? $user->lang['G_' . $row['name']] : $row['name'];
					}
					
					${$type}[$row['id']] = array('name' => $row['name'], 'colour' => $row['colour']);
				}
				$db->sql_freeresult($result[$type]);
			}
		}

		// Now Build the address list
		$plain_address_field = '';
		foreach ($address_list as $type => $adr_ary)
		{
			foreach ($adr_ary as $id => $field)
			{
				if (!isset(${$type}[$id]))
				{
					unset($address_list[$type][$id]);
					continue;
				}

				$field = ($field == 'to') ? 'to' : 'bcc';
				$type = ($type == 'u') ? 'u' : 'g';
				$id = (int) $id;

				$template->assign_block_vars($field . '_recipient', array(
					'NAME'		=> ${$type}[$id]['name'],
					'IS_GROUP'	=> ($type == 'g'),
					'IS_USER'	=> ($type == 'u'),
					'COLOUR'	=> (${$type}[$id]['colour']) ? ${$type}[$id]['colour'] : '',
					'UG_ID'		=> $id,
					'U_VIEW'	=> ($type == 'u') ? append_sid("{$phpbb_root_path}memberlist.$phpEx", 'mode=viewprofile&amp;u=' . $id) : append_sid("{$phpbb_root_path}memberlist.$phpEx", 'mode=group&amp;g=' . $id),
					'TYPE'		=> $type)
				);
			}
		}
	}

	// Build hidden address list
	$s_hidden_address_field = '';
	foreach ($address_list as $type => $adr_ary)
	{
		foreach ($adr_ary as $id => $field)
		{
			$s_hidden_address_field .= '<input type="hidden" name="address_list[' . (($type == 'u') ? 'u' : 'g') . '][' . (int) $id . ']" value="' . (($field == 'to') ? 'to' : 'bcc') . '" />';
		}
	}

	$bbcode_checked		= (isset($enable_bbcode)) ? !$enable_bbcode : (($config['allow_bbcode'] && $auth->acl_get('u_pm_bbcode')) ? !$user->optionget('bbcode') : 1);
	$smilies_checked	= (isset($enable_smilies)) ? !$enable_smilies : (($config['allow_smilies'] && $auth->acl_get('u_pm_smilies')) ? !$user->optionget('smilies') : 1);
	$urls_checked		= (isset($enable_urls)) ? !$enable_urls : 0;
	$sig_checked		= $enable_sig;

	switch ($action)
	{
		case 'post':
			$page_title = $user->lang['POST_NEW_PM'];
		break;

		case 'quote':
			$page_title = $user->lang['POST_QUOTE_PM'];
		break;

		case 'quotepost':
			$page_title = $user->lang['POST_PM_POST'];
		break;

		case 'reply':
			$page_title = $user->lang['POST_REPLY_PM'];
		break;

		case 'edit':
			$page_title = $user->lang['POST_EDIT_PM'];
		break;

		case 'forward':
			$page_title = $user->lang['POST_FORWARD_PM'];
		break;

		default:
			trigger_error('NO_ACTION_MODE', E_USER_ERROR);
	}

	$s_hidden_fields = '<input type="hidden" name="lastclick" value="' . $current_time . '" />';
	$s_hidden_fields .= (isset($check_value)) ? '<input type="hidden" name="status_switch" value="' . $check_value . '" />' : '';
	$s_hidden_fields .= ($draft_id || isset($_REQUEST['draft_loaded'])) ? '<input type="hidden" name="draft_loaded" value="' . ((isset($_REQUEST['draft_loaded'])) ? intval($_REQUEST['draft_loaded']) : $draft_id) . '" />' : '';

	$form_enctype = (@ini_get('file_uploads') == '0' || strtolower(@ini_get('file_uploads')) == 'off' || @ini_get('file_uploads') == '0' || !$config['allow_pm_attach'] || !$auth->acl_get('u_pm_attach')) ? '' : ' enctype="multipart/form-data"';

	// Start assigning vars for main posting page ...
	$template->assign_vars(array(
		'L_POST_A'					=> $page_title,
		'L_ICON'					=> $user->lang['PM_ICON'],
		'L_MESSAGE_BODY_EXPLAIN'	=> (intval($config['max_post_chars'])) ? sprintf($user->lang['MESSAGE_BODY_EXPLAIN'], intval($config['max_post_chars'])) : '',

		'SUBJECT'				=> (isset($message_subject)) ? $message_subject : '',
		'MESSAGE'				=> $message_text,
		'BBCODE_STATUS'			=> ($bbcode_status) ? sprintf($user->lang['BBCODE_IS_ON'], '<a href="' . append_sid("{$phpbb_root_path}faq.$phpEx", 'mode=bbcode') . '" onclick="target=\'_phpbbcode\';">', '</a>') : sprintf($user->lang['BBCODE_IS_OFF'], '<a href="' . append_sid("{$phpbb_root_path}faq.$phpEx", 'mode=bbcode') . '" onclick="target=\'_phpbbcode\';">', '</a>'),
		'IMG_STATUS'			=> ($img_status) ? $user->lang['IMAGES_ARE_ON'] : $user->lang['IMAGES_ARE_OFF'],
		'FLASH_STATUS'			=> ($flash_status) ? $user->lang['FLASH_IS_ON'] : $user->lang['FLASH_IS_OFF'],
		'SMILIES_STATUS'		=> ($smilies_status) ? $user->lang['SMILIES_ARE_ON'] : $user->lang['SMILIES_ARE_OFF'],
		'MINI_POST_IMG'			=> $user->img('icon_post', $user->lang['PM']),
		'ERROR'					=> (sizeof($error)) ? implode('<br />', $error) : '',

		'S_EDIT_POST'			=> ($action == 'edit'),
		'S_SHOW_PM_ICONS'		=> $s_pm_icons,
		'S_BBCODE_ALLOWED'		=> $bbcode_status,
		'S_BBCODE_CHECKED'		=> ($bbcode_checked) ? ' checked="checked"' : '',
		'S_SMILIES_ALLOWED'		=> $smilies_status,
		'S_SMILIES_CHECKED'		=> ($smilies_checked) ? ' checked="checked"' : '',
		'S_SIG_ALLOWED'			=> ($config['allow_sig'] && $auth->acl_get('u_sig')),
		'S_SIGNATURE_CHECKED'	=> ($sig_checked) ? ' checked="checked"' : '',
		'S_MAGIC_URL_CHECKED'	=> ($urls_checked) ? ' checked="checked"' : '',
		'S_SAVE_ALLOWED'		=> $auth->acl_get('u_savedrafts'),
		'S_HAS_DRAFTS'			=> ($auth->acl_get('u_savedrafts') && $drafts),
		'S_FORM_ENCTYPE'		=> $form_enctype,

		'S_BBCODE_IMG'			=> $img_status,
		'S_BBCODE_FLASH'		=> $flash_status,
		'S_BBCODE_QUOTE'		=> true,

		'S_POST_ACTION'				=> $s_action,
		'S_HIDDEN_ADDRESS_FIELD'	=> $s_hidden_address_field,
		'S_HIDDEN_FIELDS'			=> $s_hidden_fields,

		'S_CLOSE_PROGRESS_WINDOW'	=> isset($_POST['add_file']),
		'U_PROGRESS_BAR'			=> append_sid("{$phpbb_root_path}posting.$phpEx", 'f=0&amp;mode=popup'),
		'UA_PROGRESS_BAR'			=> append_sid("{$phpbb_root_path}posting.$phpEx", 'f=0&mode=popup', false),
		)
	);

	// Build custom bbcodes array
	display_custom_bbcodes();

	// Attachment entry
	if ($auth->acl_get('u_pm_attach') && $config['allow_pm_attach'] && $form_enctype)
	{
		posting_gen_attachment_entry($attachment_data, $filename_data);
	}
}

/**
* For composing messages, handle list actions
*/
function handle_message_list_actions(&$address_list, $remove_u, $remove_g, $add_to, $add_bcc)
{
	global $auth, $db;

	// Delete User [TO/BCC]
	if ($remove_u)
	{
		$remove_user_id = array_keys($_REQUEST['remove_u']);
		unset($address_list['u'][(int) $remove_user_id[0]]);
	}

	// Delete Group [TO/BCC]
	if ($remove_g)
	{
		$remove_group_id = array_keys($_REQUEST['remove_g']);
		unset($address_list['g'][(int) $remove_group_id[0]]);
	}

	// Add User/Group [TO]
	if ($add_to || $add_bcc)
	{
		$type = ($add_to) ? 'to' : 'bcc';

		// Add Selected Groups
		$group_list = request_var('group_list', array(0));

		if (sizeof($group_list))
		{
			foreach ($group_list as $group_id)
			{
				$address_list['g'][$group_id] = $type;
			}
		}

		// User ID's to add...
		$user_id_ary = array();

		// Build usernames to add
		$usernames = (isset($_REQUEST['username'])) ? array(request_var('username', '')) : array();
		$username_list = request_var('username_list', '');
		if ($username_list)
		{
			$usernames = array_merge($usernames, explode("\n", $username_list));
		}

		// Reveal the correct user_ids
		if (sizeof($usernames))
		{
			$user_id_ary = array();
			user_get_id_name($user_id_ary, $usernames);
		}

		// Add Friends if specified
		$friend_list = (is_array($_REQUEST['add_' . $type])) ? array_map('intval', array_keys($_REQUEST['add_' . $type])) : array();
		$user_id_ary = array_merge($user_id_ary, $friend_list);

		if (sizeof($user_id_ary))
		{
			// We need to check their PM status (do they want to receive PM's?)
			// Only check if not a moderator or admin, since they are allowed to override this user setting
			if (!$auth->acl_gets('a_', 'm_') && !$auth->acl_getf_global('m_'))
			{
				$sql = 'SELECT user_id
					FROM ' . USERS_TABLE . '
					WHERE user_id IN (' . implode(', ', $user_id_ary) . ')
						AND user_allow_pm = 1';
				$result = $db->sql_query($sql);

				while ($row = $db->sql_fetchrow($result))
				{
					$address_list['u'][$row['user_id']] = $type;
				}
				$db->sql_freeresult($result);
			}
			else
			{
				foreach ($user_id_ary as $user_id)
				{
					$address_list['u'][$user_id] = $type;
				}
			}
		}
	}
}

/**
* Return number of private message recipients
*/
function num_recipients($address_list)
{
	$num_recipients = 0;

	foreach ($address_list as $field => $adr_ary)
	{
		$num_recipients += sizeof($adr_ary);
	}

	return $num_recipients;
}

/**
* Get recipient at position 'pos'
*/
function get_recipient_pos($address_list, $position = 1)
{
	$recipient = array();

	$count = 1;
	foreach ($address_list as $field => $adr_ary)
	{
		foreach ($adr_ary as $id => $type)
		{
			if ($count == $position)
			{
				$recipient[$field][$id] = $type;
				break 2;
			}
			$count++;
		}
	}

	return $recipient;
}

?>