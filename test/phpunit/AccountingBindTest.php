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
 * or see https://www.gnu.org/
 */

/**
 *      \file       test/phpunit/AccountingBindTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

global $conf,$user,$langs,$db;
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/accountingaccount.class.php';
require_once dirname(__FILE__).'/../../htdocs/compta/facture/class/facture.class.php';
require_once dirname(__FILE__).'/../../htdocs/fourn/class/fournisseur.facture.class.php';
require_once dirname(__FILE__).'/../../htdocs/core/lib/accounting.lib.php';
require_once dirname(__FILE__).'/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class AccountingBindTest extends CommonClassTest
{
	/**
	 * setUpBeforeClass
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		global $conf,$user,$langs,$db;
		$db->begin(); // This is to have all actions inside a transaction even if test launched without suite.

		if (!isModEnabled('accounting')) {
			print __METHOD__." module accounting must be enabled.\n";
			exit(1);
		}

		print __METHOD__."\n";
	}

	/**
	 * testBindCreateFixtureInvoice
	 *
	 * Creates a specimen customer invoice (persisted with real facturedet lines) and returns
	 * the id of its first line, to be used as a real, bindable lineid by the following tests.
	 *
	 * @return int Id of a facturedet line
	 */
	public function testBindCreateFixtureInvoice()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = new Facture($db);
		$localobject->initAsSpecimen();
		$result = $localobject->create($user);
		$this->assertLessThan($result, 0);

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facturedet WHERE fk_facture = ".((int) $result)." ORDER BY rowid";
		$resql = $db->query($sql);
		$this->assertNotFalse($resql);
		$obj = $db->fetch_object($resql);
		$this->assertNotEmpty($obj);

		$lineid = (int) $obj->rowid;
		print __METHOD__." invoiceid=".$result." lineid=".$lineid."\n";

		return $lineid;
	}

	/**
	 * testBindCustomerLine
	 *
	 * @param	int		$lineid		Id of facturedet line
	 * @return	array{0:int,1:int}	Array(lineid, accountid)
	 *
	 * @depends	testBindCreateFixtureInvoice
	 * The depends says test is run only if previous is ok
	 */
	public function testBindCustomerLine($lineid)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_account ORDER BY rowid";
		$resql = $db->query($sql);
		$this->assertNotFalse($resql);
		$obj = $db->fetch_object($resql);
		$this->assertNotEmpty($obj, 'At least one accounting account must exist in the chart of accounts to run this test');
		$accountid = (int) $obj->rowid;

		$localobject = new AccountingAccount($db);
		$result = $localobject->bindInvoiceLine($lineid, $accountid, 'customer', $user);
		print __METHOD__." lineid=".$lineid." accountid=".$accountid." result=".$result."\n";
		$this->assertLessThan($result, 0);

		$sql = "SELECT fk_code_ventilation FROM ".MAIN_DB_PREFIX."facturedet WHERE rowid = ".((int) $lineid);
		$resql = $db->query($sql);
		$obj = $db->fetch_object($resql);
		$this->assertEquals($accountid, (int) $obj->fk_code_ventilation);

		return array($lineid, $accountid);
	}

	/**
	 * testUnbindCustomerLine
	 *
	 * @param	array{0:int,1:int}	$args	Array(lineid, accountid)
	 * @return	int		Id of facturedet line
	 *
	 * @depends	testBindCustomerLine
	 * The depends says test is run only if previous is ok
	 */
	public function testUnbindCustomerLine($args)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		list($lineid) = $args;

		$localobject = new AccountingAccount($db);
		$result = $localobject->unbindInvoiceLine($lineid, 'customer', $user);
		print __METHOD__." lineid=".$lineid." result=".$result."\n";
		$this->assertLessThan($result, 0);

		$sql = "SELECT fk_code_ventilation FROM ".MAIN_DB_PREFIX."facturedet WHERE rowid = ".((int) $lineid);
		$resql = $db->query($sql);
		$obj = $db->fetch_object($resql);
		// Unbind sentinel must be integer 0, never NULL
		$this->assertSame(0, (int) $obj->fk_code_ventilation);

		return $lineid;
	}

	/**
	 * testBindInvalidType
	 *
	 * @param	int		$lineid		Id of facturedet line
	 * @return	int		Id of facturedet line
	 *
	 * @depends	testUnbindCustomerLine
	 * The depends says test is run only if previous is ok
	 */
	public function testBindInvalidType($lineid)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = new AccountingAccount($db);
		$result = $localobject->bindInvoiceLine($lineid, 1, 'bogus', $user);
		print __METHOD__." lineid=".$lineid." result=".$result."\n";
		$this->assertTrue($result < 0);
		$this->assertNotEmpty($localobject->error);

		return $lineid;
	}

	/**
	 * testBindNegativeAccountIdClampsToUnbind
	 *
	 * @param	int		$lineid		Id of facturedet line
	 * @return	void
	 *
	 * @depends	testBindInvalidType
	 * The depends says test is run only if previous is ok
	 */
	public function testBindNegativeAccountIdClampsToUnbind($lineid)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = new AccountingAccount($db);
		$result = $localobject->bindInvoiceLine($lineid, -5, 'customer', $user);
		print __METHOD__." lineid=".$lineid." result=".$result."\n";
		$this->assertLessThan($result, 0);

		$sql = "SELECT fk_code_ventilation FROM ".MAIN_DB_PREFIX."facturedet WHERE rowid = ".((int) $lineid);
		$resql = $db->query($sql);
		$obj = $db->fetch_object($resql);
		$this->assertSame(0, (int) $obj->fk_code_ventilation);
	}

	/**
	 * testBindSupplierLine
	 *
	 * Minimal supplier-side coverage: create a specimen supplier invoice and exercise a single
	 * bind/unbind pair. The customer-side tests above already cover the shared bindInvoiceLine()
	 * code path in detail.
	 *
	 * @return void
	 */
	public function testBindSupplierLine()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = new FactureFournisseur($db);
		$localobject->initAsSpecimen();
		$facid = $localobject->create($user);
		$this->assertLessThan($facid, 0);

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture_fourn_det WHERE fk_facture_fourn = ".((int) $facid)." ORDER BY rowid";
		$resql = $db->query($sql);
		$obj = $db->fetch_object($resql);
		$this->assertNotEmpty($obj);
		$lineid = (int) $obj->rowid;

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_account ORDER BY rowid";
		$resql = $db->query($sql);
		$obj = $db->fetch_object($resql);
		$this->assertNotEmpty($obj, 'At least one accounting account must exist in the chart of accounts to run this test');
		$accountid = (int) $obj->rowid;

		$localobject2 = new AccountingAccount($db);
		$result = $localobject2->bindInvoiceLine($lineid, $accountid, 'supplier', $user);
		print __METHOD__." bind lineid=".$lineid." accountid=".$accountid." result=".$result."\n";
		$this->assertLessThan($result, 0);

		$sql = "SELECT fk_code_ventilation FROM ".MAIN_DB_PREFIX."facture_fourn_det WHERE rowid = ".((int) $lineid);
		$resql = $db->query($sql);
		$obj = $db->fetch_object($resql);
		$this->assertEquals($accountid, (int) $obj->fk_code_ventilation);

		$result = $localobject2->unbindInvoiceLine($lineid, 'supplier', $user);
		print __METHOD__." unbind lineid=".$lineid." result=".$result."\n";
		$this->assertLessThan($result, 0);

		$sql = "SELECT fk_code_ventilation FROM ".MAIN_DB_PREFIX."facture_fourn_det WHERE rowid = ".((int) $lineid);
		$resql = $db->query($sql);
		$obj = $db->fetch_object($resql);
		$this->assertSame(0, (int) $obj->fk_code_ventilation);
	}
}
