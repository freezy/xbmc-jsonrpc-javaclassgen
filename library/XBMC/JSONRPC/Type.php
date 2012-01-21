<?php

/**
 *  JSON-RPC Types
 *  ==============
 *  
 *  There are four different types of types:
 * 
 *  - *Global object*, defined at the root of the "types" node, e.g. "Global.Time".
 *      - has an identifier ("id")
 *      - has properties (either primitive, object, or array)
 *      
 *  - *Anonymous object*, defined directly under a property of a global object.
 *      - We call this "inner class", since it's defined inside the class that
 *        defines its properties.
 *      - The name of the class is the name of the variable in the parent object.
 *      - Example: "version" under "Application.Property.Value".
 *      
 *  - *Array*, defined by the presence of the "items" attribute (and type="array"). 
 *    There are several cases of arrays:
 *    	- Primitive array ("Item.Fields.Base"): Array consists of types of a primitive
 *        such as string or integer.
 *      - Typed array ("audiostreams" at "Player.Property.Value"): Only a reference 
 *        which points to a global object is provided in the "items" node.  
 *      - Enumerated array ("Audio.Fields.Album"): An array which consists of a
 *        pre-defined bunch of values. We ignore the enum and use string as type,
 *        but define an interface with the values as constants.
 *      - Anonymous type ("Video.Cast"): Instead of a global object, a custom 
 *        object is directly defined, similar to the anonymous object. This will 
 *        also result in an inner class with the name of the wrapping class 
 *        suffixed with "Item". For "Video.Cast" that would be "VideoCastItem".
 *        
 *   - *Primitive type*, only set using type="type". They are: string, integer, boolean, double.
 *   
 *   Every one of those types is represented by an instance of this class. In the most 
 *   simplest case, the primitive type, only $this->type will be set.
 */
class XBMC_JSONRPC_Type {
	
	const HALT_AT = '';
//	const HALT_AT = 'Playlist.Item';
	
	/* directly copied attributes:
	 */
	public $id = null;
	public $extends = null; // not a ref but the string (or array!) name of the super class.
	public $ref = null; // not a ref but the string name of the reference
	public $type = null; // can be null if "extends" or "$ref".
	public $enums = array();
	public $required = false;
	public $default = null;
	public $description = null;
	
	/* generated references:
	 */
	public $properties = array(); // array of XBMC_JSONRPC_Type objects.
	public $innerClasses = array(); // all anonymous property types 
	public $innerTypes = array(); // when parameters are of multiple types, create sub-classes with simplified constructor.
	public $arrayType = null; // read from "items"
	public $obj; // original JSON data
	public $name; // the name from instantiation. on global objects it equals to ID, but on inner stuff it's the var name.
	
	/* helper variables
	 */
	protected $isProperty = false; // was added as a property type
	protected $isArray = false;    // was added from "items" as array type
	public $isInner = false;    // we're dealing with an anonymous type (see class comment)
	protected $isUsedAsArray = false; // needed so we can generate the getArray() method which instatiates the array in java.
	protected $indent; // pretty print
	
	/* java mappings
	 */
	public $javaClass = null;
	public $javaType = null;
	
	/* collections
	 */
	public static $global = array(); // "global types"
	private static $classes = array(); // global types organized by [wrapperclass][typeclass] (javaClass/javaType.
	
	/* configuration
	 */
	const PACKAGE = 'org.xbmc.android.jsonrpc.api.modelgen';
	private static $emptyTypes = array('Item.Fields.Base'); // ignore these
	private static $ignoreTypes = array('Array.Integer', 'Array.String'); // ignore these too
	private static $imports = array(
		'ArrayList' => 'java.util.ArrayList',
		'AbstractModel' => 'org.xbmc.android.jsonrpc.api.AbstractModel',
		'JSONArray' => 'org.json.JSONArray',
		'JSONObject' => 'org.json.JSONObject',
		'JSONException' => 'org.json.JSONException',
	);
	public static $print = true;
	
	/* temp crap
	 */
	public static $currentImports = array();
	
	
	public function __construct($i, $name, $obj, $isProperty = false, $isArray = false, $creator = null) {
		$this->indent = $i;
		$this->p('### READ: '.$name);
		
		// copy values from object
		$this->set($obj, 'id');
		$this->parseExtends($obj);
		$this->parseRef($obj);
		$this->set($obj, 'type');
		$this->parseEnums($obj);
		$this->set($obj, 'required');
		$this->set($obj, 'default');
		$this->set($obj, 'description');
		
		$this->obj = $obj;
		$this->name = $name;
		$this->isProperty = $isProperty;
		$this->isArray = $isArray;
		
		// do one preliminary checks
		if (!$isProperty && !$isArray && !$this->id) {
			throw new Exception('No ID given for global type '.$name);
		}
		
		/* type = "object" is only indicated for entire type definitions,
		 * which can extend another class. "$ref" definitions don't specify
		 * the type, so we can be sure of an inner class if it's a property
		 * with an object type.
		 * Idem for array, although it's only inner if the parent is no root object.
		 */
		$this->isInner = ($isProperty && $this->type == 'object') || ($isArray && $this->type == 'object' && !$creator->id);
		
		if ($creator && $creator->id && $isArray) {
			$creator->isUsedAsArray = true;
		}
		
		// now we parse the properties so we're sure we'll get them all
		$this->parseProperties();
		
		// also let's check if we're an array of some kind:
		$this->parseItems();
	}
	
	/**
	 * Returns either current or references instance.
	 * @return XBMC_JSONRPC_Type
	 */
	public function getInstance() {
		if ($this->ref) {
			return $this->getRef();
		}
		return $this;
	}
	
	/**
	 * Returns the potentially inherited type of the class. Never returns null.
	 * Only used for assert.
	 * @return string
	 */
	public function getType() {
		if (!$this->type) {
			if ($this->extends) {
				if (!is_array($this->extends)) {
					if (array_key_exists($this->extends, self::$global)) {
						return self::$global[$this->extends]->getType();
					} else {
						throw new Exception('Cannot find super type '.$this->extends.' ('.$this->name.')!');
					}
				} else {
					if (array_key_exists($this->extends[0], self::$global)) {
						return self::$global[$this->extends[0]]->getType();
					} else {
						throw new Exception('Cannot find super type 0 of '.$this->extends.' ('.$this->name.')!');
					}
				}
			} else {
				if (!$this->ref) {
					$this->p(json_encode($this->obj));
					//print_r($this);
					throw new Exception('Cannot have no type, no super type and no reference ('.$this->name.')!');
				} else {
					return self::$global[$this->ref]->getType();
				}
			}
		} else {
			return $this->type;
		}
	}
	
	protected function getInnerClasses() {
		$classes = $this->innerClasses;
		foreach ($this->properties as $prop) {
			$classes = array_merge($classes, $prop->getInnerClasses());
		}
		if ($this->arrayType) {
			$classes = array_merge($classes, $this->arrayType->getInnerClasses());
		}
		return $classes;
	}
	
	/**
	 * @return XBMC_JSONRPC_Type
	 */
	protected function getArrayType() {
		if ($this->arrayType) {
			return $this->arrayType;
		}
		if ($this->extends) {
			return $this->getExtends()->getArrayType();
		}
		if ($this->ref) {
			return $this->getRef()->getArrayType();
		}
	}
	
	/**
	 * Returns parent class.
	 * @return XBMC_JSONRPC_Type
	 */
	protected function getExtends() {
		if (!$this->extends) {
			throw new Exception('No parent class defined!');
		}
		if (is_array($this->extends)) {
			return self::$types[$this->extends[0]];
		}
		if (!array_key_exists($this->extends, self::$global)) {
			throw new Exception('Type '.$this->extends.' not found in static types.');
		}
		return self::$global[$this->extends];
	}
	
	/**
	 * Returns the reference type
	 * @return XBMC_JSONRPC_Type
	 */
	public function getRef() {
		if (!$this->ref) {
			throw new Exception('No reference defined.');
		}		
		if (!array_key_exists($this->ref, self::$global)) {
			throw new Exception('Could not find reference "'.$this->ref.'".');
		}
		return self::$global[$this->ref];
	}
	public function isNative() {
		return in_array($this->getType(), array('string', 'integer', 'number', 'null', 'boolean'));
	}
	public function isMultiObjectType() {
		$obj = $this->getType();
		if (!is_array($obj)) {
			return false;
		}
		$types = array();
		foreach ($obj as $t) {
			$types[] = $t->type;
		}
		$types = array_unique($types);
		return count($types) == 1 && $types[0] == 'object';
	}
	public function isArray() {
		return $this->getArrayType() ? true : false;
	}

	public function getJavaType($notNative = false, $includeOuterClassInInnerName = false) {
		if ($this->isInner) {
			return $includeOuterClassInInnerName ? $this->javaClass.'.'.$this->javaType : $this->javaType;
		} else {
			$type = $this->getType();
			if (is_array($type)) {
				return $this->getJavaMultiType($type);
			} else {
				if ($this->getArrayType()) {
					self::addImport('ArrayList');
					return 'ArrayList<'.$this->getArrayType()->getJavaType().'>';
				} else {
					switch ($this->getType()) {
						case 'integer':
							return $this->isArray || $notNative ? 'Integer' : 'int';
						case 'any':
						case 'null':
						case 'string':
							return 'String';
						case 'boolean':
							return $this->isArray || $notNative ? 'Boolean' : 'boolean';
						case 'number':
							return $this->isArray || $notNative ? 'Double' : 'double';
						case 'object':
							return $this->javaClass.'.'.$this->javaType;
						default:
							throw new Exception('Unknown type "'.$type.'".');
					}
				}
			}
		}
	}
	public function getJavaParamType($niceArrays = false) {
		if ($this->isInner && $this->javaType) {
			return $this->javaType;
		} else {
			$type = $this->getType();
			if (is_array($type)) {
				return $this->getInstance()->getJavaType();
			}
			if ($this->getArrayType()) {
				if ($niceArrays) {
					return $this->getArrayType()->getJavaType().'...';
				} else {
					return $this->getArrayType()->getJavaType().'[]';
				}
			} else {
				switch ($this->getType()) {
					case 'integer':
						return !$this->required ? 'Integer' : 'int';
					case 'any':
					case 'null':
					case 'string':
						return 'String';
					case 'boolean':
						return !$this->required ? 'Boolean' : 'boolean';
					case 'number':
						return !$this->required ? 'Double' : 'double';
					case 'object':
						return $this->javaClass.'.'.$this->javaType;
					default:
						throw new Exception('Unknown type "'.$type.'".');
				}
			}
		}
	}
	public function getJavaMultiType(array $obj) {
		// TODO treat this correctly
		$types = array();
		foreach ($obj as $t) {
			$types[] = $t->type;
		}
		$types = array_unique($types);
		sort($types);
		if (in_array('string', $types)) {
			$this->type = 'string';
			return 'String';
		}
		if (in_array('null', $types) && in_array('boolean', $types) && count($types) == 2) {
			$this->type = 'boolean';
			return 'Boolean';
		}
		if ($this->isMultiObjectType()) {
			return $this->javaClass.'.'.$this->javaType;
		}
		throw new Exception('Unknown types combination, please add to getJavaMultiType(): '.print_r($types, true));
	}
	public function getJavaValue($name) {
		if ($this->required) {
			switch ($this->getType()) {
				case 'string':
					return 'obj.getString("'.$name.'")';
				case 'integer':
					return 'obj.getInt("'.$name.'")';
				case 'boolean':
					return 'obj.getBoolean("'.$name.'")';
				case 'number':
					return 'obj.getDouble("'.$name.'")';
				case 'object':
					return 'new '.$this->getJavaType().'(obj.getJSONObject("'.$name.'"))';
				case 'array':
					if ($this->getArrayType()->getType() == 'string') {
						return 'getStringArray(obj, "'.$name.'")';
					}
					if ($this->getArrayType()->getType() == 'integer') {
						return 'getIntegerArray(obj, "'.$name.'")';
					}
					return $this->getArrayType()->getJavaType().'.'.$this->getArrayType()->getJavaArrayCreatorMethod().'(obj, "'.$name.'")';
				default:
					if (is_array($this->getType())) {
						return 'new '.$this->javaType.'(obj.getJSONObject("'.$name.'"))';
					}
					switch ($this->getJavaType()) {
						case 'String':
							return 'obj.getString("'.$name.'")';
						default:
							
							return '/* '.$this->getJavaType().' ('.$this->getType().') */';
					}
			}
		} else {
			switch ($this->getType()) {
				case 'string':
					return 'parseString(obj, "'.$name.'")';
				case 'integer':
					return 'parseInt(obj, "'.$name.'")';
				case 'boolean':
					return 'parseBoolean(obj, "'.$name.'")';
				case 'number':
					return 'parseDouble(obj, "'.$name.'")';
				case 'Array': // "real" array, just return object
				case 'object':
					return 'obj.has("'.$name.'") ? new '.$this->getJavaType().'(obj.getJSONObject("'.$name.'")) : null';
				case 'array':
					if ($this->getArrayType()->getType() == 'string') {
						return 'getStringArray(obj, "'.$name.'")';
					}
					if ($this->getArrayType()->getType() == 'integer') {
						return 'getIntegerArray(obj, "'.$name.'")';
					}
					return $this->getArrayType()->getJavaType().'.'.$this->getArrayType()->getJavaArrayCreatorMethod().'(obj, "'.$name.'")';
				default:
					if (is_array($this->getType())) {
						return 'new '.$this->javaType.'(obj.getJSONObject("'.$name.'"))';
					}
					switch ($this->getJavaType()) {
						case 'String':
							return 'parseString(obj, "'.$name.'")';
						default:
							return '/* '.$this->getJavaType().' ('.$this->getType().') */';
					}
			}
		}
	}
	public function getJavaNullValue() {
		switch ($this->getJavaType()) {
			case 'int':
				return '-1';
			default:
				return 'null';
		}
	}
	public function getJavaParent() {
		if ($this->extends) {
			return $this->getExtends()->getJavaType();
		} else {
			if ($this->isInner) {
				return null;
			} else {
				self::addImport('AbstractModel');
				return 'AbstractModel';
			}
		}
	}
	public function getJavaArrayCreator($i) {
		$i += 2;
		$content = '';
		/*
			 public static ArrayList<Cast> getArray(String key, JSONObject obj) {
				if (obj.has(key)) {
					final JSONArray a = obj.getJSONArray(key);
					final ArrayList<Cast> l = new ArrayList<Cast>(a.length());
					for (int i = 0; i < a.length(); i++) {
						l.add(new Cast(a.getJSONObject(i)));
					}
					return l;
				}
				return new ArrayList<Cast>(0);
			} 
		 */
		$t = $this->getJavaType();
		$content .= $this->r($i, sprintf('public static ArrayList<%s> %s(JSONObject obj, String key) throws JSONException {', $t, $this->getJavaArrayCreatorMethod()));
		$content .= $this->r($i, sprintf('	if (obj.has(key)) {'));
		$content .= $this->r($i, sprintf('		final JSONArray a = obj.getJSONArray(key);'));
		$content .= $this->r($i, sprintf('		final ArrayList<%s> l = new ArrayList<%s>(a.length());', $t, $t));
		$content .= $this->r($i, sprintf('		for (int i = 0; i < a.length(); i++) {'));
		$content .= $this->r($i, sprintf('			l.add(new %s(a.getJSONObject(i)));', $t));
		$content .= $this->r($i, sprintf('		}'));
		$content .= $this->r($i, sprintf('		return l;'));
		$content .= $this->r($i, sprintf('	}'));
		$content .= $this->r($i, sprintf('	return new ArrayList<%s>(0);', $t));
		$content .= $this->r($i, sprintf('}'));
		self::addImport('ArrayList');
		return $content;
	}
	public function getJavaArrayCreatorMethod() {
		return 'get'.str_replace('.', '', $this->getJavaType()).'List';
	}
	
	/**
	 * This parses through the eventual properties and cross-links any objects.
	 * 
	 * This should be the second run.
	 */
	public function reference() {
		$this->parseJavaName();
		$this->parseMultiObject();
		if (count($this->properties)) {
			foreach ($this->properties as $prop) {
				$prop->reference();
			}
		}
		if ($this->arrayType) {
			$this->arrayType->reference();
		}
		// add to classes array so we can generate the java classes easily (but only "global objects").
		if ($this->id) {
			if (!array_key_exists($this->javaClass, self::$classes)) {
				self::$classes[$this->javaClass] = array();
			}
			self::$classes[$this->javaClass][$this->javaType] = $this;
		}
	}
	
	/**
	 * This runs a few checks in order to confirm that the model was parsed 
	 * and understood correctly.
	 * 
	 * This should be run after reference().
	 */
	public function assert() {
		$this->p('*** ASSERTING: '.$this->name);
		$this->assertRef();
		$this->assertType();
		$this->assertArray();
		if (count($this->properties)) {
			foreach ($this->properties as $prop) {
				$prop->assert();
			}
		}
		if ($this->arrayType) {
			$this->arrayType->assert();
		}
	}
	public function dump() {
		$line = '';
		if ($this->isInner) {
			$line .= '-> ';
		}
		$line .= $this->name;
		$line .= ' ('.($this->isNative() ? '*** ' : '').$this->getJavaType().')';
		if ($this->arrayType) {
			$line .= ' [ARRAY '.$this->arrayType->getType().']';
		}
		$line .= ' [TYPE '.($this->type ? $this->type : '-').'/'.$this->getType().']';
		
		$innerClasses = $this->getInnerClasses();
		if (count($innerClasses)) {
			$line .= ' [INNER: ';
			foreach ($innerClasses as $c) {
				$line .= $c->name.' ';
			} 
			$line = substr($line, 0, -1).']';
		}
		
		$this->p('... '.$line);
		if (count($this->properties)) {
			foreach ($this->properties as $prop) {
				$prop->dump();
			}
		}
		if ($this->arrayType) {
			$this->arrayType->dump();
		}
	}	
	
	private function parseExtends($obj) {
		if (isset($obj->extends) && is_array($obj->extends)) {
			$this->p('  - WARNING: Multiple heritage detected.');
			// TODO treat correctly
			$this->extends = $obj->extends[0];
		} else if (isset($obj->extends)) {
			$this->extends = $obj->extends;
		}
	}
	private function parseRef($obj) {
		$ref = '$ref';
		if (!is_object($obj)) {
			throw new Exception('Cannot parse reference from non-object ('.$obj.').');
		}
		if (property_exists($obj, '$ref')) {
			$this->ref = $obj->$ref;
		}
	}
	private function parseEnums($obj) {
		if (!isset($obj->enums)) {
			return;
		} 
		$this->enums = array();
		foreach($obj->enums as $enum) {
			if (!is_string($enum)) {
				throw new Exception('Enum must a string ('.json_encode($enum).').');
			}
			$this->enums[] = $enum;
		}
		$this->p('  - Parsed '.count($this->enums).' enums: '.json_encode($this->enums));
	}
	
	private function parseProperties($obj = null) {
		$obj = $obj ? $obj : $this->obj;
		if (!isset($obj->properties)) {
			return;
		}
		
		if (!is_object($obj->properties)) {
			throw new Exception('Properties must be an object!');
		}
		
		foreach ($obj->properties as $name => $prop) {
			$this->properties[$name] = new XBMC_JSONRPC_Type($this->indent + 1, $name, $prop, true, false, $this);
			if ($this->properties[$name]->isInner) {
				$this->innerClasses[] = $this->properties[$name];
			}
		}
		$this->p('  - Found '.count($this->properties).' properties, of which '.count($this->innerClasses).' are anonymous.');
	}
	private function parseItems() {
		if (isset($this->obj->items)) {
			$this->arrayType = new XBMC_JSONRPC_Type($this->indent + 1, $this->name, $this->obj->items, false, true, $this);
			if ($this->arrayType->isInner) {
				$this->innerClasses[] = $this->arrayType;
			}
			$this->p('  - We\'re an array ('.$this->arrayType->type.').');
		}
	}
	protected function parseJavaName($class = '') {
		$parts = explode('.', $this->getInstance()->name);
		$this->javaClass = $class ? $class : $parts[0].'Model';
		switch (count($parts)) {
			case 1:
				$this->javaClass = 'GlobalModel';
				$this->javaType = $this->isInner ? ucfirst($parts[0]) : $parts[0];
				break;
			case 2:
				$this->javaType = $parts[1];
				break;
			case 3:
				if (in_array($parts[1], array('Details', 'Fields'))) {
					$this->javaType = $parts[2].$parts[1];
				} else {
					$this->javaType = $parts[1].$parts[2];
				}
				break;
			default:
				array_splice($parts, 0, 1);
				$this->javaType = implode('', $parts);
				break;
		}
	}
	private function parseMultiObject() {
		if (!$this->isMultiObjectType()) {
			return;
		}
		
		$propsFromType = new stdClass();
		$propsFromType->properties = new stdClass();
		foreach ($this->getType() as $type) {
			foreach ($type->properties as $pname => $pval) {
				$propsFromType->properties->$pname = $pval;
			}
			$this->innerTypes[] = new XBMC_JSONRPC_ParamType($type, $this->javaType);
		}
		$this->parseProperties($propsFromType);
	}
	
	private function assertRef() {
		if ($this->ref && !array_key_exists($this->ref, self::$global)) {
			throw new Exception('Cannot find referenced type "'.$this->ref.'".');
		}
		if ($this->extends) {
			if (is_array($this->extends)) {
				foreach($this->extends as $e) {
					if (!array_key_exists($e, self::$global)) {
						throw new Exception('Cannot find parent type "'.$e.'" (multiple heritage).');
					}
				}
			} else {
				if (!array_key_exists($this->extends, self::$global)) {
					throw new Exception('Cannot find parent type "'.$this->extends.'".');
				}
			}
		}
		if ($this->ref && $this->extends) {
			throw new Exception('Referencing ('.$this->ref.') AND extending ('.$this->extends->name.') doesn\'t make any sense.');
		}		
	}
	private function assertType() {
		if (!$this->ref && !$this->extends && !$this->type) {
			throw new Exception('Cannot find type without "type" set, no reference and no parent.');
		}
		if (is_array($this->extends)) {
			$this->p('  - WARNING: Multiple heritage detected: '.json_encode($this->extends));
			// TODO treat correctly
		} else {
			if ($this->extends && !array_key_exists($this->extends, self::$global)) {
				throw new Exception('Referenced super type "'.$this->extends.'" not found.');
			}
			if ($this->ref && !array_key_exists($this->ref, self::$global)) {
				throw new Exception('Referenced type "'.$this->extends.'" not found.');
			}
		}
	}
	private function assertArray() {
		if ($this->getType() == 'array' && $this->isArray) {
			throw new Exception('Type is "array" but isArray flag is not set.');
		}
		if ($this->getType() != 'array' && $this->getArrayType()) {
			throw new Exception('There are items but the type is not "array": '.json_encode($this->obj));
		}
		if ($this->getType() == 'array' && !$this->getArrayType()) {
			throw new Exception('Type is "array" but there a no items set: '.json_encode($this->obj));
		}
		if ($this->type == 'array' && !$this->arrayType) {
			throw new Exception('Type is array but item type is unknown for '.$this->name.': '.json_encode($this->obj));
		}
	}	

	/**
	 * Sets a member if available if the provided object.
	 * @param stdClass $obj
	 * @param string $name
	 */
	protected function set($obj, $name) {
		if (isset($obj->{$name})) {
			$this->$name = $obj->{$name};
		}
	}
	protected function p($msg) {
		if (self::$print) {
			$indent = $this->indent * 4;
			printf("%' ".$indent."s%s\n", '', $msg);
		}
	}
	protected function r($i, $line) {
		return sprintf("%'\t".$i."s%s\n", '', $line);
	}	
	
	/**
	 * This goes through every type and copies the attributes.
	 *  
	 * @param stdClass $types "types" node from introspect. 
	 */
	private static function readAll($types) {
		// first run just reads and sets the attributes of each object.
		foreach ($types as $name => $type) {
			$t = new XBMC_JSONRPC_Type(0, $name, $type);
			
			if (array_key_exists($t->id, self::$global)) {
				throw new Exception('Duplicate ID? Type "'.$t->$name.'" (id "'.$t->id.'") is already saved!');
			}
		
			// add to global array
			self::$global[$t->id] = $t;
		}
	}
	private static function referenceAll() {
		// second run updates references
		foreach (self::$global as $type) {
			$type->reference();
		}
	}
	private static function assertAll() {
		// third run asserts some stuff
		foreach (self::$global as $type) {
			$type->assert();
		}
	}
	private static function printAll() {
		// now all is checkedrun asserts some stuff
		foreach (self::$global as $type) {
			$type->dump();
		}
	}
	
	/**
	 * Compiles type classes for all types.
	 * 
	 * @param stdClass $types "types" node from introspect. 
	 */
	public static function processAll($types) {
		self::readAll($types);
		self::referenceAll();
		self::assertAll();
		self::printAll();
	}
	
	private static $attrs = array('default', 'enums', 'extends', 'id', 
		'items', 'type', 'properties', 'required', '$ref', 'description', 
		'minLength', 'minItems', 'minimum', 'maximum', 'additionalProperties', 
		'uniqueItems');
	
	
	public static function addImport($import) {
		self::$currentImports[] = self::$imports[$import];
		self::$currentImports = array_unique(self::$currentImports);
		sort(self::$currentImports);
	} 
	public static function clearImports() {
		self::$currentImports = array();
	}
	
	public static function compileAll($folder, $header = '') {
		foreach (self::$classes as $className => $types) {
			if (count($types)) {
				self::clearImports();
				$filename = $folder.'/'.$className.'.java';
				$imports = '';
				$class = '';
				$inner = '';
				
				$class .= "\npublic final class ".$className." {\n";
				foreach($types as $typeName => $type) {
					if (self::HALT_AT && $type->id != self::HALT_AT) {
						continue;
					}
					if (!in_array($type->name, self::$ignoreTypes)) {
						$inner .= $type->compile(0);
					}
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
				$content .= $class;
				$content .= $inner;
				$content .= '}';
				
				if (self::HALT_AT) {
					print $content."\n";
				} else {
					file_put_contents($filename, $content);
				}
			}
		}
	}	
	public function compile($i, $jsonConstructor = true) {
		
		if (in_array($this->name, self::$emptyTypes)) {
			$this->p('*** NOT COMPILING empty type '.$this->name.'.');
			return;
		} 
		
		if ($this->isNative() && !count($this->enums)) {
			$this->p('*** NOT COMPILING native type '.$this->name.'.');
			return;
		}

		if (!count($this->enums) && !count($this->properties)) {
			if ($this->arrayType) {
				$this->p('*** COMPILE: '.$this->name.': Compiling item type instead of base type.');
				return $this->arrayType->compile($i);
			} else {
				throw new Exception('Cannot compile with no item type, no enum and no props ('.$this->name.')!');
			}
		}
		if (in_array($this->name, self::$emptyTypes) || count($this->enums)) {
			return $this->compileEnum($i);
		} else {
			return $this->compileClass($i, $jsonConstructor);
		}
	
	}
	public function compileNativeConstructor($i) {
		$i++;
		$i++;
		// don't support inherited native constructors for now
		if ($this->extends) {
			return '';
		}
		$constructArgs = '';
		foreach ($this->properties as $name => $t) {
			$constructArgs .= $t->getJavaType().' '.$name.', ';
		}
		$constructArgs = substr($constructArgs, 0, -2);
		$content = '';
		$content .= $this->r($i, sprintf('public %s(%s) {', $this->javaType, $constructArgs));
		$i++;
		foreach ($this->properties as $name => $t) {
			$content .= $this->r($i, sprintf('this.%s = %s;', $name, $name));
		}
		$i--;
		$content .= $this->r($i, sprintf('}'));
		
		return $content;
	}
	public function compileMultitype($i, $type) {
		if (!is_array($type->properties)) {
			throw new Exception('Cannot render inner type class with no properties.');
		}
		$constructArgs = '';
		$localMembers = array();
		foreach ($type->properties as $name => $t) {
			$constructArgs .= $t->getJavaType().' '.$name.', ';
			$localMembers[] = $name;
		}
		$constructArgs = substr($constructArgs, 0, -2);
		
		$superConstuctorArgs = '';
		foreach ($this->properties as $name => $prop) {
			if (in_array($name, $localMembers)) {
				$superConstuctorArgs .= $name.', ';
			} else {
				$superConstuctorArgs .= $prop->getJavaNullValue().', ';
			}
		}
		$superConstuctorArgs = substr($superConstuctorArgs, 0, -2);
		
		$i++;
		$content = '';
		$content .= $this->r($i, sprintf('public class %s extends %s {', $type->name, $this->javaType));
		$i++;
		$content .= $this->r($i, sprintf('public %s(%s) {', $type->name, $constructArgs));
		$content .= $this->r($i, sprintf('	super(%s);', $superConstuctorArgs));
		$content .= $this->r($i, sprintf('}'));
		$i--;
		$content .= $this->r($i, sprintf('}'));
		return $content;
	}
	public function compileClass($i, $jsonConstructor = true) {
		
		self::addImport('JSONArray');
		self::addImport('JSONObject');
		self::addImport('JSONException');
		
		$this->p('*** COMPILE CLASS: '.$this->name);
		
		// class header
		$content = $this->r($i, '	/**');
		if ($this->id) {
			$content .= $this->r($i, sprintf('	 * %s', $this->id));
			$content .= $this->r($i, '	 * <p/>');
		}
		if ($this->description) {
			$content .= $this->r($i, sprintf('	 * %s', $this->description));
			$content .= $this->r($i, '	 * <p/>');
		}
		$content .= $this->r($i, '	 * <i>This class was generated automatically from XBMC\'s JSON-RPC introspect.</i>');
		$content .= $this->r($i, '	 */');
		$content .= $this->r($i, sprintf('	public static class %s%s {', $this->javaType, $this->getJavaParent() ? ' extends '.$this->getJavaParent() : ''));
		if (!$this->isInner) {
			$content .= $this->r($i, sprintf('		public final static String TYPE = "%s";', $this->name));
		}
		foreach ($this->properties as $name => $property) {
			$content .= $this->r($i, sprintf('		public final %s %s;', $property->getJavaType(), $name));
		}
		
		// json constructor
		if ($jsonConstructor) {
			$content .= $this->r($i, sprintf('		public %s(JSONObject obj) throws JSONException {', $this->javaType));
			if ($this->extends) {
				$content .= $this->r($i, sprintf('			super(obj);'));
			}
			if (!$this->isInner) {
				$content .= $this->r($i, sprintf('			mType = TYPE;'));
			}
			foreach ($this->properties as $name => $property) {
				$content .= $this->r($i, sprintf('			%s = %s;', $name, $property->getJavaValue($name)));
			}
			$content .= $this->r($i, sprintf('		}'));
		}
		
		// native constructor
		$content .= $this->compileNativeConstructor($i);
		
		// inner "multi" types classes
		foreach ($this->innerTypes as $type) {
			$content .= $this->compileMultitype($i + 1, $type);
		}
		
		// array creator
		if ($jsonConstructor) {
			$content .= $this->getJavaArrayCreator($i);
		}

		// inner classes
		foreach ($this->getInnerClasses() as $class) {
			$content .= $class->compile($i + 1);
		}
		
		$content .= $this->r($i, sprintf('	}'));
		
		return $content; 
	}
	public function compileEnum($i) {
		$this->p('*** COMPILE ENUM: '.$this->name);
		$content = $this->r($i, sprintf('	public interface %s {', $this->javaType ? $this->javaType : $this->name)); 
		if (count($this->enums)) {
			foreach($this->enums as $enum) {
				$content .= $this->r($i, sprintf('		public final String %s = "%s";', strtoupper($enum), $enum)); 
			}
		}
		$content .= $this->r($i, sprintf('	}'));
		return $content; 
	}
	
}