#! /usr/bin/php -n
<?php
//
	// PropertyMaster (TM)
	// Copyright (C) 2011 by David Hoerl
	// 
	// Permission is hereby granted, free of charge, to any person obtaining a copy
	// of this software and associated documentation files (the "Software"), to deal
	// in the Software without restriction, including without limitation the rights
	// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	// copies of the Software, and to permit persons to whom the Software is
	// furnished to do so, subject to the following conditions:
	// 
	// The above copyright notice and this permission notice shall be included in
	// all copies or substantial portions of the Software.
	// 
	// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
	// THE SOFTWARE.
	//
	// Version 1.0.2, April 28 2011
	//
	// Dedicated to Mario Lurig, author of the "PHP Reference: Beginner to Intermediate PHP5" book,
	// without which this script could not have been written.
	//
	// Many thanks to Andy Lee, CocoaHeads NYC leader, for suggesting several program features,
	// bug reports, and moral support.

	// May be redefined in .property_master files
	$out_prop_space = 0;						// Add a space between the class name and the qualifiers
	$out_prop_spaces = 1;						// If set, separate property qualifiers with ", ", otherwise just ","
	$out_view_did_unload = 1;					// Output a viewDidUnload method
	$out_synthesize_always = 1;					// Always generate @synthesize statements, even if no ivars converted
	$out_classification_comments = 1;			// Output comments grouping outlets, class variables, and class extension variables
	$out_non_atomic = 1;						// Force all properties to "nonatomic"

	$search_for_delegates_in_cmts = 1;			// If comment contains "delegate" then prepend name.delegate = nil to release statement.
	$delegate_prefix = "DelegateToMe";			// Use as a class prefix to indicate the class takes a delegate ("#define DelegateToMe" in .php file). Set to "" to disable the feature.
	$delegates_prefix = "DelegatesToMe\\(([^)]*)\\)";	// Use as a class prefix to indicate the class takes multiples delegate ("#define DelegatesToMe()" in .php file) - regex
	$delegates_prefix_action = "name.property = nil";	// each of the above properties will get this treatment in dealloc/viewDidUnload
	$prop_file = "/tmp/PM_prop.txt";			// Local file used to store the last class's output, for use with class extensions. Set to "" to disable the feature.

	// .property_master files can add or remove these
	// Regular Expressions to match ivars that are delegates (or weak).
	$weak_props = array("^.*delegate.*$");	// matched against the ivar name
	$protos_with_weak_props = array("^.*delegate.*$"); //matched against protocols implemented by the ivar
	
	// These classes will release with a prepended statement (e.g., "ivar.delegate = nil, [ivar release];")
	$special_classes = array(
								"UIWebView" => "name.delegate = nil, [name stopLoading]", 
								"NSURLConnection" => "[name cancel]",
								"NSXMLParser" => "name.delegate = nil, [name abortParsing]"
								// "myClass" => "name.delegate = nil"
						);

	// Look for user additions in the current directory chain, then home directory
	if($argc == 2 && $argv[1] == "Automator") {
		$handle = popen('/usr/bin/osascript -e \'tell application "Xcode" to get path of active project document\'', 'r');
		$cwd = fread($handle, 1024);
		pclose($handle);
		$automator = 1;
	} else {
		$cwd = getcwd();
		$automator = 0;
	}
	$dirs = explode("/", $cwd);
	$count = count($dirs);
	for($i=1; $i<$count; $i++) {
		$dir = implode("/", $dirs);
		$file = $dir . "/" . ".property_master";
		if(file_exists($file)) {
			$str = file_get_contents($file);
			eval($str);
		}
		array_pop($dirs);
	}
	$file = getenv("HOME") . "/" . ".property_master";
	if(file_exists($file)) {
		$str = file_get_contents($file);
		eval($str);
	}

	define("OUT_PROP_SPACE", $out_prop_space);
	define("OUT_PROP_SPACES", $out_prop_spaces);
	define("OUT_VIEW_DID_UNLOAD", $out_view_did_unload);
	define("OUT_SYNTHESIZE_ALWAYS", $out_synthesize_always);
	define("OUT_CLASSIFICATION_COMMENTS", $out_classification_comments);
	define("OUT_NON_ATOMIC", $out_non_atomic);
	define("SEARCH_FOR_DELEGATE_IN_CMTS", $search_for_delegates_in_cmts);
	define("DELEGATE_TO_ME", $delegate_prefix);
	define("DELEGATES_TO_ME", $delegates_prefix);
	define("DELEGATES_TO_ME_ACTION", $delegates_prefix_action);
	define("PROP_FILE", $prop_file);	// Local file used to store the last class's output, for use with class extensions
												// Set PROP_FILE to "" to disable the feature.
												
	// Properties that are "weak" or who implement a delegate protocol use "assign" instead of "retain". 
	// NOTE: all patterns below use case-insensitive matching.

	$stderr = fopen("php://stderr","w");
	
	$stdin = fopen("php://stdin","r");
	$selection = fread($stdin, 1000000 );
	fclose($stdin);
	if($automator) {
		// For some reason newlines turned into carriage returns somewhere
		$selection = str_replace("\r", "\n", $selection);
	}

	$class_array = str_split($selection);
	$len = count($class_array);
	if($len <= 1) { // Maybe EOF is considered a char?
		fprintf($stderr, "Select all ivars and existing properties. The output is put on the clipboard.");
		exit(1);
	}

	$cl_properties = array();
	$clex_properties = array();
	$ivars = array();
	$class_name = "unknown";
	$class_extension = 0;
	$at_type = "";

	$class_array[] = "\n"; // helps parsing when the selection does not include a final new line
	$one_line = "";
	for ($idx = 0; $idx < count($class_array); ++$idx) {
		$c = $class_array[$idx];
		if($c == ' ' || $c == "\t" || $c == "\n" || $c == "{" || $c == "}") continue;
		$one_line = getOneLine($class_array, $idx);

		//echo "PLINE=$one_line\n"; continue;
		// if(strlen($one_line) <= 4) continue; // ;//space
		
		$c = substr($one_line, 0, 1);
		//echo "START $c\n";
		switch($c) {
		case "@":
			// printf("AT TYPE %s ONELINE %s\n", $at_type, $one_line);
			if(strpos($one_line, "@property") !== FALSE) {
				if($GLOBALS["class_extension"])		addProperty($one_line, $clex_properties);
				else								addProperty($one_line, $cl_properties);
			} else
			if(strpos($one_line, "@end") !== FALSE) {
				if($at_type == "interface") {
					$at_type = "end";
					break 2;
				}
			} else
			if(strpos($one_line, "@interface") !== FALSE) {
				$at_type = "interface";
				//$reg_ex = "^([^(]+)\\(([^)]+)\\) ?" . $reg_ex_type . " ?(<[^>]+>)? ?([][A-Za-z0-9_*]+),?([^;]*);(.*)$";
				$reg_ex = "^@interface\\|([A-Za-z0-9_]+)(\\(?\\)?)";
				$count = ereg($reg_ex, $one_line, $match);
				if(count($match) == 3) {
					$class_name = $match[1];
					if($match[2] == "()") {
						// echo "FOUND CLASS_EXTENSION\n";
						$class_extension = 1;
						
						if(strlen(PROP_FILE) && file_exists(PROP_FILE)) {
							$fp = fopen(PROP_FILE, "r");
							if($fp !== FALSE) {
								$str = fread($fp, filesize(PROP_FILE));
								$archive = unserialize($str);
								if($class_name == $archive["name"]) {
									$ivars = $archive["ivars"];
									$cl_properties = $archive["cl_properties"];
								}
								fclose($fp);
							}
						}
					} 
				}
				if(strlen(PROP_FILE) && file_exists(PROP_FILE)) unlink(PROP_FILE); // Thought is to avoid cacheing out of date information
			} else
			if(strpos($one_line, "@implementation") !== FALSE) {
				$at_type = "implementation";
				break 2;
			}
			break;
		case ";":
			//echo "FOUND COMMENT LINE\n";
			break;
		case "+":
		case "-":
			break;
		default:
			//echo "FOUND IVAR\n";
			addIvar($one_line, $ivars);
			break;
		}
	}
	//var_dump($cl_properties);
	//var_dump($ivars);
	//exit(0);

	foreach($ivars as $key => $var) {
		if(array_key_exists($key, $cl_properties)) {
			//echo "GET RID OF $key\n";
			unset($ivars[$key]);
		}
	}
	if($class_extension) {
		foreach($clex_properties as $key => $var) {
			if(array_key_exists($key, $ivars)) {
				//echo "GET RID OF $key\n";
				unset($clex_properties[$key]);
			} else
			if(array_key_exists($key, $cl_properties)) {
				//echo "GET RID OF $key\n";
				unset($clex_properties[$key]);
			}
		}
	}
	
	output($ivars, $cl_properties, $clex_properties);

	if(!$class_extension && $class_name && strlen(PROP_FILE)) {
		$archive = array();
		$archive["name"] = $class_name;
		$archive["ivars"] = $ivars;
		$archive["cl_properties"] = $cl_properties;

		$fp = fopen(PROP_FILE, "w");
		if($fp !== FALSE) {
			$str = serialize($archive);
			fwrite($fp, $str);
			fclose($fp);
		}
	}
	exit(0);

function addProperty(&$one_line, &$properties)
{
	// echo "addProperty $one_line\n";
	if(strpos($one_line, "@property") === FALSE) {
		echo "NO PROPERTY FOUND\n";
		return;
	}
	if(strpos($one_line, "(") === FALSE) {
		// property with no qualifiers
		$one_line = str_replace("@property", "@property(nonatomic,assign)", $one_line);
	}
	$array = array();
	$outlets_to_nil = array();
	$delegate = 0;

	$one_line = str_replace("IBOutlet ", "", $one_line, $outlet);
	if(DELEGATE_TO_ME) $one_line = str_replace(DELEGATE_TO_ME . " ", "", $one_line, $delegate);
	if(DELEGATES_TO_ME) {
		$count = ereg(DELEGATES_TO_ME, $one_line, $array);
		if($count > 0) {
			$one_line = str_replace($array[0] . " ", "", $one_line);
			$outlets_to_nil = explode(",", $array[1]);
		}
	}
	//echo "MODIFIED PLINE $one_line\n";

	// @property + "(" + property_list + ") " +  type + [maybe space] + first_var + last_bit + ';' + optional_comment
	$reg_ex_type = "((struct [A-Za-z0-9_]+)|(union [A-Za-z0-9_]+)|(enum [A-Za-z0-9_]+)|([A-Za-z0-9_]+))";
	$reg_ex = "^([^(]+)\\(([^)]+)\\) ?" . $reg_ex_type . " ?(<([^>]+)>)? ?([][A-Za-z0-9_*]+),?([^;]*);(.*)$";
	$count = ereg($reg_ex, $one_line, $array);

	/*
	echo "COUNT=" . count($array) . "\n";
	for($i=1; $i<count($array); ++$i) {
		echo "PART[$i] = \"$array[$i]\" \n";
	}
	*/
	if($array[1] == "@property" && count($array) == 13) {
		$prop = array();
		$prop["prop"] = explode(",", $array[2]);
		$prop["type"] = $array[3];
		$prop["prtl"] = $array[8] ? ($array[8] . " ") : "";
		
		$prop["weak"] = 0;
		if($array[9]) {
			$proto_array = explode(",", $array[9]);
			foreach($proto_array as $var) {
				$proto = trim($var);
			
				$count = 0;
				foreach($GLOBALS["protos_with_weak_props"] as $reg_ex) {
					$count = eregi($reg_ex, $proto);
					if($count) break;
				}
				$prop["weak"] = $count;
			}
		}

		$prop["outl"] = $outlet;	// count, basically 0 or 1

		$vars = array();
		$vars[] = $array[10];
		//var_dump($vars);
		if($array[11]) {
			$final = explode(",", $array[11]);
			//var_dump($final);
			for($i=0; $i<count($final); ++$i) {
				$vars[] = $final[$i];			
			}
		}
		$prop["cmmt"] = $array[12];
		$prop["delg"] = ((SEARCH_FOR_DELEGATE_IN_CMTS && stripos($prop["cmmt"], "delegate") !== FALSE) ?  1 : 0) + $delegate;
		$prop["oton"] = $outlets_to_nil;
		
		for($i=0; $i<count($vars); ++$i) {
			$prop["ivar"] = 0;
			$orig_name = $vars[$i];
			$name = str_replace("**", "", $orig_name, $ptr);
			if($ptr) {
				$stars = "**";
			} else {
				$name = str_replace("*", "", $orig_name, $ptr);
				$stars = $ptr ? "*" : "";
			}
			$prop["name"] = $name;
			$prop["*ptr"] = $stars;
			$type = $prop["type"];
			$firstC = substr($type, 0, 1);
			$firstCu = strtoupper($firstC);
			//$prop["retn"] = ($type == "id" || ($ptr == 1 && $firstC == $firstCu)) ? 1 : 0; // use this for dealloc
			$prop["retn"] = (array_search("retain", $prop["prop"]) !== FALSE || array_search("copy", $prop["prop"])) !== FALSE ? 1 : 0;
			$prop["indx"] = count($properties);

			$count = 0;
			foreach($GLOBALS["weak_props"] as $reg_ex) {
				$count = eregi($reg_ex, $prop["name"]);
				if($count) break;
			}
			$prop["weak"] += $count;

			if($prop["weak"] && $prop["retn"]) {
				printf("// Warning: property named \"%s\" is retained but matches a \"weak\" pattern.\n", $prop["name"]);
			}

			$properties[$name] = $prop;
		}
		//var_dump($properties);
	}
}

function addIvar(&$one_line, &$ivars)
{
	// echo "addIvar $one_line\n";
	$one_line = str_replace("IBOutlet ", "", $one_line, $outlet);
	$array = array();

	// @property + "(" + property_list + ") " +  type + [maybe space] + first_var + last_bit + ';' + optional_comment
	$reg_ex_type = "((struct [A-Za-z0-9_]+)|(union [A-Za-z0-9_]+)|(enum [A-Za-z0-9_]+)|([A-Za-z0-9_]+))";
	$reg_ex = "^" . $reg_ex_type . " ?(<([^>]+)>)? ?([][A-Za-z0-9_*]+),?([^;]*);(.*)$";
	$count = ereg($reg_ex, $one_line, $array);
	
	/*
	echo "COUNT=" . count($array) . "\n";
	for($i=1; $i<count($array); ++$i) {
		echo "PART[$i] = \"$array[$i]\" \n";
	}
	*/
	
	$prop = array();
	if(count($array) == 11) {
		$prop["type"] = $array[1];
		$prop["prtl"] = $array[6] ? ($array[6] . " ") : "";
		$prop["weak"] = 0;
		if($array[7]) {
			$proto_array = explode(",", $array[7]);
			foreach($proto_array as $var) {
				$proto = trim($var);
			
				$count = 0;
				foreach($GLOBALS["protos_with_weak_props"] as $reg_ex) {
					$count = eregi($reg_ex, $proto);
					if($count) break;
				}
				$prop["weak"] = $count;
			}
		}
		$prop["outl"] = $outlet;	// count, basically 0 or 1

		$vars = array();
		$vars[] = $array[8];
		//var_dump($vars);
		if($array[9]) {
			$final = explode(",", $array[9]);
			//var_dump($final);
			for($i=0; $i<count($final); ++$i) {
				$vars[] = $final[$i];			
			}
		}
		$prop["cmmt"] = $array[10];
		$prop["delg"] = (SEARCH_FOR_DELEGATE_IN_CMTS && stripos($prop["cmmt"], "delegate") !== FALSE) ?  1 : 0; 
		//var_dump($vars);
		for($i=0; $i<count($vars); ++$i) {
			$prop["ivar"] = 1;
			$orig_name = $vars[$i];
			$name = str_replace("**", "", $orig_name, $ptr);
			if($ptr) {
				$stars = "**";
			} else {
				$name = str_replace("*", "", $orig_name, $ptr);
				$stars = $ptr ? "*" : "";
			}
				
			$prop["name"] = $name;
			$prop["prop"] = array("nonatomic", $ptr ? "retain" : "assign" );

			$prop["*ptr"] = $stars;
			$type = $prop["type"];
			$firstC = substr($type, 0, 1);
			$firstCu = strtoupper($firstC);
			$prop["indx"] = count($ivars);

			$count = 0;
			foreach($GLOBALS["weak_props"] as $reg_ex) {
				$count = eregi($reg_ex, $prop["name"]);
				if($count) break;
			}
			$prop["weak"] += $count;
			
			$prop["retn"] = (!$prop["weak"] && ($type == "id" || ($ptr == 1 && $firstC == $firstCu))) ? 1 : 0;
			
			$ivars[$name] = $prop;
			//var_dump($prop);
		}
	}
}	

function output(&$ivars, &$props, &$clexs)
{
	$outlets = 0;
	$deallocs = 0;
	$clexDeallocs = 0;
	$all_props = array_merge($props, $ivars);
	
	foreach($all_props as $var) {
		if($var["outl"]) $outlets++;
	}
	foreach($all_props as $var) {
		if($var["retn"]) $deallocs++;
	}
	foreach($clexs as $var) {
		if($var["retn"]) $clexDeallocs++;
	}

	if(!$GLOBALS["class_extension"]) {
		printf("\n");
		// Do the outlets first (flag == 1)
		foreach($all_props as $var) {
			dump_line($var, 1, "prop");
		}
		// Do the non-outlets last (flag == 0)
		foreach($all_props as $var) {
			dump_line($var, 0, "prop");
		}
	}

	// Only output @synthesis if we had ivars or user wants them
	if(count($ivars) || OUT_SYNTHESIZE_ALWAYS) {
		printf("\n");
		// Do the synthesize (flag specifies synthesize comment)
		if($outlets) {
			if(OUT_CLASSIFICATION_COMMENTS) printf("// Outlets\n");
			foreach($all_props as $var) {
				dump_line($var, 1, "syth");
			}
		}
		if((count($all_props) - $outlets) > 0) {
			if(OUT_CLASSIFICATION_COMMENTS) printf("// Class\n");
			foreach($all_props as $var) {
				dump_line($var, 0, "syth");
			}
		}
		if($GLOBALS["class_extension"] && count($clexs)) {
			if(OUT_CLASSIFICATION_COMMENTS) printf("// Class Extension\n");
			foreach($clexs as $var) {
				dump_line($var, 0, "syth");
			}
		}
	}

	// do the viewWillUnload
	if(OUT_VIEW_DID_UNLOAD && $outlets) {
		printf("\n- (void)viewDidUnload\n{\n");
		foreach($all_props as $var) {
			dump_line($var, 1, "vdul");
		}
		printf("\n\t[super viewDidUnload];\n}\n");
	}
	
	// do the dealloc
	if($deallocs || $clexDeallocs) {
		printf("\n- (void)dealloc\n{\n");
		if($outlets) {
			if(OUT_CLASSIFICATION_COMMENTS) printf("\t// Outlets\n");
			foreach($all_props as $var) {
				dump_line($var, 1, "deal");
			}
		}
		if($deallocs) {
			if(OUT_CLASSIFICATION_COMMENTS) printf("\t// Class\n");
			foreach($all_props as $var) {
				dump_line($var, 0, "deal");
			}
		}
		if($clexDeallocs) {
			if(OUT_CLASSIFICATION_COMMENTS) printf("\t// Class Extension\n");
			foreach($clexs as $var) {
				dump_line($var, 0, "deal");
			}
		}
		printf("\n\t[super dealloc];\n}\n");
	}
}

function dump_line(&$prop, $flag, $style)
{
	// var_dump($prop);
	if($prop["outl"] != $flag) return;

	switch($style) {
	case "prop":
		printf("@property%s(", OUT_PROP_SPACE ? " " : "");
		$didOne = 0;
		$props = $prop["prop"];
		propertyFixer($props);
		foreach($props as $typ) {
			if($didOne) printf(",%s", OUT_PROP_SPACES ? " " : "");
			$didOne = 1;
			printf("%s", $typ);
		}
		printf(") %s%s %s%s%s;%s%s\n",
			$flag ? "IBOutlet " : "",
			$prop["type"],
			$prop["prtl"],
			$prop["*ptr"],
			$prop["name"],
			$prop["cmmt"] ? " // " : "",
			$prop["cmmt"]
		);
		break;

	case "syth":
		printf("@synthesize %s;", $prop["name"]);
		if($prop["ivar"] && $prop["retn"]) printf("\t// New");
		printf("\n");
		break;

	case "vdul":
	case "deal":
		if($prop["retn"] == 0) return;		// not a retained variable
		$append_nl = 0;
		printf("\t");
		$did_special = 0;
		foreach($GLOBALS["special_classes"] as $key => $val) {
			if($key == $prop["type"]) {
				$statement = str_replace("name", $prop["name"], $val);
				do {
					$ostatement = $statement;
					$statement = str_replace("name", $prop["name"], $statement);
				} while($statement != $ostatement);
				printf("%s, ", $statement);
				$did_special = 1;	// do this block first, it gets precedence
				break;
			}
		}
		if(!$did_special) {
			if(count($prop["oton"])) {
				printf("\n");
				foreach($prop["oton"] as $outlet) {
					$statement = str_replace("property", $outlet, DELEGATES_TO_ME_ACTION);
					do {
						$ostatement = $statement;
						$statement = str_replace("name", $prop["name"], $statement);
					} while($statement != $ostatement);
					printf("\t%s;\n", $statement);
					$append_nl = 1;
					$did_special = 1;	// do this block second, probably no one using
				}
				printf("\t");
			}
		}
		if(!$did_special) {
			if($prop["delg"]) printf("%s.delegate = nil, ", $prop["name"]);
		}
		$format = $style == "vdul" ? "self.%s = nil;\n" : "[%s release];\n";
		printf($format, $prop["name"]);
		if($append_nl) printf("\n");
		break;
	}
	return $ret;
}

function propertyFixer(&$props)
{
	$newProps = array();
	if(OUT_NON_ATOMIC || in_array("nonatomic", $props)) $newProps[] = "nonatomic";
	if(in_array("retain", $props)) $newProps[] = "retain";
	else if(in_array("copy", $props)) $newProps[] = "copy";
	else $newProps[] = "assign";
	$retProps = array_merge($newProps, $props);

	$props = array_unique($retProps);
}

// Strip comments and build a single line up to ';' (strip ';')
function getOneLine(&$class_array, &$idx)
{
	$len = count($class_array);
	$newLine = "";
	$comment = "";
	$cpp_comment = 0;
	$c_comment = 0;
	$pound = 0;
	$star = 0;
	$trim = 0;
	$comma = 0;
	$semi = 0;
	$debug = 0;

	$c = "";
	for(; $idx<$len; ++$idx) {
		$last_c = $c;
		$c = $class_array[$idx];
		if($debug) echo "T1: c=$c and last_c=$last_c\n";

		if($c == "\\") {
			$c = $last_c;
			continue;
		}
		
		if($cpp_comment == 2) {
			if($c == "\n") {
				// if($semi == 1) return comment lines
				if($debug) echo "Newline in comment - string=$newLine\n";
				{
					return trim($newLine) . ";" . trim($comment);
				}
				$cpp_comment = 0;
				$newLine .= " ";
			} else {
				$comment .= $c;
				$comment = str_replace("  ", " ", $comment);
			}
			continue;
		}
		if($c_comment == 2) {
			if($last_c == "*" && $c == "/") {
				$c_comment = 0;
				$cpp_comment = 0; // got bumped when the "/" was seen
				$comment = trim($comment);
			} else {
				if($last_c != "") {
					$comment .= $last_c;
					$comment = str_replace("  ", " ", $comment);
				}
			}
			continue;
		}
		if($pound) {
			if($c == "\n") {
				return "";
			}
			continue;
		}
		
		if($debug) echo "T2: c=$c and last_c=$last_c\n";
		switch($c) {
		case "#":
			if(strlen($newline) == 0) {
				$pound = 1;
				continue 2;
			}
			break;
		case "/":
			if($cpp_comment == 1 && $last_c == "/") {
				$cpp_comment = 2;
				$c_comment = 0;
				if(strlen($comment)) $comment .= " ";
				continue 2;
			}
			$c_comment++;
			$cpp_comment++;
			continue 2;
		case "*":
			if($c_comment == 1 && $last_c == "/") {
				$cpp_coment = 0;
				$c_comment = 2;
				$c = "";
				if(strlen($comment)) $comment .= " ";
				continue 2;
			}
			$star = 1;
			break;
		case "\n":
			if($semi == 1) {
				return trim($newLine) . ";";
			}
			// no break intended
		case " ":
		case "\t":
			$c = " ";
			if(strlen($newLine) == 0) continue 2; // ignore leading spaces
			if($last_c == " " || $star || $trim || $comma) {
				continue 2;
			}
			break;
		case "(":
			$trim++;
			break;
		case ")":
			if($trim) $trim--;
			break;
		case "@":
			// All this since someone may have done @property int foo;
			$segment = "";
			$tmpLine = "@";
			$foundProperty = 0;
			$foundQualifier = 0;
			$foundInterface = 0;
			$foundEnd = 0;
			$foundOtherAt = 0;
			for($peekIdx=$idx+1; $peekIdx<$len; $peekIdx++) {
				$peekC = $class_array[$peekIdx];
				$tmpLine = trim($tmpLine . $peekC);
				if($debug) echo "TEMPLINE=$tmpLine\n";
				if(!$foundProperty && !$foundQualifier && !$foundInterface && !$foundEnd && !$foundOtherAt) {
					$segment = trim($segment . $peekC);	// handles space between @ and name
					if($debug) echo "SEGMENT=$segment\n";
				
					switch($segment) {
					case "property":
						$foundProperty = 1;
						break;
					case "public":
					case "private":
					case "protected":
						$foundQualifier = 1;
						break;
					case "interface":
						$foundInterface = 1;
						$tmpLine .= "|"; // readability
						break;
					case "end":
						$foundEnd = 1;
						break;
					case "class":
					case "protocol":
						$foundOtherAt = 1;
						break;
					}
				}
				if($foundProperty) {
					if($peekC == "(") {
						$trim = 1;
						$newLine = "@property(";
						$idx = $peekIdx;
						continue 3;
					}
					if($peekC == ";") break;
				}
				if($peekC == "\n") {
					$idx = $peekIdx;
					if($foundQualifier) {
						return "";
					}
					if($foundInterface) {
						return $tmpLine;
					}
					if($foundEnd) {
						return "@end";
					}
					if($foundOtherAt) {
						return $tmpLine;
					}
					return "";
				}
			}
			break;
		case ",":
			$comma = 1;
			$newLine = trim($newLine);
			break;
		case ";":
			if($debug) echo "WANT TO RETURN $c_comment=$c_comment cpp_comment=$cpp_comment\n";
			if($c_comment == 0 && $cpp_comment == 0) {
				if($debug) echo "CALL RETURN \"" . trim($newLine) . "\"\n";
				$semi = 1;
			}
			break;
		case "{":
		case "}":
			continue 2;
		default:
			$star = 0;
			$comma = 0;
			break;
		}
		//$c_comment = 0;
		//$cpp_comment = 0;

		if($c_comment == 0 && $cpp_comment == 0 && $semi == 0) {
			$newLine .= $c;
		}
	}
	return "";
}
?>
