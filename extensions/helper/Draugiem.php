<?php
namespace li3_dr\extensions\helper;

use lithium\g11n\Message;

/**
 * Draugiem.lv helper
 */
class Draugiem extends \lithium\template\Helper {

	protected $_config = array();
	
	protected $_classes = array(
		'DraugiemApi' => 'li3_dr\DraugiemApi'
	);

	/**
	 * Creates a new instance
	 */
	public function __construct(array $config = array()) {
		$defaults = array();
		
		parent::__construct($config + $defaults);
	}

	/**
	 * Initializes the new instance
	 */
	protected function _init() {
		parent::_init();

		$DraugiemApi = $this->_classes['DraugiemApi'];
		$this->_config = $DraugiemApi::config();
	}

	/**
	 * Get URL for Draugiem.lv Passport login page to authenticate user
	 *
	 * @param string $redirect_url URL where user has to be redirected after authorization. The URL has to be in the same domain as URL that has been set in the properties of the application.
	 * @return string URL of Draugiem.lv Passport login page
	 */
	public function getLoginURL ($redirect_url) {
		$hash = md5($this->_config['app_key'] . $redirect_url);//Request checksum
		$link = $this->_config['login_url'] . '?app=' . $this->_config['app_id'] . '&hash=' . $hash . '&redirect=' . urlencode($redirect_url);
		return $link;
	}

	/**
	 * Get HTML for Draugiem.lv Passport login button with Draugiem.lv Passport logo.
	 *
	 * @param string $redirect_url URL where user has to be redirected after authorization. The URL has to be in the same domain as URL that has been set in the properties of the application.
	 * @param boolean $popup Whether to open authorization page within a popup window (true - popup, false - same window).
	 * @return string HTML of Draugiem.lv Passport login button
	 */
	public function getLoginButton($redirect_url, $popup = true) {
		$url = htmlspecialchars($this->getLoginUrl($redirect_url));

		if ($popup) {
			$js = "if(handle=window.open('$url&amp;popup=1','Dr_". $this->_config['app_id'] ."' ,'width=400, height=400, left='+(screen.width?(screen.width-400)/2:0)+', top='+(screen.height?(screen.height-400)/2:0)+',scrollbars=no')){handle.focus();return false;}";
			$onclick = ' onclick="'.$js.'"';
		} else {
			$onclick = '';
		}
		return '<a href="'.$url.'"'.$onclick.'><img border="0" src="http://api.draugiem.lv/authorize/login_button.png" alt="draugiem.lv" /></a>';
	}
	
	/**
	 * Get draugiem.lv domain (usually "www.draugiem.lv" but can be different for international versions of the portal) for current iframe session.
	 *
	 * This function has to be called after getSession()). Domain name should be used when linking to user profiles or other targets within draugiem.lv.
	 *
	 * @return string Draugiem.lv domain name that is currently used by application user
	 */
	public function getSessionDomain() {
		$DraugiemApi = $this->_classes['DraugiemApi'];
		return $DraugiemApi::getSessionDomain();
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
	public function getJavascript($resize_container = false, $callback_html = false) {
		if (!empty($this->_config)) {
			$data = '<script type="text/javascript" src="' . $this->_config['js_url'] . '" charset="utf-8"></script>'."\n";
			$data.= '<script type="text/javascript">'."\n";
			if($resize_container){
				$data.= " var draugiem_container='$resize_container';\n";
			}
			if(!empty($_SESSION['draugiem_domain'])){
				$data.= " var draugiem_domain='" . $this->getSessionDomain() . "';\n";
			}
			if($callback_html){
				$data.= " var draugiem_callback_url='".$callback_html."';\n";
			}
			$data.='</script>'."\n";
			return $data;
		} else {
			return false;
		}
	}
}
?>