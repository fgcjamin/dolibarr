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
 */

/**
 *      \file       test/phpunit/AccountingSetupApiTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

use Luracast\Restler\RestException;

global $conf,$user,$langs,$db;
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/api_accountingsetup.class.php';
require_once dirname(__FILE__).'/../../htdocs/api/class/api_access.class.php';
require_once dirname(__FILE__).'/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests of the accounting-setup REST API (VAT-rate accounting codes)
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class AccountingSetupApiTest extends CommonClassTest
{
	/**
	 * setUpBeforeClass
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		global $conf,$user,$langs,$db;
		$db->begin(); // This is to have all actions inside a transaction even if test launched without suite.

		if (!isModEnabled('accounting')) {
			print __METHOD__." module accounting must be enabled.\n";
			exit(1);
		}

		print __METHOD__."\n";
	}

	/**
	 * GET vatrates/accountingcodes returns the codes set via PUT vatrates/{id}/accountingcodes,
	 * and its active/fk_country filters behave as documented.
	 *
	 * @return void
	 */
	public function testGetVatRatesAccountingCodes()
	{
		global $conf,$user,$langs,$db,$mysoc;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		DolibarrApiAccess::$user = $user;

		$api = new AccountingSetup();

		// Pick a real seeded VAT rate for the company's own country.
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_tva WHERE fk_pays = ".((int) $mysoc->country_id)." AND active = 1 ORDER BY rowid LIMIT 1";
		$res = $db->query($sql);
		$this->assertNotFalse($res);
		$obj = $db->fetch_object($res);
		$this->assertNotNull($obj, 'Test DB must have at least one active VAT rate for the company country');
		$vat_id = (int) $obj->rowid;

		$put = $api->putVatRateAccountingCodes($vat_id, array(
			'accountancy_code_sell' => 'ACCSETUPTEST4457',
			'accountancy_code_buy' => 'ACCSETUPTEST4456',
		));
		$this->assertSame('ACCSETUPTEST4457', $put['accountancy_code_sell']);

		$list = $api->getVatRatesAccountingCodes();
		$found = null;
		foreach ($list as $row) {
			if ($row['id'] === $vat_id) {
				$found = $row;
				break;
			}
		}
		$this->assertNotNull($found, 'GET vatrates/accountingcodes must include the rate just updated');
		$this->assertSame('ACCSETUPTEST4457', $found['accountancy_code_sell']);
		$this->assertSame('ACCSETUPTEST4456', $found['accountancy_code_buy']);

		// fk_country = 0 (all countries) must return at least as many rows as the default
		// (company-country-only) filter.
		$all_countries = $api->getVatRatesAccountingCodes(1, 0);
		$this->assertGreaterThanOrEqual(count($list), count($all_countries));

		// A country with no seeded VAT rates must return an empty list, not an error.
		$none = $api->getVatRatesAccountingCodes(1, 999999);
		$this->assertSame(array(), $none);
	}

	/**
	 * getVatRatesAccountingCodes()/putVatRateAccountingCodes() must reject a caller without the
	 * accounting->chartofaccount right.
	 *
	 * @return void
	 */
	public function testGetVatRatesAccountingCodesRequiresPermission()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$unprivileged = clone $user;
		$unprivileged->rights = new stdClass();
		DolibarrApiAccess::$user = $unprivileged;

		$api = new AccountingSetup();

		$rejected = false;
		try {
			$api->getVatRatesAccountingCodes();
		} catch (RestException $e) {
			$rejected = true;
			$this->assertSame(403, $e->getCode());
		}
		$this->assertTrue($rejected, 'getVatRatesAccountingCodes must reject a user with no accounting->chartofaccount right');

		DolibarrApiAccess::$user = $user;
	}
}
