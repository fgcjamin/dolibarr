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
 *      \file       test/phpunit/BankAccountLineAccountancyCodeTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

global $conf,$user,$langs,$db;
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/compta/bank/class/account.class.php';
require_once dirname(__FILE__).'/../../htdocs/api/class/api.class.php';
require_once dirname(__FILE__).'/../../htdocs/api/class/api_access.class.php';
require_once dirname(__FILE__).'/../../htdocs/compta/bank/class/api_bankaccounts.class.php';
require_once dirname(__FILE__).'/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests of the Phase 13 bank-transaction-line work
 * (roadmap/backlog.md, API_GAPS_2.md items #2 and #3):
 * - AccountLine::updateAccountancyCode() (new model method) and BankAccounts::updateLine()'s new
 *   optional $accountancycode parameter that wraps it.
 * - AccountLine::fetch() previously never selected/populated numero_compte at all, so a code set
 *   via addLine()/updateLine() could never be read back through the model or the API - fixed as
 *   part of this same phase since it made the write-side additions unverifiable/unusable.
 * - BankAccounts::getLines()'s new $limit/$page pagination.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class BankAccountLineAccountancyCodeTest extends CommonClassTest
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

		if (!isModEnabled('banque')) {
			print __METHOD__." module banque must be enabled.\n";
			exit(1);
		}

		print __METHOD__."\n";
	}

	/**
	 * Create a bank account and 3 lines on it (plus the "InitialBankBalance" line Account::create()
	 * itself adds), the first seeded with an accountancy code.
	 *
	 * @return array{0:Account,1:int[]}	The bank account and the 3 created line ids
	 */
	private function seedAccountAndLines()
	{
		global $db,$user;

		static $seq = 0;
		$seq++;

		$account = new Account($db);
		$account->ref = 'BALACT'.$seq;
		$account->label = 'BankAccountLineAccountancyCodeTest account '.$seq;
		$account->bank = 'BankAccountLineAccountancyCodeTest bank';
		$account->courant = Account::TYPE_CURRENT;
		$account->type = Account::TYPE_CURRENT;
		$account->clos = 0;
		$account->country_id = 1; // FR
		$account->date_solde = dol_now();
		$account->currency_code = 'EUR';
		$account->account_number = '512999';
		$accountId = $account->create($user);
		$this->assertGreaterThan(0, $accountId, (string) $account->error);

		$lineIds = array();
		for ($i = 1; $i <= 3; $i++) {
			$lineId = $account->addline(dol_now(), 'VIR', 'BankAccountLineAccountancyCodeTest line '.$i, 100 * $i, '', 0, $user, '', '', 'ORIGCODE'.$i);
			$this->assertGreaterThan(0, $lineId, (string) $account->error);
			$lineIds[] = $lineId;
		}

		return array($account, $lineIds);
	}

	/**
	 * AccountLine::fetch() must populate numero_compte from the DB (previously a latent gap: the
	 * column was written by addLine()/insert() but never selected back by fetch(), so nothing set
	 * on a line could ever be read again through the model or the API).
	 *
	 * @return void
	 */
	public function testFetchPopulatesAccountancyCode()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		list(, $lineIds) = $this->seedAccountAndLines();

		$accountLine = new AccountLine($db);
		$result = $accountLine->fetch($lineIds[0]);
		$this->assertGreaterThan(0, $result);
		$this->assertSame('ORIGCODE1', $accountLine->numero_compte);
	}

	/**
	 * AccountLine::updateAccountancyCode() persists a new numero_compte, readable back via fetch().
	 *
	 * @return void
	 */
	public function testUpdateAccountancyCodeModelMethod()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		list(, $lineIds) = $this->seedAccountAndLines();

		$accountLine = new AccountLine($db);
		$accountLine->fetch($lineIds[0]);
		$accountLine->numero_compte = 'NEWCODE1';
		$result = $accountLine->updateAccountancyCode();
		$this->assertGreaterThan(0, $result, (string) $accountLine->error);

		$reload = new AccountLine($db);
		$reload->fetch($lineIds[0]);
		$this->assertSame('NEWCODE1', $reload->numero_compte);
	}

	/**
	 * BankAccounts::updateLine()'s new optional $accountancycode parameter updates the line's
	 * accountancy code alongside its label when provided, and leaves the existing code untouched
	 * when omitted (mirroring addLine()'s own '' == "not provided" convention).
	 *
	 * @return void
	 */
	public function testApiUpdateLineAccountancyCode()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		list($account, $lineIds) = $this->seedAccountAndLines();

		DolibarrApiAccess::$user = $user;
		$api = new BankAccounts($db);

		// Provided -> updated alongside the label.
		$resId = $api->updateLine($account->id, $lineIds[1], 'Renamed label', 'APICODE2');
		$this->assertEquals($lineIds[1], $resId);

		$reload = new AccountLine($db);
		$reload->fetch($lineIds[1]);
		$this->assertSame('Renamed label', $reload->label);
		$this->assertSame('APICODE2', $reload->numero_compte);

		// Omitted -> label updates, accountancy code is left as-is.
		$resId2 = $api->updateLine($account->id, $lineIds[2], 'Renamed label 3');
		$this->assertEquals($lineIds[2], $resId2);

		$reload2 = new AccountLine($db);
		$reload2->fetch($lineIds[2]);
		$this->assertSame('Renamed label 3', $reload2->label);
		$this->assertSame('ORIGCODE3', $reload2->numero_compte);
	}

	/**
	 * BankAccounts::updateLine()'s $label parameter is optional: omitting it updates the
	 * accountancy code alone without touching (or blanking) the existing label.
	 *
	 * @return void
	 */
	public function testApiUpdateLineAccountancyCodeWithoutLabel()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		list($account, $lineIds) = $this->seedAccountAndLines();

		DolibarrApiAccess::$user = $user;
		$api = new BankAccounts($db);

		$resId = $api->updateLine($account->id, $lineIds[0], '', 'APICODEONLY1');
		$this->assertEquals($lineIds[0], $resId);

		$reload = new AccountLine($db);
		$reload->fetch($lineIds[0]);
		$this->assertSame('BankAccountLineAccountancyCodeTest line 1', $reload->label, 'Omitting label must leave it unchanged');
		$this->assertSame('APICODEONLY1', $reload->numero_compte);
	}

	/**
	 * BankAccounts::getLines()'s new $limit/$page parameters page through an account's lines
	 * without gaps or overlap between pages.
	 *
	 * @return void
	 */
	public function testApiGetLinesPagination()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		list($account, $lineIds) = $this->seedAccountAndLines();

		DolibarrApiAccess::$user = $user;
		$api = new BankAccounts($db);

		// 3 fixture lines + the "InitialBankBalance" line Account::create() itself adds.
		$all = $api->getLines($account->id);
		$this->assertCount(4, $all);

		$page0 = $api->getLines($account->id, '', 2, 0);
		$this->assertCount(2, $page0);

		$page1 = $api->getLines($account->id, '', 2, 1);
		$this->assertCount(2, $page1);

		$ids0 = array_map(function ($l) {
			return $l->id;
		}, $page0);
		$ids1 = array_map(function ($l) {
			return $l->id;
		}, $page1);
		$this->assertEmpty(array_intersect($ids0, $ids1), 'Pagination pages must not overlap');
		$this->assertEqualsCanonicalizing(array_merge($ids0, $ids1), array_map(function ($l) {
			return $l->id;
		}, $all), 'Concatenated pages must cover the same lines as the unpaginated call');
	}
}
