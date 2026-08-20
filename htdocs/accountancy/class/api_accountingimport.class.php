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

require_once DOL_DOCUMENT_ROOT.'/imports/class/import.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/modules/import/import_csv.modules.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * API class for importing a chart-of-accounts CSV file into llx_accounting_account, driving
 * Dolibarr's generic Import engine (htdocs/imports/import.php) headlessly for the
 * "Chartofaccounts" dataset (module Accounting, import_code "accounting_1") instead of through
 * its interactive multi-step wizard.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class AccountingImport extends DolibarrApi
{
	/**
	 * @var string Import dataset code for the Chartofaccounts profile, declared in
	 *             modAccounting.class.php ($this->rights_class.'_'.$r, r=1)
	 */
	const DATATOIMPORT = 'accounting_1';

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Import a chart-of-accounts CSV file into llx_accounting_account.
	 *
	 * Drives the same Import/ImportCsv engine the "New import" wizard uses for its
	 * "Chartofaccounts" dataset, but headlessly: the file is supplied as request content
	 * (not a multi-step upload+column-mapping session) and its 9 columns must appear in the
	 * exact order declared by that dataset's profile (see the CSV columns list below) — there is
	 * no interactive column-mapping step. Call with simulate=true first to check for errors
	 * without writing anything.
	 *
	 * CSV columns, in required order (columns marked * are mandatory, may not be blank):
	 * 1. Chartofaccounts* (fk_pcg_version, e.g. "PCG25-DEV" — must match an existing
	 *    llx_accounting_system.pcg_version)
	 * 2. AccountAccounting* (account_number)
	 * 3. Label*
	 * 4. Accountparent (account_number of a parent account, or blank)
	 * 5. AccountingCategory (an accounting-category code or label, or blank)
	 * 6. Pcgtype*
	 * 7. Centralized* (0 or 1)
	 * 8. Status* (0 or 1)
	 * 9. DateCreation (YYYY-MM-DD, or blank)
	 *
	 * Either all 9 rows for a given CSV line insert/update cleanly, or (barring simulate=true)
	 * the whole request is rolled back if any line has an error — matching the wizard's own
	 * all-or-nothing commit policy for a real (non-simulated) run.
	 *
	 * @param	array	$request_data	Request data: filecontent (string, required), fileencoding
	 *                                  ('' or 'base64', default ''), filename (string, default
	 *                                  'chartofaccounts_import.csv'), excludefirstline (int,
	 *                                  number of leading lines to skip, default 1 to skip a
	 *                                  header row - pass 0 if the CSV has no header),
	 *                                  updateifexists (bool, default false), simulate (bool,
	 *                                  default false)
	 * @phan-param ?array<string,mixed> $request_data
	 * @phpstan-param ?array<string,mixed> $request_data
	 * @return	array
	 * @phan-return array{importid:string,nblines:int,nbok:int,nberrors:int,nbwarnings:int,errors:array<int|string,array<int,array<string,string>>>,warnings:array<int|string,array<int,array<string,string>>>,committed:bool}
	 * @phpstan-return array{importid:string,nblines:int,nbok:int,nberrors:int,nbwarnings:int,errors:array<int|string,array<int,array<string,string>>>,warnings:array<int|string,array<int,array<string,string>>>,committed:bool}
	 *
	 * @url POST accountingsystems/importchart
	 *
	 * @throws RestException
	 */
	public function postImportChart($request_data = null)
	{
		global $conf;

		if (!DolibarrApiAccess::$user->hasRight('accounting', 'chartofaccount')) {
			throw new RestException(403);
		}

		if ($request_data === null) {
			$request_data = array();
		}

		$filecontent = isset($request_data['filecontent']) ? (string) $request_data['filecontent'] : '';
		$fileencoding = isset($request_data['fileencoding']) ? (string) $request_data['fileencoding'] : '';
		$filename = isset($request_data['filename']) && $request_data['filename'] !== '' ? (string) $request_data['filename'] : 'chartofaccounts_import.csv';
		$excludefirstline = isset($request_data['excludefirstline']) ? (int) $request_data['excludefirstline'] : 1;
		$updateifexists = !empty($request_data['updateifexists']);
		$simulate = !empty($request_data['simulate']);

		if ($filecontent === '') {
			throw new RestException(400, 'filecontent is mandatory');
		}

		$newfilecontent = ($fileencoding == 'base64') ? base64_decode($filecontent) : $filecontent;
		if ($newfilecontent === '' || $newfilecontent === false) {
			throw new RestException(400, 'filecontent is empty after decoding');
		}

		// Write the decoded content to the same temp dir the import wizard itself uses
		// (DOL_DATA_ROOT/import/temp), so ImportCsv::import_open_file() reads it from exactly
		// where Dolibarr's own import tooling expects a pending import file.
		dol_mkdir($conf->import->dir_temp);
		$tmpfile = $conf->import->dir_temp.'/'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'-'.dol_sanitizeFileName($filename);

		$fhandle = @fopen($tmpfile, 'w');
		if (!$fhandle) {
			throw new RestException(500, "Failed to open file '".$tmpfile."' for write");
		}
		fwrite($fhandle, $newfilecontent);
		fclose($fhandle);
		dolChmod($tmpfile);

		$checkvirusarray = dolCheckVirus($tmpfile);
		if (count($checkvirusarray)) {
			dol_delete_file($tmpfile);
			throw new RestException(500, 'ErrorFileIsInfectedWithAVirus: '.implode(',', $checkvirusarray));
		}

		$objimport = new Import($this->db);
		$objimport->load_arrays(DolibarrApiAccess::$user, self::DATATOIMPORT);
		if (empty($objimport->array_import_fields[0])) {
			dol_delete_file($tmpfile);
			throw new RestException(500, 'Chartofaccounts import dataset not found - is the Accounting module enabled?');
		}

		// Build the column mapping positionally from the profile's own declared field order -
		// this endpoint requires the CSV's 9 columns to already be in that exact order, so no
		// interactive column-mapping step is needed.
		$array_match_file_to_database = array();
		$i = 1;
		foreach (array_keys($objimport->array_import_fields[0]) as $fieldkey) {
			$array_match_file_to_database[$i] = $fieldkey;
			$i++;
		}
		$maxfields = count($array_match_file_to_database);

		$updatekeys = array();
		if ($updateifexists && !empty($objimport->array_import_updatekeys[0])) {
			$updatekeys = array_keys($objimport->array_import_updatekeys[0]);
		}

		$obj = new ImportCsv($this->db, self::DATATOIMPORT);

		// import_get_nb_of_lines() docs itself as needing a closed file (it opens/reads/closes
		// the path independently of $this->handle), so it must run before import_open_file().
		$nboflines = $obj->import_get_nb_of_lines($tmpfile);

		$result = $obj->import_open_file($tmpfile);
		if ($result < 0) {
			dol_delete_file($tmpfile);
			throw new RestException(500, 'Error opening uploaded file: '.$obj->error);
		}

		$importid = dol_print_date(dol_now(), '%Y%m%d%H%M%S');
		$arrayoferrors = array();
		$arrayofwarnings = array();
		$nbok = 0;

		global $tablewithentity_cache;
		$tablewithentity_cache = array();

		$this->db->begin();

		$sourcelinenb = 0;
		$endoffile = 0;
		while ($sourcelinenb < $nboflines && !$endoffile) {
			$sourcelinenb++;
			$arrayrecord = $obj->import_read_record();
			if ($arrayrecord === false) {
				$endoffile++;
				continue;
			}
			if ($excludefirstline && ($sourcelinenb < $excludefirstline + 1)) {
				continue;
			}

			$result = $obj->import_insert($arrayrecord, $array_match_file_to_database, $objimport, $maxfields, $importid, $updatekeys);

			if (count($obj->errors)) {
				$arrayoferrors[$sourcelinenb] = $obj->errors;
			}
			if (count($obj->warnings)) {
				$arrayofwarnings[$sourcelinenb] = $obj->warnings;
			}
			if (!count($obj->errors) && !count($obj->warnings)) {
				$nbok++;
			}
		}
		$obj->import_close_file();
		dol_delete_file($tmpfile);

		$committed = false;
		if ($simulate) {
			$this->db->rollback();
		} elseif (count($arrayoferrors) > 0) {
			$this->db->rollback();
		} else {
			$error = 0;
			if (!empty($objimport->array_import_run_sql_after[0]) && is_array($objimport->array_import_run_sql_after[0])) {
				foreach ($objimport->array_import_run_sql_after[0] as $sqlafterimport) {
					if (!$this->db->query($sqlafterimport)) {
						$arrayoferrors['none'][] = array('lib' => 'Error running post-import request: '.$sqlafterimport, 'type' => 'SQL');
						$error++;
					}
				}
			}
			if (!$error) {
				$this->db->commit();
				$committed = true;
			} else {
				$this->db->rollback();
			}
		}

		return array(
			'importid' => $importid,
			'nblines' => $sourcelinenb,
			'nbok' => $nbok,
			'nberrors' => count($arrayoferrors),
			'nbwarnings' => count($arrayofwarnings),
			'errors' => $arrayoferrors,
			'warnings' => $arrayofwarnings,
			'committed' => $committed,
		);
	}
}
