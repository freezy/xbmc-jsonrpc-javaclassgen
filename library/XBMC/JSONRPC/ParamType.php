<?php

class XBMC_JSONRPC_ParamType extends XBMC_JSONRPC_Type {
	
	public function __construct($obj, $nameSuffix = '') {
		if (!is_object($obj)) {
			throw new Exception('"'.$obj.'" is not an object.');
		}
		parent::__construct(1, null, $obj, true, false, null);

		$this->parseName($nameSuffix);
		$this->parseJavaName();
	}
	
	private function parseName($nameSuffix) {
		if ($this->ref) {
			$name = str_replace('.', '', $this->ref);
		} else {
			$propkeys = array();
			foreach ($this->properties as $key => $val) {
				$propkeys[] = ucwords($key);
			}
			sort($propkeys);
			$name = implode('', $propkeys).$nameSuffix;
			$this->isInner = true;
		}
		$this->name = $name;
	}
	
}