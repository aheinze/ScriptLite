#ifndef SL_AST_READER_H
#define SL_AST_READER_H

#include "php_scriptlite.h"
#include <string.h>

/* Initialize AST class entry cache -- called at RINIT (first use) */
bool sl_ast_cache_init(void);
void sl_ast_cache_shutdown(void);

static inline const char *sl_ast_short_name(const zend_string *name, size_t *len_out) {
    const char *full = ZSTR_VAL(name);
    size_t full_len = ZSTR_LEN(name);
    const char *short_name = full;
    size_t short_len = full_len;
    size_t i;
    for (i = 0; i < full_len; i++) {
        if (full[i] == '\\') {
            short_name = full + i + 1;
            short_len = full_len - i - 1;
        }
    }
    if (len_out) {
        *len_out = short_len;
    }
    return short_name;
}

/* Read a property from an AST node object */
static inline zval *sl_ast_prop(zval *obj, zend_string *prop_name) {
    if (!obj || !prop_name) {
        return NULL;
    }

    if (Z_TYPE_P(obj) == IS_OBJECT) {
        HashTable *props = Z_OBJ_HT_P(obj)->get_properties(Z_OBJ_P(obj));
        if (props) {
            zval *found = zend_hash_find(props, prop_name);
            if (found) {
                if (Z_TYPE_P(found) == IS_INDIRECT) {
                    found = Z_INDIRECT_P(found);
                }
                ZVAL_DEREF(found);
                return found;
            }
        }
        return NULL;
    }

    if (Z_TYPE_P(obj) == IS_ARRAY) {
        return zend_hash_find(Z_ARRVAL_P(obj), prop_name);
    }

    return NULL;
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
    if (!obj || !ce) {
        return false;
    }

    if (Z_TYPE_P(obj) == IS_OBJECT && Z_OBJCE_P(obj) == ce) {
        return true;
    }

    if (Z_TYPE_P(obj) == IS_OBJECT && ce->name) {
        size_t want_len = 0;
        size_t got_len = 0;
        const char *want = sl_ast_short_name(ce->name, &want_len);
        const char *got = sl_ast_short_name(Z_OBJCE_P(obj)->name, &got_len);
        if (want_len == got_len && memcmp(want, got, want_len) == 0) {
            return true;
        }
    }

    if (Z_TYPE_P(obj) != IS_OBJECT && Z_TYPE_P(obj) != IS_ARRAY) {
        return false;
    }

    zval *kind = NULL;
    if (Z_TYPE_P(obj) == IS_OBJECT) {
        zval rv;
        kind = zend_read_property(
            Z_OBJCE_P(obj),
            Z_OBJ_P(obj),
            "__kind",
            sizeof("__kind") - 1,
            1,
            &rv
        );
    } else {
        kind = zend_hash_str_find(Z_ARRVAL_P(obj), "__kind", sizeof("__kind") - 1);
    }

    if (!kind || Z_TYPE_P(kind) != IS_STRING) {
        return false;
    }

    size_t short_len = 0;
    const char *short_name = sl_ast_short_name(ce->name, &short_len);

    return Z_STRLEN_P(kind) == short_len
        && memcmp(Z_STRVAL_P(kind), short_name, short_len) == 0;
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
