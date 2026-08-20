<?php
/* Copyright (C) 2013-2014  Olivier Geffroy         <jeff@jeffinfo.com>
 * Copyright (C) 2013-2014  Alexandre Spangaro      <aspangaro@open-dsi.fr>
 * Copyright (C) 2013-2014  Florian Henry           <florian.henry@open-concept.pro>
 * Copyright (C) 2023-2024  Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2024       Alexandre Janniaux      <alexandre.janniaux@gmail.com>
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
 * \file		htdocs/accountancy/class/accountancysystem.class.php
 * \ingroup		Accountancy (Double entries)
 * \brief		File of class to manage accountancy systems
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

/**
 * Class to manage accountancy systems
 */
class AccountancySystem extends CommonObject
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var string[] Array of Errors code (or messages)
	 */
	public $errors = array();

	/**
	 * @var int ID
	 */
	public $id;

	/**
	 * @var int ID
	 * @deprecated
	 * @see $id
	 */
	public $rowid;

	/**
	 * @var string 		Accountancy system code
	 */
	public $pcg_version;

	/**
	 * @var string 		Ref of accountancy system. Duplicate property with ->pcg_version.
	 * @see $pcg_version
	 */
	public $ref;

	/**
	 * @var int active
	 */
	public $active;

	/**
	 * @var string Accountancy System label
	 */
	public $label;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}


	/**
	 * Load record in memory
	 *
	 * @param 	int 	$rowid 				   Id
	 * @param 	string 	$ref             	   ref
	 * @return 	int                            Return integer <0 if KO, Id of record if OK and found
	 */
	public function fetch($rowid = 0, $ref = '')
	{
		global $conf;

		if ($rowid > 0 || $ref) {
			$sql  = "SELECT a.rowid, a.pcg_version, a.label, a.active";
			$sql .= " FROM ".MAIN_DB_PREFIX."accounting_system as a";
			$sql .= " WHERE";
			if ($rowid) {
				$sql .= " a.rowid = ".((int) $rowid);
			} elseif ($ref) {
				$sql .= " a.pcg_version = '".$this->db->escape($ref)."'";
			}

			dol_syslog(get_class($this)."::fetch", LOG_DEBUG);
			$result = $this->db->query($sql);
			if ($result) {
				$obj = $this->db->fetch_object($result);

				if ($obj) {
					$this->id = $obj->rowid;
					$this->rowid = $obj->rowid;
					$this->pcg_version = $obj->pcg_version;
					$this->ref = $obj->pcg_version;
					$this->label = $obj->label;
					$this->active = $obj->active;

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
	 * Insert accountancy system name into database
	 *
	 * @param User $user making insert
	 * @return int if KO, Id of line if OK
	 */
	public function create($user)
	{
		$now = dol_now();

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."accounting_system";
		$sql .= " (date_creation, fk_user_author, label, pcg_version, active)";
		$sql .= " VALUES ('"
			. $this->db->idate($now)                    ."',"
			. ((int) $user->id)                         .",'"
			. $this->db->escape($this->label)           ."','"
			. $this->db->escape($this->pcg_version)     ."',"
			. ((int) $this->active)                      .")";

		dol_syslog(get_class($this)."::create", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			$id = $this->db->last_insert_id(MAIN_DB_PREFIX."accounting_system");

			if ($id > 0) {
				$this->id = $id;
				$this->rowid = $id;
				$result = $this->rowid;
			} else {
				$result = - 2;
				$this->error = "AccountancySystem::Create Error $result: " . $this->db->lasterror();
				dol_syslog($this->error, LOG_ERR);
			}
		} else {
			$result = - 1;
			$this->error = "AccountancySystem::Create Error $result: " . $this->db->lasterror();
			dol_syslog($this->error, LOG_ERR);
		}

		return $result;
	}


	/**
	 * Update accountancy system in database
	 *
	 * @param User $user making update
	 * @return int Return integer <0 if KO, >0 if OK
	 */
	public function update($user)
	{
		$this->db->begin();

		$sql = "UPDATE ".MAIN_DB_PREFIX."accounting_system";
		$sql .= " SET label = '".$this->db->escape($this->label)."'";
		$sql .= ", pcg_version = '".$this->db->escape($this->pcg_version)."'";
		$sql .= ", active = ".((int) $this->active);
		$sql .= " WHERE rowid = ".((int) $this->id);

		dol_syslog(get_class($this)."::update", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			$this->db->commit();
			return 1;
		} else {
			$this->error = "AccountancySystem::update Error: ".$this->db->lasterror();
			dol_syslog($this->error, LOG_ERR);
			$this->db->rollback();
			return -1;
		}
	}

	/**
	 * Activate this chart-of-accounts model: bulk-load its country's chart-of-accounts data
	 * file into llx_accounting_account and point the CHARTOFACCOUNTS global const at it.
	 * Mirrors htdocs/accountancy/admin/account.php's "change and load" logic. $this must
	 * already be fetch()ed.
	 *
	 * @param User $user User activating the chart (kept for signature consistency with the
	 *                   other write methods on this class; the underlying run_sql()/
	 *                   dolibarr_set_const() calls do not take a user parameter)
	 * @return int Return integer <0 if KO, >0 if OK
	 */
	public function activate($user)
	{
		global $conf;

		if (empty($this->id) || $this->id <= 0) {
			$this->error = 'Object must be fetched before activation';
			return -1;
		}

		$sql = "SELECT code FROM ".MAIN_DB_PREFIX."c_country as c, ".MAIN_DB_PREFIX."accounting_system as a";
		$sql .= " WHERE c.rowid = a.fk_country AND a.rowid = ".((int) $this->id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = "AccountancySystem::activate Error: ".$this->db->lasterror();
			dol_syslog($this->error, LOG_ERR);
			return -2;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj || empty($obj->code)) {
			$this->error = 'No country is set on this chart-of-accounts model (fk_country), cannot resolve a chart-of-accounts data file';
			return -3;
		}
		$country_code = $obj->code;

		$sqlfile = DOL_DOCUMENT_ROOT.'/install/mysql/data/llx_accounting_account_'.strtolower($country_code).'.sql';
		if (!is_readable($sqlfile)) {
			$this->error = 'No chart-of-accounts data file available for country code '.$country_code;
			return -4;
		}

		$offsetforchartofaccount = 0;
		// Get the comment line '-- ADD CCCNNNNN to rowid...' to find CCCNNNNN (CCC is country num, NNNNN is id of accounting account)
		// and pass CCCNNNNN + (num of company * 100 000 000) as offset to run_sql to update sql on the fly to add offset to rowid and account_parent value.
		// This is to be sure there is no conflict for each chart of account, whatever is country, whatever is company when multicompany is used.
		$tmp = file_get_contents($sqlfile);
		$reg = array();
		if (preg_match('/-- ADD (\d+) to rowid/ims', $tmp, $reg)) {
			$offsetforchartofaccount += $reg[1];
		}
		$offsetforchartofaccount += ($conf->entity * 100000000);

		$result = run_sql($sqlfile, 1, $conf->entity, 1, '', 'default', 32768, 0, $offsetforchartofaccount);
		if ($result <= 0) {
			$this->error = 'Error loading chart-of-accounts data file '.$sqlfile;
			return -5;
		}

		if (!dolibarr_set_const($this->db, 'CHARTOFACCOUNTS', $this->id, 'chaine', 0, '', $conf->entity)) {
			$this->error = 'Error setting CHARTOFACCOUNTS constant';
			return -6;
		}

		return 1;
	}

	/**
	 * Delete accountancy system from database
	 *
	 * @param User $user making delete
	 * @return int Return integer <0 if KO, >0 if OK
	 */
	public function delete($user)
	{
		$this->db->begin();

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."accounting_system";
		$sql .= " WHERE rowid = ".((int) $this->id);

		dol_syslog(get_class($this)."::delete", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			$this->db->commit();
			return 1;
		} else {
			$this->error = "AccountancySystem::delete Error: ".$this->db->lasterror();
			dol_syslog($this->error, LOG_ERR);
			$this->db->rollback();
			return -1;
		}
	}
}
