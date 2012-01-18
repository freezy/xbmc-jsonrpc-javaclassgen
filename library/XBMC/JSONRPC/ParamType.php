<?php

class XBMC_JSONRPC_ParamType extends XBMC_JSONRPC_Type {
	
	public function __construct($obj) {
		if (!is_object($obj)) {
			throw new Exception('"'.$obj.'" is not an object.');
		}
		parent::__construct(1, null, $obj, true, false, null);

		$this->parseName();
		$this->parseJavaName();
	}
	
	private function parseName() {
		if ($this->ref) {
			$name = str_replace('.', '', $this->ref);
		} else {
			$propkeys = array();
			foreach ($this->properties as $key => $val) {
				$propkeys[] = ucwords($key);
			}
			sort($propkeys);
			$name = implode('', $propkeys);
		}
		$this->name = $name;
	}
	
}