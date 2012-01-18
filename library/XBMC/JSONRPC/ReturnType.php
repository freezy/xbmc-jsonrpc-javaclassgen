<?php

class XBMC_JSONRPC_ReturnType extends XBMC_JSONRPC_Type {
	
	public function __construct($name, $obj) {
		parent::__construct(0, $name, $obj, true, false, null);
		$this->parseJavaName();
	}
	
}