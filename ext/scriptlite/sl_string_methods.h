#ifndef SL_STRING_METHODS_H
#define SL_STRING_METHODS_H

#include "sl_vm.h"

/* Get a string method as a native function by name */
sl_value sl_string_get_method(sl_vm *vm, zend_string *str, zend_string *method_name);

/* Get a string property (length, or indexed character access) */
sl_value sl_string_get_property(zend_string *str, sl_value key);

#endif /* SL_STRING_METHODS_H */
