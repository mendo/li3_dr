<?php

namespace li3_dr;

use \Exception;
use \lithium\core\Libraries;
use \lithium\util\Set;

/**
 * Draugiem.lv API klase
 *
 * Satur funkcijas, kuras autorizē lietotāju un iegūst pieejamos datus no Draugiem.lv
 */
class DraugiemApi {
	/**
	 * API modelis
	 *
	 * @var array
	 */
	protected static $model = null;

	/**
	 * Draugiem API iespējamie iestatījumi
	 *
	 * @var array
	 */
	protected static $config = array();

	/**
	 * Pašlaik izmantoto iestatījumu identifikators
	 *
	 * @var string
	 */
	private static $active_config = null;

	/**
	 * Aplikācijas $_GET pieprasījuma dati
	 *
	 * @var array
	 */
	private static $request = array();

	/**
	 * Draugiem.lv lietotāja API atslēga
	 * 
	 * @var string
	 */
	private static $user_key = false;

	/**
	 * Draugiem.lv lietotāja pašreizējās sesijas atslēga
	 *
	 * @var string
	 */
	private static $session_key = false;

	/**
	 * Draugiem.lv lietotāja dati
	 *
	 * @var array
	 */
	private static $userinfo = false;

	/**
	 * Lietotāju skaits, kuri lieto aplikāciju iekš Draugiem.lv
	 *
	 * @var array
	 */
	private static $lastTotal = array();

	/**
	 * Error code for last failed API request
	 *
	 * @var int
	 */
	public static $lastError = 0;

	/**
	 * Error description for last failed API request
	 *
	 * @var int 
	 */
	public static $lastErrorDescription = '';

	/**
	 * Ieseto aplikācijas iestatījumus un pašreiz izmantoto iestatījumu identifikatoru
	 */
	public static function __init() {
		$config = Libraries::get('li3_dr');

		if (!empty($config['config'])) {
			self::$config = $config['config'];
			if (!empty($_SESSION['draugiem_config_name'])) {
				self::$active_config = $_SESSION['draugiem_config_name'];
			}
			if (!empty($_SESSION['draugiem_userkey'])) {
				self::$user_key = $_SESSION['draugiem_userkey'];
			}
		} else {
			throw new \RuntimeException(
				'Nav norādīti Draugiem.lv aplikācijas iestatījumi'
			);
		}
	}

	public static function config($config) {
		extract($config);
		self::$model = $model;
		self::$request = $request;
		self::$active_config = $_SESSION['draugiem_config_name'] = $active_config;
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
	public static function getSession() {
		if (session_id() == '') {
			session_start();
		}

		if (isset(self::$request['dr_auth_status']) && self::$request['dr_auth_status'] != 'ok') {
			self::clearSession();
		} elseif(isset(self::$request['dr_auth_code']) && (empty($_SESSION['draugiem_auth_code']) ||
			self::$request['dr_auth_code'] != $_SESSION['draugiem_auth_code'])) {	// New session authorization

			self::clearSession(); //Delete current session data to prevent overwriting of existing session

			//Get authorization data
			$response = self::apiCall('authorize', array('code' => self::$request['dr_auth_code']));

			if ($response && isset($response['apikey'])) {//API key received
				//User profile info
				$userData = reset($response['users']);

				if (!empty($userData)) {
					if (!empty(self::$request['session_hash'])) {//Internal application, store session key to recheck if draugiem.lv session is active
						$_SESSION['draugiem_lastcheck'] = time();
						self::$session_key = $_SESSION['draugiem_session'] = self::$request['session_hash'];
						if (isset(self::$request['domain'])) {//Domain for JS actions
							$_SESSION['draugiem_domain'] = preg_replace('/[^a-z0-9\.]/', '', self::$request['domain']);
						}
						if (!empty($response['inviter'])) {//Fill invitation info if any
							$_SESSION['draugiem_invite'] = array(
								'inviter' => (int) $response['inviter'],
								'extra' => isset($response['invite_extra']) ? $response['invite_extra'] : false,
							);
						}
					}

					$_SESSION['draugiem_auth_code'] = self::$request['dr_auth_code'];

					//User API key
					self::$user_key = $_SESSION['draugiem_userkey'] = $response['apikey'];
					//User language
					$_SESSION['draugiem_language'] = $response['language'];
					//Profile info
					self::$userinfo = $_SESSION['draugiem_user'] = $userData;
					//Config key
					$_SESSION['draugiem_config_name'] = self::$active_config;

					return true;//Authorization OK
				}
			}
		} elseif(isset($_SESSION['draugiem_user'])) {//Existing session
			//Load data from session
			self::$user_key = $_SESSION['draugiem_userkey'];
			self::$userinfo = $_SESSION['draugiem_user'];

			if (isset($_SESSION['draugiem_lastcheck'], $_SESSION['draugiem_session'])) { //Iframe app session

				if (isset(self::$request['dr_auth_code'], self::$request['domain'])) {//Fix session domain if changed
					$_SESSION['draugiem_domain'] = preg_replace('/[^a-z0-9\.]/', '', self::$request['domain']);
				}

				self::$session_key = $_SESSION['draugiem_session'];
				//Session check timeout not reached yet, do not check session
				if ($_SESSION['draugiem_lastcheck'] > time() - self::$config[self::$active_config]['timeout']) {
					return true;
				} else {//Session check timeout reached, recheck draugiem.lv session status
					$response = self::apiCall('session_check', array('hash' => self::$session_key));
					if(!empty($response['status']) && $response['status'] == 'OK'){
						$_SESSION['draugiem_lastcheck'] = time();
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
		return self::$user_key;
	}

	/**
	 * Get language setting of currently authorized user. The function must be called after getSession().
	 *
	 * @return string Two letter country code (lv/ru/en/de/hu/lt)
	 */
	public static function getUserLanguage() {
		return isset($_SESSION['draugiem_language']) ? $_SESSION['draugiem_language'] : 'lv';
	}

	/**
	 * Get draugiem.lv user ID for currently authorized user
	 *
	 * @return int Draugiem.lv user ID of currently authorized user or false if no user has been authorized
	 */
	public static function getUserId() {
		if (self::$user_key && !self::$userinfo) { //We don't have user data, request
			self::$userinfo = $this->getUserData();
		}
		
		if(isset(self::$userinfo['uid'])){//We have user data, return uid
			return self::$userinfo['uid'];
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

			if (self::$userinfo && ($ids == self::$userinfo['uid'] || $ids === false)) {//If we have userinfo of active user, return it immediately
				return self::$userinfo;
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
			'large' => 'l_', //710px wide
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
		if (isset(self::$lastTotal['friends'][self::$user_key])) {
			return self::$lastTotal['friends'][self::$user_key];
		}
		$response = self::apiCall('app_friends_count');
		if (isset($response['friendcount'])) {
			self::$lastTotal['friends'][self::$user_key] = (int) $response['friendcount'];
			return self::$lastTotal['friends'][self::$user_key];
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
			self::$lastTotal['friends'][self::$user_key] = (int) $response['total'];
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
		if (isset(self::$lastTotal['users'])) {
			return self::$lastTotal['users'];
		}
		$response = self::apiCall('app_users_count');
		if (isset($response['usercount'])) {
			self::$lastTotal['users'] = (int) $response['usercount'];
			return self::$lastTotal['users'];
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
			self::$lastTotal['users'] = (int) $response['total'];
			if ($return_ids) {
				return $response['userids'];
			} else {
				return $response['users'];
			}
		} else {
			return false;
		}
	}

	################################################
	###### Draugiem.lv passport functions ##########
	################################################

	/**
	 * Get URL for Draugiem.lv Passport login page to authenticate user
	 *
	 * @param string $redirect_url URL where user has to be redirected after authorization. The URL has to be in the same domain as URL that has been set in the properties of the application.
	 * @return string URL of Draugiem.lv Passport login page
	 */
	public static function getLoginURL ($redirect_url) {
		$hash = md5(self::$config[self::$active_config]['app_key'] . $redirect_url);//Request checksum
		$link = self::$config[self::$active_config]['login_url'] . '?app=' . self::$config[self::$active_config]['app_id'] . '&hash=' . $hash . '&redirect=' . urlencode($redirect_url);
		return $link;
	}

	/**
	 * Get HTML for Draugiem.lv Passport login button with Draugiem.lv Passport logo.
	 *
	 * @param string $redirect_url URL where user has to be redirected after authorization. The URL has to be in the same domain as URL that has been set in the properties of the application.
	 * @param boolean $popup Whether to open authorization page within a popup window (true - popup, false - same window).
	 * @return string HTML of Draugiem.lv Passport login button
	 */
	public static function getLoginButton($redirect_url, $popup = true) {
		$url = htmlspecialchars($this->getLoginUrl($redirect_url));

		if ($popup) {
		$js = "if(handle=window.open('$url&amp;popup=1','Dr_". self::$config[self::$active_config]['app_id'] ."' ,'width=400, height=400, left='+(screen.width?(screen.width-400)/2:0)+', top='+(screen.height?(screen.height-400)/2:0)+',scrollbars=no')){handle.focus();return false;}";
			$onclick = ' onclick="'.$js.'"';
		} else {
			$onclick = '';
		}
		return '<a href="'.$url.'"'.$onclick.'><img border="0" src="http://api.draugiem.lv/authorize/login_button.png" alt="draugiem.lv" /></a>';
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
		return isset($_SESSION['draugiem_domain']) ? $_SESSION['draugiem_domain'] : 'www.draugiem.lv';
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
		return isset($_SESSION['draugiem_invite']) ? $_SESSION['draugiem_invite'] : false;
	}

	 /**
	  * Get HTML for embedding Javascript code to allow to resize application iframe and perform other actions.
	  *
	  * Javascript code will automatically try to resize iframe according to the height of DOM element with ID that is passed
	  * in $resize_container parameter.
	  *
	  * Function also enables Javascript callback values if $callback_html argument is passed. It has to contain full
	  * address of the copy of callback.html on the application server (e.g. http://example.com/callback.html).
	  * Original can be found at http://www.draugiem.lv/applications/external/callback.html
	  *
	  * This function has to be called after getSession().
	  *
	  * @param string $resize_container DOM element ID of page container element
	  * @param string $callback_html address of callback.html Optional if no return values for Javascript API functions are needed.
	  * @return string HTML code that needs to be displayed to embed Draugiem.lv Javascript
	  */
	public static function getJavascript($resize_container = false, $callback_html = false) {
		$data = '<script type="text/javascript" src="'.self::$config[self::$active_config]['js_url'].'" charset="utf-8"></script>'."\n";
		$data.= '<script type="text/javascript">'."\n";
		if($resize_container){
			$data.= " var draugiem_container='$resize_container';\n";
		}
		if(!empty($_SESSION['draugiem_domain'])){
			$data.= " var draugiem_domain='".self::getSessionDomain()."';\n";
		}
		if($callback_html){
			$data.= " var draugiem_callback_url='".$callback_html."';\n";
		}
		$data.='</script>'."\n";
		return $data;
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
			self::$user_key = $target_userkey;
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
		$url = self::$config[self::$active_config]['api_url'] . '?app=' . self::$config[self::$active_config]['app_key'];

		if (self::$user_key) {//User has been authorized
			$url .= '&apikey=' . self::$user_key;
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
			self::$lastError = 1;
			self::$lastErrorDescription = 'No response from API server';
			return false;
		}

		$response = unserialize($response);

		if (empty($response)) {//Empty response
			self::$lastError = 2;
			self::$lastErrorDescription = 'Empty API response';
			return false;
		} else {
			if (isset($response['error'])) {//API error, fill error attributes
				self::$lastError = $response['error']['code'];
				self::$lastErrorDescription = 'API error: '.$response['error']['description'];
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
		unset(
			$_SESSION['draugiem_auth_code'],
			$_SESSION['draugiem_session'],
			$_SESSION['draugiem_userkey'],
			$_SESSION['draugiem_user'],
			$_SESSION['draugiem_lastcheck'],
			$_SESSION['draugiem_language'],
			$_SESSION['draugiem_domain'],
			$_SESSION['draugiem_invite'],
			$_SESSION['draugiem_config_name']
		);
	}
}
?>