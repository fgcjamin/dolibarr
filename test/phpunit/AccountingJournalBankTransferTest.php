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
 *      \file       test/phpunit/AccountingJournalBankTransferTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

global $conf,$user,$langs,$db;
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/accountingjournal.class.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/bookkeeping.class.php';
require_once dirname(__FILE__).'/../../htdocs/compta/bank/class/account.class.php';
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
 * Class for PHPUnit tests of the bank journal transfer (Phase 3b, fourth slice), covering
 * AccountingJournal::getDataForBank()/writeIntoBookkeepingForBank() - the extraction of
 * accountancy/journal/bankjournal.php's former inline write logic.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class AccountingJournalBankTransferTest extends CommonClassTest
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
	 * Create a bank account linked to the nature=4 accounting journal.
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
		$bankAccount->label = 'AccountingJournalBankTransferTest '.$ref;
		$bankAccount->bank = 'AccountingJournalBankTransferTest bank';
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
	 * Fetch the nature=4 (bank) accounting journal.
	 *
	 * @param	DoliDB	$db		Database handler
	 * @return	AccountingJournal
	 */
	private function fetchBankJournal($db)
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
	 * journal, a validated customer invoice, and a payment recorded to that bank account (the
	 * 'payment' branch of the 11-way payment-type dispatch - the most common real-world case).
	 * Then run the full transfer via AccountingJournal::writeIntoBookkeepingForBank(), asserting
	 * the resulting bookkeeping rows match the expected accounts/amounts and that a second run
	 * is idempotent (no duplicate rows).
	 *
	 * @return void
	 */
	public function testWriteIntoBookkeepingForBank()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		// Use a far-future, non-overlapping year so this test doesn't collide with any seeded
		// or production fiscal year / invoice (sells uses 2951/2952, expense reports uses 2950,
		// purchases uses 2953/2954).
		$year = 2955;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingJournalBankTransferTest period '.$year;
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

		// Chart of accounts + default accounts config.
		$sql = "SELECT rowid, pcg_version FROM ".MAIN_DB_PREFIX."accounting_system WHERE pcg_version = 'PCG25-DEV'";
		$res = $db->query($sql);
		$chart = $db->fetch_object($res);
		$conf->global->CHARTOFACCOUNTS = (int) $chart->rowid;
		$conf->global->ACCOUNTING_ACCOUNT_CUSTOMER = '411999';

		$this->seedAccount($db, $chart->pcg_version, '411999', 'AccountingJournalBankTransferTest customer control');
		$this->seedAccount($db, $chart->pcg_version, '512955', 'AccountingJournalBankTransferTest bank');

		$journal = $this->fetchBankJournal($db);
		$bankAccount = $this->createBankAccount($db, $user, (int) $journal->id, 'ACJBK'.$year, '512955');

		// Fixture: customer + invoice, qty=1, up=100 (HT), vat=20% -> total_ttc=120.
		$dateLine = dol_mktime(12, 0, 0, 6, 15, $year);

		$soc = new Societe($db);
		$soc->name = 'AccountingJournalBankTransferTest customer';
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
		$lineId = $fac->addline('AccountingJournalBankTransferTest line', 100, 1, 20);
		$this->assertGreaterThan(0, $lineId, (string) $fac->error);

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
		$paiement->note_private = 'AccountingJournalBankTransferTest payment';
		$paiement->fk_account = $bankAccount->id;
		$paiementId = $paiement->create($user, 1, $thirdparty);
		$this->assertGreaterThan(0, $paiementId, implode(',', $paiement->errors));

		$bankLineId = $paiement->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $bankAccount->id, '', '');
		$this->assertGreaterThan(0, $bankLineId, implode(',', $paiement->errors));

		$date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$date_end = dol_mktime(23, 59, 59, 12, 31, $year);

		// Preview via getDataForBank() finds the fixture.
		$data = $journal->getDataForBank($user, $date_start, $date_end, 'notyet');
		$this->assertCount(1, $data['tabpay'], 'getDataForBank() should find exactly the 1 fixture bank line');
		$this->assertSame('payment', $data['tabtype'][$bankLineId]);

		// Write.
		$result = $journal->writeIntoBookkeepingForBank($user, $date_start, $date_end);
		$this->assertGreaterThan(0, $result, implode(',', $journal->errors));

		$sql = "SELECT numero_compte, subledger_account, debit, credit FROM ".MAIN_DB_PREFIX."accounting_bookkeeping";
		$sql .= " WHERE doc_type = 'bank' AND fk_doc = ".((int) $bankLineId);
		$sql .= " ORDER BY numero_compte";
		$res = $db->query($sql);
		$rows = array();
		while ($obj = $db->fetch_object($res)) {
			$rows[] = $obj;
		}
		$this->assertCount(2, $rows, 'Expected 2 bookkeeping rows (bank line, thirdparty)');

		// Full payment of a 120 TTC invoice: bank account is debited 120, customer subledger is
		// credited 120 - balanced.
		$byAccount = array();
		foreach ($rows as $row) {
			$byAccount[$row->numero_compte] = $row;
		}
		$this->assertArrayHasKey('512955', $byAccount);
		$this->assertEqualsWithDelta(120.0, (float) $byAccount['512955']->debit, 0.01);
		$this->assertArrayHasKey('411999', $byAccount);
		$this->assertEqualsWithDelta(120.0, (float) $byAccount['411999']->credit, 0.01);

		// Re-running must not duplicate rows: the already-recorded bank line is excluded by
		// getDataForBank()'s own 'notyet' filter (same pre-existing SQL behavior, unchanged by
		// the extraction), so the write loop simply finds nothing to do.
		$result2 = $journal->writeIntoBookkeepingForBank($user, $date_start, $date_end);
		$this->assertGreaterThanOrEqual(0, $result2, 'Re-running the transfer must not error');

		$res = $db->query($sql);
		$this->assertSame(2, $db->num_rows($res), 'Re-running the transfer must not duplicate bookkeeping rows');
	}

	/**
	 * A bank line with no resolvable bank_url links at all (e.g. a manual misc entry) must fall
	 * into the 'unknown' branch of the 11-way payment-type dispatch, landing on the configured
	 * suspense account (ACCOUNTING_ACCOUNT_SUSPENSE) rather than a real thirdparty/subledger
	 * account - a case none of the other 3 already-extracted journals exercise.
	 *
	 * @return void
	 */
	public function testUnknownTypeUsesSuspenseAccount()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$year = 2956;

		$period = new Fiscalyear($db);
		$period->label = 'AccountingJournalBankTransferTest suspense period '.$year;
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

		$sql = "SELECT rowid, pcg_version FROM ".MAIN_DB_PREFIX."accounting_system WHERE pcg_version = 'PCG25-DEV'";
		$res = $db->query($sql);
		$chart = $db->fetch_object($res);
		$conf->global->CHARTOFACCOUNTS = (int) $chart->rowid;
		$conf->global->ACCOUNTING_ACCOUNT_SUSPENSE = '471999';

		$this->seedAccount($db, $chart->pcg_version, '471999', 'AccountingJournalBankTransferTest suspense');
		$this->seedAccount($db, $chart->pcg_version, '512956', 'AccountingJournalBankTransferTest bank (suspense case)');

		$journal = $this->fetchBankJournal($db);
		$bankAccount = $this->createBankAccount($db, $user, (int) $journal->id, 'ACJBK'.$year, '512956');

		$dateLine = dol_mktime(12, 0, 0, 6, 15, $year);

		// A raw bank line with no bank_url links at all (no payment/company/user link) - the
		// same shape as an old record or a manual misc bank entry.
		$bankLineId = $bankAccount->addline($dateLine, 'VIR', 'AccountingJournalBankTransferTest unknown entry', 50, '', 0, $user);
		$this->assertGreaterThan(0, $bankLineId, (string) $bankAccount->error);

		$date_start = dol_mktime(0, 0, 0, 1, 1, $year);
		$date_end = dol_mktime(23, 59, 59, 12, 31, $year);

		$data = $journal->getDataForBank($user, $date_start, $date_end, 'notyet');
		$this->assertCount(1, $data['tabpay'], 'getDataForBank() should find exactly the 1 fixture bank line');
		$this->assertSame('unknown', $data['tabtype'][$bankLineId], 'A bank line with no bank_url links must be typed unknown');

		$result = $journal->writeIntoBookkeepingForBank($user, $date_start, $date_end);
		$this->assertGreaterThan(0, $result, implode(',', $journal->errors));

		$sql = "SELECT numero_compte, debit, credit FROM ".MAIN_DB_PREFIX."accounting_bookkeeping";
		$sql .= " WHERE doc_type = 'bank' AND fk_doc = ".((int) $bankLineId);
		$sql .= " ORDER BY numero_compte";
		$res = $db->query($sql);
		$rows = array();
		while ($obj = $db->fetch_object($res)) {
			$rows[] = $obj;
		}
		$this->assertCount(2, $rows, 'Expected 2 bookkeeping rows (bank line, suspense counterpart)');

		$byAccount = array();
		foreach ($rows as $row) {
			$byAccount[$row->numero_compte] = $row;
		}
		$this->assertArrayHasKey('512956', $byAccount);
		$this->assertEqualsWithDelta(50.0, (float) $byAccount['512956']->debit, 0.01);
		$this->assertArrayHasKey('471999', $byAccount, 'Counterpart must land on the configured suspense account');
		$this->assertEqualsWithDelta(50.0, (float) $byAccount['471999']->credit, 0.01);
	}
}
