<?php
/* Copyright (C) 2007-2010	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2007-2010	Jean Heimburger				<jean@tiaris.info>
 * Copyright (C) 2011		Juanjo Menent				<jmenent@2byte.es>
 * Copyright (C) 2012		Regis Houssin				<regis.houssin@inodbox.com>
 * Copyright (C) 2013		Christophe Battarel			<christophe.battarel@altairis.fr>
 * Copyright (C) 2013-2022	Open-DSI					<support@open-dsi.fr>
 * Copyright (C) 2013-2025	Alexandre Spangaro			<alexandre@inovea-conseil.com>
 * Copyright (C) 2013-2014	Florian Henry				<florian.henry@open-concept.pro>
 * Copyright (C) 2013-2014	Olivier Geffroy				<jeff@jeffinfo.com>
 * Copyright (C) 2017-2025  Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2018		Ferran Marcet				<fmarcet@2byte.es>
 * Copyright (C) 2018-2024	Eric Seigne					<eric.seigne@cap-rel.fr>
 * Copyright (C) 2021		Gauthier VERDOL				<gauthier.verdol@atm-consulting.fr>
 * Copyright (C) 2024-2025	MDW							<mdeweerd@users.noreply.github.com>
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
 */

/**
 *  \file       htdocs/accountancy/journal/bankjournal.php
 *  \ingroup    Accountancy (Double entries)
 *  \brief      Page with bank journal
 */
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/report.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/accounting.lib.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingjournal.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/chargesociales.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/don/class/don.class.php';
require_once DOL_DOCUMENT_ROOT.'/don/class/paymentdonation.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/tva.class.php';
require_once DOL_DOCUMENT_ROOT.'/salaries/class/paymentsalary.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/bookkeeping.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/client.class.php';
require_once DOL_DOCUMENT_ROOT.'/expensereport/class/expensereport.class.php';
require_once DOL_DOCUMENT_ROOT.'/expensereport/class/paymentexpensereport.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/loan/class/loan.class.php';
require_once DOL_DOCUMENT_ROOT.'/loan/class/paymentloan.class.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/subscription.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array("companies", "other", "compta", "banks", "bills", "donations", "loan", "accountancy", "trips", "salaries", "hrm", "members"));

// Multi journal
$id_journal = GETPOSTINT('id_journal');

$date_startmonth = GETPOSTINT('date_startmonth');
$date_startday = GETPOSTINT('date_startday');
$date_startyear = GETPOSTINT('date_startyear');
$date_endmonth = GETPOSTINT('date_endmonth');
$date_endday = GETPOSTINT('date_endday');
$date_endyear = GETPOSTINT('date_endyear');
$in_bookkeeping = GETPOST('in_bookkeeping', 'aZ09');

$only_rappro = GETPOSTINT('only_rappro');
if ($only_rappro == 0) {
	//GET page for the first time, use default settings
	$only_rappro = getDolGlobalInt('ACCOUNTING_BANK_CONCILIATED');
}

$now = dol_now();

$action = GETPOST('action', 'aZ09');

if ($in_bookkeeping == '') {
	$in_bookkeeping = 'notyet';
}


// Security check
if (!isModEnabled('accounting')) {
	accessforbidden();
}
if ($user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('accounting', 'bind', 'write')) {
	accessforbidden();
}


/*
 * Actions
 */

$error = 0;

$date_start = dol_mktime(0, 0, 0, $date_startmonth, $date_startday, $date_startyear);
$date_end = dol_mktime(23, 59, 59, $date_endmonth, $date_endday, $date_endyear);

$pastmonth = null;  // Initialise for static analysis  (could be really unseg)
$pastmonthyear = null;

if (empty($date_startmonth)) {
	// Period by default on transfer
	$dates = getDefaultDatesForTransfer();
	$date_start = $dates['date_start'];
	$pastmonthyear = $dates['pastmonthyear'];
	$pastmonth = $dates['pastmonth'];
}
if (empty($date_endmonth)) {
	// Period by default on transfer
	$dates = getDefaultDatesForTransfer();
	$date_end = $dates['date_end'];
	$pastmonthyear = $dates['pastmonthyear'];
	$pastmonth = $dates['pastmonth'];
}

if (!GETPOSTISSET('date_startmonth') && (empty($date_start) || empty($date_end))) { // We define date_start and date_end, only if we did not submit the form
	$date_start = dol_get_first_day((int) $pastmonthyear, (int) $pastmonth, false);
	$date_end = dol_get_last_day((int) $pastmonthyear, (int) $pastmonth, false);
}

// Get all bank lines
//-------------------------------------
$accountingjournalstatic = new AccountingJournal($db);
$accountingjournalstatic->fetch($id_journal);
$journal = $accountingjournalstatic->code;
$journal_label = $accountingjournalstatic->label;

$data = $accountingjournalstatic->getDataForBank($user, $date_start, $date_end, $in_bookkeeping, $only_rappro);
$tabpay = $data['tabpay'];
$tabbq = $data['tabbq'];
$tabtp = $data['tabtp'];
$tabcompany = $data['tabcompany'];
$tabuser = $data['tabuser'];
$tabtype = $data['tabtype'];
$tabmoreinfo = $data['tabmoreinfo'];
$account_supplier = $data['account_supplier'];
$account_customer = $data['account_customer'];
$account_employee = $data['account_employee'];
$account_transfer = $data['account_transfer'];

// Write bookkeeping
if (!$error && $action == 'writebookkeeping' && $user->hasRight('accounting', 'bind', 'write')) {
	$result = $accountingjournalstatic->writeIntoBookkeepingForBank($user, $date_start, $date_end);
	$error = ($result < 0) ? abs($result) : 0;

	if (empty($error) && count($tabpay) > 0) {
		setEventMessages($langs->trans("GeneralLedgerIsWritten"), null, 'mesgs');
	} elseif (count($tabpay) == $error) {
		setEventMessages($langs->trans("NoNewRecordSaved"), null, 'warnings');
	} else {
		setEventMessages($langs->trans("GeneralLedgerSomeRecordWasNotRecorded"), null, 'warnings');
	}

	$action = '';

	// Must reload data, so we make a redirect
	if (count($tabpay) != $error) {
		$param = 'id_journal='.$id_journal;
		$param .= '&date_startday='.$date_startday;
		$param .= '&date_startmonth='.$date_startmonth;
		$param .= '&date_startyear='.$date_startyear;
		$param .= '&date_endday='.$date_endday;
		$param .= '&date_endmonth='.$date_endmonth;
		$param .= '&date_endyear='.$date_endyear;
		$param .= '&in_bookkeeping='.$in_bookkeeping;
		header("Location: ".$_SERVER['PHP_SELF'].($param ? '?'.$param : ''));
		exit;
	}
}



// Export
if ($action == 'exportcsv' && $user->hasRight('accounting', 'bind', 'write')) {		// ISO and not UTF8 !
	$sep = getDolGlobalString('ACCOUNTING_EXPORT_SEPARATORCSV');

	$filename = 'journal';
	$type_export = 'journal';
	include DOL_DOCUMENT_ROOT.'/accountancy/tpl/export_journal.tpl.php';

	// CSV header line
	print '"'.$langs->transnoentitiesnoconv("BankId").'"'.$sep;
	print '"'.$langs->transnoentitiesnoconv("Date").'"'.$sep;
	print '"'.$langs->transnoentitiesnoconv("PaymentMode").'"'.$sep;
	print '"'.$langs->transnoentitiesnoconv("AccountAccounting").'"'.$sep;
	print '"'.$langs->transnoentitiesnoconv("SubledgerAccount").'"'.$sep;
	print '"'.$langs->transnoentitiesnoconv("Label").'"'.$sep;
	print '"'.$langs->transnoentitiesnoconv("AccountingDebit").'"'.$sep;
	print '"'.$langs->transnoentitiesnoconv("AccountingCredit").'"'.$sep;
	print '"'.$langs->transnoentitiesnoconv("Journal").'"'.$sep;
	print '"'.$langs->transnoentitiesnoconv("Note").'"'.$sep;
	print "\n";

	foreach ($tabpay as $key => $val) {
		$date = dol_print_date($val["date"], 'day');

		$ref = $accountingjournalstatic->getSourceDocRefForBank($val, $tabtype[$key]);

		// Bank
		foreach ($tabbq[$key] as $k => $mt) {
			if ($mt) {
				$reflabel = '';
				if (!empty($val['lib'])) {
					$reflabel .= dol_string_nohtmltag($val['lib'])." / ";
				}
				$reflabel .= $langs->trans("Bank").' '.dol_string_nohtmltag($val['bank_account_ref']);
				if (!empty($val['soclib'])) {
					$reflabel .= " / ".dol_string_nohtmltag($val['soclib']);
				}

				print '"'.$key.'"'.$sep;
				print '"'.$date.'"'.$sep;
				print '"'.$val["type_payment"].'"'.$sep;
				print '"'.length_accountg(html_entity_decode($k)).'"'.$sep;
				print "  ".$sep;
				print '"'.$reflabel.'"'.$sep;
				print '"'.($mt >= 0 ? price($mt) : '').'"'.$sep;
				print '"'.($mt < 0 ? price(-$mt) : '').'"'.$sep;
				print '"'.$journal.'"'.$sep;
				print '"'.dol_string_nohtmltag($ref).'"'.$sep;
				print "\n";
			}
		}

		// Third party
		if (is_array($tabtp[$key])) {
			foreach ($tabtp[$key] as $k => $mt) {
				if ($mt) {
					$reflabel = '';
					if (!empty($val['lib'])) {
						$reflabel .= dol_string_nohtmltag($val['lib']).(!empty($val['soclib']) ? " / " : "");
					}
					if ($tabtype[$key] == 'banktransfert') {
						$reflabel .= dol_string_nohtmltag($langs->transnoentitiesnoconv('TransitionalAccount').' '.$account_transfer);
					} else {
						$reflabel .= dol_string_nohtmltag($val['soclib'] ?? '');
					}

					print '"'.$key.'"'.$sep;
					print '"'.$date.'"'.$sep;
					print '"'.$val["type_payment"].'"'.$sep;
					if ($tabtype[$key] == 'payment_supplier') {
						$account_ledger = (!empty($tabcompany[$key]['accountancy_code_general'])) ? $tabcompany[$key]['accountancy_code_general'] : $account_supplier;
						print '"'.length_accountg($account_ledger).'"'.$sep;
					} elseif ($tabtype[$key] == 'payment') {
						$account_ledger = (!empty($tabcompany[$key]['accountancy_code_general'])) ? $tabcompany[$key]['accountancy_code_general'] : $account_customer;
						print '"'.length_accountg($account_ledger).'"'.$sep;
					} elseif ($tabtype[$key] == 'payment_expensereport') {
						print '"'.length_accountg(getDolGlobalString('ACCOUNTING_ACCOUNT_EXPENSEREPORT')).'"'.$sep;
					} elseif ($tabtype[$key] == 'payment_salary') {
						$account_ledger = (!empty($tabuser[$key]['accountancy_code_general'])) ? $tabuser[$key]['accountancy_code_general'] : $account_employee;
						print '"'.length_accountg($account_ledger).'"'.$sep;
					} else {
						print '"'.length_accountg(html_entity_decode($k)).'"'.$sep;
					}
					print '"'.length_accounta(html_entity_decode($k)).'"'.$sep;
					print '"'.$reflabel.'"'.$sep;
					print '"'.($mt < 0 ? price(-$mt) : '').'"'.$sep;
					print '"'.($mt >= 0 ? price($mt) : '').'"'.$sep;
					print '"'.$journal.'"'.$sep;
					print '"'.dol_string_nohtmltag($ref).'"'.$sep;
					print "\n";
				}
			}
		} else {	// If thirdparty unknown, output the waiting account
			foreach ($tabbq[$key] as $k => $mt) {
				if ($mt) {
					$reflabel = '';
					if (!empty($val['lib'])) {
						$reflabel .= dol_string_nohtmltag($val['lib'])." / ";
					}
					$reflabel .= dol_string_nohtmltag('WaitingAccount');

					print '"'.$key.'"'.$sep;
					print '"'.$date.'"'.$sep;
					print '"'.$val["type_payment"].'"'.$sep;
					print '"'.length_accountg(getDolGlobalString('ACCOUNTING_ACCOUNT_SUSPENSE')).'"'.$sep;
					print $sep;
					print '"'.$reflabel.'"'.$sep;
					print '"'.($mt < 0 ? price(-$mt) : '').'"'.$sep;
					print '"'.($mt >= 0 ? price($mt) : '').'"'.$sep;
					print '"'.$journal.'"'.$sep;
					print '"'.dol_string_nohtmltag($ref).'"'.$sep;
					print "\n";
				}
			}
		}
	}
}


/*
 * View
 */

$form = new Form($db);

if (empty($action) || $action == 'view') {
	$invoicestatic = new Facture($db);
	$invoicesupplierstatic = new FactureFournisseur($db);
	$expensereportstatic = new ExpenseReport($db);
	$vatstatic = new Tva($db);
	$donationstatic = new Don($db);
	$loanstatic = new Loan($db);
	$salarystatic = new Salary($db);
	$variousstatic = new PaymentVarious($db);

	$title = $langs->trans("GenerationOfAccountingEntries").' - '.$accountingjournalstatic->getNomUrl(0, 2, 1, '', 1);
	$help_url = 'EN:Module_Double_Entry_Accounting|FR:Module_Comptabilit&eacute;_en_Partie_Double#G&eacute;n&eacute;ration_des_&eacute;critures_en_comptabilit&eacute;';
	llxHeader('', dol_string_nohtmltag($title), $help_url, '', 0, 0, '', '', '', 'mod-accountancy accountancy-generation page-bankjournal');

	$nom = $title;
	$builddate = dol_now();
	//$description = $langs->trans("DescFinanceJournal") . '<br>';
	$description = $langs->trans("DescJournalOnlyBindedVisible").'<br>';

	$listofchoices = array(
		'notyet' => $langs->trans("NotYetInGeneralLedger"),
		'already' => $langs->trans("AlreadyInGeneralLedger")
	);
	$period = $form->selectDate($date_start ? $date_start : -1, 'date_start', 0, 0, 0, '', 1, 0).' - '.$form->selectDate($date_end ? $date_end : -1, 'date_end', 0, 0, 0, '', 1, 0);
	$period .= '<span class="valignmiddle"> -  '.$langs->trans("JournalizationInLedgerStatus").' </span>'.$form->selectarray('in_bookkeeping', $listofchoices, $in_bookkeeping, 1, 0, 0, '', 0, 0, 0, '', 'minwidth75 valignmiddle');

	$varlink = 'id_journal='.$id_journal;
	$periodlink = '';
	$exportlink = '';

	$listofchoices = array(
		1 => $langs->trans("TransfertAllBankLines"),
		2 => $langs->trans("TransfertOnlyConciliatedBankLine")
	);
	$moreoptions = [ "BankLineConciliated" => $form->selectarray('only_rappro', $listofchoices, $only_rappro, 0, 0, 0, '', 0, 0, 0, '', 'minwidth75 valignmiddle')];

	journalHead($nom, '', $period, $periodlink, $description, $builddate, $exportlink, array('action' => ''), '', $varlink, $moreoptions);

	$desc = '';

	if (getDolGlobalString('ACCOUNTANCY_FISCAL_PERIOD_MODE') != 'blockedonclosed') {
		// Test that setup is complete (we are in accounting, so test on entity is always on $conf->entity only, no sharing allowed)
		// Fiscal period test
		$sql = "SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX."accounting_fiscalyear WHERE entity = ".((int) $conf->entity);
		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			if ($obj->nb == 0) {
				print '<br><div class="warning">'.img_warning().' '.$langs->trans("TheFiscalPeriodIsNotDefined");
				$desc = ' : '.$langs->trans("AccountancyAreaDescFiscalPeriod", 4, '{link}');
				$desc = str_replace('{link}', '<strong>'.$langs->transnoentitiesnoconv("MenuAccountancy").'-'.$langs->transnoentitiesnoconv("Setup")."-".$langs->transnoentitiesnoconv("FiscalPeriod").'</strong>', $desc);
				print $desc;
				print '</div>';
			}
		} else {
			dol_print_error($db);
		}
	}

	// Bank test
	$sql = "SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX."bank_account WHERE entity = ".((int) $conf->entity)." AND fk_accountancy_journal IS NULL AND clos=0";
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj->nb > 0) {
			print '<br><div class="warning">'.img_warning().' '.$langs->trans("TheJournalCodeIsNotDefinedOnSomeBankAccount");
			$desc = ' : '.$langs->trans("AccountancyAreaDescBank", 6, '{link}');
			$desc = str_replace('{link}', '<strong>'.$langs->transnoentitiesnoconv("MenuAccountancy").'-'.$langs->transnoentitiesnoconv("Setup")."-".$langs->transnoentitiesnoconv("BankAccounts").'</strong>', $desc);
			print $desc;
			print '</div>';
		}
	} else {
		dol_print_error($db);
	}


	// Button to write into Ledger
	if (getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER') == "" || getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER') == '-1'
		|| getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER') == "" || getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER') == '-1'
		|| (isModEnabled("salaries") && (getDolGlobalString('SALARIES_ACCOUNTING_ACCOUNT_PAYMENT') == "" || getDolGlobalString('SALARIES_ACCOUNTING_ACCOUNT_PAYMENT') == '-1'))
		|| (isModEnabled("expensereport") && (getDolGlobalString('ACCOUNTING_ACCOUNT_EXPENSEREPORT') == "" || getDolGlobalString('ACCOUNTING_ACCOUNT_EXPENSEREPORT') == '-1'))) {


		print($desc ? '' : '<br>').'<div class="warning">'.img_warning().' '.$langs->trans("SomeMandatoryStepsOfSetupWereNotDone");
		$desc = ' : '.$langs->trans("AccountancyAreaDescMisc", 4, '{link}');
		$desc = str_replace('{link}', '<strong>'.$langs->transnoentitiesnoconv("MenuAccountancy").'-'.$langs->transnoentitiesnoconv("Setup")."-".$langs->transnoentitiesnoconv("MenuDefaultAccounts").'</strong>', $desc);
		print $desc;
		print '</div>';
	}


	print '<br><div class="tabsAction tabsActionNoBottom centerimp">';

	if (getDolGlobalString('ACCOUNTING_ENABLE_EXPORT_DRAFT_JOURNAL') && $in_bookkeeping == 'notyet') {
		print '<input type="button" class="butAction" name="exportcsv" value="'.$langs->trans("ExportDraftJournal").'" onclick="launch_export();" />';
	}

	if (getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER') == "" || getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER') == '-1'
		|| getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER') == "" || getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER') == '-1') {
		print '<input type="button" class="butActionRefused classfortooltip" title="'.dol_escape_htmltag($langs->trans("SomeMandatoryStepsOfSetupWereNotDone")).'" value="'.$langs->trans("WriteBookKeeping").'" />';
	} else {
		if ($in_bookkeeping == 'notyet') {
			print '<input type="button" class="butAction" name="writebookkeeping" value="'.$langs->trans("WriteBookKeeping").'" onclick="writebookkeeping();" />';
		} else {
			print '<a class="butActionRefused classfortooltip" name="writebookkeeping">'.$langs->trans("WriteBookKeeping").'</a>';
		}
	}

	print '</div>';

	// TODO Avoid using js. We can use a direct link with $param
	print '
	<script type="text/javascript">
		function launch_export() {
			console.log("Set value into form and submit");
			$("div.fiche form input[name=\"action\"]").val("exportcsv");
			$("div.fiche form input[type=\"submit\"]").click();
			$("div.fiche form input[name=\"action\"]").val("");
		}
		function writebookkeeping() {
			console.log("Set value into form and submit");
			$("div.fiche form input[name=\"action\"]").val("writebookkeeping");
			$("div.fiche form input[type=\"submit\"]").click();
			$("div.fiche form input[name=\"action\"]").val("");
		}
	</script>';

	/*
	 * Show result array
	 */
	print '<br>';

	$i = 0;
	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print "<td>".$langs->trans("Date")."</td>";
	print "<td>".$langs->trans("Piece").' ('.$langs->trans("ObjectsRef").")</td>";
	print "<td>".$langs->trans("AccountAccounting")."</td>";
	print "<td>".$langs->trans("SubledgerAccount")."</td>";
	print "<td>".$langs->trans("LabelOperation")."</td>";
	print '<td class="center">'.$langs->trans("PaymentMode")."</td>";
	print '<td class="right">'.$langs->trans("AccountingDebit")."</td>";
	print '<td class="right">'.$langs->trans("AccountingCredit")."</td>";
	print "</tr>\n";

	foreach ($tabpay as $key => $val) {			  // $key is rowid in llx_bank
		$date = dol_print_date($val["date"], 'day');

		$ref = $accountingjournalstatic->getSourceDocRefForBank($val, $tabtype[$key]);

		// Bank
		foreach ($tabbq[$key] as $k => $mt) {
			if ($mt) {
				$reflabel = '';
				if (!empty($val['lib'])) {
					$reflabel .= $val['lib']." / ";
				}
				$reflabel .= $langs->trans("Bank").' '.$val['bank_account_ref'];
				if (!empty($val['soclib'])) {
					$reflabel .= " / ".$val['soclib'];
				}

				//var_dump($tabpay[$key]);
				print '<!-- Bank bank.rowid='.$key.'=accounting_bookkeeping.fk_doc (accounting_bookkeeping.doc_type=\'bank\') type='.$tabpay[$key]['type'].' ref='.$tabpay[$key]['ref'].' -->';
				print '<tr class="oddeven">';

				// Date
				print "<td>".$date."</td>";

				// Ref
				print '<td class="maxwidth300 nopaddingtopimp nopaddingbottomimp">'.dol_escape_htmltag($ref)."</td>";

				// Ledger account
				$accounttoshow = length_accountg($k);
				if (empty($accounttoshow) || $accounttoshow == 'NotDefined') {
					$accounttoshow = '<span class="error">'.$langs->trans("BankAccountNotDefined").'</span>';
				}
				print '<td class="maxwidth300" title="'.dol_escape_htmltag(dol_string_nohtmltag($accounttoshow)).'">';
				print $accounttoshow;
				print "</td>";

				// Subledger account
				print '<td class="maxwidth300">';
				/*$accounttoshow = length_accountg($k);
				if (empty($accounttoshow) || $accounttoshow == 'NotDefined')
				{
					print '<span class="error">'.$langs->trans("BankAccountNotDefined").'</span>';
				}
				else print $accounttoshow;*/
				print "</td>";

				// Label operation
				print '<td class="maxwidth300 nopaddingtopimp nopaddingbottomimp">';
				print $reflabel;	// This is already html escaped content
				print "</td>";

				print '<td class="center">'.$val["type_payment"]."</td>";
				print '<td class="right nowraponall amount">'.($mt >= 0 ? price($mt) : '')."</td>";
				print '<td class="right nowraponall amount">'.($mt < 0 ? price(-$mt) : '')."</td>";
				print "</tr>";

				$i++;
			}
		}

		// Third party
		if (is_array($tabtp[$key])) {
			foreach ($tabtp[$key] as $k => $mt) {
				if ($mt) {
					$reflabel = '';
					if (!empty($val['lib'])) {
						$reflabel .= $val['lib'].(!empty($val['soclib']) ? " / " : "");
					}
					if ($tabtype[$key] == 'banktransfert') {
						$reflabel .= $langs->trans('TransitionalAccount').' '.$account_transfer;
					} else {
						$reflabel .= isset($val['soclib']) ? $val['soclib'] : "";
					}

					print '<!-- Thirdparty bank.rowid='.$key.'=accounting_bookkeeping.fk_doc (accounting_bookkeeping.doc_type=\'bank\') type='.$tabpay[$key]['type'].' ref='.$tabpay[$key]['ref'].' -->';
					print '<tr class="oddeven">';

					// Date
					print "<td>".$date."</td>";

					// Ref / Piece
					print '<td class="nopaddingtopimp nopaddingbottomimp">'.dol_escape_htmltag($ref)."</td>";


					// Ledger account
					$account_ledger = $k;
					// Try to force general ledger account depending on type
					if ($tabtype[$key] == 'payment') {
						$account_ledger = (!empty($obj->accountancy_code_customer_general)) ? $obj->accountancy_code_customer_general : $account_customer;
					}
					if ($tabtype[$key] == 'payment_supplier') {
						$account_ledger = (!empty($obj->accountancy_code_supplier_general)) ? $obj->accountancy_code_supplier_general : $account_supplier;
					}
					if ($tabtype[$key] == 'payment_expensereport') {
						$account_ledger = getDolGlobalString('ACCOUNTING_ACCOUNT_EXPENSEREPORT');
					}
					if ($tabtype[$key] == 'payment_salary') {
						$account_ledger = (!empty($obj->accountancy_code_user_general)) ? $obj->accountancy_code_user_general : $account_employee;
					}
					if ($tabtype[$key] == 'payment_vat') {
						$account_ledger = getDolGlobalString('ACCOUNTING_VAT_PAY_ACCOUNT');
					}
					if ($tabtype[$key] == 'member') {
						$account_ledger = getDolGlobalString('ADHERENT_SUBSCRIPTION_ACCOUNTINGACCOUNT');
					}
					if ($tabtype[$key] == 'payment_various') {
						$account_ledger = $tabpay[$key]["account_various"];
					}
					$accounttoshow = length_accountg($account_ledger);
					if (empty($accounttoshow) || $accounttoshow == 'NotDefined') {
						if ($tabtype[$key] == 'unknown') {
							// We will accept writing, but into a waiting account
							if (!getDolGlobalString('ACCOUNTING_ACCOUNT_SUSPENSE') || getDolGlobalString('ACCOUNTING_ACCOUNT_SUSPENSE') == '-1') {
								$accounttoshow = '<span class="error small">'.$langs->trans('UnknownAccountForThirdpartyAndWaitingAccountNotDefinedBlocking').'</span>';
							} else {
								$accounttoshow = '<span class="warning small">'.$langs->trans('UnknownAccountForThirdparty', length_accountg(getDolGlobalString('ACCOUNTING_ACCOUNT_SUSPENSE'))).'</span>'; // We will use a waiting account
							}
						} else {
							// We will refuse writing
							$errorstring = 'UnknownAccountForThirdpartyBlocking';
							if ($tabtype[$key] == 'payment') {
								$errorstring = 'MainAccountForCustomersNotDefined';
							}
							if ($tabtype[$key] == 'payment_supplier') {
								$errorstring = 'MainAccountForSuppliersNotDefined';
							}
							if ($tabtype[$key] == 'payment_expensereport') {
								$errorstring = 'MainAccountForUsersNotDefined';
							}
							if ($tabtype[$key] == 'payment_salary') {
								$errorstring = 'MainAccountForUsersNotDefined';
							}
							if ($tabtype[$key] == 'payment_vat') {
								$errorstring = 'MainAccountForVatPaymentNotDefined';
							}
							if ($tabtype[$key] == 'member') {
								$errorstring = 'MainAccountForSubscriptionPaymentNotDefined';
							}
							$accounttoshow = '<span class="error small">'.$langs->trans($errorstring).'</span>';
						}
					}
					print '<td class="maxwidth300" title="'.dol_escape_htmltag(dol_string_nohtmltag($accounttoshow)).'">';
					print $accounttoshow;	// This is a HTML string
					print "</td>";

					// Subledger account
					$accounttoshowsubledger = '';
					if (in_array($tabtype[$key], array('payment', 'payment_supplier', 'payment_expensereport', 'payment_salary', 'payment_various'))) {	// Type of payments that uses a subledger
						$accounttoshowsubledger = length_accounta($k);
						if ($accounttoshow != $accounttoshowsubledger) {
							if (empty($accounttoshowsubledger) || $accounttoshowsubledger == 'NotDefined') {
								//print '<span class="error">'.$langs->trans("ThirdpartyAccountNotDefined").'</span>';
								if (!empty($tabcompany[$key]['code_compta'])) {
									if (in_array($tabtype[$key], array('payment_various'))) {
										// For such case, if subledger is not defined, we won't use subledger accounts.
										$accounttoshowsubledger = '<span class="warning small twolinesmax">'.$langs->trans("ThirdpartyAccountNotDefinedOrThirdPartyUnknownSubledgerIgnored").'</span>';
									} elseif (in_array($tabtype[$key], array('payment_salary'))) {
										$accounttoshowsubledger = '<span class="warning small twolinesmax">'.$langs->trans("ThirdpartyAccountNotDefinedOrThirdPartyUnknownSubledgerIgnored2").'</span>';
									} else {
										$accounttoshowsubledger = '<span class="warning small twolinesmax">'.$langs->trans("ThirdpartyAccountNotDefinedOrThirdPartyUnknown", $tabcompany[$key]['code_compta']).'</span>';
									}
								} else {
									$accounttoshowsubledger = '<span class="error small twolinesmax">'.$langs->trans("ThirdpartyAccountNotDefinedOrThirdPartyUnknownBlocking").'</span>';
								}
							}
						} else {
							$accounttoshowsubledger = '';
						}
					}
					print '<td class="maxwidth300 nopaddingtopimp nopaddingbottomimp" title="'.dolPrintHTMLForAttribute(dol_string_nohtmltag($accounttoshowsubledger)).'">';
					print $accounttoshowsubledger;	// This is a html string
					print "</td>";

					// Label operation
					print '<td class="nopaddingtopimpo paddingbottomimp">';
					print $reflabel;		// This is a html string
					print "</td>";

					print '<td class="center">'.$val["type_payment"]."</td>";

					print '<td class="right nowraponall amount">'.($mt < 0 ? price(-$mt) : '')."</td>";

					print '<td class="right nowraponall amount">'.($mt >= 0 ? price($mt) : '')."</td>";

					print "</tr>";

					$i++;
				}
			}
		} else {	// Waiting account
			foreach ($tabbq[$key] as $k => $mt) {
				if ($mt) {
					$reflabel = '';
					if (!empty($val['lib'])) {
						$reflabel .= $val['lib']." / ";
					}
					$reflabel .= 'WaitingAccount';

					print '<!-- Wait bank.rowid='.$key.' -->';
					print '<tr class="oddeven">';
					print "<td>".$date."</td>";
					print "<td>".$ref."</td>";
					// Ledger account
					print "<td>";
					/*if (empty($accounttoshow) || $accounttoshow == 'NotDefined')
					{
						print '<span class="error">'.$langs->trans("WaitAccountNotDefined").'</span>';
					}
					else */
					print length_accountg(getDolGlobalString('ACCOUNTING_ACCOUNT_SUSPENSE'));
					print "</td>";
					// Subledger account
					print "<td>";
					print "</td>";
					print "<td>".dol_escape_htmltag($reflabel)."</td>";
					print '<td class="center">'.$val["type_payment"]."</td>";
					print '<td class="right nowraponall amount">'.($mt < 0 ? price(-$mt) : '')."</td>";
					print '<td class="right nowraponall amount">'.($mt >= 0 ? price($mt) : '')."</td>";
					print "</tr>";

					$i++;
				}
			}
		}
	}

	if (!$i) {
		$colspan = 8;
		print '<tr class="oddeven"><td colspan="'.$colspan.'"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
	}

	print "</table>";
	print '</div>';

	llxFooter();
}

$db->close();
