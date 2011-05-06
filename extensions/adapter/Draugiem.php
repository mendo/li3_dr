<?php
namespace li3_dr\extensions\adapter;

use \Exception;
use \lithium\storage\Session;
use \lithium\core\Libraries;

/**
 * Draugiem.lv autorizācijas adapteris
 *
 * Atrodās iekš un izmanto DraugiemApi bibliotēku.
 * Adaptera izmantotajai query funkcijai jāsagaida viens parametrs:
 *	- user_data		Draugiem.lv lietotāja dati
 */
class Draugiem extends \lithium\core\Object {
	/**
	 * API nosaukums
	 *
	 * @var string
	 */
	protected $_api = '';

	/**
	 * Autorizācijas modeļa nosaukums
	 * 
	 * @var string
	 */
	protected $_model = '';

	/**
	 * Autorizācijas metodes nosaukums
	 * 
	 * @var string
	 */
	protected $_query = '';

	/**
	 * Aplikācijas konfigurācijas identifikators
	 *
	 * @var string
	 */
	protected $_config = '';

	/**
	 * List of configuration properties to automatically assign to the properties of the adapter
	 * when the class is constructed.
	 *
	 * @var array
	 */
	protected $_autoConfig = array(
		'api',
		'model',
		'query',
		'config'
	);

	/**
	 * Dynamic class dependencies.
	 *
	 * @var array Associative array of class names & their namespaces.
	 */
	protected static $_classes = array(
		'session' => '\lithium\storage\Session'
	);

	/**
	 * Ieseto adaptera sākumkonfigurāciju
	 *
	 * @param array $config
	 */
	public function __construct(array $config = array()) {
		$defaults = array(
			'api' => '\li3_dr\DraugiemApi',
			'model' => 'Member',
			'query' => 'draugiemLogin'
		);
		parent::__construct($config + $defaults);
	}

	/**
	 * Pārbauda vai Draugiem.lv sesija ir aktīva
	 *
	 * Ja sesija ir aktīva, tad atgriež datus, ja nav aktīva, tad mēģina reģistrēt.
	 *
	 * @param array $data Aplikācijas $_GET pieprasjums
	 * @param array $options Papildus parametri
	 * @return mixed Lietotāja dati vai boolean false
	 */
	public function check($data, array $options = array()) {
		$model = $this->_model;
		$query = $this->_query;
		$api = $this->_api;
		$session = static::$_classes['session'];

		$api::cookieFix();

		$api::config(array(
			'active_config' => $this->_config,
			'request' => $data->query,
			'model' => $api
		));

		if ($api::getSession()) {
			$user = $model::$query($api::getUserKey(), $api::getUserData());
		} else {
			throw new \RuntimeException(
				'Nevar savienoties ar Draugiem.lv'
			);
		}

		return !empty($user) ? $user->data() : false;
	}

	protected function _init() {
		parent::_init();

		if (isset($this->_fields[0])) {
			$this->_fields = array_combine($this->_fields, $this->_fields);
		}
		$this->_model = Libraries::locate('models', $this->_model);
	}

	/**
	 * A pass-through method called by `Auth`. Returns the value of `$data`, which is written to
	 * a user's session. When implementing a custom adapter, this method may be used to modify or
	 * reject data before it is written to the session.
	 *
	 * @param array $data User data to be written to the session.
	 * @param array $options Adapter-specific options. Not implemented in the `Form` adapter.
	 * @return array Returns the value of `$data`.
	 */
	public function set($data, array $options = array()) {
		return $data;
	}

	/**
	 * Called by `Auth` when a user session is terminated. Not implemented in the `Form` adapter.
	 *
	 * @param array $options Adapter-specific options. Not implemented in the `Form` adapter.
	 * @return void
	 */
	public function clear(array $options = array()) {
		$api = $this->_api;
		$api::clearSession();
	}
}
?>