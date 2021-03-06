<?php

date_default_timezone_set("CET");

/*
 * An autoloader to load the Mollie Payment Module classes.
 */
spl_autoload_register(function($className) {

	$filename = dirname(dirname(__FILE__)) . "/app/code/community/" . str_replace("_", DIRECTORY_SEPARATOR, $className) . '.php';

	if ($className === "Mollie_Mpm_IdlController")
	{
		$filename = str_replace("IdlController", "controllers/IdlController", $filename);
	}

	if (file_exists($filename))
	{
		include $filename;
	}
});

/**
 * This autoloader will create empty classes of Mage_ or Varien_ classes.
 */
spl_autoload_register(function($className) {

	if (strpos($className, "Mage_") === 0 || strpos($className, "Varien_") === 0)
	{
		if (strstr($className, "Interface"))
		{
			eval("interface $className {}");
		}
		else
		{
			eval("abstract class $className {}");
		}
	}
});

/**
 * A special testcase that enables mocking of the static Mage methods.
 *
 * If you want to mock a method of the Mage class, mock it on the $mage property.
 */
abstract class MagentoPlugin_TestCase extends PHPUnit_Framework_TestCase
{
	/**
	 * @var PHPUnit_Framework_MockObject_MockObject
	 */
	protected $mage;

	protected function setUp()
	{
		$this->mage = $this->getMock("stdClass", array("Helper", "getModel", "log", "throwException", "getSingleton", "getStoreConfig", "getUrl", "getConfig", "getBaseUrl"));
		Mage::setImplementation($this->mage);
	}
}

/**
 * Mocked Mage.
 *
 * @ignore
 * @codeCoverageIgnore
 */
class Mage
{
	public static function setImplementation(PHPUnit_Framework_MockObject_MockObject $mock)
	{
		self::$mock = $mock;
	}

	/**
	 * @var PHPUnit_Framework_MockObject_MockObject
	 */
	public static $mock;

	/**
	 * Redirect all static calls on this class to the mock object.
	 *
	 * @static
	 *
	 * @param $method
	 * @param array $args
	 *
	 * @return mixed
	 */
	public static function __callStatic($method, array $args)
	{
		return call_user_func_array(array(self::$mock, $method), $args);
	}

}

/**
 * @codeCoverageIgnore
 * @ignore
 */
class Mage_Payment_Model_Method_Abstract
{
	public function __construct () {}

	public function isAvailable()
	{
		return TRUE;
	}

	public function canUseForCountry ()
	{
		return TRUE;
	}

	public function canUseForCurrency($currencyCode)
	{
		return TRUE;
	}
}

class Mage_Sales_Model_Order
{
	/**
	 * Order states
	 */
	const STATE_NEW             = 'new';
	const STATE_PENDING_PAYMENT = 'pending_payment';
	const STATE_PROCESSING      = 'processing';
	const STATE_COMPLETE        = 'complete';
	const STATE_CLOSED          = 'closed';
	const STATE_CANCELED        = 'canceled';
	const STATE_HOLDED          = 'holded';
	const STATE_PAYMENT_REVIEW  = 'payment_review';

	/**
	 * Order statuses
	 */
	const STATUS_FRAUD  = 'fraud';
}

class Mage_Core_Controller_Front_Action {
	public function _construct() {}

	public function __($arg) { return $arg;}
}

class Mage_Sales_Model_Order_Payment_Transaction {
	const TYPE_PAYMENT = 'payment';
	const TYPE_ORDER   = 'order';
	const TYPE_AUTH    = 'authorization';
	const TYPE_CAPTURE = 'capture';
	const TYPE_VOID    = 'void';
	const TYPE_REFUND  = 'refund';
}


/**
 * Only here for some testing.
 *
 * @ignore
 */
class Magento_Template
{
	private $data = array();

	public function setData($key, $mixed)
	{
		$this->data[$key] = $mixed;
	}

	public function __call($method, array $arguments)
	{
		if (strpos($method, "get") === 0)
		{

			$method = preg_replace("!^get!", "", $method);

			$method = preg_replace_callback("![a-z]([A-Z])!", function ($matches) {
				return str_replace($matches[1], "_" . $matches[1], $matches[0]);
			}, $method);

			if (isset($data[strtolower($method)]))
			{
				return $data[strtolower($method)];
			}

			return NULL;
		}

		throw new BadMethodCallException("Method does not exist: {$method}.");
	}

	protected function getForm()
	{
		return "/path/to/form";
	}

	public function __($text)
	{
		return $text;
	}

	protected function escapeHtml($plaintext)
	{
		return htmlspecialchars($plaintext, ENT_QUOTES, "UTF-8");
	}

	public function render($filename)
	{
		include PROJECT_ROOT . DIRECTORY_SEPARATOR . $filename;
	}
}

define("PROJECT_ROOT", dirname(dirname(__FILE__)));