Java Class Generator for XBMC's JSON-RPC
========================================

Since introspect gives all the information needed, this is an approach to
compile types and methods automatically into Java classes that can be used to
access the API.

Notes
-----

It's dirty and hacky not specially efficient, but it does its job. Why PHP?
Don't ask.

Usage
-----

Include `library` in your PHP class path. Get the introspect from XBMC, for
example by running something like:

	curl -i -X POST -d '{"jsonrpc": "2.0", "method": "JSONRPC.Introspect", "params": { }, "id": 1}' http://localhost:8080/jsonrpc > xbmc-introspect.json

Create a script that looks something like this:

	try {

		$introspect = new XBMC_JSONRPC_Introspect();
		
		$introspect->parseTypes();
		$introspect->parseMethods();
		$introspect->renderMethods('/tmp/java/src');
		
	} catch (Exception $e) {
		echo "ERROR: ".$e->getMessage()."\n";
		echo $e->getTraceAsString();
		echo "\n";
	}

That's it. Feel free to tweak.

