<?php
/* Copyright (C) 2017-2022	OpenDSI						<support@open-dsi.fr>
 * Copyright (C) 2024-2025	MDW							<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2024-2025	Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2024		Alexandre Janniaux			<alexandre.janniaux@gmail.com>
 * Copyright (C) 2025		Alexandre Spangaro			<alexandre@inovea-conseil.com>
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
 * \file		htdocs/accountancy/class/accountingjournal.class.php
 * \ingroup		Accountancy (Double entries)
 * \brief		File of class to manage accounting journals
 */

/**
 * Class to manage accounting journals
 */
class AccountingJournal extends CommonObject
{
	/**
	 * @var string ID to identify managed object
	 */
	public $element = 'accounting_journal';

	/**
	 * @var string Name of table without prefix where object is stored
	 */
	public $table_element = 'accounting_journal';

	/**
	 * @var string Fieldname with ID of parent key if this field has a parent
	 */
	public $fk_element = '';

	/**
	 * @var string String with name of icon for myobject. Must be the part after the 'object_' into object_myobject.png
	 */
	public $picto = 'generic';

	/**
	 * @var int ID
	 */
	public $rowid;

	/**
	 * @var string Accounting journal code
	 */
	public $code;

	/**
	 * @var string Accounting Journal label
	 */
	public $label;

	/**
	 * @var int 1:various operations, 2:sale, 3:purchase, 4:bank, 5:expense-report, 8:inventory, 9: has-new
	 */
	public $nature;

	/**
	 * @var int is active or not
	 */
	public $active;

	/**
	 * @var array<int,array{ref:string,error:string}>	Per-invoice/report error detail from the last
	 *      writeIntoBookkeeping()/writeIntoBookkeepingForXxx() call (natures 1/2/3/5 only - natures
	 *      4/bank-treasury have no error-map to enrich, see roadmap/backlog.md). Keyed by the same
	 *      invoice/report id used in the internal $errorforinvoice map. Reset at the start of each call.
	 */
	public $errorforinvoicedetail = array();

	/**
	 * @var array<string,array{found:bool,label:string,code_formatted_1:string,label_formatted_1:string,label_formatted_2:string}> 	Accounting account cached
	 */
	public static $accounting_account_cached = array();

	/**
	 * @var array<int,string>	Nature mapping
	 */
	public static $nature_maps = array(
		1 => 'variousoperations',
		2 => 'sells',
		3 => 'purchases',
		4 => 'bank',
		5 => 'expensereports',
		8 => 'inventories',
		9 => 'hasnew',
	);

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handle
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->ismultientitymanaged = 0;
	}

	/**
	 * Create a new Accounting Journal.
	 *
	 * @param  User	$user the user that created the journal, currently unused
	 * @return int  Return integer <0 on error, or the ID of the created object
	 */
	public function create($user)
	{
		$valid_nature = array(1, 2, 3, 4, 5, 8, 9);
		if (!in_array((int) $this->nature, $valid_nature)) {
			$this->error = get_class($this)."::Create Error invalid field nature '" . strval($this->nature) . "'";
			dol_syslog($this->error, LOG_ERR);
			return -1;
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."accounting_journal";
		$sql .= " (entity, code, label, nature, active)";
		$sql .= " VALUES ("
			. ((int) $this->entity)           .",'"
			. $this->db->escape($this->code)  ."','"
			. $this->db->escape($this->label) ."',"
			. ((int) $this->nature)           .","
			. ((int) $this->active)           .")";

		dol_syslog(get_class($this)."::create", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = get_class($this)."::Create Error: " . $this->db->lasterror();
			dol_syslog($this->error, LOG_ERR);
			return -1;
		}

		$id = $this->db->last_insert_id(MAIN_DB_PREFIX."accounting_journal");
		if ($id <= 0) {
			$this->error = get_class($this)."::Create Error " . $id . ": " . $this->db->lasterror();
			dol_syslog($this->error, LOG_ERR);
			return -2;
		}

		$this->id = $id;
		$this->rowid = $id;
		return $id;
	}

	/**
	 * Update an existing Accounting Journal.
	 *
	 * @param  User	$user the user that updated the journal, currently unused
	 * @return int  Return integer <0 on error, >0 if OK
	 */
	public function update($user)
	{
		global $conf;

		$valid_nature = array(1, 2, 3, 4, 5, 8, 9);
		if (!in_array((int) $this->nature, $valid_nature)) {
			$this->error = get_class($this)."::update Error invalid field nature '" . strval($this->nature) . "'";
			dol_syslog($this->error, LOG_ERR);
			return -1;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."accounting_journal";
		$sql .= " SET code = '" . $this->db->escape($this->code) . "'";
		$sql .= ", label = '" . $this->db->escape($this->label) . "'";
		$sql .= ", nature = " . ((int) $this->nature);
		$sql .= ", active = " . ((int) $this->active);
		$sql .= " WHERE rowid = " . ((int) $this->id);
		$sql .= " AND entity = " . ((int) $conf->entity);

		dol_syslog(get_class($this)."::update", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = get_class($this)."::update Error: " . $this->db->lasterror();
			dol_syslog($this->error, LOG_ERR);
			return -1;
		}

		return 1;
	}

	/**
	 * Delete an Accounting Journal.
	 *
	 * @return int  Return integer <0 on error, >0 if OK
	 */
	public function delete()
	{
		global $conf;

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."accounting_journal";
		$sql .= " WHERE rowid = " . ((int) $this->id);
		$sql .= " AND entity = " . ((int) $conf->entity);

		dol_syslog(get_class($this)."::delete", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = get_class($this)."::delete Error: " . $this->db->lasterror();
			dol_syslog($this->error, LOG_ERR);
			return -1;
		}

		return 1;
	}

	/**
	 * Load an object from database
	 *
	 * @param	int			$rowid			Id of record to load
	 * @param 	?string		$journal_code	Journal code
	 * @return	int							Return integer <0 if KO, Id of record if OK and found
	 */
	public function fetch($rowid = 0, $journal_code = null)
	{
		global $conf;

		if ($rowid || $journal_code) {
			$sql = "SELECT rowid, code, label, nature, active";
			$sql .= " FROM ".MAIN_DB_PREFIX."accounting_journal";
			$sql .= " WHERE";
			if ($rowid) {
				$sql .= " rowid = ".((int) $rowid);
			} elseif ($journal_code) {
				$sql .= " code = '".$this->db->escape($journal_code)."'";
				$sql .= " AND entity  = ".$conf->entity;
			}

			dol_syslog(get_class($this)."::fetch", LOG_DEBUG);
			$result = $this->db->query($sql);
			if ($result) {
				$obj = $this->db->fetch_object($result);

				if ($obj) {
					$this->id = $obj->rowid;
					$this->rowid		= $obj->rowid;

					$this->code			= $obj->code;
					$this->ref			= $obj->code;
					$this->label		= $obj->label;
					$this->nature		= $obj->nature;
					$this->active		= $obj->active;

					return $this->id;
				} else {
					return 0;
				}
			} else {
				$this->error = "Error ".$this->db->lasterror();
				$this->errors[] = "Error ".$this->db->lasterror();
			}
		}
		return -1;
	}

	/**
	 * Return clickable name (with picto eventually)
	 *
	 * @param	int		$withpicto		0=No picto, 1=Include picto into link, 2=Only picto
	 * @param	int		$withlabel		0=No label, 1=Include label of journal, 2=Include nature of journal
	 * @param	int  	$nourl			1=Disable url
	 * @param	string  $moretitle		Add more text to title tooltip
	 * @param	int  	$notooltip		1=Disable tooltip
	 * @return	string	String with URL
	 */
	public function getNomUrl($withpicto = 0, $withlabel = 0, $nourl = 0, $moretitle = '', $notooltip = 0)
	{
		global $langs, $conf, $hookmanager;

		if (!empty($conf->dol_no_mouse_hover)) {
			$notooltip = 1; // Force disable tooltips
		}

		$result = '';

		$url = dolBuildUrl(DOL_URL_ROOT.'/accountancy/admin/journals_list.php', ['id' => 35]);

		$label = '<u>'.$langs->trans("ShowAccountingJournal").'</u>';
		if (!empty($this->code)) {
			$label .= '<br><b>'.$langs->trans('Code').':</b> '.$this->code;
		}
		if (!empty($this->label)) {
			$label .= '<br><b>'.$langs->trans('Label').':</b> '.$langs->transnoentities($this->label);
		}
		if ($moretitle) {
			$label .= ' - '.$moretitle;
		}

		$linkclose = '';
		if (empty($notooltip)) {
			if (getDolGlobalString('MAIN_OPTIMIZEFORTEXTBROWSER')) {
				$label = $langs->trans("ShowAccountingJournal");
				$linkclose .= ' alt="'.dolPrintHTMLForAttribute($label).'"';
			}
			$linkclose .= ' title="'.dolPrintHTMLForAttribute($label).'"';
			$linkclose .= ' class="classfortooltip"';
		}

		$linkstart = '<a href="'.$url.'"';
		$linkstart .= $linkclose.'>';
		$linkend = '</a>';

		if ($nourl) {
			$linkstart = '';
			$linkclose = '';
			$linkend = '';
		}

		$label_link = $this->code;
		if ($withlabel == 1 && !empty($this->label)) {
			$label_link .= ' - '.($nourl ? '<span class="opacitymedium">' : '').$langs->transnoentities($this->label).($nourl ? '</span>' : '');
		}
		if ($withlabel == 2 && !empty($this->nature)) {
			$key = $langs->trans("AccountingJournalType".$this->nature);
			$transferlabel = ($key != "AccountingJournalType".strtoupper($langs->trans((string) $this->nature)) ? $key : $this->label);
			$label_link .= ' - '.($nourl ? '<span class="opacitymedium">' : '').$transferlabel.($nourl ? '</span>' : '');
		}

		$result .= $linkstart;
		if ($withpicto) {
			$result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), ($notooltip ? (($withpicto != 2) ? 'class="paddingright"' : '') : 'class="'.(($withpicto != 2) ? 'paddingright ' : '').'classfortooltip"'), 0, 0, $notooltip ? 0 : 1);
		}
		if ($withpicto != 2) {
			$result .= $label_link;
		}
		$result .= $linkend;

		global $action;
		$hookmanager->initHooks(array('accountingjournaldao'));
		$parameters = array('id' => $this->id, 'getnomurl' => &$result);
		$reshook = $hookmanager->executeHooks('getNomUrl', $parameters, $this, $action); // Note that $action and $object may have been modified by some hooks
		if ($reshook > 0) {
			$result = $hookmanager->resPrint;
		} else {
			$result .= $hookmanager->resPrint;
		}
		return $result;
	}

	/**
	 *  Return the label of the status
	 *
	 *  @param  int<0,6>	$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string 				       Label of status
	 */
	public function getLibType($mode = 0)
	{
		return $this->LibType($this->nature, $mode);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Return type of an accounting journal
	 *
	 *  @param	int			$nature		Id type
	 *  @param  int<0,1>	$mode	  	0=label long, 1=label short
	 *  @return string 				   	Label of type
	 */
	public function LibType($nature, $mode = 0)
	{
		// phpcs:enable
		global $langs;

		$langs->loadLangs(array("accountancy"));

		if ($mode == 0) {
			$prefix = '';
			if ($nature == 9) {
				return $langs->trans('AccountingJournalType9');
			} elseif ($nature == 5) {
				return $langs->trans('AccountingJournalType5');
			} elseif ($nature == 4) {
				return $langs->trans('AccountingJournalType4');
			} elseif ($nature == 3) {
				return $langs->trans('AccountingJournalType3');
			} elseif ($nature == 2) {
				return $langs->trans('AccountingJournalType2');
			} elseif ($nature == 1) {
				return $langs->trans('AccountingJournalType1');
			}
		} elseif ($mode == 1) {
			if ($nature == 9) {
				return $langs->trans('AccountingJournalType9');
			} elseif ($nature == 5) {
				return $langs->trans('AccountingJournalType5');
			} elseif ($nature == 4) {
				return $langs->trans('AccountingJournalType4');
			} elseif ($nature == 3) {
				return $langs->trans('AccountingJournalType3');
			} elseif ($nature == 2) {
				return $langs->trans('AccountingJournalType2');
			} elseif ($nature == 1) {
				return $langs->trans('AccountingJournalType1');
			}
		}
		return "";
	}


	/**
	 *  Get journal data
	 *
	 * @param 	User			$user				User who get infos
	 * @param 	string			$type				Type data returned ('view', 'bookkeeping', 'csv')
	 * @param 	int				$date_start			Filter 'start date'
	 * @param 	int				$date_end			Filter 'end date'
	 * @param 	string			$in_bookkeeping		Filter 'in bookkeeping' ('already', 'notyet')
	 * @return	int<-1,-1>|array<int,array{ref:string,error:?string,blocks:array<array<array{date:string,piece:string,account_accounting:string,subledger_account:string,label_operation:string,debit:string,credit:string}|array{doc_date:string,date_lim_reglement:string,doc_ref:string,date_creation:string,doc_type:string,fk_doc:string,fk_docdet:string,thirdparty_code:string,subledger_account:string,subledger_label:string,numero_compte:string,label_compte:string,label_operation:string,montant:string,sens:string,debit:string,credit:string,code_journal:string,journal_label:string,piece_num:string,import_key:string,fk_user_author:string,entity:string}>>}>	Return integer <0 if KO, array
	 */
	public function getData(User $user, $type = 'view', $date_start = null, $date_end = null, $in_bookkeeping = 'notyet')
	{
		global $hookmanager;

		// Clean parameters
		if (empty($type)) {
			$type = 'view';
		}
		if (empty($in_bookkeeping)) {
			$in_bookkeeping = 'notyet';
		}

		$data = array();

		$hookmanager->initHooks(array('accountingjournaldao'));
		$parameters = array('data' => &$data, 'user' => $user, 'type' => $type, 'date_start' => $date_start, 'date_end' => $date_end, 'in_bookkeeping' => $in_bookkeeping);
		$reshook = $hookmanager->executeHooks('getData', $parameters, $this); // Note that $action and $object may have been
		if ($reshook < 0) {
			$this->error = $hookmanager->error;
			$this->errors = $hookmanager->errors;
			return -1;
		} elseif (empty($reshook)) {
			switch ($this->nature) {
				case 1: // Various Journal
					if (isModEnabled('asset') && !getDolGlobalInt('ACCOUNTING_DISABLE_TRANSFER_ON_ASSETS')) {
						$tmp = $this->getAssetData($user, $type, $date_start, $date_end, $in_bookkeeping);
						if (is_array($tmp)) {
							$data = array_merge($data, $tmp);
						}
					}
					if (isModEnabled('invoice') && !getDolGlobalInt('ACCOUNTING_DISABLE_TRANSFER_ON_DISCOUNTS')) {
						$tmp = $this->getDiscountCustomer($user, $type, $date_start, $date_end, $in_bookkeeping);
						if (is_array($tmp)) {
							$data = array_merge($data, $tmp);
						}
					}
					if (isModEnabled('supplier_invoice') && !getDolGlobalInt('ACCOUNTING_DISABLE_TRANSFER_ON_DISCOUNTS')) {
						$tmp = $this->getDiscountSupplier($user, $type, $date_start, $date_end, $in_bookkeeping);
						if (is_array($tmp)) {
							$data = array_merge($data, $tmp);
						}
					}
					break;
					//              case 2: // Sells Journal
					//              case 3: // Purchases Journal
					//              case 4: // Bank Journal
					//              case 5: // Expense reports Journal
					//              case 8: // Inventory Journal
					//              case 9: // hasnew Journal
			}
		}

		return $data;
	}

	/**
	 *  Get asset data for various journal
	 *
	 * @param 	User			$user				User who get infos
	 * @param 	'view'|'bookkeeping'|'csv'	$type	Type data returned ('view', 'bookkeeping', 'csv')
	 * @param 	?int			$date_start			Filter 'start date'
	 * @param 	?int			$date_end			Filter 'end date'
	 * @param 	'already'|'notyet'	$in_bookkeeping		Filter 'in bookkeeping' ('already', 'notyet')
	 * @return	int<-1,-1>|array<int,array{ref:string,error:?string,blocks:array<array<array{date:string,piece:string,account_accounting:string,subledger_account:string,label_operation:string,debit:string,credit:string}|array{doc_date:''|int,date_lim_reglement:string,doc_ref:string,date_creation:int,doc_type:string,fk_doc:int|string,fk_docdet:int|string,thirdparty_code:string,subledger_account:string,subledger_label:string,numero_compte:string,label_compte:string,label_operation:string,montant:string,sens:string,debit:int|float|string,credit:int|float|string,code_journal:string,journal_label:string,piece_num:string,import_key:string,fk_user_author:string,entity:string}>>}>	Return integer <0 if KO, array
	 */
	public function getAssetData(User $user, $type = 'view', $date_start = null, $date_end = null, $in_bookkeeping = 'notyet')
	{
		global $conf, $langs;

		if (!isModEnabled('asset')) {
			return array();
		}

		require_once DOL_DOCUMENT_ROOT . '/core/lib/accounting.lib.php';
		require_once DOL_DOCUMENT_ROOT . '/asset/class/asset.class.php';
		require_once DOL_DOCUMENT_ROOT . '/asset/class/assetaccountancycodes.class.php';
		require_once DOL_DOCUMENT_ROOT . '/asset/class/assetdepreciationoptions.class.php';

		$langs->loadLangs(array("assets"));

		// Clean parameters
		if (empty($type)) {
			$type = 'view';
		}
		if (empty($in_bookkeeping)) {
			$in_bookkeeping = 'notyet';
		}

		$sql = "";
		$sql .= "SELECT ad.fk_asset AS rowid, a.ref AS asset_ref, a.label AS asset_label, a.acquisition_value_ht AS asset_acquisition_value_ht";
		$sql .= ", a.disposal_date AS asset_disposal_date, a.disposal_amount_ht AS asset_disposal_amount_ht, a.disposal_subject_to_vat AS asset_disposal_subject_to_vat";
		$sql .= ", ad.rowid AS depreciation_id, ad.depreciation_mode, ad.ref AS depreciation_ref, ad.depreciation_date, ad.depreciation_ht, ad.accountancy_code_debit, ad.accountancy_code_credit";
		$sql .= " FROM " . MAIN_DB_PREFIX . "asset_depreciation as ad";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "asset as a ON a.rowid = ad.fk_asset";
		$sql .= " WHERE a.entity IN (" . getEntity('asset', 0) . ')'; // We don't share object for accountancy, we use source object sharing
		$sql .= " AND a.status > 0";
		if ($in_bookkeeping == 'already') {
			$sql .= " AND EXISTS (SELECT iab.fk_docdet FROM " . MAIN_DB_PREFIX . "accounting_bookkeeping AS iab WHERE iab.fk_docdet = ad.rowid AND doc_type = 'asset')";
		} elseif ($in_bookkeeping == 'notyet') {
			$sql .= " AND NOT EXISTS (SELECT iab.fk_docdet FROM " . MAIN_DB_PREFIX . "accounting_bookkeeping AS iab WHERE iab.fk_docdet = ad.rowid AND doc_type = 'asset')";
		}
		$sql .= " AND ad.ref != ''"; // not reversal lines
		if ($date_start && $date_end) {
			$sql .= " AND ad.depreciation_date >= '" . $this->db->idate($date_start) . "' AND ad.depreciation_date <= '" . $this->db->idate($date_end) . "'";
		}
		// Define begin binding date
		if (getDolGlobalString('ACCOUNTING_DATE_START_BINDING')) {
			$sql .= " AND ad.depreciation_date >= '" . $this->db->idate(getDolGlobalInt('ACCOUNTING_DATE_START_BINDING')) . "'";
		}
		$sql .= " ORDER BY ad.depreciation_date";

		dol_syslog(__METHOD__, LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}

		$pre_data = array(
			'elements' => array(),
		);
		while ($obj = $this->db->fetch_object($resql)) {
			if (!isset($pre_data['elements'][$obj->rowid])) {
				$pre_data['elements'][$obj->rowid] = array(
					'ref' => $obj->asset_ref,
					'label' => $obj->asset_label,
					'acquisition_value_ht' => $obj->asset_acquisition_value_ht,
					'depreciation' => array(),
				);

				// Disposal infos
				if (isset($obj->asset_disposal_date)) {
					$pre_data['elements'][$obj->rowid]['disposal'] = array(
						'date' => $this->db->jdate($obj->asset_disposal_date),
						'amount' => $obj->asset_disposal_amount_ht,
						'subject_to_vat' => !empty($obj->asset_disposal_subject_to_vat),
					);
				}
			}

			$compta_debit = empty($obj->accountancy_code_debit) ? 'NotDefined' : $obj->accountancy_code_debit;
			$compta_credit = empty($obj->accountancy_code_credit) ? 'NotDefined' : $obj->accountancy_code_credit;

			$pre_data['elements'][$obj->rowid]['depreciation'][$obj->depreciation_id] = array(
				'date' => $this->db->jdate($obj->depreciation_date),
				'ref' => $obj->depreciation_ref,
				'lines' => array(
					$compta_debit => -$obj->depreciation_ht,
					$compta_credit => $obj->depreciation_ht,
				),
			);
		}

		$disposal_ref = $langs->transnoentitiesnoconv('AssetDisposal');
		$journal = $this->code;
		$journal_label = $this->label;
		$journal_label_formatted = $langs->transnoentities($journal_label);
		$now = dol_now();

		$element_static = new Asset($this->db);

		$journal_data = array();
		foreach ($pre_data['elements'] as $pre_data_id => $pre_data_info) {
			$element_static->id = $pre_data_id;
			$element_static->ref = (string) $pre_data_info["ref"];
			$element_static->label = (string) $pre_data_info["label"];
			$element_static->acquisition_value_ht = $pre_data_info["acquisition_value_ht"];
			$element_link = $element_static->getNomUrl(1, 'with_label');

			$element_name_formatted_0 = dol_trunc($element_static->label, 16);
			$label_operation = $element_static->getNomUrl(0, 'label', 16);

			$element = array(
				'ref' => dol_trunc($element_static->ref, 16, 'right', 'UTF-8', 1),
				'error' => array_key_exists('error', $pre_data_info) ? $pre_data_info['error'] : '',  // @phpstan-ignore-line
				'blocks' => array(),
			);

			// Depreciation lines
			//--------------------
			foreach ($pre_data_info['depreciation'] as $depreciation_id => $line) {
				$depreciation_ref = $line["ref"];
				$depreciation_date = $line["date"];
				$depreciation_date_formatted = dol_print_date($depreciation_date, 'day');

				// lines
				$blocks = array();
				foreach ($line['lines'] as $account => $mt) {
					$account_infos = $this->getAccountingAccountInfos($account);

					if ($type == 'view') {
						$account_to_show = length_accountg($account);
						if (($account_to_show == "") || $account_to_show == 'NotDefined') {
							$account_to_show = '<span class="error">' . $langs->trans("AssetInAccountNotDefined") . '</span>';
						}

						$blocks[] = array(
							'date' => $depreciation_date_formatted,
							'piece' => $element_link,
							'account_accounting' => $account_to_show,
							'subledger_account' => '',
							'label_operation' => $label_operation . ' - ' . $depreciation_ref,
							'debit' => $mt < 0 ? price(-$mt) : '',
							'credit' => $mt >= 0 ? price($mt) : '',
						);
					} elseif ($type == 'bookkeeping') {
						if ($account_infos['found']) {
							$blocks[] = array(
								'doc_date' => $depreciation_date,
								'date_lim_reglement' => '',
								'doc_ref' => $element_static->ref,
								'date_creation' => $now,
								'doc_type' => 'asset',
								'fk_doc' => $element_static->id,
								'fk_docdet' => $depreciation_id, // Useless, can be several lines that are source of this record to add
								'thirdparty_code' => '',
								'subledger_account' => '',
								'subledger_label' => '',
								'numero_compte' => $account,
								'label_compte' => $account_infos['label'],
								'label_operation' => $element_name_formatted_0 . ' - ' . $depreciation_ref,
								'montant' => $mt,
								'sens' => $mt < 0 ? 'D' : 'C',
								'debit' => $mt < 0 ? -$mt : 0,
								'credit' => $mt >= 0 ? $mt : 0,
								'code_journal' => $journal,
								'journal_label' => $journal_label_formatted,
								'piece_num' => '',
								'import_key' => '',
								'fk_user_author' => $user->id,
								'entity' => $conf->entity,
							);
						}
					} else { // $type == 'csv'
						$blocks[] = array(
							$depreciation_date,                                   	// Date
							$element_static->ref,                                	// Piece
							$account_infos['code_formatted_1'],                		// AccountAccounting
							$element_name_formatted_0 . ' - ' . $depreciation_ref,  // LabelOperation
							$mt < 0 ? price(-$mt) : '',                        		// Debit
							$mt >= 0 ? price($mt) : '',                        		// Credit
						);
					}
				}
				$element['blocks'][] = $blocks;
			}

			// Disposal line
			//--------------------
			if (!empty($pre_data_info['disposal'])) {
				$disposal_date = $pre_data_info['disposal']['date'];

				if ((!($date_start && $date_end) || ($date_start <= $disposal_date && $disposal_date <= $date_end)) &&
					(!getDolGlobalString('ACCOUNTING_DATE_START_BINDING') || getDolGlobalInt('ACCOUNTING_DATE_START_BINDING') <= $disposal_date)
				) {
					$disposal_amount = $pre_data_info['disposal']['amount'];
					$disposal_subject_to_vat = $pre_data_info['disposal']['subject_to_vat'];
					$disposal_date_formatted = dol_print_date($disposal_date, 'day');
					$disposal_vat = getDolGlobalInt('ASSET_DISPOSAL_VAT') > 0 ? getDolGlobalInt('ASSET_DISPOSAL_VAT') : 20;

					// Get accountancy codes
					//---------------------------
					require_once DOL_DOCUMENT_ROOT . '/asset/class/assetaccountancycodes.class.php';
					$accountancy_codes = new AssetAccountancyCodes($this->db);
					$result = $accountancy_codes->fetchAccountancyCodes($element_static->id);
					if ($result < 0) {
						$element['error'] = $accountancy_codes->errorsToString();
					} else {
						// Get last depreciation cumulative amount
						$element_static->fetchDepreciationLines();
						foreach ($element_static->depreciation_lines as $mode_key => $depreciation_lines) {
							$accountancy_codes_list = $accountancy_codes->accountancy_codes[$mode_key];

							if (!isset($accountancy_codes_list['value_asset_sold'])) {
								continue;
							}

							$accountancy_code_value_asset_sold = empty($accountancy_codes_list['value_asset_sold']) ? 'NotDefined' : $accountancy_codes_list['value_asset_sold'];
							$accountancy_code_depreciation_asset = empty($accountancy_codes_list['depreciation_asset']) ? 'NotDefined' : $accountancy_codes_list['depreciation_asset'];
							$accountancy_code_asset = empty($accountancy_codes_list['asset']) ? 'NotDefined' : $accountancy_codes_list['asset'];
							$accountancy_code_receivable_on_assignment = empty($accountancy_codes_list['receivable_on_assignment']) ? 'NotDefined' : $accountancy_codes_list['receivable_on_assignment'];
							$accountancy_code_vat_collected = empty($accountancy_codes_list['vat_collected']) ? 'NotDefined' : $accountancy_codes_list['vat_collected'];
							$accountancy_code_proceeds_from_sales = empty($accountancy_codes_list['proceeds_from_sales']) ? 'NotDefined' : $accountancy_codes_list['proceeds_from_sales'];

							$last_cumulative_amount_ht = 0;
							$depreciated_ids = array_keys($pre_data_info['depreciation']);
							foreach ($depreciation_lines as $line) {
								$last_cumulative_amount_ht = $line['cumulative_depreciation_ht'];
								if (!in_array($line['id'], $depreciated_ids) && empty($line['bookkeeping']) && !empty($line['ref'])) {
									break;
								}
							}

							$lines = array();
							$lines[0][$accountancy_code_value_asset_sold] = -((float) $element_static->acquisition_value_ht - $last_cumulative_amount_ht);
							$lines[0][$accountancy_code_depreciation_asset] = - (float) $last_cumulative_amount_ht;
							$lines[0][$accountancy_code_asset] = $element_static->acquisition_value_ht;

							$disposal_amount_vat = $disposal_subject_to_vat ? (float) price2num($disposal_amount * $disposal_vat / 100, 'MT') : 0;
							$lines[1][$accountancy_code_receivable_on_assignment] = -($disposal_amount + $disposal_amount_vat);
							if ($disposal_subject_to_vat) {
								$lines[1][$accountancy_code_vat_collected] = $disposal_amount_vat;
							}
							$lines[1][$accountancy_code_proceeds_from_sales] = $disposal_amount;

							foreach ($lines as $lines_block) {
								$blocks = array();
								foreach ($lines_block as $account => $mt) {
									$account_infos = $this->getAccountingAccountInfos($account);

									if ($type == 'view') {
										$account_to_show = length_accountg($account);
										if (($account_to_show == "") || $account_to_show == 'NotDefined') {
											$account_to_show = '<span class="error">' . $langs->trans("AssetInAccountNotDefined") . '</span>';
										}

										$blocks[] = array(
											'date' => $disposal_date_formatted,
											'piece' => $element_link,
											'account_accounting' => $account_to_show,
											'subledger_account' => '',
											'label_operation' => $label_operation . ' - ' . $disposal_ref,
											'debit' => $mt < 0 ? price(-$mt) : '',
											'credit' => $mt >= 0 ? price($mt) : '',
										);
									} elseif ($type == 'bookkeeping') {
										if ($account_infos['found']) {
											$blocks[] = array(
												'doc_date' => $disposal_date,
												'date_lim_reglement' => '',
												'doc_ref' => $element_static->ref,
												'date_creation' => $now,
												'doc_type' => 'asset',
												'fk_doc' => $element_static->id,
												'fk_docdet' => 0, // Useless, can be several lines that are source of this record to add
												'thirdparty_code' => '',
												'subledger_account' => '',
												'subledger_label' => '',
												'numero_compte' => $account,
												'label_compte' => $account_infos['label'],
												'label_operation' => $element_name_formatted_0 . ' - ' . $disposal_ref,
												'montant' => $mt,
												'sens' => $mt < 0 ? 'D' : 'C',
												'debit' => $mt < 0 ? -$mt : 0,
												'credit' => $mt >= 0 ? $mt : 0,
												'code_journal' => $journal,
												'journal_label' => $journal_label_formatted,
												'piece_num' => '',
												'import_key' => '',
												'fk_user_author' => $user->id,
												'entity' => $conf->entity,
											);
										}
									} else { // $type == 'csv'
										$blocks[] = array(
											$disposal_date,                                    // Date
											$element_static->ref,                              // Piece
											$account_infos['code_formatted_1'],                // AccountAccounting
											$element_name_formatted_0 . ' - ' . $disposal_ref, // LabelOperation
											$mt < 0 ? price(-$mt) : '',                        // Debit
											$mt >= 0 ? price($mt) : '',                        // Credit
										);
									}
								}
								$element['blocks'][] = $blocks;
							}
						}
					}
				}
			}

			$journal_data[(int) $pre_data_id] = $element;
		}
		unset($pre_data);

		return $journal_data;
	}

	/**
	 *  Get customer discount (escompte) data for various journal
	 *
	 * @param	User						$user				User who get infos
	 * @param	'view'|'bookkeeping'|'csv'	$type				Type data returned ('view', 'bookkeeping', 'csv')
	 * @param	?int						$date_start			Filter 'start date'
	 * @param	?int						$date_end			Filter 'end date'
	 * @param	'already'|'notyet'			$in_bookkeeping		Filter 'in bookkeeping' ('already', 'notyet')
	 * @return	int<-1,-1>|array<int,array{ref:string,error:?string,blocks:array<array<array{date:string,piece:string,account_accounting:string,subledger_account:string,label_operation:string,debit:string,credit:string}|array{doc_date:''|int,date_lim_reglement:string,doc_ref:string,date_creation:int,doc_type:string,fk_doc:int|string,fk_docdet:int|string,thirdparty_code:string,subledger_account:string,subledger_label:string,numero_compte:string,label_compte:string,label_operation:string,montant:string,sens:string,debit:int|float|string,credit:int|float|string,code_journal:string,journal_label:string,piece_num:string,import_key:string,fk_user_author:string,entity:string}>>}>    Return integer <0 if KO, array
	 */
	public function getDiscountCustomer(User $user, $type = 'view', $date_start = null, $date_end = null, $in_bookkeeping = 'notyet')
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/accounting.lib.php';

		$langs->loadLangs(array('bills'));

		// Clean parameters
		if (empty($type)) {
			$type = 'view';
		}
		if (empty($in_bookkeeping)) {
			$in_bookkeeping = 'notyet';
		}

		// Build SQL - Customer invoices closed by discount
		$sql = "SELECT f.rowid, f.ref, f.datef, f.date_closing, f.fk_soc, f.total_ttc";
		$sql .= " FROM ".MAIN_DB_PREFIX."facture as f";
		$sql .= " WHERE f.entity IN (".getEntity('invoice', 0).')'; // We don't share object for accountancy, we use source object sharing
		$sql .= " AND f.fk_statut > 0";
		if (getDolGlobalString('FACTURE_DEPOSITS_ARE_JUST_PAYMENTS')) {	// Non common setup
			$sql .= " AND f.type IN (".Facture::TYPE_STANDARD.",".Facture::TYPE_REPLACEMENT.",".Facture::TYPE_CREDIT_NOTE.",".Facture::TYPE_SITUATION.")";
		} else {
			$sql .= " AND f.type IN (".Facture::TYPE_STANDARD.",".Facture::TYPE_REPLACEMENT.",".Facture::TYPE_CREDIT_NOTE.",".Facture::TYPE_DEPOSIT.",".Facture::TYPE_SITUATION.")";
		}
		$sql .= " AND f.close_code = 'discount_vat'";
		if ($date_start && $date_end) {
			$sql .= " AND f.date_closing >= '".$this->db->idate($date_start)."' AND f.date_closing <= '".$this->db->idate($date_end)."'";
		}
		if (getDolGlobalString('ACCOUNTING_DATE_START_BINDING')) {
			$sql .= " AND f.date_closing >= '".$this->db->idate(getDolGlobalInt('ACCOUNTING_DATE_START_BINDING'))."'";
		}
		if ($in_bookkeeping == 'already') {
			$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."accounting_bookkeeping ab";
			$sql .= "              WHERE ab.doc_type = 'customer_invoice' AND ab.fk_doc = f.rowid";
			$sql .= "                AND ab.code_journal = '".$this->db->escape($this->code)."')";
		} elseif ($in_bookkeeping == 'notyet') {
			$sql .= " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."accounting_bookkeeping ab";
			$sql .= "              WHERE ab.doc_type = 'customer_invoice' AND ab.fk_doc = f.rowid";
			$sql .= "                AND ab.code_journal = '".$this->db->escape($this->code)."')";
		}
		$sql .= " ORDER BY f.date_closing";

		dol_syslog(__METHOD__, LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}

		$journal = $this->code;
		$journal_label_formatted = $langs->transnoentities($this->label);
		$now = dol_now();

		$journal_data = array();
		$invoice_static = new Facture($this->db);
		$customer_static = new Societe($this->db);

		// Accounting accounts
		$acc_disc_granted = getDolGlobalString('ACCOUNTING_ACCOUNT_DISCOUNT_GRANTED');
		$acc_vat_coll_def = getDolGlobalString('ACCOUNTING_VAT_BUY_ACCOUNT');			// Normal to apply vat default account for buy with customer's discount

		while ($obj = $this->db->fetch_object($resql)) {
			if ($invoice_static->fetch((int) $obj->rowid) <= 0) {
				continue;
			}

			$customer_static->fetch($invoice_static->socid);
			$account_customer_general = !empty($customer_static->accountancy_code_customer_general) ? $customer_static->accountancy_code_customer_general : getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER');
			$account_customer_subsidiary = !empty($customer_static->code_compta_client) ? $customer_static->code_compta_client : '';

			$piece_link = $invoice_static->getNomUrl(1, 'withlabel');

			// Discounted amount including tax
			$paid    = (float) price2num($invoice_static->getSommePaiement(), 'MT');
			$usedcn  = (float) price2num($invoice_static->getSumCreditNotesUsed(), 'MT');
			$useddep = (float) price2num($invoice_static->getSumDepositsUsed(), 'MT');
			$ttc_inv = (float) price2num($invoice_static->total_ttc, 'MT');
			$escompte_ttc = (float) price2num(max(0, $ttc_inv - $paid - $usedcn - $useddep), 'MT');
			if ($escompte_ttc <= 0) {
				continue;
			}

			$bookkeeping_static = new BookKeeping($this->db);
			$thirdpartyname = (string) $customer_static->name;
			$label_discount = $bookkeeping_static->accountingLabelForOperation($thirdpartyname, $invoice_static->ref, $langs->trans('DiscountGranted'));

			// Distribution including VAT by rate
			$ttcByRate = array();
			$totalTTC = 0.0;
			foreach ((array) $invoice_static->lines as $li) {
				$ttc = (float) $li->total_ttc;
				if (!$ttc) {
					continue;
				}
				$key = number_format((float) $li->tva_tx, 3, '.', '');
				if (!isset($ttcByRate[$key])) {
					$ttcByRate[$key] = 0.0;
				}
				$ttcByRate[$key] += $ttc;
				$totalTTC += $ttc;
			}
			if ($totalTTC <= 0) {
				$ttcByRate = array("0.000" => $escompte_ttc);
				$totalTTC = $escompte_ttc;
			}

			$element = array(
				'ref'   => dol_trunc($invoice_static->ref, 16, 'right', 'UTF-8', 1),
				'error' => '',
				'blocks' => array(),
			);

			$closingdate = !empty($obj->date_closing) ? $obj->date_closing : $obj->datef;

			$docdate = $this->db->jdate($closingdate);
			$docdate_fmt = dol_print_date($docdate, 'day');

			$sumTTC = 0.0;
			$i = 0;
			$n = count($ttcByRate);
			foreach ($ttcByRate as $rateStr => $ttcRateOnInvoice) {
				$i++;
				$rate = (float) $rateStr;

				$ttc_part = (float) $escompte_ttc * ($ttcRateOnInvoice / $totalTTC);
				if ($i == $n) {
					$ttc_part = (float) price2num($escompte_ttc - $sumTTC, 'MT');
				} else {
					$ttc_part = (float) price2num($ttc_part, 'MT');
					$sumTTC = (float) price2num($sumTTC + $ttc_part, 'MT');
				}

				if ($rate > 0) {
					$ht_part  = (float) price2num($ttc_part / (1 + $rate / 100), 'MT');
					$tva_part = (float) price2num($ttc_part - $ht_part, 'MT');
				} else {
					$ht_part = $ttc_part;
					$tva_part = 0.0;
				}

				// VAT deductible account (by rate if available)
				// TODO write function to search the same vat code like the invoice
				$acc_vat_coll = $acc_vat_coll_def;

				$lines_view = array();
				$lines_book = array();

				// Discount granted
				$acc_info_discountgranted = $this->getAccountingAccountInfos($acc_disc_granted);
				if ($type == 'view') {
					$lines_view[] = array(
						'date' => $docdate_fmt,
						'piece' => $piece_link,
						'account_accounting' => length_accountg($acc_disc_granted),
						'subledger_account' => '',
						'label_operation' => $label_discount." - " .$langs->transnoentitiesnoconv('HT') . " (".$rateStr."%)",
						'debit' => price($ht_part),
						'credit' => '',
					);
				} elseif ($type == 'bookkeeping' && $acc_info_discountgranted['found']) {
					$lines_book[] = array(
						'doc_date' => $docdate,
						'date_lim_reglement' => '',
						'doc_ref' => $invoice_static->ref,
						'date_creation' => $now,
						'doc_type' => 'customer_invoice',
						'fk_doc' => $invoice_static->id,
						'fk_docdet' => 0,
						'thirdparty_code' => $customer_static->code_client,
						'subledger_account' => '',
						'subledger_label' => '',
						'numero_compte' => $acc_disc_granted,
						'label_compte' => $acc_info_discountgranted['label'],
						'label_operation' => $label_discount." - " .$langs->transnoentitiesnoconv('HT') . " (".$rateStr."%)",
						'montant' => $ht_part,
						'sens' => 'D',
						'debit' => $ht_part,
						'credit' => 0,
						'code_journal' => $journal,
						'journal_label' => $journal_label_formatted,
						'piece_num' => 'OD-ESC-'.$invoice_static->ref,
						'import_key' => '',
						'fk_user_author' => $user->id,
						'entity' => $conf->entity,
					);
				}

				// VAT
				if ($tva_part > 0) {
					$acc_info_vatbuy = $this->getAccountingAccountInfos($acc_vat_coll);
					if ($type == 'view') {
						$lines_view[] = array(
							'date' => $docdate_fmt,
							'piece' => $piece_link,
							'account_accounting' => length_accountg($acc_vat_coll),
							'subledger_account' => '',
							'label_operation' => $label_discount." - " .$langs->transnoentitiesnoconv('VAT') . " (".$rateStr."%)",
							'debit' => price($tva_part),
							'credit' => '',
						);
					} elseif ($type == 'bookkeeping' && $acc_info_vatbuy['found']) {
						$lines_book[] = array(
							'doc_date' => $docdate,
							'date_lim_reglement' => '',
							'doc_ref' => $invoice_static->ref,
							'date_creation' => $now,
							'doc_type' => 'customer_invoice',
							'fk_doc' => $invoice_static->id,
							'fk_docdet' => 0,
							'thirdparty_code' => $customer_static->code_client,
							'subledger_account' => '',
							'subledger_label' => '',
							'numero_compte' => $acc_vat_coll,
							'label_compte' => $acc_info_vatbuy['label'],
							'label_operation' => $label_discount." - " .$langs->transnoentitiesnoconv('VAT') . " (".$rateStr."%)",
							'montant' => $tva_part,
							'sens' => 'D',
							'debit' => $tva_part,
							'credit' => 0,
							'code_journal' => $journal,
							'journal_label' => $journal_label_formatted,
							'piece_num' => 'OD-ESC-'.$invoice_static->ref,
							'import_key' => '',
							'fk_user_author' => $user->id,
							'entity' => $conf->entity,
						);
					}
				}

				// Thirdparty
				$acc_info_customeraccount = $this->getAccountingAccountInfos($account_customer_general);
				if ($type == 'view') {
					$lines_view[] = array(
						'date' => $docdate_fmt,
						'piece' => $piece_link,
						'account_accounting' => length_accountg($account_customer_general),
						'subledger_account' => length_accounta($account_customer_subsidiary),
						'label_operation' => $label_discount.' - '.$langs->transnoentitiesnoconv('Customer'),
						'debit' => '',
						'credit' => price($ttc_part),
					);
					$element['blocks'][] = $lines_view;
				} elseif ($type == 'bookkeeping' && $acc_info_customeraccount['found']) {
					$lines_book[] = array(
						'doc_date' => $docdate,
						'date_lim_reglement' => '',
						'doc_ref' => $invoice_static->ref,
						'date_creation' => $now,
						'doc_type' => 'customer_invoice',
						'fk_doc' => $invoice_static->id,
						'fk_docdet' => 0,
						'thirdparty_code' => $customer_static->code_client,
						'subledger_account' => $account_customer_subsidiary,
						'subledger_label' => $customer_static->name,
						'numero_compte' => $account_customer_general,
						'label_compte' => $acc_info_customeraccount['label'],
						'label_operation' => $label_discount.' - '.$langs->transnoentitiesnoconv('Customer'),
						'montant' => $ttc_part,
						'sens' => 'C',
						'debit' => 0,
						'credit' => $ttc_part,
						'code_journal' => $journal,
						'journal_label' => $journal_label_formatted,
						'piece_num' => 'OD-ESC-'.$invoice_static->ref,
						'import_key' => '',
						'fk_user_author' => $user->id,
						'entity' => $conf->entity,
					);
					$element['blocks'][] = $lines_book;
				} else { // CSV
					$element['blocks'][] = array(
						$docdate,                         // Date
						$invoice_static->ref,             // Piece
						length_accountg($acc_disc_granted), // Account
						$label_discount." (".$rateStr."%)",   // Label
						price($ht_part),                  // Debit
						'',                               // Credit
					);
					if ($tva_part > 0) {
						$element['blocks'][] = array(
							$docdate, $invoice_static->ref, length_accountg($acc_vat_coll), $label_discount." ". $langs->transnoentitiesnoconv('VAT') . " (".$rateStr."%)", price($tva_part), ''
						);
					}
					$element['blocks'][] = array(
						$docdate, $invoice_static->ref, length_accountg($account_customer_general), $label_discount.' - '.$langs->transnoentitiesnoconv('Customer'), '', price($ttc_part)
					);
				}
			}

			$journal_data[(int) $invoice_static->id] = $element;
		}

		return $journal_data;
	}

	/**
	 *  Get supplier discount (escompte) data for various journal
	 *
	 * @param	User						$user				User who get infos
	 * @param	'view'|'bookkeeping'|'csv'	$type				Type data returned ('view', 'bookkeeping', 'csv')
	 * @param	?int						$date_start			Filter 'start date'
	 * @param	?int						$date_end			Filter 'end date'
	 * @param	'already'|'notyet'			$in_bookkeeping		Filter 'in bookkeeping' ('already', 'notyet')
	 * @return	int<-1,-1>|array<int,array{ref:string,error:?string,blocks:array<array<array{date:string,piece:string,account_accounting:string,subledger_account:string,label_operation:string,debit:string,credit:string}|array{doc_date:''|int,date_lim_reglement:string,doc_ref:string,date_creation:int,doc_type:string,fk_doc:int|string,fk_docdet:int|string,thirdparty_code:string,subledger_account:string,subledger_label:string,numero_compte:string,label_compte:string,label_operation:string,montant:string,sens:string,debit:int|float|string,credit:int|float|string,code_journal:string,journal_label:string,piece_num:string,import_key:string,fk_user_author:string,entity:string}>>}>    Return integer <0 if KO, array
	 */
	public function getDiscountSupplier(User $user, $type = 'view', $date_start = null, $date_end = null, $in_bookkeeping = 'notyet')
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/accounting.lib.php';

		$langs->loadLangs(array('suppliers'));

		// Clean parameters
		if (empty($type)) {
			$type = 'view';
		}
		if (empty($in_bookkeeping)) {
			$in_bookkeeping = 'notyet';
		}

		// SQL - Supplier invoices closed by discount
		$sql = "SELECT ff.rowid, ff.ref, ff.datef, ff.date_closing, ff.fk_soc, ff.total_ttc";
		$sql .= " FROM ".MAIN_DB_PREFIX."facture_fourn as ff";
		$sql .= " WHERE ff.entity IN (".getEntity('facture_fourn', 0).")"; // We don't share object for accountancy
		$sql .= " AND ff.fk_statut > 0";
		if (getDolGlobalString('FACTURE_SUPPLIER_DEPOSITS_ARE_JUST_PAYMENTS')) {
			$sql .= " AND ff.type IN (".FactureFournisseur::TYPE_STANDARD.",".FactureFournisseur::TYPE_REPLACEMENT.",".FactureFournisseur::TYPE_CREDIT_NOTE.",".FactureFournisseur::TYPE_SITUATION.")";
		} else {
			$sql .= " AND ff.type IN (".FactureFournisseur::TYPE_STANDARD.",".FactureFournisseur::TYPE_REPLACEMENT.",".FactureFournisseur::TYPE_CREDIT_NOTE.",".FactureFournisseur::TYPE_DEPOSIT.",".FactureFournisseur::TYPE_SITUATION.")";
		}
		$sql .= " AND ff.close_code = 'discount_vat'";
		if ($date_start && $date_end) {
			$sql .= " AND ff.date_closing >= '".$this->db->idate($date_start)."' AND ff.date_closing <= '".$this->db->idate($date_end)."'";
		}
		if (getDolGlobalString('ACCOUNTING_DATE_START_BINDING')) {
			$sql .= " AND ff.date_closing >= '".$this->db->idate(getDolGlobalInt('ACCOUNTING_DATE_START_BINDING'))."'";
		}
		if ($in_bookkeeping == 'already') {
			$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."accounting_bookkeeping ab";
			$sql .= "              WHERE ab.doc_type = 'supplier_invoice' AND ab.fk_doc = ff.rowid";
			$sql .= "                AND ab.code_journal = '".$this->db->escape($this->code)."')";
		} elseif ($in_bookkeeping == 'notyet') {
			$sql .= " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."accounting_bookkeeping ab";
			$sql .= "              WHERE ab.doc_type = 'supplier_invoice' AND ab.fk_doc = ff.rowid";
			$sql .= "                AND ab.code_journal = '".$this->db->escape($this->code)."')";
		}
		$sql .= " ORDER BY ff.date_closing";

		dol_syslog(__METHOD__, LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}

		$journal = $this->code;
		$journal_label_formatted = $langs->transnoentities($this->label);
		$now = dol_now();

		$journal_data = array();
		$invoicesupplier_static = new FactureFournisseur($this->db);
		$supplier_static = new Societe($this->db);

		// Accounting accounts
		$acc_disc_recv    = getDolGlobalString('ACCOUNTING_ACCOUNT_DISCOUNT_RECEIVED');
		$acc_vat_ded_def  = getDolGlobalString('ACCOUNTING_VAT_SOLD_ACCOUNT');			// Normal to apply vat default account for sold with supplier's discount

		while ($obj = $this->db->fetch_object($resql)) {
			if ($invoicesupplier_static->fetch((int) $obj->rowid) <= 0) {
				continue;
			}

			$supplier_static->fetch($invoicesupplier_static->socid);
			$account_supplier_general = !empty($supplier_static->accountancy_code_supplier_general) ? $supplier_static->accountancy_code_supplier_general : getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER');
			$account_supplier_subsidiary = !empty($supplier_static->code_compta_fournisseur) ? $supplier_static->code_compta_fournisseur : '';

			$piece_link = $invoicesupplier_static->getNomUrl(1, 'withlabel');

			// Discounted amount including tax
			$paid    = (float) price2num($invoicesupplier_static->getSommePaiement(), 'MT');
			$usedcn  = (float) price2num($invoicesupplier_static->getSumCreditNotesUsed(), 'MT');
			$useddep = (float) price2num($invoicesupplier_static->getSumDepositsUsed(), 'MT');
			$ttc_inv = (float) price2num($invoicesupplier_static->total_ttc, 'MT');
			$escompte_ttc = (float) price2num(max(0, $ttc_inv - $paid - $usedcn - $useddep), 'MT');
			if ($escompte_ttc <= 0) {
				continue;
			}

			$bookkeeping_static = new BookKeeping($this->db);
			$thirdpartyname = (string) $supplier_static->name;
			$label_discount = $bookkeeping_static->accountingLabelForOperation($thirdpartyname, $invoicesupplier_static->ref, $langs->trans('DiscountReceived'));

			// Distribution including VAT by rate
			$ttcByRate = array();
			$totalTTC = 0.0;
			foreach ((array) $invoicesupplier_static->lines as $li) {
				$ttc = (float) $li->total_ttc;
				if (!$ttc) {
					continue;
				}
				$key = number_format((float) $li->tva_tx, 3, '.', '');
				if (!isset($ttcByRate[$key])) {
					$ttcByRate[$key] = 0.0;
				}
				$ttcByRate[$key] += $ttc;
				$totalTTC += $ttc;
			}
			if ($totalTTC <= 0) {
				$ttcByRate = array("0.000" => $escompte_ttc);
				$totalTTC = $escompte_ttc;
			}

			$element = array(
				'ref'   => dol_trunc($invoicesupplier_static->ref, 16, 'right', 'UTF-8', 1),
				'error' => '',
				'blocks' => array(),
			);

			$closingdate = !empty($obj->date_closing) ? $obj->date_closing : $obj->datef;

			$docdate = $this->db->jdate($closingdate);
			$docdate_fmt = dol_print_date($docdate, 'day');

			$sumTTC = 0.0;
			$i = 0;
			$n = count($ttcByRate);
			foreach ($ttcByRate as $rateStr => $ttcRateOnInvoice) {
				$i++;
				$rate = (float) $rateStr;

				$ttc_part = $escompte_ttc * ($ttcRateOnInvoice / $totalTTC);
				if ($i == $n) {
					$ttc_part = (float) price2num($escompte_ttc - $sumTTC, 'MT');
				} else {
					$ttc_part = (float) price2num($ttc_part, 'MT');
					$sumTTC = (float) price2num($sumTTC + $ttc_part, 'MT');
				}

				if ($rate > 0) {
					$ht_part  = (float) price2num($ttc_part / (1 + $rate / 100), 'MT');
					$tva_part = (float) price2num($ttc_part - $ht_part, 'MT');
				} else {
					$ht_part = $ttc_part;
					$tva_part = 0.0;
				}

				// VAT collected account (by rate if available)
				// TODO write function to search the same vat code like the supplier invoice
				$acc_vat_ded = $acc_vat_ded_def;

				$lines_view = array();
				$lines_book = array();

				// Thirdparty
				$acc_info_supplieraccount = $this->getAccountingAccountInfos($account_supplier_general);
				if ($type == 'view') {
					$lines_view[] = array(
						'date' => $docdate_fmt,
						'piece' => $piece_link,
						'account_accounting' => length_accountg($account_supplier_general),
						'subledger_account' => length_accounta($account_supplier_subsidiary),
						'label_operation' => $label_discount.' - '.$langs->transnoentitiesnoconv('Supplier'),
						'debit' => price($ttc_part),
						'credit' => '',
					);
				} elseif ($type == 'bookkeeping' && $acc_info_supplieraccount['found']) {
					$lines_book[] = array(
						'doc_date' => $docdate,
						'date_lim_reglement' => '',
						'doc_ref' => $invoicesupplier_static->ref,
						'date_creation' => $now,
						'doc_type' => 'supplier_invoice',
						'fk_doc' => $invoicesupplier_static->id,
						'fk_docdet' => 0,
						'thirdparty_code' => $supplier_static->code_fournisseur,
						'subledger_account' => $account_supplier_subsidiary,
						'subledger_label' => $supplier_static->name,
						'numero_compte' => $account_supplier_general,
						'label_compte' => $acc_info_supplieraccount['label'],
						'label_operation' => $label_discount.' - '.$langs->transnoentitiesnoconv('Supplier'),
						'montant' => $ttc_part,
						'sens' => 'D',
						'debit' => $ttc_part,
						'credit' => 0,
						'code_journal' => $journal,
						'journal_label' => $journal_label_formatted,
						'piece_num' => 'OD-ESC-FRS-'.$invoicesupplier_static->ref,
						'import_key' => '',
						'fk_user_author' => $user->id,
						'entity' => $conf->entity,
					);
				}

				// Discount received
				$acc_info_discountreceived = $this->getAccountingAccountInfos($acc_disc_recv);
				if ($type == 'view') {
					$lines_view[] = array(
						'date' => $docdate_fmt,
						'piece' => $piece_link,
						'account_accounting' => length_accountg($acc_disc_recv),
						'subledger_account' => '',
						'label_operation' => $label_discount." - " .$langs->transnoentitiesnoconv('HT') . " (".$rateStr."%)",
						'debit' => '',
						'credit' => price($ht_part),
					);
				} elseif ($type == 'bookkeeping' && $acc_info_discountreceived['found']) {
					$lines_book[] = array(
						'doc_date' => $docdate,
						'date_lim_reglement' => '',
						'doc_ref' => $invoicesupplier_static->ref,
						'date_creation' => $now,
						'doc_type' => 'supplier_invoice',
						'fk_doc' => $invoicesupplier_static->id,
						'fk_docdet' => 0,
						'thirdparty_code' => $supplier_static->code_fournisseur,
						'subledger_account' => '',
						'subledger_label' => '',
						'numero_compte' => $acc_disc_recv,
						'label_compte' => $acc_info_discountreceived['label'],
						'label_operation' => $label_discount." - " .$langs->transnoentitiesnoconv('HT') . " (".$rateStr."%)",
						'montant' => $ht_part,
						'sens' => 'C',
						'debit' => 0,
						'credit' => $ht_part,
						'code_journal' => $journal,
						'journal_label' => $journal_label_formatted,
						'piece_num' => 'OD-ESC-FRS-'.$invoicesupplier_static->ref,
						'import_key' => '',
						'fk_user_author' => $user->id,
						'entity' => $conf->entity,
					);
				}

				// VAT
				if ($tva_part > 0) {
					$acc_info_vatbuy = $this->getAccountingAccountInfos($acc_vat_ded);
					if ($type == 'view') {
						$lines_view[] = array(
							'date' => $docdate_fmt,
							'piece' => $piece_link,
							'account_accounting' => length_accountg($acc_vat_ded),
							'subledger_account' => '',
							'label_operation' => $label_discount." - " .$langs->transnoentitiesnoconv('VAT') . " (".$rateStr."%)",
							'debit' => '',
							'credit' => price($tva_part),
						);
						$element['blocks'][] = $lines_view;
					} elseif ($type == 'bookkeeping' && $acc_info_vatbuy['found']) {
						$lines_book[] = array(
							'doc_date' => $docdate,
							'date_lim_reglement' => '',
							'doc_ref' => $invoicesupplier_static->ref,
							'date_creation' => $now,
							'doc_type' => 'supplier_invoice',
							'fk_doc' => $invoicesupplier_static->id,
							'fk_docdet' => 0,
							'thirdparty_code' => $supplier_static->code_fournisseur,
							'subledger_account' => '',
							'subledger_label' => '',
							'numero_compte' => $acc_vat_ded,
							'label_compte' => $acc_info_vatbuy['label'],
							'label_operation' => $label_discount." - " .$langs->transnoentitiesnoconv('VAT') . " (".$rateStr."%)",
							'montant' => $tva_part,
							'sens' => 'C',
							'debit' => 0,
							'credit' => $tva_part,
							'code_journal' => $journal,
							'journal_label' => $journal_label_formatted,
							'piece_num' => 'OD-ESC-FRS-'.$invoicesupplier_static->ref,
							'import_key' => '',
							'fk_user_author' => $user->id,
							'entity' => $conf->entity,
						);
						$element['blocks'][] = $lines_book;
					}
				} else {
					// si TVA = 0, pousser les 2 lignes view/bookkeeping déjà constituées
					if ($type == 'view') {
						$element['blocks'][] = $lines_view;
					} elseif ($type == 'bookkeeping') {
						$element['blocks'][] = $lines_book;
					} else { // csv
						$element['blocks'][] = array($docdate, $invoicesupplier_static->ref, length_accountg($account_supplier_general), $label_discount.' - '.$langs->transnoentitiesnoconv('Supplier'), price($ttc_part), '');
						$element['blocks'][] = array($docdate, $invoicesupplier_static->ref, length_accountg($acc_disc_recv), $label_discount.' ('.$rateStr.'%)', '', price($ht_part));
					}
				}

				// CSV
				if ($type == 'csv') {
					$element['blocks'][] = array(
						$docdate, $invoicesupplier_static->ref, length_accountg($acc_vat_ded), $label_discount." ". $langs->transnoentitiesnoconv('VAT') . " (".$rateStr."%)", '', $tva_part > 0 ? price($tva_part) : ''
					);
				}
			}

			$journal_data[(int) $invoicesupplier_static->id] = $element;
		}

		return $journal_data;
	}

	/**
	 *  Write bookkeeping
	 *
	 * @param	User		$user				User who write in the bookkeeping
	 * @param	array<int,array{ref?:string,error?:string,blocks:array<array<array{doc_date:int|string,date_lim_reglement:int|string,doc_ref:string,date_creation:int,doc_type:string,fk_doc:int|string,fk_docdet:int|string,thirdparty_code:string,subledger_account:string,subledger_label:string,numero_compte:string,label_compte:string,label_operation:string,montant:float|string,sens:string,debit:int|float|string,credit:int|float|string,code_journal:string,journal_label:string,piece_num:int|string,import_key:string,fk_user_author:string,entity:string}>>}>	$journal_data		Journal data to write in the bookkeeping
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              $journal_data = array(
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .	id_element => array(
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'ref' => 'ref',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'error' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'blocks' => array(
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		pos_block => array(
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		num_line => array(
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'doc_date' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'date_lim_reglement' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'doc_ref' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'date_creation' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'doc_type' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'fk_doc' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'fk_docdet' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'thirdparty_code' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              . 		'subledger_account' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'subledger_label' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'numero_compte' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'label_compte' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'label_operation' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'montant' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'sens' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'debit' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'credit' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'code_journal' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'journal_label' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'piece_num' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'import_key' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'fk_user_author' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .		'entity' => '',
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .	),
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .	),
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .	),
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              .	),
	 *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              );
	 * @param	int		$max_nb_errors			Nb errors authorized before stopping the process
	 * @return 	int								Return integer <0 if KO, >0 if OK
	 */
	public function writeIntoBookkeeping(User $user, &$journal_data = array(), $max_nb_errors = 10)
	{
		global $conf, $langs, $hookmanager;
		require_once DOL_DOCUMENT_ROOT . '/accountancy/class/bookkeeping.class.php';

		$error = 0;
		$this->errorforinvoicedetail = array();

		$hookmanager->initHooks(array('accountingjournaldao'));
		$parameters = array('journal_data' => &$journal_data);
		$reshook = $hookmanager->executeHooks('writeBookkeeping', $parameters, $this); // Note that $action and $object may have been
		if ($reshook < 0) {
			$this->error = $hookmanager->error;
			$this->errors = $hookmanager->errors;
			return -1;
		} elseif (empty($reshook)) {
			// Clean parameters
			if (!is_array($journal_data)) {
				$journal_data = array();
			}

			foreach ($journal_data as $element_id => $element) {
				$error_for_line = 0;
				$total_credit = 0;
				$total_debit = 0;

				$this->db->begin();

				if ($element['error'] == 'somelinesarenotbound') {
					$error++;
					$error_for_line++;
					$this->errors[] = $langs->trans('ErrorInvoiceContainsLinesNotYetBounded', $element['ref']);
					$this->errorforinvoicedetail[$element_id] = array(
						'ref' => (string) $element['ref'],
						'error' => $langs->trans('ErrorInvoiceContainsLinesNotYetBounded', $element['ref']),
					);
				}

				if (!$error_for_line) {
					foreach ($element['blocks'] as $lines) {
						foreach ($lines as $line) {
							$bookkeeping = new BookKeeping($this->db);
							$bookkeeping->doc_date = (int) $line['doc_date'];
							$bookkeeping->date_lim_reglement = (int) $line['date_lim_reglement'];
							$bookkeeping->doc_ref = $line['doc_ref'];
							$bookkeeping->date_creation = $line['date_creation']; // not used
							$bookkeeping->doc_type = $line['doc_type'];
							$bookkeeping->fk_doc = $line['fk_doc'];
							$bookkeeping->fk_docdet = $line['fk_docdet'];
							$bookkeeping->thirdparty_code = $line['thirdparty_code'];
							$bookkeeping->subledger_account = $line['subledger_account'];
							$bookkeeping->subledger_label = $line['subledger_label'];
							$bookkeeping->numero_compte = $line['numero_compte'];
							$bookkeeping->label_compte = $line['label_compte'];
							$bookkeeping->label_operation = $line['label_operation'];
							$bookkeeping->montant = $line['montant']; // Deprecated: sens/debit/credit (and deprecated amount...)
							$bookkeeping->sens = $line['sens'];
							$bookkeeping->debit = (float) $line['debit'];
							$bookkeeping->credit = (float) $line['credit'];
							$bookkeeping->code_journal = $line['code_journal'];
							$bookkeeping->journal_label = $line['journal_label'];
							$bookkeeping->piece_num = (int) $line['piece_num'];
							$bookkeeping->import_key = $line['import_key'];
							$bookkeeping->fk_user_author = $user->id;
							$bookkeeping->entity = $conf->entity;

							$total_debit += $bookkeeping->debit;
							$total_credit += $bookkeeping->credit;

							$result = $bookkeeping->create($user);
							if ($result < 0) {
								if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {   // Already exists
									$error++;
									$error_for_line++;
									$journal_data[$element_id]['error'] = 'alreadyjournalized';
									$this->errorforinvoicedetail[$element_id] = array(
										'ref' => (string) $element['ref'],
										'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
									);
								} else {
									$error++;
									$error_for_line++;
									$journal_data[$element_id]['error'] = 'other';
									$this->errors[] = $bookkeeping->errorsToString();
									$this->errorforinvoicedetail[$element_id] = array(
										'ref' => (string) $element['ref'],
										'error' => $bookkeeping->errorsToString(),
									);
								}
							}
							//
							//                          if (!$error_for_line && isModEnabled('asset') && $this->nature == 1 && $bookkeeping->fk_doc > 0) {
							//                              // Set last cumulative depreciation
							//                              require_once DOL_DOCUMENT_ROOT . '/asset/class/asset.class.php';
							//                              $asset = new Asset($this->db);
							//                              $result = $asset->setLastCumulativeDepreciation($bookkeeping->fk_doc);
							//                              if ($result < 0) {
							//                                  $error++;
							//                                  $error_for_line++;
							//                                  $journal_data[$element_id]['error'] = 'other';
							//                                  $this->errors[] = $asset->errorsToString();
							//                              }
							//                          }
						}

						if ($error_for_line) {
							break;
						}
					}
				}

				// Protection against a bug on lines before
				if (!$error_for_line && (price2num($total_debit, 'MT') != price2num($total_credit, 'MT'))) {
					$error++;
					$error_for_line++;
					$journal_data[$element_id]['error'] = 'amountsnotbalanced';
					$this->errors[] = 'Try to insert a non balanced transaction in book for ' . json_encode($element['blocks']) . '. Canceled. Surely a bug.';
					$this->errorforinvoicedetail[$element_id] = array(
						'ref' => (string) $element['ref'],
						'error' => 'Try to insert a non balanced transaction in book for ' . (string) $element['ref'] . '. Canceled. Surely a bug.',
					);
				}

				if (!$error_for_line) {
					$this->db->commit();
				} else {
					$this->db->rollback();

					if ($error >= $max_nb_errors) {
						$this->errors[] = $langs->trans("ErrorTooManyErrorsProcessStopped");
						break; // Break in the foreach
					}
				}
			}
		}

		return $error ? -$error : 1;
	}

	/**
	 * Get expense report lines eligible for journalization on this (nature=5) journal.
	 * Pure mechanical lift of accountancy/journal/expensereportsjournal.php's former inline
	 * data-collection block (query + per-row aggregation + unbound-lines check) - must be called
	 * on an instance already fetch()ed with the target journal id ($this->code/$this->label are
	 * used, matching how the page used to build $journal/$journal_label from the same instance).
	 *
	 * @param	User	$user				User (unused directly here, kept for signature symmetry with writeIntoBookkeepingForExpenseReports())
	 * @param	int		$date_start			Start date (timestamp)
	 * @param	int		$date_end			End date (timestamp)
	 * @param	string	$in_bookkeeping		'notyet' (default) or 'already'
	 * @return	array{taber:array<int,array{date:int,ref:string,comments:string,fk_expensereportdet:int}>,tabht:array<int,array<string,float>>,tabtva:array<int,array<string,float>>,tabttc:array<int,array<string,float>>,tablocaltax1:array<int,array<string,float>>,tablocaltax2:array<int,array<string,float>>,tabuser:array<int,array{id:int,name:string,user_accountancy_code:string}>,def_tva:array<int,array<string,array<string,string>>>,errorforinvoice:array<int,string>}
	 */
	public function getDataForExpenseReports(User $user, $date_start, $date_end, $in_bookkeeping = 'notyet')
	{
		global $conf, $langs, $mysoc, $hookmanager;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/accounting.lib.php';

		$taber = array();
		$tabht = array();
		$tabtva = array();
		$def_tva = array();
		$tabttc = array();
		$tablocaltax1 = array();
		$tablocaltax2 = array();
		$tabuser = array();
		$errorforinvoice = array();

		$sql = "SELECT er.rowid, er.ref, er.date_debut as de, er.date_fin as df,";
		$sql .= " erd.rowid as erdid, erd.comments, erd.total_ht, erd.total_tva, erd.total_localtax1, erd.total_localtax2, erd.tva_tx, erd.total_ttc, erd.fk_code_ventilation, erd.vat_src_code, ";
		$sql .= " u.rowid as uid, u.firstname, u.lastname, u.accountancy_code as user_accountancy_account,";
		$sql .= " f.accountancy_code, aa.rowid as fk_compte, aa.account_number as compte, aa.label as label_compte";
		$parameters = array();
		$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters); // Note that $action and $object may have been modified by hook
		$sql .= $hookmanager->resPrint;
		$sql .= " FROM ".MAIN_DB_PREFIX."expensereport_det as erd";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_type_fees as f ON f.id = erd.fk_c_type_fees";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."accounting_account as aa ON aa.rowid = erd.fk_code_ventilation";
		$sql .= " JOIN ".MAIN_DB_PREFIX."expensereport as er ON er.rowid = erd.fk_expensereport";
		$sql .= " JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = er.fk_user_author";
		$parameters = array();
		$reshook = $hookmanager->executeHooks('printFieldListFrom', $parameters); // Note that $action and $object may have been modified by hook
		$sql .= $hookmanager->resPrint;
		$sql .= " WHERE er.fk_statut > 0";
		$sql .= " AND erd.fk_code_ventilation > 0";
		$sql .= " AND er.entity IN (".getEntity('expensereport', 0).")"; // We don't share object for accountancy
		if ($date_start && $date_end) {
			$sql .= " AND er.date_debut >= '".$this->db->idate($date_start)."' AND er.date_debut <= '".$this->db->idate($date_end)."'";
		}
		// Define begin binding date
		if (getDolGlobalInt('ACCOUNTING_DATE_START_BINDING')) {
			$sql .= " AND er.date_debut >= '".$this->db->idate(getDolGlobalInt('ACCOUNTING_DATE_START_BINDING'))."'";
		}
		// Already in bookkeeping or not
		if ($in_bookkeeping == 'already') {
			$sql .= " AND er.rowid IN (SELECT fk_doc FROM ".MAIN_DB_PREFIX."accounting_bookkeeping as ab  WHERE ab.doc_type='expense_report')";
		}
		if ($in_bookkeeping == 'notyet') {
			$sql .= " AND er.rowid NOT IN (SELECT fk_doc FROM ".MAIN_DB_PREFIX."accounting_bookkeeping as ab  WHERE ab.doc_type='expense_report')";
		}
		$parameters = array();
		$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters); // Note that $action and $object may have been modified by hook
		$sql .= $hookmanager->resPrint;
		$sql .= " ORDER BY er.date_debut";

		dol_syslog('accountancy/class/accountingjournal.class.php::getDataForExpenseReports', LOG_DEBUG);
		$result = $this->db->query($sql);
		if ($result) {
			$num = $this->db->num_rows($result);

			// Variables
			$account_salary = getDolGlobalString('ACCOUNTING_ACCOUNT_EXPENSEREPORT', 'NotDefined');
			$account_vat = getDolGlobalString('ACCOUNTING_VAT_BUY_ACCOUNT', 'NotDefined');
			$noTaxDispatchingKeepWithLines = getDolGlobalInt('ACCOUNTING_EXPENSEREPORT_DO_NOT_DISPATCH_TAXES'); //If enabled, Tax will NOT get split off from the base entry and credited to a separate tax account (good for non-VAT countries like USA)

			$i = 0;
			while ($i < $num) {
				$obj = $this->db->fetch_object($result);

				// Controls
				$compta_user = (!empty($obj->user_accountancy_account)) ? $obj->user_accountancy_account : $account_salary;
				$compta_fees = $obj->compte;

				$vatdata = getTaxesFromId($obj->tva_tx.($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : ''), $mysoc, $mysoc, 0);
				$compta_tva = (!empty($vatdata['accountancy_code_buy']) ? $vatdata['accountancy_code_buy'] : $account_vat);
				$compta_localtax1 = (!empty($vatdata['accountancy_code_buy']) ? $vatdata['accountancy_code_buy'] : $account_vat);
				$compta_localtax2 = (!empty($vatdata['accountancy_code_buy']) ? $vatdata['accountancy_code_buy'] : $account_vat);

				// Define an array to display all VAT rates that use this accounting account $compta_tva
				if (price2num($obj->tva_tx) || !empty($obj->vat_src_code)) {
					$def_tva[$obj->rowid][$compta_tva][vatrate($obj->tva_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : '')] = (vatrate($obj->tva_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : ''));
				}

				if (getDolGlobalInt('ACCOUNTANCY_ER_DATE_RECORD')) {
					$taber[$obj->rowid]["date"] = $this->db->jdate($obj->df);
				} else {
					$taber[$obj->rowid]["date"] = $this->db->jdate($obj->de);
				}
				$taber[$obj->rowid]["ref"] = $obj->ref;
				$taber[$obj->rowid]["comments"] = $obj->comments;
				$taber[$obj->rowid]["fk_expensereportdet"] = $obj->erdid;

				// Avoid warnings
				if (!isset($tabttc[$obj->rowid][$compta_user])) {
					$tabttc[$obj->rowid][$compta_user] = 0;
				}
				if (!isset($tabht[$obj->rowid][$compta_fees])) {
					$tabht[$obj->rowid][$compta_fees] = 0;
				}
				if (!isset($tabtva[$obj->rowid][$compta_tva])) {
					$tabtva[$obj->rowid][$compta_tva] = 0;
				}
				if (!isset($tablocaltax1[$obj->rowid][$compta_localtax1])) {
					$tablocaltax1[$obj->rowid][$compta_localtax1] = 0;
				}
				if (!isset($tablocaltax2[$obj->rowid][$compta_localtax2])) {
					$tablocaltax2[$obj->rowid][$compta_localtax2] = 0;
				}

				$tabttc[$obj->rowid][$compta_user] += $obj->total_ttc;
				if ($noTaxDispatchingKeepWithLines) { //case where all taxes paid should be grouped with the same account as the main expense (best for USA)
					$tabht[$obj->rowid][$compta_fees] += $obj->total_ttc;
				} else { //case where every tax paid should be broken out into its own account for future recovery (best for VAT countries)
					$tabht[$obj->rowid][$compta_fees] += $obj->total_ht;
					$tabtva[$obj->rowid][$compta_tva] += $obj->total_tva;
					$tablocaltax1[$obj->rowid][$compta_localtax1] += $obj->total_localtax1;
					$tablocaltax2[$obj->rowid][$compta_localtax2] += $obj->total_localtax2;
				}
				$tabuser[$obj->rowid] = array(
						'id' => $obj->uid,
						'name' => dolGetFirstLastname($obj->firstname, $obj->lastname),
						'user_accountancy_code' => $obj->user_accountancy_account
				);

				$i++;
			}
		} else {
			$this->errors[] = $this->db->lasterror();
		}

		// Load all unbound lines
		if (!empty($taber)) {
			$sql = "SELECT fk_expensereport, COUNT(erd.rowid) as nb";
			$sql .= " FROM ".MAIN_DB_PREFIX."expensereport_det as erd";
			$sql .= " WHERE erd.fk_code_ventilation <= 0";
			$sql .= " AND erd.total_ttc <> 0";
			$sql .= " AND fk_expensereport IN (".$this->db->sanitize(implode(",", array_keys($taber))).")";
			$sql .= " GROUP BY fk_expensereport";
			$resql = $this->db->query($sql);

			$num = $this->db->num_rows($resql);
			$i = 0;
			while ($i < $num) {
				$obj = $this->db->fetch_object($resql);
				if ($obj->nb > 0) {
					$errorforinvoice[$obj->fk_expensereport] = 'somelinesarenotbound';
				}
				$i++;
			}
		}

		return array(
			'taber' => $taber,
			'tabht' => $tabht,
			'tabtva' => $tabtva,
			'tabttc' => $tabttc,
			'tablocaltax1' => $tablocaltax1,
			'tablocaltax2' => $tablocaltax2,
			'tabuser' => $tabuser,
			'def_tva' => $def_tva,
			'errorforinvoice' => $errorforinvoice,
		);
	}

	/**
	 * Write the expense-reports journal (nature=5) into the bookkeeping.
	 * Pure mechanical lift of accountancy/journal/expensereportsjournal.php's former inline
	 * writebookkeeping action block. No hook is fired here - the original block had none, and
	 * adding one would be new behavior (see roadmap/backlog.md Phase 3b). Must be called on an
	 * instance already fetch()ed with the target journal id.
	 *
	 * @param	User	$user				User who write in the bookkeeping
	 * @param	int		$date_start			Start date (timestamp)
	 * @param	int		$date_end			End date (timestamp)
	 * @param	int		$max_nb_errors		Nb errors authorized before stopping the process
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	public function writeIntoBookkeepingForExpenseReports(User $user, $date_start, $date_end, $max_nb_errors = 10)
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/bookkeeping.class.php';
		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

		$data = $this->getDataForExpenseReports($user, $date_start, $date_end, 'notyet');
		$taber = $data['taber'];
		$tabht = $data['tabht'];
		$tabtva = $data['tabtva'];
		$tabttc = $data['tabttc'];
		$tablocaltax1 = $data['tablocaltax1'];
		$tablocaltax2 = $data['tablocaltax2'];
		$tabuser = $data['tabuser'];
		$def_tva = $data['def_tva'];
		$errorforinvoice = $data['errorforinvoice'];

		$journal = $this->code;
		$journal_label = $this->label;

		$now = dol_now();
		$error = 0;
		$this->errorforinvoicedetail = array();

		$userstatic = new User($this->db);
		$bookkeepingstatic = new BookKeeping($this->db);

		$accountingaccountexpense = new AccountingAccount($this->db);
		$accountingaccountexpense->fetch(0, getDolGlobalString('ACCOUNTING_ACCOUNT_EXPENSEREPORT'), true);

		foreach ($taber as $key => $val) {		// Loop on each expense report
			$errorforline = 0;

			$totalcredit = 0;
			$totaldebit = 0;

			$this->db->begin();

			$userstatic->id = $tabuser[$key]['id'];
			$userstatic->name = $tabuser[$key]['name'];
			$userstatic->accountancy_code = $tabuser[$key]['user_accountancy_code'];

			// Error if some lines are not binded/ready to be journalized
			if (!empty($errorforinvoice[$key]) && $errorforinvoice[$key] == 'somelinesarenotbound') {
				$error++;
				$errorforline++;
				setEventMessages($langs->trans('ErrorInvoiceContainsLinesNotYetBounded', $val['ref']), null, 'errors');
				$this->errorforinvoicedetail[$key] = array(
					'ref' => (string) $val['ref'],
					'error' => $langs->trans('ErrorInvoiceContainsLinesNotYetBounded', $val['ref']),
				);
			}

			// Thirdparty
			if (!$errorforline) {
				foreach ($tabttc[$key] as $k => $mt) {
					if ($mt) {
						$bookkeeping = new BookKeeping($this->db);
						$bookkeeping->doc_date = $val["date"];
						$bookkeeping->doc_ref = $val["ref"];
						$bookkeeping->date_creation = $now;
						$bookkeeping->doc_type = 'expense_report';
						$bookkeeping->fk_doc = $key;
						$bookkeeping->fk_docdet = $val["fk_expensereportdet"];

						$bookkeeping->subledger_account = $tabuser[$key]['user_accountancy_code'];
						$bookkeeping->subledger_label = $tabuser[$key]['name'];

						$bookkeeping->numero_compte = getDolGlobalString('ACCOUNTING_ACCOUNT_EXPENSEREPORT');
						$bookkeeping->label_compte = $accountingaccountexpense->label;

						$bookkeeping->label_operation = $bookkeepingstatic->accountingLabelForOperation($userstatic->name, '', $langs->trans("SubledgerAccount"));
						$bookkeeping->montant = $mt;
						$bookkeeping->sens = ($mt >= 0) ? 'C' : 'D';
						$bookkeeping->debit = ($mt <= 0) ? -$mt : 0;
						$bookkeeping->credit = ($mt > 0) ? $mt : 0;
						$bookkeeping->code_journal = $journal;
						$bookkeeping->journal_label = $langs->transnoentities($journal_label);
						$bookkeeping->fk_user_author = $user->id;
						$bookkeeping->entity = $conf->entity;

						$totaldebit += $bookkeeping->debit;
						$totalcredit += $bookkeeping->credit;

						$result = $bookkeeping->create($user);
						if ($result < 0) {
							if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {	// Already exists
								$error++;
								$errorforline++;
								$errorforinvoice[$key] = 'alreadyjournalized';
								$this->errorforinvoicedetail[$key] = array(
									'ref' => (string) $val['ref'],
									'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
								);
							} else {
								$error++;
								$errorforline++;
								$errorforinvoice[$key] = 'other';
								setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
								$this->errorforinvoicedetail[$key] = array(
									'ref' => (string) $val['ref'],
									'error' => $bookkeeping->errorsToString(),
								);
							}
						}
					}
				}
			}

			// Fees
			if (!$errorforline) {
				foreach ($tabht[$key] as $k => $mt) {
					if ($mt) {
						if (empty($conf->cache['accountingaccountincurrententity'][$k])) {
							$accountingaccount = new AccountingAccount($this->db);
							$accountingaccount->fetch(0, $k, true);
							$conf->cache['accountingaccountincurrententity'][$k] = $accountingaccount;
						} else {
							$accountingaccount = $conf->cache['accountingaccountincurrententity'][$k];
						}

						$account_label = $accountingaccount->label;

						// get compte id and label
						if ($accountingaccount->id > 0) {
							$bookkeeping = new BookKeeping($this->db);
							$bookkeeping->doc_date = $val["date"];
							$bookkeeping->doc_ref = $val["ref"];
							$bookkeeping->date_creation = $now;
							$bookkeeping->doc_type = 'expense_report';
							$bookkeeping->fk_doc = $key;
							$bookkeeping->fk_docdet = $val["fk_expensereportdet"];

							$bookkeeping->subledger_account = '';
							$bookkeeping->subledger_label = '';

							$bookkeeping->numero_compte = $k;
							$bookkeeping->label_compte = $account_label;

							$bookkeeping->label_operation = $bookkeepingstatic->accountingLabelForOperation($userstatic->name, '', $account_label);

							$bookkeeping->montant = $mt;
							$bookkeeping->sens = ($mt < 0) ? 'C' : 'D';
							$bookkeeping->debit = ($mt > 0) ? $mt : 0;
							$bookkeeping->credit = ($mt <= 0) ? -$mt : 0;
							$bookkeeping->code_journal = $journal;
							$bookkeeping->journal_label = $langs->transnoentities($journal_label);
							$bookkeeping->fk_user_author = $user->id;
							$bookkeeping->entity = $conf->entity;

							$totaldebit += $bookkeeping->debit;
							$totalcredit += $bookkeeping->credit;

							$result = $bookkeeping->create($user);
							if ($result < 0) {
								if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {	// Already exists
									$error++;
									$errorforline++;
									$errorforinvoice[$key] = 'alreadyjournalized';
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $val['ref'],
										'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
									);
								} else {
									$error++;
									$errorforline++;
									$errorforinvoice[$key] = 'other';
									setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $val['ref'],
										'error' => $bookkeeping->errorsToString(),
									);
								}
							}
						}
					}
				}
			}

			// VAT
			if (!$errorforline) {
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
							if (empty($conf->cache['accountingaccountincurrententity_vat'][$k])) {
								$accountingaccount = new AccountingAccount($this->db);
								$accountingaccount->fetch(0, $k, true);
								$conf->cache['accountingaccountincurrententity_vat'][$k] = $accountingaccount;
							} else {
								$accountingaccount = $conf->cache['accountingaccountincurrententity_vat'][$k];
							}

							$account_label = $accountingaccount->label;

							// get compte id and label
							$bookkeeping = new BookKeeping($this->db);
							$bookkeeping->doc_date = $val["date"];
							$bookkeeping->doc_ref = $val["ref"];
							$bookkeeping->date_creation = $now;
							$bookkeeping->doc_type = 'expense_report';
							$bookkeeping->fk_doc = $key;
							$bookkeeping->fk_docdet = $val["fk_expensereportdet"];

							$bookkeeping->subledger_account = '';
							$bookkeeping->subledger_label = '';

							$bookkeeping->numero_compte = $k;
							$bookkeeping->label_compte = $account_label;

							$tmpvatrate = (empty($def_tva[$key][$k]) ? (empty($arrayofvat[$key][$k]) ? '' : $arrayofvat[$key][$k]) : implode(', ', $def_tva[$key][$k]));
							$labelvataccount = $langs->trans("Taxes").' '.$tmpvatrate.' %';
							$labelvataccount .= ($numtax ? ' - Localtax '.$numtax : '');
							$bookkeeping->label_operation = $bookkeepingstatic->accountingLabelForOperation($userstatic->name, '', $labelvataccount);

							$bookkeeping->montant = $mt;
							$bookkeeping->sens = ($mt < 0) ? 'C' : 'D';
							$bookkeeping->debit = ($mt > 0) ? $mt : 0;
							$bookkeeping->credit = ($mt <= 0) ? -$mt : 0;
							$bookkeeping->code_journal = $journal;
							$bookkeeping->journal_label = $langs->transnoentities($journal_label);
							$bookkeeping->fk_user_author = $user->id;
							$bookkeeping->entity = $conf->entity;

							$totaldebit += $bookkeeping->debit;
							$totalcredit += $bookkeeping->credit;

							$result = $bookkeeping->create($user);
							if ($result < 0) {
								if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {	// Already exists
									$error++;
									$errorforline++;
									$errorforinvoice[$key] = 'alreadyjournalized';
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $val['ref'],
										'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
									);
								} else {
									$error++;
									$errorforline++;
									$errorforinvoice[$key] = 'other';
									setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $val['ref'],
										'error' => $bookkeeping->errorsToString(),
									);
								}
							}
						}
					}
				}
			}

			// Protection against a bug on lines before
			if (!$errorforline && (price2num($totaldebit, 'MT') != price2num($totalcredit, 'MT'))) {
				$error++;
				$errorforline++;
				$errorforinvoice[$key] = 'amountsnotbalanced';
				setEventMessages('We tried to insert a non balanced transaction in book for '.$val["ref"].'. Canceled. Surely a bug.', null, 'errors');
				$this->errorforinvoicedetail[$key] = array(
					'ref' => (string) $val['ref'],
					'error' => 'Try to insert a non balanced transaction in book for '.(string) $val['ref'].'. Canceled. Surely a bug.',
				);
			}

			if (!$errorforline) {
				$this->db->commit();
			} else {
				$this->db->rollback();

				if ($error >= $max_nb_errors) {
					setEventMessages($langs->trans("ErrorTooManyErrorsProcessStopped"), null, 'errors');
					break; // Break in the foreach
				}
			}
		}

		return $error ? -$error : 1;
	}

	/**
	 * Get customer invoice lines eligible for journalization on this (nature=2) journal.
	 * Pure mechanical lift of accountancy/journal/sellsjournal.php's former inline
	 * data-collection block (query + per-row aggregation + unbound-lines check, plus its 5
	 * hook points: doActions, printFieldListSelect/From/Where, processingJournalData,
	 * processedJournalData) - must be called on an instance already fetch()ed with the target
	 * journal id ($this->code/$this->label are used, matching how the page used to build
	 * $journal/$journal_label from the same instance). The doActions hook is called with an
	 * empty $action sentinel since this method has no action parameter of its own - it exists
	 * for third-party interception, not for anything the query itself depends on.
	 *
	 * @param	User	$user				User (unused directly here, kept for signature symmetry with writeIntoBookkeepingForSells())
	 * @param	int		$date_start			Start date (timestamp)
	 * @param	int		$date_end			End date (timestamp)
	 * @param	string	$in_bookkeeping		'notyet' (default) or 'already'
	 * @return	array{tabfac:array<int,array{date:int,datereg:int,ref:string,type:int,description:string,close_code:string,revenuestamp:float}>,tabht:array<int,array<string,float>>,tabtva:array<int,array<string,float>>,def_tva:array<int,array<string,array<string,string>>>,tabwarranty:array<int,array<string,float>>,tabrevenuestamp:array<int,array<string,float>>,tabttc:array<int,array<string,float>>,tablocaltax1:array<int,array<string,float>>,tablocaltax2:array<int,array<string,float>>,tabcompany:array<int,array{id:int,name:string,code_client:string,accountancy_code_customer_general:string,code_compta:string}>,errorforinvoice:array<int,string>,error:int}
	 */
	public function getDataForSells(User $user, $date_start, $date_end, $in_bookkeeping = 'notyet')
	{
		global $conf, $langs, $mysoc, $hookmanager;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/accounting.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

		$tabfac = array();
		$tabht = array();
		$tabtva = array();
		$def_tva = array();
		$tabwarranty = array();
		$tabrevenuestamp = array();
		$tabttc = array();
		$tablocaltax1 = array();
		$tablocaltax2 = array();
		$tabcompany = array();
		$vatdata_cache = array();
		$errorforinvoice = array();
		$toomanylineserror = 0;

		$action = '';
		$parameters = array();
		$reshook = $hookmanager->executeHooks('doActions', $parameters, $user, $action);

		$sql = "SELECT f.rowid, f.ref, f.type, f.situation_cycle_ref, f.datef as df, f.ref_client, f.date_lim_reglement as dlr, f.close_code, f.retained_warranty, f.revenuestamp, f.situation_final,";
		$sql .= " fd.rowid as fdid, fd.description, fd.product_type, fd.total_ht, fd.total_tva, fd.total_localtax1, fd.total_localtax2, fd.tva_tx, fd.localtax1_tx, fd.localtax2_tx, fd.total_ttc, fd.situation_percent, fd.vat_src_code, fd.info_bits,";
		$sql .= " s.rowid as socid, s.nom as name, s.code_client, s.code_fournisseur,";
		if (getDolGlobalString('MAIN_COMPANY_PERENTITY_SHARED')) {
			$sql .= " spe.accountancy_code_customer_general,";
			$sql .= " spe.accountancy_code_customer as code_compta_client,";
			$sql .= " spe.accountancy_code_supplier_general,";
			$sql .= " spe.accountancy_code_supplier as code_compta_fournisseur,";
		} else {
			$sql .= " s.accountancy_code_customer_general,";
			$sql .= " s.code_compta as code_compta_client,";
			$sql .= " s.accountancy_code_supplier_general,";
			$sql .= " s.code_compta_fournisseur,";
		}
		$sql .= " p.rowid as pid, p.ref as pref, aa.rowid as fk_compte, aa.account_number as compte, aa.label as label_compte,";
		if (getDolGlobalString('MAIN_PRODUCT_PERENTITY_SHARED')) {
			$sql .= " ppe.accountancy_code_sell";
		} else {
			$sql .= " p.accountancy_code_sell";
		}
		$parameters = array();
		$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters); // Note that $action and $object may have been modified by hook
		$sql .= $hookmanager->resPrint;
		$sql .= " FROM ".MAIN_DB_PREFIX."facturedet as fd";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = fd.fk_product";
		if (getDolGlobalString('MAIN_PRODUCT_PERENTITY_SHARED')) {
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product_perentity as ppe ON ppe.fk_product = p.rowid AND ppe.entity = " . ((int) $conf->entity);
		}
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."accounting_account as aa ON aa.rowid = fd.fk_code_ventilation";
		$sql .= " JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = fd.fk_facture";
		$sql .= " JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		if (getDolGlobalString('MAIN_COMPANY_PERENTITY_SHARED')) {
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe_perentity as spe ON spe.fk_soc = s.rowid AND spe.entity = " . ((int) $conf->entity);
		}
		$parameters = array();
		$reshook = $hookmanager->executeHooks('printFieldListFrom', $parameters); // Note that $action and $object may have been modified by hook
		$sql .= $hookmanager->resPrint;
		$sql .= " WHERE fd.fk_code_ventilation > 0";
		$sql .= " AND f.entity IN (".getEntity('invoice', 0).')'; // We don't share object for accountancy, we use source object sharing
		$sql .= " AND f.fk_statut > 0";
		if (getDolGlobalString('FACTURE_DEPOSITS_ARE_JUST_PAYMENTS')) {	// Non common setup
			$sql .= " AND f.type IN (".Facture::TYPE_STANDARD.",".Facture::TYPE_REPLACEMENT.",".Facture::TYPE_CREDIT_NOTE.",".Facture::TYPE_SITUATION.")";
		} else {
			$sql .= " AND f.type IN (".Facture::TYPE_STANDARD.",".Facture::TYPE_REPLACEMENT.",".Facture::TYPE_CREDIT_NOTE.",".Facture::TYPE_DEPOSIT.",".Facture::TYPE_SITUATION.")";
		}
		$sql .= " AND fd.product_type IN (0,1)";
		if ($date_start && $date_end) {
			$sql .= " AND f.datef >= '".$this->db->idate($date_start)."' AND f.datef <= '".$this->db->idate($date_end)."'";
		}
		// Define begin binding date
		if (getDolGlobalInt('ACCOUNTING_DATE_START_BINDING')) {
			$sql .= " AND f.datef >= '".$this->db->idate(getDolGlobalInt('ACCOUNTING_DATE_START_BINDING'))."'";
		}
		// Already in bookkeeping or not
		if ($in_bookkeeping == 'already') {
			$sql .= " AND f.rowid IN (SELECT fk_doc FROM ".MAIN_DB_PREFIX."accounting_bookkeeping as ab WHERE ab.doc_type='customer_invoice')";
		}
		if ($in_bookkeeping == 'notyet') {
			$sql .= " AND f.rowid NOT IN (SELECT fk_doc FROM ".MAIN_DB_PREFIX."accounting_bookkeeping as ab WHERE ab.doc_type='customer_invoice')";
		}
		$parameters = array();
		$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters); // Note that $action and $object may have been modified by hook
		$sql .= $hookmanager->resPrint;
		$sql .= " ORDER BY f.datef, f.ref";

		dol_syslog('accountancy/class/accountingjournal.class.php::getDataForSells', LOG_DEBUG);

		// Variables
		$cptcli = getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER', 'NotDefined');
		$cpttva = getDolGlobalString('ACCOUNTING_VAT_SOLD_ACCOUNT', 'NotDefined');
		$cptlocaltax1 = getDolGlobalString('ACCOUNTING_LT1_SOLD_ACCOUNT', 'NotDefined');
		$cptlocaltax2 = getDolGlobalString('ACCOUNTING_LT2_SOLD_ACCOUNT', 'NotDefined');

		$result = $this->db->query($sql);
		if ($result) {
			$num = $this->db->num_rows($result);

			$i = 0;
			while ($i < $num) {
				$obj = $this->db->fetch_object($result);

				// Controls
				$accountancy_code_customer_general = (!empty($obj->accountancy_code_customer_general) && $obj->accountancy_code_customer_general != '-1') ? $obj->accountancy_code_customer_general : $cptcli;
				$compta_soc = (!empty($obj->code_compta_client)) ? $obj->code_compta_client : $cptcli;

				$compta_prod = $obj->compte;
				if (empty($compta_prod)) {
					if ($obj->product_type == 0) {
						$compta_prod = getDolGlobalString('ACCOUNTING_PRODUCT_SOLD_ACCOUNT', 'NotDefined');
					} else {
						$compta_prod = getDolGlobalString('ACCOUNTING_SERVICE_SOLD_ACCOUNT', 'NotDefined');
					}
				}

				$tax_id = $obj->tva_tx . ($obj->vat_src_code ? ' (' . $obj->vat_src_code . ')' : '');
				if (array_key_exists($tax_id, $vatdata_cache)) {
					$vatdata = $vatdata_cache[$tax_id];
				} else {
					if (getDolGlobalString('SERVICE_ARE_ECOMMERCE_200238EC')) {
						$buyer = new Societe($this->db);
						$buyer->fetch($obj->socid);
					} else {
						$buyer = null;	// We don't need the buyer in this case
					}
					$seller = $mysoc;
					$vatdata = getTaxesFromId($tax_id, $buyer, $seller, 0);
					$vatdata_cache[$tax_id] = $vatdata;
				}
				$compta_tva = (!empty($vatdata['accountancy_code_sell']) ? $vatdata['accountancy_code_sell'] : $cpttva);
				$compta_localtax1 = (!empty($vatdata['accountancy_code_sell']) ? $vatdata['accountancy_code_sell'] : $cptlocaltax1);
				$compta_localtax2 = (!empty($vatdata['accountancy_code_sell']) ? $vatdata['accountancy_code_sell'] : $cptlocaltax2);

				// Define the array to store the detail of each vat rate and code for lines
				if (price2num($obj->tva_tx) || !empty($obj->vat_src_code)) {
					$def_tva[$obj->rowid][$compta_tva][vatrate($obj->tva_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : '')] = (vatrate($obj->tva_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : ''));
					if ($obj->localtax1_tx > 0.0) {
						$def_tva[$obj->rowid][$compta_localtax1][vatrate($obj->localtax1_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : '').' LT1'] = (vatrate($obj->localtax1_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : ''));
					}
					if ($obj->localtax2_tx > 0.0) {
						$def_tva[$obj->rowid][$compta_localtax2][vatrate($obj->localtax2_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : '').' LT2'] = (vatrate($obj->localtax2_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : ''));
					}
				}

				// Create a compensation rate for situation invoice.
				$situation_ratio = 1;
				if (getDolGlobalInt('INVOICE_USE_SITUATION') == 1) {
					if ($obj->situation_cycle_ref) {
						// Avoid divide by 0
						if ($obj->situation_percent == 0) {
							$situation_ratio = 0;
						} else {
							$line = new FactureLigne($this->db);
							$line->fetch($obj->fdid);

							// Situation invoices handling
							$prev_progress = $line->get_prev_progress($obj->rowid);

							$situation_ratio = ($obj->situation_percent - $prev_progress) / $obj->situation_percent;
						}
					}
				}

				$revenuestamp = (float) price2num($obj->revenuestamp, 'MT');

				// Invoice lines
				$tabfac[$obj->rowid]["date"] = $this->db->jdate($obj->df);
				$tabfac[$obj->rowid]["datereg"] = $this->db->jdate($obj->dlr);
				$tabfac[$obj->rowid]["ref"] = $obj->ref;
				$tabfac[$obj->rowid]["type"] = $obj->type;
				$tabfac[$obj->rowid]["description"] = $obj->label_compte;
				$tabfac[$obj->rowid]["close_code"] = $obj->close_code; // close_code = 'replaced' for replacement invoices (not used in most european countries)
				$tabfac[$obj->rowid]["revenuestamp"] = $revenuestamp;

				// Avoid warnings
				if (!isset($tabttc[$obj->rowid][$compta_soc])) {
					$tabttc[$obj->rowid][$compta_soc] = 0;
				}
				if (!isset($tabht[$obj->rowid][$compta_prod])) {
					$tabht[$obj->rowid][$compta_prod] = 0;
				}
				if (!isset($tabtva[$obj->rowid][$compta_tva])) {
					$tabtva[$obj->rowid][$compta_tva] = 0;
				}
				if (!isset($tablocaltax1[$obj->rowid][$compta_localtax1])) {
					$tablocaltax1[$obj->rowid][$compta_localtax1] = 0;
				}
				if (!isset($tablocaltax2[$obj->rowid][$compta_localtax2])) {
					$tablocaltax2[$obj->rowid][$compta_localtax2] = 0;
				}

				// Compensation of data for invoice situation by using $situation_ratio. This works (nearly) for invoice that was not correctly recorded
				// but it may introduces an error for situation invoices that were correctly saved. There is still rounding problem that differs between
				// real data we should have stored and result obtained with a compensation.
				// It also seems that credit notes on situation invoices are correctly saved (but it depends on the version used in fact).
				// For credit notes, we hope to have situation_ratio = 1 so the compensation has no effect to avoid introducing troubles with credit notes.
				if (getDolGlobalInt('INVOICE_USE_SITUATION') == 1) {
					$total_ttc = $obj->total_ttc * $situation_ratio;
				} else {
					$total_ttc = $obj->total_ttc;
				}

				// Move a part of the retained warrenty into the account of warranty
				if (getDolGlobalString('INVOICE_USE_RETAINED_WARRANTY') && $obj->retained_warranty > 0 && (!getDolGlobalString('INVOICE_RETAINED_WARRANTY_LIMITED_TO_FINAL_SITUATION') || !empty($obj->situation_final))) {
					$retained_warranty = (float) price2num($total_ttc * $obj->retained_warranty / 100, 'MT');	// Calculate the amount of warrenty for this line (using the percent value)
					$tabwarranty[$obj->rowid][$compta_soc] += $retained_warranty;
					$total_ttc -= $retained_warranty;
				}

				$tabttc[$obj->rowid][$compta_soc] += $total_ttc;
				$tabht[$obj->rowid][$compta_prod] += $obj->total_ht * $situation_ratio;
				$tva_npr = ((($obj->info_bits & 1) == 1) ? 1 : 0);
				if (!$tva_npr) { // We ignore line if VAT is a NPR
					if (getDolGlobalInt('INVOICE_USE_SITUATION') == 2) {
						$tabtva[$obj->rowid][$compta_tva] += $obj->total_tva;
						$tablocaltax1[$obj->rowid][$compta_localtax1] += $obj->total_localtax1;
						$tablocaltax2[$obj->rowid][$compta_localtax2] += $obj->total_localtax2;
					} else {
						$tabtva[$obj->rowid][$compta_tva] += $obj->total_tva * $situation_ratio;
						$tablocaltax1[$obj->rowid][$compta_localtax1] += $obj->total_localtax1 * $situation_ratio;
						$tablocaltax2[$obj->rowid][$compta_localtax2] += $obj->total_localtax2 * $situation_ratio;
					}
				}

				$compta_revenuestamp = 'NotDefined';
				if (!empty($revenuestamp)) {
					$sqlrevenuestamp = "SELECT accountancy_code_sell FROM ".MAIN_DB_PREFIX."c_revenuestamp";
					$sqlrevenuestamp .= " WHERE fk_pays = ".((int) $mysoc->country_id);
					$sqlrevenuestamp .= " AND taux = ".((float) $revenuestamp);
					$sqlrevenuestamp .= " AND active = 1";
					$resqlrevenuestamp = $this->db->query($sqlrevenuestamp);

					if ($resqlrevenuestamp) {
						$num_rows_revenuestamp = $this->db->num_rows($resqlrevenuestamp);
						if ($num_rows_revenuestamp > 1) {
							dol_print_error($this->db, 'Failed 2 or more lines for the revenue stamp of your country. Check the dictionary of revenue stamp.');
						} else {
							$objrevenuestamp = $this->db->fetch_object($resqlrevenuestamp);
							if ($objrevenuestamp) {
								$compta_revenuestamp = $objrevenuestamp->accountancy_code_sell;
							}
						}
					}
				}

				if (empty($tabrevenuestamp[$obj->rowid][$compta_revenuestamp]) && !empty($revenuestamp)) {
					// The revenue stamp was never seen for this invoice id=$obj->rowid
					$tabttc[$obj->rowid][$compta_soc] += $obj->revenuestamp;
					$tabrevenuestamp[$obj->rowid][$compta_revenuestamp] = $obj->revenuestamp;
				}

				$tabcompany[$obj->rowid] = array(
					'id' => $obj->socid,
					'name' => $obj->name,
					'code_client' => $obj->code_client,
					'accountancy_code_customer_general' => $accountancy_code_customer_general,
					'code_compta' => $compta_soc
				);

				// After the line is processed
				$parameters = array(
					'obj' => $obj,
					'tabfac' => &$tabfac,
					'tabht' => &$tabht,
					'tabtva' => &$tabtva,
					'def_tva' => &$def_tva,
					'tabwarranty' => &$tabwarranty,
					'tabrevenuestamp' => &$tabrevenuestamp,
					'tabttc' => &$tabttc,
					'tablocaltax1' => &$tablocaltax1,
					'tablocaltax2' => &$tablocaltax2,
					'tabcompany' => &$tabcompany,
					'vatdata_cache' => &$vatdata_cache,
				);
				$reshook = $hookmanager->executeHooks('processingJournalData', $parameters); // Note that $action and $object may have been modified by hook

				$i++;

				// Check for too many lines.
				if ($i > getDolGlobalInt('ACCOUNTANCY_MAX_TOO_MANY_LINES_TO_PROCESS', 10000)) {
					$toomanylineserror++;
					setEventMessages("ErrorTooManyLinesToProcessPleaseUseAMoreSelectiveFilter", null, 'errors');
					break;
				}
			}

			// After the loop on each line
			$parameters = array(
				'tabfac' => &$tabfac,
				'tabht' => &$tabht,
				'tabtva' => &$tabtva,
				'def_tva' => &$def_tva,
				'tabwarranty' => &$tabwarranty,
				'tabrevenuestamp' => &$tabrevenuestamp,
				'tabttc' => &$tabttc,
				'tablocaltax1' => &$tablocaltax1,
				'tablocaltax2' => &$tablocaltax2,
				'tabcompany' => &$tabcompany,
				'vatdata_cache' => &$vatdata_cache,
			);
			$reshook = $hookmanager->executeHooks('processedJournalData', $parameters); // Note that $action and $object may have been modified by hook
		} else {
			$this->errors[] = $this->db->lasterror();
		}

		// New way, single query, load all unbound lines
		if (!empty($tabfac)) {
			$sql = "SELECT fk_facture, COUNT(fd.rowid) as nb";
			$sql .= " FROM ".MAIN_DB_PREFIX."facturedet as fd";
			$sql .= " WHERE fd.product_type <= 2 AND fd.fk_code_ventilation <= 0 AND fd.total_ttc <> 0";
			$sql .= " AND fk_facture IN (".$this->db->sanitize(implode(",", array_keys($tabfac))).")";
			$sql .= " GROUP BY fk_facture";
			$resql = $this->db->query($sql);
			if ($resql) {
				$num = $this->db->num_rows($resql);
				$i = 0;
				while ($i < $num) {
					$obj = $this->db->fetch_object($resql);
					if ($obj->nb > 0) {
						$errorforinvoice[$obj->fk_facture] = 'somelinesarenotbound';
					}
					$i++;
				}
			}
		}

		return array(
			'tabfac' => $tabfac,
			'tabht' => $tabht,
			'tabtva' => $tabtva,
			'def_tva' => $def_tva,
			'tabwarranty' => $tabwarranty,
			'tabrevenuestamp' => $tabrevenuestamp,
			'tabttc' => $tabttc,
			'tablocaltax1' => $tablocaltax1,
			'tablocaltax2' => $tablocaltax2,
			'tabcompany' => $tabcompany,
			'errorforinvoice' => $errorforinvoice,
			'error' => $toomanylineserror,
		);
	}

	/**
	 * Compute per-invoice preview subtotals from an already-collected getDataForSells() return
	 * array, without writing anything to the bookkeeping. Read-only re-derivation of
	 * writeIntoBookkeepingForSells()'s 5-block sign logic (warranty, thirdparty/ttc, product/
	 * service, VAT+localtax1+localtax2, revenue stamp) - no BookKeeping object is built, no
	 * create()/begin()/commit() call is made. Keep this in sync with
	 * writeIntoBookkeepingForSells() if that method's sign conventions ever change.
	 *
	 * total_ht/total_ttc are plain business-readable sums of tabht/tabttc (warranty and revenue
	 * stamp are deliberately excluded from these two, matching how tabttc is only ever used in
	 * the thirdparty block). total_debit/total_credit are the actual amounts the write would
	 * post, folding in all 5 buckets - they should be equal for any invoice that would transfer
	 * cleanly.
	 *
	 * @param	array	$data	Return value of getDataForSells()
	 * @phan-param array{tabfac:array<int,array{date:int,datereg:int,ref:string,type:int,description:string,close_code:string,revenuestamp:float}>,tabht:array<int,array<string,float>>,tabtva:array<int,array<string,float>>,def_tva:array<int,array<string,array<string,string>>>,tabwarranty:array<int,array<string,float>>,tabrevenuestamp:array<int,array<string,float>>,tabttc:array<int,array<string,float>>,tablocaltax1:array<int,array<string,float>>,tablocaltax2:array<int,array<string,float>>,tabcompany:array<int,array{id:int,name:string,code_client:string,accountancy_code_customer_general:string,code_compta:string}>,errorforinvoice:array<int,string>,error:int} $data
	 * @phpstan-param array{tabfac:array<int,array{date:int,datereg:int,ref:string,type:int,description:string,close_code:string,revenuestamp:float}>,tabht:array<int,array<string,float>>,tabtva:array<int,array<string,float>>,def_tva:array<int,array<string,array<string,string>>>,tabwarranty:array<int,array<string,float>>,tabrevenuestamp:array<int,array<string,float>>,tabttc:array<int,array<string,float>>,tablocaltax1:array<int,array<string,float>>,tablocaltax2:array<int,array<string,float>>,tabcompany:array<int,array{id:int,name:string,code_client:string,accountancy_code_customer_general:string,code_compta:string}>,errorforinvoice:array<int,string>,error:int} $data
	 * @return	array<int,array{total_ht:float,total_ttc:float,total_debit:float,total_credit:float}>	Keyed by the same invoice id as $data['tabfac']
	 */
	public function getPreviewAmountsForSells(array $data)
	{
		$result = array();
		foreach ($data['tabfac'] as $key => $val) {
			$totalht = array_sum($data['tabht'][$key]);
			$totalttc = array_sum($data['tabttc'][$key]);
			$totaldebit = 0.0;
			$totalcredit = 0.0;

			// Warranty - debit-positive (mirrors writeIntoBookkeepingForSells()'s warranty block)
			if (!empty($data['tabwarranty'][$key]) && is_array($data['tabwarranty'][$key])) {
				foreach ($data['tabwarranty'][$key] as $mt) {
					$totaldebit += max($mt, 0);
					$totalcredit += max(-$mt, 0);
				}
			}
			// Thirdparty/ttc - debit-positive
			foreach ($data['tabttc'][$key] as $mt) {
				$totaldebit += max($mt, 0);
				$totalcredit += max(-$mt, 0);
			}
			// Product/service - credit-positive
			foreach ($data['tabht'][$key] as $mt) {
				$totaldebit += max(-$mt, 0);
				$totalcredit += max($mt, 0);
			}
			// VAT + localtax1 + localtax2 - credit-positive
			foreach (array($data['tabtva'][$key], $data['tablocaltax1'][$key], $data['tablocaltax2'][$key]) as $arrayofvat) {
				foreach ($arrayofvat as $mt) {
					$totaldebit += max(-$mt, 0);
					$totalcredit += max($mt, 0);
				}
			}
			// Revenue stamp - credit-positive
			if (!empty($data['tabrevenuestamp'][$key]) && is_array($data['tabrevenuestamp'][$key])) {
				foreach ($data['tabrevenuestamp'][$key] as $mt) {
					$totaldebit += max(-$mt, 0);
					$totalcredit += max($mt, 0);
				}
			}

			$result[$key] = array(
				'total_ht' => (float) price2num($totalht, 'MT'),
				'total_ttc' => (float) price2num($totalttc, 'MT'),
				'total_debit' => (float) price2num($totaldebit, 'MT'),
				'total_credit' => (float) price2num($totalcredit, 'MT'),
			);
		}
		return $result;
	}

	/**
	 * Write the sells journal (nature=2) into the bookkeeping.
	 * Pure mechanical lift of accountancy/journal/sellsjournal.php's former inline
	 * writebookkeeping action block. No hook is fired here - the original block had none.
	 * Must be called on an instance already fetch()ed with the target journal id.
	 *
	 * @param	User	$user				User who write in the bookkeeping
	 * @param	int		$date_start			Start date (timestamp)
	 * @param	int		$date_end			End date (timestamp)
	 * @param	int		$max_nb_errors		Nb errors authorized before stopping the process
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	public function writeIntoBookkeepingForSells(User $user, $date_start, $date_end, $max_nb_errors = 10)
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/bookkeeping.class.php';
		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

		$data = $this->getDataForSells($user, $date_start, $date_end, 'notyet');
		$tabfac = $data['tabfac'];
		$tabht = $data['tabht'];
		$tabtva = $data['tabtva'];
		$def_tva = $data['def_tva'];
		$tabwarranty = $data['tabwarranty'];
		$tabrevenuestamp = $data['tabrevenuestamp'];
		$tabttc = $data['tabttc'];
		$tablocaltax1 = $data['tablocaltax1'];
		$tablocaltax2 = $data['tablocaltax2'];
		$tabcompany = $data['tabcompany'];
		$errorforinvoice = $data['errorforinvoice'];

		// Matches the page's own original gate: if data-collection hit the too-many-lines
		// guard, the write action must not run at all (not even partially, on the truncated
		// resultset it collected before breaking out).
		if (!empty($data['error'])) {
			return -1 * $data['error'];
		}

		$journal = $this->code;
		$journal_label = $this->label;

		$cptcli = getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER', 'NotDefined');

		$now = dol_now();
		$error = 0;
		$this->errorforinvoicedetail = array();

		$companystatic = new Societe($this->db);
		$invoicestatic = new Facture($this->db);
		$bookkeepingstatic = new BookKeeping($this->db);

		$accountingaccountcustomer = new AccountingAccount($this->db);
		$accountingaccountcustomer->fetch(0, getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER'), true);

		$accountingaccountcustomerwarranty = new AccountingAccount($this->db);
		$accountingaccountcustomerwarranty->fetch(0, getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER_RETAINED_WARRANTY'), true);

		foreach ($tabfac as $key => $val) {		// Loop on each invoice
			$errorforline = 0;

			$totalcredit = 0;
			$totaldebit = 0;

			$this->db->begin();		// We accept transaction into loop, so if we hang, we can continue transfer from the last error

			$companystatic->id = $tabcompany[$key]['id'];
			$companystatic->name = $tabcompany[$key]['name'];
			$companystatic->accountancy_code_customer_general = $tabcompany[$key]['accountancy_code_customer_general'];
			$companystatic->code_compta = $tabcompany[$key]['code_compta'];
			$companystatic->code_compta_client = $tabcompany[$key]['code_compta'];
			$companystatic->code_client = $tabcompany[$key]['code_client'];
			$companystatic->client = 3;

			$invoicestatic->id = $key;
			$invoicestatic->ref = (string) $val["ref"];
			$invoicestatic->type = $val["type"];
			$invoicestatic->close_code = $val["close_code"];

			// Is it a replaced invoice? 0=not a replaced invoice, 1=replaced invoice not yet dispatched, 2=replaced invoice dispatched
			$replacedinvoice = 0;
			if ($invoicestatic->close_code == Facture::CLOSECODE_REPLACED) {
				$replacedinvoice = 1;
				$alreadydispatched = $invoicestatic->getVentilExportCompta(); // Test if replaced invoice already into bookkeeping.
				if ($alreadydispatched) {
					$replacedinvoice = 2;
				}
			}

			// If not already into bookkeeping, we won't add it. If yes, do nothing (should not happen because creating a replacement is not possible if invoice is accounted)
			if ($replacedinvoice == 1) {
				$this->db->rollback();
				continue;
			}

			// Error if some lines are not binded/ready to be journalized
			if (isset($errorforinvoice[$key]) && $errorforinvoice[$key] == 'somelinesarenotbound') {
				$error++;
				$errorforline++;
				setEventMessages($langs->trans('ErrorInvoiceContainsLinesNotYetBounded', $val['ref']), null, 'errors');
				$this->errorforinvoicedetail[$key] = array(
					'ref' => (string) $val['ref'],
					'error' => $langs->trans('ErrorInvoiceContainsLinesNotYetBounded', $val['ref']),
				);
			}

			// Warranty
			if (!$errorforline && getDolGlobalString('INVOICE_USE_RETAINED_WARRANTY')) {
				if (isset($tabwarranty[$key]) && is_array($tabwarranty[$key])) {
					foreach ($tabwarranty[$key] as $k => $mt) {
						$bookkeeping = new BookKeeping($this->db);
						$bookkeeping->doc_date = $val["date"];
						$bookkeeping->date_lim_reglement = $val["datereg"];
						$bookkeeping->doc_ref = $val["ref"];
						$bookkeeping->date_creation = $now;
						$bookkeeping->doc_type = 'customer_invoice';
						$bookkeeping->fk_doc = $key;
						$bookkeeping->fk_docdet = 0; // Useless, can be several lines that are the source of this record to add
						$bookkeeping->thirdparty_code = $companystatic->code_client;

						$bookkeeping->subledger_account = $tabcompany[$key]['code_compta'];
						$bookkeeping->subledger_label = $tabcompany[$key]['name'];

						$bookkeeping->numero_compte = getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER_RETAINED_WARRANTY');
						$bookkeeping->label_compte = $accountingaccountcustomerwarranty->label;

						$bookkeeping->label_operation = $bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref, $langs->trans("RetainedWarranty"));
						$bookkeeping->montant = $mt;
						$bookkeeping->sens = ($mt >= 0) ? 'D' : 'C';
						$bookkeeping->debit = ($mt >= 0) ? $mt : 0;
						$bookkeeping->credit = ($mt < 0) ? -$mt : 0;
						$bookkeeping->code_journal = $journal;
						$bookkeeping->journal_label = $langs->transnoentities($journal_label);
						$bookkeeping->fk_user_author = $user->id;
						$bookkeeping->entity = $conf->entity;

						$totaldebit += $bookkeeping->debit;
						$totalcredit += $bookkeeping->credit;

						$result = $bookkeeping->create($user);
						if ($result < 0) {
							if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {    // Already exists
								$error++;
								$errorforline++;
								$errorforinvoice[$key] = 'alreadyjournalized';
								$this->errorforinvoicedetail[$key] = array(
									'ref' => (string) $val['ref'],
									'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
								);
							} else {
								$error++;
								$errorforline++;
								$errorforinvoice[$key] = 'other';
								setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
								$this->errorforinvoicedetail[$key] = array(
									'ref' => (string) $val['ref'],
									'error' => $bookkeeping->errorsToString(),
								);
							}
						}
					}
				}
			}

			// Thirdparty
			if (!$errorforline) {
				foreach ($tabttc[$key] as $k => $mt) {
					$bookkeeping = new BookKeeping($this->db);
					$bookkeeping->doc_date = $val["date"];
					$bookkeeping->date_lim_reglement = $val["datereg"];
					$bookkeeping->doc_ref = $val["ref"];
					$bookkeeping->date_creation = $now;
					$bookkeeping->doc_type = 'customer_invoice';
					$bookkeeping->fk_doc = $key;
					$bookkeeping->fk_docdet = 0; // Useless, can be several lines that are source of this record to add
					$bookkeeping->thirdparty_code = $companystatic->code_client;

					$bookkeeping->subledger_account = $tabcompany[$key]['code_compta'];
					$bookkeeping->subledger_label = $tabcompany[$key]['name'];

					$bookkeeping->numero_compte = (!empty($tabcompany[$key]['accountancy_code_customer_general']) && $tabcompany[$key]['accountancy_code_customer_general'] != '-1') ? $tabcompany[$key]['accountancy_code_customer_general'] : $cptcli;
					$bookkeeping->label_compte = $accountingaccountcustomer->label;

					$bookkeeping->label_operation = $bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref, $langs->trans("SubledgerAccount"));
					$bookkeeping->montant = $mt;
					$bookkeeping->sens = ($mt >= 0) ? 'D' : 'C';
					$bookkeeping->debit = ($mt >= 0) ? $mt : 0;
					$bookkeeping->credit = ($mt < 0) ? -$mt : 0;
					$bookkeeping->code_journal = $journal;
					$bookkeeping->journal_label = $langs->transnoentities($journal_label);
					$bookkeeping->fk_user_author = $user->id;
					$bookkeeping->entity = $conf->entity;

					$totaldebit += $bookkeeping->debit;
					$totalcredit += $bookkeeping->credit;

					$result = $bookkeeping->create($user);
					if ($result < 0) {
						if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {	// Already exists
							$error++;
							$errorforline++;
							$errorforinvoice[$key] = 'alreadyjournalized';
							$this->errorforinvoicedetail[$key] = array(
								'ref' => (string) $val['ref'],
								'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
							);
						} else {
							$error++;
							$errorforline++;
							$errorforinvoice[$key] = 'other';
							setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
							$this->errorforinvoicedetail[$key] = array(
								'ref' => (string) $val['ref'],
								'error' => $bookkeeping->errorsToString(),
							);
						}
					} else {
						if (getDolGlobalInt('ACCOUNTING_ENABLE_LETTERING') && getDolGlobalInt('ACCOUNTING_ENABLE_AUTOLETTERING')) {
							require_once DOL_DOCUMENT_ROOT . '/accountancy/class/lettering.class.php';
							$lettering_static = new Lettering($this->db);

							$nb_lettering = $lettering_static->bookkeepingLettering(array($bookkeeping->id));
						}
					}
				}
			}

			// Product / Service
			if (!$errorforline) {
				foreach ($tabht[$key] as $k => $mt) {
					if (empty($conf->cache['accountingaccountincurrententity'][$k])) {
						$accountingaccount = new AccountingAccount($this->db);
						$accountingaccount->fetch(0, $k, true);
						$conf->cache['accountingaccountincurrententity'][$k] = $accountingaccount;
					} else {
						$accountingaccount = $conf->cache['accountingaccountincurrententity'][$k];
					}

					$label_account = $accountingaccount->label;

					// get compte id and label
					if ($accountingaccount->id > 0) {
						$bookkeeping = new BookKeeping($this->db);
						$bookkeeping->doc_date = $val["date"];
						$bookkeeping->date_lim_reglement = $val["datereg"];
						$bookkeeping->doc_ref = $val["ref"];
						$bookkeeping->date_creation = $now;
						$bookkeeping->doc_type = 'customer_invoice';
						$bookkeeping->fk_doc = $key;
						$bookkeeping->fk_docdet = 0; // Useless, can be several lines that are source of this record to add
						$bookkeeping->thirdparty_code = $companystatic->code_client;

						if (getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER_USE_AUXILIARY_ON_DEPOSIT')) {
							if ($k == getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER_DEPOSIT')) {
								$bookkeeping->subledger_account = $tabcompany[$key]['code_compta'];
								$bookkeeping->subledger_label = $tabcompany[$key]['name'];
							} else {
								$bookkeeping->subledger_account = '';
								$bookkeeping->subledger_label = '';
							}
						} else {
							$bookkeeping->subledger_account = '';
							$bookkeeping->subledger_label = '';
						}

						$bookkeeping->numero_compte = $k;
						$bookkeeping->label_compte = $label_account;

						$bookkeeping->label_operation = $bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref, $label_account);
						$bookkeeping->montant = $mt;
						$bookkeeping->sens = ($mt < 0) ? 'D' : 'C';
						$bookkeeping->debit = ($mt < 0) ? -$mt : 0;
						$bookkeeping->credit = ($mt >= 0) ? $mt : 0;
						$bookkeeping->code_journal = $journal;
						$bookkeeping->journal_label = $langs->transnoentities($journal_label);
						$bookkeeping->fk_user_author = $user->id;
						$bookkeeping->entity = $conf->entity;

						$totaldebit += $bookkeeping->debit;
						$totalcredit += $bookkeeping->credit;

						$result = $bookkeeping->create($user);
						if ($result < 0) {
							if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {	// Already exists
								$error++;
								$errorforline++;
								$errorforinvoice[$key] = 'alreadyjournalized';
								$this->errorforinvoicedetail[$key] = array(
									'ref' => (string) $val['ref'],
									'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
								);
							} else {
								$error++;
								$errorforline++;
								$errorforinvoice[$key] = 'other';
								setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
								$this->errorforinvoicedetail[$key] = array(
									'ref' => (string) $val['ref'],
									'error' => $bookkeeping->errorsToString(),
								);
							}
						}
					}
				}
			}

			// VAT
			if (!$errorforline) {
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
							if (empty($conf->cache['accountingaccountincurrententity_vat'][$k])) {
								$accountingaccount = new AccountingAccount($this->db);
								$accountingaccount->fetch(0, $k, true);
								$conf->cache['accountingaccountincurrententity_vat'][$k] = $accountingaccount;
							} else {
								$accountingaccount = $conf->cache['accountingaccountincurrententity_vat'][$k];
							}

							$label_account = $accountingaccount->label;

							$bookkeeping = new BookKeeping($this->db);
							$bookkeeping->doc_date = $val["date"];
							$bookkeeping->date_lim_reglement = $val["datereg"];
							$bookkeeping->doc_ref = $val["ref"];
							$bookkeeping->date_creation = $now;
							$bookkeeping->doc_type = 'customer_invoice';
							$bookkeeping->fk_doc = $key;
							$bookkeeping->fk_docdet = 0; // Useless, can be several lines that are source of this record to add
							$bookkeeping->thirdparty_code = $companystatic->code_client;

							$bookkeeping->subledger_account = '';
							$bookkeeping->subledger_label = '';

							$bookkeeping->numero_compte = $k;
							$bookkeeping->label_compte = $label_account;


							$tmpvatrate = (empty($def_tva[$key][$k]) ? (empty($arrayofvat[$key][$k]) ? '' : $arrayofvat[$key][$k]) : implode(', ', $def_tva[$key][$k]));
							$labelvataccount = $langs->trans("Taxes").' '.$tmpvatrate.' %';
							$labelvataccount .= ($numtax ? ' - Localtax '.$numtax : '');
							$bookkeeping->label_operation = $bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref, $labelvataccount);

							$bookkeeping->montant = $mt;
							$bookkeeping->sens = ($mt < 0) ? 'D' : 'C';
							$bookkeeping->debit = ($mt < 0) ? -$mt : 0;
							$bookkeeping->credit = ($mt >= 0) ? $mt : 0;
							$bookkeeping->code_journal = $journal;
							$bookkeeping->journal_label = $langs->transnoentities($journal_label);
							$bookkeeping->fk_user_author = $user->id;
							$bookkeeping->entity = $conf->entity;

							$totaldebit += $bookkeeping->debit;
							$totalcredit += $bookkeeping->credit;

							$result = $bookkeeping->create($user);
							if ($result < 0) {
								if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {	// Already exists
									$error++;
									$errorforline++;
									$errorforinvoice[$key] = 'alreadyjournalized';
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $val['ref'],
										'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
									);
								} else {
									$error++;
									$errorforline++;
									$errorforinvoice[$key] = 'other';
									setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $val['ref'],
										'error' => $bookkeeping->errorsToString(),
									);
								}
							}
						}
					}
				}
			}

			// Revenue stamp
			if (!$errorforline) {
				if (isset($tabrevenuestamp[$key]) && is_array($tabrevenuestamp[$key])) {
					foreach ($tabrevenuestamp[$key] as $k => $mt) {
						if ($mt) {
							if (empty($conf->cache['accountingaccountincurrententity_rs'][$k])) {
								$accountingaccount = new AccountingAccount($this->db);
								$accountingaccount->fetch(0, $k, true);
								$conf->cache['accountingaccountincurrententity_rs'][$k] = $accountingaccount;
							} else {
								$accountingaccount = $conf->cache['accountingaccountincurrententity_rs'][$k];
							}

							$label_account = $accountingaccount->label;

							$bookkeeping = new BookKeeping($this->db);
							$bookkeeping->doc_date = $val["date"];
							$bookkeeping->date_lim_reglement = $val["datereg"];
							$bookkeeping->doc_ref = $val["ref"];
							$bookkeeping->date_creation = $now;
							$bookkeeping->doc_type = 'customer_invoice';
							$bookkeeping->fk_doc = $key;
							$bookkeeping->fk_docdet = 0; // Useless, can be several lines that are source of this record to add
							$bookkeeping->thirdparty_code = $companystatic->code_client;

							$bookkeeping->subledger_account = '';
							$bookkeeping->subledger_label = '';

							$bookkeeping->numero_compte = $k;
							$bookkeeping->label_compte = $label_account;

							$bookkeeping->label_operation = $bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref, $langs->trans("RevenueStamp"));
							$bookkeeping->montant = $mt;
							$bookkeeping->sens = ($mt < 0) ? 'D' : 'C';
							$bookkeeping->debit = ($mt < 0) ? -$mt : 0;
							$bookkeeping->credit = ($mt >= 0) ? $mt : 0;
							$bookkeeping->code_journal = $journal;
							$bookkeeping->journal_label = $langs->transnoentities($journal_label);
							$bookkeeping->fk_user_author = $user->id;
							$bookkeeping->entity = $conf->entity;

							$totaldebit += $bookkeeping->debit;
							$totalcredit += $bookkeeping->credit;

							$result = $bookkeeping->create($user);
							if ($result < 0) {
								if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {    // Already exists
									$error++;
									$errorforline++;
									$errorforinvoice[$key] = 'alreadyjournalized';
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $val['ref'],
										'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
									);
								} else {
									$error++;
									$errorforline++;
									$errorforinvoice[$key] = 'other';
									setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $val['ref'],
										'error' => $bookkeeping->errorsToString(),
									);
								}
							}
						}
					}
				}
			}

			// Protection against a bug on lines before
			if (!$errorforline && (price2num($totaldebit, 'MT') != price2num($totalcredit, 'MT'))) {
				$error++;
				$errorforline++;
				$errorforinvoice[$key] = 'amountsnotbalanced';
				setEventMessages('We Tried to insert a non balanced transaction in book for '.$invoicestatic->ref.'. Canceled. Surely a bug.', null, 'errors');
				$this->errorforinvoicedetail[$key] = array(
					'ref' => (string) $val['ref'],
					'error' => 'Try to insert a non balanced transaction in book for '.(string) $val['ref'].'. Canceled. Surely a bug.',
				);
			}

			if (!$errorforline) {
				$this->db->commit();
			} else {
				$this->db->rollback();

				if ($error >= $max_nb_errors) {
					setEventMessages($langs->trans("ErrorTooManyErrorsProcessStopped"), null, 'errors');
					break; // Break in the foreach
				}
			}
		}

		return $error ? -$error : 1;
	}

	/**
	 * Get supplier invoice lines eligible for journalization on this (nature=3) journal.
	 * Pure mechanical lift of accountancy/journal/purchasesjournal.php's former inline
	 * data-collection block (query + per-row aggregation, VAT reverse-charge tabs, VAT-NPR
	 * counterpart tab, too-many-lines guard, unbound-lines detection), plus its 4 hook points
	 * (doActions, printFieldListSelect/From/Where) - must be called on an instance already
	 * fetch()ed with the target journal id. Unlike getDataForSells(), this journal has no
	 * processingJournalData/processedJournalData hooks (the original page never had them) and
	 * its return shape is not a copy of getDataForSells()'s: no tabwarranty/tabrevenuestamp,
	 * but has purchases-specific tabother (VAT-NPR counterpart) and
	 * tabrctva/tabrclocaltax1/tabrclocaltax2 (VAT reverse-charge) keys instead.
	 *
	 * @param	User	$user				User (unused directly here, kept for signature symmetry with writeIntoBookkeepingForPurchases())
	 * @param	int		$date_start			Start date (timestamp)
	 * @param	int		$date_end			End date (timestamp)
	 * @param	string	$in_bookkeeping		'notyet' (default) or 'already'
	 * @return	array{tabfac:array<int,array{date:int,datereg:int,ref:string,refsologest:string,refsuppliersologest:string,type:int,description:string,close_code:string}>,tabht:array<int,array<string,float>>,tabtva:array<int,array<string,float>>,def_tva:array<int,array<string,array<string,string>>>,tabttc:array<int,array<string,float>>,tablocaltax1:array<int,array<string,float>>,tablocaltax2:array<int,array<string,float>>,tabcompany:array<int,array{id:int,name:string,code_fournisseur:string,accountancy_code_supplier_general:string,code_compta_fournisseur:string}>,tabother:array<int,array<string,float>>,tabrctva:array<int,array<string,float>>,tabrclocaltax1:array<int,array<string,float>>,tabrclocaltax2:array<int,array<string,float>>,errorforinvoice:array<int,string>,error:int}
	 */
	public function getDataForPurchases(User $user, $date_start, $date_end, $in_bookkeeping = 'notyet')
	{
		global $conf, $langs, $mysoc, $hookmanager;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/accounting.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';

		$tabfac = array();
		$tabht = array();
		$tabtva = array();
		$def_tva = array();
		$tabttc = array();
		$tablocaltax1 = array();
		$tablocaltax2 = array();
		$tabcompany = array();
		$tabother = array();
		$tabrctva = array();
		$tabrclocaltax1 = array();
		$tabrclocaltax2 = array();
		$vatdata_cache = array();
		$errorforinvoice = array();
		$toomanylineserror = 0;

		$action = '';
		$parameters = array();
		$reshook = $hookmanager->executeHooks('doActions', $parameters, $user, $action);

		$sql = "SELECT f.rowid, f.ref as ref, f.type, f.datef as df, f.libelle as label, f.ref_supplier, f.date_lim_reglement as dlr, f.close_code, f.vat_reverse_charge,";
		$sql .= " fd.rowid as fdid, fd.description, fd.product_type, fd.total_ht, fd.tva as total_tva, fd.total_localtax1, fd.total_localtax2, fd.tva_tx, fd.localtax1_tx, fd.localtax2_tx, fd.total_ttc, fd.vat_src_code, fd.info_bits,";
		$sql .= " p.default_vat_code AS product_buy_default_vat_code, p.tva_tx as product_buy_vat, p.localtax1_tx as product_buy_localvat1, p.localtax2_tx as product_buy_localvat2,";
		$sql .= " co.code as country_code, co.label as country_label,";
		$sql .= " s.rowid as socid, s.nom as name, s.fournisseur, s.code_client, s.code_fournisseur, s.fk_pays,";
		if (getDolGlobalString('MAIN_COMPANY_PERENTITY_SHARED')) {
			$sql .= " spe.accountancy_code_customer_general,";
			$sql .= " spe.accountancy_code_customer as code_compta,";
			$sql .= " spe.accountancy_code_supplier_general,";
			$sql .= " spe.accountancy_code_supplier as code_compta_fournisseur,";
		} else {
			$sql .= " s.accountancy_code_customer_general,";
			$sql .= " s.code_compta as code_compta,";
			$sql .= " s.accountancy_code_supplier_general,";
			$sql .= " s.code_compta_fournisseur,";
		}
		if (getDolGlobalString('MAIN_PRODUCT_PERENTITY_SHARED')) {
			$sql .= " ppe.accountancy_code_buy,";
		} else {
			$sql .= " p.accountancy_code_buy,";
		}
		$sql .= " aa.rowid as fk_compte, aa.account_number as compte, aa.label as label_compte";
		$parameters = array();
		$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters); // Note that $action and $object may have been modified by hook
		$sql .= $hookmanager->resPrint;
		$sql .= " FROM ".MAIN_DB_PREFIX."facture_fourn_det as fd";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = fd.fk_product";
		if (getDolGlobalString('MAIN_PRODUCT_PERENTITY_SHARED')) {
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product_perentity as ppe ON ppe.fk_product = p.rowid AND ppe.entity = " . ((int) $conf->entity);
		}
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."accounting_account as aa ON aa.rowid = fd.fk_code_ventilation";
		$sql .= " JOIN ".MAIN_DB_PREFIX."facture_fourn as f ON f.rowid = fd.fk_facture_fourn";
		$sql .= " JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_country as co ON co.rowid = s.fk_pays ";
		if (getDolGlobalString('MAIN_COMPANY_PERENTITY_SHARED')) {
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe_perentity as spe ON spe.fk_soc = s.rowid AND spe.entity = " . ((int) $conf->entity);
		}
		$parameters = array();
		$reshook = $hookmanager->executeHooks('printFieldListFrom', $parameters); // Note that $action and $object may have been modified by hook
		$sql .= $hookmanager->resPrint;
		$sql .= " WHERE f.fk_statut > 0";
		$sql .= " AND fd.fk_code_ventilation > 0";
		$sql .= " AND f.entity IN (".getEntity('facture_fourn', 0).")"; // We don't share object for accountancy
		if (getDolGlobalString('FACTURE_SUPPLIER_DEPOSITS_ARE_JUST_PAYMENTS')) {
			$sql .= " AND f.type IN (".FactureFournisseur::TYPE_STANDARD.",".FactureFournisseur::TYPE_REPLACEMENT.",".FactureFournisseur::TYPE_CREDIT_NOTE.",".FactureFournisseur::TYPE_SITUATION.")";
		} else {
			$sql .= " AND f.type IN (".FactureFournisseur::TYPE_STANDARD.",".FactureFournisseur::TYPE_REPLACEMENT.",".FactureFournisseur::TYPE_CREDIT_NOTE.",".FactureFournisseur::TYPE_DEPOSIT.",".FactureFournisseur::TYPE_SITUATION.")";
		}
		if ($date_start && $date_end) {
			$sql .= " AND f.datef >= '".$this->db->idate($date_start)."' AND f.datef <= '".$this->db->idate($date_end)."'";
		}
		// Define begin binding date
		if (getDolGlobalInt('ACCOUNTING_DATE_START_BINDING')) {
			$sql .= " AND f.datef >= '".$this->db->idate(getDolGlobalInt('ACCOUNTING_DATE_START_BINDING'))."'";
		}
		// Already in bookkeeping or not
		if ($in_bookkeeping == 'already') {
			$sql .= " AND f.rowid IN (SELECT fk_doc FROM ".MAIN_DB_PREFIX."accounting_bookkeeping as ab WHERE ab.doc_type='supplier_invoice')";
		}
		if ($in_bookkeeping == 'notyet') {
			$sql .= " AND f.rowid NOT IN (SELECT fk_doc FROM ".MAIN_DB_PREFIX."accounting_bookkeeping as ab WHERE ab.doc_type='supplier_invoice')";
		}
		$parameters = array();
		$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters); // Note that $action and $object may have been modified by hook
		$sql .= $hookmanager->resPrint;
		$sql .= " ORDER BY f.datef";

		dol_syslog('accountancy/class/accountingjournal.class.php::getDataForPurchases', LOG_DEBUG);

		// Variables
		$cptfour = getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER', 'NotDefined');
		$cpttva = getDolGlobalString('ACCOUNTING_VAT_BUY_ACCOUNT', 'NotDefined');
		$rcctva = getDolGlobalString('ACCOUNTING_VAT_BUY_REVERSE_CHARGES_CREDIT', 'NotDefined');
		$rcdtva = getDolGlobalString('ACCOUNTING_VAT_BUY_REVERSE_CHARGES_DEBIT', 'NotDefined');
		$cptlocaltax1 = getDolGlobalString('ACCOUNTING_LT1_BUY_ACCOUNT', 'NotDefined');
		$rcclocaltax1 = getDolGlobalString('ACCOUNTING_LT1_BUY_REVERSE_CHARGES_CREDIT', 'NotDefined');
		$rcdlocaltax1 = getDolGlobalString('ACCOUNTING_LT1_BUY_REVERSE_CHARGES_DEBIT', 'NotDefined');
		$cptlocaltax2 = getDolGlobalString('ACCOUNTING_LT2_BUY_ACCOUNT', 'NotDefined');
		$rcclocaltax2 = getDolGlobalString('ACCOUNTING_LT2_BUY_REVERSE_CHARGES_CREDIT', 'NotDefined');
		$rcdlocaltax2 = getDolGlobalString('ACCOUNTING_LT2_BUY_REVERSE_CHARGES_DEBIT', 'NotDefined');
		$noTaxDispatchingKeepWithLines = getDolGlobalInt('ACCOUNTING_PURCHASES_DO_NOT_DISPATCH_TAXES'); //If enabled, Tax will NOT get split off from the base entry and credited to a separate tax account (good for non-VAT countries like USA)
		$country_code_in_EEC = getCountriesInEEC();		// This make a database call but there is a cache done into $conf->cache['country_code_in_EEC']

		$result = $this->db->query($sql);
		if ($result) {
			$num = $this->db->num_rows($result);

			$i = 0;
			while ($i < $num) {
				$obj = $this->db->fetch_object($result);

				// Controls
				$accountancy_code_supplier_general = (!empty($obj->accountancy_code_supplier_general)) ? $obj->accountancy_code_supplier_general : $cptfour;
				$compta_soc = ($obj->code_compta_fournisseur != "") ? $obj->code_compta_fournisseur : $cptfour;

				$compta_prod = $obj->compte;
				if (empty($compta_prod)) {
					if ($obj->product_type == 0) {
						$compta_prod = getDolGlobalString('ACCOUNTING_PRODUCT_BUY_ACCOUNT', 'NotDefined');
					} else {
						$compta_prod = getDolGlobalString('ACCOUNTING_SERVICE_BUY_ACCOUNT', 'NotDefined');
					}
				}

				$tax_id = $obj->tva_tx . ($obj->vat_src_code ? ' (' . $obj->vat_src_code . ')' : '');
				if (array_key_exists($tax_id, $vatdata_cache)) {
					$vatdata = $vatdata_cache[$tax_id];
				} else {
					$vatdata = getTaxesFromId($tax_id, $mysoc, $mysoc, 0);
					$vatdata_cache[$tax_id] = $vatdata;
				}
				$compta_tva = (!empty($vatdata['accountancy_code_buy']) ? $vatdata['accountancy_code_buy'] : $cpttva);
				$compta_localtax1 = (!empty($vatdata['accountancy_code_buy']) ? $vatdata['accountancy_code_buy'] : $cptlocaltax1);
				$compta_localtax2 = (!empty($vatdata['accountancy_code_buy']) ? $vatdata['accountancy_code_buy'] : $cptlocaltax2);
				$compta_counterpart_tva_npr = getDolGlobalString('ACCOUNTING_COUNTERPART_VAT_NPR', 'NotDefined');

				// Define an array to display all VAT rates that use this accounting account $compta_tva
				if (price2num($obj->tva_tx) || !empty($obj->vat_src_code)) {
					$def_tva[$obj->rowid][$compta_tva][vatrate($obj->tva_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : '')] = (vatrate($obj->tva_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : ''));
					if ($obj->localtax1_tx > 0.0) {
						$def_tva[$obj->rowid][$compta_localtax1][vatrate($obj->localtax1_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : '').' LT1'] = (vatrate($obj->localtax1_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : ''));
					}
					if ($obj->localtax2_tx > 0.0) {
						$def_tva[$obj->rowid][$compta_localtax2][vatrate($obj->localtax2_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : '').' LT2'] = (vatrate($obj->localtax2_tx).($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : ''));
					}
				}

				$tabfac[$obj->rowid]["date"] = $this->db->jdate($obj->df);
				$tabfac[$obj->rowid]["datereg"] = $this->db->jdate($obj->dlr);
				$tabfac[$obj->rowid]["ref"] = $obj->ref_supplier.' ('.$obj->ref.')';
				$tabfac[$obj->rowid]["refsologest"] = $obj->ref;
				$tabfac[$obj->rowid]["refsuppliersologest"] = $obj->ref_supplier;
				$tabfac[$obj->rowid]["type"] = $obj->type;
				$tabfac[$obj->rowid]["description"] = $obj->description;
				$tabfac[$obj->rowid]["close_code"] = $obj->close_code; // close_code = 'replaced' for replacement invoices (not used in most european countries)

				// Avoid warnings
				if (!isset($tabttc[$obj->rowid][$compta_soc])) {
					$tabttc[$obj->rowid][$compta_soc] = 0;
				}
				if (!isset($tabht[$obj->rowid][$compta_prod])) {
					$tabht[$obj->rowid][$compta_prod] = 0;
				}
				if (!isset($tabtva[$obj->rowid][$compta_tva])) {
					$tabtva[$obj->rowid][$compta_tva] = 0;
				}
				if (!isset($tablocaltax1[$obj->rowid][$compta_localtax1])) {
					$tablocaltax1[$obj->rowid][$compta_localtax1] = 0;
				}
				if (!isset($tablocaltax2[$obj->rowid][$compta_localtax2])) {
					$tablocaltax2[$obj->rowid][$compta_localtax2] = 0;
				}

				// VAT Reverse charge
				if (($mysoc->country_code == 'FR' || getDolGlobalString('ACCOUNTING_FORCE_ENABLE_VAT_REVERSE_CHARGE')) && $obj->vat_reverse_charge == 1 && (in_array($obj->country_code, $country_code_in_EEC) || getDolGlobalString('ACCOUNTING_REVERSE_CHARGE_ALSO_NON_EEC'))) {
					$rcvatdata = getTaxesFromId($obj->product_buy_vat . ($obj->product_buy_default_vat_code ? ' (' . $obj->product_buy_default_vat_code . ')' : ''), $mysoc, $mysoc, 0);
					$rcc_compta_tva = (!empty($vatdata['accountancy_code_vat_reverse_charge_credit']) ? $vatdata['accountancy_code_vat_reverse_charge_credit'] : $rcctva);
					$rcd_compta_tva = (!empty($vatdata['accountancy_code_vat_reverse_charge_debit']) ? $vatdata['accountancy_code_vat_reverse_charge_debit'] : $rcdtva);
					$rcc_compta_localtax1 = (!empty($vatdata['accountancy_code_vat_reverse_charge_credit']) ? $vatdata['accountancy_code_vat_reverse_charge_credit'] : $rcclocaltax1);
					$rcd_compta_localtax1 = (!empty($vatdata['accountancy_code_vat_reverse_charge_debit']) ? $vatdata['accountancy_code_vat_reverse_charge_debit'] : $rcdlocaltax1);
					$rcc_compta_localtax2 = (!empty($vatdata['accountancy_code_vat_reverse_charge_credit']) ? $vatdata['accountancy_code_vat_reverse_charge_credit'] : $rcclocaltax2);
					$rcd_compta_localtax2 = (!empty($vatdata['accountancy_code_vat_reverse_charge_debit']) ? $vatdata['accountancy_code_vat_reverse_charge_debit'] : $rcdlocaltax2);
					if (price2num($obj->product_buy_vat) || !empty($obj->product_buy_default_vat_code)) {
						$vat_key = vatrate($obj->product_buy_vat) . ($obj->product_buy_default_vat_code ? ' (' . $obj->product_buy_default_vat_code . ')' : '');
						$val_value = $vat_key;
						$def_tva[$obj->rowid][$rcc_compta_tva][$vat_key] = $val_value;
						$def_tva[$obj->rowid][$rcd_compta_tva][$vat_key] = $val_value;
					}

					if (!isset($tabrctva[$obj->rowid][$rcc_compta_tva])) {
						$tabrctva[$obj->rowid][$rcc_compta_tva] = 0;
					}
					if (!isset($tabrctva[$obj->rowid][$rcd_compta_tva])) {
						$tabrctva[$obj->rowid][$rcd_compta_tva] = 0;
					}
					if (!isset($tabrclocaltax1[$obj->rowid][$rcc_compta_localtax1])) {
						$tabrclocaltax1[$obj->rowid][$rcc_compta_localtax1] = 0;
					}
					if (!isset($tabrclocaltax1[$obj->rowid][$rcd_compta_localtax1])) {
						$tabrclocaltax1[$obj->rowid][$rcd_compta_localtax1] = 0;
					}
					if (!isset($tabrclocaltax2[$obj->rowid][$rcc_compta_localtax2])) {
						$tabrclocaltax2[$obj->rowid][$rcc_compta_localtax2] = 0;
					}
					if (!isset($tabrclocaltax2[$obj->rowid][$rcd_compta_localtax2])) {
						$tabrclocaltax2[$obj->rowid][$rcd_compta_localtax2] = 0;
					}

					$rcvat = (float) price2num($obj->total_ttc * $obj->product_buy_vat / 100, 'MT');
					$rclocalvat1 = (float) price2num($obj->total_ttc * $obj->product_buy_localvat1 / 100, 'MT');
					$rclocalvat2 = (float) price2num($obj->total_ttc * $obj->product_buy_localvat2 / 100, 'MT');

					$tabrctva[$obj->rowid][$rcd_compta_tva] += $rcvat;
					$tabrctva[$obj->rowid][$rcc_compta_tva] -= $rcvat;
					$tabrclocaltax1[$obj->rowid][$rcd_compta_localtax1] += $rclocalvat1;
					$tabrclocaltax1[$obj->rowid][$rcc_compta_localtax1] -= $rclocalvat1;
					$tabrclocaltax2[$obj->rowid][$rcd_compta_localtax2] += $rclocalvat2;
					$tabrclocaltax2[$obj->rowid][$rcc_compta_localtax2] -= $rclocalvat2;
				}

				$tabttc[$obj->rowid][$compta_soc] += $obj->total_ttc;

				if ($noTaxDispatchingKeepWithLines) { //case where all taxes paid should be grouped with the same account as the main expense (best for USA)
					$tabht[$obj->rowid][$compta_prod] += $obj->total_ttc;
				} else { //case where every tax paid should be broken out into its own account for future recovery (best for VAT countries)
					$tabht[$obj->rowid][$compta_prod] += $obj->total_ht;
					$tabtva[$obj->rowid][$compta_tva] += $obj->total_tva;
					$tva_npr = ((($obj->info_bits & 1) == 1) ? 1 : 0);
					if ($tva_npr) { // If NPR, we add an entry for counterpartWe into tabother
						$tabother[$obj->rowid][$compta_counterpart_tva_npr] += $obj->total_tva;
					}
					$tablocaltax1[$obj->rowid][$compta_localtax1] += $obj->total_localtax1;
					$tablocaltax2[$obj->rowid][$compta_localtax2] += $obj->total_localtax2;
				}
				$tabcompany[$obj->rowid] = array(
						'id' => $obj->socid,
						'name' => $obj->name,
						'code_fournisseur' => $obj->code_fournisseur,
						'accountancy_code_supplier_general' => $accountancy_code_supplier_general,
						'code_compta_fournisseur' => $compta_soc
					);

				$i++;

				// Check for too many lines.
				if ($i > getDolGlobalInt('ACCOUNTANCY_MAX_TOO_MANY_LINES_TO_PROCESS', 10000)) {
					$toomanylineserror++;
					setEventMessages("ErrorTooManyLinesToProcessPleaseUseAMoreSelectiveFilter", null, 'errors');
					break;
				}
			}
		} else {
			$this->errors[] = $this->db->lasterror();
		}

		// New way, single query, load all unbound lines
		if (!empty($tabfac)) {
			$sql = "SELECT fk_facture_fourn, COUNT(fd.rowid) as nb";
			$sql .= " FROM ".MAIN_DB_PREFIX."facture_fourn_det as fd";
			$sql .= " WHERE fd.product_type <= 2 AND fd.fk_code_ventilation <= 0 AND fd.total_ttc <> 0";
			$sql .= " AND fk_facture_fourn IN (".$this->db->sanitize(implode(",", array_keys($tabfac))).")";
			$sql .= " GROUP BY fk_facture_fourn";
			$resql = $this->db->query($sql);
			if ($resql) {
				$num = $this->db->num_rows($resql);
				$i = 0;
				while ($i < $num) {
					$obj = $this->db->fetch_object($resql);
					if ($obj->nb > 0) {
						$errorforinvoice[$obj->fk_facture_fourn] = 'somelinesarenotbound';
					}
					$i++;
				}
			}
		}

		return array(
			'tabfac' => $tabfac,
			'tabht' => $tabht,
			'tabtva' => $tabtva,
			'def_tva' => $def_tva,
			'tabttc' => $tabttc,
			'tablocaltax1' => $tablocaltax1,
			'tablocaltax2' => $tablocaltax2,
			'tabcompany' => $tabcompany,
			'tabother' => $tabother,
			'tabrctva' => $tabrctva,
			'tabrclocaltax1' => $tabrclocaltax1,
			'tabrclocaltax2' => $tabrclocaltax2,
			'errorforinvoice' => $errorforinvoice,
			'error' => $toomanylineserror,
		);
	}

	/**
	 * Compute per-invoice preview subtotals from an already-collected getDataForPurchases()
	 * return array, without writing anything to the bookkeeping. Read-only re-derivation of
	 * writeIntoBookkeepingForPurchases()'s 4-block sign logic (thirdparty/ttc, product/service,
	 * VAT+localtax1+localtax2 with the reverse-charge substitution, VAT-NPR counterpart) - no
	 * BookKeeping object is built, no create()/begin()/commit() call is made. Keep this in sync
	 * with writeIntoBookkeepingForPurchases() if that method's sign conventions or reverse-charge
	 * substitution logic ever change.
	 *
	 * total_ht/total_ttc are plain business-readable sums of tabht/tabttc. total_debit/
	 * total_credit are the actual amounts the write would post, folding in all 4 buckets (plus
	 * the reverse-charge/NPR counterparts) - they should be equal for any invoice that would
	 * transfer cleanly.
	 *
	 * @param	array	$data	Return value of getDataForPurchases()
	 * @phan-param array{tabfac:array<int,array{date:int,datereg:int,ref:string,refsologest:string,refsuppliersologest:string,type:int,description:string,close_code:string}>,tabht:array<int,array<string,float>>,tabtva:array<int,array<string,float>>,def_tva:array<int,array<string,array<string,string>>>,tabttc:array<int,array<string,float>>,tablocaltax1:array<int,array<string,float>>,tablocaltax2:array<int,array<string,float>>,tabcompany:array<int,array{id:int,name:string,code_fournisseur:string,accountancy_code_supplier_general:string,code_compta_fournisseur:string}>,tabother:array<int,array<string,float>>,tabrctva:array<int,array<string,float>>,tabrclocaltax1:array<int,array<string,float>>,tabrclocaltax2:array<int,array<string,float>>,errorforinvoice:array<int,string>,error:int} $data
	 * @phpstan-param array{tabfac:array<int,array{date:int,datereg:int,ref:string,refsologest:string,refsuppliersologest:string,type:int,description:string,close_code:string}>,tabht:array<int,array<string,float>>,tabtva:array<int,array<string,float>>,def_tva:array<int,array<string,array<string,string>>>,tabttc:array<int,array<string,float>>,tablocaltax1:array<int,array<string,float>>,tablocaltax2:array<int,array<string,float>>,tabcompany:array<int,array{id:int,name:string,code_fournisseur:string,accountancy_code_supplier_general:string,code_compta_fournisseur:string}>,tabother:array<int,array<string,float>>,tabrctva:array<int,array<string,float>>,tabrclocaltax1:array<int,array<string,float>>,tabrclocaltax2:array<int,array<string,float>>,errorforinvoice:array<int,string>,error:int} $data
	 * @return	array<int,array{total_ht:float,total_ttc:float,total_debit:float,total_credit:float}>	Keyed by the same invoice id as $data['tabfac']
	 */
	public function getPreviewAmountsForPurchases(array $data)
	{
		global $mysoc;

		$result = array();
		foreach ($data['tabfac'] as $key => $val) {
			$totalht = array_sum($data['tabht'][$key]);
			$totalttc = array_sum($data['tabttc'][$key]);
			$totaldebit = 0.0;
			$totalcredit = 0.0;

			// Thirdparty/ttc - credit-positive (inverted vs. sells)
			foreach ($data['tabttc'][$key] as $mt) {
				$totalcredit += max($mt, 0);
				$totaldebit += max(-$mt, 0);
			}
			// Product/service - debit-positive
			foreach ($data['tabht'][$key] as $mt) {
				$totaldebit += max($mt, 0);
				$totalcredit += max(-$mt, 0);
			}
			// VAT + localtax1 + localtax2, with reverse-charge substitution - debit-positive
			// (mirrors writeIntoBookkeepingForPurchases()'s VAT block verbatim)
			foreach (array(0, 1, 2) as $numtax) {
				$arrayofvat = $data['tabtva'];
				$rcarray = $data['tabrctva'];
				if ($numtax == 1) {
					$arrayofvat = $data['tablocaltax1'];
					$rcarray = $data['tabrclocaltax1'];
				}
				if ($numtax == 2) {
					$arrayofvat = $data['tablocaltax2'];
					$rcarray = $data['tabrclocaltax2'];
				}

				if ($mysoc->country_code == 'FR' || getDolGlobalString('ACCOUNTING_FORCE_ENABLE_VAT_REVERSE_CHARGE')) {
					$has_vat = false;
					foreach ($arrayofvat[$key] as $mt) {
						if ($mt) {
							$has_vat = true;
						}
					}

					if (!$has_vat) {
						$arrayofvat = $rcarray;
						if (!isset($arrayofvat[$key]) || !is_array($arrayofvat[$key])) {
							$arrayofvat[$key] = array();
						}
					}
				}

				foreach ($arrayofvat[$key] as $mt) {
					if ($mt) {
						$totaldebit += max($mt, 0);
						$totalcredit += max(-$mt, 0);
					}
				}
			}
			// VAT-NPR counterpart - debit-positive
			if (isset($data['tabother'][$key]) && is_array($data['tabother'][$key])) {
				foreach ($data['tabother'][$key] as $mt) {
					if ($mt) {
						$totaldebit += max($mt, 0);
						$totalcredit += max(-$mt, 0);
					}
				}
			}

			$result[$key] = array(
				'total_ht' => (float) price2num($totalht, 'MT'),
				'total_ttc' => (float) price2num($totalttc, 'MT'),
				'total_debit' => (float) price2num($totaldebit, 'MT'),
				'total_credit' => (float) price2num($totalcredit, 'MT'),
			);
		}
		return $result;
	}

	/**
	 * Write the purchases journal (nature=3) into the bookkeeping.
	 * Pure mechanical lift of accountancy/journal/purchasesjournal.php's former inline
	 * writebookkeeping action block. No hook is fired here - the original block had none.
	 * Must be called on an instance already fetch()ed with the target journal id.
	 *
	 * @param	User	$user				User who write in the bookkeeping
	 * @param	int		$date_start			Start date (timestamp)
	 * @param	int		$date_end			End date (timestamp)
	 * @param	int		$max_nb_errors		Nb errors authorized before stopping the process
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	public function writeIntoBookkeepingForPurchases(User $user, $date_start, $date_end, $max_nb_errors = 10)
	{
		global $conf, $langs, $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/bookkeeping.class.php';
		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

		$data = $this->getDataForPurchases($user, $date_start, $date_end, 'notyet');
		$tabfac = $data['tabfac'];
		$tabht = $data['tabht'];
		$tabtva = $data['tabtva'];
		$def_tva = $data['def_tva'];
		$tabttc = $data['tabttc'];
		$tablocaltax1 = $data['tablocaltax1'];
		$tablocaltax2 = $data['tablocaltax2'];
		$tabcompany = $data['tabcompany'];
		$tabother = $data['tabother'];
		$tabrctva = $data['tabrctva'];
		$tabrclocaltax1 = $data['tabrclocaltax1'];
		$tabrclocaltax2 = $data['tabrclocaltax2'];
		$errorforinvoice = $data['errorforinvoice'];

		// Matches the page's own original gate: if data-collection hit the too-many-lines
		// guard, the write action must not run at all (not even partially, on the truncated
		// resultset it collected before breaking out).
		if (!empty($data['error'])) {
			return -1 * $data['error'];
		}

		$journal = $this->code;
		$journal_label = $this->label;

		$now = dol_now();
		$error = 0;
		$this->errorforinvoicedetail = array();

		$companystatic = new Societe($this->db);
		$invoicestatic = new FactureFournisseur($this->db);
		$accountingaccountsupplier = new AccountingAccount($this->db);
		$bookkeepingstatic = new BookKeeping($this->db);

		$accountingaccountsupplier->fetch(0, getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER'), true);

		foreach ($tabfac as $key => $val) {		// Loop on each invoice
			$errorforline = 0;

			$totalcredit = 0;
			$totaldebit = 0;

			$this->db->begin();		// We accept transaction into loop, so if we hang, we can continue transfer from the last error

			$companystatic->id = $tabcompany[$key]['id'];
			$companystatic->name = $tabcompany[$key]['name'];
			$companystatic->accountancy_code_supplier_general = $tabcompany[$key]['accountancy_code_supplier_general'];
			$companystatic->code_compta_fournisseur = $tabcompany[$key]['code_compta_fournisseur'];
			$companystatic->code_fournisseur = $tabcompany[$key]['code_fournisseur'];
			$companystatic->fournisseur = 1;

			$invoicestatic->id = $key;
			$invoicestatic->ref = (string) $val["refsologest"];
			$invoicestatic->ref_supplier = $val["refsuppliersologest"];
			$invoicestatic->type = $val["type"];
			$invoicestatic->description = html_entity_decode(dol_trunc($val["description"], 32));
			$invoicestatic->close_code = $val["close_code"];

			// Is it a replaced invoice? 0=not a replaced invoice, 1=replaced invoice not yet dispatched, 2=replaced invoice dispatched
			$replacedinvoice = 0;
			if ($invoicestatic->close_code == FactureFournisseur::CLOSECODE_REPLACED) {
				$replacedinvoice = 1;
				$alreadydispatched = $invoicestatic->getVentilExportCompta(); // Test if replaced invoice already into bookkeeping.
				if ($alreadydispatched) {
					$replacedinvoice = 2;
				}
			}

			// If not already into bookkeeping, we won't add it. If yes, do nothing (should not happen because creating replacement not possible if invoice is accounted)
			if ($replacedinvoice == 1) {
				$this->db->rollback();
				continue;
			}

			// Error if some lines are not binded/ready to be journalized
			if (isset($errorforinvoice[$key]) && $errorforinvoice[$key] == 'somelinesarenotbound') {
				$error++;
				$errorforline++;
				setEventMessages($langs->trans('ErrorInvoiceContainsLinesNotYetBounded', $val['ref']), null, 'errors');
				$this->errorforinvoicedetail[$key] = array(
					'ref' => (string) $val['ref'],
					'error' => $langs->trans('ErrorInvoiceContainsLinesNotYetBounded', $val['ref']),
				);
			}

			// Thirdparty
			if (!$errorforline) {
				foreach ($tabttc[$key] as $k => $mt) {
					$bookkeeping = new BookKeeping($this->db);
					$bookkeeping->doc_date = $val["date"];
					$bookkeeping->date_lim_reglement = $val["datereg"];
					$bookkeeping->doc_ref = $val["refsologest"];
					$bookkeeping->date_creation = $now;
					$bookkeeping->doc_type = 'supplier_invoice';
					$bookkeeping->fk_doc = $key;
					$bookkeeping->fk_docdet = 0; // Useless, can be several lines that are source of this record to add
					$bookkeeping->thirdparty_code = $companystatic->code_fournisseur;

					$bookkeeping->subledger_account = $tabcompany[$key]['code_compta_fournisseur'];
					$bookkeeping->subledger_label = $tabcompany[$key]['name'];

					$bookkeeping->numero_compte = $tabcompany[$key]['accountancy_code_supplier_general'];
					$bookkeeping->label_compte = $accountingaccountsupplier->label;

					$bookkeeping->label_operation = $bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref_supplier, $langs->trans("SubledgerAccount"));
					$bookkeeping->montant = $mt;
					$bookkeeping->sens = ($mt >= 0) ? 'C' : 'D';
					$bookkeeping->debit = ($mt <= 0) ? -$mt : 0;
					$bookkeeping->credit = ($mt > 0) ? $mt : 0;
					$bookkeeping->code_journal = $journal;
					$bookkeeping->journal_label = $langs->transnoentities($journal_label);
					$bookkeeping->fk_user_author = $user->id;
					$bookkeeping->entity = $conf->entity;

					$totaldebit += $bookkeeping->debit;
					$totalcredit += $bookkeeping->credit;

					$result = $bookkeeping->create($user);
					if ($result < 0) {
						if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {	// Already exists
							$error++;
							$errorforline++;
							$errorforinvoice[$key] = 'alreadyjournalized';
							$this->errorforinvoicedetail[$key] = array(
								'ref' => (string) $val['ref'],
								'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
							);
						} else {
							$error++;
							$errorforline++;
							$errorforinvoice[$key] = 'other';
							setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
							$this->errorforinvoicedetail[$key] = array(
								'ref' => (string) $val['ref'],
								'error' => $bookkeeping->errorsToString(),
							);
						}
					} else {
						if (getDolGlobalInt('ACCOUNTING_ENABLE_LETTERING') && getDolGlobalInt('ACCOUNTING_ENABLE_AUTOLETTERING')) {
							require_once DOL_DOCUMENT_ROOT . '/accountancy/class/lettering.class.php';
							$lettering_static = new Lettering($this->db);

							$nb_lettering = $lettering_static->bookkeepingLettering(array($bookkeeping->id));
						}
					}
				}
			}

			// Product / Service
			if (!$errorforline) {
				foreach ($tabht[$key] as $k => $mt) {
					if (empty($conf->cache['accountingaccountincurrententity'][$k])) {
						$accountingaccount = new AccountingAccount($this->db);
						$accountingaccount->fetch(0, $k, true);
						$conf->cache['accountingaccountincurrententity'][$k] = $accountingaccount;
					} else {
						$accountingaccount = $conf->cache['accountingaccountincurrententity'][$k];
					}

					$label_account = $accountingaccount->label;

					// get compte id and label
					if ($accountingaccount->id > 0) {
						$bookkeeping = new BookKeeping($this->db);
						$bookkeeping->doc_date = $val["date"];
						$bookkeeping->date_lim_reglement = $val["datereg"];
						$bookkeeping->doc_ref = $val["refsologest"];
						$bookkeeping->date_creation = $now;
						$bookkeeping->doc_type = 'supplier_invoice';
						$bookkeeping->fk_doc = $key;
						$bookkeeping->fk_docdet = 0; // Useless, can be several lines that are source of this record to add
						$bookkeeping->thirdparty_code = $companystatic->code_fournisseur;

						if (getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER_USE_AUXILIARY_ON_DEPOSIT')) {
							if ($k == getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER_DEPOSIT')) {
								$bookkeeping->subledger_account = $tabcompany[$key]['code_compta_fournisseur'];
								$bookkeeping->subledger_label = $tabcompany[$key]['name'];
							} else {
								$bookkeeping->subledger_account = '';
								$bookkeeping->subledger_label = '';
							}
						} else {
							$bookkeeping->subledger_account = '';
							$bookkeeping->subledger_label = '';
						}

						$bookkeeping->numero_compte = $k;
						$bookkeeping->label_compte = $label_account;

						$bookkeeping->label_operation = $bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref_supplier, $label_account);
						$bookkeeping->montant = $mt;
						$bookkeeping->sens = ($mt < 0) ? 'C' : 'D';
						$bookkeeping->debit = ($mt > 0) ? $mt : 0;
						$bookkeeping->credit = ($mt <= 0) ? -$mt : 0;
						$bookkeeping->code_journal = $journal;
						$bookkeeping->journal_label = $langs->transnoentities($journal_label);
						$bookkeeping->fk_user_author = $user->id;
						$bookkeeping->entity = $conf->entity;

						$totaldebit += $bookkeeping->debit;
						$totalcredit += $bookkeeping->credit;

						$result = $bookkeeping->create($user);
						if ($result < 0) {
							if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {	// Already exists
								$error++;
								$errorforline++;
								$errorforinvoice[$key] = 'alreadyjournalized';
								$this->errorforinvoicedetail[$key] = array(
									'ref' => (string) $val['ref'],
									'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
								);
							} else {
								$error++;
								$errorforline++;
								$errorforinvoice[$key] = 'other';
								setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
								$this->errorforinvoicedetail[$key] = array(
									'ref' => (string) $val['ref'],
									'error' => $bookkeeping->errorsToString(),
								);
							}
						}
					}
				}
			}

			// VAT
			if (!$errorforline) {
				$listoftax = array(0, 1, 2);
				foreach ($listoftax as $numtax) {
					$arrayofvat = $tabtva;
					if ($numtax == 1) {
						$arrayofvat = $tablocaltax1;
					}
					if ($numtax == 2) {
						$arrayofvat = $tablocaltax2;
					}

					// VAT Reverse charge
					if ($mysoc->country_code == 'FR' || getDolGlobalString('ACCOUNTING_FORCE_ENABLE_VAT_REVERSE_CHARGE')) {
						$has_vat = false;
						foreach ($arrayofvat[$key] as $k => $mt) {
							if ($mt) {
								$has_vat = true;
							}
						}

						if (!$has_vat) {
							$arrayofvat = $tabrctva;
							if ($numtax == 1) {
								$arrayofvat = $tabrclocaltax1;
							}
							if ($numtax == 2) {
								$arrayofvat = $tabrclocaltax2;
							}
							if (!isset($arrayofvat[$key]) || !is_array($arrayofvat[$key])) {
								$arrayofvat[$key] = array();
							}
						}
					}

					foreach ($arrayofvat[$key] as $k => $mt) {
						if ($mt) {
							if (empty($conf->cache['accountingaccountincurrententity_vat'][$k])) {
								$accountingaccount = new AccountingAccount($this->db);
								$accountingaccount->fetch(0, $k, true);
								$conf->cache['accountingaccountincurrententity_vat'][$k] = $accountingaccount;
							} else {
								$accountingaccount = $conf->cache['accountingaccountincurrententity_vat'][$k];
							}

							$label_account = $accountingaccount->label;

							$bookkeeping = new BookKeeping($this->db);
							$bookkeeping->doc_date = $val["date"];
							$bookkeeping->date_lim_reglement = $val["datereg"];
							$bookkeeping->doc_ref = $val["refsologest"];
							$bookkeeping->date_creation = $now;
							$bookkeeping->doc_type = 'supplier_invoice';
							$bookkeeping->fk_doc = $key;
							$bookkeeping->fk_docdet = 0; // Useless, can be several lines that are source of this record to add
							$bookkeeping->thirdparty_code = $companystatic->code_fournisseur;

							$bookkeeping->subledger_account = '';
							$bookkeeping->subledger_label = '';

							$bookkeeping->numero_compte = $k;
							$bookkeeping->label_compte = $label_account;

							$tmpvatrate = (empty($def_tva[$key][$k]) ? (empty($arrayofvat[$key][$k]) ? '' : $arrayofvat[$key][$k]) : implode(', ', $def_tva[$key][$k]));
							$labelvataccount = $langs->trans("Taxes").' '.$tmpvatrate.' %';
							$labelvataccount .= ($numtax ? ' - Localtax '.$numtax : '');
							$bookkeeping->label_operation = $bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref_supplier, $labelvataccount);

							$bookkeeping->montant = $mt;
							$bookkeeping->sens = ($mt < 0) ? 'C' : 'D';
							$bookkeeping->debit = ($mt > 0) ? $mt : 0;
							$bookkeeping->credit = ($mt <= 0) ? -$mt : 0;
							$bookkeeping->code_journal = $journal;
							$bookkeeping->journal_label = $langs->transnoentities($journal_label);
							$bookkeeping->fk_user_author = $user->id;
							$bookkeeping->entity = $conf->entity;

							$totaldebit += $bookkeeping->debit;
							$totalcredit += $bookkeeping->credit;

							$result = $bookkeeping->create($user);
							if ($result < 0) {
								if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {	// Already exists
									$error++;
									$errorforline++;
									$errorforinvoice[$key] = 'alreadyjournalized';
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $val['ref'],
										'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
									);
								} else {
									$error++;
									$errorforline++;
									$errorforinvoice[$key] = 'other';
									setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $val['ref'],
										'error' => $bookkeeping->errorsToString(),
									);
								}
							}
						}
					}
				}
			}

			// Counterpart of VAT for VAT NPR
			if (!$errorforline && isset($tabother[$key]) && is_array($tabother[$key])) {
				foreach ($tabother[$key] as $k => $mt) {
					if ($mt) {
						$bookkeeping = new BookKeeping($this->db);
						$bookkeeping->doc_date = $val["date"];
						$bookkeeping->date_lim_reglement = $val["datereg"];
						$bookkeeping->doc_ref = $val["refsologest"];
						$bookkeeping->date_creation = $now;
						$bookkeeping->doc_type = 'supplier_invoice';
						$bookkeeping->fk_doc = $key;
						$bookkeeping->fk_docdet = 0; // Useless, can be several lines that are source of this record to add
						$bookkeeping->thirdparty_code = $companystatic->code_fournisseur;

						$bookkeeping->subledger_account = '';
						$bookkeeping->subledger_label = '';

						$bookkeeping->numero_compte = $k;

						$bookkeeping->label_operation = $bookkeepingstatic->accountingLabelForOperation($companystatic->name, $invoicestatic->ref_supplier, $langs->trans("VAT").' NPR');
						$bookkeeping->montant = $mt;
						$bookkeeping->sens = ($mt < 0) ? 'C' : 'D';
						$bookkeeping->debit = ($mt > 0) ? $mt : 0;
						$bookkeeping->credit = ($mt <= 0) ? -$mt : 0;
						$bookkeeping->code_journal = $journal;
						$bookkeeping->journal_label = $langs->transnoentities($journal_label);
						$bookkeeping->fk_user_author = $user->id;
						$bookkeeping->entity = $conf->entity;

						$totaldebit += $bookkeeping->debit;
						$totalcredit += $bookkeeping->credit;

						$result = $bookkeeping->create($user);
						if ($result < 0) {
							if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {	// Already exists
								$error++;
								$errorforline++;
								$errorforinvoice[$key] = 'alreadyjournalized';
								$this->errorforinvoicedetail[$key] = array(
									'ref' => (string) $val['ref'],
									'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
								);
							} else {
								$error++;
								$errorforline++;
								$errorforinvoice[$key] = 'other';
								setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
								$this->errorforinvoicedetail[$key] = array(
									'ref' => (string) $val['ref'],
									'error' => $bookkeeping->errorsToString(),
								);
							}
						}
					}
				}
			}

			// Protection against a bug on lines before
			if (!$errorforline && (price2num($totaldebit, 'MT') != price2num($totalcredit, 'MT'))) {
				$error++;
				$errorforline++;
				$errorforinvoice[$key] = 'amountsnotbalanced';
				setEventMessages('We tried to insert a non balanced transaction in book for '.$invoicestatic->ref.'. Canceled. Surely a bug.', null, 'errors');
				$this->errorforinvoicedetail[$key] = array(
					'ref' => (string) $val['ref'],
					'error' => 'Try to insert a non balanced transaction in book for '.(string) $val['ref'].'. Canceled. Surely a bug.',
				);
			}

			if (!$errorforline) {
				$this->db->commit();
			} else {
				$this->db->rollback();

				if ($error >= $max_nb_errors) {
					setEventMessages($langs->trans("ErrorTooManyErrorsProcessStopped"), null, 'errors');
					break; // Break in the foreach
				}
			}
		}

		return $error ? -$error : 1;
	}

	/**
	 * Return source for doc_ref of a bank transaction.
	 * Pure mechanical lift of accountancy/journal/bankjournal.php's former page-local
	 * getSourceDocRef() function, promoted to a method on this class (public, not private)
	 * since the page's own export-CSV and view-rendering blocks call it too, from outside
	 * the class.
	 *
	 * @param	array<string,null|int|float|string>	$val			Array of val
	 * @param	string									$typerecord		Type of record ('payment', 'payment_supplier', 'payment_expensereport', 'payment_vat', ...)
	 * @return	string													A string label to describe a record into llx_bank_url
	 */
	public function getSourceDocRefForBank($val, $typerecord)
	{
		global $langs;

		// Defined the docref into $ref (We start with $val['ref'] by default and we complete according to other data)
		// WE MUST HAVE SAME REF FOR ALL LINES WE WILL RECORD INTO THE BOOKKEEPING
		$ref = $val['ref'];
		if ($ref == '(SupplierInvoicePayment)' || $ref == '(SupplierInvoicePaymentBack)') {
			$ref = $langs->transnoentitiesnoconv('Supplier');
		}
		if ($ref == '(CustomerInvoicePayment)' || $ref == '(CustomerInvoicePaymentBack)') {
			$ref = $langs->transnoentitiesnoconv('Customer');
		}
		if ($ref == '(SocialContributionPayment)') {
			$ref = $langs->transnoentitiesnoconv('SocialContribution');
		}
		if ($ref == '(DonationPayment)') {
			$ref = $langs->transnoentitiesnoconv('Donation');
		}
		if ($ref == '(SubscriptionPayment)') {
			$ref = $langs->transnoentitiesnoconv('Subscription');
		}
		if ($ref == '(ExpenseReportPayment)') {
			$ref = $langs->transnoentitiesnoconv('Employee');
		}
		if ($ref == '(LoanPayment)') {
			$ref = $langs->transnoentitiesnoconv('Loan');
		}
		if ($ref == '(payment_salary)') {
			$ref = $langs->transnoentitiesnoconv('Employee');
		}

		$sqlmid = '';
		if ($typerecord == 'payment') {
			if (getDolGlobalInt('FACTURE_DEPOSITS_ARE_JUST_PAYMENTS')) {
				$sqlmid = "SELECT payfac.fk_facture as id, ".$this->db->ifsql('f1.rowid IS NULL', 'f.ref', 'f1.ref')." as ref";
				$sqlmid .= " FROM ".$this->db->prefix()."paiement_facture as payfac";
				$sqlmid .= " LEFT JOIN ".$this->db->prefix()."facture as f ON f.rowid = payfac.fk_facture";
				$sqlmid .= " LEFT JOIN ".$this->db->prefix()."societe_remise_except as sre ON sre.fk_facture_source = payfac.fk_facture";
				$sqlmid .= " LEFT JOIN ".$this->db->prefix()."facture as f1 ON f1.rowid = sre.fk_facture";
				$sqlmid .= " WHERE payfac.fk_paiement=".((int) $val['paymentid']);
			} else {
				$sqlmid = "SELECT payfac.fk_facture as id, f.ref as ref";
				$sqlmid .= " FROM ".$this->db->prefix()."paiement_facture as payfac";
				$sqlmid .= " INNER JOIN ".$this->db->prefix()."facture as f ON f.rowid = payfac.fk_facture";
				$sqlmid .= " WHERE payfac.fk_paiement=".((int) $val['paymentid']);
			}
			$ref = $langs->transnoentitiesnoconv("Invoice");
		} elseif ($typerecord == 'payment_supplier') {
			$sqlmid = 'SELECT payfac.fk_facturefourn as id, f.ref';
			$sqlmid .= " FROM ".MAIN_DB_PREFIX."paiementfourn_facturefourn as payfac, ".MAIN_DB_PREFIX."facture_fourn as f";
			$sqlmid .= " WHERE payfac.fk_facturefourn = f.rowid AND payfac.fk_paiementfourn=".((int) $val["paymentsupplierid"]);
			$ref = $langs->transnoentitiesnoconv("SupplierInvoice");
		} elseif ($typerecord == 'payment_expensereport') {
			$sqlmid = 'SELECT e.rowid as id, e.ref';
			$sqlmid .= " FROM ".MAIN_DB_PREFIX."payment_expensereport as pe, ".MAIN_DB_PREFIX."expensereport as e";
			$sqlmid .= " WHERE pe.rowid=".((int) $val["paymentexpensereport"])." AND pe.fk_expensereport = e.rowid";
			$ref = $langs->transnoentitiesnoconv("ExpenseReport");
		} elseif ($typerecord == 'payment_salary') {
			$sqlmid = 'SELECT s.rowid as ref';
			$sqlmid .= " FROM ".MAIN_DB_PREFIX."payment_salary as s";
			$sqlmid .= " WHERE s.rowid=".((int) $val["paymentsalid"]);
			$ref = $langs->transnoentitiesnoconv("SalaryPayment");
		} elseif ($typerecord == 'sc') {
			$sqlmid = 'SELECT sc.rowid as ref';
			$sqlmid .= " FROM ".MAIN_DB_PREFIX."paiementcharge as sc";
			$sqlmid .= " WHERE sc.rowid=".((int) $val["paymentscid"]);
			$ref = $langs->transnoentitiesnoconv("SocialContribution");
		} elseif ($typerecord == 'payment_vat') {
			$sqlmid = 'SELECT v.rowid as ref';
			$sqlmid .= " FROM ".MAIN_DB_PREFIX."tva as v";
			$sqlmid .= " WHERE v.rowid=".((int) $val["paymentvatid"]);
			$ref = $langs->transnoentitiesnoconv("PaymentVat");
		} elseif ($typerecord == 'payment_donation') {
			$sqlmid = 'SELECT payd.fk_donation as ref';
			$sqlmid .= " FROM ".MAIN_DB_PREFIX."payment_donation as payd";
			$sqlmid .= " WHERE payd.fk_donation=".((int) $val["paymentdonationid"]);
			$ref = $langs->transnoentitiesnoconv("Donation");
		} elseif ($typerecord == 'payment_loan') {
			$sqlmid = 'SELECT l.rowid as ref';
			$sqlmid .= " FROM ".MAIN_DB_PREFIX."payment_loan as l";
			$sqlmid .= " WHERE l.rowid=".((int) $val["paymentloanid"]);
			$ref = $langs->transnoentitiesnoconv("LoanPayment");
		} elseif ($typerecord == 'payment_various') {
			$sqlmid = 'SELECT v.rowid as ref';
			$sqlmid .= " FROM ".MAIN_DB_PREFIX."payment_various as v";
			$sqlmid .= " WHERE v.rowid=".((int) $val["paymentvariousid"]);
			$ref = $langs->transnoentitiesnoconv("VariousPayment");
		}
		// Add warning
		if (empty($sqlmid)) {
			dol_syslog("Found a typerecord=".$typerecord." not supported", LOG_WARNING);
		}

		if ($sqlmid) {
			dol_syslog("accountancy/journal/bankjournal.php::sqlmid=".$sqlmid, LOG_DEBUG);
			$resultmid = $this->db->query($sqlmid);
			if ($resultmid) {
				while ($objmid = $this->db->fetch_object($resultmid)) {
					$ref .= ' '.$objmid->ref;
				}
			} else {
				dol_print_error($this->db);
			}
		}

		$ref = dol_trunc($langs->transnoentitiesnoconv("BankId").' '.$val['fk_bank'].' - '.$ref, 295); // 295 + 3 dots (...) is < than max size of 300
		return $ref;
	}

	/**
	 * Collect bank journal (nature=4) data pending transfer to the bookkeeping.
	 * Pure mechanical lift of accountancy/journal/bankjournal.php's former inline
	 * data-collection block. No hook is fired here - the original block had none.
	 * Must be called on an instance already fetch()ed with the target journal id.
	 *
	 * @param	User	$user				Unused directly here, kept for signature symmetry with writeIntoBookkeepingForBank()
	 * @param	int		$date_start			Start date (timestamp)
	 * @param	int		$date_end			End date (timestamp)
	 * @param	string	$in_bookkeeping		Filter on already/notyet dispatched bank lines ('notyet' by default)
	 * @param	int		$only_rappro		Filter on reconciliation status (2 = only reconciled lines, 0 = no filter)
	 * @return	array{tabpay:array<int,array{date:int,type_payment:string,ref:string,fk_bank:int,bank_account_ref:string,fk_bank_account:int,lib:string,type:string,paymentid?:int,paymentsupplierid?:int,soclib?:string,paymentscid?:int,paymentdonationid?:int,paymentsubscriptionid?:int,paymentvatid?:int,paymentsalid?:int,paymentexpensereport?:int,paymentvariousid?:int,account_various?:string,paymentloanid?:int}>,tabbq:array<int,array<string,float>>,tabtp:array<int,array<string,float>>,tabcompany:array<int,array{id:int,name:string,code_compta:string,accountancy_code_general:string,email:string}>,tabuser:array<int,array{id:int,name:string,lastname:string,firstname:string,email:string,accountancy_code_general:string,accountancy_code:string,status:int}>,tabtype:array<int,string>,tabmoreinfo:array<int,array<string,int>>,account_supplier:string,account_customer:string,account_employee:string,account_transfer:string}
	 */
	public function getDataForBank(User $user, $date_start, $date_end, $in_bookkeeping = 'notyet', $only_rappro = 0)
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/chargesociales.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
		require_once DOL_DOCUMENT_ROOT.'/don/class/paymentdonation.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/tva.class.php';
		require_once DOL_DOCUMENT_ROOT.'/salaries/class/paymentsalary.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
		require_once DOL_DOCUMENT_ROOT.'/expensereport/class/paymentexpensereport.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		require_once DOL_DOCUMENT_ROOT.'/loan/class/paymentloan.class.php';
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/subscription.class.php';

		// Get all bank lines
		//-------------------------------------
		$sql  = "SELECT b.rowid, b.dateo as do, b.datev as dv, b.amount, b.amount_main_currency, b.label, b.rappro, b.num_releve, b.num_chq, b.fk_type, b.fk_account,";
		$sql .= " ba.courant, ba.ref as baref, ba.account_number, ba.fk_accountancy_journal,";
		$sql .= " soc.rowid as socid, soc.nom as name, soc.email as email, bu1.type as typeop_company,";
		if (getDolGlobalString('MAIN_COMPANY_PERENTITY_SHARED')) {
			$sql .= " spe.accountancy_code_customer_general,";
			$sql .= " spe.accountancy_code_customer as code_compta_client,";
			$sql .= " spe.accountancy_code_supplier_general,";
			$sql .= " spe.accountancy_code_supplier as code_compta_fournisseur,";
		} else {
			$sql .= " soc.accountancy_code_customer_general,";
			$sql .= " soc.code_compta as code_compta_client,";
			$sql .= " soc.accountancy_code_supplier_general,";
			$sql .= " soc.code_compta_fournisseur,";
		}
		$sql .= " u.accountancy_code_user_general, u.accountancy_code, u.rowid as userid, u.lastname as lastname, u.firstname as firstname, u.email as useremail, u.statut as userstatus,";
		$sql .= " bu2.type as typeop_user,";
		$sql .= " bu3.type as typeop_payment, bu4.type as typeop_payment_supplier";
		$sql .= " FROM ".$this->db->prefix()."bank as b";
		$sql .= " JOIN ".$this->db->prefix()."bank_account as ba on b.fk_account = ba.rowid";
		$sql .= " LEFT JOIN ".$this->db->prefix()."bank_url as bu1 ON bu1.fk_bank = b.rowid AND bu1.type='company'";
		$sql .= " LEFT JOIN ".$this->db->prefix()."bank_url as bu2 ON bu2.fk_bank = b.rowid AND bu2.type='user'";
		$sql .= " LEFT JOIN ".$this->db->prefix()."bank_url as bu3 ON bu3.fk_bank = b.rowid AND bu3.type='payment'";
		$sql .= " LEFT JOIN ".$this->db->prefix()."bank_url as bu4 ON bu4.fk_bank = b.rowid AND bu4.type='payment_supplier'";
		$sql .= " LEFT JOIN ".$this->db->prefix()."societe as soc on bu1.url_id=soc.rowid";
		if (getDolGlobalString('MAIN_COMPANY_PERENTITY_SHARED')) {
			$sql .= " LEFT JOIN " . $this->db->prefix() . "societe_perentity as spe ON spe.fk_soc = soc.rowid AND spe.entity = " . ((int) $conf->entity);
		}
		$sql .= " LEFT JOIN ".$this->db->prefix()."user as u on bu2.url_id=u.rowid";
		$sql .= " WHERE ba.fk_accountancy_journal=".((int) $this->id);
		$sql .= " AND b.amount <> 0 AND ba.entity IN (".getEntity('bank_account', 0).")"; // We don't share object for accountancy
		if ($date_start && $date_end) {
			$sql .= " AND b.dateo >= '".$this->db->idate($date_start)."' AND b.dateo <= '".$this->db->idate($date_end)."'";
		}
		// Define begin binding date
		if (getDolGlobalInt('ACCOUNTING_DATE_START_BINDING')) {
			$sql .= " AND b.dateo >= '".$this->db->idate(getDolGlobalInt('ACCOUNTING_DATE_START_BINDING'))."'";
		}
		// Already in bookkeeping or not
		if ($in_bookkeeping == 'already') {
			$sql .= " AND (b.rowid IN (SELECT fk_doc FROM ".$this->db->prefix()."accounting_bookkeeping as ab  WHERE ab.doc_type='bank') )";
		}
		if ($in_bookkeeping == 'notyet') {
			$sql .= " AND (b.rowid NOT IN (SELECT fk_doc FROM ".$this->db->prefix()."accounting_bookkeeping as ab  WHERE ab.doc_type='bank') )";
		}
		if ($only_rappro == 2) {
			$sql .= " AND (b.rappro = '1')";
		}
		$sql .= " ORDER BY b.datev";
		//print $sql;

		// Preload payment account codes by payment type from c_paiement
		$accountancy_code_by_payment = array();
		$sql2 = "SELECT code, accountancy_code";
		$sql2 .= " FROM ".MAIN_DB_PREFIX."c_paiement";
		$sql2 .= " WHERE entity IN (".getEntity('c_paiement').")";
		$sql2 .= " AND active = 1";
		$resql = $this->db->query($sql2);
		if ($resql) {
			while ($objp = $this->db->fetch_object($resql)) {
				if (!empty($objp->code) && !empty($objp->accountancy_code)) {
					$accountancy_code_by_payment[$objp->code] = $objp->accountancy_code;
				}
			}
		}

		$object = new Account($this->db);
		$paymentstatic = new Paiement($this->db);
		$paymentsupplierstatic = new PaiementFourn($this->db);
		$societestatic = new Societe($this->db);
		$userstatic = new User($this->db);
		$bankaccountstatic = new Account($this->db);
		$chargestatic = new ChargeSociales($this->db);
		$paymentdonstatic = new PaymentDonation($this->db);
		$paymentvatstatic = new Tva($this->db);
		$paymentsalstatic = new PaymentSalary($this->db);
		$paymentexpensereportstatic = new PaymentExpenseReport($this->db);
		$paymentvariousstatic = new PaymentVarious($this->db);
		$paymentloanstatic = new PaymentLoan($this->db);
		$accountLinestatic = new AccountLine($this->db);
		$paymentsubscriptionstatic = new Subscription($this->db);

		$tmppayment = new Paiement($this->db);
		$tmpinvoice = new Facture($this->db);

		$accountingaccount = new AccountingAccount($this->db);
		$account_transfer = 'NotDefined'; // For static analysis, NotDefined is a reserved word


		$tabcompany = array();
		$tabuser = array();
		$tabpay = array();
		$tabbq = array();
		$tabtp = array();
		$tabtype = array();
		$tabmoreinfo = array();

		'
		@phan-var-force array<array{id:mixed,name:mixed,code_compta_client:string,email:string}> $tabcompany
		@phan-var-force array<array{id:int,name:string,lastname:string,firstname:string,email:string,accountancy_code:string,status:int> $tabuser
		@phan-var-force array<int,array{date:string,type_payment:string,ref:string,fk_bank:int,ban_account_ref:string,fk_bank_account:int,lib:string,type:string}> $tabpay
		@phan-var-force array<array{lib:string,date?:int|string,type_payment?:string,ref?:string,fk_bank?:int,ban_account_ref?:string,fk_bank_account?:int,type?:string,bank_account_ref?:string,paymentid?:int,paymentsupplierid?:int,soclib?:string,paymentscid?:int,paymentdonationid?:int,paymentsubscriptionid?:int,paymentvatid?:int,paymentsalid?:int,paymentexpensereport?:int,paymentvariousid?:int,account_various?:string,paymentloanid?:int}> $tabtp
		';

		$account_customer = 'NotDefined';
		$account_supplier = 'NotDefined';
		$account_employee = 'NotDefined';

		//print $sql;
		dol_syslog("accountancy/journal/bankjournal.php", LOG_DEBUG);
		$result = $this->db->query($sql);
		if ($result) {
			$num = $this->db->num_rows($result);
			//print $sql;

			// Variables
			$account_supplier = getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER', 'NotDefined'); // NotDefined is a reserved word
			$account_customer = getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER', 'NotDefined'); // NotDefined is a reserved word
			$account_employee = getDolGlobalString('SALARIES_ACCOUNTING_ACCOUNT_PAYMENT', 'NotDefined'); // NotDefined is a reserved word
			$account_expensereport = getDolGlobalString('ACCOUNTING_ACCOUNT_EXPENSEREPORT', 'NotDefined'); // NotDefined is a reserved word
			$account_pay_vat = getDolGlobalString('ACCOUNTING_VAT_PAY_ACCOUNT', 'NotDefined'); // NotDefined is a reserved word
			$account_pay_donation = getDolGlobalString('DONATION_ACCOUNTINGACCOUNT', 'NotDefined'); // NotDefined is a reserved word
			$account_pay_subscription = getDolGlobalString('ADHERENT_SUBSCRIPTION_ACCOUNTINGACCOUNT', 'NotDefined'); // NotDefined is a reserved word
			$account_transfer = getDolGlobalString('ACCOUNTING_ACCOUNT_TRANSFER_CASH', 'NotDefined'); // NotDefined is a reserved word

			// Loop on each line into the llx_bank table. For each line, we should get:
			// one line tabpay = line into bank
			// one line for bank record = tabbq
			// one line for thirdparty record = tabtp
			// Note: tabcompany is used to store the subledger account
			$i = 0;
			while ($i < $num) {
				$obj = $this->db->fetch_object($result);

				$lineisapurchase = -1;
				$lineisasale = -1;
				// Old method to detect if it's a sale or purchase
				if ($obj->label == '(SupplierInvoicePayment)' || $obj->label == '(SupplierInvoicePaymentBack)') {
					$lineisapurchase = 1;
				}
				if ($obj->label == '(CustomerInvoicePayment)' || $obj->label == '(CustomerInvoicePaymentBack)') {
					$lineisasale = 1;
				}
				// Try a more reliable method to detect if record is a supplier payment or a customer payment
				if ($lineisapurchase < 0) {
					if ($obj->typeop_payment_supplier == 'payment_supplier') {
						$lineisapurchase = 1;
					}
				}
				if ($lineisasale < 0) {
					if ($obj->typeop_payment == 'payment') {
						$lineisasale = 1;
					}
				}
				//var_dump($obj->type_payment); //var_dump($obj->type_payment_supplier);
				//var_dump($lineisapurchase); //var_dump($lineisasale);

				// Set accountancy code for bank
				$compta_bank = $obj->account_number;

				// Determining the bank account by payment method
				if (!empty($obj->fk_type) && !empty($accountancy_code_by_payment[$obj->fk_type])) {
					$compta_bank = $accountancy_code_by_payment[$obj->fk_type];
				}

				// Set accountancy code for thirdparty (example: '411CU...' or '411' if no subledger account defined on customer)
				$compta_soc = 'NotDefined';
				$accountancy_code_general = 'NotDefined';
				if ($lineisapurchase > 0) {
					$accountancy_code_general = (!empty($obj->accountancy_code_supplier_general) && $obj->accountancy_code_supplier_general != '-1') ? $obj->accountancy_code_supplier_general : $account_supplier;
					$compta_soc = (($obj->code_compta_fournisseur != "") ? $obj->code_compta_fournisseur : $account_supplier);
				}
				if ($lineisasale > 0) {
					$accountancy_code_general = (!empty($obj->accountancy_code_customer_general) && $obj->accountancy_code_customer_general != '-1') ? $obj->accountancy_code_customer_general : $account_customer;
					$compta_soc = (!empty($obj->code_compta_client) ? $obj->code_compta_client : $account_customer);
				}

				$tabcompany[$obj->rowid] = array(
					'id' => $obj->socid,
					'name' => $obj->name,
					'code_compta' => $compta_soc,
					'accountancy_code_general' => $accountancy_code_general,
					'email' => $obj->email
				);

				// Set accountancy code for user
				// $obj->accountancy_code is the accountancy_code of table u=user (but it is defined only if
				// a link with type 'user' exists and user as a subledger account)
				$accountancy_code_user_general = (!empty($obj->accountancy_code_user_general)) ? $obj->accountancy_code_user_general : $account_employee;
				$compta_user = (!empty($obj->accountancy_code) ? $obj->accountancy_code : '');

				$tabuser[$obj->rowid] = array(
					'id' => $obj->userid,
					'name' => dolGetFirstLastname($obj->firstname, $obj->lastname),
					'lastname' => $obj->lastname,
					'firstname' => $obj->firstname,
					'email' => $obj->useremail,
					'accountancy_code_general' => $accountancy_code_user_general,
					'accountancy_code' => $compta_user,
					'status' => $obj->userstatus
				);

				// Variable bookkeeping ($obj->rowid is Bank Id)
				$tabpay[$obj->rowid]["date"] = $this->db->jdate($obj->do);
				$tabpay[$obj->rowid]["type_payment"] = $obj->fk_type; // CHQ, VIR, LIQ, CB, ...
				$tabpay[$obj->rowid]["ref"] = $obj->label; // By default. Not unique. May be changed later
				$tabpay[$obj->rowid]["fk_bank"] = $obj->rowid;
				$tabpay[$obj->rowid]["bank_account_ref"] = $obj->baref;
				$tabpay[$obj->rowid]["fk_bank_account"] = $obj->fk_account;
				$reg = array();
				if (preg_match('/^\((.*)\)$/i', $obj->label, $reg)) {
					$tabpay[$obj->rowid]["lib"] = $langs->trans($reg[1]);
				} else {
					$tabpay[$obj->rowid]["lib"] = dol_trunc($obj->label, 60);
				}

				// Load of url links to the line into llx_bank (so load llx_bank_url)
				$links = $object->get_url($obj->rowid); // Get an array('url'=>, 'url_id'=>, 'label'=>, 'type'=> 'fk_bank'=> )
				// print '<p>' . json_encode($object) . "</p>";//exit;
				// print '<p>' . json_encode($links) . "</p>";//exit;

				// By default
				$tabpay[$obj->rowid]['type'] = 'unknown'; // Can be SOLD, miscellaneous entry, payment of patient, or any old record with no links in bank_url.
				$tabtype[$obj->rowid] = 'unknown';
				$tabmoreinfo[$obj->rowid] = array();

				$amounttouse = $obj->amount;
				if (!empty($obj->amount_main_currency)) {
					// If $obj->amount_main_currency is set, it means that $obj->amount is not in same currency, we must use $obj->amount_main_currency
					$amounttouse = $obj->amount_main_currency;
				}

				// in case option FACTURE_PAYMENTS_ON_DIFFERENT_THIRDPARTIES_BILLS is on, payment could be for more than one third-partie
				// so we have to find which part of the payment is affected to each third-parties
				// (because in this case $obj-amount = the total of the paiement and not the paiement for each third-parties)
				if (getDolGlobalString('FACTURE_PAYMENTS_ON_DIFFERENT_THIRDPARTIES_BILLS') && ($lineisapurchase == 1 || $lineisasale == 1) ) {
					if ($lineisapurchase == 1) {
						$sqlamount = "SELECT SUM(pf.amount) as amount";
						$sqlamount .= " FROM ".MAIN_DB_PREFIX."paiementfourn_facturefourn AS pf";
						$sqlamount .= " INNER JOIN ".MAIN_DB_PREFIX."paiementfourn AS p ON pf.fk_paiementfourn = p.rowid";
						$sqlamount .= " RIGHT JOIN ".MAIN_DB_PREFIX."facture AS f ON pf.fk_facturefourn = f.rowid";
						$sqlamount .= " WHERE p.fk_bank = ".((int) $obj->rowid);
						$sqlamount .= " AND f.fk_soc = ".((int) $obj->socid);
					} else {
						$sqlamount = "SELECT SUM(pf.amount) as amount";
						$sqlamount .= " FROM ".MAIN_DB_PREFIX."paiement_facture AS pf";
						$sqlamount .= " INNER JOIN ".MAIN_DB_PREFIX."paiement AS p ON pf.fk_paiement = p.rowid";
						$sqlamount .= " RIGHT JOIN ".MAIN_DB_PREFIX."facture AS f ON pf.fk_facture = f.rowid";
						$sqlamount .= " WHERE p.fk_bank = ".((int) $obj->rowid);
						$sqlamount .= " AND f.fk_soc = ".((int) $obj->socid);
					}
					$resultamount = $this->db->query($sqlamount);
					if ($resultamount) {
						$objamount = $this->db->fetch_object($resultamount);
						if (!empty($objamount->amount)) $amounttouse = $objamount->amount;
					}
				}

				// get_url may return -1 which is not traversable
				if (is_array($links) && count($links) > 0) {
					// Test if entry is for a social contribution, salary or expense report.
					// In such a case, we will ignore the bank url line for user
					$is_sc = false;
					$is_salary = false;
					$is_expensereport = false;
					foreach ($links as $v) {
						if ($v['type'] == 'sc') {
							$is_sc = true;
							break;
						}
						if ($v['type'] == 'payment_salary') {
							$is_salary = true;
							break;
						}
						if ($v['type'] == 'payment_expensereport') {
							$is_expensereport = true;
							break;
						}
					}

					// Now loop on each link of record in bank (code similar to bankentries_list.php)
					foreach ($links as $key => $val) {
						if ($links[$key]['type'] == 'user' && !$is_sc && !$is_salary && !$is_expensereport) {
							// We must avoid as much as possible this "continue". If we want to jump to next loop, it means we don't want to process
							// the case the link is user (often because managed by hard coded code into another link), and we must avoid this.
							continue;
						}
						if (in_array($links[$key]['type'], array('sc', 'payment_sc', 'payment', 'payment_supplier', 'payment_vat', 'payment_expensereport', 'banktransfert', 'payment_donation', 'member', 'payment_loan', 'payment_salary', 'payment_various'))) {
							// So we excluded 'company' and 'user' here. We want only payment lines

							// We save tabtype for a future use, to remember what kind of payment it is
							$tabpay[$obj->rowid]['type'] = $links[$key]['type'];
							$tabtype[$obj->rowid] = $links[$key]['type'];
							/* phpcs:disable -- Code does nothing at this moment -> commented
							} elseif (in_array($links[$key]['type'], array('company', 'user'))) {
								if ($tabpay[$obj->rowid]['type'] == 'unknown') {
									// We can guess here it is a bank record for a thirdparty company or a user.
									// But we won't be able to record somewhere else than into a waiting account, because there is no other journal to record the contreparty.
								}
							*/ // phpcs::enable
						}

						// Special case to ask later to add more request to get information for old links without company link.
						if ($links[$key]['type'] == 'withdraw') {
							$tabmoreinfo[$obj->rowid]['withdraw'] = 1;
						}

						if ($links[$key]['type'] == 'payment') {
							$paymentstatic->id = $links[$key]['url_id'];
							$paymentstatic->ref = (string) $links[$key]['url_id'];
							$tabpay[$obj->rowid]["lib"] .= ' '.$paymentstatic->getNomUrl(2, '', ''); // TODO Do not include list of invoice in tooltip, the dol_string_nohtmltag is ko with this
							$tabpay[$obj->rowid]["paymentid"] = $paymentstatic->id;
						} elseif ($links[$key]['type'] == 'payment_supplier') {
							$paymentsupplierstatic->id = $links[$key]['url_id'];
							$paymentsupplierstatic->ref = (string) $links[$key]['url_id'];
							$tabpay[$obj->rowid]["lib"] .= ' '.$paymentsupplierstatic->getNomUrl(2);
							$tabpay[$obj->rowid]["paymentsupplierid"] = $paymentsupplierstatic->id;
						} elseif ($links[$key]['type'] == 'company') {
							$societestatic->id = $links[$key]['url_id'];
							$societestatic->name = $links[$key]['label'];
							$societestatic->email = $tabcompany[$obj->rowid]['email'];
							$tabpay[$obj->rowid]["soclib"] = $societestatic->getNomUrl(1, '', 30);
							if ($compta_soc) {
								// because we are in 2 loop (loop on the line from the sql queries and loop on $links)
								// and in case of option FACTURE_PAYMENTS_ON_DIFFERENT_THIRDPARTIES_BILLS is on,
								// we will pass here n times for each payment line
								// so we have to add $amoutouse only if the line $links[$key] correspond to the payment line we are in used ( socid correspondinf at the payment line $links)
								// if FACTURE_PAYMENTS_ON_DIFFERENT_THIRDPARTIES_BILLS is off we add $amounttouse
								if (!getDolGlobalString('FACTURE_PAYMENTS_ON_DIFFERENT_THIRDPARTIES_BILLS') || $obj->socid == $links[$key]['url_id']){
									if (empty($tabtp[$obj->rowid][$compta_soc])) {
										$tabtp[$obj->rowid][$compta_soc] = $amounttouse;
									} else {
										$tabtp[$obj->rowid][$compta_soc] += $amounttouse;
									}
								}
							}
						} elseif ($links[$key]['type'] == 'user') {
							$userstatic->id = $links[$key]['url_id'];
							$userstatic->name = $links[$key]['label'];
							$userstatic->email = $tabuser[$obj->rowid]['email'];
							$userstatic->firstname = $tabuser[$obj->rowid]['firstname'];
							$userstatic->lastname = $tabuser[$obj->rowid]['lastname'];
							$userstatic->status = $tabuser[$obj->rowid]['status'];
							$userstatic->accountancy_code_user_general = $tabuser[$obj->rowid]['accountancy_code_general'];
							$userstatic->accountancy_code = $tabuser[$obj->rowid]['accountancy_code'];

							// For a payment of social contribution, we have a link sc + user.
							// but we already fill the $tabpay[$obj->rowid]["soclib"] in the line 'sc'.
							// If we fill it here to, we must concat.
							if ($userstatic->id > 0) {
								if ($is_sc) {
									$tabpay[$obj->rowid]["soclib"] .= ' '.$userstatic->getNomUrl(-1, 'accountancy', 0);
								} else {
									$tabpay[$obj->rowid]["soclib"] = $userstatic->getNomUrl(-1, 'accountancy', 0);
								}
							} else {
								$tabpay[$obj->rowid]["soclib"] = '???'; // Should not happen, but happens with old data when id of user was not saved on expense report payment.
							}

							if ($compta_user) {
								if ($is_sc) {
									//$tabcompany[$obj->rowid][$compta_user] += $amounttouse;
								} else {
									$tabtp[$obj->rowid][$compta_user] += $amounttouse;
								}
							}
						} elseif ($links[$key]['type'] == 'sc') {
							$chargestatic->id = $links[$key]['url_id'];
							$chargestatic->ref = (string) $links[$key]['url_id'];

							$tabpay[$obj->rowid]["lib"] .= ' '.$chargestatic->getNomUrl(2);
							$reg = array();
							if (preg_match('/^\((.*)\)$/i', $links[$key]['label'], $reg)) {
								if ($reg[1] == 'socialcontribution') {
									$reg[1] = 'SocialContribution';
								}
								$chargestatic->label = $langs->trans($reg[1]);
							} else {
								$chargestatic->label = $links[$key]['label'];
							}
							$chargestatic->ref = $chargestatic->label;

							// Retrieve the accounting code of the social contribution of the payment from link of payment.
							// Note: We have the social contribution id, it can be faster to get accounting code from social contribution id.
							/*
							$sqlmid = "SELECT cchgsoc.accountancy_code";
							$sqlmid .= " FROM ".MAIN_DB_PREFIX."c_chargesociales cchgsoc";
							$sqlmid .= " INNER JOIN ".MAIN_DB_PREFIX."chargesociales as chgsoc ON chgsoc.fk_type = cchgsoc.id";
							$sqlmid .= " INNER JOIN ".MAIN_DB_PREFIX."paiementcharge as paycharg ON paycharg.fk_charge = chgsoc.rowid";
							$sqlmid .= " INNER JOIN ".MAIN_DB_PREFIX."bank_url as bkurl ON bkurl.url_id=paycharg.rowid AND bkurl.type = 'payment_sc'";
							$sqlmid .= " WHERE bkurl.fk_bank = ".((int) $obj->rowid);

							dol_syslog("accountancy/journal/bankjournal.php:: sqlmid=".$sqlmid, LOG_DEBUG);
							$resultmid = $this->db->query($sqlmid);
							if ($resultmid) {
								$objmid = $this->db->fetch_object($resultmid);
								$tabtp[$obj->rowid][$objmid->accountancy_code] = isset($tabtp[$obj->rowid][$objmid->accountancy_code]) ? $tabtp[$obj->rowid][$objmid->accountancy_code] + $amounttouse : $amounttouse;
							}*/
							$tmpcharge = new ChargeSociales($this->db);
							$resultmid = $tmpcharge->fetch($chargestatic->id);
							if ($resultmid) {
								$chargestatic->type_label = $tmpcharge->type_label;
								$chargestatic->type_code = $tmpcharge->type_code;
								$chargestatic->type_accountancy_code = $tmpcharge->type_accountancy_code;

								$tabtp[$obj->rowid][$tmpcharge->type_accountancy_code] = isset($tabtp[$obj->rowid][$tmpcharge->type_accountancy_code]) ? $tabtp[$obj->rowid][$tmpcharge->type_accountancy_code] + $amounttouse : $amounttouse;
							}

							$tabpay[$obj->rowid]["soclib"] = $chargestatic->getNomUrl(1, '30');
							$tabpay[$obj->rowid]["paymentscid"] = $chargestatic->id;
						} elseif ($links[$key]['type'] == 'payment_donation') {
							$paymentdonstatic->id = $links[$key]['url_id'];
							$paymentdonstatic->ref = (string) $links[$key]['url_id'];
							$paymentdonstatic->fk_donation = $links[$key]['url_id'];
							$tabpay[$obj->rowid]["lib"] .= ' '.$paymentdonstatic->getNomUrl(2);
							$tabpay[$obj->rowid]["paymentdonationid"] = $paymentdonstatic->id;
							$tabtp[$obj->rowid][$account_pay_donation] = isset($tabtp[$obj->rowid][$account_pay_donation]) ? $tabtp[$obj->rowid][$account_pay_donation] + $amounttouse : $amounttouse;
						} elseif ($links[$key]['type'] == 'member') {
							$paymentsubscriptionstatic->id = $links[$key]['url_id'];
							$paymentsubscriptionstatic->ref = (string) $links[$key]['url_id'];
							$paymentsubscriptionstatic->label = $links[$key]['label'];
							$tabpay[$obj->rowid]["lib"] .= ' '.$paymentsubscriptionstatic->getNomUrl(2);
							$tabpay[$obj->rowid]["paymentsubscriptionid"] = $paymentsubscriptionstatic->id;
							$paymentsubscriptionstatic->fetch($paymentsubscriptionstatic->id);
							$tabtp[$obj->rowid][$account_pay_subscription] = isset($tabtp[$obj->rowid][$account_pay_subscription]) ? $tabtp[$obj->rowid][$account_pay_subscription] + $amounttouse : $amounttouse;
						} elseif ($links[$key]['type'] == 'payment_vat') {				// Payment VAT
							$paymentvatstatic->id = $links[$key]['url_id'];
							$paymentvatstatic->ref = (string) $links[$key]['url_id'];
							$paymentvatstatic->label = $links[$key]['label'];
							$tabpay[$obj->rowid]["lib"] .= ' '.$paymentvatstatic->getNomUrl(2);
							$tabpay[$obj->rowid]["paymentvatid"] = $paymentvatstatic->id;
							$tabtp[$obj->rowid][$account_pay_vat] = isset($tabtp[$obj->rowid][$account_pay_vat]) ? $tabtp[$obj->rowid][$account_pay_vat] + $amounttouse : $amounttouse;
						} elseif ($links[$key]['type'] == 'payment_salary') {
							$paymentsalstatic->id = $links[$key]['url_id'];
							$paymentsalstatic->ref = (string) $links[$key]['url_id'];
							$paymentsalstatic->label = $links[$key]['label'];
							$tabpay[$obj->rowid]["lib"] .= ' '.$paymentsalstatic->getNomUrl(2);
							$tabpay[$obj->rowid]["paymentsalid"] = $paymentsalstatic->id;

							// This part of code is no more required. it is here to solve case where a link were missing (with v14.0.0) and keep writing in accountancy complete.
							// Note: A better way to fix this is to delete payment of salary and recreate it, or to fix the bookkeeping table manually after.
							if (getDolGlobalString('ACCOUNTANCY_AUTOFIX_MISSING_LINK_TO_USER_ON_SALARY_BANK_PAYMENT')) {
								$tmpsalary = new Salary($this->db);
								$tmpsalary->fetch($paymentsalstatic->id);
								$tmpsalary->fetch_user($tmpsalary->fk_user);

								$userstatic->id = $tmpsalary->user->id;
								$userstatic->name = $tmpsalary->user->name;
								$userstatic->email = $tmpsalary->user->email;
								$userstatic->firstname = $tmpsalary->user->firstname;
								$userstatic->lastname = $tmpsalary->user->lastname;
								$userstatic->status = $tmpsalary->user->status;
								$userstatic->accountancy_code = $tmpsalary->user->accountancy_code;

								if ($userstatic->id > 0) {
									$tabpay[$obj->rowid]["soclib"] = $userstatic->getNomUrl(1, 'accountancy', 0);
								} else {
									$tabpay[$obj->rowid]["soclib"] = '???'; // Should not happen
								}

								if (empty($obj->typeop_user)) {	// Add test to avoid adding amount twice if a link already exists also on user.
									$accountancy_code_user_general = (!empty($obj->accountancy_code_user_general)) ? $obj->accountancy_code_user_general : $account_employee;
									$compta_user = $userstatic->accountancy_code;
									if ($compta_user) {
										$tabtp[$obj->rowid][$compta_user] += $amounttouse;
										$tabuser[$obj->rowid] = array(
										'id' => $userstatic->id,
										'name' => dolGetFirstLastname($userstatic->firstname, $userstatic->lastname),
										'lastname' => $userstatic->lastname,
										'firstname' => $userstatic->firstname,
										'email' => $userstatic->email,
										'accountancy_code_general' => $accountancy_code_user_general,
										'accountancy_code' => $compta_user,
										'status' => $userstatic->status
										);
									}
								}
							}
						} elseif ($links[$key]['type'] == 'payment_expensereport') {
							$paymentexpensereportstatic->id = $links[$key]['url_id'];
							$tabpay[$obj->rowid]["lib"] .= $paymentexpensereportstatic->getNomUrl(2);
							$tabpay[$obj->rowid]["paymentexpensereport"] = $paymentexpensereportstatic->id;
						} elseif ($links[$key]['type'] == 'payment_various') {
							$paymentvariousstatic->id = $links[$key]['url_id'];
							$paymentvariousstatic->ref = (string) $links[$key]['url_id'];
							$paymentvariousstatic->label = $links[$key]['label'];
							$tabpay[$obj->rowid]["lib"] .= ' '.$paymentvariousstatic->getNomUrl(2);
							$tabpay[$obj->rowid]["paymentvariousid"] = $paymentvariousstatic->id;
							$paymentvariousstatic->fetch($paymentvariousstatic->id);
							$account_various = (!empty($paymentvariousstatic->accountancy_code) ? $paymentvariousstatic->accountancy_code : 'NotDefined'); // NotDefined is a reserved word
							$account_subledger = (!empty($paymentvariousstatic->subledger_account) ? $paymentvariousstatic->subledger_account : ''); // NotDefined is a reserved word
							$tabpay[$obj->rowid]["account_various"] = $account_various;
							$tabtp[$obj->rowid][$account_subledger] = isset($tabtp[$obj->rowid][$account_subledger]) ? $tabtp[$obj->rowid][$account_subledger] + $amounttouse : $amounttouse;
						} elseif ($links[$key]['type'] == 'payment_loan') {
							$paymentloanstatic->id = $links[$key]['url_id'];
							$paymentloanstatic->ref = (string) $links[$key]['url_id'];
							$paymentloanstatic->fk_loan = $links[$key]['url_id'];
							$tabpay[$obj->rowid]["lib"] .= ' '.$paymentloanstatic->getNomUrl(2);
							$tabpay[$obj->rowid]["paymentloanid"] = $paymentloanstatic->id;
							//$tabtp[$obj->rowid][$account_pay_loan] += $amounttouse;
							$sqlmid = 'SELECT pl.amount_capital, pl.amount_insurance, pl.amount_interest, l.accountancy_account_capital, l.accountancy_account_insurance, l.accountancy_account_interest';
							$sqlmid .= ' FROM '.MAIN_DB_PREFIX.'payment_loan as pl, '.MAIN_DB_PREFIX.'loan as l';
							$sqlmid .= ' WHERE l.rowid = pl.fk_loan AND pl.fk_bank = '.((int) $obj->rowid);

							dol_syslog("accountancy/journal/bankjournal.php:: sqlmid=".$sqlmid, LOG_DEBUG);
							$resultmid = $this->db->query($sqlmid);
							if ($resultmid) {
								$objmid = $this->db->fetch_object($resultmid);
								$tabtp[$obj->rowid][$objmid->accountancy_account_capital] = isset($objmid->amount_capital) ? ($tabtp[$obj->rowid][$objmid->accountancy_account_capital] ?? 0) - $objmid->amount_capital : 0;
								$tabtp[$obj->rowid][$objmid->accountancy_account_insurance] = isset($objmid->amount_insurance) ? ($tabtp[$obj->rowid][$objmid->accountancy_account_insurance] ?? 0) - $objmid->amount_insurance : 0;
								$tabtp[$obj->rowid][$objmid->accountancy_account_interest] = isset($objmid->amount_interest) ? ($tabtp[$obj->rowid][$objmid->accountancy_account_interest] ?? 0) - $objmid->amount_interest : 0;
							}
						} elseif ($links[$key]['type'] == 'banktransfert') {
							$accountLinestatic->fetch($links[$key]['url_id']);
							$tabpay[$obj->rowid]["lib"] .= ' '.$langs->trans("BankTransfer").' '.$accountLinestatic ->getNomUrl(1);
							$tabtp[$obj->rowid][$account_transfer] = isset($tabtp[$obj->rowid][$account_transfer]) ? $tabtp[$obj->rowid][$account_transfer] + $amounttouse : $amounttouse;
							$bankaccountstatic->fetch($tabpay[$obj->rowid]['fk_bank_account']);
							$tabpay[$obj->rowid]["soclib"] = $bankaccountstatic->getNomUrl(2);
						}
					}
				}

				if (empty($tabbq[$obj->rowid][$compta_bank])) {
					$tabbq[$obj->rowid][$compta_bank] = $amounttouse;
				} else {
					$tabbq[$obj->rowid][$compta_bank] += $amounttouse;
				}

				// If no links were found to know the amount on thirdparty, we try to guess it.
				// This may happen on bank entries without the links lines to 'company'.
				if (empty($tabtp[$obj->rowid]) && !empty($tabmoreinfo[$obj->rowid]['withdraw'])) {	// If we don't find 'company' link because it is an old 'withdraw' record
					foreach ($links as $key => $val) {
						if ($links[$key]['type'] == 'payment') {
							// Get thirdparty
							$tmppayment->fetch($links[$key]['url_id']);
							$arrayofamounts = $tmppayment->getAmountsArray();
							if (is_array($arrayofamounts)) {
								foreach ($arrayofamounts as $invoiceid => $amount) {
									$tmpinvoice->fetch($invoiceid);
									$tmpinvoice->fetch_thirdparty();
									if ($tmpinvoice->thirdparty->code_compta_client) {
										$tabtp[$obj->rowid][$tmpinvoice->thirdparty->code_compta_client] += $amount;
									}
								}
							}
						}
					}
				}

				// If no links were found to know the amount on thirdparty/user, we init it to account 'NotDefined'.
				if (empty($tabtp[$obj->rowid])) {
					$tabtp[$obj->rowid]['NotDefined'] = $tabbq[$obj->rowid][$compta_bank];
				}

				// if($obj->socid)$tabtp[$obj->rowid][$compta_soc] += $amounttouse;

				$i++;
			}
		} else {
			dol_print_error($this->db);
		}

		return array(
			'tabpay' => $tabpay,
			'tabbq' => $tabbq,
			'tabtp' => $tabtp,
			'tabcompany' => $tabcompany,
			'tabuser' => $tabuser,
			'tabtype' => $tabtype,
			'tabmoreinfo' => $tabmoreinfo,
			'account_supplier' => $account_supplier,
			'account_customer' => $account_customer,
			'account_employee' => $account_employee,
			'account_transfer' => $account_transfer,
		);
	}

	/**
	 * Write the bank journal (nature=4, non-RECETTES-DEPENSES accounting mode) into the
	 * bookkeeping. Pure mechanical lift of accountancy/journal/bankjournal.php's former
	 * inline writebookkeeping action block. No hook is fired here - the original block had
	 * none. Must be called on an instance already fetch()ed with the target journal id.
	 *
	 * @param	User	$user				User who write in the bookkeeping
	 * @param	int		$date_start			Start date (timestamp)
	 * @param	int		$date_end			End date (timestamp)
	 * @param	int		$max_nb_errors		Nb errors authorized before stopping the process (bank's original threshold is 5, stricter than other journals' 10)
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	public function writeIntoBookkeepingForBank(User $user, $date_start, $date_end, $max_nb_errors = 5)
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/bookkeeping.class.php';
		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';

		$data = $this->getDataForBank($user, $date_start, $date_end, 'notyet');
		$tabpay = $data['tabpay'];
		$tabbq = $data['tabbq'];
		$tabtp = $data['tabtp'];
		$tabcompany = $data['tabcompany'];
		$tabuser = $data['tabuser'];
		$tabtype = $data['tabtype'];
		$account_transfer = $data['account_transfer'];

		$journal = $this->code;
		$journal_label = $this->label;

		$now = dol_now();

		$accountingaccountcustomer = new AccountingAccount($this->db);
		$accountingaccountcustomer->fetch(0, getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER'), true);

		$accountingaccountsupplier = new AccountingAccount($this->db);
		$accountingaccountsupplier->fetch(0, getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER'), true);

		$accountingaccountpayment = new AccountingAccount($this->db);
		$accountingaccountpayment->fetch(0, getDolGlobalString('SALARIES_ACCOUNTING_ACCOUNT_PAYMENT'), true);

		$accountingaccountexpensereport = new AccountingAccount($this->db);
		$accountingaccountexpensereport->fetch(0, getDolGlobalString('ACCOUNTING_ACCOUNT_EXPENSEREPORT'), true);

		$accountingaccountsuspense = new AccountingAccount($this->db);
		$accountingaccountsuspense->fetch(0, getDolGlobalString('ACCOUNTING_ACCOUNT_SUSPENSE'), true);

		$error = 0;
		$this->errorforinvoicedetail = array();
		foreach ($tabpay as $key => $val) {		// $key is rowid into llx_bank
			$date = dol_print_date($val["date"], 'day');

			$ref = $this->getSourceDocRefForBank($val, $tabtype[$key]);

			$errorforline = 0;

			$totalcredit = 0;
			$totaldebit = 0;

			$this->db->begin();

			// Introduce a protection. Total of tabtp must be total of tabbq

			// Bank
			if (is_array($tabbq[$key])) {
				// Line into bank account
				foreach ($tabbq[$key] as $k => $mt) {
					if ($mt) {
						if (empty($conf->cache['accountingaccountincurrententity'][$k])) {
							$accountingaccount = new AccountingAccount($this->db);
							$accountingaccount->fetch(0, $k, true);	// $k is accounting account of the bank.
							$conf->cache['accountingaccountincurrententity'][$k] = $accountingaccount;
						} else {
							$accountingaccount = $conf->cache['accountingaccountincurrententity'][$k];
						}

						$account_label = $accountingaccount->label;

						$reflabel = '';
						if (!empty($val['lib'])) {
							$reflabel .= dol_string_nohtmltag($val['lib'])." / ";
						}
						$reflabel .= $langs->trans("Bank").' '.dol_string_nohtmltag($val['bank_account_ref']);
						if (!empty($val['soclib'])) {
							$reflabel .= " / ".dol_string_nohtmltag($val['soclib']);
						}

						$bookkeeping = new BookKeeping($this->db);
						$bookkeeping->doc_date = $val["date"];
						$bookkeeping->doc_ref = $ref;
						$bookkeeping->doc_type = 'bank';
						$bookkeeping->fk_doc = $key;
						$bookkeeping->fk_docdet = $val["fk_bank"];

						$bookkeeping->numero_compte = $k;
						$bookkeeping->label_compte = $account_label;

						$bookkeeping->label_operation = $reflabel;
						$bookkeeping->montant = $mt;
						$bookkeeping->sens = ($mt >= 0) ? 'D' : 'C';
						$bookkeeping->debit = ($mt >= 0 ? $mt : 0);
						$bookkeeping->credit = ($mt < 0 ? -$mt : 0);
						$bookkeeping->code_journal = $journal;
						$bookkeeping->journal_label = $langs->transnoentities($journal_label);
						$bookkeeping->fk_user_author = $user->id;
						$bookkeeping->date_creation = $now;

						// No subledger_account value for the bank line but add a specific label_operation
						$bookkeeping->subledger_account = '';
						$bookkeeping->label_operation = $reflabel;
						$bookkeeping->entity = $conf->entity;

						$totaldebit += $bookkeeping->debit;
						$totalcredit += $bookkeeping->credit;

						$result = $bookkeeping->create($user);
						if ($result < 0) {
							if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {	// Already exists
								$error++;
								$errorforline++;
								setEventMessages('Transaction for ('.$bookkeeping->doc_type.', '.$bookkeeping->fk_doc.', '.$bookkeeping->fk_docdet.') were already recorded', null, 'warnings');
								$this->errorforinvoicedetail[$key] = array(
									'ref' => (string) $ref,
									'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
								);
							} else {
								$error++;
								$errorforline++;
								setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
								$this->errorforinvoicedetail[$key] = array(
									'ref' => (string) $ref,
									'error' => $bookkeeping->errorsToString(),
								);
							}
						}
					}
				}
			}

			// Third party
			if (!$errorforline) {
				if (is_array($tabtp[$key])) {
					// Line into thirdparty account
					foreach ($tabtp[$key] as $k => $mt) {
						if ($mt) {
							$lettering = false;

							$reflabel = '';
							if (!empty($val['lib'])) {
								$reflabel .= dol_string_nohtmltag($val['lib']).(!empty($val['soclib']) ? " / " : "");
							}
							if ($tabtype[$key] == 'banktransfert') {
								$reflabel .= dol_string_nohtmltag($langs->transnoentitiesnoconv('TransitionalAccount').' '.$account_transfer);
							} else {
								$reflabel .= dol_string_nohtmltag($val['soclib'] ?? '');
							}

							$bookkeeping = new BookKeeping($this->db);
							$bookkeeping->doc_date = $val["date"];
							$bookkeeping->doc_ref = $ref;
							$bookkeeping->doc_type = 'bank';
							$bookkeeping->fk_doc = $key;
							$bookkeeping->fk_docdet = $val["fk_bank"];

							$bookkeeping->label_operation = $reflabel;
							$bookkeeping->montant = $mt;
							$bookkeeping->sens = ($mt < 0) ? 'D' : 'C';
							$bookkeeping->debit = ($mt < 0 ? -$mt : 0);
							$bookkeeping->credit = ($mt >= 0) ? $mt : 0;
							$bookkeeping->code_journal = $journal;
							$bookkeeping->journal_label = $langs->transnoentities($journal_label);
							$bookkeeping->fk_user_author = $user->id;
							$bookkeeping->date_creation = $now;

							if ($tabtype[$key] == 'payment') {	// If payment is payment of customer invoice, we get ref of invoice
								$lettering = true;
								$bookkeeping->subledger_account = $k; // For payment, the subledger account is stored as $key of $tabtp
								$bookkeeping->subledger_label = $tabcompany[$key]['name']; // $tabcompany is defined only if we are sure there is 1 thirdparty for the bank transaction
								$bookkeeping->numero_compte = $tabcompany[$key]['accountancy_code_general'];
								$bookkeeping->label_compte = $accountingaccountcustomer->label;
							} elseif ($tabtype[$key] == 'payment_supplier') {	// If payment is payment of supplier invoice, we get ref of invoice
								$lettering = true;
								$bookkeeping->subledger_account = $k; // For payment, the subledger account is stored as $key of $tabtp
								$bookkeeping->subledger_label = $tabcompany[$key]['name']; // $tabcompany is defined only if we are sure there is 1 thirdparty for the bank transaction
								$bookkeeping->numero_compte = $tabcompany[$key]['accountancy_code_general'];
								$bookkeeping->label_compte = $accountingaccountsupplier->label;
							} elseif ($tabtype[$key] == 'payment_expensereport') {
								$bookkeeping->subledger_account = $tabuser[$key]['accountancy_code'];
								$bookkeeping->subledger_label = $tabuser[$key]['name'];
								$bookkeeping->numero_compte = getDolGlobalString('ACCOUNTING_ACCOUNT_EXPENSEREPORT');
								$bookkeeping->label_compte = $accountingaccountexpensereport->label;
							} elseif ($tabtype[$key] == 'payment_salary') {
								$bookkeeping->subledger_account = $tabuser[$key]['accountancy_code'];
								$bookkeeping->subledger_label = $tabuser[$key]['name'];
								$bookkeeping->numero_compte = $tabuser[$key]['accountancy_code_general'];
								$bookkeeping->label_compte = $accountingaccountpayment->label;
							} elseif (in_array($tabtype[$key], array('sc', 'payment_sc'))) {   // If payment is payment of social contribution
								$bookkeeping->subledger_account = '';
								$bookkeeping->subledger_label = '';
								if (empty($conf->cache['accountingaccountincurrententity'][$k])) {
									$accountingaccount = new AccountingAccount($this->db);
									$accountingaccount->fetch(0, $k, true);
									$conf->cache['accountingaccountincurrententity'][$k] = $accountingaccount;
								} else {
									$accountingaccount = $conf->cache['accountingaccountincurrententity'][$k];
								}
								$bookkeeping->numero_compte = $k;
								$bookkeeping->label_compte = $accountingaccount->label;
							} elseif ($tabtype[$key] == 'payment_vat') {
								$bookkeeping->subledger_account = '';
								$bookkeeping->subledger_label = '';
								if (empty($conf->cache['accountingaccountincurrententity'][$k])) {
									$accountingaccount = new AccountingAccount($this->db);
									$accountingaccount->fetch(0, $k, true);
									$conf->cache['accountingaccountincurrententity'][$k] = $accountingaccount;
								} else {
									$accountingaccount = $conf->cache['accountingaccountincurrententity'][$k];
								}
								$bookkeeping->numero_compte = $k;
								$bookkeeping->label_compte = $accountingaccount->label;
							} elseif ($tabtype[$key] == 'payment_donation') {
								$bookkeeping->subledger_account = '';
								$bookkeeping->subledger_label = '';
								if (empty($conf->cache['accountingaccountincurrententity'][$k])) {
									$accountingaccount = new AccountingAccount($this->db);
									$accountingaccount->fetch(0, $k, true);
									$conf->cache['accountingaccountincurrententity'][$k] = $accountingaccount;
								} else {
									$accountingaccount = $conf->cache['accountingaccountincurrententity'][$k];
								}
								$bookkeeping->numero_compte = $k;
								$bookkeeping->label_compte = $accountingaccount->label;
							} elseif ($tabtype[$key] == 'member') {
								$bookkeeping->subledger_account = '';
								$bookkeeping->subledger_label = '';
								if (empty($conf->cache['accountingaccountincurrententity'][$k])) {
									$accountingaccount = new AccountingAccount($this->db);
									$accountingaccount->fetch(0, $k, true);
									$conf->cache['accountingaccountincurrententity'][$k] = $accountingaccount;
								} else {
									$accountingaccount = $conf->cache['accountingaccountincurrententity'][$k];
								}
								$bookkeeping->numero_compte = $k;
								$bookkeeping->label_compte = $accountingaccount->label;
							} elseif ($tabtype[$key] == 'payment_loan') {
								$bookkeeping->subledger_account = '';
								$bookkeeping->subledger_label = '';
								if (empty($conf->cache['accountingaccountincurrententity'][$k])) {
									$accountingaccount = new AccountingAccount($this->db);
									$accountingaccount->fetch(0, $k, true);
									$conf->cache['accountingaccountincurrententity'][$k] = $accountingaccount;
								} else {
									$accountingaccount = $conf->cache['accountingaccountincurrententity'][$k];
								}
								$bookkeeping->numero_compte = $k;
								$bookkeeping->label_compte = $accountingaccount->label;
							} elseif ($tabtype[$key] == 'payment_various') {
								$bookkeeping->subledger_account = $k;
								$bookkeeping->subledger_label = $tabcompany[$key]['name'];
								if (empty($conf->cache['accountingaccountincurrententity'][$tabpay[$key]["account_various"]])) {
									$accountingaccount = new AccountingAccount($this->db);
									$accountingaccount->fetch(0, $tabpay[$key]["account_various"], true);
									$conf->cache['accountingaccountincurrententity'][$tabpay[$key]["account_various"]] = $accountingaccount;
								} else {
									$accountingaccount = $conf->cache['accountingaccountincurrententity'][$tabpay[$key]["account_various"]];
								}
								$bookkeeping->numero_compte = $tabpay[$key]["account_various"];
								$bookkeeping->label_compte = $accountingaccount->label;
							} elseif ($tabtype[$key] == 'banktransfert') {
								$bookkeeping->subledger_account = '';
								$bookkeeping->subledger_label = '';
								if (empty($conf->cache['accountingaccountincurrententity'][$k])) {
									$accountingaccount = new AccountingAccount($this->db);
									$accountingaccount->fetch(0, $k, true);
									$conf->cache['accountingaccountincurrententity'][$k] = $accountingaccount;
								} else {
									$accountingaccount = $conf->cache['accountingaccountincurrententity'][$k];
								}
								$bookkeeping->numero_compte = $k;
								$bookkeeping->label_compte = $accountingaccount->label;
							} else {
								if ($tabtype[$key] == 'unknown') {	// Unknown transaction, we will use a waiting account for thirdparty.
									// Temporary account
									$bookkeeping->subledger_account = '';
									$bookkeeping->subledger_label = '';
									$bookkeeping->numero_compte = getDolGlobalString('ACCOUNTING_ACCOUNT_SUSPENSE');
									$bookkeeping->label_compte = $accountingaccountsuspense->label;
								}
							}
							$bookkeeping->label_operation = $reflabel;
							$bookkeeping->entity = $conf->entity;

							$totaldebit += $bookkeeping->debit;
							$totalcredit += $bookkeeping->credit;

							$result = $bookkeeping->create($user);
							if ($result < 0) {
								if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {	// Already exists
									$error++;
									$errorforline++;
									setEventMessages('Transaction for ('.$bookkeeping->doc_type.', '.$bookkeeping->fk_doc.', '.$bookkeeping->fk_docdet.') were already recorded', null, 'warnings');
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $ref,
										'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
									);
								} else {
									$error++;
									$errorforline++;
									setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $ref,
										'error' => $bookkeeping->errorsToString(),
									);
								}
							} else {
								if ($lettering && getDolGlobalInt('ACCOUNTING_ENABLE_LETTERING') && getDolGlobalInt('ACCOUNTING_ENABLE_AUTOLETTERING')) {
									require_once DOL_DOCUMENT_ROOT . '/accountancy/class/lettering.class.php';
									$lettering_static = new Lettering($this->db);
									$nb_lettering = $lettering_static->bookkeepingLetteringAll(array($bookkeeping->id));
								}
							}
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

							$bookkeeping = new BookKeeping($this->db);
							$bookkeeping->doc_date = $val["date"];
							$bookkeeping->doc_ref = $ref;
							$bookkeeping->doc_type = 'bank';
							$bookkeeping->fk_doc = $key;
							$bookkeeping->fk_docdet = $val["fk_bank"];
							$bookkeeping->montant = $mt;
							$bookkeeping->sens = ($mt < 0) ? 'D' : 'C';
							$bookkeeping->debit = ($mt < 0 ? -$mt : 0);
							$bookkeeping->credit = ($mt >= 0) ? $mt : 0;
							$bookkeeping->code_journal = $journal;
							$bookkeeping->journal_label = $langs->transnoentities($journal_label);
							$bookkeeping->fk_user_author = $user->id;
							$bookkeeping->date_creation = $now;
							$bookkeeping->label_compte = '';
							$bookkeeping->label_operation = $reflabel;
							$bookkeeping->entity = $conf->entity;

							$totaldebit += $bookkeeping->debit;
							$totalcredit += $bookkeeping->credit;

							$result = $bookkeeping->create($user);

							if ($result < 0) {
								if ($bookkeeping->error == 'BookkeepingRecordAlreadyExists') {	// Already exists
									$error++;
									$errorforline++;
									setEventMessages('Transaction for ('.$bookkeeping->doc_type.', '.$bookkeeping->fk_doc.', '.$bookkeeping->fk_docdet.') were already recorded', null, 'warnings');
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $ref,
										'error' => $langs->trans('BookkeepingRecordAlreadyExists'),
									);
								} else {
									$error++;
									$errorforline++;
									setEventMessages($bookkeeping->error, $bookkeeping->errors, 'errors');
									$this->errorforinvoicedetail[$key] = array(
										'ref' => (string) $ref,
										'error' => $bookkeeping->errorsToString(),
									);
								}
							}
						}
					}
				}
			}

			if (price2num($totaldebit, 'MT') != price2num($totalcredit, 'MT')) {
				$error++;
				$errorforline++;
				setEventMessages('We tried to insert a non balanced transaction in book for '.$ref.'. Canceled. Surely a bug.', null, 'errors');
				$this->errorforinvoicedetail[$key] = array(
					'ref' => (string) $ref,
					'error' => 'Try to insert a non balanced transaction in book for '.(string) $ref.'. Canceled. Surely a bug.',
				);
			}

			if (!$errorforline) {
				$this->db->commit();
			} else {
				//print 'KO for line '.$key.' '.$error.'<br>';
				$this->db->rollback();

				if ($error >= $max_nb_errors) {
					setEventMessages($langs->trans("ErrorTooManyErrorsProcessStopped").' (>'.$max_nb_errors.')', null, 'errors');
					break; // Break in the foreach
				}
			}
		}

		return $error ? -$error : 1;
	}

	/**
	 * Collect treasury journal (nature=4, RECETTES-DEPENSES accounting mode) data pending
	 * transfer to the bookkeeping. Pure mechanical lift of
	 * accountancy/journal/treasuryjournal.php's former inline data-collection block (10
	 * per-source-type SQL query/loop pairs - payment, payment_supplier,
	 * payment_expensereport, payment_salary, payment_sc, payment_vat, payment_donation,
	 * payment_loan, payment_various, member - plus banktransfert, dispatched by a switch over
	 * bank-line-linked object types; zero hooks, confirmed the whole file fires none). Must be
	 * called on an instance already fetch()ed with the target journal id.
	 *
	 * One deliberate deviation from a pure mechanical lift: the original page defined a
	 * page-scoped named function payment_filter() to array_filter() out payments with no
	 * objects. A named function definition can't safely live inside a method body - it would
	 * fatal with "Cannot redeclare payment_filter()" the second time this method (or
	 * writeIntoBookkeepingForTreasury(), which calls it) runs in the same PHP process (e.g.
	 * transfer() and pendingData() both called from one script or phpunit run). Replaced with
	 * an equivalent inline closure - same filter condition, same result.
	 *
	 * @param	User	$user				Unused directly here, kept for signature symmetry with writeIntoBookkeepingForTreasury()
	 * @param	int		$date_start			Start date (timestamp)
	 * @param	int		$date_end			End date (timestamp)
	 * @param	string	$in_bookkeeping		Filter on already/notyet dispatched bank lines ('notyet' by default)
	 * @param	int		$only_rappro		Filter on reconciliation status (2 = only reconciled lines, 0 = no filter)
	 * @return	array{tabpay:array<int,array{id:int,date:int,type_payment:string,ref:string,fk_bank_account:int,objects:array<string,array{amount:float,bu_url_id:int}>,lib:string}>,tabaccount:array<int,array{id:int,account_ref:string,account_number:string,url:string}>,tabobject:array<string,array{id:int,ref:string,total_ht:float,total_ttc:float,url:string,operations:array<string,array{total_ht:float,label?:string}>,vats:array<string,array<int|float|string,array{tva_tx:int|float|string,total_tva:float,total_localtax1:float,total_localtax2:float}>>}>,tabaccountingaccount:array<string,array{label:string}>}
	 */
	public function getDataForTreasury(User $user, $date_start, $date_end, $in_bookkeeping = 'notyet', $only_rappro = 0)
	{
		global $conf, $langs, $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/expensereport/class/expensereport.class.php';
		require_once DOL_DOCUMENT_ROOT.'/salaries/class/paymentsalary.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/chargesociales.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/tva.class.php';
		require_once DOL_DOCUMENT_ROOT.'/don/class/don.class.php';
		require_once DOL_DOCUMENT_ROOT.'/loan/class/loan.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/subscription.class.php';

		// Get all bank lines
		//-------------------------------------
		$sql  = "SELECT b.rowid, b.dateo as do, b.datev as dv, b.amount, b.amount_main_currency, b.label, b.rappro, b.num_releve, b.num_chq, b.fk_type, b.fk_account,";
		$sql .= " ba.courant, ba.ref as baref, ba.account_number, ba.fk_accountancy_journal,";
		$sql .= " bu.type as bu_type";
		$sql .= " FROM ".$this->db->prefix()."bank as b";
		$sql .= " JOIN ".$this->db->prefix()."bank_account as ba on b.fk_account = ba.rowid";
		$sql .= " LEFT JOIN ".$this->db->prefix()."bank_url as bu ON bu.fk_bank = b.rowid";
		$sql .= " WHERE ba.fk_accountancy_journal = ".((int) $this->id);
		$sql .= " AND b.amount <> 0 AND ba.entity IN (".getEntity('bank_account').")"; // We don't share object for accountancy, we use source object sharing
		if ($date_start && $date_end) {
			$sql .= " AND b.dateo >= '".$this->db->idate($date_start)."' AND b.dateo <= '".$this->db->idate($date_end)."'";
		}
		// Define begin binding date
		if (getDolGlobalInt('ACCOUNTING_DATE_START_BINDING')) {
			$sql .= " AND b.dateo >= '".$this->db->idate(getDolGlobalInt('ACCOUNTING_DATE_START_BINDING'))."'";
		}
		// Already in bookkeeping or not
		if ($in_bookkeeping == 'already') {
			$sql .= " AND (b.rowid IN (SELECT fk_doc FROM ".$this->db->prefix()."accounting_bookkeeping as ab  WHERE ab.doc_type='bank') )";
		}
		if ($in_bookkeeping == 'notyet') {
			$sql .= " AND (b.rowid NOT IN (SELECT fk_doc FROM ".$this->db->prefix()."accounting_bookkeeping as ab  WHERE ab.doc_type='bank') )";
		}
		if ($only_rappro == 2) {
			$sql .= " AND (b.rappro = '1')";
		}
		$sql .= " ORDER BY b.dateo";
		//print $sql;

		$result_lines = array();

		// Data cached
		$payment_ids = array();
		$tabpay = array();
		$tabaccount = array();
		$tabobject = array();
		$tabaccountingaccount = array();
		$tabvatdata = array();

		dol_syslog("accountancy/journal/treasuryjournal.php", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			$static_account = new Account($this->db);

			while ($obj = $this->db->fetch_object($resql)) {
				// Get payment infos (rowid is bank ID)
				if (!isset($tabpay[$obj->rowid])) {
					$tabpay[$obj->rowid] = array(
						'id' => $obj->rowid,
						'date' => $this->db->jdate($obj->do),
						'type_payment' => $obj->fk_type,// CHQ, VIR, LIQ, CB, ...
						'ref' => $obj->label, // by default, not unique. May be changed later
						'fk_bank_account' => $obj->fk_account,
						'objects' => array(),
					);
					$reg = array();
					if (preg_match('/^\((.*)\)$/i', $obj->label, $reg)) {
						$tabpay[$obj->rowid]["lib"] = $langs->trans($reg[1]);
					} else {
						$tabpay[$obj->rowid]["lib"] = dol_trunc($obj->label, 60);
					}
				}
				$payment_ids[$obj->bu_type][$obj->rowid] = $obj->rowid;

				// Get bank account infos (rowid is bank ID)
				if (!isset($tabaccount[$obj->fk_account])) {
					$static_account->id = $obj->fk_account;
					$static_account->ref = $obj->baref;
					$tabaccount[$obj->fk_account] = [
						'id' => $obj->fk_account,
						'account_ref' => $obj->baref,
						'account_number' => $obj->account_number,
						'url' => $static_account->getNomUrl(1),
					];
				}
			}
			$this->db->free($resql);

			foreach ($payment_ids as $type => $ids) {
				switch ($type) {
					case 'payment':

						// Customer invoices
						//------------------------------------------
						$sql = "SELECT f.rowid, f.ref AS ref, f.total_ht AS invoice_total_ht, f.total_ttc AS invoice_total_ttc,";
						$sql .= " pf.amount AS amount_payment,";
						$sql .= " fd.rowid AS row_id, fd.total_ht, fd.total_tva, fd.total_localtax1, fd.total_localtax2, fd.tva_tx, fd.total_ttc, fd.vat_src_code,";
						$sql .= " aa.account_number as accountancy_code, aa.label as accountancy_code_label,";
						$sql .= " bu.fk_bank, bu.url_id AS bu_url_id, bu.type AS bu_type";
						$sql .= " FROM ".$this->db->prefix()."facturedet as fd";
						$sql .= " INNER JOIN ".$this->db->prefix()."facture as f ON f.rowid = fd.fk_facture";
						$sql .= " INNER JOIN ".$this->db->prefix()."paiement_facture as pf ON pf.fk_facture = f.rowid";
						$sql .= " INNER JOIN ".$this->db->prefix()."bank_url as bu ON bu.url_id = pf.fk_paiement AND bu.type = '".$this->db->escape($type)."'";
						$sql .= " LEFT JOIN ".$this->db->prefix()."product as p ON p.rowid = fd.fk_product";
						$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_account as aa ON aa.rowid = fd.fk_code_ventilation";
						// Already in bookkeeping or not
						if ($in_bookkeeping == 'already') {
							$sql .= " INNER JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=f.rowid";
						} else {
							$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=f.rowid";
						}
						$sql .= " WHERE f.entity IN (".getEntity('facture', 0).')'; // We don't share object for accountancy, we use source object sharing
						// Not already in bookkeeping
						if ($in_bookkeeping == 'notyet') {
							$sql .= " AND ab.rowid IS NULL";
						}
						$sql .= " AND fd.fk_code_ventilation > 0";
						$sql .= " AND f.fk_statut > 0";
						$sql .= " AND fd.product_type IN (0,1)";
						$sql .= " AND f.type IN (".Facture::TYPE_STANDARD.",".Facture::TYPE_REPLACEMENT.",".Facture::TYPE_CREDIT_NOTE.",".(!getDolGlobalString('FACTURE_DEPOSITS_ARE_JUST_PAYMENTS') ? Facture::TYPE_DEPOSIT."," : "").Facture::TYPE_SITUATION.")";
						$sql .= " AND bu.fk_bank IN (".$this->db->sanitize(implode(',', $ids)).")";
						$sql .= " GROUP BY fd.rowid, bu.fk_bank, pf.amount, bu.url_id";	// TODO Must never have a GROUP BY on a field if field is not inside an aggregate function.
						$sql .= " ORDER BY aa.account_number";

						$resql = $this->db->query($sql);
						if ($resql) {
							$langs->load("bills");
							$static_invoice = new Facture($this->db);
							$already_sum = array();
							$account_vat_sold = getDolGlobalString('ACCOUNTING_VAT_SOLD_ACCOUNT', 'NotDefined'); // NotDefined is a reserved word

							while ($obj = $this->db->fetch_object($resql)) {
								$object_key = $obj->bu_type.'_'.$obj->rowid;

								// To check...
								// If 1 invoice has 2 payments at 2 different date, seems ok, we have 2 record $tabpay because $obj->fk_bank is different (obj->fk_bank is ID of payment in bank record table).
								// If 2 invoices are paid in the same payment, we have 2 $tabobject but also 2 $tabpay (because $object_key has 2 different values) when we should have 1.

								// Add object in payment
								if (!isset($tabpay[$obj->fk_bank]['objects'][$object_key])) {
									$tabpay[$obj->fk_bank]['objects'][$object_key] = array(
										'amount' => $obj->amount_payment,
										'bu_url_id' => $obj->bu_url_id,
									);
								}

								if (isset($already_sum[$obj->row_id])) {
									continue;
								}
								$already_sum[$obj->row_id] = $obj->row_id;

								// Set object infos
								if (!isset($tabobject[$object_key])) {
									$static_invoice->id = $obj->rowid;
									$static_invoice->ref = $obj->ref;
									$tabobject[$object_key] = array(
										'id' => $obj->rowid,
										'ref' => $obj->ref,		// It would be better to have a doc_ref that is 'BankId '.$obj->fk_bank.' - Facture FAzzz' and not just 'FAzzz' to be protected against duplicate, where xxx = $obj->fk_bank
										'total_ht' => $obj->invoice_total_ht,
										'total_ttc' => $obj->invoice_total_ttc,
										'url' => $static_invoice->getNomUrl(1),
										'operations' => array(),
										'vats' => array(),
									);
								}

								// Set accounting account infos
								if (!isset($tabaccountingaccount[$obj->accountancy_code])) {
									$tabaccountingaccount[$obj->accountancy_code] = array(
										'label' => !empty($obj->accountancy_code_label) ? $obj->accountancy_code_label : $langs->trans('NotDefined'),
									);
								}

								// Add amount for the accountancy code
								if (!isset($tabobject[$object_key]['operations'][$obj->accountancy_code])) {
									$tabobject[$object_key]['operations'][$obj->accountancy_code] = array(
										'total_ht' => 0,
									);
								}
								$tabobject[$object_key]['operations'][$obj->accountancy_code]['total_ht'] += $obj->total_ht;

								if ($obj->total_tva + $obj->total_localtax1 + $obj->total_localtax2 != 0) {
									// Get vat code compta
									if (!isset($tabvatdata[$obj->tva_tx][$obj->vat_src_code])) {
										$tabvatdata[$obj->tva_tx][$obj->vat_src_code] = getTaxesFromId($obj->tva_tx.($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : ''), $mysoc, $mysoc, 0);
									}
									$compta_tva = (!empty($tabvatdata[$obj->tva_tx][$obj->vat_src_code]['accountancy_code_sell']) ? $tabvatdata[$obj->tva_tx][$obj->vat_src_code]['accountancy_code_sell'] : $account_vat_sold);

									// Add amount VAT for the code compta
									if (!isset($tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx])) {
										$tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx] = array(
											'tva_tx' => $obj->tva_tx,
											'total_tva' => 0,
											'total_localtax1' => 0,
											'total_localtax2' => 0,
										);
									}
									$tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx]['total_tva'] += $obj->total_tva;
									$tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx]['total_localtax1'] += $obj->total_localtax1;
									$tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx]['total_localtax2'] += $obj->total_localtax2;
								}
							}
						} else {
							dol_print_error($this->db);
						}
						break;
					case 'payment_supplier':

						// Supplier invoices
						//------------------------------------------
						$sql = "SELECT ff.rowid, ff.ref, ff.total_ht AS supplier_invoice_total_ht, ff.total_ttc AS supplier_invoice_total_ttc,";
						$sql .= " pff.amount AS amount_payment,";
						$sql .= " ffd.rowid AS row_id, ffd.total_ht, ffd.tva AS total_tva, ffd.total_localtax1, ffd.total_localtax2, ffd.tva_tx, ffd.total_ttc, ffd.vat_src_code,";
						$sql .= " aa.account_number as accountancy_code, aa.label as accountancy_code_label,";
						$sql .= " bu.fk_bank, bu.url_id AS bu_url_id, bu.type AS bu_type";
						$sql .= " FROM ".$this->db->prefix()."facture_fourn_det as ffd";
						$sql .= " INNER JOIN ".$this->db->prefix()."facture_fourn as ff ON ff.rowid = ffd.fk_facture_fourn";
						$sql .= " INNER JOIN ".$this->db->prefix()."paiementfourn_facturefourn as pff ON pff.fk_facturefourn = ff.rowid";
						$sql .= " INNER JOIN ".$this->db->prefix()."bank_url as bu ON bu.url_id = pff.fk_paiementfourn AND bu.type = '".$this->db->escape($type)."'";
						$sql .= " LEFT JOIN ".$this->db->prefix()."product as p ON p.rowid = ffd.fk_product";
						$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_account as aa ON aa.rowid = ffd.fk_code_ventilation";
						// Already in bookkeeping or not
						if ($in_bookkeeping == 'already') {
							$sql .= " INNER JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=ff.rowid";
						} else {
							$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=ff.rowid";
						}
						$sql .= " WHERE ff.entity IN (".getEntity('facture_fourn', 0).')'; // We don't share object for accountancy, we use source object sharing
						// Not already in bookkeeping
						if ($in_bookkeeping == 'notyet') {
							$sql .= " AND ab.rowid IS NULL";
						}
						$sql .= " AND ffd.fk_code_ventilation > 0";
						$sql .= " AND ff.fk_statut > 0";
						$sql .= " AND ffd.product_type IN (0,1)";
						$sql .= " AND ff.type IN (".FactureFournisseur::TYPE_STANDARD.",".FactureFournisseur::TYPE_REPLACEMENT.",".FactureFournisseur::TYPE_CREDIT_NOTE.",".(!getDolGlobalString('FACTURE_DEPOSITS_ARE_JUST_PAYMENTS') ? FactureFournisseur::TYPE_DEPOSIT."," : "").FactureFournisseur::TYPE_SITUATION.")";
						$sql .= " AND bu.fk_bank IN (".$this->db->sanitize(implode(',', $ids)).")";
						$sql .= " GROUP BY ffd.rowid, bu.fk_bank";
						$sql .= " ORDER BY aa.account_number";

						$resql = $this->db->query($sql);
						if ($resql) {
							$langs->load("suppliers");
							$static_supplier_invoice = new FactureFournisseur($this->db);
							$already_sum = array();
							$account_vat_buy = getDolGlobalString('ACCOUNTING_VAT_BUY_ACCOUNT', 'NotDefined'); // NotDefined is a reserved word

							while ($obj = $this->db->fetch_object($resql)) {
								$object_key = $obj->bu_type.'_'.$obj->rowid;

								// Add object in payment
								if (!isset($tabpay[$obj->fk_bank]['objects'][$object_key])) {
									$tabpay[$obj->fk_bank]['objects'][$object_key] = array(
										'amount' => -$obj->amount_payment,
										'bu_url_id' => $obj->bu_url_id,
									);
								}

								if (isset($already_sum[$obj->row_id])) {
									continue;
								}
								$already_sum[$obj->row_id] = $obj->row_id;

								// Set object infos
								if (!isset($tabobject[$object_key])) {
									$static_supplier_invoice->id = $obj->rowid;
									$static_supplier_invoice->ref = $obj->ref;
									$tabobject[$object_key] = array(
										'id' => $obj->rowid,
										'ref' => $obj->ref,		// It would be better to have a doc_ref that is 'BankId '.$obj->fk_bank.' - Facture FAzzz' and not just 'FAzzz' to be protected against duplicate, where xxx = $obj->fk_bank
										'total_ht' => -$obj->supplier_invoice_total_ht,
										'total_ttc' => -$obj->supplier_invoice_total_ttc,
										'url' => $static_supplier_invoice->getNomUrl(1),
										'operations' => array(),
										'vats' => array(),
									);
								}

								// Set accounting account infos
								if (!isset($tabaccountingaccount[$obj->accountancy_code])) {
									$tabaccountingaccount[$obj->accountancy_code] = array(
										'label' => !empty($obj->accountancy_code_label) ? $obj->accountancy_code_label : $langs->trans('NotDefined'),
									);
								}

								// Add amount for the accountancy code
								if (!isset($tabobject[$object_key]['operations'][$obj->accountancy_code])) {
									$tabobject[$object_key]['operations'][$obj->accountancy_code] = array(
										'total_ht' => 0,
									);
								}
								$tabobject[$object_key]['operations'][$obj->accountancy_code]['total_ht'] -= $obj->total_ht;

								if ($obj->total_tva + $obj->total_localtax1 + $obj->total_localtax2 != 0) {
									// Get vat code compta
									if (!isset($tabvatdata[$obj->tva_tx][$obj->vat_src_code])) {
										$tabvatdata[$obj->tva_tx][$obj->vat_src_code] = getTaxesFromId($obj->tva_tx.($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : ''), $mysoc, $mysoc, 0);
									}
									$compta_tva = (!empty($tabvatdata[$obj->tva_tx][$obj->vat_src_code]['accountancy_code_buy']) ? $tabvatdata[$obj->tva_tx][$obj->vat_src_code]['accountancy_code_buy'] : $account_vat_buy);

									// Add amount VAT for the code compta
									if (!isset($tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx])) {
										$tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx] = array(
											'tva_tx' => $obj->tva_tx,
											'total_tva' => 0,
											'total_localtax1' => 0,
											'total_localtax2' => 0,
										);
									}
									$tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx]['total_tva'] -= $obj->total_tva;
									$tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx]['total_localtax1'] -= $obj->total_localtax1;
									$tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx]['total_localtax2'] -= $obj->total_localtax2;
								}
							}
						} else {
							dol_print_error($this->db);
						}
						break;
					case 'payment_expensereport':

						// Expense reports
						//------------------------------------------
						$sql = "SELECT er.rowid, er.ref, er.total_ht AS expense_report_total_ht, er.total_ttc AS expense_report_total_ttc,";
						$sql .= " per.amount AS amount_payment,";
						$sql .= " erf.rowid AS row_id, erf.total_ht, erf.total_tva, erf.total_localtax1, erf.total_localtax2, erf.tva_tx, erf.total_ttc, erf.vat_src_code,";
						$sql .= " ctf.accountancy_code,";
						$sql .= " aa.label as accountancy_code_label,";
						$sql .= " bu.fk_bank, bu.url_id AS bu_url_id, bu.type AS bu_type";
						$sql .= " FROM ".$this->db->prefix()."expensereport_det as erf";
						$sql .= " INNER JOIN ".$this->db->prefix()."expensereport as er ON er.rowid = erf.fk_expensereport";
						$sql .= " INNER JOIN ".$this->db->prefix()."payment_expensereport as per ON per.fk_expensereport = er.rowid";
						$sql .= " INNER JOIN ".$this->db->prefix()."bank_url as bu ON bu.url_id = per.rowid AND bu.type = '".$this->db->escape($type)."'";
						$sql .= " LEFT JOIN ".$this->db->prefix()."c_type_fees as ctf ON ctf.id = erf.fk_c_type_fees";
						$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_account as aa ON aa.account_number = ctf.accountancy_code";
						// Already in bookkeeping or not
						if ($in_bookkeeping == 'already') {
							$sql .= " INNER JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=er.rowid";
						} else {
							$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=er.rowid";
						}
						$sql .= " WHERE er.entity IN (".getEntity('expensereport', 0).')'; // We don't share object for accountancy, we use source object sharing
						// Not already in bookkeeping
						if ($in_bookkeeping == 'notyet') {
							$sql .= " AND ab.rowid IS NULL";
						}
						$sql .= " AND er.fk_statut >= ".ExpenseReport::STATUS_APPROVED;
						$sql .= " AND bu.fk_bank IN (".$this->db->sanitize(implode(',', $ids)).")";
						$sql .= " GROUP BY erf.rowid, bu.fk_bank, per.amount, aa.label, bu.url_id";
						$sql .= " ORDER BY aa.account_number";

						$resql = $this->db->query($sql);
						if ($resql) {
							$langs->load("trips");
							$static_expense_report = new ExpenseReport($this->db);
							$already_sum = array();
							$account_vat_buy = getDolGlobalString('ACCOUNTING_VAT_BUY_ACCOUNT', 'NotDefined'); // NotDefined is a reserved word

							while ($obj = $this->db->fetch_object($resql)) {
								$object_key = $obj->bu_type.'_'.$obj->rowid;

								// Add object in payment
								if (!isset($tabpay[$obj->fk_bank]['objects'][$object_key])) {
									$tabpay[$obj->fk_bank]['objects'][$object_key] = array(
										'amount' => -$obj->amount_payment,
										'bu_url_id' => $obj->bu_url_id,
									);
								}

								if (isset($already_sum[$obj->row_id])) {
									continue;
								}
								$already_sum[$obj->row_id] = $obj->row_id;

								// Set object infos
								if (!isset($tabobject[$object_key])) {
									$static_expense_report->id = $obj->rowid;
									$static_expense_report->ref = $obj->ref;
									$tabobject[$object_key] = array(
										'id' => $obj->rowid,
										'ref' => $obj->ref,
										'total_ht' => -$obj->expense_report_total_ht,
										'total_ttc' => -$obj->expense_report_total_ttc,
										'url' => $static_expense_report->getNomUrl(1),
										'operations' => array(),
										'vats' => array(),
									);
								}

								// Set accounting account infos
								$accountancy_code = !empty($obj->accountancy_code) ? $obj->accountancy_code : 'NotDefined';
								if (!isset($tabaccountingaccount[$accountancy_code])) {
									$tabaccountingaccount[$accountancy_code] = array(
										'label' => !empty($obj->accountancy_code_label) ? $obj->accountancy_code_label : $langs->trans('NotDefined'),
									);
								}

								// Add amount for the accountancy code
								if (!isset($tabobject[$object_key]['operations'][$accountancy_code])) {
									$tabobject[$object_key]['operations'][$accountancy_code] = array(
										'total_ht' => 0,
									);
								}
								$tabobject[$object_key]['operations'][$accountancy_code]['total_ht'] -= $obj->total_ht;

								if ($obj->total_tva + $obj->total_localtax1 + $obj->total_localtax2 != 0) {
									// Get vat code compta
									if (!isset($tabvatdata[$obj->tva_tx][$obj->vat_src_code])) {
										$tabvatdata[$obj->tva_tx][$obj->vat_src_code] = getTaxesFromId($obj->tva_tx.($obj->vat_src_code ? ' ('.$obj->vat_src_code.')' : ''), $mysoc, $mysoc, 0);
									}
									$compta_tva = (!empty($tabvatdata[$obj->tva_tx][$obj->vat_src_code]['accountancy_code_buy']) ? $tabvatdata[$obj->tva_tx][$obj->vat_src_code]['accountancy_code_buy'] : $account_vat_buy);

									// Add amount VAT for the code compta
									if (!isset($tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx])) {
										$tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx] = array(
											'tva_tx' => $obj->tva_tx,
											'total_tva' => 0,
											'total_localtax1' => 0,
											'total_localtax2' => 0,
										);
									}
									$tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx]['total_tva'] -= $obj->total_tva;
									$tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx]['total_localtax1'] -= $obj->total_localtax1;
									$tabobject[$object_key]['vats'][$compta_tva][$obj->tva_tx]['total_localtax2'] -= $obj->total_localtax2;
								}
							}
						} else {
							dol_print_error($this->db);
						}
						break;
					case 'payment_salary':
						// Payment salaries
						//------------------------------------------
						$sql = "SELECT ps.rowid,";
						$sql .= " ps.amount AS amount_payment, ps.label AS label,";
						$sql .= " bu.fk_bank, bu.url_id AS bu_url_id, bu.type AS bu_type";
						$sql .= " FROM ".$this->db->prefix()."payment_salary as ps";
						$sql .= " INNER JOIN ".$this->db->prefix()."bank_url as bu ON bu.url_id = ps.rowid AND bu.type = '".$this->db->escape($type)."'";
						// Already in bookkeeping or not
						if ($in_bookkeeping == 'already') {
							$sql .= " INNER JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=ps.rowid";
						} else {
							$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=ps.rowid";
						}
						$sql .= " WHERE ps.entity IN (".getEntity('user', 0).')'; // We don't share object for accountancy, we use source object sharing
						// Not already in bookkeeping
						if ($in_bookkeeping == 'notyet') {
							$sql .= " AND ab.rowid IS NULL";
						}
						$sql .= " AND bu.fk_bank IN (".$this->db->sanitize(implode(',', $ids)).")";

						$resql = $this->db->query($sql);
						if ($resql) {
							$langs->load("salaries");
							$static_payment_salary = new PaymentSalary($this->db);
							$account_employee = getDolGlobalString('SALARIES_ACCOUNTING_ACCOUNT_PAYMENT', 'NotDefined'); // NotDefined is a reserved word
							$prefix_ref = getDolGlobalString('MAIN_PAYMENT_SALARY_REF_PREFIX', 'PS');

							while ($obj = $this->db->fetch_object($resql)) {
								$object_key = $obj->bu_type.'_'.$obj->rowid;

								// Add object in payment
								if (!isset($tabpay[$obj->fk_bank]['objects'][$object_key])) {
									$tabpay[$obj->fk_bank]['objects'][$object_key] = array(
										'amount' => -$obj->amount_payment,
										'bu_url_id' => $obj->bu_url_id,
									);
								}

								// Set object infos
								if (!isset($tabobject[$object_key])) {
									$static_payment_salary->id = $obj->rowid;
									$static_payment_salary->ref = $prefix_ref.$obj->rowid;
									$tabobject[$object_key] = array(
										'id' => $obj->rowid,
										'ref' => $prefix_ref.$obj->rowid,
										'total_ht' => -$obj->amount_payment,
										'total_ttc' => -$obj->amount_payment,
										'url' => $static_payment_salary->getNomUrl(1),
										'operations' => array(),
										'vats' => array(),
									);
								}

								// Add amount for the accountancy code
								$tabobject[$object_key]['operations'][$account_employee] = array(
									'total_ht' => -$obj->amount_payment,
									'label' => $obj->label,
								);
							}
						} else {
							dol_print_error($this->db);
						}
						break;
					case 'payment_sc':
						// Socials contributions
						//------------------------------------------
						$sql = "SELECT cs.rowid, cs.ref, cs.libelle AS label, cs.amount AS sociales_contributions_amount,";
						$sql .= " pc.amount AS amount_payment,";
						$sql .= " ccs.accountancy_code,";
						$sql .= " bu.fk_bank, bu.url_id AS bu_url_id, bu.type AS bu_type";
						$sql .= " FROM ".$this->db->prefix()."paiementcharge as pc";
						$sql .= " INNER JOIN ".$this->db->prefix()."bank_url as bu ON bu.url_id = pc.rowid AND bu.type = '".$this->db->escape($type)."'";
						$sql .= " INNER JOIN ".$this->db->prefix()."chargesociales AS cs ON cs.rowid = pc.fk_charge";
						$sql .= " LEFT JOIN ".$this->db->prefix()."c_chargesociales as ccs ON ccs.id = cs.fk_type";
						// Already in bookkeeping or not
						if ($in_bookkeeping == 'already') {
							$sql .= " INNER JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=cs.rowid";
						} else {
							$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=cs.rowid";
						}
						$sql .= " WHERE cs.entity = ".$conf->entity; // We don't share object for accountancy, we use source object sharing
						// Not already in bookkeeping
						if ($in_bookkeeping == 'notyet') {
							$sql .= " AND ab.rowid IS NULL";
						}
						$sql .= " AND bu.fk_bank IN (".$this->db->sanitize(implode(',', $ids)).")";

						$resql = $this->db->query($sql);
						if ($resql) {

							$langs->load("bills");
							$static_sociales_contributions = new ChargeSociales($this->db);
							$prefix_ref = getDolGlobalString('MAIN_PAYMENT_SOCIALES_CONTRIBUTIONS_REF_PREFIX', 'SC');

							while ($obj = $this->db->fetch_object($resql)) {
								$object_key = $obj->bu_type.'_'.$obj->rowid;

								// Add object in payment
								if (!isset($tabpay[$obj->fk_bank]['objects'][$object_key])) {
									$tabpay[$obj->fk_bank]['objects'][$object_key] = array(
										'amount' => -$obj->amount_payment,
										'bu_url_id' => $obj->bu_url_id,
									);
								}

								// Set object infos
								if (!isset($tabobject[$object_key])) {
									$static_sociales_contributions->id = $obj->rowid;
									$static_sociales_contributions->ref = $prefix_ref.$obj->rowid;
									$tabobject[$object_key] = array(
										'id' => $obj->rowid,
										'ref' => $prefix_ref.$obj->rowid,
										'total_ht' => -$obj->sociales_contributions_amount,
										'total_ttc' => -$obj->sociales_contributions_amount,
										'url' => $static_sociales_contributions->getNomUrl(1),
										'operations' => array(),
										'vats' => array(),
									);
								}

								$accountancy_code = !empty($obj->accountancy_code) ? $obj->accountancy_code : 'NotDefined';

								// Add amount for the accountancy code
								$tabobject[$object_key]['operations'][$accountancy_code] = array(
									'total_ht' => -$obj->sociales_contributions_amount,
									'label' => $obj->label,
								);
							}
						} else {
							dol_print_error($this->db);
						}
						break;
					case 'payment_vat':
						// Payment VAT
						//------------------------------------------
						$sql = "SELECT t.rowid,";
						$sql .= " t.amount AS amount_payment, t.label AS label,";
						$sql .= " bu.fk_bank, bu.url_id AS bu_url_id, bu.type AS bu_type";
						$sql .= " FROM ".$this->db->prefix()."tva as t";
						$sql .= " INNER JOIN ".$this->db->prefix()."bank_url as bu ON bu.url_id = t.rowid AND bu.type = '".$this->db->escape($type)."'";
						// Already in bookkeeping or not
						if ($in_bookkeeping == 'already') {
							$sql .= " INNER JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=t.rowid";
						} else {
							$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=t.rowid";
						}
						$sql .= " WHERE bu.fk_bank IN (".$this->db->sanitize(implode(',', $ids)).")";
						// $sql .= " AND t.entity = " . $conf->entity; // TODO when entity is managed in tva
						// Not already in bookkeeping
						if ($in_bookkeeping == 'notyet') {
							$sql .= " AND ab.rowid IS NULL";
						}

						$resql = $this->db->query($sql);
						if ($resql) {

							$langs->load("salaries");
							$static_tva = new Tva($this->db);
							$account_pay_vat = getDolGlobalString('ACCOUNTING_VAT_PAY_ACCOUNT', 'NotDefined'); // NotDefined is a reserved word
							$prefix_ref = getDolGlobalString('MAIN_PAYMENT_VAT_REF_PREFIX', 'VAT');

							while ($obj = $this->db->fetch_object($resql)) {
								$object_key = $obj->bu_type.'_'.$obj->rowid;

								// Add object in payment
								if (!isset($tabpay[$obj->fk_bank]['objects'][$object_key])) {
									$tabpay[$obj->fk_bank]['objects'][$object_key] = array(
										'amount' => -$obj->amount_payment,
										'bu_url_id' => $obj->bu_url_id,
									);
								}

								// Set object infos
								if (!isset($tabobject[$object_key])) {
									$static_tva->id = $obj->rowid;
									$static_tva->ref = $prefix_ref.$obj->rowid;
									$tabobject[$object_key] = array(
										'id' => $obj->rowid,
										'ref' => $prefix_ref.$obj->rowid,
										'total_ht' => -$obj->amount_payment,
										'total_ttc' => -$obj->amount_payment,
										'url' => $static_tva->getNomUrl(1),
										'operations' => array(),
										'vats' => array(),
									);
								}

								// Add amount for the accountancy code
								$tabobject[$object_key]['operations'][$account_pay_vat] = array(
									'total_ht' => -$obj->amount_payment,
									'label' => $obj->label,
								);
							}
						} else {
							dol_print_error($this->db);
						}
						break;
					case 'payment_donation':
						// Payment donation
						//------------------------------------------
						$sql = "SELECT d.rowid, d.amount AS don_amount,";
						$sql .= " pd.amount AS amount_payment,";
						$sql .= " bu.fk_bank, bu.url_id AS bu_url_id, bu.type AS bu_type";
						$sql .= " FROM ".$this->db->prefix()."payment_donation as pd";
						$sql .= " INNER JOIN ".$this->db->prefix()."don as d ON pd.fk_donation = d.rowid";
						$sql .= " INNER JOIN ".$this->db->prefix()."bank_url as bu ON bu.url_id = pd.rowid AND bu.type = '".$this->db->escape($type)."'";
						// Already in bookkeeping or not
						if ($in_bookkeeping == 'already') {
							$sql .= " INNER JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=d.rowid";
						} else {
							$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=d.rowid";
						}
						$sql .= " WHERE d.entity IN (".getEntity('donation', 0).')'; // We don't share object for accountancy, we use source object sharing
						// Not already in bookkeeping
						if ($in_bookkeeping == 'notyet') {
							$sql .= " AND ab.rowid IS NULL";
						}
						$sql .= " AND bu.fk_bank IN (".$this->db->sanitize(implode(',', $ids)).")";

						$resql = $this->db->query($sql);
						if ($resql) {

							$langs->load("donations");
							$static_don = new Don($this->db);
							$account_pay_donation = getDolGlobalString('DONATION_ACCOUNTINGACCOUNT', 'NotDefined'); // NotDefined is a reserved word
							$prefix_ref = getDolGlobalString('MAIN_PAYMENT_DONATION_REF_PREFIX', 'D');

							while ($obj = $this->db->fetch_object($resql)) {
								$object_key = $obj->bu_type.'_'.$obj->rowid;

								// Add object in payment
								if (!isset($tabpay[$obj->fk_bank]['objects'][$object_key])) {
									$tabpay[$obj->fk_bank]['objects'][$object_key] = array(
										'amount' => $obj->amount_payment,
										'bu_url_id' => $obj->bu_url_id,
									);
								}

								// Set object infos
								if (!isset($tabobject[$object_key])) {
									$static_don->id = $obj->rowid;
									$static_don->ref = $prefix_ref.$obj->rowid;
									$tabobject[$object_key] = array(
										'id' => $obj->rowid,
										'ref' => $prefix_ref.$obj->rowid,
										'total_ht' => $obj->don_amount,
										'total_ttc' => $obj->don_amount,
										'url' => $static_don->getNomUrl(1),
										'operations' => array(),
										'vats' => array(),
									);
								}

								// Add amount for the accountancy code
								$tabobject[$object_key]['operations'][$account_pay_donation] = array(
									'total_ht' => $obj->don_amount,
									'label' => $langs->trans('Donation').' '.$prefix_ref.$obj->rowid,
								);
							}
						} else {
							dol_print_error($this->db);
						}
						break;
					case 'payment_loan':
						// Payment loan
						//------------------------------------------
						$sql = "SELECT l.rowid, l.capital AS loan_capital, l.accountancy_account_capital, l.accountancy_account_interest, l.accountancy_account_insurance, l.label,";
						$sql .= " pl.amount_capital, pl.amount_interest, pl.amount_insurance,";
						$sql .= " bu.fk_bank, bu.url_id AS bu_url_id, bu.type AS bu_type";
						$sql .= " FROM ".$this->db->prefix()."payment_loan as pl";
						$sql .= " INNER JOIN ".$this->db->prefix()."loan as l ON pl.fk_loan = l.rowid";
						$sql .= " INNER JOIN ".$this->db->prefix()."bank_url as bu ON bu.url_id = pl.rowid AND bu.type = '".$this->db->escape($type)."'";
						// Already in bookkeeping or not
						if ($in_bookkeeping == 'already') {
							$sql .= " INNER JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=l.rowid";
						} else {
							$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=l.rowid";
						}
						$sql .= " WHERE l.entity = ".$conf->entity; // We don't share object for accountancy, we use source object sharing
						// Not already in bookkeeping
						if ($in_bookkeeping == 'notyet') {
							$sql .= " AND ab.rowid IS NULL";
						}
						$sql .= " AND bu.fk_bank IN (".$this->db->sanitize(implode(',', $ids)).")";

						$resql = $this->db->query($sql);
						if ($resql) {

							$langs->load("loan");
							$static_loan = new Loan($this->db);
							$account_pay_loan_capital = getDolGlobalString('LOAN_ACCOUNTING_ACCOUNT_CAPITAL', 'NotDefined'); // NotDefined is a reserved word
							$account_pay_loan_interest = getDolGlobalString('LOAN_ACCOUNTING_ACCOUNT_INTEREST', 'NotDefined'); // NotDefined is a reserved word
							$account_pay_loan_insurance = getDolGlobalString('LOAN_ACCOUNTING_ACCOUNT_INSURANCE', 'NotDefined'); // NotDefined is a reserved word
							$prefix_ref = getDolGlobalString('MAIN_PAYMENT_LOAN_REF_PREFIX', 'L');

							while ($obj = $this->db->fetch_object($resql)) {
								$object_key = $obj->bu_type.'_'.$obj->rowid;

								// Add object in payment
								$payment_amount = $obj->amount_capital + $obj->amount_interest + $obj->amount_insurance;
								if (!isset($tabpay[$obj->fk_bank]['objects'][$object_key])) {
									$tabpay[$obj->fk_bank]['objects'][$object_key] = array(
										'amount' => -$payment_amount,
										'bu_url_id' => $obj->bu_url_id,
									);
								}

								// Set object infos
								if (!isset($tabobject[$object_key])) {
									$static_loan->id = $obj->rowid;
									$static_loan->ref = $prefix_ref.$obj->rowid;
									$tabobject[$object_key] = array(
										'id' => $obj->rowid,
										'ref' => $prefix_ref.$obj->rowid,
										'total_ht' => -$obj->loan_capital,
										'total_ttc' => -$obj->loan_capital,
										'url' => $static_loan->getNomUrl(1),
										'operations' => array(),
										'vats' => array(),
									);
								}

								// Add amount for the accountancy code
								$accountancy_account_capital = !empty($obj->accountancy_account_capital) ? $obj->accountancy_account_capital : $account_pay_loan_capital;
								$tabobject[$object_key]['operations'][$accountancy_account_capital] = array(
									// virtual total = loan_capital * amount_capital / payment_amount
									'total_ht' => -($obj->loan_capital * $obj->amount_capital / $payment_amount),
									'label' => $obj->label.' '.$langs->trans('LoanCapital'),
								);

								// Add amount for the accountancy code
								$accountancy_account_interest = !empty($obj->accountancy_account_interest) ? $obj->accountancy_account_interest : $account_pay_loan_interest;
								$tabobject[$object_key]['operations'][$accountancy_account_interest] = array(
									// virtual total = loan_capital * amount_interest / payment_amount
									'total_ht' => -($obj->loan_capital * $obj->amount_interest / $payment_amount),
									'label' => $obj->label.' '.$langs->trans('Interest'),
								);

								// 526,23 = 569,74 * x / 15 000,00

								// Add amount for the accountancy code
								$accountancy_account_insurance = !empty($obj->accountancy_account_insurance) ? $obj->accountancy_account_insurance : $account_pay_loan_insurance;
								$tabobject[$object_key]['operations'][$accountancy_account_insurance] = array(
									// virtual total = loan_capital * amount_insurance / payment_amount
									'total_ht' => -($obj->loan_capital * $obj->amount_insurance / $payment_amount),
									'label' => $obj->label.' '.$langs->trans('Insurance'),
								);
							}
						} else {
							dol_print_error($this->db);
						}
						break;
					case 'payment_various':
						// Payment various
						//------------------------------------------
						$sql = "SELECT pv.rowid,";
						$sql .= " pv.sens AS sens_payment, pv.amount AS amount_payment, pv.label, pv.accountancy_code,";
						$sql .= " bu.fk_bank, bu.url_id AS bu_url_id, bu.type AS bu_type";
						$sql .= " FROM ".$this->db->prefix()."payment_various as pv";
						$sql .= " INNER JOIN ".$this->db->prefix()."bank_url as bu ON bu.url_id = pv.rowid AND bu.type = '".$this->db->escape($type)."'";
						// Already in bookkeeping or not
						if ($in_bookkeeping == 'already') {
							$sql .= " INNER JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=pv.rowid";
						} else {
							$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=pv.rowid";
						}
						$sql .= " WHERE pv.entity IN (".getEntity('payment_various', 0).')';    // We don't share object for accountancy, we use source object sharing
						$sql .= " AND bu.fk_bank IN (".$this->db->sanitize(implode(',', $ids)).")";
						// Not already in bookkeeping
						if ($in_bookkeeping == 'notyet') {
							$sql .= " AND ab.rowid IS NULL";
						}

						$resql = $this->db->query($sql);
						if ($resql) {

							$static_payment_various = new PaymentVarious($this->db);
							$prefix_ref = getDolGlobalString('MAIN_PAYMENT_VARIOUS_REF_PREFIX', 'PM');

							while ($obj = $this->db->fetch_object($resql)) {
								$object_key = $obj->bu_type.'_'.$obj->rowid;

								$payment_amount = (empty($obj->sens_payment) ? -1 : 1) * $obj->amount_payment;
								// Add object in payment
								if (!isset($tabpay[$obj->fk_bank]['objects'][$object_key])) {
									$tabpay[$obj->fk_bank]['objects'][$object_key] = array(
										'amount' => $payment_amount,
										'bu_url_id' => $obj->bu_url_id,
									);
								}

								// Set object infos
								if (!isset($tabobject[$object_key])) {
									$static_payment_various->id = $obj->rowid;
									$static_payment_various->ref = $prefix_ref.$obj->rowid;
									$tabobject[$object_key] = array(
										'id' => $obj->rowid,
										'ref' => $prefix_ref.$obj->rowid,
										'total_ht' => $payment_amount,
										'total_ttc' => $payment_amount,
										'url' => $static_payment_various->getNomUrl(1),
										'operations' => array(),
										'vats' => array(),
									);
								}

								// Add amount for the accountancy code
								$accountancy_code = !empty($obj->accountancy_code) ? $obj->accountancy_code : 'NotDefined';
								$tabobject[$object_key]['operations'][$obj->accountancy_code] = array(
									'total_ht' => $payment_amount,
									'label' => $obj->label,
								);
							}
						} else {
							dol_print_error($this->db);
						}
						break;
					case 'member':
						// Subscription member
						//------------------------------------------
						$sql = "SELECT su.rowid,";
						$sql .= " su.subscription AS amount_payment, su.note AS label,";
						$sql .= " adh.lastname, adh.firstname,";
						$sql .= " bu.fk_bank, bu.url_id AS bu_url_id, bu.type AS bu_type";
						$sql .= " FROM ".$this->db->prefix()."subscription as su";
						$sql .= " INNER JOIN ".$this->db->prefix()."adherent as adh ON adh.rowid = su.fk_adherent";
						$sql .= " INNER JOIN ".$this->db->prefix()."bank_url as bu ON bu.fk_bank = su.fk_bank AND bu.type = '".$this->db->escape($type)."'";
						// Already in bookkeeping or not
						if ($in_bookkeeping == 'already') {
							$sql .= " INNER JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=su.rowid";
						} else {
							$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=su.rowid";
						}
						$sql .= " WHERE bu.fk_bank IN (".$this->db->sanitize(implode(',', $ids)).")";
						// Not already in bookkeeping
						if ($in_bookkeeping == 'notyet') {
							$sql .= " AND ab.rowid IS NULL";
						}

						$resql = $this->db->query($sql);
						if ($resql) {

							$langs->load("members");
							$static_subscription = new Subscription($this->db);
							$account_subscription = getDolGlobalString('ADHERENT_SUBSCRIPTION_ACCOUNTINGACCOUNT', 'NotDefined'); // NotDefined is a reserved word
							$prefix_ref = getDolGlobalString('MAIN_PAYMENT_SUBSCRIPTION_REF_PREFIX', 'SU');

							while ($obj = $this->db->fetch_object($resql)) {
								$object_key = $obj->bu_type.'_'.$obj->rowid;

								// Add object in payment
								if (!isset($tabpay[$obj->fk_bank]['objects'][$object_key])) {
									$tabpay[$obj->fk_bank]['objects'][$object_key] = array(
										'amount' => $obj->amount_payment,
										'bu_url_id' => $obj->bu_url_id,
									);
								}

								// Set object infos
								if (!isset($tabobject[$object_key])) {
									$static_subscription->id = $obj->rowid;
									$static_subscription->ref = $prefix_ref.$obj->rowid;
									$tabobject[$object_key] = array(
										'id' => $obj->rowid,
										'ref' => $prefix_ref.$obj->rowid,
										'total_ht' => -$obj->amount_payment,
										'total_ttc' => -$obj->amount_payment,
										'url' => $static_subscription->getNomUrl(1),
										'operations' => array(),
										'vats' => array(),
									);
								}

								// Add amount for the accountancy code
								$tabobject[$object_key]['operations'][$account_subscription] = array(
									'total_ht' => -$obj->amount_payment,
									'label' => $obj->label.' - '.$obj->lastname.' '.$obj->firstname,
								);
							}
						} else {
							dol_print_error($this->db);
						}
						break;
					case 'banktransfert':
						// Bank transfer
						//------------------------------------------
						$sql = "SELECT b.rowid, b.amount, b.label,";
						$sql .= " bu.fk_bank, bu.url_id AS bu_url_id, bu.type AS bu_type";
						$sql .= " FROM ".$this->db->prefix()."bank_url as bu";
						$sql .= " INNER JOIN ".$this->db->prefix()."bank as b ON bu.url_id = b.rowid";
						$sql .= " LEFT JOIN ".$this->db->prefix()."bank_account as ba ON ba.rowid = b.fk_account";
						// Already in bookkeeping or not
						if ($in_bookkeeping == 'already') {
							$sql .= " INNER JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=b.rowid";
						} else {
							$sql .= " LEFT JOIN ".$this->db->prefix()."accounting_bookkeeping as ab ON ab.fk_doc=bu.fk_bank AND ab.fk_docdet=b.rowid";
						}
						$sql .= " WHERE ba.entity IN (".getEntity('bank_account', 0).')'; // We don't share object for accountancy, we use source object sharing
						$sql .= " AND bu.fk_bank IN (".$this->db->sanitize(implode(',', $ids)).")";
						$sql .= " AND bu.type = '".$this->db->escape($type)."'";
						// Not already in bookkeeping
						if ($in_bookkeeping == 'notyet') {
							$sql .= " AND ab.rowid IS NULL";
						}

						$resql = $this->db->query($sql);
						if ($resql) {

							$static_account_line = new AccountLine($this->db);
							$account_transfer = getDolGlobalString('ACCOUNTING_ACCOUNT_TRANSFER_CASH', 'NotDefined'); // NotDefined is a reserved word
							$prefix_ref = getDolGlobalString('MAIN_PAYMENT_TRANSFER_CASH_REF_PREFIX', 'T');

							while ($obj = $this->db->fetch_object($resql)) {
								$object_key = $obj->bu_type.'_'.$obj->rowid;

								// Add object in payment
								if (!isset($tabpay[$obj->fk_bank]['objects'][$object_key])) {
									$tabpay[$obj->fk_bank]['objects'][$object_key] = array(
										'amount' => -$obj->amount,
										'bu_url_id' => $obj->bu_url_id,
									);
								}

								// Set object infos
								if (!isset($tabobject[$object_key])) {
									$static_account_line->id = $obj->rowid;
									$static_account_line->rowid = $obj->rowid;
									$static_account_line->ref = $prefix_ref.$obj->rowid;
									$tabobject[$object_key] = array(
										'id' => $obj->rowid,
										'ref' => $prefix_ref.$obj->rowid,
										'total_ht' => -$obj->amount,
										'total_ttc' => -$obj->amount,
										'url' => $static_account_line->getNomUrl(1),
										'operations' => array(),
										'vats' => array(),
									);
								}

								// Add amount for the accountancy code
								$tabobject[$object_key]['operations'][$account_transfer] = array(
									'total_ht' => -$obj->amount,
									'label' => $obj->label,
								);
							}
						} else {
							dol_print_error($this->db);
						}
						break;
				}
			}
		} else {
			dol_print_error($this->db);
		}

		$tabpay = array_filter($tabpay, function ($v) {
			return count($v['objects']) > 0;
		});

		return array(
			'tabpay' => $tabpay,
			'tabaccount' => $tabaccount,
			'tabobject' => $tabobject,
			'tabaccountingaccount' => $tabaccountingaccount,
		);
	}

	/**
	 * Write the treasury journal (nature=4, RECETTES-DEPENSES accounting mode) into the
	 * bookkeeping. Pure mechanical lift of accountancy/journal/treasuryjournal.php's former
	 * inline writebookkeeping action block. No hook is fired here - the original block had
	 * none. Must be called on an instance already fetch()ed with the target journal id.
	 *
	 * One dropped no-op: the original page reassigned its (page-local, never actually
	 * parametrized) $MAXNBERRORS = 5 a second time inside the per-payment rollback branch -
	 * always the exact same literal already set once at the top of the page, so a pure no-op
	 * given the page had no way to pass a different value in anyway. Threading a real
	 * $max_nb_errors parameter through here (matching every sibling
	 * writeIntoBookkeepingForXxx()) while keeping that second assignment would have turned a
	 * no-op into a real behavior change - silently clobbering a caller-supplied non-default
	 * threshold back down to 5 after the first rolled-back payment - so it is dropped rather
	 * than "preserved".
	 *
	 * @param	User	$user				User who write in the bookkeeping. BookKeeping::createFromValues() itself pulls `global $user;` internally rather than taking a $user parameter, so passing a different $user here does not change which user createFromValues()'s own trigger calls actually attribute the write to - a pre-existing footgun, not something this extraction fixes.
	 * @param	int		$date_start			Start date (timestamp)
	 * @param	int		$date_end			End date (timestamp)
	 * @param	int		$max_nb_errors		Nb errors authorized before stopping the process (treasury's original threshold is 5, like bank, stricter than other journals' 10)
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	public function writeIntoBookkeepingForTreasury(User $user, $date_start, $date_end, $max_nb_errors = 5)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/bookkeeping.class.php';
		require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';

		$data = $this->getDataForTreasury($user, $date_start, $date_end, 'notyet');
		$tabpay = $data['tabpay'];
		$tabaccount = $data['tabaccount'];
		$tabobject = $data['tabobject'];
		$tabaccountingaccount = $data['tabaccountingaccount'];

		$journal = $this->code;
		$journal_label = $langs->transnoentitiesnoconv($this->label);

		$accountingaccount = new AccountingAccount($this->db);

		$error = 0;
		$this->errorforinvoicedetail = array();
		foreach ($tabpay as $payment_id => $payment) {
			$accountInfos = $tabaccount[$payment["fk_bank_account"]];

			// Set accounting account infos
			if (!isset($tabaccountingaccount[$accountInfos['account_number']])) {
				$result = $accountingaccount->fetch(0, $accountInfos['account_number'], true);
				if ($result < 0) {
					setEventMessages($accountingaccount->error, $accountingaccount->errors, 'errors');
					// AccountingAccount::fetch() leaves $this->errors empty when called with no
					// rowid/account_number at all (e.g. the bank account has no GL account_number
					// configured) - fall back to a descriptive message so this per-line detail is
					// never silently empty.
					$this->errorforinvoicedetail[$payment_id] = array(
						'ref' => (string) $payment['ref'],
						'error' => $accountingaccount->errorsToString() !== '' ? $accountingaccount->errorsToString() : 'Unable to fetch accounting account for account number "'.$accountInfos['account_number'].'"',
					);
					$error++;
					break;
				}
				$tabaccountingaccount[$accountInfos['account_number']] = array(
					'label' => $result > 0 ? $accountingaccount->label : $langs->trans('NotDefined'),
				);
			}


			$errorforline = 0;
			$this->db->begin();

			foreach ($payment['objects'] as $object_key => $object_data) {
				$objectInfos = $tabobject[$object_key];

				$total_check = 0;

				// Show bank line
				if ($object_data['amount'] >= 0) {
					$amount = (float) price2num($object_data['amount'], 'MT');
					$total_check += $amount;

					$bookkeepingToCreate = new BookKeeping($this->db);

					// Unique key is on couple: $payment_id, $objectInfos['id']
					// For record in llx_accountaing_bookkeeping, for record with doc_type = 'bank', the value of fk_doc is ID in llx_bank and fk_docdet too. Wetry a fix this way;
					//$result = $bookkeepingToCreate->createFromValues($payment["date"], $objectInfos['ref'], 'bank', $payment_id, $objectInfos['id'], $accountInfos['account_number'], $tabaccountingaccount[$accountInfos['account_number']]['label'], $accountInfos['account_ref'], $amount, $journal, $journal_label, '');
					$result = $bookkeepingToCreate->createFromValues($payment["date"], $objectInfos['ref'], 'bank', $payment_id, 0, $accountInfos['account_number'], $tabaccountingaccount[$accountInfos['account_number']]['label'], $accountInfos['account_ref'], $amount, $journal, $journal_label, '');

					if ($result < 0) {
						$errorforline++;

						if (!empty($bookkeepingToCreate->warnings)) {
							setEventMessages(null, $bookkeepingToCreate->warnings, 'warnings');
						}
						if (!empty($bookkeepingToCreate->errors)) {
							setEventMessages(null, $bookkeepingToCreate->errors, 'errors');
						}
						$this->errorforinvoicedetail[$payment_id] = array(
							'ref' => (string) $objectInfos['ref'],
							'error' => $bookkeepingToCreate->errorsToString(),
						);
					}
				}

				// Operations
				$payment_total_vat = (float) price2num($object_data['amount'] * ($objectInfos['total_ttc'] - $objectInfos['total_ht']) / $objectInfos['total_ttc'], 'MT');
				$payment_total_ht = $object_data['amount'] - $payment_total_vat;
				$total_operation = 0;
				$idx = 1;
				$nb_operation = count($objectInfos['operations']);
				foreach ($objectInfos['operations'] as $accountancy_code => $operation) {
					if (!empty($operation['total_ht'])) {
						// Set accounting account infos
						if (!isset($tabaccountingaccount[$accountancy_code])) {
							$result = $accountingaccount->fetch(0, $accountancy_code, true);
							if ($result < 0) {
								setEventMessages($accountingaccount->error, $accountingaccount->errors, 'errors');
								$accountancy_code_label = $accountingaccount->errorsToString();
								$errorforline++;
							} elseif ($result > 0) {
								$accountancy_code_label = $accountingaccount->label;
							} else {
								$accountancy_code_label = $langs->trans('NotDefined');
							}
							$tabaccountingaccount[$accountancy_code] = array('label' => $accountancy_code_label);
						}
						$accountingAccountInfos = $tabaccountingaccount[$accountancy_code];
						if ($idx < $nb_operation) {
							$amount = price2num($payment_total_ht * $operation['total_ht'] / $objectInfos['total_ht'], 'MT');
							$total_operation += (float) $amount;
						} else {
							$amount = $payment_total_ht - $total_operation;
						}
						$total_check -= (float) $amount;

						$bookkeepingToCreate = new BookKeeping($this->db);
						//$result = $bookkeepingToCreate->createFromValues($payment["date"], $objectInfos['ref'], 'bank', $payment_id, $objectInfos['id'], $accountancy_code, $accountingAccountInfos['label'], (!empty($operation['label']) ? $operation['label'] : $accountingAccountInfos['label']), -$amount, $journal, $journal_label, '');
						$result = $bookkeepingToCreate->createFromValues($payment["date"], $objectInfos['ref'], 'bank', $payment_id, 0, $accountancy_code, $accountingAccountInfos['label'], (!empty($operation['label']) ? $operation['label'] : $accountingAccountInfos['label']), - (float) $amount, $journal, $journal_label, '');
						if ($result < 0) {
							$errorforline++;

							if (!empty($bookkeepingToCreate->warnings)) {
								setEventMessages(null, $bookkeepingToCreate->warnings, 'warnings');
							}
							if (!empty($bookkeepingToCreate->errors)) {
								setEventMessages(null, $bookkeepingToCreate->errors, 'errors');
							}
							$this->errorforinvoicedetail[$payment_id] = array(
								'ref' => (string) $objectInfos['ref'],
								'error' => $bookkeepingToCreate->errorsToString(),
							);
						}
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
						$amount = $vat_infos['total_tva'] + $vat_infos['total_localtax1'] + $vat_infos['total_localtax2'];
						if (!empty($amount)) {
							// Set accounting account infos
							if (!isset($tabaccountingaccount[$accountancy_code])) {
								$result = $accountingaccount->fetch(0, $accountancy_code, true);
								if ($result < 0) {
									setEventMessages($accountingaccount->error, $accountingaccount->errors, 'errors');
									$accountancy_code_label = $accountingaccount->errorsToString();
									$errorforline++;
								} elseif ($result > 0) {
									$accountancy_code_label = $accountingaccount->label;
								} else {
									$accountancy_code_label = $langs->trans('NotDefined');
								}
								$tabaccountingaccount[$accountancy_code] = array('label' => $accountancy_code_label);
							}
							$accountingAccountInfos = $tabaccountingaccount[$accountancy_code];
							$amount = (float) price2num($payment_total_vat * $amount / ($objectInfos['total_ttc'] - $objectInfos['total_ht']), 'MT');
							$total_vat += $amount;
							$total_check -= $amount;

							$bookkeepingToCreate = new BookKeeping($this->db);
							//$result = $bookkeepingToCreate->createFromValues($payment["date"], $objectInfos['ref'], 'bank', $payment_id, $objectInfos['id'], $accountancy_code, $accountingAccountInfos['label'], $langs->trans('VAT').' '.price($vat_infos['tva_tx']).'%', -$amount, $journal, $journal_label, '');
							$result = $bookkeepingToCreate->createFromValues($payment["date"], $objectInfos['ref'], 'bank', $payment_id, 0, $accountancy_code, $accountingAccountInfos['label'], $langs->trans('VAT').' '.price($vat_infos['tva_tx']).'%', -$amount, $journal, $journal_label, '');
							if ($result < 0) {
								$errorforline++;

								if (!empty($bookkeepingToCreate->warnings)) {
									setEventMessages(null, $bookkeepingToCreate->warnings, 'warnings');
								}
								if (!empty($bookkeepingToCreate->errors)) {
									setEventMessages(null, $bookkeepingToCreate->errors, 'errors');
								}
								$this->errorforinvoicedetail[$payment_id] = array(
									'ref' => (string) $objectInfos['ref'],
									'error' => $bookkeepingToCreate->errorsToString(),
								);
							}
						}
						$idx++;
					}
				}

				// Show bank line
				if ($object_data['amount'] < 0) {
					$amount = (float) price2num($object_data['amount'], 'MT');
					$total_check += $amount;

					$bookkeepingToCreate = new BookKeeping($this->db);
					//$result = $bookkeepingToCreate->createFromValues($payment["date"], $objectInfos['ref'], 'bank', $payment_id, $objectInfos['id'], $accountInfos['account_number'], $tabaccountingaccount[$accountInfos['account_number']]['label'], $accountInfos['account_ref'], $amount, $journal, $journal_label, '');
					$result = $bookkeepingToCreate->createFromValues($payment["date"], $objectInfos['ref'], 'bank', $payment_id, 0, $accountInfos['account_number'], $tabaccountingaccount[$accountInfos['account_number']]['label'], $accountInfos['account_ref'], $amount, $journal, $journal_label, '');
					if ($result < 0) {
						$errorforline++;

						if (!empty($bookkeepingToCreate->warnings)) {
							setEventMessages(null, $bookkeepingToCreate->warnings, 'warnings');
						}
						if (!empty($bookkeepingToCreate->errors)) {
							setEventMessages(null, $bookkeepingToCreate->errors, 'errors');
						}
						$this->errorforinvoicedetail[$payment_id] = array(
							'ref' => (string) $objectInfos['ref'],
							'error' => $bookkeepingToCreate->errorsToString(),
						);
					}
				}

				$total_check = price2num($total_check, 'MT');
				if (!empty($total_check)) {
					$errorforline++;
					setEventMessages($langs->trans('ErrorBookkeepingTryInsertNotBalancedTransactionAndCanceled', $objectInfos['ref'], $object_data['bu_url_id']), null, 'errors');
					$this->errorforinvoicedetail[$payment_id] = array(
						'ref' => (string) $objectInfos['ref'],
						'error' => $langs->trans('ErrorBookkeepingTryInsertNotBalancedTransactionAndCanceled', $objectInfos['ref'], $object_data['bu_url_id']),
					);
				}

				if ($errorforline) {
					$error++;

					if ($error >= $max_nb_errors) {
						break;  // Break in the foreach
					}
				}
			}

			if (!$errorforline) {
				$this->db->commit();
			} else {
				//print 'KO for line '.$key.' '.$error.'<br>';
				$this->db->rollback();

				if ($error >= $max_nb_errors) {
					setEventMessages($langs->trans("ErrorTooManyErrorsProcessStopped").' (>'.$max_nb_errors.')', null, 'errors');
					break;  // Break in the foreach
				}
			}
		}

		return $error ? -$error : 1;
	}

	/**
	 *	Export journal CSV
	 * 	ISO and not UTF8 !
	 *
	 * @param	array<int,array{blocks:array<array<array<string>>>}>	$journal_data			Journal data to write in the bookkeeping
	 *                                                                                          $journal_data = array(
	 *                                                                                          id_element => array(
	 *                                                                                          'continue' => false,
	 *                                                                                          'blocks' => array(
	 *                                                                                          pos_block => array(
	 *                                                                                          num_line => array(
	 *                                                                                          data to write in the CSV line
	 *                                                                                          ),
	 *                                                                                          ),
	 *                                                                                          ),
	 *                                                                                          ),
	 *                                                                                          );
	 * @param	int				$search_date_end		Search date end
	 * @param	string			$sep					CSV separator
	 * @return 	int|string								Return integer <0 if KO, >0 if OK
	 */
	public function exportCsv(&$journal_data = array(), $search_date_end = 0, $sep = '')
	{
		global $conf, $langs, $hookmanager;

		if (empty($sep)) {
			$sep = getDolGlobalString('ACCOUNTING_EXPORT_SEPARATORCSV');
		}
		$out = '';

		// Hook
		$hookmanager->initHooks(array('accountingjournaldao'));
		$parameters = array('journal_data' => &$journal_data, 'search_date_end' => &$search_date_end, 'sep' => &$sep, 'out' => &$out);
		$reshook = $hookmanager->executeHooks('exportCsv', $parameters, $this); // Note that $action and $object may have been
		if ($reshook < 0) {
			$this->error = $hookmanager->error;
			$this->errors = $hookmanager->errors;
			return -1;
		} elseif (empty($reshook)) {
			// Clean parameters
			$journal_data = is_array($journal_data) ? $journal_data : array();

			// CSV header line
			$header = array();
			if ($this->nature == 4) {
				$header = array(
					$langs->transnoentitiesnoconv("BankId"),
					$langs->transnoentitiesnoconv("Date"),
					$langs->transnoentitiesnoconv("PaymentMode"),
					$langs->transnoentitiesnoconv("AccountAccounting"),
					$langs->transnoentitiesnoconv("LedgerAccount"),
					$langs->transnoentitiesnoconv("SubledgerAccount"),
					$langs->transnoentitiesnoconv("Label"),
					$langs->transnoentitiesnoconv("AccountingDebit"),
					$langs->transnoentitiesnoconv("AccountingCredit"),
					$langs->transnoentitiesnoconv("Journal"),
					$langs->transnoentitiesnoconv("Note"),
				);
			} elseif ($this->nature == 5) {
				$header = array(
					$langs->transnoentitiesnoconv("Date"),
					$langs->transnoentitiesnoconv("Piece"),
					$langs->transnoentitiesnoconv("AccountAccounting"),
					$langs->transnoentitiesnoconv("LabelOperation"),
					$langs->transnoentitiesnoconv("AccountingDebit"),
					$langs->transnoentitiesnoconv("AccountingCredit"),
				);
			} elseif ($this->nature == 1) {
				$header = array(
					$langs->transnoentitiesnoconv("Date"),
					$langs->transnoentitiesnoconv("Piece"),
					$langs->transnoentitiesnoconv("AccountAccounting"),
					$langs->transnoentitiesnoconv("LabelOperation"),
					$langs->transnoentitiesnoconv("AccountingDebit"),
					$langs->transnoentitiesnoconv("AccountingCredit"),
				);
			}

			if (!empty($header)) {
				$out .= '"' . implode('"' . $sep . '"', $header) . '"' . "\n";
			}
			foreach ($journal_data as $element_id => $element) {
				foreach ($element['blocks'] as $lines) {
					foreach ($lines as $line) {
						$out .= '"' . implode('"' . $sep . '"', $line) . '"' . "\n";
					}
				}
			}
		}

		return $out;
	}

	/**
	 *  Get accounting account info
	 *
	 * @param	string	$account	Accounting account number
	 * @return	array{found:bool,label:string,code_formatted_1:string,label_formatted_1:string,label_formatted_2:string}		Accounting account info
	 */
	public function getAccountingAccountInfos($account)
	{
		if (!isset(self::$accounting_account_cached[$account])) {
			require_once DOL_DOCUMENT_ROOT . '/core/lib/accounting.lib.php';
			require_once DOL_DOCUMENT_ROOT . '/accountancy/class/accountingaccount.class.php';
			$accountingaccount = new AccountingAccount($this->db);
			$result = $accountingaccount->fetch(0, $account, true);
			if ($result > 0) {
				self::$accounting_account_cached[$account] = array(
					'found' => true,
					'label' => $accountingaccount->label,
					'code_formatted_1' => length_accountg(html_entity_decode($account)),
					'label_formatted_1' => mb_convert_encoding(dol_trunc($accountingaccount->label, 32), 'ISO-8859-1'),
					'label_formatted_2' => dol_trunc($accountingaccount->label, 32),
				);
			} else {
				self::$accounting_account_cached[$account] = array(
					'found' => false,
					'label' => '',
					'code_formatted_1' => length_accountg(html_entity_decode($account)),
					'label_formatted_1' => '',
					'label_formatted_2' => '',
				);
			}
		}

		return self::$accounting_account_cached[$account];
	}
}
