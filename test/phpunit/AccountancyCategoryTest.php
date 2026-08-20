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
 *
 * \file       test/phpunit/AccountancyCategoryTest.php
 * \ingroup    test
 * \brief      PHPUnit test
 *	\remarks    To run this script as CLI:  phpunit filename.php
 */

global $conf, $user, $langs, $db;
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/accountancycategory.class.php';
require_once dirname(__FILE__).'/../../htdocs/accountancy/class/accountingaccount.class.php';
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
class AccountancyCategoryTest extends CommonClassTest
{
	/**
	 * testAccountancyCategoryCreate
	 *
	 * AccountancyCategory::create() used to return whatever $this->id happened to already hold
	 * (it was never assigned from the insert id) instead of the new row's id — asserting a
	 * strictly positive, freshly-issued id here covers that fix.
	 *
	 * @return int		the ID of the created object
	 */
	public function testAccountancyCategoryCreate(): int
	{
		global $user, $db;

		$category = new AccountancyCategory($db);
		$category->code = 'ACJCATUNITTEST';
		$category->label = 'Unittest category';
		$category->range_account = '';
		$category->sens = 0;
		$category->category_type = 0;
		$category->formula = '';
		$result = $category->create($user);

		print __METHOD__." result=".$result." id=".$category->id."\n";
		$this->assertGreaterThan(0, $result, 'Cannot create AccountancyCategory: '.$category->error);
		$this->assertEquals($result, $category->id, 'create() must return the same id it set on the object');

		return $category->id;
	}

	/**
	 * testAccountancyCategoryFetch
	 *
	 * @param	int		$id		Id of category entry
	 * @return	AccountancyCategory		Loaded record object
	 *
	 * @depends	testAccountancyCategoryCreate
	 */
	public function testAccountancyCategoryFetch($id)
	{
		global $db;

		$category = new AccountancyCategory($db);
		$result = $category->fetch($id);
		print __METHOD__." id=".$id." result=".$result."\n";
		$this->assertGreaterThan(0, $result);
		$this->assertEquals('ACJCATUNITTEST', $category->code);
		$this->assertEquals('Unittest category', $category->label);

		return $category;
	}

	/**
	 * testAccountancyCategoryUpdate
	 *
	 * @param	AccountancyCategory	$category	Object from testAccountancyCategoryFetch
	 * @return	AccountancyCategory				Updated record object
	 *
	 * @depends	testAccountancyCategoryFetch
	 */
	public function testAccountancyCategoryUpdate($category)
	{
		global $db, $user;

		$category->label = 'Unittest category - updated';
		$result = $category->update($user);
		print __METHOD__." id=".$category->id." result=".$result."\n";
		$this->assertGreaterThan(0, $result, 'Cannot update AccountancyCategory: '.$category->error);

		$check = new AccountancyCategory($db);
		$check->fetch($category->id);
		$this->assertEquals('Unittest category - updated', $check->label);
		$this->assertEquals('ACJCATUNITTEST', $check->code);

		return $category;
	}

	/**
	 * testAccountancyCategoryAssignment
	 *
	 * Round-trips updateAccAcc()/getCptsCat()/deleteCptCat() against a fixture accounting
	 * account on the currently active chart of accounts (CHARTOFACCOUNTS).
	 *
	 * @param	AccountancyCategory	$category	Object from testAccountancyCategoryUpdate
	 * @return	AccountancyCategory				Same record object, unchanged
	 *
	 * @depends	testAccountancyCategoryUpdate
	 */
	public function testAccountancyCategoryAssignment($category)
	{
		global $db, $user, $conf;

		$pcgversion = dol_getIdFromCode($db, (string) getDolGlobalInt('CHARTOFACCOUNTS'), 'accounting_system', 'rowid', 'pcg_version');
		$this->assertNotEmpty($pcgversion, 'No active chart of accounts (CHARTOFACCOUNTS) configured in test DB');

		$account = new AccountingAccount($db);
		$account->fk_pcg_version = $pcgversion;
		$account->account_number = '2960001';
		$account->label = 'AccountancyCategoryTest fixture account';
		$account->active = 1;
		$accountResult = $account->create($user);
		$this->assertGreaterThan(0, $accountResult, 'Cannot create fixture AccountingAccount: '.$account->error);

		require_once dirname(__FILE__).'/../../htdocs/core/lib/accounting.lib.php';
		$formatted = length_accountg($account->account_number);

		$assignResult = $category->updateAccAcc($category->id, array($formatted => "'".$formatted."'"));
		$this->assertGreaterThan(0, $assignResult, 'updateAccAcc failed: '.$category->error);

		$assigned = $category->getCptsCat($category->id);
		$this->assertIsArray($assigned);
		$this->assertCount(1, $assigned);
		$this->assertEquals($account->id, $assigned[0]['id']);
		$this->assertEquals($account->account_number, $assigned[0]['account_number']);

		$unassignResult = $category->deleteCptCat($account->id);
		$this->assertGreaterThan(0, $unassignResult, 'deleteCptCat failed: '.$category->error);

		$assignedAfter = $category->getCptsCat($category->id);
		$this->assertIsArray($assignedAfter);
		$this->assertCount(0, $assignedAfter);

		return $category;
	}

	/**
	 * testAccountancyCategoryDelete
	 *
	 * @param	AccountancyCategory	$category	Object from testAccountancyCategoryAssignment
	 * @return	void
	 *
	 * @depends	testAccountancyCategoryAssignment
	 */
	public function testAccountancyCategoryDelete($category)
	{
		global $db, $user;

		$id = $category->id;
		$result = $category->delete($user);
		print __METHOD__." id=".$id." result=".$result."\n";
		$this->assertGreaterThan(0, $result, 'Cannot delete AccountancyCategory: '.$category->error);

		$check = new AccountancyCategory($db);
		$check->fetch($id);
		$this->assertEmpty($check->id);
	}
}
