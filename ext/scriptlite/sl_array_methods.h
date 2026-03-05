#ifndef SL_ARRAY_METHODS_H
#define SL_ARRAY_METHODS_H

#include "sl_vm.h"

/* Get an array method as a native function by name */
sl_value sl_array_get_method(sl_vm *vm, sl_js_array *arr, zend_string *method_name);

#endif /* SL_ARRAY_METHODS_H */
