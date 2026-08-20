<?php
/* Copyright (C) 2010 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2023 Alexandre Janniaux   <alexandre.janniaux@gmail.com>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
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
 *      \file       test/phpunit/ProductTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

global $conf,$user,$langs,$db;
//define('TEST_DB_FORCE_TYPE','mysql');	// This is to force using mysql driver
//require_once 'PHPUnit/Autoload.php';
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/product/class/product.class.php';
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
 * @remarks backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class ProductTest extends CommonClassTest
{
	/**
	 * setUpBeforeClass
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		global $conf,$user,$langs,$db;

		if (!isModEnabled('product')) {
			print __METHOD__." Module Product must be enabled.\n";
			die(1);
		}

		$db->begin(); // This is to have all actions inside a transaction even if test launched without suite.

		print __METHOD__."\n";
	}


	/**
	 * testProductCreate
	 *
	 * @return  void
	 */
	public function testProductCreate()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = new Product($db);
		$localobject->initAsSpecimen();
		$result = $localobject->create($user);

		print __METHOD__." result=".$result."\n";
		$this->assertLessThanOrEqual($result, 0, "Creation of product");

		return $result;
	}

	/**
	 * testProductFetch
	 *
	 * @param   int $id     Id product
	 * @return  Product
	 *
	 * @depends testProductCreate
	 * The depends says test is run only if previous is ok
	 */
	public function testProductFetch($id)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = new Product($db);
		$result = $localobject->fetch($id);
		print __METHOD__." id=".$id." result=".$result."\n";
		$this->assertLessThan($result, 0);

		return $localobject;
	}

	/**
	 * testProductUpdate
	 *
	 * @param   Product $localobject    Product
	 * @return  void
	 *
	 * @depends testProductFetch
	 * The depends says test is run only if previous is ok
	 */
	public function testProductUpdate($localobject)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject->note_public = 'New public note after update';
		$localobject->note_private = 'New private note after update';
		$result = $localobject->update($localobject->id, $user);
		print __METHOD__." id=".$localobject->id." result=".$result."\n";
		$this->assertLessThan($result, 0, 'Error '.$localobject->error);

		return $localobject;
	}

	/**
	 * testProductOther
	 *
	 * @param   Product $localobject    Product
	 * @return  void
	 *
	 * @depends	testProductUpdate
	 * The depends says test is run only if previous is ok
	 */
	public function testProductOther($localobject)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$this->assertEquals(0, 0);

		return $localobject->id;
	}

	/**
	 * testProductDelete
	 *
	 * @param       int $id     Id of product
	 * @return      void
	 *
	 * @depends testProductOther
	 * The depends says test is run only if previous is ok
	 */
	public function testProductDelete($id)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = new Product($db);
		$result = $localobject->fetch($id);

		$result = $localobject->delete($user);
		print __METHOD__." id=".$id." result=".$result."\n";
		$this->assertLessThan($result, 0);

		return $result;
	}

	/**
	 * testProductAccountancyCodePerEntityShared
	 *
	 * Product::create()/update()/fetch() must route the 6 accountancy_code_* fields to
	 * llx_product_perentity (scoped by fk_product+entity), not llx_product, whenever
	 * MAIN_PRODUCT_PERENTITY_SHARED is on, and back to llx_product when it's off (default).
	 * Deliberately not chained onto the create/fetch/update/delete tests above via a
	 * dependency annotation: self-contained so it doesn't interfere with the shared fixture
	 * id those tests pass along.
	 *
	 * @return  void
	 */
	public function testProductAccountancyCodePerEntityShared()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$savPerentityShared = getDolGlobalString('MAIN_PRODUCT_PERENTITY_SHARED');

		try {
			// --- MAIN_PRODUCT_PERENTITY_SHARED on: accountancy codes must land in product_perentity ---
			$conf->global->MAIN_PRODUCT_PERENTITY_SHARED = '1';

			$localobject = new Product($db);
			$localobject->ref = 'PHPUNIT-PERENTITY-'.time();
			$localobject->label = 'PHPUnit per-entity-shared accountancy code test';
			$localobject->type = Product::TYPE_PRODUCT;
			$localobject->accountancy_code_buy = 'BUY1';
			$localobject->accountancy_code_sell = 'SELL1';
			$result = $localobject->create($user);
			print __METHOD__." create result=".$result."\n";
			$this->assertGreaterThan(0, $result, 'Error '.$localobject->error);
			$id = $localobject->id;

			$sql = "SELECT accountancy_code_buy, accountancy_code_sell FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".((int) $id);
			$resql = $db->query($sql);
			$obj = $db->fetch_object($resql);
			$this->assertSame('', (string) $obj->accountancy_code_buy, 'llx_product.accountancy_code_buy must stay empty when MAIN_PRODUCT_PERENTITY_SHARED is on');

			$sql = "SELECT accountancy_code_buy, accountancy_code_sell FROM ".MAIN_DB_PREFIX."product_perentity WHERE fk_product = ".((int) $id)." AND entity = ".((int) $conf->entity);
			$resql = $db->query($sql);
			$this->assertEquals(1, $db->num_rows($resql), 'Expected exactly one product_perentity row for this product+entity');
			$obj = $db->fetch_object($resql);
			$this->assertSame('BUY1', $obj->accountancy_code_buy);
			$this->assertSame('SELL1', $obj->accountancy_code_sell);

			$fetched = new Product($db);
			$fetched->fetch($id);
			$this->assertSame('BUY1', $fetched->accountancy_code_buy, 'fetch() must read accountancy codes back from product_perentity');
			$this->assertSame('SELL1', $fetched->accountancy_code_sell);

			// update() must upsert (not duplicate) the product_perentity row
			$fetched->accountancy_code_buy = 'BUY2';
			$fetched->accountancy_code_sell = 'SELL2';
			$result = $fetched->update($id, $user);
			print __METHOD__." update result=".$result."\n";
			$this->assertGreaterThan(0, $result, 'Error '.$fetched->error);

			$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."product_perentity WHERE fk_product = ".((int) $id)." AND entity = ".((int) $conf->entity);
			$resql = $db->query($sql);
			$obj = $db->fetch_object($resql);
			$this->assertEquals(1, $obj->nb, 'update() must upsert the existing product_perentity row, not insert a second one');

			$refetched = new Product($db);
			$refetched->fetch($id);
			$this->assertSame('BUY2', $refetched->accountancy_code_buy);
			$this->assertSame('SELL2', $refetched->accountancy_code_sell);

			// --- MAIN_PRODUCT_PERENTITY_SHARED off (default): regression check, base table still used ---
			$conf->global->MAIN_PRODUCT_PERENTITY_SHARED = '0';

			$defobject = new Product($db);
			$defobject->ref = 'PHPUNIT-DEFAULT-'.time();
			$defobject->label = 'PHPUnit default-mode accountancy code test';
			$defobject->type = Product::TYPE_PRODUCT;
			$defobject->accountancy_code_buy = 'DBUY1';
			$result = $defobject->create($user);
			print __METHOD__." create (default mode) result=".$result."\n";
			$this->assertGreaterThan(0, $result, 'Error '.$defobject->error);

			$sql = "SELECT accountancy_code_buy FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".((int) $defobject->id);
			$resql = $db->query($sql);
			$obj = $db->fetch_object($resql);
			$this->assertSame('DBUY1', $obj->accountancy_code_buy, 'llx_product.accountancy_code_buy must be used when MAIN_PRODUCT_PERENTITY_SHARED is off');

			$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."product_perentity WHERE fk_product = ".((int) $defobject->id);
			$resql = $db->query($sql);
			$obj = $db->fetch_object($resql);
			$this->assertEquals(0, $obj->nb, 'No product_perentity row should be created when MAIN_PRODUCT_PERENTITY_SHARED is off');
		} finally {
			$conf->global->MAIN_PRODUCT_PERENTITY_SHARED = $savPerentityShared;
		}
	}
}
