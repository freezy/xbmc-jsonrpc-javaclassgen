<?php

class XBMC_JSONRPC_Method {
	
	/* directly copied attributes:
	 */
	public $name;
	public $description;
	public $type;
	
	/* generated references:
	 */
	public $params = array(); // array of XBMC_JSONRPC_Param objects
	/**
	 * Return type
	 * @var XBMC_JSONRPC_Type
	 */
	public $returns;
	
	/* collections
	 */
	private static $global = array(); // all methods referenced
	private static $attrs = array('description', 'params', 'returns', 'type'); // possible attributes

	
	public function __construct($name, $obj) {
		
		print "### $name\n";
		
		// copy values from object
		$this->name = $name;
		$this->type = $obj->type;
		$this->description = $obj->description;
		
		$this->parseParams($obj);
		$this->parseReturns($obj);
		
		print_r($this);
	}

	private function parseParams($obj) {
		if (isset($obj->params)) {
			foreach ($obj->params as $param) {
				$this->params[] = new XBMC_JSONRPC_Param($param);
			}
		}
	}
	private function parseReturns($obj) {
		if (!isset($obj->returns)) {
			throw new Exception('No return type set for method "'.$this->name.'".');
		}
		$this->returns = new XBMC_JSONRPC_Type(0, $this->name.'Result', $obj->returns, true, false);
	}
	
	private function assertKeys(stdClass $obj, array $values) {
		foreach ($obj as $k => $v) {
			if (!in_array($k, $values)) {
				throw new Exception('Key "'.$k.'" should not be in array: '.json_encode($obj));
			} 
		}
	}
	
	/**
	 * This goes through every method and copies the attributes.
	 *  
	 * @param stdClass $methods "methods" node from introspect. 
	 */
	private static function readAll($methods) {
		// first run just reads and sets the attributes of each object.
		foreach ($methods as $name => $method) {
			if ($name != 'AudioLibrary.GetAlbums') {
				continue;	
			}
			$m = new XBMC_JSONRPC_Method($name, $method);
			
			if (array_key_exists($name, self::$global)) {
				throw new Exception('Duplicate name? Method "'.$name.'" is already saved!');
			}
		
			// add to global array
			self::$global[$name] = $m;
		}
	}
	
	/**
	 * Compiles type classes for all methods.
	 * 
	 * @param stdClass $methods "methods" node from introspect. 
	 */
	public static function processAll($types) {
		self::readAll($types);
//		self::referenceAll();
//		self::assertAll();
//		self::printAll();
	}
}