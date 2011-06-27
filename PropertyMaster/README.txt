OVERVIEW

PropertyMaster supports the conversion of ivars into properties, and merges them into an existing set of properties. 

In addition, it assists developers in maintaining correct viewDidUnloads and deallocs in classes where multiple developers edit the code in high pressure environments, and don't always add appropriate releases to viewDidUnload and dealloc. It also supports properties declared solely in class extensions.

PropertyMaster includes customizable logic to classify ivars as either retained or assigned during the conversion (and will generate warnings for existing properties that appear incorrectly defined). It also aides in proper deconstruction of objects, nil'ing a delegate being the simplest case.

This PHP script is offered with a lenient opened source license and can thus be further customized or modified.

DETAILS

The script is reasonably robust, and has the following characteristics:
- pointer ivars with a type having a leading capital letter are classified as objects
- "id" typed ivars are retained
- both C and C++ comments are supported (and regenerated)
- protocols are supported
- when an existing property name matches an ivar name, the ivar is ignored
- existing qualifiers such as "readonly" are retained
- typedefed names, ints, floats, and pointers to such are handled
- named structures, unions, and enums are handled (ie "struct food apple" but not "struct { float x; float y; } name;")
- multiple names per statement are supported (ie int *foo, counter, i, **handle;) but are regenerated one per line
- properties with multiple names are regenerated one per line

The output has the following characteristics:
- IBOutlets come first, followed by class properties, followed by class extension properties
- newly created retained properties get a trailing "// New" comment by default, to remind you to examine class usage and convert to dot usage.
- ivars and properties previously grouped on one line are converted to one per line.
- every property can be forced to have a leading "nonatomic" qualifier, and all have one of "assign", "copy", or "retain".
- each property line will have the same look as all others, both in terms of where nonatomic and retain/assign/copy are located, but also spacing [property attribute spaces can be changed by changing two global variables]
- dealloc and viewDidUnload statements can be customized to prefix other statements prior to the release (ie foo.delegate = nil;)

USAGE

Using the script is as simple as selecting the interface text from "@interface" through "@end" (or all text) and selecting the script in Xcode's script menu. Paste the clipboard into the bottom of the file. Remove the existing ivars and replace existing properties with the newly created merged list.

Copy what's left (synthesize statements, possibly a viewDidUnload method, and a dealloc method), and switch to the implementation file. If you use a class extension with properties, then select that and run PropertyMaster again - it will merge those properties in with the existing set. Select the existing synthesize statements and paste - they will be replaced by the new unified synthesize statements (and the viewDidUnload and dealloc). Cut the methods, and paste them near where your original methods are. Edit as needed.

Properties are placed into one of three buckets: outlets, class properties, and class extension properties. If you converted ivars, they get added to either the class property list or the outlet list after the original properties. Each bucket acts as a FIFO.

Property statements are output outlets first followed by the rest. Synthesize statements output outlets first, class properties, then class extension properties. An optional comment line identifying the start of a section can make it easier to determine where manual additions should go. The idea is to take the pressure off the developer to constantly count properties, then count the viewDidUnload and dealloc statements by hand to assure even minimal correctness.

ViewDidUnload statements output in the same order as the IBOutlets properties, and dealloc statements follow the same order as the synthesize statements.

Once you have customized your environment so that the script produces proper viewDidUnloads and deallocs, you can then rerun the script periodically and after a quick glance replace existing viewDidLoads and deallocs with the generated output.

Warnings may be generated if a current property appears to be a weak property but is retained.

ADVANCED USAGE

The script initializes a handful of properties, then goes looking for ".property_master" files in the project directory and every directory above it except "/", then in the home directory. If a file exists, the script reads the file then evaluates the statements in its current environment. You can thus add, change, or delete any of the settings:

Simple options:

$out_prop_space = 0;						// Add a space between the class name and the qualifiers
$out_prop_spaces = 1;						// If set, separate property qualifiers with ", ", otherwise just ","
$out_view_did_unload = 1;					// output a viewDidUnload method
$out_synthesize_always = 1;					// Always generate @synthesize statements, even if no ivars converted
$out_classification_comments = 1;			// Output comments grouping outlets, class variables, and class extension variables
$out_non_atomic = 1;						// Tag all properties as "nonatomic"

Prepend Statements To Release:

$search_for_delegates_in_cmts = 1;			// If comment contains "delegate" then prepend name.delegate = nil to release statement.
You can indicate that a property has a delegate (probably self) by inserting the word "delegate" (case insensitive) into the comment, for example:
@property (nonatomic, retain) MySpecialClass *obj; // has a delegate

If you use a precompiled header, you can add "#define DelegateToMe" to it, and then prepend classes with this word to indicate the class has a delegate:
@property (nonatomic, retain) DelegateToMe MySpecialClass *obj;
The prefix can be customized by changing "delegates_prefix" in a .property_master file.

Likewise, if you have a more complex class that takes a pointer to "self" for multiple properties (UITableViewDelegate has a delegate and a dataSource property often pointing back to "self"), you can use "DelegatesToMe(property, property,...)". You add "#define DelegatesToMe(...)" to the precompiled header file, then use as:
@property (nonatomic, retain) DelegatesToMe(delegate, dataSource) MySpecialClass *obj;
The action taken for DelegatesToMe can be customized (ie "if(name.property == self) name.property = nil"), and defaults to the standard "self.property = nil;". All "name" matches get converted to the actual property name.

When doing an initial ivar to property conversion, object names are matched to a regular expression to determine if they should be weakly referenced ("assign") or retained.
$weak_props = array("^.*delegate.*$");	// matched against the ivar name (case insensitive)
Thus "MyClass *klgDelegate;" would be assigned, not retained.

Likewise, any protocols subscribed to by the variable are also matched and may indicate a weak reference:
$protos_with_weak_props = array("^.*delegate.*$"); //matched against protocols implemented by the ivar
Thus, "MyClass <processDelegate> *klg;" would be weakly retained.

During the script development, I discoverd that many common classes offer a method to use to bring the object to a controlled stop prior to dealloc. Often you see code that just sets the delegate to nil and leaves the class possibly running. Thus, PropertyMaster offers an initial list (which I hope will grow over time) to use these controlled stop methods:
$special_classes = array(
							"UIWebView" => "[name stopLoading]", 
							"NSURLConnection" => "[name cancel]",
							"NSXMLParser" => "[name abortParsing]"
					);
Note that you can always add to this list, or modify it in your HOME .property_master file with statements such as '$special_classes["mySpecialClass"] = "[name pleaseStop]";'. You can add multiple actions: "MyClass" => "[name doThis], [name doThat], [name waveBye]".


INSTALLATION

If you have Xcode 3 you can create a script under the script menu, and paste the "Parser.php" script into the text section. Set the directory and input to be "Selection", Output to "Place on Clipboard", and Errors "Display in Alert".


For Xcode 4 users, you can (hopefully) get it to work using the Automator based Service file "PropertyMaster" that incorporates this script, which you place in ~/Library/Services. To use it, you would then select text, go to the Services menu, and select PropertyMaster. When it finishes paste where you want the text.

The Automator script should work in any Services savvy app, but it does ask Xcode for the directory of the current frontmost project.

BUGS

When running the script using the Automator-based Service, the system seems to remove blank lines consisting of only a newline. Given that Apple will almost certainly introduce the Script menu back into Xcode 4, and thus obviate the need for this Service, I have no intention (now) of trying to find a workaround for this (like outputting a newline and a space).

FUTURE WORK

Extend the script to look for class extension in a complete implementation file, build a parser to extract the viewDidUnload and dealloc methods, then verify that all retained properties are in face nil'd or released. That would enable the construction of scipts that could ascertain for every class in a project whether all retained objects are properly disposed of. However, it would seem that the clang analyzer should be doing this work, right?

Copyright (c) 2011 David Hoerl
