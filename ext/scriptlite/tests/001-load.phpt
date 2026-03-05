--TEST--
ScriptLite extension loads and classes exist
--EXTENSIONS--
scriptlite
--FILE--
<?php
echo "Extension loaded: " . (extension_loaded('scriptlite') ? 'yes' : 'no') . "\n";
echo "Compiler class: " . (class_exists('ScriptLiteExt\\Compiler') ? 'yes' : 'no') . "\n";
echo "VirtualMachine class: " . (class_exists('ScriptLiteExt\\VirtualMachine') ? 'yes' : 'no') . "\n";
echo "CompiledScript class: " . (class_exists('ScriptLiteExt\\CompiledScript') ? 'yes' : 'no') . "\n";
echo "Legacy ScriptLiteNative\\\\Compiler alias: " . (class_exists('ScriptLiteNative\\Compiler') ? 'yes' : 'no') . "\n";
echo "Legacy ScriptLiteNative\\\\VirtualMachine alias: " . (class_exists('ScriptLiteNative\\VirtualMachine') ? 'yes' : 'no') . "\n";
echo "Legacy ScriptLiteNative\\\\CompiledScript alias: " . (class_exists('ScriptLiteNative\\CompiledScript') ? 'yes' : 'no') . "\n";

$compiler = new ScriptLiteExt\Compiler();
echo "Compiler instantiated: yes\n";

$vm = new ScriptLiteExt\VirtualMachine();
echo "VM instantiated: yes\n";

echo "Version: " . phpversion('scriptlite') . "\n";
echo "Engine class: " . (class_exists('ScriptLiteExt\\Engine') ? 'yes' : 'no') . "\n";
echo "Legacy ScriptLite\\\\Engine present: " . (class_exists('ScriptLite\\Engine') ? 'yes' : 'no') . "\n";
echo "Engine::BACKEND_AUTO: " . (class_exists('ScriptLiteExt\\Engine') ? ScriptLiteExt\Engine::BACKEND_AUTO : 'n/a') . "\n";
?>
--EXPECT--
Extension loaded: yes
Compiler class: yes
VirtualMachine class: yes
CompiledScript class: yes
Legacy ScriptLiteNative\\Compiler alias: yes
Legacy ScriptLiteNative\\VirtualMachine alias: yes
Legacy ScriptLiteNative\\CompiledScript alias: yes
Compiler instantiated: yes
VM instantiated: yes
Version: 0.1.0
Engine class: yes
Legacy ScriptLite\\Engine present: no
Engine::BACKEND_AUTO: auto
