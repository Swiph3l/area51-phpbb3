<?php
/**
*
* @package testing
* @copyright (c) 2011 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

require_once dirname(__FILE__) . '/../../phpBB/includes/functions.php';
require_once dirname(__FILE__) . '/../../phpBB/includes/db/migration/tool/module.php';
require_once dirname(__FILE__) . '/../../phpBB/includes/db/migration/exception.php';

class phpbb_dbal_migrator_tool_module_test extends phpbb_database_test_case
{
	public function getDataSet()
	{
		return $this->createXMLDataSet(dirname(__FILE__).'/fixtures/migrator_module.xml');
	}

	public function setup()
	{
		// Need global $db, $user for delete_module function in acp_modules
		global $phpbb_root_path, $phpEx, $skip_add_log, $db, $user;

		parent::setup();

		// Force add_log function to not be used
		$skip_add_log = true;

		$db = $this->db = $this->new_dbal();
		$this->cache = new phpbb_cache_service(new phpbb_cache_driver_null());
		$user = $this->user = new phpbb_user();

		$this->tool = new phpbb_db_migration_tool_module($this->db, $this->cache, $this->user, $phpbb_root_path, $phpEx);
	}

	public function exists_data()
	{
		return array(
			// Test the category
			array(
				'',
				'ACP_CAT',
				true,
			),
			array(
				0,
				'ACP_CAT',
				true,
			),

			// Test the module
			array(
				'',
				'ACP_MODULE',
				false,
			),
			array(
				false,
				'ACP_MODULE',
				true,
			),
			array(
				'ACP_CAT',
				'ACP_MODULE',
				true,
			),
		);
	}

	/**
	* @dataProvider exists_data
	*/
	public function test_exists($parent, $module, $expected)
	{
		$this->assertEquals($expected, $this->tool->exists('acp', $parent, $module));
	}

	public function test_add()
	{
		try
		{
			$this->tool->add('acp', 0, 'ACP_NEW_CAT');
		}
		catch (Exception $e)
		{
			$this->fail($e);
		}
		$this->assertEquals(true, $this->tool->exists('acp', 0, 'ACP_NEW_CAT'));

		// Should throw an exception when trying to add a module that already exists
		try
		{
			$this->tool->add('acp', 0, 'ACP_NEW_CAT');
			$this->fail('Exception not thrown');
		}
		catch (Exception $e) {}

		try
		{
			$this->tool->add('acp', ACP_NEW_CAT, array(
				'module_basename'	=> 'acp_new_module',
				'module_langname'	=> 'ACP_NEW_MODULE',
				'module_mode'		=> 'test',
				'module_auth'		=> '',
			));
		}
		catch (Exception $e)
		{
			$this->fail($e);
		}
		$this->assertEquals(true, $this->tool->exists('acp', 'ACP_NEW_CAT', 'ACP_NEW_MODULE'));
	}

	public function test_remove()
	{
		try
		{
			$this->tool->remove('acp', 'ACP_CAT', 'ACP_MODULE');
		}
		catch (Exception $e)
		{
			$this->fail($e);
		}
		$this->assertEquals(false, $this->tool->exists('acp', 'ACP_CAT', 'ACP_MODULE'));
	}
}
