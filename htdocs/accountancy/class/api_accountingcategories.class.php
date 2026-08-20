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

require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountancycategory.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';

/**
 * API class for accounting account categories, and their assignment to accounting accounts
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class AccountingAccountCategories extends DolibarrApi
{
	/**
	 * @var string[] Mandatory fields, checked when creating an object
	 *
	 * code/label/range_account/sens/category_type/formula all map to NOT NULL columns with no
	 * DB default on c_accounting_category (llx_c_accounting_category-accounting.sql) — and
	 * AccountancyCategory::create() writes an explicit NULL for any of them left unset on the
	 * object, rather than omitting the column and letting a DB default apply — so all six are
	 * genuinely mandatory for create() to succeed, not just the two "identifying" fields.
	 * position/fk_country/active are nullable columns and stay optional.
	 */
	public static $FIELDS = array(
		'code',
		'label',
		'range_account',
		'sens',
		'category_type',
		'formula',
	);

	/**
	 * @var string[] Settable fields, matching what AccountancyCategory::create()/update() actually persist
	 */
	private static $SETTABLE_FIELDS = array(
		'code',
		'label',
		'range_account',
		'sens',
		'category_type',
		'formula',
		'position',
		'fk_country',
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
	 * Get the list of accounting account categories.
	 *
	 * @param string	$sortfield		Sort field
	 * @param string	$sortorder		Sort order
	 * @param int		$limit			Limit for list
	 * @param int		$page			Page number
	 * @param string	$sqlfilters		Other criteria to filter answers separated by a comma. Syntax example "(t.code:like:'CAT%')"
	 * @param string	$properties		Restrict the data returned to these properties. Ignored if empty. Comma separated list of properties names
	 * @return array					List of accounting account category objects
	 * @phan-return AccountancyCategory[]
	 * @phpstan-return AccountancyCategory[]
	 *
	 * @throws RestException
	 */
	public function index($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 100, $page = 0, $sqlfilters = '', $properties = '')
	{
		$list = array();

		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$sql = "SELECT t.rowid FROM ".MAIN_DB_PREFIX."c_accounting_category AS t";
		$sql .= " WHERE t.entity IN (".getEntity('c_accounting_category').")";

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
				$category = new AccountancyCategory($this->db);
				if ($category->fetch($obj->rowid) > 0 && !empty($category->id)) {
					$list[] = $this->_filterObjectProperties($this->_cleanObjectDatas($category), $properties);
				}
			}
		} else {
			throw new RestException(503, 'Error when retrieving list of accounting account categories: '.$this->db->lasterror());
		}

		return $list;
	}

	/**
	 * Get accounting account category by ID.
	 *
	 * @param	int		$id		ID of accounting account category
	 * @return	Object			Object with cleaned properties
	 *
	 * @throws RestException
	 */
	public function get($id)
	{
		return $this->_cleanObjectDatas($this->_fetch($id));
	}

	/**
	 * Shared fetch logic.
	 *
	 * AccountancyCategory::fetch() always returns 1 when the query itself succeeds, even if no
	 * row matched the given id (unlike AccountingAccount::fetch()) — so existence has to be
	 * checked on whether ->id actually got populated, not on fetch()'s own return value.
	 *
	 * @param	int		$id		ID of accounting account category
	 * @return	AccountancyCategory		Loaded object (not yet cleaned)
	 *
	 * @throws RestException
	 */
	private function _fetch($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$category = new AccountancyCategory($this->db);
		$result = $category->fetch($id);
		if ($result < 0 || empty($category->id)) {
			throw new RestException(404, 'Accounting account category not found');
		}

		return $category;
	}

	/**
	 * Create an accounting account category
	 *
	 * @param	array $request_data		Request data
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return	int						ID of accounting account category
	 *
	 * @throws RestException
	 */
	public function post($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$this->_validate($request_data);

		$category = new AccountancyCategory($this->db);
		foreach ($request_data as $field => $value) {
			if (!in_array($field, self::$SETTABLE_FIELDS)) {
				continue;
			}
			$category->$field = $this->_checkValForAPI($field, $value, $category);
		}

		$result = $category->create(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error creating accounting account category: '.$category->error);
		}
		return $result;
	}

	/**
	 * Update an accounting account category
	 *
	 * @param	int    $id              ID of accounting account category
	 * @param	array  $request_data    data
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return	Object					Object with cleaned properties
	 *
	 * @throws RestException
	 */
	public function put($id, $request_data = null)
	{
		$category = $this->_fetch($id);

		foreach ($request_data as $field => $value) {
			if (!in_array($field, self::$SETTABLE_FIELDS)) {
				continue;
			}
			$category->$field = $this->_checkValForAPI($field, $value, $category);
		}

		$result = $category->update(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error updating accounting account category: '.$category->error);
		}
		return $this->get($id);
	}

	/**
	 * Delete an accounting account category
	 *
	 * Rejected (409) if any accounting account is still assigned to this category — there is no
	 * DB-level FK/cascade protecting accounting_account.fk_accounting_category, so an unguarded
	 * delete would silently orphan those accounts' category assignment.
	 *
	 * @param	int    $id    ID of accounting account category
	 * @return	array
	 * @phan-return array{success:array{code:int,message:string}}
	 * @phpstan-return array{success:array{code:int,message:string}}
	 *
	 * @throws RestException
	 */
	public function delete($id)
	{
		$category = $this->_fetch($id);

		$accounts = $category->getCptsCat($id);
		if (!is_array($accounts)) {
			throw new RestException(500, 'Error checking accounts assigned to category: '.$category->error);
		}
		if (count($accounts) > 0) {
			throw new RestException(409, 'Cannot delete: '.count($accounts).' accounting account(s) are still assigned to this category');
		}

		$result = $category->delete(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error deleting accounting account category: '.$category->error);
		}

		return array(
			'success' => array(
				'code' => 200,
				'message' => 'Accounting account category deleted'
			)
		);
	}

	/**
	 * List the accounting accounts assigned to a category.
	 *
	 * @param	int		$id		ID of accounting account category
	 * @return	array			List of {id, account_number, account_label}
	 * @phan-return array<array{id:int,account_number:string,account_label:string}>
	 * @phpstan-return array<array{id:int,account_number:string,account_label:string}>
	 *
	 * @url GET {id}/accounts
	 *
	 * @throws RestException
	 */
	public function getAccounts($id)
	{
		$category = $this->_fetch($id);

		$accounts = $category->getCptsCat($id);
		if (!is_array($accounts)) {
			throw new RestException(500, 'Error retrieving accounts assigned to category: '.$category->error);
		}

		return $accounts;
	}

	/**
	 * Assign an accounting account to a category.
	 *
	 * AccountancyCategory::updateAccAcc() only matches accounts belonging to the currently
	 * active chart of accounts (CHARTOFACCOUNTS) and silently does nothing (no SQL error) for
	 * an account outside that chart — so the assignment is verified by re-fetching the account
	 * afterward, rather than trusting updateAccAcc()'s own success return alone.
	 *
	 * @param	int		$id			ID of accounting account category
	 * @param	int		$account_id	ID of accounting account
	 * @return	array
	 * @phan-return array{success:array{code:int,message:string}}
	 * @phpstan-return array{success:array{code:int,message:string}}
	 *
	 * @url POST {id}/accounts/{account_id}
	 *
	 * @throws RestException
	 */
	public function linkAccount($id, $account_id)
	{
		$category = $this->_fetch($id);

		$account = new AccountingAccount($this->db);
		if (!$account->fetch($account_id)) {
			throw new RestException(404, 'Accounting account not found');
		}

		$formatted = length_accountg($account->account_number);
		$result = $category->updateAccAcc($id, array($formatted => "'".$formatted."'"));
		if ($result < 0) {
			throw new RestException(500, 'Error assigning accounting account to category: '.$category->error);
		}

		$account->fetch($account_id);
		if ((int) $account->account_category !== (int) $id) {
			throw new RestException(400, 'Accounting account is not part of the currently active chart of accounts (CHARTOFACCOUNTS) and could not be assigned');
		}

		return array(
			'success' => array(
				'code' => 200,
				'message' => 'Accounting account assigned to category'
			)
		);
	}

	/**
	 * Unassign an accounting account from a category.
	 *
	 * @param	int		$id			ID of accounting account category
	 * @param	int		$account_id	ID of accounting account
	 * @return	array
	 * @phan-return array{success:array{code:int,message:string}}
	 * @phpstan-return array{success:array{code:int,message:string}}
	 *
	 * @url DELETE {id}/accounts/{account_id}
	 *
	 * @throws RestException
	 */
	public function unlinkAccount($id, $account_id)
	{
		$category = $this->_fetch($id);

		$result = $category->deleteCptCat($account_id);
		if ($result < 0) {
			throw new RestException(500, 'Error unassigning accounting account from category: '.$category->error);
		}

		return array(
			'success' => array(
				'code' => 200,
				'message' => 'Accounting account unassigned from category'
			)
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

// Dolibarr's API dispatcher (htdocs/api/index.php) resolves the class to load via
// ucwords($moduleobject), i.e. "Accountingcategories" for URL prefix "accountingcategories" —
// which doesn't match this class's real name. Alias it so class_exists() finds it.
class_alias('AccountingAccountCategories', 'Accountingcategories');
