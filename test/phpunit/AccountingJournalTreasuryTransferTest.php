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
 *      \file       test/phpunit/AccountingJournalTreasuryTransferTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

global $conf,$user,$langs,$db;
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/accountingjournal.class.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/bookkeeping.class.php';
require_once dirname(__FILE__).'/../../htdocs/compta/bank/class/account.class.php';
require_once dirname(__FILE__).'/../../htdocs/compta/bank/class/paymentvarious.class.php';
require_once dirname(__FILE__).'/../../htdocs/compta/paiement/class/paiement.class.php';
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
 * Class for PHPUnit tests of the treasury journal transfer (Phase 3b, fifth/final slice),
 * covering AccountingJournal::getDataForTreasury()/writeIntoBookkeepingForTreasury() - the
 * extraction of accountancy/journal/treasuryjournal.php's former inline data-collection and
 * write logic (nature=4, RECETTES-DEPENSES accounting mode - the same journal row bank uses,
 * but a functionally distinct page/data model, see roadmap/backlog.md Phase 3b).
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class AccountingJournalTreasuryTransferTest extends CommonClassTest
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
		if (!isModEnabled('banque')) {
			print __METHOD__." module banque must be enabled.\n";
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
	 * Create a bank account linked to the nature=4 accounting journal (shared by bank and
	 * treasury - see roadmap/backlog.md Phase 3b for why both pages target this one journal row).
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	User	$user		User
	 * @param	int		$journalId	Nature=4 accounting journal id
	 * @param	string	$ref		Bank account ref (must be unique)
	 * @param	string	$accountNumber	GL account number for this bank account
	 * @return	Account				Created bank account
	 */
	private function createBankAccount($db, $user, $journalId, $ref, $accountNumber)
	{
		$bankAccount = new Account($db);
		$bankAccount->ref = $ref;
		$bankAccount->label = 'AccountingJournalTreasuryTransferTest '.$ref;
		$bankAccount->bank = 'AccountingJournalTreasuryTransferTest bank';
		$bankAccount->courant = Account::TYPE_CURRENT;
		$bankAccount->type = Account::TYPE_CURRENT;
		$bankAccount->clos = 0;
		$bankAccount->country_id = 1; // FR
		$bankAccount->date_solde = dol_now();
		$bankAccount->currency_code = 'EUR';
		$bankAccount->account_number = $accountNumber;
		$bankAccount->fk_accountancy_journal = $journalId;
		$bankAccountId = $bankAccount->create($user);
		$this->assertGreaterThan(0, $bankAccountId, (string) $bankAccount->error);
		return $bankAccount;
	}

	/**
	 * Fetch the nature=4 (bank/treasury) accounting journal.
	 *
	 * @param	DoliDB	$db		Database handler
	 * @return	AccountingJournal
	 */
	private function fetchTreasuryJournal($db)
	{
		global $conf;
		$journal = new AccountingJournal($db);
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_journal WHERE nature = 4 AND entity = ".((int) $conf->entity);
		$res = $db->query($sql);
		$obj = $db->fetch_object($res);
		$this->assertNotNull($obj, 'No nature=4 accounting journal found - accounting module setup is incomplete');
		$journal->fetch((int) $obj->rowid);
		return $journal;
	}

	/**
	 * Seed a fiscal period, chart-of-accounts accounts, a bank account linked to the nature=4
	 * journal, a validated customer invoice with its line bound (fk_code_ventilation) to a
	 * product account, and a 0%-VAT payment recorded to that bank account (the 'payment' branch
	 * of the 10-way source-type dispatch - the most common real-world case). VAT is kept at 0% so
	 * the fixture doesn't need VAT-rate accounting-code dictionary data seeded (mirrors how the
	 * purchases journal's baseline test kept reverse-charge/NPR switched off to keep the expected
	 * math simple). Then run the full transfer via
	 * AccountingJournal::writeIntoBookkeepingForTreasury(), asserting the resulting bookkeeping
	 * rows match the expected accounts/amounts and that a second run is idempotent (no duplicate
	 * rows).
	 *
	 * Unlike bank's 'payment' branch (which books the counterpart to the customer subledger
	 * account), treasury's 'payment' branch books the counterpart directly to the invoice line's
	 * own bound product/service account (fd.fk_code_ventilation) - there is no subledger/lettering
	 * handling at all in treasury, confirmed while scoping this slice (see roadmap/backlog.md).
	 *
	 * @return void
	 */
	public function testWriteIntoBookkeepingForTreasuryPaymentBranch()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		// Use a far-future, non-overlapping year so this test doesn't collide with any seeded
		// or production fiscal year / invoice (sells uses 2951/2952, expense reports uses 2950,
		// purchases uses 2953/2954, bank uses 2955-2957).
		$year = 2958;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingJournalTreasuryTransferTest period '.$year;
		$period->date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$period->date_end = dol_mktime(23, 59, 59, 12, 31, $year);
		$period_id = $period->create($user);
		$this->assertGreaterThan(0, $period_id, $period->errorsToString());
		// BookKeeping::validBookkeepingDate() caches the active-fiscal-period list in
		// $conf->cache on first use and never refreshes it (force=false); since this test class
		// runs multiple methods, each creating a new fiscal year, in the same process, the cache
		// must be invalidated after each new period is created or a later test's period would be
		// invisible to BookKeeping::create()'s date check.
		unset($conf->cache['active_fiscal_period_cached']);

		$conf->global->ACCOUNTING_MODE = 'RECETTES-DEPENSES';

		// Chart of accounts + default accounts config.
		$sql = "SELECT rowid, pcg_version FROM ".MAIN_DB_PREFIX."accounting_system WHERE pcg_version = 'PCG25-DEV'";
		$res = $db->query($sql);
		$chart = $db->fetch_object($res);
		$conf->global->CHARTOFACCOUNTS = (int) $chart->rowid;

		$productAccountId = $this->seedAccount($db, $chart->pcg_version, '707958', 'AccountingJournalTreasuryTransferTest product');
		$this->seedAccount($db, $chart->pcg_version, '512958', 'AccountingJournalTreasuryTransferTest bank');

		$journal = $this->fetchTreasuryJournal($db);
		$bankAccount = $this->createBankAccount($db, $user, (int) $journal->id, 'ACJTR'.$year, '512958');

		// Fixture: customer + invoice, qty=1, up=120 (HT), vat=0% -> total_ttc=120.
		$dateLine = dol_mktime(12, 0, 0, 6, 15, $year);

		$soc = new Societe($db);
		$soc->name = 'AccountingJournalTreasuryTransferTest customer';
		$soc->client = 1;
		$soc->code_client = '-1';
		$socId = $soc->create($user);
		$this->assertGreaterThan(0, $socId, (string) $soc->error);

		$fac = new Facture($db);
		$fac->socid = $socId;
		$fac->date = $dateLine;
		$fac->type = Facture::TYPE_STANDARD;
		$facId = $fac->create($user);
		$this->assertGreaterThan(0, $facId, (string) $fac->error);

		// addline($desc, $pu_ht, $qty, $txtva, ...)
		$lineId = $fac->addline('AccountingJournalTreasuryTransferTest line', 120, 1, 0);
		$this->assertGreaterThan(0, $lineId, (string) $fac->error);

		// Bind the line to the product account - treasury's 'payment' branch requires
		// fd.fk_code_ventilation > 0 (there is no bindInvoiceLine() convenience method; every
		// journal fixture in this family sets it via this same raw UPDATE, matching how
		// accountancy/customer/card.php itself does it).
		$sql = "UPDATE ".MAIN_DB_PREFIX."facturedet SET fk_code_ventilation = ".((int) $productAccountId)." WHERE rowid = ".((int) $lineId);
		$db->query($sql);

		$valResult = $fac->validate($user);
		$this->assertGreaterThanOrEqual(0, $valResult, (string) $fac->error);

		// Record a full payment on this invoice, into the bank account linked to the nature=4
		// journal - mirrors htdocs/compta/paiement.php's action=='confirm_paiement' flow.
		$thirdparty = new Societe($db);
		$thirdparty->fetch($socId);

		$paiement = new Paiement($db);
		$paiement->datepaye = $dateLine;
		$paiement->amounts = array($facId => 120);
		$paiement->paiementcode = 'VIR';
		$paiement->paiementid = dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1);
		$paiement->note_private = 'AccountingJournalTreasuryTransferTest payment';
		$paiement->fk_account = $bankAccount->id;
		$paiementId = $paiement->create($user, 1, $thirdparty);
		$this->assertGreaterThan(0, $paiementId, implode(',', $paiement->errors));

		$bankLineId = $paiement->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $bankAccount->id, '', '');
		$this->assertGreaterThan(0, $bankLineId, implode(',', $paiement->errors));

		$date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$date_end = dol_mktime(23, 59, 59, 12, 31, $year);

		// Preview via getDataForTreasury() finds the fixture.
		$data = $journal->getDataForTreasury($user, $date_start, $date_end, 'notyet');
		$this->assertCount(1, $data['tabpay'], 'getDataForTreasury() should find exactly the 1 fixture bank line');

		// Write.
		$result = $journal->writeIntoBookkeepingForTreasury($user, $date_start, $date_end);
		$this->assertGreaterThan(0, $result, implode(',', $journal->errors));

		$sql = "SELECT numero_compte, debit, credit FROM ".MAIN_DB_PREFIX."accounting_bookkeeping";
		$sql .= " WHERE doc_type = 'bank' AND fk_doc = ".((int) $bankLineId);
		$sql .= " ORDER BY numero_compte";
		$res = $db->query($sql);
		$rows = array();
		while ($obj = $db->fetch_object($res)) {
			$rows[] = $obj;
		}
		$this->assertCount(2, $rows, 'Expected 2 bookkeeping rows (bank line, product account counterpart)');

		// Full 0%-VAT payment of a 120 TTC invoice: bank account is debited 120, the invoice
		// line's own bound product account is credited 120 - balanced.
		$byAccount = array();
		foreach ($rows as $row) {
			$byAccount[$row->numero_compte] = $row;
		}
		$this->assertArrayHasKey('512958', $byAccount);
		$this->assertEqualsWithDelta(120.0, (float) $byAccount['512958']->debit, 0.01);
		$this->assertArrayHasKey('707958', $byAccount, 'Counterpart must land on the account the invoice line was bound to, not a customer subledger');
		$this->assertEqualsWithDelta(120.0, (float) $byAccount['707958']->credit, 0.01);

		// Re-running must not duplicate rows: the already-recorded bank line is excluded by
		// getDataForTreasury()'s own 'notyet' filter (same pre-existing SQL behavior, unchanged
		// by the extraction), so the write loop simply finds nothing to do.
		$result2 = $journal->writeIntoBookkeepingForTreasury($user, $date_start, $date_end);
		$this->assertGreaterThanOrEqual(0, $result2, 'Re-running the transfer must not error');

		$res = $db->query($sql);
		$this->assertSame(2, $db->num_rows($res), 'Re-running the transfer must not duplicate bookkeeping rows');
	}

	/**
	 * The 'payment_various' branch is a genuinely distinct code path from 'payment': it books
	 * directly against the PaymentVarious row's own accountancy_code (no invoice, no thirdparty,
	 * no subledger, no fk_code_ventilation binding at all), exercising a different switch case in
	 * getDataForTreasury() and a different key-resolution path in writeIntoBookkeepingForTreasury().
	 *
	 * @return void
	 */
	public function testWriteIntoBookkeepingForTreasuryPaymentVariousBranch()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$year = 2959;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingJournalTreasuryTransferTest payment_various period '.$year;
		$period->date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$period->date_end = dol_mktime(23, 59, 59, 12, 31, $year);
		$period_id = $period->create($user);
		$this->assertGreaterThan(0, $period_id, $period->errorsToString());
		unset($conf->cache['active_fiscal_period_cached']);

		$conf->global->ACCOUNTING_MODE = 'RECETTES-DEPENSES';

		$sql = "SELECT rowid, pcg_version FROM ".MAIN_DB_PREFIX."accounting_system WHERE pcg_version = 'PCG25-DEV'";
		$res = $db->query($sql);
		$chart = $db->fetch_object($res);
		$conf->global->CHARTOFACCOUNTS = (int) $chart->rowid;

		$this->seedAccount($db, $chart->pcg_version, '627958', 'AccountingJournalTreasuryTransferTest misc expense');
		$this->seedAccount($db, $chart->pcg_version, '512959', 'AccountingJournalTreasuryTransferTest bank (payment_various case)');

		$journal = $this->fetchTreasuryJournal($db);
		$bankAccount = $this->createBankAccount($db, $user, (int) $journal->id, 'ACJTR'.$year, '512959');

		$dateLine = dol_mktime(12, 0, 0, 6, 15, $year);

		$paymentVarious = new PaymentVarious($db);
		$paymentVarious->label = 'AccountingJournalTreasuryTransferTest misc payment';
		$paymentVarious->datep = $dateLine;
		$paymentVarious->datev = $dateLine;
		$paymentVarious->amount = 75;
		$paymentVarious->sens = 1; // money in
		$paymentVarious->accountancy_code = '627958';
		$paymentVarious->subledger_account = '';
		$paymentVarious->fk_account = $bankAccount->id;
		$paymentVarious->type_payment = dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1);
		$paymentVariousId = $paymentVarious->create($user);
		$this->assertGreaterThan(0, $paymentVariousId, (string) $paymentVarious->error);

		// create() links the bank line via update_fk_bank(), which updates the DB row but does
		// not populate $this->fk_bank on the in-memory object - re-fetch to get it.
		$paymentVarious->fetch($paymentVariousId);
		$bankLineId = (int) $paymentVarious->fk_bank;
		$this->assertGreaterThan(0, $bankLineId, 'PaymentVarious::create() must have linked a bank line');

		$date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$date_end = dol_mktime(23, 59, 59, 12, 31, $year);

		$data = $journal->getDataForTreasury($user, $date_start, $date_end, 'notyet');
		$this->assertCount(1, $data['tabpay'], 'getDataForTreasury() should find exactly the 1 fixture bank line');

		$result = $journal->writeIntoBookkeepingForTreasury($user, $date_start, $date_end);
		$this->assertGreaterThan(0, $result, implode(',', $journal->errors));

		$sql = "SELECT numero_compte, debit, credit FROM ".MAIN_DB_PREFIX."accounting_bookkeeping";
		$sql .= " WHERE doc_type = 'bank' AND fk_doc = ".((int) $bankLineId);
		$sql .= " ORDER BY numero_compte";
		$res = $db->query($sql);
		$rows = array();
		while ($obj = $db->fetch_object($res)) {
			$rows[] = $obj;
		}
		$this->assertCount(2, $rows, 'Expected 2 bookkeeping rows (bank line, accountancy_code counterpart)');

		$byAccount = array();
		foreach ($rows as $row) {
			$byAccount[$row->numero_compte] = $row;
		}
		$this->assertArrayHasKey('512959', $byAccount);
		$this->assertEqualsWithDelta(75.0, (float) $byAccount['512959']->debit, 0.01);
		$this->assertArrayHasKey('627958', $byAccount, 'Counterpart must land on the PaymentVarious row\'s own accountancy_code');
		$this->assertEqualsWithDelta(75.0, (float) $byAccount['627958']->credit, 0.01);
	}
}
