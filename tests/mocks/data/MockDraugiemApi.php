<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2010, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_dr\tests\mocks\data;

class MockDraugiemApi extends \lithium\tests\mocks\data\MockBase {

	public static function __init($config = array()) {
		parent::__init();
	}

	public static function getSession() {
		return true;
	}

	public static function getUserKey() {
		return true;
	}

	public static function getUserId() {
		return true;
	}

	public static function imageForSize() {
		return true;
	}

	public static function getUserData() {
		return array(
			'uid' => '12345',
			'name' => 'Testa',
			'surname' => 'Lietotājs',
			'nick' => '',
			'place' => '',
			'age' => '',
			'adult' => '1',
			'img' => 'http://i9.ifrype.com/profile/876/459/v1256785837/sm_56789.jpg',
			'sex' => 'M'
		);
	}

	public static function getUserLanguage() {
		return true;
	}

	public static function checkFriendship() {
		return true;
	}

	public static function getFriendCount() {
		return true;
	}

	public static function getUserFriends() {
		return true;
	}

	public static function getOnlineFriends() {
		return true;
	}

	public static function getUserCount() {
		return true;
	}

	public static function getAppUsers() {
		return true;
	}

	public static function getLoginURL() {
		return true;
	}

	public static function clearSession() {
		return true;
	}

	public static function apiCall($action, $args = array()) {
		return parent::apiCall($action, $args);
	}

	public static function apiRequest($url) {
		pr($url);die;

		switch($request['action']) {
			case 'authorize':
				$users = array(
					'136fc96ce4ffe820b83e' => array(
						'apikey' => '3333333333333333333333333333',
						'uid' => '12345',
						'language' => 'lv',
						'users' => Array (
							'80799' => Array (
								'uid' => '12345',
								'name' => 'Testa',
								'surname' => 'Lietotājs',
								'nick' => '',
								'place' => '',
								'age' => '',
								'adult' => '1',
								'img' => 'http://i9.ifrype.com/profile/876/459/v1256785837/sm_56789.jpg',
								'sex' => 'M',
							)
						)
					)
				);

				if (!empty($request['app'])) {
					if (!empty($users[$request['code']])) {
						echo serialize($users[$request['code']]);
					} else {
						echo serialize(array(
							'error' => array(
								'code' => 1,
								'description' => 'Lietotājs nav reģistrējies aplikācijai'
							)
						));
					}
				} else {
					echo serialize(array(
						'error' => array(
							'code' => 2,
							'description' => 'Nav padota lietotāja aplikācijas atslēga'
						)
					));
				}

				break;

			case 'session_check':
				echo serialize(array(
					'status' => 'OK'
				));
				break;

			case 'check_friendship':
				echo serialize(array(
					'status' => 'OK'
				));
				break;

			case 'app_friends_count':
				echo serialize(array(
					'friendcount' => 537
				));
				break;

			case 'app_friends':
			case 'app_friends_online':
			case 'app_users':

				if(isset($request['show']) && $request['show'] == 'ids') {
					echo serialize(array(
						'total' => 2,
						'userids' => array(
							66666,
							77777
						)
					));
				} else {
					echo serialize(array(
						'total' => 2,
						'users' => array(
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
						)
					));
				}

				break;

			case 'app_users_count':
				echo serialize(array(
					'usercount' => 48
				));
				break;
		}
	}
}

?>