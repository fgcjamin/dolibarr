<?php
/* Copyright (C) 2007-2010  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2007-2010  Jean Heimburger         <jean@tiaris.info>
 * Copyright (C) 2011       Juanjo Menent           <jmenent@2byte.es>
 * Copyright (C) 2012       Regis Houssin           <regis.houssin@inodbox.com>
 * Copyright (C) 2013       Christophe Battarel     <christophe.battarel@altairis.fr>
 * Copyright (C) 2013-2025	Alexandre Spangaro		<alexandre@inovea-conseil.com>
 * Copyright (C) 2013-2014  Florian Henry           <florian.henry@open-concept.pro>
 * Copyright (C) 2013-2014  Olivier Geffroy         <jeff@jeffinfo.com>
 * Copyright (C) 2017-2025  Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2018		Ferran Marcet		    <fmarcet@2byte.es>
 * Copyright (C) 2025		Hannes Hieronimi		<hannes@innwerk.org>
 * Copyright (C) 2025		MDW						<mdeweerd@users.noreply.github.com>
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
 *  \file       htdocs/accountancy/journal/treasuryjournal.php
 *  \ingroup    Advanced accountancy
 *  \brief      Page with bank journal
 */
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/accounting.lib.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingjournal.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/bookkeeping.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/bookkeeping.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formaccounting.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Societe $mysoc
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

$data = $accountingjournalstatic->getDataForTreasury($user, $date_start, $date_end, $in_bookkeeping, $only_rappro);
$tabpay = $data['tabpay'];
$tabaccount = $data['tabaccount'];
$tabobject = $data['tabobject'];
$tabaccountingaccount = $data['tabaccountingaccount'];

$accountingaccount = new AccountingAccount($db);

// Write bookkeeping
if ($action == 'writebookkeeping' /* && $user->hasRight('accounting', 'bind', 'write') */) { // Test on permission already done
	$result = $accountingjournalstatic->writeIntoBookkeepingForTreasury($user, $date_start, $date_end);
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
		header("Location: " . $_SERVER['PHP_SELF'] . '?' . $param);
		exit;
	}
}


/*
 * View
 */

$form = new Form($db);
$description = null;

if (empty($action) || $action == 'view') {
	llxHeader('', $langs->trans("FinanceJournal"));

	$nom = $langs->trans("FinanceJournal").' | '.$accountingjournalstatic->getNomUrl(0, 1, 1, '', 1);
	$nomlink = '';
	$builddate = dol_now();
	$description = $langs->trans("DescJournalOnlyBindedVisible").'<br>';

	$listofchoices = array(
		'notyet' => $langs->trans("NotYetInGeneralLedger"),
		'already' => $langs->trans("AlreadyInGeneralLedger")
	);
	$period = $form->selectDate($date_start ?: -1, 'date_start', 0, 0, 0, '', 1, 0).' - '.$form->selectDate($date_end ?: -1, 'date_end', 0, 0, 0, '', 1, 0);
	$period .= ' -  '.$langs->trans("JournalizationInLedgerStatus").' '.$form->selectarray('in_bookkeeping', $listofchoices, $in_bookkeeping, 1);

	$varlink = 'id_journal='.$id_journal;
	$periodlink = '';
	$exportlink = '';

	journalHead($nom, $nomlink, $period, $periodlink, $description, $builddate, $exportlink, array('action' => ''), '', $varlink);

	// Test that setup is complete
	$sql = "SELECT COUNT(rowid) as nb FROM ".$db->prefix()."bank_account WHERE fk_accountancy_journal IS NULL AND clos = 0";
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj->nb > 0) {
			print '<br>'.img_warning().' '.$langs->trans("TheJournalCodeIsNotDefinedOnSomeBankAccount");
			print ' : '.$langs->trans("AccountancyAreaDescBank", 9, '<strong>'.$langs->transnoentitiesnoconv("MenuAccountancy").'-'.$langs->transnoentitiesnoconv("MenuAccountancy").'-'.$langs->transnoentitiesnoconv("Setup")."-".$langs->transnoentitiesnoconv("BankAccounts").'</strong>');
		}
	} else {
		dol_print_error($db);
	}

	// Button to write into Ledger
	if (!getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER') || getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER') == '-1'
		|| !getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER') || getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER') == '-1'
		|| !getDolGlobalString('SALARIES_ACCOUNTING_ACCOUNT_PAYMENT') || getDolGlobalString('SALARIES_ACCOUNTING_ACCOUNT_PAYMENT') == '-1') {
		print '<br>'.img_warning().' '.$langs->trans("SomeMandatoryStepsOfSetupWereNotDone");
		print ' : '.$langs->trans("AccountancyAreaDescMisc", 4, '<strong>'.$langs->transnoentitiesnoconv("MenuAccountancy").'-'.$langs->transnoentitiesnoconv("MenuAccountancy").'-'.$langs->transnoentitiesnoconv("Setup")."-".$langs->transnoentitiesnoconv("MenuDefaultAccounts").'</strong>');
	}

	print '<br><div class="tabsAction tabsActionNoBottom centerimp">';

	if (getDolGlobalString('ACCOUNTING_ENABLE_EXPORT_DRAFT_JOURNAL')) {
		print '<input type="button" class="butAction" name="exportcsv" value="'.$langs->trans("ExportDraftJournal").'" onclick="launch_export();" />';
	}

	if (!getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER') || getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER') == '-1'
		|| !getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER') || getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER') == '-1'
		|| !getDolGlobalString('SALARIES_ACCOUNTING_ACCOUNT_PAYMENT') || getDolGlobalString('SALARIES_ACCOUNTING_ACCOUNT_PAYMENT') == '-1') {
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
	print "<td>".$langs->trans("Piece").' ('.$langs->trans("ObjectsRef").')</td>';
	print "<td>".$langs->trans("AccountAccounting")."</td>";
	print "<td>".$langs->trans("LabelOperation")."</td>";
	print '<td class="center">'.$langs->trans("PaymentMode")."</td>";
	print '<td class="right">'.$langs->trans("AccountingDebit")."</td>";
	print '<td class="right">'.$langs->trans("AccountingCredit")."</td>";
	print "</tr>\n";

	foreach ($tabpay as $payment_id => $payment) {
		$accountInfos = $tabaccount[$payment["fk_bank_account"]];
		$date = dol_print_date($payment["date"], 'day');
		$i++;

		foreach ($payment['objects'] as $object_key => $object_data) {
			$objectInfos = $tabobject[$object_key];

			// Show bank line
			if ($object_data['amount'] >= 0) {
				FormAccounting::printJournalLine($langs, $date, $objectInfos['url'], $accountInfos['account_number'], $accountInfos['account_ref'], $payment['type_payment'], $object_data['amount']);
			}

			// Operations
			$payment_total_vat = (float) price2num($object_data['amount'] * ($objectInfos['total_ttc'] - $objectInfos['total_ht']) / $objectInfos['total_ttc'], 'MT');
			$payment_total_ht = $object_data['amount'] - $payment_total_vat;
			$total_operation = 0;
			$idx = 1;
			$nb_operation = count($objectInfos['operations']);
			foreach ($objectInfos['operations'] as $accountancy_code => $operation) {
				// Set accounting account infos
				if (!isset($tabaccountingaccount[$accountancy_code])) {
					$result = $accountingaccount->fetch(0, $accountancy_code, true);
					$tabaccountingaccount[$accountancy_code] = array(
						'label' => $result < 0 ? $accountingaccount->errorsToString() : ($result > 0 ? $accountingaccount->label : $langs->trans('NotDefined')),
					);
				}
				$accountingAccountInfos = $tabaccountingaccount[$accountancy_code];
				if (!empty($operation['total_ht'])) {
					if ($idx < $nb_operation) {
						$value = price2num($payment_total_ht * $operation['total_ht'] / $objectInfos['total_ht'], 'MT');
						$total_operation += (float) $value;
					} else {
						$value = $payment_total_ht - $total_operation;
					}
					FormAccounting::printJournalLine($langs, $date, $objectInfos['url'], $accountancy_code, (!empty($operation['label']) ? $operation['label'] : $accountingAccountInfos['label']), $payment['type_payment'], - (float) $value);
				}
				$idx++;
			}

			// VATs
			$total_vat = 0;
			$idx = 1;
			$nb_vat = 0;
			foreach ($objectInfos['vats'] as $accountancy_code => $vats) {
				foreach ($vats as $vat_tx => $vat_infos) {
					$nb_vat++;
				}
			}
			foreach ($objectInfos['vats'] as $accountancy_code => $vats) {
				foreach ($vats as $vat_tx => $vat_infos) {
					$amount_vat = $vat_infos['total_tva'] + $vat_infos['total_localtax1'] + $vat_infos['total_localtax2'];
					if (!empty($amount_vat)) {
						$amount_vat = (float) price2num($payment_total_vat * $amount_vat / ($objectInfos['total_ttc'] - $objectInfos['total_ht']), 'MT');
						$total_vat += $amount_vat;
						FormAccounting::printJournalLine($langs, $date, $objectInfos['url'], $accountancy_code, $langs->trans('VAT').' '.price($vat_infos['tva_tx']).'%', $payment['type_payment'], -$amount_vat);
					}
					$idx++;
				}
			}

			// Show bank line
			if ($object_data['amount'] < 0) {
				FormAccounting::printJournalLine($langs, $date, $objectInfos['url'], $accountInfos['account_number'], $accountInfos['account_ref'], $payment['type_payment'], $object_data['amount']);
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
