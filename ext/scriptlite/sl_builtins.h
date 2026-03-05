#ifndef SL_BUILTINS_H
#define SL_BUILTINS_H

#include "sl_vm.h"

/* Set up global environment with all built-in objects and functions */
void sl_builtins_setup(sl_vm *vm, sl_environment *env);

/* Individual built-in object constructors */
sl_native_func *sl_builtin_math(void);
sl_native_func *sl_builtin_json(void);
sl_native_func *sl_builtin_console(sl_vm *vm);
sl_native_func *sl_builtin_object_constructor(void);
sl_native_func *sl_builtin_array_constructor(void);
sl_native_func *sl_builtin_number_constructor(void);
sl_native_func *sl_builtin_string_constructor(void);
sl_native_func *sl_builtin_date_constructor(void);
sl_native_func *sl_builtin_regexp_constructor(void);

/* Global functions */
sl_value sl_builtin_parseint(sl_vm *vm, sl_value *args, int argc);
sl_value sl_builtin_parsefloat(sl_vm *vm, sl_value *args, int argc);
sl_value sl_builtin_isnan(sl_vm *vm, sl_value *args, int argc);
sl_value sl_builtin_isfinite(sl_vm *vm, sl_value *args, int argc);
sl_value sl_builtin_encode_uri(sl_vm *vm, sl_value *args, int argc);
sl_value sl_builtin_decode_uri(sl_vm *vm, sl_value *args, int argc);
sl_value sl_builtin_encode_uri_component(sl_vm *vm, sl_value *args, int argc);
sl_value sl_builtin_decode_uri_component(sl_vm *vm, sl_value *args, int argc);

#endif /* SL_BUILTINS_H */
