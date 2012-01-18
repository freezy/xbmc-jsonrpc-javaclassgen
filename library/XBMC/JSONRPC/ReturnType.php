<?php

class XBMC_JSONRPC_ReturnType extends XBMC_JSONRPC_Type {
	
	public function __construct($name, $obj, $isInner = false) {
		
		if (!is_object($obj)) {
			throw new Exception('"'.$obj.'" is not an object.');
		}
		
		$this->isInner = $isInner;
		
		parent::__construct(1, $name, $obj, true, false, null);
		$this->parseJavaName();
	}
	
}