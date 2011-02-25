<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2009, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_dr\tests\cases\security\auth\adapter;

use lithium\action\Request;
use lithium\data\entity\Record;
use lithium\core\Libraries;
use lithium\data\Connections;

use app\models\Member;
use li3_dr\DraugiemApi;
use li3_dr\extensions\adapter\Draugiem;

use li3_dr\tests\mocks\data\MockDraugiemApi;

class DraugiemTest extends \lithium\test\Unit {

	public function setUp() {
		$this->old_dr_lib = Libraries::get('li3_dr');
		$this->config_name = 'test';
		$this->dr_auth_code = '136fc96ce4ffe820b83e';

		Libraries::add('li3_dr', array(
			'config' => array(
				'test' => array(
					'app_id' => '1111',
					'app_key' => '22222222222222222222',
					'api_url' => 'http://localhost/op_dr/tests/draugiem_api/',
					'login_url' => 'http://localhost/op_dr/tests/draugiem_login/',
					'js_url' => 'http://ifrype.com/applications/external/draugiem.js',
					'timeout' => 180
				)
			)
		));

		Member::meta('connection', 'test');
	}

	public function tearDown() {
		Libraries::add('li3_dr', $this->old_dr_lib);
		MockDraugiemApi::clearSession();
		$members = Member::find('all');
		if(count($members)) {
			$members->delete();
		}
	}

	public function testAdapter() {
		$subject = new Draugiem(array(
			'api' => '\li3_dr\tests\mocks\data\MockDraugiemApi',
			'model' => '\app\models\Member',
			'config' => $this->config_name
		));

		/**
		 * 1
		 */
		$request = new Request();
		$request->query = array(
            'dr_auth_status' => 'ok',
            'dr_auth_code' => $this->dr_auth_code,
            'session_hash' => '181479011',
            'domain' => 'www.draugiem.lv'
		);

		$result = $subject->check($request, $request->query, array(
			'checkSession' => false,
			'writeSession' => false
		));

		$expected = array(
			'_id' => '3333333333333333333333333333',
			'name' => 'Testa',
			'surname' => 'Lietotājs',
			'age' => false, 
			'adult' => 1, 
			'img' => 'http://i9.ifrype.com/profile/876/459/v1256785837/sm_56789.jpg',
			'sex' => 'M'
		);

		$this->assertEqual($expected, $result);
		MockDraugiemApi::clearSession();

		/**
		 * 2
		 */
		$request = new Request();
		$request->query = array(
            'dr_auth_status' => 'error'
		);

		$this->expectException(true);
		$result = $subject->check($request, $request->query, array(
			'checkSession' => false,
			'writeSession' => false
		));
		MockDraugiemApi::clearSession();

		/**
		 * 3
		 */
		$request = new Request();
		$request->query = array(
            'dr_auth_status' => 'ok',
            'dr_auth_code' => $this->dr_auth_code . '4432',
            'session_hash' => '181479011',
            'domain' => 'www.draugiem.lv'
		);

		$this->expectException(true);
		$result = $subject->check($request, $request->query, array(
			'checkSession' => false,
			'writeSession' => false
		));
	}

	public function testLogin() {
		/**
		 * 1
		 */
		$request = new Request();
		$request->query = array(
            'dr_auth_status' => 'ok',
            'dr_auth_code' => $this->dr_auth_code,
            'session_hash' => '181479011',
            'domain' => 'www.draugiem.lv'
		);

		$result = \lithium\security\Auth::check('draugiem', $request, array(
			'checkSession' => false,
			'writeSession' => false
		));
		
		$this->assertEqual('Lietotājs', $result['surname']);
		MockDraugiemApi::clearSession();

		/**
		 * 2
		 */
		$request = new Request();
		$request->query = array();

		$this->expectException(true);
		$result = \lithium\security\Auth::check('draugiem', $request, array(
			'checkSession' => false,
			'writeSession' => false
		));
	}

	public function testGetUserKey() {
		$result = MockDraugiemApi::getUserKey();
		$expected = '3333333333333333333333333333';
		$this->assertEqual($expected, $result);
	}

	public function testGetUserLanguage() {
		$result = MockDraugiemApi::getUserLanguage();
		$expected = 'lv';
		$this->assertEqual($expected, $result);
	}

	public function testGetUserId() {
		$result = MockDraugiemApi::getUserId();
		$expected = '12345';
		$this->assertEqual($expected, $result);
	}

	public function testGetUserData() {
		$result = MockDraugiemApi::getUserData();
		$expected = array(
			'uid' => '12345',
			'name' => 'Testa',
			'surname' => 'Lietotājs',
			'nick' => '',
			'place' => '',
			'age' => false,
			'adult' => 1,
			'img' => 'http://i9.ifrype.com/profile/876/459/v1256785837/sm_56789.jpg',
			'sex' => 'M'
		);
		$this->assertEqual($expected, $result);
	}

	public function testImageForSize() {
		$result = MockDraugiemApi::imageForSize('http://i9.ifrype.com/profile/876/459/v1256785837/sm_56789.jpg', 'medium');
		$expected = 'http://i9.ifrype.com/profile/876/459/v1256785837/m_56789.jpg';
		$this->assertEqual($expected, $result);
	}

	public function testCheckFriendship() {
		$result = MockDraugiemApi::checkFriendship(12345, 54321);
		$expected = 'OK';
		$this->assertEqual($expected, $result);
	}
	
	public function testGetFriendCount() {
		$result = MockDraugiemApi::getFriendCount();
		$expected = '537';
		$this->assertEqual($expected, $result);
	}

	public function testGetUserFriends() {
		/**
		 * 1
		 */
		$result = MockDraugiemApi::getUserFriends(1, 2, true);
		$expected = array(
			66666,
			77777
		);
		$this->assertEqual($expected, $result);

		/**
		 * 2
		 */
		$result = MockDraugiemApi::getUserFriends(1, 2, false);
		$expected = array(
			66666 => array(
				'uid' => '66666',
				'name' => 'Pirmais',
				'surname' => 'Lietotājs',
				'nick' => '',
				'place' => '',
				'age' => false,
				'adult' => 1,
				'img' => 'http://i9.ifrype.com/profile/876/459/v1256785837/sm_56789.jpg',
				'sex' => 'F'
			),
			77777 => array(
				'uid' => '77777',
				'name' => 'Otrais',
				'surname' => 'Lietotājs',
				'nick' => '',
				'place' => '',
				'age' => false,
				'adult' => 1,
				'img' => 'http://i9.ifrype.com/profile/876/459/v1256785837/sm_56789.jpg',
				'sex' => 'M'
			)
		);
		$this->assertEqual($expected, $result);
	}

	public function testGetOnlineFriends() {
		/**
		 * 1
		 */
		$result = MockDraugiemApi::getOnlineFriends(1, 2, true);
		$expected = array(
			66666,
			77777
		);
		$this->assertEqual($expected, $result);

		/**
		 * 2
		 */
		$result = MockDraugiemApi::getOnlineFriends(1, 2, false);
		$expected = array(
			66666 => array(
				'uid' => '66666',
				'name' => 'Pirmais',
				'surname' => 'Lietotājs',
				'nick' => '',
				'place' => '',
				'age' => false,
				'adult' => 1,
				'img' => 'http://i9.ifrype.com/profile/876/459/v1256785837/sm_56789.jpg',
				'sex' => 'F'
			),
			77777 => array(
				'uid' => '77777',
				'name' => 'Otrais',
				'surname' => 'Lietotājs',
				'nick' => '',
				'place' => '',
				'age' => false,
				'adult' => 1,
				'img' => 'http://i9.ifrype.com/profile/876/459/v1256785837/sm_56789.jpg',
				'sex' => 'M'
			)
		);
		$this->assertEqual($expected, $result);
	}

	public function testGetUserCount() {
		$result = MockDraugiemApi::getUserCount();
		$expected = '48';
		$this->assertEqual($expected, $result);
	}

	public function testGetAppUsers() {
		/**
		 * 1
		 */
		$result = MockDraugiemApi::getAppUsers(1, 2, true);
		$expected = array(
			66666,
			77777
		);
		$this->assertEqual($expected, $result);

		/**
		 * 2
		 */
		$result = MockDraugiemApi::getAppUsers(1, 2, false);
		$expected = array(
			66666 => array(
				'uid' => '66666',
				'name' => 'Pirmais',
				'surname' => 'Lietotājs',
				'nick' => '',
				'place' => '',
				'age' => false,
				'adult' => 1,
				'img' => 'http://i9.ifrype.com/profile/876/459/v1256785837/sm_56789.jpg',
				'sex' => 'F'
			),
			77777 => array(
				'uid' => '77777',
				'name' => 'Otrais',
				'surname' => 'Lietotājs',
				'nick' => '',
				'place' => '',
				'age' => false,
				'adult' => 1,
				'img' => 'http://i9.ifrype.com/profile/876/459/v1256785837/sm_56789.jpg',
				'sex' => 'M'
			)
		);
		$this->assertEqual($expected, $result);
	}

	public function testGetLoginURL() {
		$redirect_url = 'http://testapp';
		$config = Libraries::get('li3_dr');
		$app_key = $config['config'][$this->config_name]['app_key'];
		$result = MockDraugiemApi::getLoginURL($redirect_url);
		$expected = 'http://localhost/op_dr/tests/draugiem_login/?app=1111&hash=' . md5($app_key . $redirect_url) . '&redirect=' . urlencode($redirect_url);
		$this->assertEqual($expected, $result);
	}

	/**
	 * Tests that `Form::set()` passes data through unmodified, even with invalid options.
	 *
	 * @return void
	 */
	public function testSetPassthru() {
		$subject = new Draugiem(array(
			'model' => __CLASS__
		));
		
		$user = array(
			'id' => 5,
			'name' => 'bob'
		);

		$result = $subject->set($user);
		$this->assertIdentical($user, $result);
	}
}

?>