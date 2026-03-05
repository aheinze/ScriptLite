--TEST--
ScriptLite extension loads and classes exist
--EXTENSIONS--
scriptlite
--FILE--
<?php
echo "Extension loaded: " . (extension_loaded('scriptlite') ? 'yes' : 'no') . "\n";
echo "Compiler class: " . (class_exists('ScriptLiteNative\\Compiler') ? 'yes' : 'no') . "\n";
echo "VirtualMachine class: " . (class_exists('ScriptLiteNative\\VirtualMachine') ? 'yes' : 'no') . "\n";
echo "CompiledScript class: " . (class_exists('ScriptLiteNative\\CompiledScript') ? 'yes' : 'no') . "\n";

$compiler = new ScriptLiteNative\Compiler();
echo "Compiler instantiated: yes\n";

$vm = new ScriptLiteNative\VirtualMachine();
echo "VM instantiated: yes\n";

echo "Version: " . phpversion('scriptlite') . "\n";
?>
--EXPECT--
Extension loaded: yes
Compiler class: yes
VirtualMachine class: yes
CompiledScript class: yes
Compiler instantiated: yes
VM instantiated: yes
Version: 0.1.0
