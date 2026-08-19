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
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

/**
 * API class for binding/unbinding invoice lines to accounting accounts (the
 * "ventilation" step of the accountancy workflow).
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class AccountingBind extends DolibarrApi
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Check the accounting->bind->write permission required by every endpoint of this class.
	 *
	 * @return void
	 * @throws RestException
	 */
	private function _checkBindWritePermission()
	{
		if (!DolibarrApiAccess::$user->hasRight('accounting', 'bind', 'write')) {
			throw new RestException(403);
		}
	}

	/**
	 * Get the list of not-yet-bound customer invoice lines.
	 *
	 * @param string	$date_start		Start date (YYYY-MM-DD), filters on invoice date
	 * @param string	$date_end		End date (YYYY-MM-DD), filters on invoice date
	 * @param int		$socid			Thirdparty id to filter on, 0=no filter
	 * @param string	$sqlfilters		Other criteria to filter answers separated by a comma. Syntax example "(l.total_ht:>:100)"
	 * @param string	$sortfield		Sort field
	 * @param string	$sortorder		Sort order
	 * @param int		$limit			Limit for list
	 * @param int		$page			Page number
	 * @return array					List of unbound line summaries
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url GET customerlines/unbound
	 *
	 * @throws RestException
	 */
	public function getCustomerUnboundLines($date_start = '', $date_end = '', $socid = 0, $sqlfilters = '', $sortfield = "l.rowid", $sortorder = 'ASC', $limit = 100, $page = 0)
	{
		return $this->_getUnboundLines('customer', $date_start, $date_end, $socid, $sqlfilters, $sortfield, $sortorder, $limit, $page);
	}

	/**
	 * Get the list of not-yet-bound supplier invoice lines.
	 *
	 * @param string	$date_start		Start date (YYYY-MM-DD), filters on invoice date
	 * @param string	$date_end		End date (YYYY-MM-DD), filters on invoice date
	 * @param int		$socid			Thirdparty id to filter on, 0=no filter
	 * @param string	$sqlfilters		Other criteria to filter answers separated by a comma. Syntax example "(l.total_ht:>:100)"
	 * @param string	$sortfield		Sort field
	 * @param string	$sortorder		Sort order
	 * @param int		$limit			Limit for list
	 * @param int		$page			Page number
	 * @return array					List of unbound line summaries
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 *
	 * @url GET supplierlines/unbound
	 *
	 * @throws RestException
	 */
	public function getSupplierUnboundLines($date_start = '', $date_end = '', $socid = 0, $sqlfilters = '', $sortfield = "l.rowid", $sortorder = 'ASC', $limit = 100, $page = 0)
	{
		return $this->_getUnboundLines('supplier', $date_start, $date_end, $socid, $sqlfilters, $sortfield, $sortorder, $limit, $page);
	}

	/**
	 * Shared "unbound lines" listing logic for customer and supplier invoice lines. Mirrors the
	 * "WHERE f.fk_statut > 0 AND l.fk_code_ventilation <= 0" pattern used by
	 * accountancy/customer/list.php and accountancy/supplier/list.php.
	 *
	 * @param string	$type			'customer' or 'supplier'
	 * @param string	$date_start		Start date (YYYY-MM-DD)
	 * @param string	$date_end		End date (YYYY-MM-DD)
	 * @param int		$socid			Thirdparty id to filter on, 0=no filter
	 * @param string	$sqlfilters		Other criteria to filter answers separated by a comma.
	 * @param string	$sortfield		Sort field
	 * @param string	$sortorder		Sort order
	 * @param int		$limit			Limit for list
	 * @param int		$page			Page number
	 * @return array
	 * @phan-return array<int,array<string,mixed>>
	 * @phpstan-return array<int,array<string,mixed>>
	 * @throws RestException
	 */
	private function _getUnboundLines($type, $date_start, $date_end, $socid, $sqlfilters, $sortfield, $sortorder, $limit, $page)
	{
		$this->_checkBindWritePermission();

		if ($type == 'customer') {
			$invoicetable = 'facture';
			$linktofacture = 'l.fk_facture = f.rowid';
			$entityfilter = getEntity('invoice', 0);
			$linetable = 'facturedet';
		} else {
			$invoicetable = 'facture_fourn';
			$linktofacture = 'l.fk_facture_fourn = f.rowid';
			$entityfilter = getEntity('facture_fourn', 0);
			$linetable = 'facture_fourn_det';
		}

		$sql = "SELECT l.rowid as lineid, f.rowid as invoiceid, f.ref as invoiceref, s.rowid as socid, s.nom as thirdparty_name, f.datef as datef, l.description, l.total_ht, p.rowid as product_id, p.ref as product_ref";
		$sql .= " FROM ".MAIN_DB_PREFIX.$invoicetable." as f";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX.$linetable." as l ON ".$linktofacture;
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = l.fk_product";
		$sql .= " WHERE f.entity IN (".$entityfilter.")";
		$sql .= " AND f.fk_statut > 0 AND l.fk_code_ventilation <= 0";

		if ($date_start) {
			$sql .= " AND f.datef >= '".$this->db->escape($date_start)."'";
		}
		if ($date_end) {
			$sql .= " AND f.datef <= '".$this->db->escape($date_end)."'";
		}
		if ($socid > 0) {
			$sql .= " AND s.rowid = ".((int) $socid);
		}

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
		if (!$result) {
			throw new RestException(503, 'Error when retrieving list of unbound lines: '.$this->db->lasterror());
		}

		$list = array();
		$num = $this->db->num_rows($result);
		$min = min($num, ($limit <= 0 ? $num : $limit));
		for ($i = 0; $i < $min; $i++) {
			$obj = $this->db->fetch_object($result);
			$list[] = array(
				'lineid' => (int) $obj->lineid,
				'invoiceid' => (int) $obj->invoiceid,
				'invoiceref' => $obj->invoiceref,
				'socid' => (int) $obj->socid,
				'thirdparty_name' => $obj->thirdparty_name,
				'date' => $this->db->jdate($obj->datef),
				'description' => $obj->description,
				'total_ht' => (float) $obj->total_ht,
				'product_id' => $obj->product_id ? (int) $obj->product_id : null,
				'product_ref' => $obj->product_ref,
			);
		}

		return $list;
	}

	/**
	 * Suggest an accounting account for a customer invoice line. Pure wrap of
	 * AccountingAccount::getAccountingCodeToBind().
	 *
	 * @param int $lineid Id of invoice line
	 * @return array
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url GET customerlines/{lineid}/suggestaccount
	 *
	 * @throws RestException
	 */
	public function getCustomerLineSuggestAccount($lineid)
	{
		return $this->_suggestAccount('customer', $lineid);
	}

	/**
	 * Suggest an accounting account for a supplier invoice line. Pure wrap of
	 * AccountingAccount::getAccountingCodeToBind().
	 *
	 * @param int $lineid Id of invoice line
	 * @return array
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 *
	 * @url GET supplierlines/{lineid}/suggestaccount
	 *
	 * @throws RestException
	 */
	public function getSupplierLineSuggestAccount($lineid)
	{
		return $this->_suggestAccount('supplier', $lineid);
	}

	/**
	 * Shared suggest-account logic: builds the same buyer/seller/product/facture/factureDet
	 * inputs as the suggestion loop in accountancy/customer/list.php and
	 * accountancy/supplier/list.php, then wraps AccountingAccount::getAccountingCodeToBind().
	 *
	 * @param string	$type		'customer' or 'supplier'
	 * @param int		$lineid		Id of invoice line
	 * @return array
	 * @phan-return array<string,mixed>
	 * @phpstan-return array<string,mixed>
	 * @throws RestException
	 */
	private function _suggestAccount($type, $lineid)
	{
		$this->_checkBindWritePermission();

		global $mysoc, $conf;

		$chartaccountcode = dol_getIdFromCode($this->db, getDolGlobalString('CHARTOFACCOUNTS'), 'accounting_system', 'rowid', 'pcg_version');
		if (empty($chartaccountcode)) {
			throw new RestException(400, 'Chart of account system not selected');
		}

		if ($type == 'customer') {
			$invoicetable = 'facture';
			$linktofacture = 'l.fk_facture = f.rowid';
			$linetable = 'facturedet';
			$codesellbuy = 'accountancy_code_sell';
			$codesellbuyintra = 'accountancy_code_sell_intra';
			$codesellbuyexport = 'accountancy_code_sell_export';
			$companycodesellbuy = 'accountancy_code_sell';
		} else {
			$invoicetable = 'facture_fourn';
			$linktofacture = 'l.fk_facture_fourn = f.rowid';
			$linetable = 'facture_fourn_det';
			$codesellbuy = 'accountancy_code_buy';
			$codesellbuyintra = 'accountancy_code_buy_intra';
			$codesellbuyexport = 'accountancy_code_buy_export';
			$companycodesellbuy = 'accountancy_code_buy';
		}

		$sql = "SELECT f.rowid as facid, f.ref, f.datef, f.type as ftype, f.fk_facture_source,";
		$sql .= " l.rowid, l.total_ht, l.product_type as type_l, l.tva_tx as tva_tx_line, l.vat_src_code, l.description,";
		$sql .= " p.rowid as product_id, p.ref as product_ref, p.label as product_label, p.tva_tx as tva_tx_prod,";
		$sql .= " p.".$codesellbuy." as code_sellbuy, p.".$codesellbuyintra." as code_sellbuy_intra, p.".$codesellbuyexport." as code_sellbuy_export,";
		$sql .= " aa.rowid as aarowid, aa2.rowid as aarowid_intra, aa3.rowid as aarowid_export, aa4.rowid as aarowid_thirdparty,";
		$sql .= " s.rowid as socid, s.nom as name, s.tva_intra, s.email, s.client, s.fournisseur, s.code_client, s.code_fournisseur, s.code_compta, s.code_compta_fournisseur, s.".$companycodesellbuy." as company_code_sellbuy,";
		$sql .= " co.code as country_code";
		$sql .= " FROM ".MAIN_DB_PREFIX.$invoicetable." as f";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_country as co ON co.rowid = s.fk_pays";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX.$linetable." as l ON ".$linktofacture;
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = l.fk_product";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."accounting_account as aa  ON p.".$codesellbuy." = aa.account_number         AND aa.active = 1  AND aa.fk_pcg_version = '".$this->db->escape($chartaccountcode)."' AND aa.entity = ".((int) $conf->entity);
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."accounting_account as aa2 ON p.".$codesellbuyintra." = aa2.account_number  AND aa2.active = 1 AND aa2.fk_pcg_version = '".$this->db->escape($chartaccountcode)."' AND aa2.entity = ".((int) $conf->entity);
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."accounting_account as aa3 ON p.".$codesellbuyexport." = aa3.account_number AND aa3.active = 1 AND aa3.fk_pcg_version = '".$this->db->escape($chartaccountcode)."' AND aa3.entity = ".((int) $conf->entity);
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."accounting_account as aa4 ON s.".$companycodesellbuy." = aa4.account_number        AND aa4.active = 1 AND aa4.fk_pcg_version = '".$this->db->escape($chartaccountcode)."' AND aa4.entity = ".((int) $conf->entity);
		$sql .= " WHERE l.rowid = ".((int) $lineid);

		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(503, 'Error when fetching line: '.$this->db->lasterror());
		}
		if (!$this->db->num_rows($result)) {
			throw new RestException(404, 'Invoice line not found');
		}
		$objp = $this->db->fetch_object($result);

		$thirdpartystatic = new Societe($this->db);
		$thirdpartystatic->id = $objp->socid;
		$thirdpartystatic->name = $objp->name;
		$thirdpartystatic->client = $objp->client;
		$thirdpartystatic->fournisseur = $objp->fournisseur;
		$thirdpartystatic->code_client = $objp->code_client;
		$thirdpartystatic->code_compta = $objp->code_compta;
		$thirdpartystatic->code_compta_client = $objp->code_compta;
		$thirdpartystatic->code_fournisseur = $objp->code_fournisseur;
		$thirdpartystatic->code_compta_fournisseur = $objp->code_compta_fournisseur;
		$thirdpartystatic->email = $objp->email;
		$thirdpartystatic->country_code = $objp->country_code;
		$thirdpartystatic->tva_intra = $objp->tva_intra;
		$thirdpartystatic->code_compta_product = $objp->company_code_sellbuy;

		$product_static = new Product($this->db);
		$product_static->ref = $objp->product_ref;
		$product_static->id = $objp->product_id;
		$product_static->label = $objp->product_label;
		$product_static->tva_tx = $objp->tva_tx_prod;
		if ($type == 'customer') {
			$product_static->accountancy_code_sell = $objp->code_sellbuy;
			$product_static->accountancy_code_sell_intra = $objp->code_sellbuy_intra;
			$product_static->accountancy_code_sell_export = $objp->code_sellbuy_export;
		} else {
			$product_static->accountancy_code_buy = $objp->code_sellbuy;
			$product_static->accountancy_code_buy_intra = $objp->code_sellbuy_intra;
			$product_static->accountancy_code_buy_export = $objp->code_sellbuy_export;
		}

		$accountingAccountArray = array(
			'dom' => $objp->aarowid,
			'intra' => $objp->aarowid_intra,
			'export' => $objp->aarowid_export,
			'thirdparty' => $objp->aarowid_thirdparty,
		);

		$accountingaccount = new AccountingAccount($this->db);

		if ($type == 'customer') {
			$facturestatic = new Facture($this->db);
			$facturestatic->ref = $objp->ref;
			$facturestatic->id = $objp->facid;
			$facturestatic->type = $objp->ftype;
			$facturestatic->date = $this->db->jdate($objp->datef);
			$facturestatic->fk_facture_source = $objp->fk_facture_source;

			$facturedet = new FactureLigne($this->db);
			$facturedet->id = $objp->rowid;
			$facturedet->total_ht = $objp->total_ht;
			$facturedet->tva_tx = $objp->tva_tx_line;
			$facturedet->vat_src_code = $objp->vat_src_code;
			$facturedet->product_type = $objp->type_l;
			$facturedet->desc = $objp->description;

			$return = $accountingaccount->getAccountingCodeToBind($thirdpartystatic, $mysoc, $product_static, $facturestatic, $facturedet, $accountingAccountArray, 'customer');
		} else {
			$facturestatic = new FactureFournisseur($this->db);
			$facturestatic->ref = $objp->ref;
			$facturestatic->id = $objp->facid;
			$facturestatic->type = $objp->ftype;
			$facturestatic->date = $this->db->jdate($objp->datef);
			$facturestatic->fk_facture_source = $objp->fk_facture_source;

			$facturedet = new SupplierInvoiceLine($this->db);
			$facturedet->id = $objp->rowid;
			$facturedet->total_ht = $objp->total_ht;
			$facturedet->tva_tx = $objp->tva_tx_line;
			$facturedet->vat_src_code = $objp->vat_src_code;
			$facturedet->product_type = $objp->type_l;
			$facturedet->desc = $objp->description;

			$return = $accountingaccount->getAccountingCodeToBind($mysoc, $thirdpartystatic, $product_static, $facturestatic, $facturedet, $accountingAccountArray, 'supplier');
		}

		if (!is_array($return)) {
			throw new RestException(500, 'Error suggesting account: '.$accountingaccount->error);
		}

		return $return;
	}

	/**
	 * Bind a single customer invoice line to an accounting account.
	 *
	 * @param int	$lineid			Id of invoice line
	 * @param array	$request_data	Request data
	 * @phan-param array{accountid:int} $request_data
	 * @phpstan-param array{accountid:int} $request_data
	 * @return array
	 * @phan-return array{lineid:int,accountid:int}
	 * @phpstan-return array{lineid:int,accountid:int}
	 *
	 * @url PUT customerlines/{lineid}/bind
	 *
	 * @throws RestException
	 */
	public function putCustomerLineBind($lineid, $request_data = null)
	{
		return $this->_bindLine('customer', $lineid, $request_data);
	}

	/**
	 * Bind a single supplier invoice line to an accounting account.
	 *
	 * @param int	$lineid			Id of invoice line
	 * @param array	$request_data	Request data
	 * @phan-param array{accountid:int} $request_data
	 * @phpstan-param array{accountid:int} $request_data
	 * @return array
	 * @phan-return array{lineid:int,accountid:int}
	 * @phpstan-return array{lineid:int,accountid:int}
	 *
	 * @url PUT supplierlines/{lineid}/bind
	 *
	 * @throws RestException
	 */
	public function putSupplierLineBind($lineid, $request_data = null)
	{
		return $this->_bindLine('supplier', $lineid, $request_data);
	}

	/**
	 * Unbind a single customer invoice line.
	 *
	 * @param int $lineid Id of invoice line
	 * @return array
	 * @phan-return array{lineid:int,accountid:int}
	 * @phpstan-return array{lineid:int,accountid:int}
	 *
	 * @url PUT customerlines/{lineid}/unbind
	 *
	 * @throws RestException
	 */
	public function putCustomerLineUnbind($lineid)
	{
		return $this->_bindLine('customer', $lineid, array('accountid' => 0));
	}

	/**
	 * Unbind a single supplier invoice line.
	 *
	 * @param int $lineid Id of invoice line
	 * @return array
	 * @phan-return array{lineid:int,accountid:int}
	 * @phpstan-return array{lineid:int,accountid:int}
	 *
	 * @url PUT supplierlines/{lineid}/unbind
	 *
	 * @throws RestException
	 */
	public function putSupplierLineUnbind($lineid)
	{
		return $this->_bindLine('supplier', $lineid, array('accountid' => 0));
	}

	/**
	 * Shared single-line bind/unbind logic.
	 *
	 * @param string	$type			'customer' or 'supplier'
	 * @param int		$lineid			Id of invoice line
	 * @param ?array	$request_data	Request data, must contain 'accountid' (<=0 unbinds)
	 * @phan-param ?array{accountid:int} $request_data
	 * @phpstan-param ?array{accountid:int} $request_data
	 * @return array
	 * @phan-return array{lineid:int,accountid:int}
	 * @phpstan-return array{lineid:int,accountid:int}
	 * @throws RestException
	 */
	private function _bindLine($type, $lineid, $request_data)
	{
		$this->_checkBindWritePermission();

		if (!is_array($request_data) || !isset($request_data['accountid']) || !is_numeric($request_data['accountid'])) {
			throw new RestException(400, 'accountid field missing or not numeric');
		}
		$accountid = (int) $request_data['accountid'];

		$accountingaccount = new AccountingAccount($this->db);
		$result = $accountingaccount->bindInvoiceLine($lineid, $accountid, $type, DolibarrApiAccess::$user);
		if ($result <= 0) {
			throw new RestException(500, 'Error binding line: '.$accountingaccount->error);
		}

		return array('lineid' => (int) $lineid, 'accountid' => max(0, $accountid));
	}

	/**
	 * Mass-bind several customer invoice lines to the same accounting account. Never aborts on
	 * a single line's failure, matching the current UI mass-action semantics.
	 *
	 * @param array	$request_data	Request data
	 * @phan-param array{lineids:int[],accountid:int} $request_data
	 * @phpstan-param array{lineids:int[],accountid:int} $request_data
	 * @return array
	 * @phan-return array{success:int[],errors:array<int,array{lineid:int,error:string}>,nbok:int,nbko:int}
	 * @phpstan-return array{success:int[],errors:array<int,array{lineid:int,error:string}>,nbok:int,nbko:int}
	 *
	 * @url POST customerlines/bind
	 *
	 * @throws RestException
	 */
	public function postCustomerLinesBind($request_data = null)
	{
		return $this->_bindLines('customer', $request_data);
	}

	/**
	 * Mass-bind several supplier invoice lines to the same accounting account. Never aborts on
	 * a single line's failure, matching the current UI mass-action semantics.
	 *
	 * @param array	$request_data	Request data
	 * @phan-param array{lineids:int[],accountid:int} $request_data
	 * @phpstan-param array{lineids:int[],accountid:int} $request_data
	 * @return array
	 * @phan-return array{success:int[],errors:array<int,array{lineid:int,error:string}>,nbok:int,nbko:int}
	 * @phpstan-return array{success:int[],errors:array<int,array{lineid:int,error:string}>,nbok:int,nbko:int}
	 *
	 * @url POST supplierlines/bind
	 *
	 * @throws RestException
	 */
	public function postSupplierLinesBind($request_data = null)
	{
		return $this->_bindLines('supplier', $request_data);
	}

	/**
	 * Shared mass-bind logic.
	 *
	 * @param string	$type			'customer' or 'supplier'
	 * @param ?array	$request_data	Request data, must contain 'lineids' (non-empty array) and 'accountid'
	 * @phan-param ?array{lineids:int[],accountid:int} $request_data
	 * @phpstan-param ?array{lineids:int[],accountid:int} $request_data
	 * @return array
	 * @phan-return array{success:int[],errors:array<int,array{lineid:int,error:string}>,nbok:int,nbko:int}
	 * @phpstan-return array{success:int[],errors:array<int,array{lineid:int,error:string}>,nbok:int,nbko:int}
	 * @throws RestException
	 */
	private function _bindLines($type, $request_data)
	{
		$this->_checkBindWritePermission();

		if (!is_array($request_data) || empty($request_data['lineids']) || !is_array($request_data['lineids'])) {
			throw new RestException(400, 'lineids field missing or empty');
		}
		if (!isset($request_data['accountid']) || !is_numeric($request_data['accountid'])) {
			throw new RestException(400, 'accountid field missing or not numeric');
		}
		$accountid = (int) $request_data['accountid'];

		$success = array();
		$errors = array();

		foreach ($request_data['lineids'] as $lineid) {
			$accountingaccount = new AccountingAccount($this->db);
			$result = $accountingaccount->bindInvoiceLine((int) $lineid, $accountid, $type, DolibarrApiAccess::$user);
			if ($result > 0) {
				$success[] = (int) $lineid;
			} else {
				$errors[] = array('lineid' => (int) $lineid, 'error' => $accountingaccount->error);
			}
		}

		return array(
			'success' => $success,
			'errors' => $errors,
			'nbok' => count($success),
			'nbko' => count($errors),
		);
	}
}
