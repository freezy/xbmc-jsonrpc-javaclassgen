<?php

class XBMC_JSONRPC_Method {
	
//	const HALT_AT = 'Player.Open';
//	const HALT_AT = 'AudioLibrary.GetAlbums';
//	const HALT_AT = 'XBMC.GetInfoLabels';
	const HALT_AT = ''; 
	
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
	public $innerClasses = array(); // inner classes can happen for return types and parameters that aren't referenced by defined in the method's body.
	public $ns; // the "namespace" of the API method
	public $m;  // the method name, without namespace.
	/**
	 * Return type
	 * @var XBMC_JSONRPC_ReturnType
	 */
	public $returns;
	public $returnsArray = false;
	public $returnAttr; // how the attribute of the return value is called
	
	/* collections
	 */
	private static $global = array(); // all methods referenced
	private static $classes = array(); // global types organized by [wrapperclass][typeclass] (javaClass/javaType.
	private static $attrs = array('description', 'params', 'returns', 'type'); // possible attributes
	private static $listParamNames = array('sort', 'limits');
	private static $listReturnNames = array('limits');
	
	/* configuration
	 */
	const PACKAGE = 'org.xbmc.android.jsonrpc.api.call';
	const UNDEFINED_RESULT = 'UndefinedResult';
	const RESULT = 'RESULT';
	const RESULTS = 'RESULTS';
	private static $imports = array(
		'ArrayList' => 'java.util.ArrayList',
		'UndefinedResult' => 'org.xbmc.android.jsonrpc.api.UndefinedResult',
		'AbstractCall' => 'org.xbmc.android.jsonrpc.api.AbstractCall',
		'AbstractModel' => 'org.xbmc.android.jsonrpc.api.AbstractModel',
		'JSONArray' => 'org.json.JSONArray',
		'JSONObject' => 'org.json.JSONObject',
		'JSONException' => 'org.json.JSONException',
		'Parcel' => 'android.os.Parcel',
		'Parcelable' => 'android.os.Parcelable',
	);
	public static $currentImports = array();
	
	public function __construct($name, $obj) {
		
		print "### $name\n";
		
		// copy values from object
		$this->type = $obj->type;
		$this->description = $obj->description;
		
		$this->parseName($name);
		$this->parseParams($obj);
		$this->parseReturns($obj);
		$this->generateConstructors();
		
		// move out later if necessary
		$this->reference();
	}
	
	public function reference() {
		// add to classes array so we can generate the java classes easily.
		if (!array_key_exists($this->ns, self::$classes)) {
			self::$classes[$this->ns] = array();
		}
		self::$classes[$this->ns][$this->m] = $this;
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
		$types = array(); // values are all objects!
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
				$types[] = array($param->type);
			}
			$names[] = $param->name;
		}
		$this->listParams = $listParams;
		if (!count($types)) {
			return;
		}
				
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
		$props = null;
		if (isset($obj->returns->properties)) {
			$props = $obj->returns->properties;
		} else if (isset($obj->returns->items->properties)) {
			$props = $obj->returns->items->properties;
		}
		if ($props) {
			$i = 0;
			// check if there is only 1 prop besides the ones we ignore
			$ignore = 0;
			$total = 0;
			$attr = null;
			$returnObject = new stdClass();
			$returnObject->properties = new stdClass();
			$returnObject->type = 'object';
			$lastNonIgnored = null;
			foreach ($props as $a => $r) {
				if (in_array($a, self::$listReturnNames)) {
					$ignore++;
				} else {
					$attr = $a;
					$returnObject->properties->$a = $r;
					$lastNonIgnored = $r;
				}
				$total++;
			}
			if ($total - $ignore == 1) {
				$this->returnAttr = $attr;
				if (isset($lastNonIgnored->items)) {
					$this->returns = new XBMC_JSONRPC_ReturnType(ucwords($this->name).'Result', $lastNonIgnored->items);
					$this->returns->returnsArray = true;
				} else {
					$this->returns = new XBMC_JSONRPC_ReturnType(ucwords($this->name).'Result', $lastNonIgnored);
				}
				if ($this->returns->getInstance()->arrayType) {
					$this->returns->returnsArray = true;
				}
			} else {
				$this->returns = new XBMC_JSONRPC_ReturnType(ucwords($this->name).'Result', $returnObject, $this->m);
				$this->innerClasses[] = $this->returns;
			}
		} else {
			$this->returns = new XBMC_JSONRPC_ReturnType(ucwords($this->name).'Result', $obj->returns);
		}
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
//		self::compileAll();
	}
	
	public static function addModelImport($model) {
		self::$currentImports[] = XBMC_JSONRPC_Type::PACKAGE.'.'.$model;
	}
	
	public static function addImport($import) {
		self::$currentImports[] = self::$imports[$import];
		self::$currentImports = array_unique(self::$currentImports);
		sort(self::$currentImports);
	} 
	public static function clearImports() {
		self::$currentImports = array();
	}
		
	public static function compileAll($folder, $header = '') {
		foreach (self::$classes as $namespace => $classes) {
			if (count($classes)) {
				self::clearImports();
				$filename = $folder.'/'.$namespace.'.java';
				$imports = '';
				$ns = '';
				$inner = '';
				
				$ns .= "\npublic final class ".$namespace." {\n";
				$ns .= "\n	private final static String PREFIX = \"".$namespace.".\";\n\n";
				foreach ($classes as $c) {
					$inner .= $c->compile();
				}
				if (!$inner) {
					continue;
				}
				foreach(self::$currentImports as $import) {
					$imports .= 'import '.$import.";\n";
				}
				$content = $header;
				$content .= 'package '.self::PACKAGE.";\n\n";
				$content .= $imports;
				$content .= $ns;
				$content .= $inner;
				$content .= '}';
				if (self::HALT_AT) {
					print $content;
				} else {
					file_put_contents($filename, $content);
					print "Written ".strlen($content)." bytes to ".$filename.".\n";
					
				}
			}
		}
	}	
	
	public function getReturnType($notNative = false) {
		if (isset($this->returns->getInstance()->arrayType)) {
			$type = $this->returns->getInstance()->arrayType->getInstance();
		} else {
			$type = $this->returns->getInstance();
		}
			
		if ($type->isInner) {
			return $type->getJavaType($notNative, true);
		} else {
			return $type->getJavaType($notNative);
		}
	}
	
	public function getReturn() {
		if (isset($this->returns->arrayType)) {
			return $this->returns->arrayType->getInstance();
		} else {
			return $this->returns->getInstance();
		}
	}
	
	private function compile() {
		
		echo "... Compiling ".$this->name."...\n";
		
		self::addImport('AbstractCall');
		self::addImport('JSONException');
		if (strpos($this->getReturnType(), '.') && !$this->returns->isInnerType) {
			self::addModelImport($this->getReturn()->javaClass);
		}
		if ($this->getReturnType() == self::UNDEFINED_RESULT) {
			self::addImport(self::UNDEFINED_RESULT);
		}

		// header
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
		$content .= $this->r($i, sprintf('public static class %s extends AbstractCall<%s> { ', $this->m, $this->getReturnType(true)));
		$content .= $this->r($i, sprintf('	private static final String NAME = "%s";', $this->m));
		if ($this->returnAttr) {
			$content .= $this->r($i, sprintf('	public static final String %s = "%s";', self::RESULTS, $this->returnAttr));
		}

		// constructors
		$i++;
		if (count($this->constructs)) {
			foreach ($this->constructs as $c) {
				$content .= $this->compileConstructor($c);
			}
		} else {
			$content .= $this->r($i, sprintf('/**'));
			if ($this->description) {
				$content .= $this->r($i, sprintf(' * %s', $this->description));
			}
			$content .= $this->r($i, sprintf(' * @throws JSONException'));
			$content .= $this->r($i, sprintf(' */'));
			$content .= $this->r($i, sprintf('public %s() throws JSONException {', $this->m));
			$content .= $this->r($i, sprintf('	super();'));
			$content .= $this->r($i, sprintf('}'));
		}
		
		// json de-serializer
		if ($this->returns->returnsArray) {
			if (!$this->returnAttr) {
				throw new Exception('Cannot compile de-serializer when result attribute is unknown!');
			}
			$content .= $this->r($i, sprintf('@Override'));
			$content .= $this->r($i, sprintf('protected ArrayList<%s> parseMany(JSONObject obj) throws JSONException {', $this->getReturnType(true)));
			$content .= $this->r($i, sprintf('	final JSONArray %s = parseResult(obj).getJSONArray(%s);', $this->returnAttr, self::RESULTS));
			$content .= $this->r($i, sprintf('	final ArrayList<%s> ret = new ArrayList<%s>(%s.length());', $this->getReturnType(true), $this->getReturnType(true), $this->returnAttr));
			$content .= $this->r($i, sprintf('	for (int i = 0; i < %s.length(); i++) {', $this->returnAttr));
			$content .= $this->r($i, sprintf('		final JSONObject item = %s.getJSONObject(i);', $this->returnAttr));
			$content .= $this->r($i, sprintf('		ret.add(new %s(item));', $this->getReturnType(true)));
			$content .= $this->r($i, sprintf('	}'));
			$content .= $this->r($i, sprintf('	return ret;'));
			$content .= $this->r($i, sprintf('}'));
			self::addImport('ArrayList');
			self::addImport('JSONArray');
			self::addImport('JSONObject');
		} else {
			$content .= $this->r($i, sprintf('@Override'));
			$content .= $this->r($i, sprintf('protected %s parseOne(JSONObject obj) throws JSONException {', $this->getReturnType(true)));
			switch ($this->getReturn()->type) {
				case 'integer':
					$content .= $this->r($i, sprintf('	return obj.getInt(%s);', self::RESULT));
					break;
				case 'number':
					$content .= $this->r($i, sprintf('	return obj.getDouble(%s);', self::RESULT));
					break;
				case 'boolean':
					$content .= $this->r($i, sprintf('	return obj.getBoolean(%s);', self::RESULT));
					break;
				case 'null':
				case 'any':
				case 'string':
					$content .= $this->r($i, sprintf('	return obj.getString(%s);', self::RESULT));
					break;
				default:
					if ($this->getReturnType(true) == self::UNDEFINED_RESULT) {
						$content .= $this->r($i, sprintf('	return new %s(obj);', self::UNDEFINED_RESULT));
					} else {
						if ($this->returnAttr) {
							$content .= $this->r($i, sprintf('	return new %s(parseResult(obj).getJSONObject(%s));', $this->getReturnType(true), self::RESULTS));
						} else {
							$content .= $this->r($i, sprintf('	return new %s(parseResult(obj));', $this->getReturnType(true)));
						}
					}
					break;
			}
			$content .= $this->r($i, sprintf('}'));
			self::addImport('JSONObject');
		}
		
		// inner classes
		foreach ($this->innerClasses as $c) {
			$content .= $c->compile($i - 1);
		}
		if (count($this->innerClasses)) {
			self::addImport('ArrayList');
			self::addImport('JSONObject');
			self::addImport('JSONArray');
		}
		
		// inner types
		foreach ($this->params as $param) {
			foreach ($param->getInnerTypes() as $type) {
				$content .= $type->compile($i - 1, false);
			}
		}
		$content .= $this->r($i, '@Override');
		$content .= $this->r($i, 'protected String getName() {');
		$content .= $this->r($i, '	return PREFIX + NAME;');
		$content .= $this->r($i, '}');
		$content .= $this->r($i, '@Override');
		$content .= $this->r($i, 'protected boolean returnsList() {');
		$content .= $this->r($i, sprintf('	return %s;', $this->returns->returnsArray ? 'true' : 'false'));
		$content .= $this->r($i, '}');
		
		$i--;
		$content .= $this->r($i, '}');
		
		return $content;
	}
	
	private function compileConstructor($c) {
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
		// args
		$args = '';
		foreach ($c as $name => $type) {
			$class = $type->getJavaParamType($n == 1);
			$args .= $class.' '.$name.', ';
			if (preg_match('/[^\.]\.[^\.]/', $class)) {
				self::addModelImport($type->javaClass);
			}
		}
		$args = substr($args, 0, -2);
		
		$i = 2;
		$content = '';
		$content .= $this->r($i, sprintf('/**'));
		if ($this->description) {
			$content .= $this->r($i, sprintf(' * %s', $this->description));
		}
		$see = '';
		foreach ($this->params as $param) {
			$desc = '';
			// comment enums
			if (is_object($param->type) && count($param->type->getInstance())) {
				$inst = $param->type->getInstance();
				if (count($inst->enums)) {
					$desc = 'One of: <tt>'.implode('</tt>, <tt>', $inst->enums).'</tt>. See constants at {@link '.$inst->javaClass.'.'.$inst->javaType.'}.';
					$see .= $this->r($i, sprintf(' * @see %s.%s',$inst->javaClass, $inst->javaType));
				}
			}
			// comment list of enums
			if (isset($param->type->arrayType)) {
				$inst = $param->type->arrayType->getInstance();
				if (count($inst->enums)) {
					$desc = 'One or more of: <tt>'.implode('</tt>, <tt>', $inst->enums).'</tt>. See constants at {@link '.$inst->javaClass.'.'.$inst->javaType.'}.';
					$see .= $this->r($i, sprintf(' * @see %s.%s',$inst->javaClass, $inst->javaType));
				}
			}
			$content .= $this->r($i, sprintf(' * @param %s %s', $param->name, $param->description ? $this->description.($desc ? ' ('.$desc.')' : '') : $desc));
		}
		$content .= $see;
		$content .= $this->r($i, sprintf(' * @throws JSONException'));
		$content .= $this->r($i, sprintf(' */'));
		$content .= $this->r($i, sprintf('public %s(%s) throws JSONException {', $this->m, $args));
		$content .= $this->r($i, sprintf('	super();'));
		foreach ($c as $name => $type) {
			$content .= $this->r($i + 1, sprintf('addParameter("%s", %s);', $name, $name));
		}
		$content .= $this->r($i, sprintf('}'));
		return $content;
	}
}