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
 *      \file       test/phpunit/AccountingLedgerApiTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

use Luracast\Restler\RestException;

global $conf,$user,$langs,$db;
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/api_accountancy.class.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/bookkeeping.class.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/lettering.class.php';
require_once dirname(__FILE__).'/../../htdocs/core/class/fiscalyear.class.php';
require_once dirname(__FILE__).'/../../htdocs/societe/class/societe.class.php';
require_once dirname(__FILE__).'/../../htdocs/api/class/api_access.class.php';
require_once dirname(__FILE__).'/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests of the ledger CRUD + lettering REST API (Phase 3a)
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class AccountingLedgerApiTest extends CommonClassTest
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
	 * Create a fiscal period so BookKeeping::canModifyBookkeeping() (used internally by update()
	 * and delete()) accepts writes to lines dated inside it. Uses a far-future, non-overlapping
	 * date range so this test doesn't collide with any seeded or production fiscal year.
	 *
	 * @param 	int 	$year 	Year for the fiscal period
	 * @return 	int
	 */
	private function createFiscalPeriod($year)
	{
		global $db,$user;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingLedgerApiTest period '.$year;
		$period->date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$period->date_end = dol_mktime(23, 59, 59, 12, 31, $year);
		$period_id = $period->create($user);
		$this->assertGreaterThan(0, $period_id, $period->errorsToString());

		return $period_id;
	}

	/**
	 * Create a bookkeeping line fixture inside the given year.
	 *
	 * @param 	int 	$year 				Year (must have a fiscal period already created)
	 * @param 	array 	$overrides 			Properties to override on top of initAsSpecimen() defaults
	 * @phan-param array<string,mixed> $overrides
	 * @phpstan-param array<string,mixed> $overrides
	 * @return 	BookKeeping
	 */
	private function createLedgerLine($year, $overrides = array())
	{
		global $db,$user;

		$line = new BookKeeping($db);
		$line->initAsSpecimen();
		$line->doc_date = dol_mktime(12, 0, 0, 6, 15, $year);
		foreach ($overrides as $field => $value) {
			$line->$field = $value;
		}
		// NB: BookKeeping::create() has a pre-existing bug where it returns 0 on success instead
		// of the new row id (out of scope for this phase, see roadmap/backlog.md Phase 3b), so
		// assert on $line->id (correctly populated) rather than the return value.
		$line->create($user);
		$this->assertGreaterThan(0, $line->id, $line->errorsToString());

		return $line;
	}

	/**
	 * GET/PUT/DELETE ledger/{id}, asserting the validated/exported guards.
	 *
	 * @return void
	 */
	public function testLedgerEntryCrudAndGuards()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		DolibarrApiAccess::$user = $user;

		$api = new Accountancy();

		$year = 2902;
		$this->createFiscalPeriod($year);

		$clean = $this->createLedgerLine($year, array('piece_num' => 6101));
		$validated = $this->createLedgerLine($year, array('piece_num' => 6102));
		$exported = $this->createLedgerLine($year, array('piece_num' => 6103));

		$db->query("UPDATE ".MAIN_DB_PREFIX."accounting_bookkeeping SET date_validated = '".$db->idate(dol_now())."' WHERE rowid = ".((int) $validated->id));
		$db->query("UPDATE ".MAIN_DB_PREFIX."accounting_bookkeeping SET date_export = '".$db->idate(dol_now())."' WHERE rowid = ".((int) $exported->id));

		// GET
		$fetched = $api->getLedgerEntry($clean->id);
		$this->assertSame($clean->id, $fetched->id);

		// PUT on a clean line succeeds and persists.
		$updated = $api->putLedgerEntry($clean->id, array('label_operation' => 'Updated via API test', 'debit' => 111.0));
		$this->assertSame('Updated via API test', $updated->label_operation);
		$this->assertSame(111.0, (float) $updated->debit);

		// PUT on a validated or exported line must be rejected.
		foreach (array($validated, $exported) as $blocked) {
			$rejected = false;
			try {
				$api->putLedgerEntry($blocked->id, array('debit' => 999));
			} catch (RestException $e) {
				$rejected = true;
				$this->assertSame(403, $e->getCode());
			}
			$this->assertTrue($rejected, 'putLedgerEntry on a validated/exported line must be rejected');
		}

		// DELETE on a validated line must be rejected; on an exported-but-not-validated line it
		// must succeed (matching the UI's narrower delete guard, card.php:1106-1119).
		$rejected = false;
		try {
			$api->deleteLedgerEntry($validated->id);
		} catch (RestException $e) {
			$rejected = true;
			$this->assertSame(403, $e->getCode());
		}
		$this->assertTrue($rejected, 'deleteLedgerEntry on a validated line must be rejected');

		$result = $api->deleteLedgerEntry($exported->id);
		$this->assertArrayHasKey('success', $result);

		$notfound = false;
		try {
			$api->getLedgerEntry($exported->id);
		} catch (RestException $e) {
			$notfound = true;
			$this->assertSame(404, $e->getCode());
		}
		$this->assertTrue($notfound, 'exported line must be gone after delete');
	}

	/**
	 * getLedgerEntry() on an unknown id must return a 404.
	 *
	 * @return void
	 */
	public function testGetLedgerEntryNotFound()
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
			$api->getLedgerEntry(0);
		} catch (RestException $e) {
			$notfound = true;
			$this->assertSame(404, $e->getCode());
		}
		$this->assertTrue($notfound, 'getLedgerEntry(0) must be rejected with a 404');
	}

	/**
	 * Manual lettering (POST ledger/lettering) then unlettering (DELETE ledger/lettering) on a
	 * balanced pair of lines, plus the ACCOUNTING_ENABLE_LETTERING precondition.
	 *
	 * @return void
	 */
	public function testLedgerLettering()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		DolibarrApiAccess::$user = $user;
		$conf->global->ACCOUNTING_ENABLE_LETTERING = 1;

		$api = new Accountancy();

		$year = 2903;
		$this->createFiscalPeriod($year);

		$lineA = $this->createLedgerLine($year, array('piece_num' => 6201, 'subledger_account' => 'LEDGERAPITEST', 'debit' => 200.0, 'credit' => 0.0));
		$lineB = $this->createLedgerLine($year, array('piece_num' => 6201, 'subledger_account' => 'LEDGERAPITEST', 'debit' => 0.0, 'credit' => 200.0));

		$letResult = $api->postLedgerLettering(array('ids' => array($lineA->id, $lineB->id)));
		$this->assertSame(2, $letResult['lettered']);

		$fetchedA = $api->getLedgerEntry($lineA->id);
		$this->assertNotEmpty($fetchedA->lettering_code);

		$badRequest = false;
		try {
			$api->postLedgerLettering(array('ids' => array()));
		} catch (RestException $e) {
			$badRequest = true;
			$this->assertSame(400, $e->getCode());
		}
		$this->assertTrue($badRequest, 'postLedgerLettering with empty ids must be rejected');

		$unletResult = $api->deleteLedgerLettering(array('ids' => array($lineA->id, $lineB->id)));
		$this->assertSame(2, $unletResult['unlettered']);

		$fetchedA = $api->getLedgerEntry($lineA->id);
		$this->assertEmpty($fetchedA->lettering_code);

		// Precondition: lettering disabled.
		$conf->global->ACCOUNTING_ENABLE_LETTERING = 0;
		$rejected = false;
		try {
			$api->postLedgerLettering(array('ids' => array($lineA->id, $lineB->id)));
		} catch (RestException $e) {
			$rejected = true;
			$this->assertSame(400, $e->getCode());
		}
		$this->assertTrue($rejected, 'postLedgerLettering must be rejected when lettering is disabled');
		$conf->global->ACCOUNTING_ENABLE_LETTERING = 1;
	}

	/**
	 * POST thirdparties/{id}/lettering (auto-lettering entry point) does not throw for a
	 * thirdparty with no matching candidates, and is gated by ACCOUNTING_ENABLE_LETTERING.
	 *
	 * @return void
	 */
	public function testThirdpartyLettering()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		DolibarrApiAccess::$user = $user;
		$conf->global->ACCOUNTING_ENABLE_LETTERING = 1;

		$api = new Accountancy();

		$thirdparty = new Societe($db);
		$thirdparty->name = 'AccountingLedgerApiTest thirdparty';
		$thirdparty->client = 1;
		$thirdparty->code_compta = 'LEDGERAPITESTTP';
		$thirdparty_id = $thirdparty->create($user);
		$this->assertGreaterThan(0, $thirdparty_id, implode(',', $thirdparty->errors));

		$result = $api->postThirdpartyLettering($thirdparty_id);
		$this->assertArrayHasKey('success', $result);

		$conf->global->ACCOUNTING_ENABLE_LETTERING = 0;
		$rejected = false;
		try {
			$api->postThirdpartyLettering($thirdparty_id);
		} catch (RestException $e) {
			$rejected = true;
			$this->assertSame(400, $e->getCode());
		}
		$this->assertTrue($rejected, 'postThirdpartyLettering must be rejected when lettering is disabled');
		$conf->global->ACCOUNTING_ENABLE_LETTERING = 1;
	}
}
