<?php
/**
*
* @package testing
* @copyright (c) 2013 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

require_once dirname(__FILE__) . '/common_groups_test.php';

/**
* @group functional
*/
class phpbb_functional_ucp_groups_test extends phpbb_functional_common_groups_test
{
	protected $db;

	protected function get_url()
	{
		return 'ucp.php?i=groups&mode=manage&action=edit';
	}

	protected function get_teampage_settings()
	{
		if (!isset($this->db))
		{
			$this->db = $this->get_db();
		}
		$sql = 'SELECT g.group_legend AS group_legend, t.teampage_position AS group_teampage
			FROM ' . GROUPS_TABLE . ' g
			LEFT JOIN ' . TEAMPAGE_TABLE . ' t
				ON (t.group_id = g.group_id)
			WHERE g.group_id = 5';
		$result = $this->db->sql_query($sql);
		$group_row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $group_row;
	}

	public function test_ucp_groups_teampage()
	{
		$this->group_manage_login();

		// Test if group_legend or group_teampage are modified while
		// submitting the ucp_group_manage page
		$form = $this->get_group_manage_form();
		$teampage_settings = $this->get_teampage_settings();
		$crawler = self::submit($form);
		$this->assertContains($this->lang('GROUP_UPDATED'), $crawler->text());
		$this->assertEquals($teampage_settings, $this->get_teampage_settings());
	}
}
