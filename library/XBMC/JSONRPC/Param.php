<?php

class XBMC_JSONRPC_Param	 {
	
	/* directly copied attributes:
	 */
	public $ref = null;
	public $default = null;
	public $name = null;
	
	/* references
	 */
	public $type = null; // can be one or an array of (or null if referred).
	
	/* collections
	 */
	private static $attrs = array('$ref', 'default', 'name'); // possible attributes

	
	public function __construct(stdClass $obj) {
		
		// copy values from object
		$this->name = $obj->name;
		$this->default = isset($obj->default) ? $obj->default : null;
		
		$this->parseRef($obj);
		$this->parseType($obj);
	}
	
	private function parseRef($obj) {
		$ref = '$ref';
		if (property_exists($obj, '$ref')) {
			$this->ref = $obj->$ref;
		}
	}
	
	private function parseType($obj) {
		if (!isset($obj->type)) {
			return;
		}
		if (is_array($obj->type)) {
			$this->type = array();
			foreach ($obj->type as $type) {
				$this->type[] = new XBMC_JSONRPC_ParamType($type);
			}
//			print_r($this->type);exit;
		} else {
			$this->type = new XBMC_JSONRPC_ParamType($obj->type);
		}
	}
	
	/**
	 * @return XBMC_JSONRPC_Type
	 */
	public function getType() {
		if (!$this->type && !$this->ref) {
			throw new Exception('Cannot return type if "type" AND reference are unknown.');
		}
		if ($this->ref) {
			if (!array_key_exists($this->ref, XBMC_JSONRPC_Type::$global)) {
				throw new Exception('Cannot find reference "'.$this->ref.'" in types array!');
			}
			return XBMC_JSONRPC_Type::$global[$this->ref];
		} 
		return $this->type;
	}

}