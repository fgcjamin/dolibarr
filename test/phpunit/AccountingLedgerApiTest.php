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
require_once dirname(__FILE__).'/../../htdocs/api/class/api.class.php';
require_once dirname(__FILE__).'/../../htdocs/api/class/api_access.class.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/api_accountancy.class.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/bookkeeping.class.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/lettering.class.php';
require_once dirname(__FILE__).'/../../htdocs/core/class/fiscalyear.class.php';
require_once dirname(__FILE__).'/../../htdocs/societe/class/societe.class.php';
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
		global $conf,$db,$user;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingLedgerApiTest period '.$year;
		$period->date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$period->date_end = dol_mktime(23, 59, 59, 12, 31, $year);
		$period_id = $period->create($user);
		$this->assertGreaterThan(0, $period_id, $period->errorsToString());

		// BookKeeping::validBookkeepingDate()/loadFiscalPeriods() caches the active-fiscal-period
		// list per process and never auto-refreshes it, so a later test method's freshly created
		// period would otherwise be invisible to BookKeeping::create()'s date check.
		unset($conf->cache['active_fiscal_period_cached']);

		return $period_id;
	}

	/**
	 * @var int Sequence used to keep each createLedgerLine() fixture's (numero_compte,
	 *          label_operation, subledger_account) tuple unique, since initAsSpecimen()'s fixed
	 *          defaults for those fields would otherwise collide with BookKeeping::create()'s own
	 *          duplicate-detection uniqueness check (doc_type+fk_doc+numero_compte+label_operation+
	 *          subledger_account+entity) as soon as more than one fixture line is created without
	 *          overriding them.
	 */
	private static $ledgerLineSeq = 0;

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

		self::$ledgerLineSeq++;

		$line = new BookKeeping($db);
		$line->initAsSpecimen();
		$line->doc_date = dol_mktime(12, 0, 0, 6, 15, $year);
		$line->numero_compte = '411'.self::$ledgerLineSeq;
		$line->label_operation = 'AccountingLedgerApiTest line '.self::$ledgerLineSeq;
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
		$this->assertSame((int) $clean->id, (int) $fetched->id);

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
	 * POST ledger creates a balanced multi-line manual ("OD") entry sharing one piece_num.
	 *
	 * @return void
	 */
	public function testPostLedgerCreatesBalancedEntry()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		DolibarrApiAccess::$user = $user;

		$api = new Accountancy();

		$year = 2905;
		$this->createFiscalPeriod($year);

		$result = $api->postLedgerEntry(array(
			'code_journal' => 'OD',
			'doc_date' => $year.'-06-15',
			'doc_ref' => 'LEDGERAPITEST-POST',
			'lines' => array(
				array('numero_compte' => '411999', 'label_operation' => 'Debit line', 'debit' => 150.0),
				array('numero_compte' => '706999', 'label_operation' => 'Credit line', 'credit' => 150.0),
			),
		));

		$this->assertCount(2, $result);
		$this->assertSame($result[0]->piece_num, $result[1]->piece_num);
		foreach ($result as $line) {
			$this->assertSame('LEDGERAPITEST-POST', $line->doc_ref);
			$this->assertSame('OD', $line->code_journal);
		}
		$total_debit = (float) $result[0]->debit + (float) $result[1]->debit;
		$total_credit = (float) $result[0]->credit + (float) $result[1]->credit;
		$this->assertSame(150.0, $total_debit);
		$this->assertSame(150.0, $total_credit);
	}

	/**
	 * POST ledger rejects an unbalanced entry, a line missing numero_compte, a line with both
	 * debit and credit set, and an unknown journal code.
	 *
	 * @return void
	 */
	public function testPostLedgerRejectsUnbalancedOrInvalidEntry()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		DolibarrApiAccess::$user = $user;

		$api = new Accountancy();

		$year = 2906;
		$this->createFiscalPeriod($year);

		$base = array(
			'code_journal' => 'OD',
			'doc_date' => $year.'-06-15',
			'doc_ref' => 'LEDGERAPITEST-POST-INVALID',
		);

		$cases = array(
			'unbalanced' => array(400, array_merge($base, array('lines' => array(
				array('numero_compte' => '411999', 'debit' => 100.0),
				array('numero_compte' => '706999', 'credit' => 50.0),
			)))),
			'missing numero_compte' => array(400, array_merge($base, array('lines' => array(
				array('numero_compte' => '', 'debit' => 100.0),
				array('numero_compte' => '706999', 'credit' => 100.0),
			)))),
			'both debit and credit on one line' => array(400, array_merge($base, array('lines' => array(
				array('numero_compte' => '411999', 'debit' => 100.0, 'credit' => 100.0),
				array('numero_compte' => '706999', 'credit' => 100.0),
			)))),
			'empty lines' => array(400, array_merge($base, array('lines' => array()))),
			'unknown journal' => array(404, array_merge($base, array('code_journal' => 'NOTAJOURNAL', 'lines' => array(
				array('numero_compte' => '411999', 'debit' => 100.0),
				array('numero_compte' => '706999', 'credit' => 100.0),
			)))),
		);

		foreach ($cases as $label => $case) {
			list($expected_code, $payload) = $case;
			$rejected = false;
			try {
				$api->postLedgerEntry($payload);
			} catch (RestException $e) {
				$rejected = true;
				$this->assertSame($expected_code, $e->getCode(), $label);
			}
			$this->assertTrue($rejected, 'postLedgerEntry must reject case: '.$label);
		}
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
	 * getLedger()'s date_start/date_end must filter correctly whether passed as a raw Unix
	 * timestamp (what the accounting MCP server sends, see roadmap/API_GAPS.md #6) or as a
	 * 'YYYY-MM-DD' string, and must reject an unparseable value with a 400 instead of silently
	 * producing a wrong (near-empty) result.
	 *
	 * @return void
	 */
	public function testGetLedgerDateFilterAcceptsTimestampAndDateString()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		DolibarrApiAccess::$user = $user;

		$api = new Accountancy();

		$year = 2907;
		$this->createFiscalPeriod($year);

		$docDate = dol_mktime(12, 0, 0, 6, 15, $year);
		$line = $this->createLedgerLine($year, array('piece_num' => 6301, 'doc_date' => $docDate));

		// Raw Unix timestamps bracketing doc_date (the MCP server's actual usage pattern). Bracket
		// the whole day rather than +/-1h: t.doc_date is a plain DATE column (no time component),
		// so the stored value is truncated to midnight regardless of the time-of-day doc_date was
		// created with.
		$dayStart = dol_mktime(0, 0, 0, 6, 15, $year);
		$dayEnd = dol_mktime(23, 59, 59, 6, 15, $year);
		$result = $api->getLedger('t.piece_num, t.rowid', 'ASC', 100, 0, '', (string) $dayStart, (string) $dayEnd);
		$ids = array_map(function ($l) {
			return (int) $l->id;
		}, $result);
		$this->assertContains((int) $line->id, $ids, 'getLedger must match doc_date when date_start/date_end are raw Unix timestamps');

		// 'YYYY-MM-DD' strings bracketing doc_date must still work.
		$result2 = $api->getLedger('t.piece_num, t.rowid', 'ASC', 100, 0, '', $year.'-06-14', $year.'-06-16');
		$ids2 = array_map(function ($l) {
			return (int) $l->id;
		}, $result2);
		$this->assertContains((int) $line->id, $ids2, 'getLedger must match doc_date when date_start/date_end are YYYY-MM-DD strings');

		// A date range not covering doc_date must exclude the line.
		$result3 = $api->getLedger('t.piece_num, t.rowid', 'ASC', 100, 0, '', $year.'-01-01', $year.'-01-02');
		$ids3 = array_map(function ($l) {
			return (int) $l->id;
		}, $result3);
		$this->assertNotContains((int) $line->id, $ids3, 'getLedger must exclude lines outside the requested date range');

		// An unparseable date_start must be rejected with a 400, not silently miscomputed.
		$rejected = false;
		try {
			$api->getLedger('t.piece_num, t.rowid', 'ASC', 100, 0, '', 'not-a-date');
		} catch (RestException $e) {
			$rejected = true;
			$this->assertSame(400, $e->getCode());
		}
		$this->assertTrue($rejected, 'getLedger must reject an unparseable date_start with a 400');
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
		$thirdparty->code_client = '-1'; // '-1' = auto-generate, per this test DB's configured customer-code mask
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
