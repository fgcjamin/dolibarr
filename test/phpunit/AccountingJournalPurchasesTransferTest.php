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
 *      \file       test/phpunit/AccountingJournalPurchasesTransferTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

global $conf,$user,$langs,$db;
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/accountingjournal.class.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/bookkeeping.class.php';
require_once dirname(__FILE__).'/../../htdocs/fourn/class/fournisseur.facture.class.php';
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
 * Class for PHPUnit tests of the purchases journal transfer (Phase 3b), covering
 * AccountingJournal::getDataForPurchases()/writeIntoBookkeepingForPurchases() - the extraction of
 * accountancy/journal/purchasesjournal.php's former inline write logic.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class AccountingJournalPurchasesTransferTest extends CommonClassTest
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
	 * Seed a fiscal period, chart-of-accounts accounts, and one validated+bound supplier
	 * invoice line, then run the full transfer via
	 * AccountingJournal::writeIntoBookkeepingForPurchases(), asserting the resulting bookkeeping
	 * rows match the expected accounts/amounts and that a second run is idempotent (no
	 * duplicate rows). No VAT reverse-charge and no VAT-NPR in this fixture, so only 3 of the 4
	 * create() sites fire (thirdparty, product, VAT) - reverse-charge and NPR are left as
	 * follow-up test cases (see roadmap/backlog.md).
	 *
	 * @return void
	 */
	public function testWriteIntoBookkeepingForPurchases()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		// Use a far-future, non-overlapping year so this test doesn't collide with any seeded
		// or production fiscal year / invoice (sells uses 2951/2952, expense reports uses 2950).
		$year = 2953;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingJournalPurchasesTransferTest period '.$year;
		$period->date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$period->date_end = dol_mktime(23, 59, 59, 12, 31, $year);
		$period_id = $period->create($user);
		$this->assertGreaterThan(0, $period_id, $period->errorsToString());

		// Chart of accounts + default accounts config.
		$sql = "SELECT rowid, pcg_version FROM ".MAIN_DB_PREFIX."accounting_system WHERE pcg_version = 'PCG25-DEV'";
		$res = $db->query($sql);
		$chart = $db->fetch_object($res);
		$conf->global->CHARTOFACCOUNTS = (int) $chart->rowid;
		$conf->global->ACCOUNTING_ACCOUNT_SUPPLIER = '401999';
		$conf->global->ACCOUNTING_VAT_BUY_ACCOUNT = '445799';
		$conf->global->ACCOUNTING_PRODUCT_BUY_ACCOUNT = '607999';

		$this->seedAccount($db, $chart->pcg_version, '401999', 'AccountingJournalPurchasesTransferTest supplier control');
		$acctProductId = $this->seedAccount($db, $chart->pcg_version, '607999', 'AccountingJournalPurchasesTransferTest product purchases');
		$this->seedAccount($db, $chart->pcg_version, '445799', 'AccountingJournalPurchasesTransferTest VAT');

		// Fixture: supplier + invoice, qty=1, up=100 (HT), vat=20%, no reverse-charge, no NPR.
		$dateLine = dol_mktime(12, 0, 0, 6, 15, $year);

		$soc = new Societe($db);
		$soc->name = 'AccountingJournalPurchasesTransferTest supplier';
		$soc->fournisseur = 1;
		$soc->code_fournisseur = -1;
		$socId = $soc->create($user);
		$this->assertGreaterThan(0, $socId, (string) $soc->error);

		$fac = new FactureFournisseur($db);
		$fac->socid = $socId;
		$fac->date = $dateLine;
		$fac->type = FactureFournisseur::TYPE_STANDARD;
		$facId = $fac->create($user);
		$this->assertGreaterThan(0, $facId, (string) $fac->error);

		// addline($desc, $pu, $txtva, $txlocaltax1, $txlocaltax2, $qty, $fk_product, $remise_percent, $date_start, $date_end, $fk_code_ventilation)
		$lineId = $fac->addline('AccountingJournalPurchasesTransferTest line', 100, 20, 0, 0, 1, 0, 0, 0, 0, $acctProductId);
		$this->assertGreaterThan(0, $lineId, (string) $fac->error);

		$valResult = $fac->validate($user);
		$this->assertGreaterThanOrEqual(0, $valResult, (string) $fac->error);

		// Find the nature=3 purchases journal.
		$journal = new AccountingJournal($db);
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_journal WHERE nature = 3 AND entity = ".((int) $conf->entity);
		$res = $db->query($sql);
		$obj = $db->fetch_object($res);
		$this->assertNotNull($obj, 'No nature=3 accounting journal found - accounting module setup is incomplete');
		$journal->fetch((int) $obj->rowid);

		$date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$date_end = dol_mktime(23, 59, 59, 12, 31, $year);

		// Preview via getDataForPurchases() finds the fixture.
		$data = $journal->getDataForPurchases($user, $date_start, $date_end, 'notyet');
		$this->assertCount(1, $data['tabfac'], 'getDataForPurchases() should find exactly the 1 fixture invoice');

		// Write.
		$result = $journal->writeIntoBookkeepingForPurchases($user, $date_start, $date_end);
		$this->assertGreaterThan(0, $result, implode(',', $journal->errors));

		$sql = "SELECT numero_compte, subledger_account, debit, credit FROM ".MAIN_DB_PREFIX."accounting_bookkeeping";
		$sql .= " WHERE doc_type = 'supplier_invoice' AND fk_doc = ".((int) $facId);
		$sql .= " ORDER BY numero_compte";
		$res = $db->query($sql);
		$rows = array();
		while ($obj = $db->fetch_object($res)) {
			$rows[] = $obj;
		}
		$this->assertCount(3, $rows, 'Expected 3 bookkeeping rows (thirdparty, product, VAT)');

		// up=100 HT, vat=20%: total_ht=100, total_tva=20, total_ttc=120 - balanced.
		// Unlike sells' customer entry (debit), the purchases thirdparty entry is a supplier
		// payable, so it lands as a credit; product/VAT land as debits (counterpart).
		$byAccount = array();
		foreach ($rows as $row) {
			$byAccount[$row->numero_compte] = $row;
		}
		$this->assertArrayHasKey('401999', $byAccount);
		$this->assertEqualsWithDelta(120.0, (float) $byAccount['401999']->credit, 0.01);
		$this->assertArrayHasKey('607999', $byAccount);
		$this->assertEqualsWithDelta(100.0, (float) $byAccount['607999']->debit, 0.01);
		$this->assertArrayHasKey('445799', $byAccount);
		$this->assertEqualsWithDelta(20.0, (float) $byAccount['445799']->debit, 0.01);

		// Re-running must not duplicate rows: the already-recorded invoice is excluded by
		// getDataForPurchases()'s own 'notyet' filter (same pre-existing SQL behavior, unchanged
		// by the extraction), so the write loop simply finds nothing to do.
		$result2 = $journal->writeIntoBookkeepingForPurchases($user, $date_start, $date_end);
		$this->assertGreaterThanOrEqual(0, $result2, 'Re-running the transfer must not error');

		$res = $db->query($sql);
		$this->assertSame(3, $db->num_rows($res), 'Re-running the transfer must not duplicate bookkeeping rows');
	}

	/**
	 * Phase 9: AccountingJournal::getPreviewAmountsForPurchases() must, computed BEFORE any
	 * write, match what writeIntoBookkeepingForPurchases() actually posts to the ledger
	 * afterward - the whole point of a "preview" is that it's trustworthy.
	 *
	 * @return void
	 */
	public function testGetPreviewAmountsForPurchasesMatchesWrite()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$year = 2961;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingJournalPurchasesTransferTest preview period '.$year;
		$period->date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$period->date_end = dol_mktime(23, 59, 59, 12, 31, $year);
		$period_id = $period->create($user);
		$this->assertGreaterThan(0, $period_id, $period->errorsToString());
		unset($conf->cache['active_fiscal_period_cached']);

		$sql = "SELECT rowid, pcg_version FROM ".MAIN_DB_PREFIX."accounting_system WHERE pcg_version = 'PCG25-DEV'";
		$res = $db->query($sql);
		$chart = $db->fetch_object($res);
		$conf->global->CHARTOFACCOUNTS = (int) $chart->rowid;
		$conf->global->ACCOUNTING_ACCOUNT_SUPPLIER = '401999';
		$conf->global->ACCOUNTING_VAT_BUY_ACCOUNT = '445799';
		$conf->global->ACCOUNTING_PRODUCT_BUY_ACCOUNT = '607999';

		$this->seedAccount($db, $chart->pcg_version, '401999', 'AccountingJournalPurchasesTransferTest supplier control');
		$acctProductId = $this->seedAccount($db, $chart->pcg_version, '607999', 'AccountingJournalPurchasesTransferTest product purchases');
		$this->seedAccount($db, $chart->pcg_version, '445799', 'AccountingJournalPurchasesTransferTest VAT');

		$dateLine = dol_mktime(12, 0, 0, 6, 15, $year);

		$soc = new Societe($db);
		$soc->name = 'AccountingJournalPurchasesTransferTest preview supplier';
		$soc->fournisseur = 1;
		$soc->code_fournisseur = -1;
		$socId = $soc->create($user);
		$this->assertGreaterThan(0, $socId, (string) $soc->error);

		$fac = new FactureFournisseur($db);
		$fac->socid = $socId;
		$fac->date = $dateLine;
		$fac->type = FactureFournisseur::TYPE_STANDARD;
		$facId = $fac->create($user);
		$this->assertGreaterThan(0, $facId, (string) $fac->error);

		$lineId = $fac->addline('AccountingJournalPurchasesTransferTest preview line', 100, 20, 0, 0, 1, 0, 0, 0, 0, $acctProductId);
		$this->assertGreaterThan(0, $lineId, (string) $fac->error);

		$valResult = $fac->validate($user);
		$this->assertGreaterThanOrEqual(0, $valResult, (string) $fac->error);

		$journal = new AccountingJournal($db);
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_journal WHERE nature = 3 AND entity = ".((int) $conf->entity);
		$res = $db->query($sql);
		$obj = $db->fetch_object($res);
		$journal->fetch((int) $obj->rowid);

		$date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$date_end = dol_mktime(23, 59, 59, 12, 31, $year);

		// Capture the preview BEFORE writing anything.
		$data = $journal->getDataForPurchases($user, $date_start, $date_end, 'notyet');
		$preview = $journal->getPreviewAmountsForPurchases($data);
		$this->assertArrayHasKey($facId, $preview);
		$this->assertEqualsWithDelta(100.0, $preview[$facId]['total_ht'], 0.01);
		$this->assertEqualsWithDelta(120.0, $preview[$facId]['total_ttc'], 0.01);
		$this->assertEqualsWithDelta(120.0, $preview[$facId]['total_debit'], 0.01);
		$this->assertEqualsWithDelta(120.0, $preview[$facId]['total_credit'], 0.01);

		$result = $journal->writeIntoBookkeepingForPurchases($user, $date_start, $date_end);
		$this->assertGreaterThan(0, $result, implode(',', $journal->errors));

		$sql = "SELECT SUM(debit) as d, SUM(credit) as c FROM ".MAIN_DB_PREFIX."accounting_bookkeeping";
		$sql .= " WHERE doc_type = 'supplier_invoice' AND fk_doc = ".((int) $facId);
		$res = $db->query($sql);
		$obj = $db->fetch_object($res);
		$this->assertEqualsWithDelta($preview[$facId]['total_debit'], (float) $obj->d, 0.01, 'Preview total_debit must match what was actually written');
		$this->assertEqualsWithDelta($preview[$facId]['total_credit'], (float) $obj->c, 0.01, 'Preview total_credit must match what was actually written');
	}

	/**
	 * Phase 9: getPreviewAmountsForPurchases() must correctly replicate
	 * writeIntoBookkeepingForPurchases()'s VAT reverse-charge substitution (tabtva all-zero for
	 * an invoice -> swap in tabrctva) - a synthetic hand-built $data array, no DB fixture at all,
	 * since this method is a pure function over its input array and the baseline fixture above
	 * has reverse-charge switched off (same simplification the original purchases write-method
	 * slice used to keep its own baseline fixture's math simple).
	 *
	 * @return void
	 */
	public function testGetPreviewAmountsForPurchasesVatReverseCharge()
	{
		global $conf;
		$conf = $this->savconf;
		$conf->global->ACCOUNTING_FORCE_ENABLE_VAT_REVERSE_CHARGE = 1;

		$journal = new AccountingJournal($this->savdb);
		$data = array(
			'tabfac' => array(1 => array('ref' => 'RC-TEST')),
			'tabht' => array(1 => array('607999' => 0.0)),
			'tabttc' => array(1 => array('401999' => 0.0)),
			'tabtva' => array(1 => array('445799' => 0.0)), // all-zero: triggers the reverse-charge swap
			'tablocaltax1' => array(1 => array()),
			'tablocaltax2' => array(1 => array()),
			'tabrctva' => array(1 => array('445798' => -15.0)),
			'tabrclocaltax1' => array(1 => array()),
			'tabrclocaltax2' => array(1 => array()),
			'tabother' => array(),
		);

		$preview = $journal->getPreviewAmountsForPurchases($data);
		$this->assertArrayHasKey(1, $preview);
		// -15.0 is debit-positive per the write loop's VAT block rule: debit = max($mt,0)=0,
		// credit = max(-$mt,0)=15.0.
		$this->assertEqualsWithDelta(15.0, $preview[1]['total_credit'], 0.01);
		$this->assertEqualsWithDelta(0.0, $preview[1]['total_debit'], 0.01);
		// The all-zero tabtva entry must not be double-counted alongside the reverse-charge swap.
		$this->assertEqualsWithDelta(0.0, $preview[1]['total_ht'], 0.01);
		$this->assertEqualsWithDelta(0.0, $preview[1]['total_ttc'], 0.01);

		unset($conf->global->ACCOUNTING_FORCE_ENABLE_VAT_REVERSE_CHARGE);
	}

	/**
	 * Phase 9: getPreviewAmountsForPurchases() must fold the VAT-NPR counterpart bucket
	 * (tabother) into total_debit/total_credit, but not into total_ht/total_ttc - synthetic
	 * hand-built $data, same rationale as the reverse-charge test above.
	 *
	 * @return void
	 */
	public function testGetPreviewAmountsForPurchasesVatNpr()
	{
		$journal = new AccountingJournal($this->savdb);
		$data = array(
			'tabfac' => array(1 => array('ref' => 'NPR-TEST')),
			'tabht' => array(1 => array('607999' => 0.0)),
			'tabttc' => array(1 => array('401999' => 0.0)),
			'tabtva' => array(1 => array('445799' => 0.0)),
			'tablocaltax1' => array(1 => array()),
			'tablocaltax2' => array(1 => array()),
			'tabrctva' => array(),
			'tabrclocaltax1' => array(),
			'tabrclocaltax2' => array(),
			'tabother' => array(1 => array('4458' => 5.0)),
		);

		$preview = $journal->getPreviewAmountsForPurchases($data);
		$this->assertArrayHasKey(1, $preview);
		// tabother is debit-positive: debit = max(5.0,0)=5.0, credit = max(-5.0,0)=0.
		$this->assertEqualsWithDelta(5.0, $preview[1]['total_debit'], 0.01);
		$this->assertEqualsWithDelta(0.0, $preview[1]['total_credit'], 0.01);
		$this->assertEqualsWithDelta(0.0, $preview[1]['total_ht'], 0.01);
		$this->assertEqualsWithDelta(0.0, $preview[1]['total_ttc'], 0.01);
	}

	/**
	 * A "replaced" invoice (close_code == FactureFournisseur::CLOSECODE_REPLACED, not yet
	 * dispatched) must be silently skipped by writeIntoBookkeepingForPurchases() - no
	 * bookkeeping rows, no error counted.
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

		$year = 2954;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingJournalPurchasesTransferTest replaced period '.$year;
		$period->date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$period->date_end = dol_mktime(23, 59, 59, 12, 31, $year);
		$period_id = $period->create($user);
		$this->assertGreaterThan(0, $period_id, $period->errorsToString());

		$sql = "SELECT rowid, pcg_version FROM ".MAIN_DB_PREFIX."accounting_system WHERE pcg_version = 'PCG25-DEV'";
		$res = $db->query($sql);
		$chart = $db->fetch_object($res);
		$conf->global->CHARTOFACCOUNTS = (int) $chart->rowid;
		$conf->global->ACCOUNTING_ACCOUNT_SUPPLIER = '401999';
		$conf->global->ACCOUNTING_VAT_BUY_ACCOUNT = '445799';
		$conf->global->ACCOUNTING_PRODUCT_BUY_ACCOUNT = '607999';

		$acctProductId = $this->seedAccount($db, $chart->pcg_version, '607999', 'AccountingJournalPurchasesTransferTest product purchases');

		$dateLine = dol_mktime(12, 0, 0, 6, 15, $year);

		$soc = new Societe($db);
		$soc->name = 'AccountingJournalPurchasesTransferTest replaced supplier';
		$soc->fournisseur = 1;
		$soc->code_fournisseur = -1;
		$socId = $soc->create($user);
		$this->assertGreaterThan(0, $socId, (string) $soc->error);

		$fac = new FactureFournisseur($db);
		$fac->socid = $socId;
		$fac->date = $dateLine;
		$fac->type = FactureFournisseur::TYPE_STANDARD;
		$facId = $fac->create($user);
		$this->assertGreaterThan(0, $facId, (string) $fac->error);

		$lineId = $fac->addline('AccountingJournalPurchasesTransferTest replaced line', 100, 20, 0, 0, 1, 0, 0, 0, 0, $acctProductId);
		$this->assertGreaterThan(0, $lineId, (string) $fac->error);

		$fac->validate($user);

		$db->query("UPDATE ".MAIN_DB_PREFIX."facture_fourn SET close_code = 'replaced' WHERE rowid = ".((int) $facId));

		$journal = new AccountingJournal($db);
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_journal WHERE nature = 3 AND entity = ".((int) $conf->entity);
		$res = $db->query($sql);
		$obj = $db->fetch_object($res);
		$journal->fetch((int) $obj->rowid);

		$date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$date_end = dol_mktime(23, 59, 59, 12, 31, $year);

		$journal->writeIntoBookkeepingForPurchases($user, $date_start, $date_end);

		$sql = "SELECT COUNT(*) as n FROM ".MAIN_DB_PREFIX."accounting_bookkeeping WHERE doc_type = 'supplier_invoice' AND fk_doc = ".((int) $facId);
		$res = $db->query($sql);
		$obj = $db->fetch_object($res);
		$this->assertSame(0, (int) $obj->n, 'A replaced-but-not-dispatched invoice must be skipped, not journalized');
	}
}
