<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2011, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

use lithium\core\ErrorHandler;
use lithium\analysis\Logger;
use lithium\core\Environment;
use lithium\action\Response;
use lithium\net\http\Media;

Logger::config(array(
	'error' => array(
		'adapter' => 'File'
	)
));

ErrorHandler::apply('lithium\action\Dispatcher::run', array(), function($info, $params) {
	Logger::write('error', "{$info['file']} : {$info['line']} : {$info['message']}");

	$env = Environment::get();

	if (in_array($env, array('test', 'development'))) {
		return $info['exception']->getMessage();
	}

	return;
});
?>