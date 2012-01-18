<?php

class XBMC_JSONRPC_Introspect {
	
	const FILENAME = 'xbmc-introspect.json';
	
	private $_filename;
	private $_introspect;
	
	private static $package = '';
	private static $header = '/*
 *      Copyright (C) 2005-2015 Team XBMC
 *      http://xbmc.org
 *
 *  This Program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2, or (at your option)
 *  any later version.
 *
 *  This Program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with XBMC Remote; see the file license.  If not, write to
 *  the Free Software Foundation, 675 Mass Ave, Cambridge, MA 02139, USA.
 *  http://www.gnu.org/copyleft/gpl.html
 *
 */

';
	
	
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
	
	public function readTypes() {
		print "*** Reading Types...\n";
		$types = $this->_introspect->types;
		XBMC_JSONRPC_Type::processAll($types);
	}
	
	public function readMethods() {
		print "*** Reading Methods...\n";
		$methods = $this->_introspect->methods;
		XBMC_JSONRPC_Method::processAll($methods);
	}
	
	public function compileTypes($folder) {
		if (!is_dir($folder) || !is_writable($folder)) {
			throw new Exception('Folder "'.$folder.'" must exist and be writeable.');
		}
		XBMC_JSONRPC_Type::compileAll($folder, self::$header);
	}
	public function compileMethods($folder) {
		if (!is_dir($folder) || !is_writable($folder)) {
			throw new Exception('Folder "'.$folder.'" must exist and be writeable.');
		}
		XBMC_JSONRPC_Method::compileAll($folder, self::$header);
	}
	
}