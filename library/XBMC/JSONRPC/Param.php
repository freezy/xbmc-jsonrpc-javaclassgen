<?php

class XBMC_JSONRPC_Param	 {
	
	/* directly copied attributes:
	 */
	public $ref;
	public $default;
	public $name;
	
	/* collections
	 */
	private static $attrs = array('$ref', 'default', 'name'); // possible attributes

	
	public function __construct(stdClass $obj) {
		
		// copy values from object
		$this->name = $obj->name;
		$this->default = isset($obj->default) ? $obj->default : null;
		
		$this->parseRef($obj);
	}
	
	private function parseRef($obj) {
		$ref = '$ref';
		if (property_exists($obj, '$ref')) {
			$this->ref = $obj->$ref;
		}
	}
	

}