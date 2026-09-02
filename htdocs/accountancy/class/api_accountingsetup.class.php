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

require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountancysystem.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/fiscalyear.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

/**
 * API class for accountancy setup: chart-of-accounts models, fiscal years,
 * default accounting accounts, and the accounting-code fields of the
 * VAT-rate and social-contribution-type dictionaries.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class AccountingSetup extends DolibarrApi
{
	/**
	 * @var string[] Mandatory fields, checked when creating a fiscal year
	 */
	public static $FIELDS_FISCALYEAR = array(
		'label',
		'date_start',
	);

	/**
	 * @var string[] Mandatory fields, checked when creating a chart-of-accounts model
	 */
	public static $FIELDS_ACCOUNTINGSYSTEM = array(
		'pcg_version',
		'label',
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
	 * Get the list of chart-of-accounts models.
	 *
	 * @param string	$sortfield		Sort field
	 * @param string	$sortorder		Sort order
	 * @param int		$limit			Limit for list
	 * @param int		$page			Page number
	 * @param string	$sqlfilters		Other criteria to filter answers separated by a comma.
	 * @return array					List of chart-of-accounts model objects
	 * @phan-return AccountancySystem[]
	 * @phpstan-return AccountancySystem[]
	 *
	 * @url GET accountingsystems
	 *
	 * @throws RestException
	 */
	public function getAccountingSystems($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 100, $page = 0, $sqlfilters = '')
	{
		$list = array();

		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$sql = "SELECT t.rowid FROM ".MAIN_DB_PREFIX."accounting_system AS t";
		$sql .= " WHERE 1 = 1";

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
				$system = new AccountancySystem($this->db);
				if ($system->fetch($obj->rowid) > 0) {
					$list[] = $this->_cleanObjectDatas($system);
				}
			}
		} else {
			throw new RestException(503, 'Error when retrieving list of chart-of-accounts models: '.$this->db->lasterror());
		}

		return $list;
	}

	/**
	 * Get a chart-of-accounts model by ID.
	 *
	 * @param	int		$id		ID of chart-of-accounts model
	 * @return	Object			Object with cleaned properties
	 *
	 * @url GET accountingsystems/{id}
	 *
	 * @throws RestException
	 */
	public function getAccountingSystem($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$system = new AccountancySystem($this->db);
		$result = $system->fetch($id);
		if (!$result) {
			throw new RestException(404, 'Chart-of-accounts model not found');
		}

		return $this->_cleanObjectDatas($system);
	}

	/**
	 * Create a chart-of-accounts model.
	 *
	 * @param	array $request_data		Request data: pcg_version, label, active
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return	int						ID of chart-of-accounts model
	 *
	 * @url POST accountingsystems
	 *
	 * @throws RestException
	 */
	public function postAccountingSystem($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		if ($request_data === null) {
			$request_data = array();
		}
		foreach (self::$FIELDS_ACCOUNTINGSYSTEM as $field) {
			if (!isset($request_data[$field])) {
				throw new RestException(400, "$field field missing");
			}
		}

		$system = new AccountancySystem($this->db);
		$system->pcg_version = $this->_checkValForAPI('pcg_version', $request_data['pcg_version'], $system);
		$system->label = $this->_checkValForAPI('label', $request_data['label'], $system);
		$system->active = isset($request_data['active']) ? (int) $request_data['active'] : 0;

		$result = $system->create(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error creating chart-of-accounts model: '.$system->error);
		}
		return $result;
	}

	/**
	 * Update a chart-of-accounts model. Only label and active can be changed —
	 * pcg_version is immutable via this endpoint because llx_accounting_account.fk_pcg_version
	 * matches it by string value, not by rowid, so renaming it would silently orphan
	 * every account already bound to the old value.
	 *
	 * @param	int    $id              ID of chart-of-accounts model
	 * @param	array  $request_data    data: label, active
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return	Object					Object with cleaned properties
	 *
	 * @url PUT accountingsystems/{id}
	 *
	 * @throws RestException
	 */
	public function putAccountingSystem($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$system = new AccountancySystem($this->db);
		$result = $system->fetch($id);
		if (!$result) {
			throw new RestException(404, 'Chart-of-accounts model not found');
		}

		if ($request_data === null) {
			$request_data = array();
		}
		if (isset($request_data['pcg_version']) && $request_data['pcg_version'] != $system->pcg_version) {
			throw new RestException(400, 'pcg_version cannot be changed once created (it is referenced by string value from existing accounts)');
		}
		if (isset($request_data['label'])) {
			$system->label = $this->_checkValForAPI('label', $request_data['label'], $system);
		}
		if (isset($request_data['active'])) {
			$system->active = (int) $request_data['active'];
		}

		$result = $system->update(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error updating chart-of-accounts model: '.$system->error);
		}
		return $this->getAccountingSystem($id);
	}

	/**
	 * Delete a chart-of-accounts model. Refused if it is the currently active chart
	 * (CHARTOFACCOUNTS global) or if any accounting account still uses it, since
	 * neither is enforced by a database constraint.
	 *
	 * @param	int    $id    ID of chart-of-accounts model
	 * @return	array
	 * @phan-return array{success:array{code:int,message:string}}
	 * @phpstan-return array{success:array{code:int,message:string}}
	 *
	 * @url DELETE accountingsystems/{id}
	 *
	 * @throws RestException
	 */
	public function deleteAccountingSystem($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$system = new AccountancySystem($this->db);
		$result = $system->fetch($id);
		if (!$result) {
			throw new RestException(404, 'Chart-of-accounts model not found');
		}

		if ((int) getDolGlobalInt('CHARTOFACCOUNTS') === (int) $id) {
			throw new RestException(409, 'Cannot delete the currently active chart-of-accounts model');
		}

		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."accounting_account";
		$sql .= " WHERE fk_pcg_version = '".$this->db->escape($system->pcg_version)."'";
		$sqlresult = $this->db->query($sql);
		if ($sqlresult) {
			$obj = $this->db->fetch_object($sqlresult);
			if ($obj && $obj->nb > 0) {
				throw new RestException(409, 'Cannot delete a chart-of-accounts model that still has accounting accounts bound to it');
			}
		}

		$result = $system->delete(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error deleting chart-of-accounts model: '.$system->error);
		}

		return array(
			'success' => array(
				'code' => 200,
				'message' => 'Chart-of-accounts model deleted'
			)
		);
	}

	/**
	 * Resolve the country code and chart-of-accounts data file for a chart-of-accounts model,
	 * shared by the activation preview and activation endpoints below.
	 *
	 * @param	int		$id		ID of chart-of-accounts model
	 * @return	array
	 * @phan-return array{country_code:string,sqlfile:string,sqlfile_readable:bool}
	 * @phpstan-return array{country_code:string,sqlfile:string,sqlfile_readable:bool}
	 *
	 * @throws RestException
	 */
	private function _resolveActivationCountryCode($id)
	{
		$sql = "SELECT code FROM ".MAIN_DB_PREFIX."c_country as c, ".MAIN_DB_PREFIX."accounting_system as a";
		$sql .= " WHERE c.rowid = a.fk_country AND a.rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RestException(503, 'Error resolving country for chart-of-accounts model: '.$this->db->lasterror());
		}
		$obj = $this->db->fetch_object($resql);
		$country_code = ($obj && !empty($obj->code)) ? $obj->code : '';
		$sqlfile = $country_code ? DOL_DOCUMENT_ROOT.'/install/mysql/data/llx_accounting_account_'.strtolower($country_code).'.sql' : '';

		return array(
			'country_code' => $country_code,
			'sqlfile' => $sqlfile,
			'sqlfile_readable' => ($sqlfile !== '' && is_readable($sqlfile)),
		);
	}

	/**
	 * Preview what activating this chart-of-accounts model would do, without making any change:
	 * the country-specific chart-of-accounts data file that would be loaded, whether it exists
	 * and is readable, and whether this model is already the active one. Read-only — does not
	 * call run_sql() or dolibarr_set_const(). Call this before POST .../activate.
	 *
	 * @param	int		$id		ID of chart-of-accounts model
	 * @return	array
	 * @phan-return array{id:int,country_code:string,sqlfile:string,sqlfile_readable:bool,already_active:bool}
	 * @phpstan-return array{id:int,country_code:string,sqlfile:string,sqlfile_readable:bool,already_active:bool}
	 *
	 * @url GET accountingsystems/{id}/activate/preview
	 *
	 * @throws RestException
	 */
	public function getAccountingSystemActivatePreview($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$system = new AccountancySystem($this->db);
		$result = $system->fetch($id);
		if (!$result) {
			throw new RestException(404, 'Chart-of-accounts model not found');
		}

		$resolved = $this->_resolveActivationCountryCode($id);

		return array(
			'id' => (int) $id,
			'country_code' => $resolved['country_code'],
			'sqlfile' => $resolved['sqlfile'],
			'sqlfile_readable' => $resolved['sqlfile_readable'],
			'already_active' => ((int) getDolGlobalInt('CHARTOFACCOUNTS') === (int) $id),
		);
	}

	/**
	 * Activate a chart-of-accounts model: bulk-loads its country's chart-of-accounts data file
	 * into llx_accounting_account and points the CHARTOFACCOUNTS global at it. This is a
	 * high-impact operation that is not fully idempotent on reruns (see
	 * AccountancySystem::activate()), so callers must pass confirm=true explicitly, and
	 * re-activating the model that is already active is rejected outright rather than silently
	 * reloading it. Call GET accountingsystems/{id}/activate/preview first to see what this
	 * would do.
	 *
	 * @param	int		$id				ID of chart-of-accounts model
	 * @param	array	$request_data	Request data: confirm (must be true)
	 * @phan-param ?array<string,mixed> $request_data
	 * @phpstan-param ?array<string,mixed> $request_data
	 * @return	array
	 * @phan-return array{accountingsystem:Object,country_code:string,sqlfile:string}
	 * @phpstan-return array{accountingsystem:Object,country_code:string,sqlfile:string}
	 *
	 * @url POST accountingsystems/{id}/activate
	 *
	 * @throws RestException
	 */
	public function postAccountingSystemActivate($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$system = new AccountancySystem($this->db);
		$result = $system->fetch($id);
		if (!$result) {
			throw new RestException(404, 'Chart-of-accounts model not found');
		}

		if ($request_data === null) {
			$request_data = array();
		}
		if (empty($request_data['confirm'])) {
			throw new RestException(400, 'Set confirm=true to actually activate this chart of accounts. Call GET accountingsystems/{id}/activate/preview first to see what this will do.');
		}

		if ((int) getDolGlobalInt('CHARTOFACCOUNTS') === (int) $id) {
			throw new RestException(409, 'This chart of accounts is already active; re-running the load is not supported');
		}

		$resolved = $this->_resolveActivationCountryCode($id);

		$result = $system->activate(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error activating chart-of-accounts model: '.$system->error);
		}

		return array(
			'accountingsystem' => $this->getAccountingSystem($id),
			'country_code' => $resolved['country_code'],
			'sqlfile' => $resolved['sqlfile'],
		);
	}


	/**
	 * Get the list of fiscal years.
	 *
	 * @param string	$sortfield		Sort field
	 * @param string	$sortorder		Sort order
	 * @param int		$limit			Limit for list
	 * @param int		$page			Page number
	 * @param string	$sqlfilters		Other criteria to filter answers separated by a comma.
	 * @return array					List of fiscal year objects
	 * @phan-return Fiscalyear[]
	 * @phpstan-return Fiscalyear[]
	 *
	 * @url GET fiscalyears
	 *
	 * @throws RestException
	 */
	public function getFiscalyears($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 100, $page = 0, $sqlfilters = '')
	{
		$list = array();

		if (!DolibarrApiAccess::$user->hasRight('accounting', 'fiscalyear', 'write')) {
			throw new RestException(403);
		}

		$sql = "SELECT t.rowid FROM ".MAIN_DB_PREFIX."accounting_fiscalyear AS t";
		$sql .= " WHERE t.entity IN (".getEntity('accounting_fiscalyear').")";

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
				$fiscalyear = new Fiscalyear($this->db);
				if ($fiscalyear->fetch($obj->rowid) > 0) {
					$list[] = $this->_cleanObjectDatas($fiscalyear);
				}
			}
		} else {
			throw new RestException(503, 'Error when retrieving list of fiscal years: '.$this->db->lasterror());
		}

		return $list;
	}

	/**
	 * Get a fiscal year by ID.
	 *
	 * @param	int		$id		ID of fiscal year
	 * @return	Object			Object with cleaned properties
	 *
	 * @url GET fiscalyears/{id}
	 *
	 * @throws RestException
	 */
	public function getFiscalyear($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'fiscalyear', 'write')) {
			throw new RestException(403);
		}

		$fiscalyear = new Fiscalyear($this->db);
		$result = $fiscalyear->fetch($id);
		if ($result <= 0) {
			throw new RestException(404, 'Fiscal year not found');
		}

		return $this->_cleanObjectDatas($fiscalyear);
	}

	/**
	 * Create a fiscal year. Dates must not overlap an existing fiscal year
	 * (enforced by Fiscalyear::create() itself).
	 *
	 * @param	array $request_data		Request data: label, date_start, date_end (timestamps)
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return	int						ID of fiscal year
	 *
	 * @url POST fiscalyears
	 *
	 * @throws RestException
	 */
	public function postFiscalyear($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'fiscalyear', 'write')) {
			throw new RestException(403);
		}

		if ($request_data === null) {
			$request_data = array();
		}
		foreach (self::$FIELDS_FISCALYEAR as $field) {
			if (!isset($request_data[$field])) {
				throw new RestException(400, "$field field missing");
			}
		}

		$fiscalyear = new Fiscalyear($this->db);
		$fiscalyear->label = $this->_checkValForAPI('label', $request_data['label'], $fiscalyear);
		$fiscalyear->date_start = (int) $request_data['date_start'];
		$fiscalyear->date_end = isset($request_data['date_end']) ? (int) $request_data['date_end'] : 0;

		$result = $fiscalyear->create(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error creating fiscal year: '.$fiscalyear->error);
		}
		return $result;
	}

	/**
	 * Update a fiscal year. Dates must not overlap another existing fiscal
	 * year (enforced by Fiscalyear::update() itself).
	 *
	 * @param	int    $id              ID of fiscal year
	 * @param	array  $request_data    data: label, date_start, date_end (timestamps), status
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return	Object					Object with cleaned properties
	 *
	 * @url PUT fiscalyears/{id}
	 *
	 * @throws RestException
	 */
	public function putFiscalyear($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'fiscalyear', 'write')) {
			throw new RestException(403);
		}

		$fiscalyear = new Fiscalyear($this->db);
		$result = $fiscalyear->fetch($id);
		if ($result <= 0) {
			throw new RestException(404, 'Fiscal year not found');
		}

		if ($request_data === null) {
			$request_data = array();
		}
		if (isset($request_data['label'])) {
			$fiscalyear->label = $this->_checkValForAPI('label', $request_data['label'], $fiscalyear);
		}
		if (isset($request_data['date_start'])) {
			$fiscalyear->date_start = (int) $request_data['date_start'];
		}
		if (isset($request_data['date_end'])) {
			$fiscalyear->date_end = (int) $request_data['date_end'];
		}
		if (isset($request_data['status'])) {
			$fiscalyear->status = (int) $request_data['status'];
		}

		$result = $fiscalyear->update(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error updating fiscal year: '.$fiscalyear->error);
		}
		return $this->getFiscalyear($id);
	}

	/**
	 * Delete a fiscal year.
	 *
	 * @param	int    $id    ID of fiscal year
	 * @return	array
	 * @phan-return array{success:array{code:int,message:string}}
	 * @phpstan-return array{success:array{code:int,message:string}}
	 *
	 * @url DELETE fiscalyears/{id}
	 *
	 * @throws RestException
	 */
	public function deleteFiscalyear($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'fiscalyear', 'write')) {
			throw new RestException(403);
		}

		$fiscalyear = new Fiscalyear($this->db);
		$result = $fiscalyear->fetch($id);
		if ($result <= 0) {
			throw new RestException(404, 'Fiscal year not found');
		}

		$result = $fiscalyear->delete(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, 'Error deleting fiscal year: '.$fiscalyear->error);
		}

		return array(
			'success' => array(
				'code' => 200,
				'message' => 'Fiscal year deleted'
			)
		);
	}


	/**
	 * List of default-accounting-account constant names this API accepts, mirroring
	 * the conditional list built by htdocs/accountancy/admin/defaultaccounts.php.
	 * Must be kept in sync with that page if new constants are added there.
	 *
	 * @return string[]
	 */
	private function _getDefaultAccountConstNames()
	{
		global $mysoc;

		$names = array(
			'ACCOUNTING_ACCOUNT_CUSTOMER',
			'ACCOUNTING_ACCOUNT_SUPPLIER',
			'SALARIES_ACCOUNTING_ACCOUNT_PAYMENT',
		);

		if (isModEnabled('expensereport')) {
			$names[] = 'ACCOUNTING_ACCOUNT_EXPENSEREPORT';
		}

		$names[] = 'ACCOUNTING_PRODUCT_SOLD_ACCOUNT';
		if ($mysoc->isInEEC()) {
			$names[] = 'ACCOUNTING_PRODUCT_SOLD_INTRA_ACCOUNT';
		}
		$names[] = 'ACCOUNTING_PRODUCT_SOLD_EXPORT_ACCOUNT';
		$names[] = 'ACCOUNTING_PRODUCT_BUY_ACCOUNT';
		if ($mysoc->isInEEC()) {
			$names[] = 'ACCOUNTING_PRODUCT_BUY_INTRA_ACCOUNT';
		}
		$names[] = 'ACCOUNTING_PRODUCT_BUY_EXPORT_ACCOUNT';

		$names[] = 'ACCOUNTING_SERVICE_SOLD_ACCOUNT';
		if ($mysoc->isInEEC()) {
			$names[] = 'ACCOUNTING_SERVICE_SOLD_INTRA_ACCOUNT';
		}
		$names[] = 'ACCOUNTING_SERVICE_SOLD_EXPORT_ACCOUNT';
		$names[] = 'ACCOUNTING_SERVICE_BUY_ACCOUNT';
		if ($mysoc->isInEEC()) {
			$names[] = 'ACCOUNTING_SERVICE_BUY_INTRA_ACCOUNT';
		}
		$names[] = 'ACCOUNTING_SERVICE_BUY_EXPORT_ACCOUNT';

		$names[] = 'ACCOUNTING_VAT_SOLD_ACCOUNT';
		$names[] = 'ACCOUNTING_VAT_BUY_ACCOUNT';
		$names[] = 'ACCOUNTING_VAT_PAY_ACCOUNT';

		$names[] = 'ACCOUNTING_LT1_SOLD_ACCOUNT';
		$names[] = 'ACCOUNTING_LT1_BUY_ACCOUNT';
		$names[] = 'ACCOUNTING_LT1_PAY_ACCOUNT';
		$names[] = 'ACCOUNTING_LT2_SOLD_ACCOUNT';
		$names[] = 'ACCOUNTING_LT2_BUY_ACCOUNT';
		$names[] = 'ACCOUNTING_LT2_PAY_ACCOUNT';

		if (getDolGlobalString('ACCOUNTING_FORCE_ENABLE_VAT_REVERSE_CHARGE')) {
			$names[] = 'ACCOUNTING_VAT_BUY_REVERSE_CHARGES_CREDIT';
			$names[] = 'ACCOUNTING_VAT_BUY_REVERSE_CHARGES_DEBIT';
			$names[] = 'ACCOUNTING_LT1_BUY_REVERSE_CHARGES_CREDIT';
			$names[] = 'ACCOUNTING_LT1_BUY_REVERSE_CHARGES_DEBIT';
			$names[] = 'ACCOUNTING_LT2_BUY_REVERSE_CHARGES_CREDIT';
			$names[] = 'ACCOUNTING_LT2_BUY_REVERSE_CHARGES_DEBIT';
		}
		if (isModEnabled('bank')) {
			$names[] = 'ACCOUNTING_ACCOUNT_TRANSFER_CASH';
		}
		if (getDolGlobalString('INVOICE_USE_RETAINED_WARRANTY')) {
			$names[] = 'ACCOUNTING_ACCOUNT_CUSTOMER_RETAINED_WARRANTY';
		}
		if (isModEnabled('don')) {
			$names[] = 'DONATION_ACCOUNTINGACCOUNT';
		}
		if (isModEnabled('member')) {
			$names[] = 'ADHERENT_SUBSCRIPTION_ACCOUNTINGACCOUNT';
		}
		if (isModEnabled('loan')) {
			$names[] = 'LOAN_ACCOUNTING_ACCOUNT_CAPITAL';
			$names[] = 'LOAN_ACCOUNTING_ACCOUNT_INTEREST';
			$names[] = 'LOAN_ACCOUNTING_ACCOUNT_INSURANCE';
		}
		$names[] = 'ACCOUNTING_ACCOUNT_SUSPENSE';
		if (isModEnabled('invoice') || isModEnabled('supplier_invoice')) {
			$names[] = 'ACCOUNTING_ACCOUNT_DISCOUNT_GRANTED';
			$names[] = 'ACCOUNTING_ACCOUNT_DISCOUNT_RECEIVED';
		}
		$names[] = 'ACCOUNTING_ACCOUNT_CUSTOMER_DEPOSIT';
		$names[] = 'ACCOUNTING_ACCOUNT_SUPPLIER_DEPOSIT';

		return $names;
	}

	/**
	 * Get default accounting accounts (thirdparty/product/service/VAT/tax default bindings).
	 *
	 * @return array<string,string>	Map of constant name to accounting account value
	 *
	 * @url GET accountingdefaultaccounts
	 *
	 * @throws RestException
	 */
	public function getAccountingDefaultAccounts()
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$result = array();
		foreach ($this->_getDefaultAccountConstNames() as $constname) {
			$result[$constname] = getDolGlobalString($constname);
		}

		return $result;
	}

	/**
	 * Set default accounting accounts. Only constant names known to this
	 * installation's setup page are accepted; unknown keys are rejected.
	 *
	 * @param	array $request_data		Map of constant name to accounting account value
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return array<string,string>	Map of constant name to accounting account value, after update
	 *
	 * @url PUT accountingdefaultaccounts
	 *
	 * @throws RestException
	 */
	public function putAccountingDefaultAccounts($request_data = null)
	{
		global $conf;

		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		if ($request_data === null) {
			$request_data = array();
		}

		$allowed = $this->_getDefaultAccountConstNames();
		foreach ($request_data as $constname => $constvalue) {
			if (!in_array($constname, $allowed)) {
				throw new RestException(400, "Unknown or unavailable default account constant: $constname");
			}
		}

		foreach ($request_data as $constname => $constvalue) {
			$constvalue = sanitizeVal($constvalue, 'alphanohtml');
			if (!dolibarr_set_const($this->db, $constname, $constvalue, 'chaine', 0, '', $conf->entity)) {
				throw new RestException(500, "Error setting constant $constname");
			}
		}

		return $this->getAccountingDefaultAccounts();
	}


	/**
	 * Get the list of VAT rates (dictionary entries) with their accounting-code fields.
	 * Companion GET for putVatRateAccountingCodes() below, mirroring the
	 * getChargesocialesAccountingCodes()/putChargesocialesAccountingCode() pair further down -
	 * without this, discovering a VAT rate's id (needed by the PUT) requires going through the
	 * unrelated generic GET dictionary/vat endpoint (api_setup.class.php).
	 *
	 * @param	int	$active		Filter on active status, -1 for all
	 * @param	int	$fk_country	Country of the VAT rate, -1 = current company's country (default), 0 = all
	 * @return array<int,array{id:int,code:string,taux:float,label:string,accountancy_code_sell:string,accountancy_code_buy:string}>
	 *
	 * @url GET vatrates/accountingcodes
	 *
	 * @throws RestException
	 */
	public function getVatRatesAccountingCodes($active = 1, $fk_country = -1)
	{
		global $mysoc;

		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$list = array();

		$sql = "SELECT rowid, code, taux, note, fk_pays, accountancy_code_sell, accountancy_code_buy";
		$sql .= " FROM ".MAIN_DB_PREFIX."c_tva";
		$sql .= " WHERE 1 = 1";
		if ($active != -1) {
			$sql .= " AND active = ".((int) $active);
		}
		if ($fk_country == -1) {
			$sql .= " AND fk_pays = ".((int) $mysoc->country_id);
		} elseif ($fk_country > 0) {
			$sql .= " AND fk_pays = ".((int) $fk_country);
		}
		$sql .= " ORDER BY taux";

		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(503, 'Error when retrieving list of vat rates: '.$this->db->lasterror());
		}

		$num = $this->db->num_rows($result);
		for ($i = 0; $i < $num; $i++) {
			$obj = $this->db->fetch_object($result);
			$list[] = array(
				'id' => (int) $obj->rowid,
				'code' => (string) $obj->code,
				'taux' => (float) $obj->taux,
				'label' => (string) $obj->note,
				'accountancy_code_sell' => (string) $obj->accountancy_code_sell,
				'accountancy_code_buy' => (string) $obj->accountancy_code_buy,
			);
		}

		return $list;
	}

	/**
	 * Set the accounting-code fields of a VAT rate (dictionary entry).
	 * Only accountancy_code_sell and accountancy_code_buy are touched; the
	 * rate/label/country of the VAT dictionary entry are managed by the
	 * generic dictionary API/admin page, not here.
	 *
	 * @param	int   $id				ID of the VAT rate (llx_c_tva.rowid)
	 * @param	array $request_data		Request data: accountancy_code_sell, accountancy_code_buy
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return array{id:int,accountancy_code_sell:string,accountancy_code_buy:string}
	 *
	 * @url PUT vatrates/{id}/accountingcodes
	 *
	 * @throws RestException
	 */
	public function putVatRateAccountingCodes($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_tva WHERE rowid = ".((int) $id);
		$result = $this->db->query($sql);
		if (!$result || !$this->db->num_rows($result)) {
			throw new RestException(404, 'VAT rate not found');
		}

		if ($request_data === null) {
			$request_data = array();
		}
		$code_sell = isset($request_data['accountancy_code_sell']) ? sanitizeVal($request_data['accountancy_code_sell'], 'alphanohtml') : '';
		$code_buy = isset($request_data['accountancy_code_buy']) ? sanitizeVal($request_data['accountancy_code_buy'], 'alphanohtml') : '';

		$sql = "UPDATE ".MAIN_DB_PREFIX."c_tva SET";
		$sql .= " accountancy_code_sell = ".($code_sell !== '' ? "'".$this->db->escape($code_sell)."'" : "NULL");
		$sql .= ", accountancy_code_buy = ".($code_buy !== '' ? "'".$this->db->escape($code_buy)."'" : "NULL");
		$sql .= " WHERE rowid = ".((int) $id);

		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(500, 'Error updating VAT rate accounting codes: '.$this->db->lasterror());
		}

		return array(
			'id' => (int) $id,
			'accountancy_code_sell' => $code_sell,
			'accountancy_code_buy' => $code_buy,
		);
	}


	/**
	 * Get the accounting codes of social-contribution / tax types.
	 *
	 * @param	int	$active		Filter on active status, -1 for all
	 * @return array<int,array{id:int,code:string,label:string,accountancy_code:string}>
	 *
	 * @url GET chargesociales/accountingcodes
	 *
	 * @throws RestException
	 */
	public function getChargesocialesAccountingCodes($active = 1)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$list = array();

		$sql = "SELECT id, code, libelle as label, accountancy_code FROM ".MAIN_DB_PREFIX."c_chargesociales";
		$sql .= " WHERE 1 = 1";
		if ($active != -1) {
			$sql .= " AND active = ".((int) $active);
		}
		$sql .= " ORDER BY code";

		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(503, 'Error when retrieving list of tax types: '.$this->db->lasterror());
		}

		$num = $this->db->num_rows($result);
		for ($i = 0; $i < $num; $i++) {
			$obj = $this->db->fetch_object($result);
			$list[] = array(
				'id' => (int) $obj->id,
				'code' => $obj->code,
				'label' => $obj->label,
				'accountancy_code' => $obj->accountancy_code,
			);
		}

		return $list;
	}

	/**
	 * Set the accounting code of a social-contribution / tax type.
	 *
	 * @param	int   $id				ID of the tax type (llx_c_chargesociales.id)
	 * @param	array $request_data		Request data: accountancy_code
	 * @phan-param ?array<string,string> $request_data
	 * @phpstan-param ?array<string,string> $request_data
	 * @return array{id:int,accountancy_code:string}
	 *
	 * @url PUT chargesociales/{id}/accountingcodes
	 *
	 * @throws RestException
	 */
	public function putChargesocialesAccountingCode($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		$sql = "SELECT id FROM ".MAIN_DB_PREFIX."c_chargesociales WHERE id = ".((int) $id);
		$result = $this->db->query($sql);
		if (!$result || !$this->db->num_rows($result)) {
			throw new RestException(404, 'Tax type not found');
		}

		if ($request_data === null) {
			$request_data = array();
		}
		$code = isset($request_data['accountancy_code']) ? sanitizeVal($request_data['accountancy_code'], 'alphanohtml') : '';

		$sql = "UPDATE ".MAIN_DB_PREFIX."c_chargesociales SET";
		$sql .= " accountancy_code = ".($code !== '' ? "'".$this->db->escape($code)."'" : "NULL");
		$sql .= " WHERE id = ".((int) $id);

		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(500, 'Error updating tax type accounting code: '.$this->db->lasterror());
		}

		return array(
			'id' => (int) $id,
			'accountancy_code' => $code,
		);
	}
}
