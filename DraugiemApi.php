<?php
namespace li3_dr;

use lithium\util\Set;
use lithium\core\Libraries;
use lithium\storage\Session;
use lithium\action\DispatchException;

/**
 * Draugiem.lv API klase
 *
 * Satur funkcijas, kuras autorizē lietotāju un iegūst pieejamos datus no Draugiem.lv
 */
class DraugiemApi {

	/**
	 * API iestatījumi
	 *
	 * @var array
	 */
	protected static $_config = array();

	/**
	 * Aplikācijas pieprasījuma parametri
	 *
	 * @var array
	 */
	protected static $_request = array();

	/**
	 * Draugiem.lv lietotāja pašreizējās sesijas atslēga
	 *
	 * @var string
	 */
	protected static $_session_key = false;

	/**
	 * Draugiem.lv lietotāja API atslēga
	 *
	 * @var string
	 */
	protected static $_user_key = false;

	/**
	 * Draugiem.lv lietotāja dati
	 *
	 * @var array
	 */
	protected static $_user_info = false;

	/**
	 * Lietotāju skaits, kuri lieto aplikāciju iekš Draugiem.lv
	 *
	 * @var array
	 */
	protected static $_last_total_user_count = array();

	/**
	 * Error code for last failed API request
	 *
	 * @var int
	 */
	protected static $_last_error = 0;

	/**
	 * Error description for last failed API request
	 *
	 * @var int 
	 */
	protected static $_last_error_description = '';

	/**
	 * Ieseto aplikācijas iestatījumus un pašreiz izmantoto iestatījumu identifikatoru
	 */
	public static function __init() {
		$library = Libraries::get('li3_dr');
		$session = Session::read('draugiem_api');

		if (!empty($library['config'])) {
			self::$_config = $library['config'];

			if (!empty($session['draugiem_userkey'])) {
				self::$_user_key = $session['draugiem_userkey'];
			}
		} else {
			throw new DispatchException(
				'Nav norādīti Draugiem.lv aplikācijas iestatījumi'
			);
		}
	}

	/**
	 * Atgriež visu vai daļu no iestatījumiem
	 *
	 * @param string $key
	 * @return mixed
	 */
	public static function config($key = null) {
		if (!empty($key)) {
			if (isset(self::$_config[$key])) {
				return self::$_config[$key];
			}
			return false;
		}

		return self::$_config;
	}

	/**
	 * Load draugiem.lv user data and validate session.
	 *
	 * If session is new, makes API authorize request and loads user data, otherwise
	 * gets user info stored in PHP session. Draugiem.lv session status is revalidated automatically
	 * by performing session_check requests in intervals specified by SESSION_CHECK_TIMEOUT constant.
	 *
	 * @return boolean Returns true on successful authorization or false on failure.
	 */
	public static function getSession(array $request = array()) {
		$session = Session::read('draugiem_api');
		self::cookieFix();

		if (isset($request['dr_auth_status']) && $request['dr_auth_status'] != 'ok') {
			self::clearSession();
		} elseif (isset($request['dr_auth_code']) && (empty($session['draugiem_auth_code']) ||
			$request['dr_auth_code'] != $session['draugiem_auth_code'])) {	// New session authorization

			self::clearSession(); //Delete current session data to prevent overwriting of existing session

			//Get authorization data
			$response = self::apiCall('authorize', array('code' => $request['dr_auth_code']));

			if ($response && isset($response['apikey'])) {//API key received
				//User profile info
				$userData = reset($response['users']);

				if (!empty($userData)) {
					if (!empty($request['session_hash'])) {//Internal application, store session key to recheck if draugiem.lv session is active
						Session::write('draugiem_api.draugiem_lastcheck', time());
						self::$_session_key = $session['draugiem_session'] = $request['session_hash'];

						if (isset($request['domain'])) {//Domain for JS actions
							Session::write('draugiem_api.draugiem_domain', preg_replace('/[^a-z0-9\.]/', '', $request['domain']));
						}

						if (!empty($response['inviter'])) {//Fill invitation info if any
							Session::write('draugiem_api.draugiem_invite', array(
								'inviter' => (int) $response['inviter'],
								'extra' => isset($response['invite_extra']) ? $response['invite_extra'] : false,
							));
						}
					}

					Session::write('draugiem_api.draugiem_auth_code', $request['dr_auth_code']);

					//User API key
					self::$_user_key = $response['apikey'];
					Session::write('draugiem_api.draugiem_userkey', self::$_user_key);

					//User language
					Session::write('draugiem_api.draugiem_language', $response['language']);

					//Profile info
					self::$_user_info = $userData;
					Session::write('draugiem_api.draugiem_user', self::$_user_info);

					return true;//Authorization OK
				}
			}
		} elseif (isset($session['draugiem_user'])) {//Existing session
			//Load data from session
			self::$_user_key = $session['draugiem_userkey'];
			self::$_user_info = $session['draugiem_user'];

			if (isset($session['draugiem_lastcheck'], $session['draugiem_session'])) { //Iframe app session

				if (isset($request['dr_auth_code'], $request['domain'])) {//Fix session domain if changed
					Session::write('draugiem_api.draugiem_domain', preg_replace('/[^a-z0-9\.]/', '', $request['domain']));
				}

				self::$_session_key = $session['draugiem_session'];
				//Session check timeout not reached yet, do not check session
				if ($session['draugiem_lastcheck'] > time() - self::$_config['timeout']) {
					return true;
				} else {//Session check timeout reached, recheck draugiem.lv session status
					$response = self::apiCall('session_check', array('hash' => self::$_session_key));
					if(!empty($response['status']) && $response['status'] == 'OK'){
						Session::write('draugiem_api.draugiem_lastcheck', time());
						return true;
					}
				}
			} else {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get user API key from current session. The function must be called after getSession().
	 *
	 * @return string API key of current user or false if no user has been authorized
	 */
	public static function getUserKey() {
		return self::$_user_key;
	}

	/**
	 * Get language setting of currently authorized user. The function must be called after getSession().
	 *
	 * @return string Two letter country code (lv/ru/en/de/hu/lt)
	 */
	public static function getUserLanguage() {
		$draugiem_language = Session::read('draugiem_api.draugiem_language');
		return !empty($draugiem_language) ? $draugiem_language : 'lv';
	}

	/**
	 * Get draugiem.lv user ID for currently authorized user
	 *
	 * @return int Draugiem.lv user ID of currently authorized user or false if no user has been authorized
	 */
	public static function getUserId() {
		if (self::$_user_key && !self::$_user_info) { //We don't have user data, request
			self::$_user_info = $this->getUserData();
		}
		
		if (isset(self::$_user_info['uid'])) {//We have user data, return uid
			return self::$_user_info['uid'];
		} else {
			return false;
		}
	}

	/**
	 * Return user data for specified Draugiem.lv user IDs
	 *
	 * If a single user ID is passed  to this function, a single user data element is returned.
	 * If an array of user IDs is passed to this function, an array of user data elements is returned.
	 * Function can return only information about users that have authorized the application.
	 *
	 * @param mixed $ids array of user IDs or a single user ID (this argument can also be false. In that case, user data of current user will be returned)
	 * @return array Requested user data items or false if API request has failed
	 */
	public static function getUserData($ids = false) {
		if (is_array($ids)) {//Array of IDs
			$ids = implode(',', $ids);
		} else {//Single ID
			$return_single = true;

			if (self::$_user_info && ($ids == self::$_user_info['uid'] || $ids === false)) {//If we have userinfo of active user, return it immediately
				return self::$_user_info;
			}

			if ($ids !== false) {
				$ids = (int) $ids;
			}
		}

		$response = self::apiCall('userdata', array('ids' => $ids));
		
		if ($response) {
			$userData = $response['users'];
			if (!empty($return_single)) {//Single item requested
				if (!empty($userData)) {//Data received
					return reset($userData);
				} else {//Data not received
					return false;
				}
			} else {//Multiple items requested
				return $userData;
			}
		} else {
			return false;
		}
	}

	/**
	 * Get user profile image URL with different size
	 * @param string $img User profile image URL from API (default size)
	 * @param string $size Desired image size (icon/small/medium/large)
	 */
	public static function imageForSize($img, $size) {
		$sizes = array(
			'icon' => 'i_', //50x50px
			'small'=>'sm_', //100x100px (default)
			'medium' => 'm_', //215px wide
			'large' => 'l_', //710px wide,
			'normal' => 'nm_' //240px wide
		);
		if (isset($sizes[$size])) {
			$img = str_replace('/sm_', '/' . $sizes[$size], $img);
		}
		return $img;
	}

	/**
	 * Check if two application users are friends
	 *
	 * @param int $uid User ID of the first user
	 * @param int $uid2 User ID of the second user (or false to use current user)
	 * @return boolean Returns true if the users are friends, false otherwise
	 */
	public static function checkFriendship($uid, $uid2 = false) {
		$response = self::apiCall('check_friendship', array('uid' => $uid, 'uid2' => $uid2));
		if (isset($response['status']) && $response['status'] == 'OK') {
			return true;
		}
		return false;
	}

	/**
	 * Get number of user friends within application
	 *
	 * To reach better performance, it is recommended to call this function after getUserFriends() call
	 * (in that way, a single API request will be made for both calls).
	 *
	 * @return integer Returns number of friends or false on failure
	 */
	public static function getFriendCount() {
		if (isset(self::$_last_total_user_count['friends'][self::$_user_key])) {
			return self::$_last_total_user_count['friends'][self::$_user_key];
		}
		
		$response = self::apiCall('app_friends_count');
		if (isset($response['friendcount'])) {
			self::$_last_total_user_count['friends'][self::$_user_key] = (int) $response['friendcount'];
			return self::$_last_total_user_count['friends'][self::$_user_key];
		}
		return false;
	}

	/**
	 * Get list of friends of currently authorized user that also use this application.
	 *
	 * @param integer $page Which page of data to return (pagination starts with 1, default value 1)
	 * @param integer $limit Number of users per page (min value 1, max value 200, default value 20)
	 * @param boolean $return_ids Whether to return only user IDs or full profile information (true - IDs, false - full data)
	 * @return array List of user data items/user IDs or false on failure
	 */
	public static function getUserFriends($page = 1, $limit = 20, $return_ids = false) {
		$response = self::apiCall('app_friends', array(
			'show' => ($return_ids ? 'ids' : false),
			'page' => $page,
			'limit' => $limit
		));

		if ($response) {
			self::$_last_total_user_count['friends'][self::$_user_key] = (int) $response['total'];
			if ($return_ids) {
				return $response['userids'];
			} else {
				return $response['users'];
			}
		} else {
			return false;
		}
	}

	/**
	 * Get list of friends of currently authorized user that also use this application and are currently logged in draugiem.lv.
	 * Function available only to integrated applications.
	 *
	 * @param integer $limit Number of users per page (min value 1, max value 100, default value 20)
	 * @param boolean $in_app Whether to return friends that currently use app (true - online in app, false - online in portal)
	 * @param boolean $return_ids Whether to return only user IDs or full profile information (true - IDs, false - full data)
	 * @return array List of user data items/user IDs or false on failure
	 */
	public static function getOnlineFriends($limit = 20, $in_app = false, $return_ids = false) {
		$response = self::apiCall('app_friends_online', array(
			'show' => ($return_ids ? 'ids' : false),
			'in_app' => $in_app,
			'limit' => $limit
		));
		if ($response) {
			if ($return_ids) {
				return $response['userids'];
			} else {
				return $response['users'];
			}
		} else {
			return false;
		}
	}

	/**
	 * Get number of users that have authorized the application
	 *
	 * To reach better performance, it is recommended to call this function after getAppUsers() call
	 * (in that way, a single API request will be made for both calls).
	 *
	 * @return integer Returns number of users or false on failure
	 */
	public static function getUserCount() {
		if (isset(self::$_last_total_user_count['users'])) {
			return self::$_last_total_user_count['users'];
		}
		
		$response = self::apiCall('app_users_count');
		if (isset($response['usercount'])) {
			self::$_last_total_user_count['users'] = (int) $response['usercount'];
			return self::$_last_total_user_count['users'];
		}
		return false;
	}

	/**
	 * Get list of users that have authorized this application.
	 *
	 * @param integer $page Which page of data to return (pagination starts with 1, default value 1)
	 * @param integer $limit Number of users per page (min value 1, max value 200, default value 20)
	 * @param boolean $return_ids Whether to return only user IDs or full profile information (true - IDs, false - full data)
	 * @return array List of user data items/user IDs or false on failure
	 */
	public static function getAppUsers($page = 1, $limit = 20, $return_ids = false) {
		$response = self::apiCall('app_users', array(
			'show' => ($return_ids ? 'ids' : false),
			'page' => $page,
			'limit' => $limit
		));

		if ($response) {
			self::$_last_total_user_count['users'] = (int) $response['total'];
			if ($return_ids) {
				return $response['userids'];
			} else {
				return $response['users'];
			}
		} else {
			return false;
		}
	}

	############################################
	###### Iframe application functions ####
	############################################

	/**
	 * Get draugiem.lv domain (usually "www.draugiem.lv" but can be different for international versions of the portal) for current iframe session.
	 *
	 * This function has to be called after getSession()). Domain name should be used when linking to user profiles or other targets within draugiem.lv.
	 *
	 * @return string Draugiem.lv domain name that is currently used by application user
	 */
	public static function getSessionDomain() {
		$draugiem_domain = Session::read('draugiem_api.draugiem_domain');
		return !empty($draugiem_domain) ? $draugiem_domain : 'www.draugiem.lv';
	}

	/**
	 * Get information about accepted invite from current session.
	 *
	 * If user has just joined the application after accepting an invitation, function returns array
	 * with two elements:
	 * inviter - User ID of the person who sent invitation.
	 * extra - Extra string data attached to invitation (or false if there are no data)
	 * This function has to be called after getSession().
	 *
	 * @return returns array with invitation info or false if no invitation has been accepted.
	 */
	public static function getInviteInfo(){
		$draugiem_invite = Session::read('draugiem_api.draugiem_invite');
		return !empty($draugiem_invite) ? $draugiem_invite : false;
	}

	/**
	 * Workaround for cookie creation problems in iframe with IE and Safari.
	 *
	 * This function has to be called before getSession() and after session_start()
	 */
	public static function cookieFix() {
		$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

		//Set up P3P policy to allow cookies in iframe with IE
		if(strpos($user_agent, 'MSIE')){
			header('P3P:CP="IDC DSP COR ADM DEVi TAIi PSA PSD IVAi IVDi CONi HIS OUR IND CNT"');
		}

		//Workaround for Safari - post a form with Javascript to create session cookie
		if(empty($_COOKIE[session_name()]) && strpos($user_agent,'Safari') && isset($_GET['dr_auth_code']) && !isset($_GET['dr_cookie_fix'])){
	?>
		<html><head><title>Iframe Cookie fix</title></head>
		<body>
			<form name="cookieFix" method="get" action="">
				<?php foreach($_GET as $key=>$val){
					echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($val).'" />';
				} ?>
				<input type="hidden" name="dr_cookie_fix" value="1" />
				<noscript><input type="submit" value="Continue" /></noscript>
			</form>
			<script type="text/javascript">document.cookieFix.submit();</script>
		</body></html>
	<?php
			exit;
		}

	}

	##########################################################
	### Functions available only for approved applications ###
	##########################################################

	/**
	 * Adds entry to current user's profile activity feed. Function available only for selected applications.
	 * Contact api@draugiem.lv to request posting permissions for your application.
	 * @param string $text Link text of the activity
	 * @param string $prefix Text before the activity link
	 * @param string $link Target URL of the activity link (must be in the same domain as application).
	 * @return boolean Returns true if activity was created successfully or false if it was not created (permission was denied or activity posting limit was reached).
	 */
	public static function addActivity($text, $prefix = false, $link = false) {
		$response = self::apiCall('add_activity', array(
			'text' => $text,
			'prefix' => $prefix,
			'link' => $link
		));

		if (!empty($response['status'])) {
			if ($response['status'] == 'OK') {
				return true;
			}
		}
		return false;
	}

	/**
	 * Adds notification to current user's profile news box. Function available only for selected applications.
	 * Contact api@draugiem.lv to request posting permissions for your application.
	 * @param string $text Link text of the notification
	 * @param string $prefix Text before the notification link
	 * @param string $link Target URL of the notification link (must be in the same domain as application).
	 * @param int $creator User ID of the user that created the notification (if it is 0, application name wil be shown as creator)
	 * @return boolean Returns true if notification was created successfully or false if it was not created (permission was denied or posting limit was reached).
	 */
	public static function addNotification($text, $prefix = false, $link = false, $creator = 0, $target_userkey = false) {
		if ($target) {
			self::$_user_key = $target_userkey;
		}

		$response = self::apiCall('add_notification', array(
			'text' => $text,
			'prefix' => $prefix,
			'link' => $link,
			'creator' => $creator
		));
		
		if (!empty($response['status'])) {
			if ($response['status'] == 'OK') {
				return true;
			}
		}
		return false;
	}

	#########################
	### Utility functions ###
	#########################

	/**
	 * Inner function that calls Draugiem.lv API and returns response as an array
	 *
	 * @param string $action API action that has to be called
	 * @param array $args Key/value pairs of additional parameters for the request (excluding app, apikey and action)
	 * @return mixed API response data or false if the request has failed
	 */
	public static function apiCall($action, $args = array()) {
		$url = self::$_config['api_url'] . '?app=' . self::$_config['app_key'];

		if (self::$_user_key) {//User has been authorized
			$url .= '&apikey=' . self::$_user_key;
		}

		$url .= '&action=' . $action;
		
		if (!empty($args)) {
			foreach ($args as $k => $v) {
				if ($v !== false) {
					$url .= '&' . urlencode($k) . '=' . urlencode($v);
				}
			}
		}

		$response = self::apiRequest($url);

		if ($response === false) {//Request failed
			self::$_last_error = 1;
			self::$_last_error_description = 'No response from API server';
			return false;
		}

		$response = unserialize($response);

		if (empty($response)) {//Empty response
			self::$_last_error = 2;
			self::$_last_error_description = 'Empty API response';
			return false;
		} else {
			if (isset($response['error'])) {//API error, fill error attributes
				self::$_last_error = $response['error']['code'];
				self::$_last_error_description = 'API error: ' . $response['error']['description'];
				return false;
			} else {
				return $response;
			}
		}
	}

	/**
	 * Zvans Draugiem
	 *
	 * @param string $url
	 * @return mixed
	 */
	public static function apiRequest($url) {
		return @file_get_contents($url);//Get API response (@ to avoid accidentaly displaying API keys in case of errors)
	}

	/**
	 * Iztīra Draugiem.lv sesijas laikā iesetotos datus
	 */
	public static function clearSession() {
		return Session::delete('draugiem_api');
	}
}
?>