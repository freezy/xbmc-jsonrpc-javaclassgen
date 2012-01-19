<?php

class XBMC_JSONRPC_ParamType extends XBMC_JSONRPC_Type {
	
	public function __construct($obj, $nameSuffix = '', $name = '') {
		if (!is_object($obj)) {
			throw new Exception('"'.$obj.'" is not an object.');
		}
		parent::__construct(1, null, $obj, true, false, null);

		$resetName = $this->parseName($nameSuffix, $name);
		$this->parseJavaName();
		if ($resetName && $name) {
			$this->name = $name;
		}
	}
	
	private function parseName($nameSuffix) {
		$resetName = false;
		if ($this->ref) {
			$name = str_replace('.', '', $this->ref);
		} else {
			if (count($this->properties )) {
				$propkeys = array();
				foreach ($this->properties as $key => $val) {
					$propkeys[] = ucwords($key);
				}
				sort($propkeys);
				$name = implode('', $propkeys).$nameSuffix;
			} else {
				$resetName = true;
			}
			$this->isInner = true;
		}
		return $resetName;
	}
	
}