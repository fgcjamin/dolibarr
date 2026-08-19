<?php
/* Copyright (C) 2015		Jean-François Ferry		<jfefe@aternatik.fr>
 * Copyright (C) 2019		Cedric Ancelin			<icedo.anc@gmail.com>
 * Copyright (C) 2023		Lionel Vessiller		<lvessiller@open-dsi.fr>
 * Copyright (C) 2024		MDW						<mdeweerd@users.noreply.github.com>
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

use Luracast\Restler\RestException;

/**
 * API class for accountancy
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 *
 */
class Accountancy extends DolibarrApi
{
	/**
	 *
	 * @var string[] $FIELDS Mandatory fields, checked when create and update object
	 */
	public static $FIELDS = array();

	/**
	 * @var BookKeeping $bookkeeping {@type BookKeeping}
	 */
	public $bookkeeping;

	/**
	 * @var Lettering $lettering {@type Lettering}
	 */
	public $lettering;

	/**
	 * @var AccountancyExport $accountancyexport {@type AccountancyExport}
	 */
	public $accountancyexport;

	/**
	 * @var string[] Settable fields for PUT ledger/{id}, matching the "confirm_update" single-line
	 *               edit form in accountancy/bookkeeping/card.php
	 */
	private static $LEDGER_SETTABLE_FIELDS = array('numero_compte', 'subledger_account', 'subledger_label', 'label_compte', 'label_operation', 'debit', 'credit');

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db, $langs;
		$this->db = $db;

		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/bookkeeping.class.php';
		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/lettering.class.php';
		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountancyexport.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/fiscalyear.class.php';

		$langs->load('accountancy');

		$this->bookkeeping = new BookKeeping($this->db);
		$this->lettering = new Lettering($this->db);
		$this->accountancyexport = new AccountancyExport($this->db);
	}

	/**
	 * Accountancy export data
	 *
	 * @param       string		$period					Period : 'lastmonth', 'currentmonth', 'last3months', 'last6months', 'currentyear', 'lastyear', 'fiscalyear', 'lastfiscalyear', 'actualandlastfiscalyear' or 'custom' (see above)
	 * @param		string		$date_min				[=''] Start date of period if 'custom' is set in period parameter
	 *													Date format is 'YYYY-MM-DD'
	 * @param		string		$date_max				[=''] End date of period if 'custom' is set in period parameter
	 *													Date format is 'YYYY-MM-DD'
	 * @param		string		$format					[=''] by default uses '1' for 'Configurable (CSV)' for format number
	 *													or '1000' for FEC
	 *													or '1010' for FEC2
	 *													(see AccountancyExport class)
	 * @param		int			$lettering				[=0] by default don't export or 1 to export lettering data (columns 'letterring_code' and 'date_lettering' returns empty or not)
	 * @param		int			$alreadyexport			[=0] by default export data only if it's not yet exported or 1 already exported (always export data even if 'date_export" is set)
	 * @param		int			$notnotifiedasexport	[=0] by default notified as exported or 1 not notified as exported (when the export is done, notified or not the column 'date_export')
	 *
	 * @return	string
	 *
	 * @url		GET exportdata
	 *
	 * @throws	RestException	401		Insufficient rights
	 * @throws	RestException	404		Accountancy export period not found
	 * @throws	RestException	404		Accountancy export start or end date not defined
	 * @throws	RestException	404		Accountancy export format not found
	 * @throws	RestException	500		Error on accountancy export
	 */
	public function exportData($period, $date_min = '', $date_max = '', $format = '', $lettering = 0, $alreadyexport = 0, $notnotifiedasexport = 0)
	{
		global $conf, $langs;

		// check rights
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'mouvements', 'export')) {
			throw new RestException(403, 'No permission to export accounting');
		}

		// check parameters
		$period_available_list = array('lastmonth', 'currentmonth', 'last3months', 'last6months', 'currentyear', 'lastyear', 'fiscalyear', 'lastfiscalyear', 'actualandlastfiscalyear', 'custom');
		if (!in_array($period, $period_available_list)) {
			throw new RestException(404, 'Accountancy export period not found');
		}
		if ($period == 'custom') {
			if ($date_min == '' && $date_max == '') {
				throw new RestException(404, 'Accountancy export start and end date for custom period not defined');
			}
		}
		if ($format == '') {
			$format = AccountancyExport::$EXPORT_TYPE_CONFIGURABLE; // uses default
		}

		// get objects
		$bookkeeping = $this->bookkeeping;
		$accountancyexport = $this->accountancyexport;

		// find export format code from format number
		$format_number_available_list = $accountancyexport->getType();
		if (is_numeric($format)) {
			$format_number = (int) $format;
		} else {
			$format_number = 0;
			$format_label_available_list = array_flip($format_number_available_list);
			if (isset($format_label_available_list[$format])) {
				$format_number = $format_label_available_list[$format];
			}
		}

		// get all format available and check if exists
		if (!array_key_exists($format_number, $format_number_available_list)) {
			throw new RestException(404, 'Accountancy export format not found');
		}

		$sortorder = 'ASC'; // by default
		$sortfield = 't.piece_num, t.rowid'; // by default

		// set filter for each period available
		$filter = array();
		$doc_date_start = null;
		$doc_date_end = null;
		$now = dol_now();
		$now_arr = dol_getdate($now);
		$now_month = $now_arr['mon'];
		$now_year = $now_arr['year'];
		if ($period == 'custom') {
			if ($date_min != '') {
				$time_min = strtotime($date_min);
				if ($time_min !== false) {
					$doc_date_start = $time_min;
				}
			}
			if ($date_max != '') {
				$time_max = strtotime($date_max);
				if ($time_max !== false) {
					$doc_date_end = $time_max;
				}
			}
		} elseif ($period == 'lastmonth') {
			$prev_date_arr = dol_get_prev_month($now_month, $now_year); // get previous month and year if month is january
			$doc_date_start = dol_mktime(0, 0, 0, $prev_date_arr['month'], 1, $prev_date_arr['year']); // first day of previous month
			$doc_date_end = dol_get_last_day($prev_date_arr['year'], $prev_date_arr['month']); // last day of previous month
		} elseif ($period == 'currentmonth') {
			$doc_date_start = dol_mktime(0, 0, 0, $now_month, 1, $now_year); // first day of current month
			$doc_date_end = dol_get_last_day($now_year, $now_month); // last day of current month
		} elseif ($period == 'last3months' || $period == 'last6months') {
			if ($period == 'last3months') {
				// last 3 months
				$nb_prev_month = 3;
			} else {
				// last 6 months
				$nb_prev_month = 6;
			}
			$prev_month_date_list = array();
			$prev_month_date_list[] = dol_get_prev_month($now_month, $now_year); // get previous month for index = 0
			for ($i = 1; $i < $nb_prev_month; $i++) {
				$prev_month_date_list[] = dol_get_prev_month($prev_month_date_list[$i - 1]['month'], $prev_month_date_list[$i - 1]['year']); // get i+1 previous month for index=i
			}
			$doc_date_start = dol_mktime(0, 0, 0, $prev_month_date_list[$nb_prev_month - 1]['month'], 1, $prev_month_date_list[$nb_prev_month - 1]['year']); // first day of n previous month for index=n-1
			$doc_date_end = dol_get_last_day($prev_month_date_list[0]['year'], $prev_month_date_list[0]['month']); // last day of previous month for index = 0
		} elseif ($period == 'currentyear' || $period == 'lastyear') {
			$period_year = $now_year;
			if ($period == 'lastyear') {
				$period_year--;
			}
			$doc_date_start = dol_mktime(0, 0, 0, 1, 1, $period_year); // first day of year
			$doc_date_end = dol_mktime(23, 59, 59, 12, 31, $period_year); // last day of year
		} elseif ($period == 'fiscalyear' || $period == 'lastfiscalyear' || $period == 'actualandlastfiscalyear') {
			// find actual fiscal year
			$cur_fiscal_period = getCurrentPeriodOfFiscalYear($this->db, $conf);
			$cur_fiscal_date_start = $cur_fiscal_period['date_start'];
			$cur_fiscal_date_end = $cur_fiscal_period['date_end'];

			if ($period == 'fiscalyear') {
				$doc_date_start = $cur_fiscal_date_start;
				$doc_date_end = $cur_fiscal_date_end;
			} else {
				// get one day before current fiscal date start (to find previous fiscal period)
				$prev_fiscal_date_search = dol_time_plus_duree($cur_fiscal_date_start, -1, 'd');

				// find previous fiscal year from current fiscal year
				$prev_fiscal_period = getCurrentPeriodOfFiscalYear($this->db, $conf, $prev_fiscal_date_search);
				$prev_fiscal_date_start = $prev_fiscal_period['date_start'];
				$prev_fiscal_date_end = $prev_fiscal_period['date_end'];

				if ($period == 'lastfiscalyear') {
					$doc_date_start = $prev_fiscal_date_start;
					$doc_date_end = $prev_fiscal_date_end;
				} else {
					// period == 'actualandlastfiscalyear'
					$doc_date_start = $prev_fiscal_date_start;
					$doc_date_end = $cur_fiscal_date_end;
				}
			}
		}
		if (is_numeric($doc_date_start)) {
			$filter['t.doc_date>='] = $doc_date_start;
		}
		if (is_numeric($doc_date_end)) {
			$filter['t.doc_date<='] = $doc_date_end;
		}

		// @FIXME Critical bugged. Never use fetchAll without limit !
		$result = $bookkeeping->fetchAll($sortorder, $sortfield, 0, 0, $filter, 'AND', $alreadyexport);

		if ($result < 0) {
			throw new RestException(500, 'Error bookkeeping fetch all : '.$bookkeeping->errorsToString());
		} else {
			// export files then exit
			if (empty($lettering)) {
				if (is_array($bookkeeping->lines)) {
					foreach ($bookkeeping->lines as $k => $movement) {
						unset($bookkeeping->lines[$k]->lettering_code);
						unset($bookkeeping->lines[$k]->date_lettering);
					}
				}
			}

			$error = 0;
			$this->db->begin();

			if (empty($notnotifiedasexport)) {
				if (is_array($bookkeeping->lines)) {
					foreach ($bookkeeping->lines as $movement) {
						$now = dol_now();

						$sql = " UPDATE " . MAIN_DB_PREFIX . "accounting_bookkeeping";
						$sql .= " SET date_export = '" . $this->db->idate($now) . "'";
						$sql .= " WHERE rowid = " . ((int) $movement->id);

						$result = $this->db->query($sql);
						if (!$result) {
							$accountancyexport->errors[] = $langs->trans('NotAllExportedMovementsCouldBeRecordedAsExportedOrValidated');
							$error++;
							break;
						}
					}
				}
			}

			// export and only write file without downloading
			if (!$error) {
				$result = $accountancyexport->export($bookkeeping->lines, $format_number, 0, 1, 2);
				if ($result < 0) {
					$error++;
				}
			}

			if ($error) {
				$this->db->rollback();
				throw new RestException(500, 'Error accountancy export : '.implode(',', $accountancyexport->errors));
			} else {
				$this->db->commit();
				exit();
			}
		}
	}

	/**
	 * Get a list of bookkeeping (ledger) entries
	 *
	 * @param   string  $sortfield              Sort field
	 * @param   string  $sortorder              Sort order
	 * @param   int     $limit                  Limit for list
	 * @param   int     $page                   Page number
	 * @param   string  $type                   '' or 'general' for general account listing, 'sub' for subledger (auxiliary) account listing
	 * @param   string  $date_start             Filter on doc_date >= (format YYYY-MM-DD)
	 * @param   string  $date_end               Filter on doc_date <= (format YYYY-MM-DD)
	 * @param   string  $account_min            Filter on account number range (general account, or subledger account if type=sub) >=
	 * @param   string  $account_max            Filter on account number range (general account, or subledger account if type=sub) <=
	 * @param   string  $code_journal           Filter on journal code(s), comma separated for multiple
	 * @param   int     $piece_num              Filter on piece number
	 * @param   int     $fk_doc                 Filter on source document id
	 * @param   int     $fk_docdet              Filter on source document line id
	 * @param   string  $date_validation_start  Filter on date_validated >= (format YYYY-MM-DD)
	 * @param   string  $date_validation_end    Filter on date_validated <= (format YYYY-MM-DD)
	 * @param   string  $export_status          '' or 'all' to show already exported movements (default), 'notexported' to hide them. Ignored when type=sub
	 * @param   int     $reconciled             1 to show all movements (default), 0 to show only unreconciled (unlettered) movements
	 * @param   string  $sqlfilters             Other criteria to filter answers, syntax example "(t.numero_compte:like:'411%')". Mutually exclusive with the filters above; not supported when type=sub
	 * @param   string  $properties             Restrict the data returned to these properties. Ignored if empty. Comma separated list of properties names
	 * @param   bool    $pagination_data        Include pagination data in the response. Only supported when type=sub
	 * @return  array
	 * @phan-return array<int,BookKeepingLine>|array{data:array<int,BookKeepingLine>,pagination:array{total:int,page:int,page_count:int,limit:int}}
	 * @phpstan-return array<int,BookKeepingLine>|array{data:array<int,BookKeepingLine>,pagination:array{total:int,page:int,page_count:int,limit:int}}
	 *
	 * @url     GET ledger
	 *
	 * @throws  RestException  400  Bad parameters
	 * @throws  RestException  403  Insufficient rights
	 * @throws  RestException  503  Error while fetching ledger entries
	 */
	public function getLedger($sortfield = 't.piece_num, t.rowid', $sortorder = 'ASC', $limit = 100, $page = 0, $type = '', $date_start = '', $date_end = '', $account_min = '', $account_max = '', $code_journal = '', $piece_num = 0, $fk_doc = 0, $fk_docdet = 0, $date_validation_start = '', $date_validation_end = '', $export_status = '', $reconciled = 1, $sqlfilters = '', $properties = '', $pagination_data = false)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'comptarapport', 'lire') && !DolibarrApiAccess::$user->hasRight('accounting', 'mouvements', 'lire')) {
			throw new RestException(403, 'No permission to read accounting reports');
		}

		if ($type != '' && $type != 'general' && $type != 'sub') {
			throw new RestException(400, "type must be '', 'general' or 'sub'");
		}
		$issub = ($type == 'sub');

		if ($sqlfilters != '' && $issub) {
			throw new RestException(400, 'sqlfilters is not supported when type=sub');
		}
		if ($pagination_data && !$issub) {
			throw new RestException(400, 'pagination_data is only supported when type=sub');
		}

		$obj_ret = array();

		if ($sqlfilters != '') {
			$filter = $sqlfilters;
		} else {
			$filter = $this->_buildLedgerFilter($type, $date_start, $date_end, $account_min, $account_max, $code_journal, $reconciled);
			if (!empty($piece_num)) {
				$filter['t.piece_num'] = (int) $piece_num;
			}
			if (!empty($fk_doc)) {
				$filter['t.fk_doc'] = (int) $fk_doc;
			}
			if (!empty($fk_docdet)) {
				$filter['t.fk_docdet'] = (int) $fk_docdet;
			}
			if ($date_validation_start !== '') {
				$filter['t.date_validated>='] = strtotime($date_validation_start);
			}
			if ($date_validation_end !== '') {
				$filter['t.date_validated<='] = strtotime($date_validation_end);
			}
		}

		if ($page < 0) {
			$page = 0;
		}
		$offset = $limit * $page;

		if ($issub) {
			if ($pagination_data) {
				$total = $this->bookkeeping->fetchAllByAccount($sortorder, $sortfield, 0, 0, $filter, 'AND', 1, 1);
				if ($total < 0) {
					throw new RestException(503, 'Error while fetching ledger entries: '.$this->bookkeeping->errorsToString());
				}
			}

			$result = $this->bookkeeping->fetchAllByAccount($sortorder, $sortfield, $limit, $offset, $filter, 'AND', 1);
		} else {
			$showAlreadyExportMovements = ($export_status == 'notexported') ? 0 : 1;
			$result = $this->bookkeeping->fetchAll($sortorder, $sortfield, $limit, $offset, $filter, 'AND', $showAlreadyExportMovements);
		}

		if ($result < 0) {
			throw new RestException(503, 'Error while fetching ledger entries: '.$this->bookkeeping->errorsToString());
		}

		if (is_array($this->bookkeeping->lines)) {
			foreach ($this->bookkeeping->lines as $line) {
				$obj_ret[] = $this->_filterObjectProperties($this->_cleanObjectDatas($line), $properties);
			}
		}

		if ($pagination_data) {
			return array(
				'data' => $obj_ret,
				'pagination' => array(
					'total' => (int) $total,
					'page' => $page,
					'page_count' => ($limit ? (int) ceil((int) $total / $limit) : 0),
					'limit' => $limit
				)
			);
		}

		return $obj_ret;
	}

	/**
	 * Get the trial balance (bookkeeping entries grouped and summed by account)
	 *
	 * @param   string  $type           '' or 'general' to group by general account (default), 'sub' to group by subledger (auxiliary) account
	 * @param   string  $date_start     Filter on doc_date >= (format YYYY-MM-DD)
	 * @param   string  $date_end       Filter on doc_date <= (format YYYY-MM-DD)
	 * @param   string  $account_min    Filter on account number range (general account, or subledger account if type=sub) >=
	 * @param   string  $account_max    Filter on account number range (general account, or subledger account if type=sub) <=
	 * @param   string  $code_journal   Filter on journal code(s), comma separated for multiple
	 * @param   int     $reconciled     1 to show all movements (default), 0 to include only unreconciled (unlettered) movements in the sums
	 * @param   string  $sortfield      Sort field
	 * @param   string  $sortorder      Sort order
	 * @param   int     $limit          Limit for list, 0 means no limit (default, trial balances are naturally small)
	 * @param   int     $page           Page number, used only if limit is set
	 * @return  array
	 * @phan-return array<int,BookKeepingLine>
	 * @phpstan-return array<int,BookKeepingLine>
	 *
	 * @url     GET ledger/balance
	 *
	 * @throws  RestException  400  Bad parameters
	 * @throws  RestException  403  Insufficient rights
	 * @throws  RestException  503  Error while fetching trial balance
	 */
	public function getLedgerBalance($type = '', $date_start = '', $date_end = '', $account_min = '', $account_max = '', $code_journal = '', $reconciled = 1, $sortfield = 't.numero_compte', $sortorder = 'ASC', $limit = 0, $page = 0)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'comptarapport', 'lire') && !DolibarrApiAccess::$user->hasRight('accounting', 'mouvements', 'lire')) {
			throw new RestException(403, 'No permission to read accounting reports');
		}

		if ($type != '' && $type != 'general' && $type != 'sub') {
			throw new RestException(400, "type must be '', 'general' or 'sub'");
		}
		$issub = ($type == 'sub');

		$filter = $this->_buildLedgerFilter($type, $date_start, $date_end, $account_min, $account_max, $code_journal, $reconciled);

		if ($page < 0) {
			$page = 0;
		}
		$offset = $limit * $page;

		$result = $this->bookkeeping->fetchAllBalance($sortorder, $sortfield, $limit, $offset, $filter, 'AND', $issub ? 1 : 0);
		if ($result < 0) {
			throw new RestException(503, 'Error while fetching trial balance: '.$this->bookkeeping->errorsToString());
		}

		$obj_ret = array();
		if (is_array($this->bookkeeping->lines)) {
			foreach ($this->bookkeeping->lines as $line) {
				$obj_ret[] = $this->_cleanObjectDatas($line);
			}
		}

		return $obj_ret;
	}

	/**
	 * Get a bookkeeping (ledger) entry by ID
	 *
	 * @param   int     $id     Bookkeeping entry ID
	 * @return  Object
	 *
	 * @url     GET ledger/{id}
	 *
	 * @throws  RestException  403  Insufficient rights
	 * @throws  RestException  404  Ledger entry not found
	 * @throws  RestException  503  Error while fetching ledger entry
	 */
	public function getLedgerEntry($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'mouvements', 'lire')) {
			throw new RestException(403, 'No permission to read ledger entries');
		}

		$entry = $this->_fetchLedgerEntry($id);

		return $this->_cleanObjectDatas($entry);
	}

	/**
	 * Update a bookkeeping (ledger) entry
	 *
	 * Only the fields editable through the single-line edit form of accountancy/bookkeeping/card.php
	 * are settable (numero_compte, subledger_account, subledger_label, label_compte,
	 * label_operation, debit, credit). Piece-level fields (doc_date, doc_ref, ref, code_journal)
	 * are edited at the piece level by the UI (affecting every line sharing the same piece_num) and
	 * are not exposed here.
	 *
	 * @param   int     $id             Bookkeeping entry ID
	 * @param   array   $request_data   Request data
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return  Object
	 *
	 * @url     PUT ledger/{id}
	 *
	 * @throws  RestException  403  Insufficient rights, or entry already validated/exported
	 * @throws  RestException  404  Ledger entry not found
	 * @throws  RestException  500  Error while updating ledger entry
	 */
	public function putLedgerEntry($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'mouvements', 'creer')) {
			throw new RestException(403, 'No permission to write ledger entries');
		}

		$entry = $this->_fetchLedgerEntry($id);

		// Model layer (BookKeeping::update()) only checks fiscal-period-open state, not
		// validation/export status - replicate the UI's own guard (card.php:1094-1104) here.
		if (!empty($entry->date_export) || !empty($entry->date_validation)) {
			throw new RestException(403, 'Ledger entry has already been validated or exported and can no longer be modified');
		}

		if (is_array($request_data)) {
			foreach ($request_data as $field => $value) {
				if (!in_array($field, self::$LEDGER_SETTABLE_FIELDS)) {
					continue;
				}
				$entry->$field = $this->_checkValForAPI($field, $value, $entry);
			}
		}

		$result = $entry->update(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error while updating ledger entry: '.$entry->errorsToString());
		}

		return $this->getLedgerEntry($id);
	}

	/**
	 * Delete a bookkeeping (ledger) entry
	 *
	 * @param   int     $id     Bookkeeping entry ID
	 * @return  array
	 * @phan-return array{success:array{code:int,message:string}}
	 * @phpstan-return array{success:array{code:int,message:string}}
	 *
	 * @url     DELETE ledger/{id}
	 *
	 * @throws  RestException  403  Insufficient rights, or entry already validated
	 * @throws  RestException  404  Ledger entry not found
	 * @throws  RestException  500  Error while deleting ledger entry
	 */
	public function deleteLedgerEntry($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'mouvements', 'supprimer')) {
			throw new RestException(403, 'No permission to delete ledger entries');
		}

		$entry = $this->_fetchLedgerEntry($id);

		// Model layer (BookKeeping::delete()) only checks fiscal-period-open state, not
		// validation status - replicate the UI's own delete-link guard (card.php:1106-1119),
		// which (unlike the edit guard above) does not also block on date_export.
		if (!empty($entry->date_validation)) {
			throw new RestException(403, 'Ledger entry has already been validated and can no longer be deleted');
		}

		$result = $entry->delete(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error while deleting ledger entry: '.$entry->errorsToString());
		}

		return array(
			'success' => array(
				'code' => 200,
				'message' => 'Ledger entry deleted'
			)
		);
	}

	/**
	 * Manually letter (reconcile) a set of ledger entries sharing the same subledger account
	 *
	 * @param   array   $request_data   Request data
	 * @phan-param array{ids?:array<int>,partial?:bool} $request_data
	 * @phpstan-param array{ids?:array<int>,partial?:bool} $request_data
	 * @return  array
	 * @phan-return array{lettered:int}
	 * @phpstan-return array{lettered:int}
	 *
	 * @url     POST ledger/lettering
	 *
	 * @throws  RestException  400  Bad parameters, or lettering is not enabled
	 * @throws  RestException  403  Insufficient rights
	 * @throws  RestException  500  Error while lettering
	 */
	public function postLedgerLettering($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'mouvements', 'creer')) {
			throw new RestException(403, 'No permission to write ledger entries');
		}
		if (!getDolGlobalInt('ACCOUNTING_ENABLE_LETTERING')) {
			throw new RestException(400, 'Lettering is not enabled (ACCOUNTING_ENABLE_LETTERING)');
		}

		$ids = (!empty($request_data['ids']) && is_array($request_data['ids'])) ? array_map('intval', $request_data['ids']) : array();
		if (empty($ids)) {
			throw new RestException(400, 'ids is mandatory and must be a non-empty array');
		}
		$partial = !empty($request_data['partial']);

		$result = $this->lettering->updateLettering($ids, 0, $partial);
		if ($result < 0) {
			throw new RestException(500, 'Error while lettering: '.$this->lettering->errorsToString());
		}

		return array('lettered' => (int) $result);
	}

	/**
	 * Remove lettering (reconciliation) from a set of ledger entries
	 *
	 * @param   array   $request_data   Request data
	 * @phan-param array{ids?:array<int>} $request_data
	 * @phpstan-param array{ids?:array<int>} $request_data
	 * @return  array
	 * @phan-return array{unlettered:int}
	 * @phpstan-return array{unlettered:int}
	 *
	 * @url     DELETE ledger/lettering
	 *
	 * @throws  RestException  400  Bad parameters, or lettering is not enabled
	 * @throws  RestException  403  Insufficient rights
	 * @throws  RestException  500  Error while removing lettering
	 */
	public function deleteLedgerLettering($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'mouvements', 'creer')) {
			throw new RestException(403, 'No permission to write ledger entries');
		}
		if (!getDolGlobalInt('ACCOUNTING_ENABLE_LETTERING')) {
			throw new RestException(400, 'Lettering is not enabled (ACCOUNTING_ENABLE_LETTERING)');
		}

		$ids = (!empty($request_data['ids']) && is_array($request_data['ids'])) ? array_map('intval', $request_data['ids']) : array();
		if (empty($ids)) {
			throw new RestException(400, 'ids is mandatory and must be a non-empty array');
		}

		$result = $this->lettering->deleteLettering($ids);
		if ($result < 0) {
			throw new RestException(500, 'Error while removing lettering: '.$this->lettering->errorsToString());
		}

		return array('unlettered' => (int) $result);
	}

	/**
	 * Automatically letter (reconcile) balanced ledger entries for a thirdparty's subledger accounts
	 *
	 * Uses POST rather than a GET: letteringThirdparty() is not read-only, it writes
	 * lettering_code/date_lettering on matching balanced groups of entries, so a safe HTTP verb
	 * would be misleading (a gateway/cache could prefetch or replay a GET).
	 *
	 * @param   int     $id     Thirdparty ID
	 * @return  array
	 * @phan-return array{success:array{code:int,message:string}}
	 * @phpstan-return array{success:array{code:int,message:string}}
	 *
	 * @url     POST thirdparties/{id}/lettering
	 *
	 * @throws  RestException  400  Lettering is not enabled
	 * @throws  RestException  403  Insufficient rights
	 * @throws  RestException  500  Error while lettering
	 */
	public function postThirdpartyLettering($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'mouvements', 'creer')) {
			throw new RestException(403, 'No permission to write ledger entries');
		}
		if (!getDolGlobalInt('ACCOUNTING_ENABLE_LETTERING')) {
			throw new RestException(400, 'Lettering is not enabled (ACCOUNTING_ENABLE_LETTERING)');
		}

		$result = $this->lettering->letteringThirdparty((int) $id);
		if ($result < 0) {
			throw new RestException(500, 'Error while lettering: '.$this->lettering->errorsToString());
		}

		return array(
			'success' => array(
				'code' => 200,
				'message' => 'Lettering processed for thirdparty'
			)
		);
	}

	/**
	 * Get list of fiscal periods (accounting closure periods), ordered by start date
	 *
	 * @return  array<array{id:int,label:string,date_start:int,date_end:int,status:int}>
	 *
	 * @url     GET fiscalperiods
	 *
	 * @throws  RestException  403  Insufficient rights
	 * @throws  RestException  503  Error while fetching fiscal periods
	 */
	public function getFiscalPeriods()
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'fiscalyear', 'write') && !DolibarrApiAccess::$user->hasRight('accounting', 'mouvements', 'lire')) {
			throw new RestException(403, 'No permission to read fiscal periods');
		}

		$list = $this->bookkeeping->getFiscalPeriods();
		if (!is_array($list)) {
			throw new RestException(503, 'Error while fetching fiscal periods: '.$this->bookkeeping->errorsToString());
		}

		return array_values($list);
	}

	/**
	 * Get a fiscal period (accounting closure period) by ID
	 *
	 * @param   int     $id     Fiscal period ID
	 * @return  Object
	 *
	 * @url     GET fiscalperiods/{id}
	 *
	 * @throws  RestException  403  Insufficient rights
	 * @throws  RestException  404  Fiscal period not found
	 */
	public function getFiscalPeriod($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'fiscalyear', 'write') && !DolibarrApiAccess::$user->hasRight('accounting', 'mouvements', 'lire')) {
			throw new RestException(403, 'No permission to read fiscal periods');
		}

		$fiscalyear = $this->_fetchFiscalPeriod($id);

		return $this->_cleanObjectDatas($fiscalyear);
	}

	/**
	 * Validate all bookkeeping movements of a fiscal period between two dates
	 * (step 1 of the accounting closure wizard, see accountancy/closure/index.php)
	 *
	 * @param   int     $id             Fiscal period ID
	 * @param   int     $date_start     Date start (timestamp)
	 * @param   int     $date_end       Date end (timestamp)
	 * @return  Object
	 *
	 * @url     POST fiscalperiods/{id}/validate
	 *
	 * @throws  RestException  400  Bad parameters
	 * @throws  RestException  403  Insufficient rights
	 * @throws  RestException  404  Fiscal period not found
	 * @throws  RestException  409  Fiscal period is already closed
	 * @throws  RestException  500  Error while validating movements
	 */
	public function validateFiscalPeriod($id, $date_start, $date_end)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'fiscalyear', 'write')) {
			throw new RestException(403, 'No permission to close accounting periods');
		}

		if (empty($date_start) || empty($date_end)) {
			throw new RestException(400, 'date_start and date_end are mandatory');
		}

		$fiscalyear = $this->_fetchFiscalPeriod($id);
		if ($fiscalyear->status == Fiscalyear::STATUS_CLOSED) {
			throw new RestException(409, 'Fiscal period is already closed, movements can no longer be validated');
		}

		$result = $this->bookkeeping->validateMovementForFiscalPeriod($date_start, $date_end);
		if ($result < 0) {
			throw new RestException(500, 'Error while validating movements: '.$this->bookkeeping->errorsToString());
		}

		$fiscalyear = $this->_fetchFiscalPeriod($id);

		return $this->_cleanObjectDatas($fiscalyear);
	}

	/**
	 * Close a fiscal period, optionally generating closure bookkeeping records
	 * (step 2 of the accounting closure wizard, see accountancy/closure/index.php)
	 *
	 * @param   int     $id                             Fiscal period ID
	 * @param   int     $new_fiscal_period_id           New fiscal period ID (movements resume into this one)
	 * @param   int     $separate_auxiliary_account     1 to separate auxiliary (subledger) accounts, 0 otherwise
	 * @param   int     $generate_bookkeeping_records   1 to generate closure bookkeeping records, 0 otherwise
	 * @return  Object
	 *
	 * @url     POST fiscalperiods/{id}/close
	 *
	 * @throws  RestException  400  Bad parameters
	 * @throws  RestException  403  Insufficient rights
	 * @throws  RestException  404  Fiscal period not found
	 * @throws  RestException  409  Fiscal period is already closed, or unvalidated movements remain
	 * @throws  RestException  500  Error while closing fiscal period
	 */
	public function closeFiscalPeriod($id, $new_fiscal_period_id, $separate_auxiliary_account = 0, $generate_bookkeeping_records = 1)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'fiscalyear', 'write')) {
			throw new RestException(403, 'No permission to close accounting periods');
		}

		if (empty($new_fiscal_period_id)) {
			throw new RestException(400, 'new_fiscal_period_id is mandatory');
		}

		$fiscalyear = $this->_fetchFiscalPeriod($id);
		if ($fiscalyear->status == Fiscalyear::STATUS_CLOSED) {
			throw new RestException(409, 'Fiscal period is already closed');
		}

		$count_by_month = $this->bookkeeping->getCountByMonthForFiscalPeriod((int) $fiscalyear->date_start, (int) $fiscalyear->date_end);
		if (!is_array($count_by_month)) {
			throw new RestException(500, 'Error while checking unvalidated movements: '.$this->bookkeeping->errorsToString());
		}
		if (!empty($count_by_month['total']) && !getDolGlobalString('ACCOUNTANCY_DISABLE_CLOSURE_LINE_BY_LINE')) {
			throw new RestException(409, 'Some bookkeeping movements of this fiscal period are not yet validated');
		}

		$result = $this->bookkeeping->closeFiscalPeriod($id, $new_fiscal_period_id, (bool) $separate_auxiliary_account, (bool) $generate_bookkeeping_records);
		if ($result < 0) {
			throw new RestException(500, 'Error while closing fiscal period: '.$this->bookkeeping->errorsToString());
		}

		$fiscalyear = $this->_fetchFiscalPeriod($id);

		return $this->_cleanObjectDatas($fiscalyear);
	}

	/**
	 * Insert accounting reversal entries into the inventory journal of the new fiscal period
	 * (step 3 of the accounting closure wizard, see accountancy/closure/index.php)
	 *
	 * @param   int     $id                     Fiscal period ID (must already be closed)
	 * @param   int     $inventory_journal_id   Inventory journal ID
	 * @param   int     $new_fiscal_period_id   New fiscal period ID
	 * @param   int     $date_start             Date start (timestamp)
	 * @param   int     $date_end               Date end (timestamp)
	 * @return  Object
	 *
	 * @url     POST fiscalperiods/{id}/reversal
	 *
	 * @throws  RestException  400  Bad parameters
	 * @throws  RestException  403  Insufficient rights
	 * @throws  RestException  404  Fiscal period not found
	 * @throws  RestException  409  Fiscal period is not closed yet
	 * @throws  RestException  500  Error while inserting accounting reversal
	 */
	public function reversalFiscalPeriod($id, $inventory_journal_id, $new_fiscal_period_id, $date_start, $date_end)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'fiscalyear', 'write')) {
			throw new RestException(403, 'No permission to close accounting periods');
		}

		if (empty($inventory_journal_id) || empty($new_fiscal_period_id) || empty($date_start) || empty($date_end)) {
			throw new RestException(400, 'inventory_journal_id, new_fiscal_period_id, date_start and date_end are mandatory');
		}

		$fiscalyear = $this->_fetchFiscalPeriod($id);
		if ($fiscalyear->status != Fiscalyear::STATUS_CLOSED) {
			throw new RestException(409, 'Fiscal period must be closed before inserting the accounting reversal');
		}

		$result = $this->bookkeeping->insertAccountingReversal($id, $inventory_journal_id, $new_fiscal_period_id, $date_start, $date_end);
		if ($result < 0) {
			throw new RestException(500, 'Error while inserting accounting reversal: '.$this->bookkeeping->errorsToString());
		}

		$fiscalyear = $this->_fetchFiscalPeriod($id);

		return $this->_cleanObjectDatas($fiscalyear);
	}

	/**
	 * Fetch a fiscal period by id or throw a 404
	 *
	 * @param   int         $id     Fiscal period ID
	 * @return  Fiscalyear
	 *
	 * @throws  RestException  404  Fiscal period not found
	 */
	private function _fetchFiscalPeriod($id)
	{
		$fiscalyear = new Fiscalyear($this->db);
		$result = $fiscalyear->fetch($id);
		if ($result <= 0) {
			throw new RestException(404, 'Fiscal period not found');
		}

		return $fiscalyear;
	}

	/**
	 * Fetch a bookkeeping (ledger) entry by id or throw a 404
	 *
	 * @param   int         $id     Bookkeeping entry ID
	 * @return  BookKeeping
	 *
	 * @throws  RestException  404  Ledger entry not found
	 * @throws  RestException  503  Error while fetching ledger entry
	 */
	private function _fetchLedgerEntry($id)
	{
		$entry = new BookKeeping($this->db);
		$result = $entry->fetch((int) $id);
		if ($result < 0) {
			throw new RestException(503, 'Error while fetching ledger entry: '.$entry->errorsToString());
		}
		if (!$result) {
			throw new RestException(404, 'Ledger entry not found');
		}

		return $entry;
	}

	/**
	 * Build the array-form BookKeeping filter shared by getLedger() and getLedgerBalance()
	 *
	 * @param   string  $type           '' or 'general' or 'sub'
	 * @param   string  $date_start     Filter on doc_date >= (format YYYY-MM-DD)
	 * @param   string  $date_end       Filter on doc_date <= (format YYYY-MM-DD)
	 * @param   string  $account_min    Filter on account number range >=
	 * @param   string  $account_max    Filter on account number range <=
	 * @param   string  $code_journal   Filter on journal code(s), comma separated for multiple
	 * @param   int     $reconciled     1 = no filter, 0 = only unreconciled (unlettered) movements
	 * @return  array
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 */
	private function _buildLedgerFilter($type, $date_start, $date_end, $account_min, $account_max, $code_journal, $reconciled)
	{
		$filter = array();
		$accountkey = ($type == 'sub') ? 't.subledger_account' : 't.numero_compte';

		if ($date_start !== '') {
			$filter['t.doc_date>='] = strtotime($date_start);
		}
		if ($date_end !== '') {
			$filter['t.doc_date<='] = strtotime($date_end);
		}
		if ($account_min !== '') {
			$filter[$accountkey.'>='] = $account_min;
		}
		if ($account_max !== '') {
			$filter[$accountkey.'<='] = $account_max;
		}
		if ($code_journal !== '') {
			$filter['t.code_journal'] = (strpos($code_journal, ',') !== false) ? explode(',', $code_journal) : $code_journal;
		}
		if (empty($reconciled)) {
			$filter['t.reconciled_option'] = 1;
		}

		return $filter;
	}
}
