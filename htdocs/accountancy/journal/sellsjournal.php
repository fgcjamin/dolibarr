<?php
/* Copyright (C) 2007-2010	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2007-2010	Jean Heimburger				<jean@tiaris.info>
 * Copyright (C) 2011		Juanjo Menent				<jmenent@2byte.es>
 * Copyright (C) 2012		Regis Houssin				<regis.houssin@inodbox.com>
 * Copyright (C) 2013		Christophe Battarel			<christophe.battarel@altairis.fr>
 * Copyright (C) 2013-2025	Alexandre Spangaro			<alexandre@inovea-conseil.com>
 * Copyright (C) 2013-2016	Florian Henry				<florian.henry@open-concept.pro>
 * Copyright (C) 2013-2016	Olivier Geffroy				<jeff@jeffinfo.com>
 * Copyright (C) 2014		Raphaël Doursenaud			<rdoursenaud@gpcsolutions.fr>
 * Copyright (C) 2018-2025  Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2024-2025	MDW							<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2025		Vincent de Grandporé        <vincent@de-grandpre.quebec>
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
 * \file		htdocs/accountancy/journal/sellsjournal.php
 * \ingroup		Accountancy (Double entries)
 * \brief		Page with sells journal
 */

// Load Dolibarr environment
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/report.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/accounting.lib.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingjournal.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/client.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/bookkeeping.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Societe $mysoc
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array("commercial", "compta", "bills", "other", "accountancy", "errors"));

$id_journal = GETPOSTINT('id_journal');
$action = GETPOST('action', 'aZ09');

$date_startmonth = GETPOSTINT('date_startmonth');
$date_startday = GETPOSTINT('date_startday');
$date_startyear = GETPOSTINT('date_startyear');
$date_endmonth = GETPOSTINT('date_endmonth');
$date_endday = GETPOSTINT('date_endday');
$date_endyear = GETPOSTINT('date_endyear');
$in_bookkeeping = GETPOST('in_bookkeeping');
if ($in_bookkeeping == '') {
	$in_bookkeeping = 'notyet';
}

$now = dol_now();

$hookmanager->initHooks(array('sellsjournal'));
$parameters = array();

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

$error = 0;

$tabfac = array();
$tabht = array();
$tabtva = array();
$tabwarranty = array();
$tabttc = array();
$tablocaltax1 = array();
$tablocaltax2 = array();

$cptcli = 'NotDefined';

/*
 * Actions
 */

$accountingaccount = new AccountingAccount($db);

// Get information of a journal
$accountingjournalstatic = new AccountingJournal($db);
$accountingjournalstatic->fetch($id_journal);
$journal = $accountingjournalstatic->code;
$journal_label = $accountingjournalstatic->label;

$date_start = dol_mktime(0, 0, 0, $date_startmonth, $date_startday, $date_startyear);
$date_end = dol_mktime(23, 59, 59, $date_endmonth, $date_endday, $date_endyear);

$pastmonth = null;  // Initialise, could be unset
$pastmonthyear = null;  // Initialise, could be unset

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
if (getDolGlobalString('ACCOUNTANCY_JOURNAL_USE_CURRENT_MONTH')) {
	$pastmonth += 1;
}

if (!GETPOSTISSET('date_startmonth') && (empty($date_start) || empty($date_end))) { // We define date_start and date_end, only if we did not submit the form
	$date_start = dol_get_first_day((int) $pastmonthyear, (int) $pastmonth, false);
	$date_end = dol_get_last_day((int) $pastmonthyear, (int) $pastmonth, false);
}

// Data collection (per-invoice aggregation, with all 5 hook points) is delegated to
// AccountingJournal, so the same logic is reusable from the REST API.
$dataforsells = $accountingjournalstatic->getDataForSells($user, $date_start, $date_end, $in_bookkeeping);
$tabfac = $dataforsells['tabfac'];
$tabht = $dataforsells['tabht'];
$tabtva = $dataforsells['tabtva'];
$def_tva = $dataforsells['def_tva'];
$tabwarranty = $dataforsells['tabwarranty'];
$tabrevenuestamp = $dataforsells['tabrevenuestamp'];
$tabttc = $dataforsells['tabttc'];
$tablocaltax1 = $dataforsells['tablocaltax1'];
$tablocaltax2 = $dataforsells['tablocaltax2'];
$tabcompany = $dataforsells['tabcompany'];
$errorforinvoice = $dataforsells['errorforinvoice'];
$error += $dataforsells['error']; // Matches original: too-many-lines during collection blocks the write action below.

// Still needed below (export/view sections read it directly).
$cptcli = getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER', 'NotDefined');

// Bookkeeping Write
if ($action == 'writebookkeeping' && !$error && $user->hasRight('accounting', 'bind', 'write')) {
	$result = $accountingjournalstatic->writeIntoBookkeepingForSells($user, $date_start, $date_end);
	if ($result < 0) {
		setEventMessages($accountingjournalstatic->error, $accountingjournalstatic->errors, 'errors');
	}
	$error = ($result < 0) ? abs($result) : 0;

	$tabpay = $tabfac;

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



/*
 * View
 */

$form = new Form($db);

// Export
if ($action == 'exportcsv' && !$error) {		// ISO and not UTF8 !
	// Note that to have the button to get this feature enabled, you must enable ACCOUNTING_ENABLE_EXPORT_DRAFT_JOURNAL
	$sep = getDolGlobalString('ACCOUNTING_EXPORT_SEPARATORCSV');

	$filename = 'journal';
	$type_export = 'journal';
	include DOL_DOCUMENT_ROOT.'/accountancy/tpl/export_journal.tpl.php';

	$companystatic = new Client($db);
	$invoicestatic = new Facture($db);
	$bookkeepingstatic = new BookKeeping($db);

	foreach ($tabfac as $key => $val) {
		$companystatic->id = $tabcompany[$key]['id'];
		$companystatic->name = $tabcompany[$key]['name'];
		$companystatic->accountancy_code_customer_general = (!empty($tabcompany[$key]['accountancy_code_customer_general']) && $tabcompany[$key]['accountancy_code_customer_general'] != '-1') ? $tabcompany[$key]['accountancy_code_customer_general'] : $cptcli;
		$companystatic->code_compta = $tabcompany[$key]['code_compta'];				// deprecated
		$companystatic->code_compta_client = $tabcompany[$key]['code_compta'];
		$companystatic->code_client = $tabcompany[$key]['code_client'];
		$companystatic->client = 3;

		$invoicestatic->id = $key;
		$invoicestatic->ref = (string) $val["ref"];
		$invoicestatic->type = $val["type"];
		$invoicestatic->close_code = $val["close_code"];

		$date = dol_print_date($val["date"], 'day');

		// Is it a replaced invoice? 0=not a replaced invoice, 1=replaced invoice not yet dispatched, 2=replaced invoice dispatched
		$replacedinvoice = 0;
		if ($invoicestatic->close_code == Facture::CLOSECODE_REPLACED) {
			$replacedinvoice = 1;
			$alreadydispatched = $invoicestatic->getVentilExportCompta(); // Test if replaced invoice already into bookkeeping.
			if ($alreadydispatched) {
				$replacedinvoice = 2;
			}
		}

		// If not already into bookkeeping, we won't add it. If yes, do nothing (should not happen because creating replacement not possible if invoice is accounted)
		if ($replacedinvoice == 1) {
			continue;
		}

		// Warranty
		if (getDolGlobalString('INVOICE_USE_RETAINED_WARRANTY') && isset($tabwarranty[$key])) {
			foreach ($tabwarranty[$key] as $k => $mt) {
				//if ($mt) {
				print '"'.$key.'"'.$sep;
				print '"'.$date.'"'.$sep;
				print '"'.$val["ref"].'"'.$sep;
				print '"'.csvClean(dol_trunc($companystatic->name, 32)).'"'.$sep;
				print '"'.length_accounta(html_entity_decode($k)).'"'.$sep;
				print '"'.length_accountg(getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER_RETAINED_WARRANTY')).'"'.$sep;
				print '"'.length_accounta(html_entity_decode($k)).'"'.$sep;
				print '"'.$langs->trans("ThirdParty").'"'.$sep;
				print '"'.csvClean($bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref, $langs->trans("RetainedWarranty"))).'"'.$sep;
				print '"'.($mt >= 0 ? price($mt) : '').'"'.$sep;
				print '"'.($mt < 0 ? price(-$mt) : '').'"'.$sep;
				print '"'.$journal.'"';
				print "\n";
				//}
			}
		}

		// Third party
		foreach ($tabttc[$key] as $k => $mt) {
			//if ($mt) {
			print '"'.$key.'"'.$sep;
			print '"'.$date.'"'.$sep;
			print '"'.$val["ref"].'"'.$sep;
			print '"'.csvClean(dol_trunc($companystatic->name, 32)).'"'.$sep;
			print '"'.length_accounta(html_entity_decode($k)).'"'.$sep;
			print '"'.length_accountg($companystatic->accountancy_code_customer_general).'"'.$sep;
			print '"'.length_accounta(html_entity_decode($k)).'"'.$sep;
			print '"'.$langs->trans("ThirdParty").'"'.$sep;
			print '"'.csvClean($bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref, $langs->trans("ThirdParty"))).'"'.$sep;
			print '"'.($mt >= 0 ? price($mt) : '').'"'.$sep;
			print '"'.($mt < 0 ? price(-$mt) : '').'"'.$sep;
			print '"'.$journal.'"';
			print "\n";
			//}
		}

		// Product / Service
		foreach ($tabht[$key] as $k => $mt) {
			$accountingaccount = new AccountingAccount($db);
			$accountingaccount->fetch(0, $k, true);
			//if ($mt) {
			print '"'.$key.'"'.$sep;
			print '"'.$date.'"'.$sep;
			print '"'.$val["ref"].'"'.$sep;
			print '"'.csvClean(dol_trunc($companystatic->name, 32)).'"'.$sep;
			print '"'.length_accountg(html_entity_decode($k)).'"'.$sep;
			print '"'.length_accountg(html_entity_decode($k)).'"'.$sep;
			print '""'.$sep;
			print '"'.csvClean(dol_trunc($accountingaccount->label, 32)).'"'.$sep;
			print '"'.csvClean($bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref, $accountingaccount->label)).'"'.$sep;
			print '"'.($mt < 0 ? price(-$mt) : '').'"'.$sep;
			print '"'.($mt >= 0 ? price($mt) : '').'"'.$sep;
			print '"'.$journal.'"';
			print "\n";
			//}
		}

		// VAT
		$listoftax = array(0, 1, 2);
		foreach ($listoftax as $numtax) {
			$arrayofvat = $tabtva;
			if ($numtax == 1) {
				$arrayofvat = $tablocaltax1;
			}
			if ($numtax == 2) {
				$arrayofvat = $tablocaltax2;
			}

			foreach ($arrayofvat[$key] as $k => $mt) {
				if ($mt) {
					print '"'.$key.'"'.$sep;
					print '"'.$date.'"'.$sep;
					print '"'.$val["ref"].'"'.$sep;
					print '"'.csvClean(dol_trunc($companystatic->name, 32)).'"'.$sep;
					print '"'.length_accountg(html_entity_decode($k)).'"'.$sep;
					print '"'.length_accountg(html_entity_decode($k)).'"'.$sep;
					print '""'.$sep;
					print '"'.$langs->trans("VAT").' - '.implode(', ', $def_tva[$key][$k]).' %"'.$sep;
					print '"'.csvClean($bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref, $langs->trans("VAT").implode($def_tva[$key][$k]).' %'.($numtax ? ' - Localtax '.$numtax : ''))).'"'.$sep;
					print '"'.($mt < 0 ? price(-$mt) : '').'"'.$sep;
					print '"'.($mt >= 0 ? price($mt) : '').'"'.$sep;
					print '"'.$journal.'"';
					print "\n";
				}
			}
		}

		// Revenue stamp
		if (isset($tabrevenuestamp[$key])) {
			foreach ($tabrevenuestamp[$key] as $k => $mt) {
				//if ($mt) {
				print '"'.$key.'"'.$sep;
				print '"'.$date.'"'.$sep;
				print '"'.$val["ref"].'"'.$sep;
				print '"'.csvClean(dol_trunc($companystatic->name, 32)).'"'.$sep;
				print '"'.length_accountg(html_entity_decode($k)).'"'.$sep;
				print '"'.length_accountg(html_entity_decode($k)).'"'.$sep;
				print '""'.$sep;
				print '"'.$langs->trans("RevenueStamp").'"'.$sep;
				print '"'.csvClean($bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref, $langs->trans("RevenueStamp"))).'"'.$sep;
				print '"'.($mt < 0 ? price(-$mt) : '').'"'.$sep;
				print '"'.($mt >= 0 ? price($mt) : '').'"'.$sep;
				print '"'.$journal.'"';
				print "\n";
				//}
			}
		}
	}
}



if (empty($action) || $action == 'view') {
	$title = $langs->trans("GenerationOfAccountingEntries").' - '.$accountingjournalstatic->getNomUrl(0, 2, 1, '', 1);
	$help_url = 'EN:Module_Double_Entry_Accounting|FR:Module_Comptabilit&eacute;_en_Partie_Double#G&eacute;n&eacute;ration_des_&eacute;critures_en_comptabilit&eacute;';
	llxHeader('', dol_string_nohtmltag($title), $help_url, '', 0, 0, '', '', '', 'mod-accountancy accountancy-generation page-sellsjournal');

	$nom = $title;
	$nomlink = '';
	$periodlink = '';
	$exportlink = '';
	$builddate = dol_now();
	$description = $langs->trans("DescJournalOnlyBindedVisible").'<br>';
	if (getDolGlobalString('FACTURE_DEPOSITS_ARE_JUST_PAYMENTS')) {
		$description .= $langs->trans("DepositsAreNotIncluded");
	} else {
		$description .= $langs->trans("DepositsAreIncluded");
	}

	$listofchoices = array('notyet' => $langs->trans("NotYetInGeneralLedger"), 'already' => $langs->trans("AlreadyInGeneralLedger"));
	$period = $form->selectDate($date_start ? $date_start : -1, 'date_start', 0, 0, 0, '', 1, 0).' - '.$form->selectDate($date_end ? $date_end : -1, 'date_end', 0, 0, 0, '', 1, 0);
	$period .= ' -  '.$langs->trans("JournalizationInLedgerStatus").' '.$form->selectarray('in_bookkeeping', $listofchoices, $in_bookkeeping, 1);

	$varlink = 'id_journal='.$id_journal;

	journalHead($nom, $nomlink, $period, $periodlink, $description, $builddate, $exportlink, array('action' => ''), '', $varlink);

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

	// Button to write into Ledger
	$acctCustomerNotConfigured = in_array(getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER'), ['','-1']);
	if ($acctCustomerNotConfigured) {
		print '<br><div class="warning">'.img_warning().' '.$langs->trans("SomeMandatoryStepsOfSetupWereNotDone");
		$desc = ' : '.$langs->trans("AccountancyAreaDescMisc", 4, '{link}');
		$desc = str_replace('{link}', '<strong>'.$langs->transnoentitiesnoconv("MenuAccountancy").'-'.$langs->transnoentitiesnoconv("Setup")."-".$langs->transnoentitiesnoconv("MenuDefaultAccounts").'</strong>', $desc);
		print $desc;
		print '</div>';
	}
	print '<br><div class="tabsAction tabsActionNoBottom centerimp">';
	if (getDolGlobalString('ACCOUNTING_ENABLE_EXPORT_DRAFT_JOURNAL') && $in_bookkeeping == 'notyet') {
		print '<input type="button" class="butAction" name="exportcsv" value="'.$langs->trans("ExportDraftJournal").'" onclick="launch_export();" />';
	}
	if ($acctCustomerNotConfigured) {
		print '<input type="button" class="butActionRefused classfortooltip" title="'.dol_escape_htmltag($langs->trans("SomeMandatoryStepsOfSetupWereNotDone")).'" value="'.$langs->trans("WriteBookKeeping").'" />';
	} else {
		if ($in_bookkeeping == 'notyet') {
			print '<input type="button" class="butAction" name="writebookkeeping" value="'.$langs->trans("WriteBookKeeping").'" onclick="writebookkeeping();" />';
		} else {
			print '<a href="#" class="butActionRefused classfortooltip" name="writebookkeeping">'.$langs->trans("WriteBookKeeping").'</a>';
		}
	}
	print '</div>';

	// TODO Avoid using js. We can use a direct link with $param
	print '
	<script type="text/javascript">
		function launch_export() {
			$("div.fiche form input[name=\"action\"]").val("exportcsv");
			$("div.fiche form input[type=\"submit\"]").click();
			$("div.fiche form input[name=\"action\"]").val("");
		}
		function writebookkeeping() {
			console.log("click on writebookkeeping");
			$("div.fiche form input[name=\"action\"]").val("writebookkeeping");
			$("div.fiche form input[type=\"submit\"]").click();
			$("div.fiche form input[name=\"action\"]").val("");
		}
	</script>';

	/*
	 * Show result array
	 */
	print '<br>';

	print '<div class="div-table-responsive">';
	print "<table class=\"noborder\" width=\"100%\">";
	print "<tr class=\"liste_titre\">";
	print "<td>".$langs->trans("Date")."</td>";
	print "<td>".$langs->trans("Piece").' ('.$langs->trans("InvoiceRef").")</td>";
	print "<td>".$langs->trans("AccountAccounting")."</td>";
	print "<td>".$langs->trans("SubledgerAccount")."</td>";
	print "<td>".$langs->trans("LabelOperation")."</td>";
	print '<td class="center">'.$langs->trans("AccountingDebit")."</td>";
	print '<td class="center">'.$langs->trans("AccountingCredit")."</td>";
	print "</tr>\n";

	$i = 0;

	$companystatic = new Client($db);
	$invoicestatic = new Facture($db);
	$bookkeepingstatic = new BookKeeping($db);

	foreach ($tabfac as $key => $val) {
		$companystatic->id = $tabcompany[$key]['id'];
		$companystatic->name = $tabcompany[$key]['name'];
		$companystatic->accountancy_code_customer_general = (!empty($tabcompany[$key]['accountancy_code_customer_general']) && $tabcompany[$key]['accountancy_code_customer_general'] != '-1') ? $tabcompany[$key]['accountancy_code_customer_general'] : $cptcli;
		$companystatic->code_compta = $tabcompany[$key]['code_compta'];
		$companystatic->code_compta_client = $tabcompany[$key]['code_compta'];
		$companystatic->code_client = $tabcompany[$key]['code_client'];
		$companystatic->client = 3;

		$invoicestatic->id = $key;
		$invoicestatic->ref = (string) $val["ref"];
		$invoicestatic->type = $val["type"];
		$invoicestatic->close_code = $val["close_code"];

		$date = dol_print_date($val["date"], 'day');

		// Is it a replaced invoice? 0=not a replaced invoice, 1=replaced invoice not yet dispatched, 2=replaced invoice dispatched
		$replacedinvoice = 0;
		if ($invoicestatic->close_code == Facture::CLOSECODE_REPLACED) {
			$replacedinvoice = 1;
			$alreadydispatched = $invoicestatic->getVentilExportCompta(); // Test if replaced invoice already into bookkeeping.
			if ($alreadydispatched) {
				$replacedinvoice = 2;
			}
		}

		// If not already into bookkeeping, we won't add it, if yes, add the counterpart ???.
		if ($replacedinvoice == 1) {
			print '<tr class="oddeven">';
			print "<!-- Replaced invoice -->";
			print "<td>".$date."</td>";
			print "<td><strike>".$invoicestatic->getNomUrl(1)."</strike></td>";
			// Account
			print "<td>";
			print $langs->trans("Replaced");
			print '</td>';
			// Subledger account
			print "<td>";
			print '</td>';
			print "<td>";
			print "</td>";
			print '<td class="right"></td>';
			print '<td class="right"></td>';
			print "</tr>";

			$i++;
			continue;
		}
		if (isset($errorforinvoice[$key]) && $errorforinvoice[$key] == 'somelinesarenotbound') {
			print '<tr class="oddeven">';
			print "<!-- Some lines are not bound -->";
			print "<td>".$date."</td>";
			print "<td>".$invoicestatic->getNomUrl(1)."</td>";
			// Account
			print "<td>";
			print '<span class="error">'.$langs->trans('ErrorInvoiceContainsLinesNotYetBoundedShort', $val['ref']).'</span>';
			print '</td>';
			// Subledger account
			print "<td>";
			print '</td>';
			print "<td>";
			print "</td>";
			print '<td class="right"></td>';
			print '<td class="right"></td>';
			print "</tr>";

			$i++;
		}

		// Warranty
		if (getDolGlobalString('INVOICE_USE_RETAINED_WARRANTY') && isset($tabwarranty[$key]) && is_array($tabwarranty[$key])) {
			foreach ($tabwarranty[$key] as $k => $mt) {
				print '<tr class="oddeven">';
				print "<!-- Thirdparty warranty -->";
				print "<td>" . $date . "</td>";
				print "<td>" . $invoicestatic->getNomUrl(1) . "</td>";
				// Account
				print "<td>";
				$accountoshow = length_accountg(getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER_RETAINED_WARRANTY'));
				if (($accountoshow == "") || $accountoshow == 'NotDefined') {
					print '<span class="error">' . $langs->trans("MainAccountForRetainedWarrantyNotDefined") . '</span>';
				} else {
					print $accountoshow;
				}
				print '</td>';
				// Subledger account
				print "<td>";
				$accountoshow = length_accounta($k);
				if (($accountoshow == "") || $accountoshow == 'NotDefined') {
					print '<span class="error">' . $langs->trans("ThirdpartyAccountNotDefined") . '</span>';
				} else {
					print $accountoshow;
				}
				print '</td>';
				print "<td>" . $bookkeepingstatic->accountingLabelForOperation($companystatic->getNomUrl(0), $invoicestatic->ref, $langs->trans("RetainedWarranty"), 1) . "</td>";
				print '<td class="right nowraponall amount">' . ($mt >= 0 ? price($mt) : '') . "</td>";
				print '<td class="right nowraponall amount">' . ($mt < 0 ? price(-$mt) : '') . "</td>";
				print "</tr>";
			}
		}

		// Third party
		foreach ($tabttc[$key] as $k => $mt) {
			print '<tr class="oddeven">';
			print "<!-- Thirdparty -->";
			print "<td>".$date."</td>";
			print "<td>".$invoicestatic->getNomUrl(1)."</td>";
			// Account
			print "<td>";
			$accountoshow = length_accountg($companystatic->accountancy_code_customer_general);
			if (($accountoshow == "") || $accountoshow == "-1" || $accountoshow == 'NotDefined') {
				print '<span class="error">'.$langs->trans("MainAccountForCustomersNotDefined").'</span>';
			} else {
				print $accountoshow;
			}
			print '</td>';
			// Subledger account
			print "<td>";
			$accountoshow = length_accounta($k);
			if (($accountoshow == "") || $accountoshow == 'NotDefined') {
				print '<span class="error">'.$langs->trans("ThirdpartyAccountNotDefined").'</span>';
			} else {
				print $accountoshow;
			}
			print '</td>';
			print "<td>" . $bookkeepingstatic->accountingLabelForOperation($companystatic->getNomUrl(0, 'customer'), $invoicestatic->ref, $langs->trans("SubledgerAccount"), 1) . "</td>";
			print '<td class="right nowraponall amount">'.($mt >= 0 ? price($mt) : '')."</td>";
			print '<td class="right nowraponall amount">'.($mt < 0 ? price(-$mt) : '')."</td>";
			print "</tr>";

			$i++;
		}

		// Product / Service
		foreach ($tabht[$key] as $k => $mt) {
			if (empty($conf->cache['accountingaccountincurrententity'][$k])) {
				$accountingaccount = new AccountingAccount($db);
				$accountingaccount->fetch(0, $k, true);
				$conf->cache['accountingaccountincurrententity'][$k] = $accountingaccount;
			} else {
				$accountingaccount = $conf->cache['accountingaccountincurrententity'][$k];
			}

			print '<tr class="oddeven">';
			print "<!-- Product -->";
			print "<td>".$date."</td>";
			print "<td>".$invoicestatic->getNomUrl(1)."</td>";
			// Account
			print "<td>";
			$accountoshow = length_accountg($k);
			if (($accountoshow == "") || $accountoshow == 'NotDefined') {
				print '<span class="error">'.$langs->trans("ProductNotDefined").'</span>';
			} else {
				print $accountoshow;
			}
			print "</td>";
			// Subledger account
			print "<td>";
			if (getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER_USE_AUXILIARY_ON_DEPOSIT')) {
				if ($k == getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER_DEPOSIT')) {
					print length_accounta($tabcompany[$key]['code_compta']);
				}
			} elseif (($accountoshow == "") || $accountoshow == 'NotDefined') {
				print '<span class="error">' . $langs->trans("ThirdpartyAccountNotDefined") . '</span>';
			}
			print '</td>';
			$companystatic->id = $tabcompany[$key]['id'];
			$companystatic->name = $tabcompany[$key]['name'];
			print "<td>" . $bookkeepingstatic->accountingLabelForOperation($companystatic->getNomUrl(0, 'customer'), $invoicestatic->ref, $accountingaccount->label, 1) . "</td>";
			print '<td class="right nowraponall amount">'.($mt < 0 ? price(-$mt) : '')."</td>";
			print '<td class="right nowraponall amount">'.($mt >= 0 ? price($mt) : '')."</td>";
			print "</tr>";

			$i++;
		}

		// VAT
		$listoftax = array(0, 1, 2);
		foreach ($listoftax as $numtax) {
			$arrayofvat = $tabtva;
			if ($numtax == 1) {
				$arrayofvat = $tablocaltax1;
			}
			if ($numtax == 2) {
				$arrayofvat = $tablocaltax2;
			}

			// $key is id of invoice
			foreach ($arrayofvat[$key] as $k => $mt) {
				if ($mt) {
					print '<tr class="oddeven">';
					print "<!-- VAT -->";
					print "<td>".$date."</td>";
					print "<td>".$invoicestatic->getNomUrl(1)."</td>";
					// Account
					print "<td>";
					$accountoshow = length_accountg($k);
					if (($accountoshow == "") || $accountoshow == 'NotDefined') {
						print '<span class="error">'.$langs->trans("VATAccountNotDefined").' ('.$langs->trans("AccountingJournalType2").')</span>';
					} else {
						print $accountoshow;
					}
					print "</td>";
					// Subledger account
					print "<td>";
					print '</td>';
					// $def_tva is array[invoiceid][accountancy_code_sell_of_vat_rate_found][vatrate]=vatrate
					//var_dump($arrayofvat[$key]); //var_dump($key); //var_dump($k);
					$tmpvatrate = (empty($def_tva[$key][$k]) ? (empty($arrayofvat[$key][$k]) ? '' : $arrayofvat[$key][$k]) : implode(', ', $def_tva[$key][$k]));
					$labelvatrate = $langs->trans("Taxes").' '.$tmpvatrate.' %';
					$labelvatrate .= ($numtax ? ' - Localtax '.$numtax : '');
					print "<td>" . $bookkeepingstatic->accountingLabelForOperation($companystatic->getNomUrl(0, 'customer'), $invoicestatic->ref, $labelvatrate, 1) . "</td>";
					print '<td class="right nowraponall amount">'.($mt < 0 ? price(-$mt) : '')."</td>";
					print '<td class="right nowraponall amount">'.($mt >= 0 ? price($mt) : '')."</td>";
					print "</tr>";

					$i++;
				}
			}
		}

		// Revenue stamp
		if (isset($tabrevenuestamp[$key]) && is_array($tabrevenuestamp[$key])) {
			foreach ($tabrevenuestamp[$key] as $k => $mt) {
				print '<tr class="oddeven">';
				print "<!-- Thirdparty revenuestamp -->";
				print "<td>" . $date . "</td>";
				print "<td>" . $invoicestatic->getNomUrl(1) . "</td>";
				// Account
				print "<td>";
				$accountoshow = length_accountg($k);
				if (($accountoshow == "") || $accountoshow == 'NotDefined') {
					print '<span class="error">' . $langs->trans("MainAccountForRevenueStampSaleNotDefined") . '</span>';
				} else {
					print $accountoshow;
				}
				print '</td>';
				// Subledger account
				print "<td>";
				print '</td>';
				print "<td>" . $bookkeepingstatic->accountingLabelForOperation($companystatic->getNomUrl(0, 'customer'), $invoicestatic->ref, $langs->trans("RevenueStamp"), 1) . "</td>";
				print '<td class="right nowraponall amount">' . ($mt < 0 ? price(-$mt) : '') . "</td>";
				print '<td class="right nowraponall amount">' . ($mt >= 0 ? price($mt) : '') . "</td>";
				print "</tr>";
			}
		}
	}

	if (!$i) {
		print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
	}

	print "</table>";
	print '</div>';

	// End of page
	llxFooter();
}

$db->close();
