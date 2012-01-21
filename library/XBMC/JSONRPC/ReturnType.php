<?php

class XBMC_JSONRPC_ReturnType extends XBMC_JSONRPC_Type {
	
	public $isInnerType;
	
	public function __construct($name, $obj, $outerClass = '') {
		
		if (!is_object($obj)) {
			throw new Exception('"'.$obj.'" is not an object.');
		}
		
		$this->isInnerType = $outerClass ? true : false;
		
		parent::__construct(1, $name, $obj, true, false, null);
		$this->parseJavaName($outerClass);
		foreach ($this->properties as $prop) {
			$prop->parseJavaName();
		}
	}
	
	/**
	 * Returns the "normalized" type, meaning if an array is returned, this 
	 * returns the type of the array's elements.
	 * @return XBMC_JSONRPC_Type
	 */
	public function getNormalizedType() {
		return $this->isArray() ? $this->arrayType : $this;
	}
}