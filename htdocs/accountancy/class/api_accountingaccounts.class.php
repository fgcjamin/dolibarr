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

require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';

/**
 * API class for chart of accounts (accounting accounts)
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class AccountingAccounts extends DolibarrApi
{
	/**
	 * @var string[] Mandatory fields, checked when creating an object
	 */
	public static $FIELDS = array(
		'fk_pcg_version',
		'account_number',
		'label',
	);

	/**
	 * @var string[] Settable fields, matching what AccountingAccount::create()/update() actually persist
	 */
	private static $SETTABLE_FIELDS = array(
		'fk_pcg_version',
		'pcg_type',
		'account_number',
		'account_parent',
		'label',
		'labelshort',
		'account_category',
		'active',
		'reconcilable',
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
	 * Get the list of accounting accounts (chart of accounts).
	 *
	 * @param string	$sortfield		Sort field
	 * @param string	$sortorder		Sort order
	 * @param int		$limit			Limit for list
	 * @param int		$page			Page number
	 * @param string	$sqlfilters		Other criteria to filter answers separated by a comma. Syntax example "(t.account_number:like:'411%')"
	 * @param string	$properties		Restrict the data returned to these properties. Ignored if empty. Comma separated list of properties names
	 * @return array					List of accounting account objects
	 * @phan-return AccountingAccount[]
	 * @phpstan-return AccountingAccount[]
	 *
	 * @throws RestException
	 */
	public function index($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 100, $page = 0, $sqlfilters = '', $properties = '')
	{
		$list = array();

		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$sql = "SELECT t.rowid FROM ".MAIN_DB_PREFIX."accounting_account AS t";
		$sql .= " WHERE t.entity IN (".getEntity('accounting_account').")";

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
				$account = new AccountingAccount($this->db);
				if ($account->fetch($obj->rowid) > 0) {
					$list[] = $this->_filterObjectProperties($this->_cleanObjectDatas($account), $properties);
				}
			}
		} else {
			throw new RestException(503, 'Error when retrieving list of accounting accounts: '.$this->db->lasterror());
		}

		return $list;
	}

	/**
	 * Get accounting account by ID.
	 *
	 * @param	int		$id		ID of accounting account
	 * @return	Object			Object with cleaned properties
	 *
	 * @throws RestException
	 */
	public function get($id)
	{
		return $this->_fetch($id);
	}

	/**
	 * Get accounting account by account number.
	 *
	 * @param	string	$account_number		Account number
	 * @return	Object						Object with cleaned properties
	 *
	 * @url GET byaccountnumber/{account_number}
	 *
	 * @throws RestException
	 */
	public function getByAccountNumber($account_number)
	{
		return $this->_fetch(0, $account_number);
	}

	/**
	 * Shared fetch logic
	 *
	 * @param	int		$id					ID of accounting account
	 * @param	string	$account_number		Account number
	 * @return	Object						Object with cleaned properties
	 *
	 * @throws RestException
	 */
	private function _fetch($id, $account_number = '')
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$account = new AccountingAccount($this->db);
		$result = $account->fetch($id, $account_number ? $account_number : null);
		if (!$result) {
			throw new RestException(404, 'Accounting account not found');
		}

		return $this->_cleanObjectDatas($account);
	}

	/**
	 * Create an accounting account
	 *
	 * @param	array $request_data		Request data
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return	int						ID of accounting account
	 *
	 * @throws RestException
	 */
	public function post($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$this->_validate($request_data);

		$account = new AccountingAccount($this->db);
		foreach ($request_data as $field => $value) {
			if (!in_array($field, self::$SETTABLE_FIELDS)) {
				continue;
			}
			$account->$field = $this->_checkValForField($field, $value, $account);
		}

		$result = $account->create(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error creating accounting account: '.$account->error);
		}
		return $result;
	}

	/**
	 * Update an accounting account
	 *
	 * @param	int    $id              ID of accounting account
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

		$account = new AccountingAccount($this->db);
		$result = $account->fetch($id);
		if (!$result) {
			throw new RestException(404, 'Accounting account not found');
		}

		foreach ($request_data as $field => $value) {
			if (!in_array($field, self::$SETTABLE_FIELDS)) {
				continue;
			}
			$account->$field = $this->_checkValForField($field, $value, $account);
		}

		$result = $account->update(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error updating accounting account: '.$account->error);
		}
		return $this->get($id);
	}

	/**
	 * Delete an accounting account
	 *
	 * @param	int    $id    ID of accounting account
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

		$account = new AccountingAccount($this->db);
		$result = $account->fetch($id);
		if (!$result) {
			throw new RestException(404, 'Accounting account not found');
		}

		$result = $account->delete(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error deleting accounting account: '.$account->error);
		}

		return array(
			'success' => array(
				'code' => 200,
				'message' => 'Accounting account deleted'
			)
		);
	}

	/**
	 * Sanitize a single field value, working around DolibarrApi::_checkValForAPI()'s
	 * fk_-prefix heuristic: fk_pcg_version is a varchar chart-of-accounts code
	 * (e.g. 'PCG25-DEV'), not an integer id, despite its name.
	 *
	 * @param	string	$field		Field name
	 * @param	mixed	$value		Raw value
	 * @param	AccountingAccount	$account	Object being populated
	 * @return	mixed	Sanitized value
	 */
	private function _checkValForField($field, $value, $account)
	{
		if ($field === 'fk_pcg_version') {
			return sanitizeVal($value, 'alphanohtml');
		}
		return $this->_checkValForAPI($field, $value, $account);
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
