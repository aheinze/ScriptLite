#ifndef SL_ENVIRONMENT_H
#define SL_ENVIRONMENT_H

#include "sl_runtime.h"

struct _sl_environment {
    sl_gc_header gc;
    HashTable   *values;
    HashTable   *const_bindings;
    sl_environment *parent;
};

sl_environment *sl_env_new(sl_environment *parent);
void sl_env_free(sl_environment *env);
void sl_env_define(sl_environment *env, zend_string *name, sl_value val, bool is_const);
sl_value sl_env_get(sl_environment *env, zend_string *name);
bool sl_env_has(sl_environment *env, zend_string *name);
void sl_env_set(sl_environment *env, zend_string *name, sl_value val);
sl_environment *sl_env_extend(sl_environment *env);

#endif /* SL_ENVIRONMENT_H */
