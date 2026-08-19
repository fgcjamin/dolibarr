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
 *      \file       test/phpunit/AccountingClosureApiTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

use Luracast\Restler\RestException;

global $conf,$user,$langs,$db;
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/api_accountancy.class.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/bookkeeping.class.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/accountingjournal.class.php';
require_once dirname(__FILE__).'/../../htdocs/core/class/fiscalyear.class.php';
require_once dirname(__FILE__).'/../../htdocs/api/class/api_access.class.php';
require_once dirname(__FILE__).'/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests of the accounting closure REST API (Phase 4)
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class AccountingClosureApiTest extends CommonClassTest
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
	 * Full closure wizard flow driven through the Accountancy API class, asserting that
	 * out-of-order calls (reversal before close, close before validate) are rejected, and that
	 * the in-order sequence (validate -> close -> reversal) succeeds.
	 *
	 * @return void
	 */
	public function testAccountingClosureApiFlow()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		DolibarrApiAccess::$user = $user;

		$api = new Accountancy();

		// Use a far-future, non-overlapping date range so this test doesn't collide with any
		// seeded or production fiscal year.
		$base_year = 2900;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingClosureApiTest period '.$base_year;
		$period->date_start = dol_mktime(0, 0, 0, 1, 1, $base_year);
		$period->date_end = dol_mktime(23, 59, 59, 12, 31, $base_year);
		$period_id = $period->create($user);
		$this->assertGreaterThan(0, $period_id, $period->errorsToString());

		$new_period = new Fiscalyear($db);
		$new_period->label = 'AccountingClosureApiTest period '.($base_year + 1);
		$new_period->date_start = dol_mktime(0, 0, 0, 1, 1, $base_year + 1);
		$new_period->date_end = dol_mktime(23, 59, 59, 12, 31, $base_year + 1);
		$new_period_id = $new_period->create($user);
		$this->assertGreaterThan(0, $new_period_id, $new_period->errorsToString());

		// Reversal before close must be rejected.
		$rejected = false;
		try {
			$api->reversalFiscalPeriod($period_id, 1, $new_period_id, $period->date_start, $period->date_end);
		} catch (RestException $e) {
			$rejected = true;
			$this->assertSame(409, $e->getCode());
		}
		$this->assertTrue($rejected, 'reversalFiscalPeriod on a non-closed period must be rejected');

		// Seed one unvalidated bookkeeping movement inside the period.
		// NB: BookKeeping::create() has a pre-existing bug where it returns 0 on success
		// instead of the new row id (out of scope for this phase), so assert on $line->id
		// (correctly populated) rather than the return value.
		$line = new BookKeeping($db);
		$line->initAsSpecimen();
		$line->doc_date = $period->date_start;
		$line->create($user);
		$this->assertGreaterThan(0, $line->id, $line->errorsToString());

		// Close before validate must be rejected (unvalidated movements remain).
		$rejected = false;
		try {
			$api->closeFiscalPeriod($period_id, $new_period_id, 0, 0);
		} catch (RestException $e) {
			$rejected = true;
			$this->assertSame(409, $e->getCode());
		}
		$this->assertTrue($rejected, 'closeFiscalPeriod with unvalidated movements must be rejected');

		// Validate movements (step 1).
		$validated = $api->validateFiscalPeriod($period_id, $period->date_start, $period->date_end);
		$this->assertSame($period_id, $validated->id);

		// Close the period (step 2), without generating closure bookkeeping records so this
		// test doesn't depend on ACCOUNTING_CLOSURE_* setup configuration.
		$closed = $api->closeFiscalPeriod($period_id, $new_period_id, 0, 0);
		$this->assertSame(Fiscalyear::STATUS_CLOSED, (int) $closed->status);

		// Fetch the standard seeded 'INV' inventory journal.
		$journal = new AccountingJournal($db);
		$journal->fetch(0, 'INV');
		$this->assertGreaterThan(0, $journal->id, 'INV inventory journal not found');

		// Insert accounting reversal (step 3) — now allowed since the period is closed.
		$reversed = $api->reversalFiscalPeriod($period_id, $journal->id, $new_period_id, $period->date_start, $period->date_end);
		$this->assertSame($period_id, $reversed->id);

		// Read endpoints.
		$fetched = $api->getFiscalPeriod($period_id);
		$this->assertSame($period_id, $fetched->id);

		$list = $api->getFiscalPeriods();
		$this->assertIsArray($list);
	}

	/**
	 * getFiscalPeriod() on an unknown id must return a 404.
	 *
	 * @return void
	 */
	public function testGetFiscalPeriodNotFound()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		DolibarrApiAccess::$user = $user;

		$api = new Accountancy();

		$notfound = false;
		try {
			$api->getFiscalPeriod(0);
		} catch (RestException $e) {
			$notfound = true;
			$this->assertSame(404, $e->getCode());
		}
		$this->assertTrue($notfound, 'getFiscalPeriod(0) must be rejected with a 404');
	}
}
