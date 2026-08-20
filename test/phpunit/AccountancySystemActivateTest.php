<?php
/* Copyright (C) 2026	fgcjamin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * or see https://www.gnu.org/
 *
 * \file       test/phpunit/AccountancySystemActivateTest.php
 * \ingroup    test
 * \brief      PHPUnit test
 *	\remarks    To run this script as CLI:  phpunit filename.php
 */

global $conf,$user,$langs,$db;
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/accountancysystem.class.php';
require_once dirname(__FILE__).'/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests of AccountancySystem::activate()
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class AccountancySystemActivateTest extends CommonClassTest
{
	/**
	 * testAccountancySystemActivateSuccess
	 *
	 * Activates the chart-of-accounts model seeded by install/mysql/data/llx_accounting_system.sql
	 * for "SE BAS-K1-MINI" — its country data file (llx_accounting_account_se.sql, 60 lines) is
	 * the smallest of all supported countries, keeping this test fast. Confirms new
	 * llx_accounting_account rows land and CHARTOFACCOUNTS is updated.
	 *
	 * @return void
	 */
	public function testAccountancySystemActivateSuccess()
	{
		global $db, $user;

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_system WHERE pcg_version = 'BAS-K1-MINI'";
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, 'Query on accounting_system failed: '.$db->lasterror());
		$obj = $db->fetch_object($resql);
		$this->assertNotNull($obj, 'Seed row for BAS-K1-MINI (Sweden) not found in llx_accounting_system - check install/mysql/data/llx_accounting_system.sql');
		$id = (int) $obj->rowid;

		$countSql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."accounting_account WHERE fk_pcg_version = 'BAS-K1-MINI'";
		$previousCount = (int) $db->fetch_object($db->query($countSql))->nb;

		$system = new AccountancySystem($db);
		$result = $system->fetch($id);
		$this->assertGreaterThan(0, $result, 'Cannot fetch seeded AccountancySystem: '.$system->error);

		$result = $system->activate($user);
		print __METHOD__." result=".$result." id=".$id."\n";
		$this->assertGreaterThan(0, $result, 'Cannot activate AccountancySystem: '.$system->error);

		$newCount = (int) $db->fetch_object($db->query($countSql))->nb;
		$this->assertGreaterThan($previousCount, $newCount, 'No new accounting_account rows were loaded for BAS-K1-MINI');

		$this->assertEquals($id, (int) getDolGlobalInt('CHARTOFACCOUNTS'), 'CHARTOFACCOUNTS was not updated to the activated model id');
	}

	/**
	 * testAccountancySystemActivateNoCountryFails
	 *
	 * A freshly created chart-of-accounts model has no fk_country set (AccountancySystem::create()
	 * never writes that column), so activation cannot resolve a country-specific data file and
	 * must fail cleanly with $this->error set, not a raw file-read warning.
	 *
	 * @return void
	 */
	public function testAccountancySystemActivateNoCountryFails()
	{
		global $db, $user;

		$system = new AccountancySystem($db);
		$system->pcg_version = 'ACTIVATETEST-NOCOUNTRY';
		$system->label = 'Activate test with no country';
		$id = $system->create($user);
		$this->assertGreaterThan(0, $id, 'Cannot create fixture AccountancySystem: '.$system->error);

		$result = $system->activate($user);
		print __METHOD__." result=".$result."\n";
		$this->assertLessThan(0, $result, 'Activation should fail when no country/data file can be resolved');
		$this->assertNotEmpty($system->error);
	}
}
