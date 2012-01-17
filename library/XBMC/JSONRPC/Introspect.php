<?php

class XBMC_JSONRPC_Introspect {
	
	const FILENAME = 'xbmc-introspect.json';
	
	private $_filename;
	private $_introspect;
	
	
	public function __construct($filename = self::FILENAME) {
		$this->_filename = $filename;
		if (!file_exists($filename)) {
			throw new Exception('Cannot open introspect file at ' + $filename + '.');
		}
		$rawdata = file_get_contents($filename);
		// strip eventual http headers
		while ($rawdata[0] != '{') {
			$rawdata = substr($rawdata, 1);
		}
		$this->_introspect = json_decode($rawdata)->result;
		print "*** Introspect loaded.\n";
	}
	
	public function parseTypes() {
		print "*** Reading Types...\n";
		$types = $this->_introspect->types;
		XBMC_JSONRPC_Type::processAll($types);
	}
	
	public function parseMethods() {
		$methods = $this->_introspect->methods;
	}
	
	public function renderMethods($folder) {
		if (!is_dir($folder) || !is_writable($folder)) {
			throw new Exception('Folder "'.$folder.'" must exist and be writeable.');
		}
		XBMC_JSONRPC_Type::renderAll($folder);
	}
	
}