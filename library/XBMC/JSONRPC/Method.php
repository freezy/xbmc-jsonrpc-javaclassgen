<?php

class XBMC_JSONRPC_Method {
	
//	const HALT_AT = 'Player.Open';
//	const HALT_AT = 'AudioLibrary.GetAlbums';
	const HALT_AT = 'AudioLibrary.GetRecentlyAddedSongs'; // has parameter descriptions
	
	/* directly copied attributes:
	 */
	public $name;
	public $description;
	public $type;
	
	/* generated references:
	 */
	public $params = array(); // array of XBMC_JSONRPC_Param objects
	public $constructs = array(); // constructor types
	public $listParams = array(); // list params we don't want in the constructor (generate a setter)
	public $ns; // the "namespace" of the API method
	public $m;  // the method name, without namespace.
	/**
	 * Return type
	 * @var XBMC_JSONRPC_Type
	 */
	public $returns;
	public $returnsArray = false;
	public $returnAttr; // how the attribute of the return value is called
	
	/* collections
	 */
	private static $global = array(); // all methods referenced
	private static $attrs = array('description', 'params', 'returns', 'type'); // possible attributes
	private static $listParamNames = array('sort', 'limits');
	
	public function __construct($name, $obj) {
		
		print "### $name\n";
		
		// copy values from object
		$this->type = $obj->type;
		$this->description = $obj->description;
		
		$this->parseName($name);
		$this->parseParams($obj);
		$this->parseReturns($obj);
		$this->generateConstructors();
		
	}
	
	private function parseName($name) {
		$p = explode('.', $name);
		if (count($p) != 2) {
			throw new Exception('"'.$name.'" seems like a weird name.');
		}
		$this->name = $name;
		$this->ns = $p[0];
		$this->m = $p[1];
		
	}
	
	/**
	 * Creates constructors for every parameter/type combination.
	 * 
	 * Since there can be several types per parameter, we potentially
	 * need more than one constructor in order to cover all the combinations
	 * of types.
	 */
	private function generateConstructors() {
		$types = array();
		$names = array();
		$listParams = array();

		// collect types
		foreach ($this->params as $param) {
			if (is_array($param->type)) {
				$tt = array();
				foreach ($param->type as $type) {
					$tt[] = $type;
				}
				$types[] = $tt;
				$names[] = $param->name;
			} else {
				$types[] = array($param->getType());
			}
			$names[] = $param->name;
		}
		$this->listParams = $listParams;
		
		/* 
		 * now we have $types, where first the dimension are the params and 
		 * second dimension are the types available for each param.
		 * 
		 * then we compute all type combinations per param, like a slot 
		 * machine where each slots represents a param and every symbol a type.
		 */
		
		$constructors = array(); // what we return
		$s = count($types); // number of slots
		$max = array(); // max count per slot (or number of types per param)
		for ($i = 0; $i < $s; $i++) {
			$max[] = count($types[$i]);
		}
		$c = array(0); // current combination of type per param
		do {
			
			// collect types and add them to constructors 
			$constructor = array();
			for ($t = 0; $t < $s; $t++) {
				$constructor[$names[$t]] = $types[$t][(isset($c[$t]) ? $c[$t] : 0)];
			}
			$constructors[] = $constructor;
			
			// count up
			$c[0]++;
			for ($i = 0; $i < $s; $i++) {
				// at max, reset to 0 and bump next counter of next slot
				if (isset($c[$i]) && $c[$i] >= $max[$i]) {
					$c[$i] = 0;
					if (!isset($c[$i+1])) {
						$c[$i+1] = 1;
					} else {
						$c[$i+1]++;	
					}
					// if no more slots available, quit.
					if ($i+2 > $s) {
						break(2);
					}
				}
			}
		} while (true);
		
		$this->constructs = $constructors;
	}

	private function parseParams($obj) {
		if (isset($obj->params)) {
			foreach ($obj->params as $param) {
				if (in_array($param->name, self::$listParamNames)) {
					$this->listParams[] = new XBMC_JSONRPC_Param($param);
				} else {
					$this->params[] = new XBMC_JSONRPC_Param($param);
				}
			}
		}
	}
	private function parseReturns($obj) {
		if (!isset($obj->returns)) {
			throw new Exception('No return type set for method "'.$this->name.'".');
		}
		$i = 0;
		$isArray = isset($obj->return->limits);
		foreach ($obj->returns->properties as $attr => $r) {
			$i++;
			if ($i > 2) {
				throw new Exception('Should not have more than 2 return type properties. Please check wtf is going on.');
			} 
			if ($attr == 'limits') {
				continue;
			}
			if ($isArray && !isset($r->items)) {
				throw new Exception('The "limits" property is defined but there is no "items" attribute in the return object. Please check.');
			}
			$this->returnAttr = $attr;
			$this->returns = new XBMC_JSONRPC_ReturnType(ucwords($this->name).'Result', $isArray ? $r->items : $r);
		}
	//	print_r($this->returns->arrayType->getInstance());exit;
	}
	
	private function assertKeys(stdClass $obj, array $values) {
		foreach ($obj as $k => $v) {
			if (!in_array($k, $values)) {
				throw new Exception('Key "'.$k.'" should not be in array: '.json_encode($obj));
			} 
		}
	}
	
	protected function r($i, $line) {
		return sprintf("%'\t".$i."s%s\n", '', $line);
	}
	
	/**
	 * This goes through every method and copies the attributes.
	 *  
	 * @param stdClass $methods "methods" node from introspect. 
	 */
	private static function readAll($methods) {
		// first run just reads and sets the attributes of each object.
		foreach ($methods as $name => $method) {
			if (self::HALT_AT && $name != self::HALT_AT) {
				continue;	
			}
			$m = new XBMC_JSONRPC_Method($name, $method);
			
			if (array_key_exists($name, self::$global)) {
				throw new Exception('Duplicate name? Method "'.$name.'" is already saved!');
			}
		
			// add to global array
			self::$global[$name] = $m;
		}
	}
	
	/**
	 * Compiles type classes for all methods.
	 * 
	 * @param stdClass $methods "methods" node from introspect. 
	 */
	public static function processAll($types) {
		self::readAll($types);
//		self::referenceAll();
//		self::assertAll();
//		self::printAll();
		self::compileAll();
	}
	
	public static function compileAll() {
		foreach (self::$global as $method) {
			print $method->compile();
		}
	}
	
	public function getReturnType() {
		if (isset($this->returns->arrayType)) {
			return $this->returns->arrayType->getInstance()->getJavaType();
		} else {
			return $this->returns->getInstance()->getJavaType();
		}
	}
	
	private function compile() {
		$i = 1;
		$content = $this->r($i, '/**');
		if ($this->description) {
			$content .= $this->r($i, sprintf(' * %s', $this->description));
			$content .= $this->r($i, ' * <p/>');
		}
		if ($this->name) {
			$content .= $this->r($i, sprintf(' * API Name: <code>%s</code>', $this->name));
			$content .= $this->r($i, ' * <p/>');
		}
		$content .= $this->r($i, ' * <i>This class was generated automatically from XBMC\'s JSON-RPC introspect.</i>');
		$content .= $this->r($i, ' */');
		$content .= $this->r($i, sprintf('public static class %s extends AbstractCall<%s> { ', $this->m, $this->getReturnType()));
		$content .= $this->r($i, sprintf('	private static final String NAME = "%s";', $this->m));
		foreach ($this->constructs as $c) {
			$content .= $this->compileConstructor($c);
		}
		
		$content .= $this->r($i, sprintf('} '));
		
		return $content;
	}
	
	private function compileConstructor($c) {
		
		// args
		$args = '';
		// count the number of arrays first
		$n = 0;
		foreach ($c as $name => $type) {
			if ($type->getType() == 'array') {
				$a = $name;
				$n++;
			}
		}
		// move array to bottom
		if ($n == 1) {
			$aa = $c[$a];
			unset($c[$a]);
			$c[$a] = $aa;
		}
		
		foreach ($c as $name => $type) {
			$args .= $type->getJavaParamType($n == 1).' '.$name.', ';
		}
		$args = substr($args, 0, -2);
		
		$i = 2;
		$content = '';
		$content .= $this->r($i, sprintf('/**'));
		if ($this->description) {
			$content .= $this->r($i, sprintf(' * %s', $this->description));
		}
		foreach ($this->params as $param) {
			$content .= $this->r($i, sprintf(' * @param %s %s', $param->name, $param->description));
		}
		$content .= $this->r($i, sprintf(' * @throws JSONException'));
		$content .= $this->r($i, sprintf(' */'));
		$content .= $this->r($i, sprintf('public %s(%s) throws JSONException {', $this->m, $args));
		$content .= $this->r($i, sprintf('}'));
		return $content;
	}
}