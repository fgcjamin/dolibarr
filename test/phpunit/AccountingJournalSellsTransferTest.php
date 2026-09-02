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
 *      \file       test/phpunit/AccountingJournalSellsTransferTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

global $conf,$user,$langs,$db;
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/accountingjournal.class.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/bookkeeping.class.php';
require_once dirname(__FILE__).'/../../htdocs/compta/facture/class/facture.class.php';
require_once dirname(__FILE__).'/../../htdocs/societe/class/societe.class.php';
require_once dirname(__FILE__).'/../../htdocs/core/lib/date.lib.php';
require_once dirname(__FILE__).'/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests of the sells journal transfer (Phase 3b), covering
 * AccountingJournal::getDataForSells()/writeIntoBookkeepingForSells() - the extraction of
 * accountancy/journal/sellsjournal.php's former inline write logic.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class AccountingJournalSellsTransferTest extends CommonClassTest
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

	/**
	 * Seed a fiscal period, chart-of-accounts accounts, and one validated+bound customer
	 * invoice line, then run the full transfer via
	 * AccountingJournal::writeIntoBookkeepingForSells(), asserting the resulting bookkeeping
	 * rows match the expected accounts/amounts and that a second run is idempotent (no
	 * duplicate rows). Also covers the replaced-invoice skip guard.
	 *
	 * @return void
	 */
	public function testWriteIntoBookkeepingForSells()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		// Use a far-future, non-overlapping year so this test doesn't collide with any seeded
		// or production fiscal year / invoice.
		$year = 2951;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingJournalSellsTransferTest period '.$year;
		$period->date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$period->date_end = dol_mktime(23, 59, 59, 12, 31, $year);
		$period_id = $period->create($user);
		$this->assertGreaterThan(0, $period_id, $period->errorsToString());

		// Chart of accounts + default accounts config.
		$sql = "SELECT rowid, pcg_version FROM ".MAIN_DB_PREFIX."accounting_system WHERE pcg_version = 'PCG25-DEV'";
		$res = $db->query($sql);
		$chart = $db->fetch_object($res);
		$conf->global->CHARTOFACCOUNTS = (int) $chart->rowid;
		$conf->global->ACCOUNTING_ACCOUNT_CUSTOMER = '411999';
		$conf->global->ACCOUNTING_VAT_SOLD_ACCOUNT = '445999';
		$conf->global->ACCOUNTING_PRODUCT_SOLD_ACCOUNT = '707999';

		$this->seedAccount($db, $chart->pcg_version, '411999', 'AccountingJournalSellsTransferTest customer control');
		$acctProductId = $this->seedAccount($db, $chart->pcg_version, '707999', 'AccountingJournalSellsTransferTest product sales');
		$this->seedAccount($db, $chart->pcg_version, '445999', 'AccountingJournalSellsTransferTest VAT');

		// Fixture: customer + invoice, qty=1, up=100 (HT), vat=20%.
		$dateLine = dol_mktime(12, 0, 0, 6, 15, $year);

		$soc = new Societe($db);
		$soc->name = 'AccountingJournalSellsTransferTest customer';
		$soc->client = 1;
		$soc->code_client = -1;
		$socId = $soc->create($user);
		$this->assertGreaterThan(0, $socId, (string) $soc->error);

		$fac = new Facture($db);
		$fac->socid = $socId;
		$fac->date = $dateLine;
		$fac->type = Facture::TYPE_STANDARD;
		$facId = $fac->create($user);
		$this->assertGreaterThan(0, $facId, (string) $fac->error);

		$lineId = $fac->addline('AccountingJournalSellsTransferTest line', 100, 1, 20, 0, 0, 0, 0, '', '', $acctProductId);
		$this->assertGreaterThan(0, $lineId, (string) $fac->error);

		$valResult = $fac->validate($user);
		$this->assertGreaterThanOrEqual(0, $valResult, (string) $fac->error);

		// Find the nature=2 sells journal.
		$journal = new AccountingJournal($db);
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_journal WHERE nature = 2 AND entity = ".((int) $conf->entity);
		$res = $db->query($sql);
		$obj = $db->fetch_object($res);
		$this->assertNotNull($obj, 'No nature=2 accounting journal found - accounting module setup is incomplete');
		$journal->fetch((int) $obj->rowid);

		$date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$date_end = dol_mktime(23, 59, 59, 12, 31, $year);

		// Preview via getDataForSells() finds the fixture.
		$data = $journal->getDataForSells($user, $date_start, $date_end, 'notyet');
		$this->assertCount(1, $data['tabfac'], 'getDataForSells() should find exactly the 1 fixture invoice');

		// Write.
		$result = $journal->writeIntoBookkeepingForSells($user, $date_start, $date_end);
		$this->assertGreaterThan(0, $result, implode(',', $journal->errors));

		$sql = "SELECT numero_compte, subledger_account, debit, credit FROM ".MAIN_DB_PREFIX."accounting_bookkeeping";
		$sql .= " WHERE doc_type = 'customer_invoice' AND fk_doc = ".((int) $facId);
		$sql .= " ORDER BY numero_compte";
		$res = $db->query($sql);
		$rows = array();
		while ($obj = $db->fetch_object($res)) {
			$rows[] = $obj;
		}
		$this->assertCount(3, $rows, 'Expected 3 bookkeeping rows (thirdparty, product, VAT)');

		// up=100 HT, vat=20%: total_ht=100, total_tva=20, total_ttc=120 - balanced.
		$byAccount = array();
		foreach ($rows as $row) {
			$byAccount[$row->numero_compte] = $row;
		}
		$this->assertArrayHasKey('411999', $byAccount);
		$this->assertEqualsWithDelta(120.0, (float) $byAccount['411999']->debit, 0.01);
		$this->assertArrayHasKey('707999', $byAccount);
		$this->assertEqualsWithDelta(100.0, (float) $byAccount['707999']->credit, 0.01);
		$this->assertArrayHasKey('445999', $byAccount);
		$this->assertEqualsWithDelta(20.0, (float) $byAccount['445999']->credit, 0.01);

		// Re-running must not duplicate rows: the already-recorded invoice is excluded by
		// getDataForSells()'s own 'notyet' filter (same pre-existing SQL behavior, unchanged
		// by the extraction), so the write loop simply finds nothing to do.
		$result2 = $journal->writeIntoBookkeepingForSells($user, $date_start, $date_end);
		$this->assertGreaterThanOrEqual(0, $result2, 'Re-running the transfer must not error');

		$res = $db->query($sql);
		$this->assertSame(3, $db->num_rows($res), 'Re-running the transfer must not duplicate bookkeeping rows');
	}

	/**
	 * Phase 9: AccountingJournal::getPreviewAmountsForSells() must, computed BEFORE any write,
	 * match what writeIntoBookkeepingForSells() actually posts to the ledger afterward - the
	 * whole point of a "preview" is that it's trustworthy.
	 *
	 * @return void
	 */
	public function testGetPreviewAmountsForSellsMatchesWrite()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$year = 2960;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingJournalSellsTransferTest preview period '.$year;
		$period->date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$period->date_end = dol_mktime(23, 59, 59, 12, 31, $year);
		$period_id = $period->create($user);
		$this->assertGreaterThan(0, $period_id, $period->errorsToString());
		unset($conf->cache['active_fiscal_period_cached']);

		$sql = "SELECT rowid, pcg_version FROM ".MAIN_DB_PREFIX."accounting_system WHERE pcg_version = 'PCG25-DEV'";
		$res = $db->query($sql);
		$chart = $db->fetch_object($res);
		$conf->global->CHARTOFACCOUNTS = (int) $chart->rowid;
		$conf->global->ACCOUNTING_ACCOUNT_CUSTOMER = '411999';
		$conf->global->ACCOUNTING_VAT_SOLD_ACCOUNT = '445999';
		$conf->global->ACCOUNTING_PRODUCT_SOLD_ACCOUNT = '707999';

		$this->seedAccount($db, $chart->pcg_version, '411999', 'AccountingJournalSellsTransferTest customer control');
		$acctProductId = $this->seedAccount($db, $chart->pcg_version, '707999', 'AccountingJournalSellsTransferTest product sales');
		$this->seedAccount($db, $chart->pcg_version, '445999', 'AccountingJournalSellsTransferTest VAT');

		$dateLine = dol_mktime(12, 0, 0, 6, 15, $year);

		$soc = new Societe($db);
		$soc->name = 'AccountingJournalSellsTransferTest preview customer';
		$soc->client = 1;
		$soc->code_client = -1;
		$socId = $soc->create($user);
		$this->assertGreaterThan(0, $socId, (string) $soc->error);

		$fac = new Facture($db);
		$fac->socid = $socId;
		$fac->date = $dateLine;
		$fac->type = Facture::TYPE_STANDARD;
		$facId = $fac->create($user);
		$this->assertGreaterThan(0, $facId, (string) $fac->error);

		$lineId = $fac->addline('AccountingJournalSellsTransferTest preview line', 100, 1, 20, 0, 0, 0, 0, '', '', $acctProductId);
		$this->assertGreaterThan(0, $lineId, (string) $fac->error);

		$valResult = $fac->validate($user);
		$this->assertGreaterThanOrEqual(0, $valResult, (string) $fac->error);

		$journal = new AccountingJournal($db);
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_journal WHERE nature = 2 AND entity = ".((int) $conf->entity);
		$res = $db->query($sql);
		$obj = $db->fetch_object($res);
		$journal->fetch((int) $obj->rowid);

		$date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$date_end = dol_mktime(23, 59, 59, 12, 31, $year);

		// Capture the preview BEFORE writing anything.
		$data = $journal->getDataForSells($user, $date_start, $date_end, 'notyet');
		$preview = $journal->getPreviewAmountsForSells($data);
		$this->assertArrayHasKey($facId, $preview);
		$this->assertEqualsWithDelta(100.0, $preview[$facId]['total_ht'], 0.01);
		$this->assertEqualsWithDelta(120.0, $preview[$facId]['total_ttc'], 0.01);
		$this->assertEqualsWithDelta(120.0, $preview[$facId]['total_debit'], 0.01);
		$this->assertEqualsWithDelta(120.0, $preview[$facId]['total_credit'], 0.01);

		$result = $journal->writeIntoBookkeepingForSells($user, $date_start, $date_end);
		$this->assertGreaterThan(0, $result, implode(',', $journal->errors));

		$sql = "SELECT SUM(debit) as d, SUM(credit) as c FROM ".MAIN_DB_PREFIX."accounting_bookkeeping";
		$sql .= " WHERE doc_type = 'customer_invoice' AND fk_doc = ".((int) $facId);
		$res = $db->query($sql);
		$obj = $db->fetch_object($res);
		$this->assertEqualsWithDelta($preview[$facId]['total_debit'], (float) $obj->d, 0.01, 'Preview total_debit must match what was actually written');
		$this->assertEqualsWithDelta($preview[$facId]['total_credit'], (float) $obj->c, 0.01, 'Preview total_credit must match what was actually written');
	}

	/**
	 * Regression test for the 2026-09-02 bug report: a transfer failure (here, a missing VAT
	 * account causing BookKeeping::create()'s NOT NULL 'label_compte' insert to fail) must be
	 * captured per-invoice in AccountingJournal::$errorforinvoicedetail, not just as an opaque
	 * error count - this is what lets AccountingJournals::transfer() surface which invoice
	 * failed and why, instead of an empty-looking `{"success": false, "nb_errors": 1}`.
	 *
	 * @return void
	 */
	public function testWriteIntoBookkeepingForSellsCapturesErrorDetail()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$year = 2962;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingJournalSellsTransferTest errordetail period '.$year;
		$period->date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$period->date_end = dol_mktime(23, 59, 59, 12, 31, $year);
		$period_id = $period->create($user);
		$this->assertGreaterThan(0, $period_id, $period->errorsToString());
		unset($conf->cache['active_fiscal_period_cached']);

		$sql = "SELECT rowid, pcg_version FROM ".MAIN_DB_PREFIX."accounting_system WHERE pcg_version = 'PCG25-DEV'";
		$res = $db->query($sql);
		$chart = $db->fetch_object($res);
		$conf->global->CHARTOFACCOUNTS = (int) $chart->rowid;
		$conf->global->ACCOUNTING_ACCOUNT_CUSTOMER = '411999';
		// Deliberately point at a VAT account that is never seeded into accounting_account -
		// reproduces the real-world "missing account in the chart of accounts" failure.
		$conf->global->ACCOUNTING_VAT_SOLD_ACCOUNT = '445962';
		$conf->global->ACCOUNTING_PRODUCT_SOLD_ACCOUNT = '707999';

		$this->seedAccount($db, $chart->pcg_version, '411999', 'AccountingJournalSellsTransferTest customer control');
		$acctProductId = $this->seedAccount($db, $chart->pcg_version, '707999', 'AccountingJournalSellsTransferTest product sales');

		$dateLine = dol_mktime(12, 0, 0, 6, 15, $year);

		$soc = new Societe($db);
		$soc->name = 'AccountingJournalSellsTransferTest errordetail customer';
		$soc->client = 1;
		$soc->code_client = -1;
		$socId = $soc->create($user);
		$this->assertGreaterThan(0, $socId, (string) $soc->error);

		$fac = new Facture($db);
		$fac->socid = $socId;
		$fac->date = $dateLine;
		$fac->type = Facture::TYPE_STANDARD;
		$facId = $fac->create($user);
		$this->assertGreaterThan(0, $facId, (string) $fac->error);

		$lineId = $fac->addline('AccountingJournalSellsTransferTest errordetail line', 100, 1, 20, 0, 0, 0, 0, '', '', $acctProductId);
		$this->assertGreaterThan(0, $lineId, (string) $fac->error);

		$valResult = $fac->validate($user);
		$this->assertGreaterThanOrEqual(0, $valResult, (string) $fac->error);

		$journal = new AccountingJournal($db);
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_journal WHERE nature = 2 AND entity = ".((int) $conf->entity);
		$res = $db->query($sql);
		$obj = $db->fetch_object($res);
		$journal->fetch((int) $obj->rowid);

		$date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$date_end = dol_mktime(23, 59, 59, 12, 31, $year);

		$result = $journal->writeIntoBookkeepingForSells($user, $date_start, $date_end);
		$this->assertLessThan(0, $result, 'Transfer must report an error when a target account is missing from the chart of accounts');
		$this->assertArrayHasKey($facId, $journal->errorforinvoicedetail, 'The failing invoice must be captured in errorforinvoicedetail');
		$this->assertSame($fac->ref, $journal->errorforinvoicedetail[$facId]['ref']);
		$this->assertNotEmpty($journal->errorforinvoicedetail[$facId]['error'], 'The per-invoice error detail must carry the actual server error message, not be empty');
	}

	/**
	 * A "replaced" invoice (close_code == Facture::CLOSECODE_REPLACED, not yet dispatched)
	 * must be silently skipped by writeIntoBookkeepingForSells() - no bookkeeping rows, no
	 * error counted.
	 *
	 * @return void
	 */
	public function testReplacedInvoiceIsSkipped()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$year = 2952;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingJournalSellsTransferTest replaced period '.$year;
		$period->date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$period->date_end = dol_mktime(23, 59, 59, 12, 31, $year);
		$period_id = $period->create($user);
		$this->assertGreaterThan(0, $period_id, $period->errorsToString());

		$sql = "SELECT rowid, pcg_version FROM ".MAIN_DB_PREFIX."accounting_system WHERE pcg_version = 'PCG25-DEV'";
		$res = $db->query($sql);
		$chart = $db->fetch_object($res);
		$conf->global->CHARTOFACCOUNTS = (int) $chart->rowid;
		$conf->global->ACCOUNTING_ACCOUNT_CUSTOMER = '411999';
		$conf->global->ACCOUNTING_VAT_SOLD_ACCOUNT = '445999';
		$conf->global->ACCOUNTING_PRODUCT_SOLD_ACCOUNT = '707999';

		$acctProductId = $this->seedAccount($db, $chart->pcg_version, '707999', 'AccountingJournalSellsTransferTest product sales');

		$dateLine = dol_mktime(12, 0, 0, 6, 15, $year);

		$soc = new Societe($db);
		$soc->name = 'AccountingJournalSellsTransferTest replaced customer';
		$soc->client = 1;
		$soc->code_client = -1;
		$socId = $soc->create($user);
		$this->assertGreaterThan(0, $socId, (string) $soc->error);

		$fac = new Facture($db);
		$fac->socid = $socId;
		$fac->date = $dateLine;
		$fac->type = Facture::TYPE_STANDARD;
		$facId = $fac->create($user);
		$this->assertGreaterThan(0, $facId, (string) $fac->error);

		$lineId = $fac->addline('AccountingJournalSellsTransferTest replaced line', 100, 1, 20, 0, 0, 0, 0, '', '', $acctProductId);
		$this->assertGreaterThan(0, $lineId, (string) $fac->error);

		$fac->validate($user);

		$db->query("UPDATE ".MAIN_DB_PREFIX."facture SET close_code = 'replaced' WHERE rowid = ".((int) $facId));

		$journal = new AccountingJournal($db);
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_journal WHERE nature = 2 AND entity = ".((int) $conf->entity);
		$res = $db->query($sql);
		$obj = $db->fetch_object($res);
		$journal->fetch((int) $obj->rowid);

		$date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$date_end = dol_mktime(23, 59, 59, 12, 31, $year);

		$journal->writeIntoBookkeepingForSells($user, $date_start, $date_end);

		$sql = "SELECT COUNT(*) as n FROM ".MAIN_DB_PREFIX."accounting_bookkeeping WHERE doc_type = 'customer_invoice' AND fk_doc = ".((int) $facId);
		$res = $db->query($sql);
		$obj = $db->fetch_object($res);
		$this->assertSame(0, (int) $obj->n, 'A replaced-but-not-dispatched invoice must be skipped, not journalized');
	}
}
