#include "sl_value.h"
#include "sl_runtime.h"
#include "sl_environment.h"
#include <math.h>
#include <Zend/zend_closures.h>

/* ---- Refcount operations ---- */

void sl_value_addref(sl_value *v) {
    switch (v->tag) {
        case SL_TAG_STRING:
            zend_string_addref(v->u.str);
            break;
        case SL_TAG_ARRAY:
            SL_GC_ADDREF(v->u.arr);
            break;
        case SL_TAG_OBJECT:
            SL_GC_ADDREF(v->u.obj);
            break;
        case SL_TAG_CLOSURE:
            SL_GC_ADDREF(v->u.closure);
            break;
        case SL_TAG_NATIVE:
            SL_GC_ADDREF(v->u.native);
            break;
        case SL_TAG_DATE:
            SL_GC_ADDREF(v->u.date);
            break;
        case SL_TAG_REGEX:
            SL_GC_ADDREF(v->u.regex);
            break;
        case SL_TAG_PHP_PROXY:
            Z_TRY_ADDREF(v->u.zv);
            break;
        default:
            break;
    }
}

void sl_value_delref(sl_value *v) {
    switch (v->tag) {
        case SL_TAG_STRING:
            zend_string_release(v->u.str);
            break;
        case SL_TAG_ARRAY:
            if (SL_GC_DELREF(v->u.arr) == 0) {
                sl_array_free(v->u.arr);
            }
            break;
        case SL_TAG_OBJECT:
            if (SL_GC_DELREF(v->u.obj) == 0) {
                sl_object_free(v->u.obj);
            }
            break;
        case SL_TAG_CLOSURE:
            if (SL_GC_DELREF(v->u.closure) == 0) {
                sl_closure_free(v->u.closure);
            }
            break;
        case SL_TAG_NATIVE:
            if (SL_GC_DELREF(v->u.native) == 0) {
                sl_native_free(v->u.native);
            }
            break;
        case SL_TAG_DATE:
            if (SL_GC_DELREF(v->u.date) == 0) {
                sl_date_free(v->u.date);
            }
            break;
        case SL_TAG_REGEX:
            if (SL_GC_DELREF(v->u.regex) == 0) {
                sl_regex_free(v->u.regex);
            }
            break;
        case SL_TAG_PHP_PROXY:
            zval_ptr_dtor(&v->u.zv);
            break;
        default:
            break;
    }
}

/* ---- JS ToNumber ---- */

double sl_to_number(sl_value v) {
    switch (v.tag) {
        case SL_TAG_INT:       return (double)v.u.ival;
        case SL_TAG_DOUBLE:    return v.u.dval;
        case SL_TAG_BOOL:      return v.u.bval ? 1.0 : 0.0;
        case SL_TAG_NULL:      return 0.0;
        case SL_TAG_UNDEFINED: return NAN;
        case SL_TAG_STRING: {
            if (ZSTR_LEN(v.u.str) == 0) return 0.0;
            /* Trim whitespace */
            const char *s = ZSTR_VAL(v.u.str);
            size_t len = ZSTR_LEN(v.u.str);
            while (len > 0 && (s[0] == ' ' || s[0] == '\t' || s[0] == '\n' || s[0] == '\r')) { s++; len--; }
            while (len > 0 && (s[len-1] == ' ' || s[len-1] == '\t' || s[len-1] == '\n' || s[len-1] == '\r')) { len--; }
            if (len == 0) return 0.0;

            /* Check for hex */
            if (len > 2 && s[0] == '0' && (s[1] == 'x' || s[1] == 'X')) {
                char *end;
                double r = (double)strtoll(s, &end, 16);
                if ((size_t)(end - s) == len) return r;
                return NAN;
            }

            /* Check for Infinity */
            if (len == 8 && memcmp(s, "Infinity", 8) == 0) return INFINITY;
            if (len == 9 && memcmp(s, "-Infinity", 9) == 0) return -INFINITY;

            char *end;
            double r = strtod(s, &end);
            if ((size_t)(end - s) == len) return r;
            return NAN;
        }
        default:
            return NAN;
    }
}

double sl_to_double(sl_value v) {
    if (v.tag == SL_TAG_INT) return (double)v.u.ival;
    if (v.tag == SL_TAG_DOUBLE) return v.u.dval;
    return sl_to_number(v);
}

/* ---- JS ToString ---- */

zend_string *sl_to_js_string(sl_value v) {
    switch (v.tag) {
        case SL_TAG_STRING:
            return zend_string_copy(v.u.str);
        case SL_TAG_INT: {
            char buf[32];
            int len = snprintf(buf, sizeof(buf), ZEND_LONG_FMT, v.u.ival);
            return zend_string_init(buf, len, 0);
        }
        case SL_TAG_DOUBLE: {
            if (isnan(v.u.dval)) return zend_string_init("NaN", 3, 0);
            if (isinf(v.u.dval)) {
                if (v.u.dval > 0) return zend_string_init("Infinity", 8, 0);
                return zend_string_init("-Infinity", 9, 0);
            }
            /* Use PHP's double-to-string for consistent formatting */
            return zend_strpprintf(0, "%.*G", 17, v.u.dval);
        }
        case SL_TAG_BOOL:
            return v.u.bval ? zend_string_init("true", 4, 0) : zend_string_init("false", 5, 0);
        case SL_TAG_NULL:
            return zend_string_init("null", 4, 0);
        case SL_TAG_UNDEFINED:
            return zend_string_init("undefined", 9, 0);
        case SL_TAG_ARRAY:
            /* Join elements with comma */
            {
                smart_string buf = {0};
                sl_js_array *arr = v.u.arr;
                for (uint32_t i = 0; i < arr->length; i++) {
                    if (i > 0) smart_string_appendl(&buf, ",", 1);
                    if (arr->elements[i].tag != SL_TAG_NULL && arr->elements[i].tag != SL_TAG_UNDEFINED) {
                        zend_string *es = sl_to_js_string(arr->elements[i]);
                        smart_string_appendl(&buf, ZSTR_VAL(es), ZSTR_LEN(es));
                        zend_string_release(es);
                    }
                }
                smart_string_0(&buf);
                zend_string *result = buf.c ? zend_string_init(buf.c, buf.len, 0) : zend_string_init("", 0, 0);
                smart_string_free(&buf);
                return result;
            }
        case SL_TAG_OBJECT:
            return zend_string_init("[object Object]", 15, 0);
        case SL_TAG_CLOSURE:
        case SL_TAG_NATIVE:
            return zend_string_init("function", 8, 0);
        case SL_TAG_DATE: {
            /* ISO string representation */
            time_t secs = (time_t)(v.u.date->timestamp / 1000.0);
            struct tm *tm = gmtime(&secs);
            char buf[64];
            int len = snprintf(buf, sizeof(buf), "%04d-%02d-%02dT%02d:%02d:%02d.%03dZ",
                tm->tm_year + 1900, tm->tm_mon + 1, tm->tm_mday,
                tm->tm_hour, tm->tm_min, tm->tm_sec,
                (int)fmod(v.u.date->timestamp, 1000.0));
            return zend_string_init(buf, len, 0);
        }
        case SL_TAG_REGEX: {
            zend_string *result = zend_strpprintf(0, "/%s/%s",
                ZSTR_VAL(v.u.regex->pattern), ZSTR_VAL(v.u.regex->flags));
            return result;
        }
        case SL_TAG_PHP_PROXY:
            return zend_string_init("[object PhpProxy]", 17, 0);
        default:
            return zend_string_init("", 0, 0);
    }
}

/* ---- JS ToBoolean ---- */

bool sl_is_truthy(sl_value v) {
    switch (v.tag) {
        case SL_TAG_UNDEFINED:
        case SL_TAG_NULL:
            return false;
        case SL_TAG_BOOL:
            return v.u.bval;
        case SL_TAG_INT:
            return v.u.ival != 0;
        case SL_TAG_DOUBLE:
            return v.u.dval != 0.0 && !isnan(v.u.dval);
        case SL_TAG_STRING:
            return ZSTR_LEN(v.u.str) > 0;
        default:
            return true; /* objects, arrays, functions are always truthy */
    }
}

/* ---- JS typeof ---- */

zend_string *sl_js_typeof(sl_value v) {
    switch (v.tag) {
        case SL_TAG_UNDEFINED: return zend_string_init("undefined", 9, 0);
        case SL_TAG_NULL:      return zend_string_init("object", 6, 0); /* typeof null === "object" */
        case SL_TAG_BOOL:      return zend_string_init("boolean", 7, 0);
        case SL_TAG_INT:
        case SL_TAG_DOUBLE:    return zend_string_init("number", 6, 0);
        case SL_TAG_STRING:    return zend_string_init("string", 6, 0);
        case SL_TAG_CLOSURE:
        case SL_TAG_NATIVE:    return zend_string_init("function", 8, 0);
        default:               return zend_string_init("object", 6, 0);
    }
}

/* ---- JS === (strict equality) ---- */

bool sl_strict_equal(sl_value a, sl_value b) {
    /* Both must be same general type for JS === */
    /* Special: int and double are both "number" */
    if (a.tag == SL_TAG_INT && b.tag == SL_TAG_INT) {
        return a.u.ival == b.u.ival;
    }
    if (a.tag == SL_TAG_DOUBLE && b.tag == SL_TAG_DOUBLE) {
        /* NaN !== NaN */
        if (isnan(a.u.dval) || isnan(b.u.dval)) return false;
        return a.u.dval == b.u.dval;
    }
    if ((a.tag == SL_TAG_INT && b.tag == SL_TAG_DOUBLE) ||
        (a.tag == SL_TAG_DOUBLE && b.tag == SL_TAG_INT)) {
        double da = sl_to_double(a);
        double db = sl_to_double(b);
        if (isnan(da) || isnan(db)) return false;
        return da == db;
    }
    if (a.tag != b.tag) return false;

    switch (a.tag) {
        case SL_TAG_UNDEFINED:
        case SL_TAG_NULL:
            return true;
        case SL_TAG_BOOL:
            return a.u.bval == b.u.bval;
        case SL_TAG_STRING:
            return zend_string_equals(a.u.str, b.u.str);
        case SL_TAG_ARRAY:
            return a.u.arr == b.u.arr; /* reference equality */
        case SL_TAG_OBJECT:
            return a.u.obj == b.u.obj;
        case SL_TAG_CLOSURE:
            return a.u.closure == b.u.closure;
        case SL_TAG_NATIVE:
            return a.u.native == b.u.native;
        default:
            return false;
    }
}

/* ---- JS == (loose equality) ---- */

bool sl_loose_equal(sl_value a, sl_value b) {
    /* null == undefined (and vice versa) */
    if (SL_IS_NULLISH(a) && SL_IS_NULLISH(b)) return true;
    if (SL_IS_NULLISH(a) || SL_IS_NULLISH(b)) return false;

    /* Same type: use strict equality */
    if (a.tag == b.tag) return sl_strict_equal(a, b);

    /* int/double are both "number" */
    if (SL_IS_NUMERIC(a) && SL_IS_NUMERIC(b)) {
        return sl_to_double(a) == sl_to_double(b);
    }

    /* string == number -> ToNumber(string) */
    if (a.tag == SL_TAG_STRING && SL_IS_NUMERIC(b)) {
        double an = sl_to_number(a);
        double bn = sl_to_double(b);
        return an == bn;
    }
    if (SL_IS_NUMERIC(a) && b.tag == SL_TAG_STRING) {
        double an = sl_to_double(a);
        double bn = sl_to_number(b);
        return an == bn;
    }

    /* bool == anything -> ToNumber(bool) then compare */
    if (a.tag == SL_TAG_BOOL) {
        sl_value an = sl_val_int(a.u.bval ? 1 : 0);
        return sl_loose_equal(an, b);
    }
    if (b.tag == SL_TAG_BOOL) {
        sl_value bn = sl_val_int(b.u.bval ? 1 : 0);
        return sl_loose_equal(a, bn);
    }

    return false;
}

/* ---- PHP ↔ JS conversion ---- */

sl_value sl_zval_to_value(zval *zv) {
    switch (Z_TYPE_P(zv)) {
        case IS_NULL:
            return sl_val_null();
        case IS_TRUE:
            return sl_val_bool(1);
        case IS_FALSE:
            return sl_val_bool(0);
        case IS_LONG:
            return sl_val_int(Z_LVAL_P(zv));
        case IS_DOUBLE:
            return sl_val_double(Z_DVAL_P(zv));
        case IS_STRING:
            return sl_val_string(zend_string_copy(Z_STR_P(zv)));
        case IS_ARRAY: {
            HashTable *ht = Z_ARRVAL_P(zv);
            /* Check if indexed or associative */
            if (zend_array_is_list(ht)) {
                sl_js_array *arr = sl_array_new(zend_hash_num_elements(ht));
                zval *val;
                ZEND_HASH_FOREACH_VAL(ht, val) {
                    sl_value sv = sl_zval_to_value(val);
                    sl_array_push(arr, sv);
                } ZEND_HASH_FOREACH_END();
                return sl_val_array(arr);
            } else {
                sl_js_object *obj = sl_object_new();
                zend_string *key;
                zval *val;
                ZEND_HASH_FOREACH_STR_KEY_VAL(ht, key, val) {
                    if (key) {
                        sl_value sv = sl_zval_to_value(val);
                        sl_object_set(obj, key, sv);
                    }
                } ZEND_HASH_FOREACH_END();
                return sl_val_object(obj);
            }
        }
        case IS_OBJECT: {
            /* Check if it's a Closure */
            if (Z_OBJCE_P(zv) == zend_ce_closure) {
                sl_native_func *fn = sl_native_new_php(zv);
                return sl_val_native(fn);
            }
            /* Wrap as PHP proxy */
            sl_value v;
            v.tag = SL_TAG_PHP_PROXY;
            ZVAL_COPY(&v.u.zv, zv);
            return v;
        }
        default:
            return sl_val_undefined();
    }
}

void sl_value_to_zval(sl_value *v, zval *zv) {
    switch (v->tag) {
        case SL_TAG_UNDEFINED:
        case SL_TAG_NULL:
            ZVAL_NULL(zv);
            break;
        case SL_TAG_BOOL:
            ZVAL_BOOL(zv, v->u.bval);
            break;
        case SL_TAG_INT:
            ZVAL_LONG(zv, v->u.ival);
            break;
        case SL_TAG_DOUBLE:
            /* Convert float to int if it's an integer value (matches PHP VM toPhp behavior) */
            if (v->u.dval == (double)(zend_long)v->u.dval && !isinf(v->u.dval) && !isnan(v->u.dval)) {
                ZVAL_LONG(zv, (zend_long)v->u.dval);
            } else {
                ZVAL_DOUBLE(zv, v->u.dval);
            }
            break;
        case SL_TAG_STRING:
            ZVAL_STR_COPY(zv, v->u.str);
            break;
        case SL_TAG_ARRAY: {
            array_init_size(zv, v->u.arr->length);
            for (uint32_t i = 0; i < v->u.arr->length; i++) {
                zval elem;
                sl_value_to_zval(&v->u.arr->elements[i], &elem);
                zend_hash_next_index_insert(Z_ARRVAL_P(zv), &elem);
            }
            break;
        }
        case SL_TAG_OBJECT: {
            array_init(zv);
            zend_string *key;
            zval *val;
            ZEND_HASH_FOREACH_STR_KEY_VAL(v->u.obj->properties, key, val) {
                if (key) {
                    /* val stores sl_value* in our custom HashTable -- need special handling */
                    /* For now, object properties are stored as zvals wrapping sl_values */
                    zval elem;
                    sl_value sv = *(sl_value*)Z_PTR_P(val);  /* Stored pointer to sl_value in zval */
                    sl_value_to_zval(&sv, &elem);
                    zend_hash_add_new(Z_ARRVAL_P(zv), key, &elem);
                }
            } ZEND_HASH_FOREACH_END();
            break;
        }
        case SL_TAG_CLOSURE:
        case SL_TAG_NATIVE:
            /* Return null for functions (can't easily convert back) */
            ZVAL_NULL(zv);
            break;
        case SL_TAG_PHP_PROXY:
            ZVAL_COPY(zv, &v->u.zv);
            break;
        default:
            ZVAL_NULL(zv);
            break;
    }
}
