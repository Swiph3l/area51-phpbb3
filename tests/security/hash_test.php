<?php
/**
*
* @package testing
* @copyright (c) 2011 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

require_once dirname(__FILE__) . '/../../phpBB/includes/functions.php';

class phpbb_security_hash_test extends phpbb_test_case
{
	public function test_check_hash_with_phpass()
	{
		global $phpbb_container;

		$config = new \phpbb\config\config(array());
		$phpbb_container = $this->getMock('Symfony\Component\DependencyInjection\ContainerInterface');
		$driver_helper = new \phpbb\passwords\driver\helper($config);
		$passwords_drivers = array(
			'passwords.driver.bcrypt'		=> new \phpbb\passwords\driver\bcrypt($config, $driver_helper),
			'passwords.driver.bcrypt_2y'	=> new \phpbb\passwords\driver\bcrypt_2y($config, $driver_helper),
			'passwords.driver.salted_md5'	=> new \phpbb\passwords\driver\salted_md5($config, $driver_helper),
			'passwords.driver.phpass'		=> new \phpbb\passwords\driver\phpass($config, $driver_helper),
		);

		foreach ($passwords_drivers as $key => $driver)
		{
			$driver->set_name($key);
		}

		$passwords_helper = new \phpbb\passwords\helper;
		// Set up passwords manager
		$passwords_manager = new \phpbb\passwords\manager($config, $passwords_drivers, $passwords_helper, 'passwords.driver.bcrypt_2y');

		$phpbb_container
			->expects($this->any())
			->method('get')
			->with('passwords.manager')
			->will($this->returnValue($passwords_manager));

		$this->assertTrue(phpbb_check_hash('test', '$H$9isfrtKXWqrz8PvztXlL3.daw4U0zI1'));
		$this->assertTrue(phpbb_check_hash('test', '$P$9isfrtKXWqrz8PvztXlL3.daw4U0zI1'));
		$this->assertFalse(phpbb_check_hash('foo', '$H$9isfrtKXWqrz8PvztXlL3.daw4U0zI1'));
	}
}

