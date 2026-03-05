#ifndef SL_VALUE_H
#define SL_VALUE_H

#include "php_scriptlite.h"
#include <math.h>

/* ---- Value type tags ---- */
typedef enum {
    SL_TAG_UNDEFINED = 0,
    SL_TAG_NULL      = 1,
    SL_TAG_BOOL      = 2,
    SL_TAG_INT       = 3,
    SL_TAG_DOUBLE    = 4,
    SL_TAG_STRING    = 5,
    SL_TAG_ARRAY     = 6,
    SL_TAG_OBJECT    = 7,
    SL_TAG_CLOSURE   = 8,
    SL_TAG_NATIVE    = 9,
    SL_TAG_DATE      = 10,
    SL_TAG_REGEX     = 11,
    SL_TAG_PHP_PROXY = 12,
} sl_tag;

/* ---- Tagged union value ---- */
typedef struct _sl_value {
    sl_tag tag;
    union {
        zend_bool   bval;
        zend_long   ival;
        double      dval;
        zend_string *str;
        sl_js_array *arr;
        sl_js_object *obj;
        sl_js_closure *closure;
        sl_native_func *native;
        sl_js_date *date;
        sl_js_regex *regex;
        zval        zv;         /* PHP_PROXY */
    } u;
} sl_value;

/* ---- Convenience constructors ---- */
static inline sl_value sl_val_undefined(void) {
    sl_value v; v.tag = SL_TAG_UNDEFINED; return v;
}
static inline sl_value sl_val_null(void) {
    sl_value v; v.tag = SL_TAG_NULL; return v;
}
static inline sl_value sl_val_bool(zend_bool b) {
    sl_value v; v.tag = SL_TAG_BOOL; v.u.bval = b; return v;
}
static inline sl_value sl_val_int(zend_long i) {
    sl_value v; v.tag = SL_TAG_INT; v.u.ival = i; return v;
}
static inline sl_value sl_val_double(double d) {
    sl_value v; v.tag = SL_TAG_DOUBLE; v.u.dval = d; return v;
}
static inline sl_value sl_val_string(zend_string *s) {
    sl_value v; v.tag = SL_TAG_STRING; v.u.str = s; return v;
}
static inline sl_value sl_val_array(sl_js_array *a) {
    sl_value v; v.tag = SL_TAG_ARRAY; v.u.arr = a; return v;
}
static inline sl_value sl_val_object(sl_js_object *o) {
    sl_value v; v.tag = SL_TAG_OBJECT; v.u.obj = o; return v;
}
static inline sl_value sl_val_closure(sl_js_closure *c) {
    sl_value v; v.tag = SL_TAG_CLOSURE; v.u.closure = c; return v;
}
static inline sl_value sl_val_native(sl_native_func *n) {
    sl_value v; v.tag = SL_TAG_NATIVE; v.u.native = n; return v;
}
static inline sl_value sl_val_date(sl_js_date *d) {
    sl_value v; v.tag = SL_TAG_DATE; v.u.date = d; return v;
}
static inline sl_value sl_val_regex(sl_js_regex *r) {
    sl_value v; v.tag = SL_TAG_REGEX; v.u.regex = r; return v;
}

/* ---- Type test macros ---- */
#define SL_IS_NUMERIC(v)   ((v).tag == SL_TAG_INT || (v).tag == SL_TAG_DOUBLE)
#define SL_IS_NULLISH(v)   ((v).tag <= SL_TAG_NULL)
#define SL_IS_CALLABLE(v)  ((v).tag == SL_TAG_CLOSURE || (v).tag == SL_TAG_NATIVE)
#define SL_IS_HEAP(v)      ((v).tag >= SL_TAG_STRING)

/* ---- Refcount operations ---- */
void sl_value_addref(sl_value *v);
void sl_value_delref(sl_value *v);

#define SL_ADDREF(v) do { if (SL_IS_HEAP(v)) sl_value_addref(&(v)); } while(0)
#define SL_DELREF(v) do { if (SL_IS_HEAP(v)) sl_value_delref(&(v)); } while(0)

/* ---- Type conversion (JS semantics) ---- */
double sl_to_number(sl_value v);
zend_string *sl_to_js_string(sl_value v);
bool sl_is_truthy(sl_value v);
zend_string *sl_js_typeof(sl_value v);
bool sl_loose_equal(sl_value a, sl_value b);
bool sl_strict_equal(sl_value a, sl_value b);
double sl_to_double(sl_value v);

/* ---- PHP ↔ JS conversion ---- */
sl_value sl_zval_to_value(zval *zv);
void sl_value_to_zval(sl_value *v, zval *zv);

/* ---- Value copy (addref) ---- */
static inline sl_value sl_value_copy(sl_value v) {
    SL_ADDREF(v);
    return v;
}

#endif /* SL_VALUE_H */
