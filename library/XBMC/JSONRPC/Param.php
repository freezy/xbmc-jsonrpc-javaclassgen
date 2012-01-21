<?php

class XBMC_JSONRPC_Param {
	
	/* directly copied attributes:
	 */
	public $ref = null;
	public $default = null;
	public $name = null;
	public $description = null;
	
	/* references
	 */
	public $type = null; // can be one or an array of (or null if referred).
	
	/* helper attributes
	 */
	public $isArray = false;
	
	/* collections
	 */
	private static $attrs = array('$ref', 'default', 'name'); // possible attributes

	
	public function __construct(stdClass $obj) {
		if (!is_object($obj)) {
			throw new Exception('"'.$obj.'" is not an object.');
		}
		
		// copy values from object
		$this->name = $obj->name;
		$this->default = isset($obj->default) ? $obj->default : null;
		$this->description = isset($obj->description) ? $obj->description : null;
		
		$this->parseRef($obj);
		$this->parseType($obj);
	}
	
	private function parseRef($obj) {
		$ref = '$ref';
		if (isset($obj->type) && $obj->type == 'array') {
			if (!isset($obj->items)) {
				throw new Exception('Parameter type is array, but no "items" node found.');
			}
			$this->isArray = true;
			$obj = $obj->items;
		}
		if (property_exists($obj, '$ref')) {
			$this->ref = $obj->$ref;
		}
	}
	
	private function parseType($obj) {
		if (!isset($obj->type)) {
			if (!array_key_exists($this->ref, XBMC_JSONRPC_Type::$global)) {
				throw new Exception('Cannot find reference "'.$this->ref.'" in types array!');
			}
			$this->type = XBMC_JSONRPC_Type::$global[$this->ref];
			return;
		}
		if (is_array($obj->type)) {
			$this->type = array();
			foreach ($obj->type as $type) {
				$this->type[] = new XBMC_JSONRPC_ParamType($type, '', ucwords($this->name));
			}
		} else {
			$this->type = new XBMC_JSONRPC_ParamType($obj);
		}
	}
	
	public function getInnerTypes() {
		if (!is_array($this->type)) {
			return array();
		}
		$types = array();
		foreach ($this->type as $type) {
			if ($type->isInner) {
				$types[] = $type;
			}
		}
		return $types; 
	}
	
}