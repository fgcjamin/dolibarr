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
 *      \file       test/phpunit/AccountingJournalExpenseReportsTransferTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

global $conf,$user,$langs,$db;
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/accountingjournal.class.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/bookkeeping.class.php';
require_once dirname(__FILE__).'/../../htdocs/expensereport/class/expensereport.class.php';
require_once dirname(__FILE__).'/../../htdocs/core/lib/date.lib.php';
require_once dirname(__FILE__).'/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests of the expense-reports journal transfer (Phase 3b), covering
 * AccountingJournal::getDataForExpenseReports()/writeIntoBookkeepingForExpenseReports() - the
 * extraction of accountancy/journal/expensereportsjournal.php's former inline write logic.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class AccountingJournalExpenseReportsTransferTest extends CommonClassTest
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
	 * Seed a fiscal period, a chart-of-accounts account, and one validated+bound expense report
	 * line, then run the full transfer via AccountingJournal::writeIntoBookkeepingForExpenseReports(),
	 * asserting the resulting bookkeeping rows match the expected accounts/amounts and that a
	 * second run is idempotent (no duplicate rows).
	 *
	 * @return void
	 */
	public function testWriteIntoBookkeepingForExpenseReports()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		// Use a far-future, non-overlapping year so this test doesn't collide with any seeded
		// or production fiscal year / expense report.
		$year = 2950;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingJournalExpenseReportsTransferTest period '.$year;
		$period->date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$period->date_end = dol_mktime(23, 59, 59, 12, 31, $year);
		$period_id = $period->create($user);
		$this->assertGreaterThan(0, $period_id, $period->errorsToString());

		// Chart of accounts + default accounts config.
		$sql = "SELECT rowid, pcg_version FROM ".MAIN_DB_PREFIX."accounting_system WHERE pcg_version = 'PCG25-DEV'";
		$res = $db->query($sql);
		$chart = $db->fetch_object($res);
		$conf->global->CHARTOFACCOUNTS = (int) $chart->rowid;
		$conf->global->ACCOUNTING_ACCOUNT_EXPENSEREPORT = '425888';
		$conf->global->ACCOUNTING_VAT_BUY_ACCOUNT = '445888';
		$conf->global->EXPENSEREPORT_ADDON = 'mod_expensereport_sand';
		$conf->global->EXPENSEREPORT_SAND_MASK = 'ERTEST{yyyy}{mm}-{0000}';

		$acctFeeId = $this->seedAccount($db, $chart->pcg_version, '625888', 'AccountingJournalExpenseReportsTransferTest fees');
		$this->seedAccount($db, $chart->pcg_version, '425888', 'AccountingJournalExpenseReportsTransferTest expense control');
		$this->seedAccount($db, $chart->pcg_version, '445888', 'AccountingJournalExpenseReportsTransferTest VAT');

		// Fixture expense report: qty=1, up=100 (TTC), fee type id=2 (TF_TRIP), vat=20%.
		$dateLine = dol_mktime(12, 0, 0, 6, 15, $year);
		$er = new ExpenseReport($db);
		$er->fk_user_author = 1;
		$er->date_debut = $dateLine;
		$er->date_fin = $dateLine;
		$erId = $er->create($user);
		$this->assertGreaterThan(0, $erId, (string) $er->error);

		// @ - ExpenseReport::addline() emits "Undefined array key" notices when
		// getLocalTaxesFromRate() finds incomplete local-tax reference data, a pre-existing
		// gap in this schema-only test DB unrelated to this test (phpunittest.xml promotes
		// notices to failures).
		$lineId = @$er->addline(1, 100, 2, '20', $dateLine, 'AccountingJournalExpenseReportsTransferTest line');
		$this->assertGreaterThan(0, $lineId, (string) $er->error);

		// @ - ExpenseReport::setValidate() emits an "Undefined property" notice for
		// $conf->expensereport->multidir_output on this minimal test config; same pre-existing,
		// unrelated gap as addline() above.
		$valResult = @$er->setValidate($user, 1);
		$this->assertGreaterThanOrEqual(0, $valResult, (string) $er->error);

		$sql = "UPDATE ".MAIN_DB_PREFIX."expensereport_det SET fk_code_ventilation = ".((int) $acctFeeId)." WHERE rowid = ".((int) $lineId);
		$db->query($sql);

		// Find (or seed) the nature=5 expense-reports journal.
		$journal = new AccountingJournal($db);
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_journal WHERE nature = 5 AND entity = ".((int) $conf->entity);
		$res = $db->query($sql);
		$obj = $db->fetch_object($res);
		$this->assertNotNull($obj, 'No nature=5 accounting journal found - accounting module setup is incomplete');
		$journal->fetch((int) $obj->rowid);

		$date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$date_end = dol_mktime(23, 59, 59, 12, 31, $year);

		// Preview via getDataForExpenseReports() finds the fixture.
		$data = $journal->getDataForExpenseReports($user, $date_start, $date_end, 'notyet');
		$this->assertCount(1, $data['taber'], 'getDataForExpenseReports() should find exactly the 1 fixture expense report');

		// Write.
		$result = $journal->writeIntoBookkeepingForExpenseReports($user, $date_start, $date_end);
		$this->assertGreaterThan(0, $result, implode(',', $journal->errors));

		$sql = "SELECT numero_compte, subledger_account, debit, credit FROM ".MAIN_DB_PREFIX."accounting_bookkeeping";
		$sql .= " WHERE doc_type = 'expense_report' AND fk_doc = ".((int) $erId);
		$sql .= " ORDER BY numero_compte";
		$res = $db->query($sql);
		$rows = array();
		while ($obj = $db->fetch_object($res)) {
			$rows[] = $obj;
		}
		$this->assertCount(3, $rows, 'Expected 3 bookkeeping rows (thirdparty control, fee, VAT)');

		// up=100 is TTC: total_ht=83.33, total_tva=16.67, total_ttc=100 - balanced.
		$byAccount = array();
		foreach ($rows as $row) {
			$byAccount[$row->numero_compte] = $row;
		}
		$this->assertArrayHasKey('425888', $byAccount);
		$this->assertEqualsWithDelta(100.0, (float) $byAccount['425888']->credit, 0.01);
		$this->assertArrayHasKey('625888', $byAccount);
		$this->assertEqualsWithDelta(83.33, (float) $byAccount['625888']->debit, 0.01);
		$this->assertArrayHasKey('445888', $byAccount);
		$this->assertEqualsWithDelta(16.67, (float) $byAccount['445888']->debit, 0.01);

		// Re-running must not duplicate rows: the already-recorded report is excluded by
		// getDataForExpenseReports()'s own 'notyet' filter (same pre-existing SQL behavior,
		// unchanged by the extraction), so the write loop simply finds nothing to do.
		$result2 = $journal->writeIntoBookkeepingForExpenseReports($user, $date_start, $date_end);
		$this->assertGreaterThanOrEqual(0, $result2, 'Re-running the transfer must not error');

		$res = $db->query($sql);
		$this->assertSame(3, $db->num_rows($res), 'Re-running the transfer must not duplicate bookkeeping rows');
	}

	/**
	 * Find or create an accounting_account row for a given account number.
	 *
	 * @param	DoliDB	$db				Database handler
	 * @param	string	$pcgVersion		Chart of accounts version code
	 * @param	string	$accountNumber	Account number
	 * @param	string	$label			Account label
	 * @return	int						Row id
	 */
	private function seedAccount($db, $pcgVersion, $accountNumber, $label)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_account WHERE account_number = '".$db->escape($accountNumber)."' AND entity = 1";
		$res = $db->query($sql);
		if ($res && $db->num_rows($res) > 0) {
			$obj = $db->fetch_object($res);
			return (int) $obj->rowid;
		}
		$now = dol_now();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."accounting_account (entity, datec, fk_pcg_version, pcg_type, account_number, label, active)";
		$sql .= " VALUES (1, '".$db->idate($now)."', '".$db->escape($pcgVersion)."', 'XXXXXX', '".$db->escape($accountNumber)."', '".$db->escape($label)."', 1)";
		$db->query($sql);
		return (int) $db->last_insert_id(MAIN_DB_PREFIX.'accounting_account');
	}
}
