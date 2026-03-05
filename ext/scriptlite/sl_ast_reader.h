#ifndef SL_AST_READER_H
#define SL_AST_READER_H

#include "php_scriptlite.h"

/* Initialize AST class entry cache -- called at RINIT (first use) */
bool sl_ast_cache_init(void);
bool sl_scriptlite_bootstrap_parser_runtime(void);

/* Read a property from an AST node object */
static inline zval *sl_ast_prop(zval *obj, zend_string *prop_name) {
    zval rv;
    return zend_read_property_ex(Z_OBJCE_P(obj), Z_OBJ_P(obj), prop_name, 1, &rv);
}

/* Read a property and return its string value (borrowed reference) */
static inline zend_string *sl_ast_prop_str(zval *obj, zend_string *prop_name) {
    zval *val = sl_ast_prop(obj, prop_name);
    if (val && Z_TYPE_P(val) == IS_STRING) {
        return Z_STR_P(val);
    }
    return NULL;
}

/* Read a property and return its long value */
static inline zend_long sl_ast_prop_long(zval *obj, zend_string *prop_name) {
    zval *val = sl_ast_prop(obj, prop_name);
    if (val && Z_TYPE_P(val) == IS_LONG) {
        return Z_LVAL_P(val);
    }
    return 0;
}

/* Read a property and return its double value */
static inline double sl_ast_prop_double(zval *obj, zend_string *prop_name) {
    zval *val = sl_ast_prop(obj, prop_name);
    if (val) {
        if (Z_TYPE_P(val) == IS_DOUBLE) return Z_DVAL_P(val);
        if (Z_TYPE_P(val) == IS_LONG) return (double)Z_LVAL_P(val);
    }
    return 0.0;
}

/* Read a property and return its bool value */
static inline bool sl_ast_prop_bool(zval *obj, zend_string *prop_name) {
    zval *val = sl_ast_prop(obj, prop_name);
    return val && zend_is_true(val);
}

/* Check if an AST node is a specific type */
static inline bool sl_ast_is(zval *obj, zend_class_entry *ce) {
    return Z_TYPE_P(obj) == IS_OBJECT && Z_OBJCE_P(obj) == ce;
}

/* Get the array from an AST property (e.g., statements, params) */
static inline HashTable *sl_ast_prop_array(zval *obj, zend_string *prop_name) {
    zval *val = sl_ast_prop(obj, prop_name);
    if (val && Z_TYPE_P(val) == IS_ARRAY) {
        return Z_ARRVAL_P(val);
    }
    return NULL;
}

/* Check if AST property is null */
static inline bool sl_ast_prop_is_null(zval *obj, zend_string *prop_name) {
    zval *val = sl_ast_prop(obj, prop_name);
    return !val || Z_TYPE_P(val) == IS_NULL;
}

#endif /* SL_AST_READER_H */
