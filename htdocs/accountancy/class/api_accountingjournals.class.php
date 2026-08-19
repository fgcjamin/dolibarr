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
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingjournal.class.php';

/**
 * API class for accounting journals
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class AccountingJournals extends DolibarrApi
{
	/**
	 * @var string[] Mandatory fields, checked when creating an object
	 */
	public static $FIELDS = array(
		'code',
		'label',
		'nature',
	);

	/**
	 * @var string[] Settable fields, matching what AccountingJournal::create()/update() actually persist
	 */
	private static $SETTABLE_FIELDS = array(
		'code',
		'label',
		'nature',
		'active',
	);

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Get the list of accounting journals.
	 *
	 * @param string	$sortfield		Sort field
	 * @param string	$sortorder		Sort order
	 * @param int		$limit			Limit for list
	 * @param int		$page			Page number
	 * @param string	$sqlfilters		Other criteria to filter answers separated by a comma. Syntax example "(t.nature:=:2)"
	 * @param string	$properties		Restrict the data returned to these properties. Ignored if empty. Comma separated list of properties names
	 * @return array					List of accounting journal objects
	 * @phan-return AccountingJournal[]
	 * @phpstan-return AccountingJournal[]
	 *
	 * @throws RestException
	 */
	public function index($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 100, $page = 0, $sqlfilters = '', $properties = '')
	{
		$list = array();

		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$sql = "SELECT t.rowid FROM ".MAIN_DB_PREFIX."accounting_journal AS t";
		$sql .= " WHERE t.entity IN (".getEntity('accounting_journal').")";

		if ($sqlfilters) {
			$errormessage = '';
			$sql .= forgeSQLFromUniversalSearchCriteria($sqlfilters, $errormessage);
			if ($errormessage) {
				throw new RestException(400, 'Error when validating parameter sqlfilters -> '.$errormessage);
			}
		}

		$sql .= $this->db->order($sortfield, $sortorder);
		if ($limit) {
			if ($page < 0) {
				$page = 0;
			}
			$offset = $limit * $page;

			$sql .= $this->db->plimit($limit + 1, $offset);
		}

		$result = $this->db->query($sql);
		if ($result) {
			$num = $this->db->num_rows($result);
			$min = min($num, ($limit <= 0 ? $num : $limit));
			for ($i = 0; $i < $min; $i++) {
				$obj = $this->db->fetch_object($result);
				$journal = new AccountingJournal($this->db);
				if ($journal->fetch($obj->rowid) > 0) {
					$list[] = $this->_filterObjectProperties($this->_cleanObjectDatas($journal), $properties);
				}
			}
		} else {
			throw new RestException(503, 'Error when retrieving list of accounting journals: '.$this->db->lasterror());
		}

		return $list;
	}

	/**
	 * Get accounting journal by ID.
	 *
	 * @param	int		$id		ID of accounting journal
	 * @return	Object			Object with cleaned properties
	 *
	 * @throws RestException
	 */
	public function get($id)
	{
		return $this->_fetch($id);
	}

	/**
	 * Get accounting journal by code.
	 *
	 * @param	string	$code	Journal code
	 * @return	Object			Object with cleaned properties
	 *
	 * @url GET bycode/{code}
	 *
	 * @throws RestException
	 */
	public function getByCode($code)
	{
		return $this->_fetch(0, $code);
	}

	/**
	 * Shared fetch logic
	 *
	 * @param	int		$id		ID of accounting journal
	 * @param	string	$code	Journal code
	 * @return	Object			Object with cleaned properties
	 *
	 * @throws RestException
	 */
	private function _fetch($id, $code = '')
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$journal = new AccountingJournal($this->db);
		$result = $journal->fetch($id, $code ? $code : null);
		if (!$result) {
			throw new RestException(404, 'Accounting journal not found');
		}

		return $this->_cleanObjectDatas($journal);
	}

	/**
	 * Create an accounting journal
	 *
	 * @param	array $request_data		Request data
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return	int						ID of accounting journal
	 *
	 * @throws RestException
	 */
	public function post($request_data = null)
	{
		global $conf;

		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$this->_validate($request_data);

		$journal = new AccountingJournal($this->db);
		$journal->entity = $conf->entity;
		foreach ($request_data as $field => $value) {
			if (!in_array($field, self::$SETTABLE_FIELDS)) {
				continue;
			}
			$journal->$field = $this->_checkValForAPI($field, $value, $journal);
		}

		$result = $journal->create(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error creating accounting journal: '.$journal->error);
		}
		return $result;
	}

	/**
	 * Update an accounting journal
	 *
	 * @param	int    $id              ID of accounting journal
	 * @param	array  $request_data    data
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return	Object					Object with cleaned properties
	 *
	 * @throws RestException
	 */
	public function put($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$journal = new AccountingJournal($this->db);
		$result = $journal->fetch($id);
		if (!$result) {
			throw new RestException(404, 'Accounting journal not found');
		}

		foreach ($request_data as $field => $value) {
			if (!in_array($field, self::$SETTABLE_FIELDS)) {
				continue;
			}
			$journal->$field = $this->_checkValForAPI($field, $value, $journal);
		}

		$result = $journal->update(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error updating accounting journal: '.$journal->error);
		}
		return $this->get($id);
	}

	/**
	 * Delete an accounting journal
	 *
	 * @param	int    $id    ID of accounting journal
	 * @return	array
	 * @phan-return array{success:array{code:int,message:string}}
	 * @phpstan-return array{success:array{code:int,message:string}}
	 *
	 * @throws RestException
	 */
	public function delete($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$journal = new AccountingJournal($this->db);
		$result = $journal->fetch($id);
		if (!$result) {
			throw new RestException(404, 'Accounting journal not found');
		}

		$result = $journal->delete();
		if ($result < 0) {
			throw new RestException(500, 'Error deleting accounting journal: '.$journal->error);
		}

		return array(
			'success' => array(
				'code' => 200,
				'message' => 'Accounting journal deleted'
			)
		);
	}

	/**
	 * Write pending accounting movements for a journal into the general ledger.
	 *
	 * Supported journal natures: 1 (various operations, wraps AccountingJournal::getData()+
	 * writeIntoBookkeeping()) and 5 (expense reports, wraps AccountingJournal::
	 * writeIntoBookkeepingForExpenseReports()). Other natures are not yet implemented via API -
	 * see roadmap/backlog.md Phase 3b.
	 *
	 * @param	int		$id				Accounting journal ID
	 * @param	array	$request_data	Request data
	 * @phan-param array{date_start?:int,date_end?:int} $request_data
	 * @phpstan-param array{date_start?:int,date_end?:int} $request_data
	 * @return	array
	 * @phan-return array{success:bool,nb_errors:int}
	 * @phpstan-return array{success:bool,nb_errors:int}
	 *
	 * @url		POST journals/{id}/transfer
	 *
	 * @throws	RestException	400	Bad parameters, or transfer not implemented for this journal's nature
	 * @throws	RestException	403	Insufficient rights
	 * @throws	RestException	404	Accounting journal not found
	 */
	public function transfer($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'bind', 'write')) {
			throw new RestException(403, 'No permission to transfer accounting movements');
		}

		$journal = new AccountingJournal($this->db);
		$result = $journal->fetch($id);
		if (!$result) {
			throw new RestException(404, 'Accounting journal not found');
		}

		$date_start = !empty($request_data['date_start']) ? (int) $request_data['date_start'] : 0;
		$date_end = !empty($request_data['date_end']) ? (int) $request_data['date_end'] : 0;
		if (empty($date_start) || empty($date_end)) {
			throw new RestException(400, 'date_start and date_end are mandatory');
		}

		if ((int) $journal->nature === 1) {
			$journal_data = $journal->getData(DolibarrApiAccess::$user, 'bookkeeping', $date_start, $date_end, 'notyet');
			$result = $journal->writeIntoBookkeeping(DolibarrApiAccess::$user, $journal_data);
		} elseif ((int) $journal->nature === 5) {
			$result = $journal->writeIntoBookkeepingForExpenseReports(DolibarrApiAccess::$user, $date_start, $date_end);
		} else {
			throw new RestException(400, 'Transfer via API is not yet implemented for this journal type (nature '.$journal->nature.'); see roadmap/backlog.md Phase 3b');
		}

		return array(
			'success' => $result >= 0,
			'nb_errors' => $result < 0 ? abs($result) : 0,
		);
	}

	/**
	 * Preview pending (not-yet-journalized) accounting movements for a journal, without writing.
	 *
	 * Kept deliberately simple: a list of pending documents and whether each already carries an
	 * error flag, not a full trial-balance preview (computing debit/credit subtotals without
	 * writing would mean re-deriving the write loop's math a second time).
	 *
	 * @param	int		$id				Accounting journal ID
	 * @param	int		$date_start		Start date (timestamp)
	 * @param	int		$date_end		End date (timestamp)
	 * @return	array
	 * @phan-return array{nb_elements:int,items:array<array{ref:string,has_error:bool}>}
	 * @phpstan-return array{nb_elements:int,items:array<array{ref:string,has_error:bool}>}
	 *
	 * @url		GET journals/{id}/pendingdata
	 *
	 * @throws	RestException	400	Bad parameters, or preview not implemented for this journal's nature
	 * @throws	RestException	403	Insufficient rights
	 * @throws	RestException	404	Accounting journal not found
	 */
	public function pendingData($id, $date_start = 0, $date_end = 0)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'bind', 'write') && !DolibarrApiAccess::$user->hasRight('accounting', 'mouvements', 'lire')) {
			throw new RestException(403, 'No permission to preview accounting movements');
		}

		$journal = new AccountingJournal($this->db);
		$result = $journal->fetch($id);
		if (!$result) {
			throw new RestException(404, 'Accounting journal not found');
		}

		if (empty($date_start) || empty($date_end)) {
			throw new RestException(400, 'date_start and date_end are mandatory');
		}

		$items = array();
		if ((int) $journal->nature === 1) {
			$journal_data = $journal->getData(DolibarrApiAccess::$user, 'bookkeeping', (int) $date_start, (int) $date_end, 'notyet');
			foreach ($journal_data as $element) {
				$items[] = array('ref' => (string) (!empty($element['ref']) ? $element['ref'] : ''), 'has_error' => !empty($element['error']));
			}
		} elseif ((int) $journal->nature === 5) {
			$data = $journal->getDataForExpenseReports(DolibarrApiAccess::$user, (int) $date_start, (int) $date_end, 'notyet');
			foreach ($data['taber'] as $key => $val) {
				$items[] = array('ref' => (string) $val['ref'], 'has_error' => !empty($data['errorforinvoice'][$key]));
			}
		} else {
			throw new RestException(400, 'Preview via API is not yet implemented for this journal type (nature '.$journal->nature.'); see roadmap/backlog.md Phase 3b');
		}

		return array(
			'nb_elements' => count($items),
			'items' => $items,
		);
	}

	/**
	 * Validate fields before creating an object
	 *
	 * @param ?array<string,string> $data   Data to validate
	 * @return void
	 *
	 * @throws RestException
	 */
	private function _validate($data)
	{
		if ($data === null) {
			$data = array();
		}
		foreach (self::$FIELDS as $field) {
			if (!isset($data[$field])) {
				throw new RestException(400, "$field field missing");
			}
		}
	}
}
