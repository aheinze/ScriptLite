/*
 * sl_builtins.c — Built-in global objects and functions for ScriptLite VM
 *
 * Implements: console, Math, Object, Array, Number, String, Date, JSON,
 *             RegExp, and global functions (parseInt, parseFloat, etc.)
 */

#include "sl_builtins.h"
#include "sl_runtime.h"
#include "sl_environment.h"
#include "sl_value.h"
#include <math.h>
#include <time.h>
#include <sys/time.h>
#include <string.h>
#include <stdlib.h>
#include "ext/json/php_json.h"
#include "ext/standard/url.h"
#include "ext/standard/php_string.h"
#include "zend_smart_str.h"
#include <ctype.h>

#include "ext/json/php_json.h"
#include "ext/pcre/php_pcre.h"

/* ============================================================
 * Helper: set a property on a native_func's properties HashTable
 * ============================================================ */

static void sl_native_props_dtor(zval *zv) {
    sl_value *v = (sl_value*)Z_PTR_P(zv);
    SL_DELREF(*v);
    efree(v);
}

static void sl_native_set_prop(sl_native_func *f, const char *name, sl_value val) {
    if (!f->properties) {
        ALLOC_HASHTABLE(f->properties);
        zend_hash_init(f->properties, 8, NULL, sl_native_props_dtor, 0);
    }
    sl_value *sv = emalloc(sizeof(sl_value));
    *sv = val;
    SL_ADDREF(val);
    zval zv;
    ZVAL_PTR(&zv, sv);
    zend_string *key = zend_string_init(name, strlen(name), 0);
    zend_hash_add(f->properties, key, &zv);
    zend_string_release(key);
}

static void sl_native_set_prop_method(sl_native_func *parent, const char *name, sl_native_handler handler) {
    zend_string *fname = zend_string_init(name, strlen(name), 0);
    sl_native_func *fn = sl_native_new(fname, handler);
    zend_string_release(fname);
    sl_native_set_prop(parent, name, sl_val_native(fn));
    /* sl_native_set_prop did addref, release our local ref */
    if (SL_GC_DELREF(fn) == 0) {
        sl_native_free(fn);
    }
}

/* ============================================================
 * console.log
 * ============================================================ */

/* We need the vm for output. The console object stores the vm pointer
 * in args context. We use a static vm pointer trick with a wrapper. */
static sl_vm *s_console_vm = NULL;

static sl_value sl_console_log_handler(sl_vm *vm, sl_value *args, int argc) {
    for (int i = 0; i < argc; i++) {
        if (i > 0) {
            smart_string_appendl(&vm->output, " ", 1);
        }
        zend_string *str = sl_to_js_string(args[i]);
        smart_string_appendl(&vm->output, ZSTR_VAL(str), ZSTR_LEN(str));
        zend_string_release(str);
    }
    smart_string_appendl(&vm->output, "\n", 1);
    return sl_val_undefined();
}

sl_native_func *sl_builtin_console(sl_vm *vm) {
    s_console_vm = vm;
    zend_string *name = zend_string_init("console", 7, 0);
    sl_native_func *console = sl_native_new(name, NULL);
    zend_string_release(name);
    sl_native_set_prop_method(console, "log", sl_console_log_handler);
    return console;
}

/* ============================================================
 * Math
 * ============================================================ */

static sl_value sl_math_floor(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(floor(n));
}

static sl_value sl_math_ceil(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(ceil(n));
}

static sl_value sl_math_abs(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(fabs(n));
}

static sl_value sl_math_max(sl_vm *vm, sl_value *args, int argc) {
    if (argc == 0) return sl_val_double(-INFINITY);
    double result = sl_to_number(args[0]);
    for (int i = 1; i < argc; i++) {
        double v = sl_to_number(args[i]);
        if (isnan(v)) return sl_val_double(NAN);
        if (v > result) result = v;
    }
    return sl_val_double(result);
}

static sl_value sl_math_min(sl_vm *vm, sl_value *args, int argc) {
    if (argc == 0) return sl_val_double(INFINITY);
    double result = sl_to_number(args[0]);
    for (int i = 1; i < argc; i++) {
        double v = sl_to_number(args[i]);
        if (isnan(v)) return sl_val_double(NAN);
        if (v < result) result = v;
    }
    return sl_val_double(result);
}

static sl_value sl_math_round(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(round(n));
}

static sl_value sl_math_random(sl_vm *vm, sl_value *args, int argc) {
    return sl_val_double((double)rand() / (double)RAND_MAX);
}

static sl_value sl_math_sqrt(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(sqrt(n));
}

static sl_value sl_math_pow(sl_vm *vm, sl_value *args, int argc) {
    double base = argc > 0 ? sl_to_number(args[0]) : NAN;
    double exp = argc > 1 ? sl_to_number(args[1]) : NAN;
    return sl_val_double(pow(base, exp));
}

static sl_value sl_math_sin(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(sin(n));
}

static sl_value sl_math_cos(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(cos(n));
}

static sl_value sl_math_tan(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(tan(n));
}

static sl_value sl_math_asin(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(asin(n));
}

static sl_value sl_math_acos(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(acos(n));
}

static sl_value sl_math_atan(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(atan(n));
}

static sl_value sl_math_atan2(sl_vm *vm, sl_value *args, int argc) {
    double y = argc > 0 ? sl_to_number(args[0]) : NAN;
    double x = argc > 1 ? sl_to_number(args[1]) : NAN;
    return sl_val_double(atan2(y, x));
}

static sl_value sl_math_log(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(log(n));
}

static sl_value sl_math_log2(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(log2(n));
}

static sl_value sl_math_log10(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(log10(n));
}

static sl_value sl_math_exp(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(exp(n));
}

static sl_value sl_math_cbrt(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    return sl_val_double(cbrt(n));
}

static sl_value sl_math_hypot(sl_vm *vm, sl_value *args, int argc) {
    if (argc == 0) return sl_val_double(0.0);
    double sum = 0.0;
    for (int i = 0; i < argc; i++) {
        double v = sl_to_number(args[i]);
        if (isnan(v)) return sl_val_double(NAN);
        sum += v * v;
    }
    return sl_val_double(sqrt(sum));
}

static sl_value sl_math_sign(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    if (isnan(n)) return sl_val_double(NAN);
    if (n > 0) return sl_val_double(1.0);
    if (n < 0) return sl_val_double(-1.0);
    return sl_val_double(n); /* preserve +0 / -0 */
}

static sl_value sl_math_trunc(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : NAN;
    if (isnan(n) || isinf(n)) return sl_val_double(n);
    return sl_val_double(n >= 0 ? floor(n) : ceil(n));
}

static sl_value sl_math_clz32(sl_vm *vm, sl_value *args, int argc) {
    double n = argc > 0 ? sl_to_number(args[0]) : 0.0;
    uint32_t x = (uint32_t)(int32_t)n;
    if (x == 0) return sl_val_int(32);
    int count = 0;
    while (!(x & 0x80000000)) {
        count++;
        x <<= 1;
    }
    return sl_val_int(count);
}

sl_native_func *sl_builtin_math(void) {
    zend_string *name = zend_string_init("Math", 4, 0);
    sl_native_func *math = sl_native_new(name, NULL);
    zend_string_release(name);

    /* Constants */
    sl_native_set_prop(math, "PI",      sl_val_double(M_PI));
    sl_native_set_prop(math, "E",       sl_val_double(M_E));
    sl_native_set_prop(math, "LN2",     sl_val_double(M_LN2));
    sl_native_set_prop(math, "LN10",    sl_val_double(M_LN10));
    sl_native_set_prop(math, "LOG2E",   sl_val_double(M_LOG2E));
    sl_native_set_prop(math, "LOG10E",  sl_val_double(M_LOG10E));
    sl_native_set_prop(math, "SQRT1_2", sl_val_double(M_SQRT1_2));
    sl_native_set_prop(math, "SQRT2",   sl_val_double(M_SQRT2));

    /* Methods */
    sl_native_set_prop_method(math, "floor",  sl_math_floor);
    sl_native_set_prop_method(math, "ceil",   sl_math_ceil);
    sl_native_set_prop_method(math, "abs",    sl_math_abs);
    sl_native_set_prop_method(math, "max",    sl_math_max);
    sl_native_set_prop_method(math, "min",    sl_math_min);
    sl_native_set_prop_method(math, "round",  sl_math_round);
    sl_native_set_prop_method(math, "random", sl_math_random);
    sl_native_set_prop_method(math, "sqrt",   sl_math_sqrt);
    sl_native_set_prop_method(math, "pow",    sl_math_pow);
    sl_native_set_prop_method(math, "sin",    sl_math_sin);
    sl_native_set_prop_method(math, "cos",    sl_math_cos);
    sl_native_set_prop_method(math, "tan",    sl_math_tan);
    sl_native_set_prop_method(math, "asin",   sl_math_asin);
    sl_native_set_prop_method(math, "acos",   sl_math_acos);
    sl_native_set_prop_method(math, "atan",   sl_math_atan);
    sl_native_set_prop_method(math, "atan2",  sl_math_atan2);
    sl_native_set_prop_method(math, "log",    sl_math_log);
    sl_native_set_prop_method(math, "log2",   sl_math_log2);
    sl_native_set_prop_method(math, "log10",  sl_math_log10);
    sl_native_set_prop_method(math, "exp",    sl_math_exp);
    sl_native_set_prop_method(math, "cbrt",   sl_math_cbrt);
    sl_native_set_prop_method(math, "hypot",  sl_math_hypot);
    sl_native_set_prop_method(math, "sign",   sl_math_sign);
    sl_native_set_prop_method(math, "trunc",  sl_math_trunc);
    sl_native_set_prop_method(math, "clz32",  sl_math_clz32);

    return math;
}

/* ============================================================
 * Object
 * ============================================================ */

static sl_value sl_object_keys_handler(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1 || args[0].tag != SL_TAG_OBJECT) {
        return sl_val_array(sl_array_new(0));
    }
    sl_js_object *obj = args[0].u.obj;
    sl_js_array *result = sl_array_new(zend_hash_num_elements(obj->properties));
    zend_string *key;
    ZEND_HASH_FOREACH_STR_KEY(obj->properties, key) {
        if (key) {
            sl_array_push(result, sl_val_string(zend_string_copy(key)));
        }
    } ZEND_HASH_FOREACH_END();
    return sl_val_array(result);
}

static sl_value sl_object_values_handler(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1 || args[0].tag != SL_TAG_OBJECT) {
        return sl_val_array(sl_array_new(0));
    }
    sl_js_object *obj = args[0].u.obj;
    sl_js_array *result = sl_array_new(zend_hash_num_elements(obj->properties));
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL(obj->properties, key, val) {
        if (key) {
            sl_value sv = *(sl_value*)Z_PTR_P(val);
            sl_array_push(result, sv);
        }
    } ZEND_HASH_FOREACH_END();
    return sl_val_array(result);
}

static sl_value sl_object_entries_handler(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1 || args[0].tag != SL_TAG_OBJECT) {
        return sl_val_array(sl_array_new(0));
    }
    sl_js_object *obj = args[0].u.obj;
    sl_js_array *result = sl_array_new(zend_hash_num_elements(obj->properties));
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL(obj->properties, key, val) {
        if (key) {
            sl_value sv = *(sl_value*)Z_PTR_P(val);
            sl_js_array *pair = sl_array_new(2);
            sl_array_push(pair, sl_val_string(zend_string_copy(key)));
            sl_array_push(pair, sv);
            sl_array_push(result, sl_val_array(pair));
            /* push did addref, release our local ref */
            SL_GC_DELREF(pair);
        }
    } ZEND_HASH_FOREACH_END();
    return sl_val_array(result);
}

static sl_value sl_object_assign_handler(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1 || args[0].tag != SL_TAG_OBJECT) {
        return argc > 0 ? args[0] : sl_val_undefined();
    }
    sl_js_object *target = args[0].u.obj;
    for (int i = 1; i < argc; i++) {
        if (args[i].tag == SL_TAG_OBJECT) {
            sl_js_object *source = args[i].u.obj;
            zend_string *key;
            zval *val;
            ZEND_HASH_FOREACH_STR_KEY_VAL(source->properties, key, val) {
                if (key) {
                    sl_value sv = *(sl_value*)Z_PTR_P(val);
                    sl_object_set(target, key, sv);
                }
            } ZEND_HASH_FOREACH_END();
        }
    }
    return sl_value_copy(args[0]);
}

static sl_value sl_object_is_handler(sl_vm *vm, sl_value *args, int argc) {
    sl_value a = argc > 0 ? args[0] : sl_val_undefined();
    sl_value b = argc > 1 ? args[1] : sl_val_undefined();

    /* Handle NaN === NaN */
    if (a.tag == SL_TAG_DOUBLE && b.tag == SL_TAG_DOUBLE) {
        if (isnan(a.u.dval) && isnan(b.u.dval)) return sl_val_bool(1);
        /* Handle +0 vs -0 */
        if (a.u.dval == 0.0 && b.u.dval == 0.0) {
            return sl_val_bool((1.0 / a.u.dval) == (1.0 / b.u.dval));
        }
    }
    return sl_val_bool(sl_strict_equal(a, b));
}

static sl_value sl_object_create_handler(sl_vm *vm, sl_value *args, int argc) {
    sl_js_object *obj = sl_object_new();
    if (argc > 0 && args[0].tag == SL_TAG_OBJECT) {
        obj->prototype = args[0].u.obj;
        SL_GC_ADDREF(obj->prototype);
    }
    return sl_val_object(obj);
}

static sl_value sl_object_freeze_handler(sl_vm *vm, sl_value *args, int argc) {
    /* No-op: freeze semantics not enforced */
    return argc > 0 ? sl_value_copy(args[0]) : sl_val_undefined();
}

sl_native_func *sl_builtin_object_constructor(void) {
    zend_string *name = zend_string_init("Object", 6, 0);
    sl_native_func *obj = sl_native_new(name, NULL);
    zend_string_release(name);

    sl_native_set_prop_method(obj, "keys",    sl_object_keys_handler);
    sl_native_set_prop_method(obj, "values",  sl_object_values_handler);
    sl_native_set_prop_method(obj, "entries", sl_object_entries_handler);
    sl_native_set_prop_method(obj, "assign",  sl_object_assign_handler);
    sl_native_set_prop_method(obj, "is",      sl_object_is_handler);
    sl_native_set_prop_method(obj, "create",  sl_object_create_handler);
    sl_native_set_prop_method(obj, "freeze",  sl_object_freeze_handler);

    return obj;
}

/* ============================================================
 * Array (static methods)
 * ============================================================ */

static sl_value sl_array_is_array_handler(sl_vm *vm, sl_value *args, int argc) {
    return sl_val_bool(argc > 0 && args[0].tag == SL_TAG_ARRAY);
}

static sl_value sl_array_from_handler(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1) return sl_val_array(sl_array_new(0));

    if (args[0].tag == SL_TAG_ARRAY) {
        /* Shallow copy */
        sl_js_array *src = args[0].u.arr;
        sl_js_array *result = sl_array_new(src->length);
        for (uint32_t i = 0; i < src->length; i++) {
            sl_array_push(result, src->elements[i]);
        }
        return sl_val_array(result);
    }
    if (args[0].tag == SL_TAG_STRING) {
        /* Split string into characters */
        zend_string *str = args[0].u.str;
        size_t len = ZSTR_LEN(str);
        const char *s = ZSTR_VAL(str);
        sl_js_array *result = sl_array_new(len);
        size_t i = 0;
        while (i < len) {
            /* UTF-8 character length */
            unsigned char c = (unsigned char)s[i];
            size_t char_len = 1;
            if (c >= 0xC0 && c < 0xE0) char_len = 2;
            else if (c >= 0xE0 && c < 0xF0) char_len = 3;
            else if (c >= 0xF0) char_len = 4;
            if (i + char_len > len) char_len = len - i;
            zend_string *ch = zend_string_init(s + i, char_len, 0);
            sl_array_push(result, sl_val_string(ch));
            zend_string_release(ch);
            i += char_len;
        }
        return sl_val_array(result);
    }
    return sl_val_array(sl_array_new(0));
}

static sl_value sl_array_of_handler(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *result = sl_array_new(argc);
    for (int i = 0; i < argc; i++) {
        sl_array_push(result, args[i]);
    }
    return sl_val_array(result);
}

sl_native_func *sl_builtin_array_constructor(void) {
    zend_string *name = zend_string_init("Array", 5, 0);
    sl_native_func *arr = sl_native_new(name, NULL);
    zend_string_release(name);

    sl_native_set_prop_method(arr, "isArray", sl_array_is_array_handler);
    sl_native_set_prop_method(arr, "from",    sl_array_from_handler);
    sl_native_set_prop_method(arr, "of",      sl_array_of_handler);

    return arr;
}

/* ============================================================
 * Number
 * ============================================================ */

static sl_value sl_number_is_integer_handler(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1) return sl_val_bool(0);
    sl_value v = args[0];
    if (v.tag == SL_TAG_INT) return sl_val_bool(1);
    if (v.tag == SL_TAG_DOUBLE) {
        if (!isfinite(v.u.dval)) return sl_val_bool(0);
        return sl_val_bool(floor(v.u.dval) == v.u.dval);
    }
    return sl_val_bool(0);
}

static sl_value sl_number_is_finite_handler(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1) return sl_val_bool(0);
    sl_value v = args[0];
    if (v.tag == SL_TAG_INT) return sl_val_bool(1);
    if (v.tag == SL_TAG_DOUBLE) return sl_val_bool(isfinite(v.u.dval));
    return sl_val_bool(0);
}

static sl_value sl_number_is_nan_handler(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1) return sl_val_bool(0);
    sl_value v = args[0];
    if (v.tag == SL_TAG_DOUBLE) return sl_val_bool(isnan(v.u.dval));
    return sl_val_bool(0);
}

sl_native_func *sl_builtin_number_constructor(void) {
    zend_string *name = zend_string_init("Number", 6, 0);
    sl_native_func *num = sl_native_new(name, NULL);
    zend_string_release(name);

    sl_native_set_prop_method(num, "isInteger",  sl_number_is_integer_handler);
    sl_native_set_prop_method(num, "isFinite",   sl_number_is_finite_handler);
    sl_native_set_prop_method(num, "isNaN",      sl_number_is_nan_handler);

    /* parseInt and parseFloat are set in sl_builtins_setup (shared with globals) */

    /* Constants */
    sl_native_set_prop(num, "MAX_SAFE_INTEGER", sl_val_double(9007199254740991.0));
    sl_native_set_prop(num, "MIN_SAFE_INTEGER", sl_val_double(-9007199254740991.0));
    sl_native_set_prop(num, "EPSILON",          sl_val_double(2.2204460492503131e-16));
    sl_native_set_prop(num, "POSITIVE_INFINITY", sl_val_double(INFINITY));
    sl_native_set_prop(num, "NEGATIVE_INFINITY", sl_val_double(-INFINITY));
    sl_native_set_prop(num, "NaN",               sl_val_double(NAN));

    return num;
}

/* ============================================================
 * String (static methods)
 * ============================================================ */

static sl_value sl_string_from_char_code_handler(sl_vm *vm, sl_value *args, int argc) {
    smart_string buf = {0};
    for (int i = 0; i < argc; i++) {
        zend_long code = (zend_long)sl_to_number(args[i]);
        /* Encode codepoint as UTF-8 */
        if (code < 0x80) {
            smart_string_appendc(&buf, (char)code);
        } else if (code < 0x800) {
            smart_string_appendc(&buf, (char)(0xC0 | (code >> 6)));
            smart_string_appendc(&buf, (char)(0x80 | (code & 0x3F)));
        } else if (code < 0x10000) {
            smart_string_appendc(&buf, (char)(0xE0 | (code >> 12)));
            smart_string_appendc(&buf, (char)(0x80 | ((code >> 6) & 0x3F)));
            smart_string_appendc(&buf, (char)(0x80 | (code & 0x3F)));
        } else if (code < 0x110000) {
            smart_string_appendc(&buf, (char)(0xF0 | (code >> 18)));
            smart_string_appendc(&buf, (char)(0x80 | ((code >> 12) & 0x3F)));
            smart_string_appendc(&buf, (char)(0x80 | ((code >> 6) & 0x3F)));
            smart_string_appendc(&buf, (char)(0x80 | (code & 0x3F)));
        }
    }
    smart_string_0(&buf);
    zend_string *result = buf.c ? zend_string_init(buf.c, buf.len, 0) : zend_string_init("", 0, 0);
    smart_string_free(&buf);
    return sl_val_string(result);
}

sl_native_func *sl_builtin_string_constructor(void) {
    zend_string *name = zend_string_init("String", 6, 0);
    sl_native_func *str = sl_native_new(name, NULL);
    zend_string_release(name);

    sl_native_set_prop_method(str, "fromCharCode", sl_string_from_char_code_handler);

    return str;
}

/* ============================================================
 * Date
 * ============================================================ */

static double sl_now_ms(void) {
    struct timeval tv;
    gettimeofday(&tv, NULL);
    return (double)tv.tv_sec * 1000.0 + (double)tv.tv_usec / 1000.0;
}

static sl_value sl_date_constructor_handler(sl_vm *vm, sl_value *args, int argc) {
    if (argc == 0) {
        /* new Date() -- current time */
        return sl_val_date(sl_date_new(floor(sl_now_ms())));
    }
    if (argc == 1) {
        if (args[0].tag == SL_TAG_STRING) {
            /* new Date(string) -- parse date string */
            zend_string *str = args[0].u.str;
            /* Use PHP's strtotime */
            zval ret, arg;
            ZVAL_UNDEF(&ret);
            ZVAL_STR_COPY(&arg, str);

            zval func_name;
            ZVAL_STRING(&func_name, "strtotime");
            if (call_user_function(EG(function_table), NULL, &func_name, &ret, 1, &arg) == SUCCESS) {
                zval_ptr_dtor(&func_name);
                zval_ptr_dtor(&arg);
                if (Z_TYPE(ret) == IS_LONG) {
                    double ts = (double)Z_LVAL(ret) * 1000.0;
                    zval_ptr_dtor(&ret);
                    return sl_val_date(sl_date_new(ts));
                }
                zval_ptr_dtor(&ret);
                return sl_val_date(sl_date_new(NAN));
            }
            zval_ptr_dtor(&func_name);
            zval_ptr_dtor(&arg);
            return sl_val_date(sl_date_new(NAN));
        }
        /* new Date(milliseconds) */
        double ms = sl_to_number(args[0]);
        return sl_val_date(sl_date_new(ms));
    }
    /* new Date(year, month, day?, hours?, minutes?, seconds?, ms?) */
    int year  = (int)sl_to_number(args[0]);
    int month = (int)sl_to_number(args[1]) + 1; /* JS months 0-based */
    int day   = argc > 2 ? (int)sl_to_number(args[2]) : 1;
    int hour  = argc > 3 ? (int)sl_to_number(args[3]) : 0;
    int min   = argc > 4 ? (int)sl_to_number(args[4]) : 0;
    int sec   = argc > 5 ? (int)sl_to_number(args[5]) : 0;
    int ms    = argc > 6 ? (int)sl_to_number(args[6]) : 0;

    struct tm tm_val = {0};
    tm_val.tm_year = year - 1900;
    tm_val.tm_mon = month - 1;
    tm_val.tm_mday = day;
    tm_val.tm_hour = hour;
    tm_val.tm_min = min;
    tm_val.tm_sec = sec;
    tm_val.tm_isdst = 0;

    time_t t = timegm(&tm_val);
    double timestamp = (double)t * 1000.0 + (double)ms;
    return sl_val_date(sl_date_new(timestamp));
}

static sl_value sl_date_now_handler(sl_vm *vm, sl_value *args, int argc) {
    return sl_val_double(floor(sl_now_ms()));
}

static sl_value sl_date_parse_handler(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1 || args[0].tag != SL_TAG_STRING) {
        return sl_val_double(NAN);
    }
    zend_string *str = args[0].u.str;
    zval ret, arg;
    ZVAL_UNDEF(&ret);
    ZVAL_STR_COPY(&arg, str);

    zval func_name;
    ZVAL_STRING(&func_name, "strtotime");
    if (call_user_function(EG(function_table), NULL, &func_name, &ret, 1, &arg) == SUCCESS) {
        zval_ptr_dtor(&func_name);
        zval_ptr_dtor(&arg);
        if (Z_TYPE(ret) == IS_LONG) {
            double ts = (double)Z_LVAL(ret) * 1000.0;
            zval_ptr_dtor(&ret);
            return sl_val_double(ts);
        }
        zval_ptr_dtor(&ret);
        return sl_val_double(NAN);
    }
    zval_ptr_dtor(&func_name);
    zval_ptr_dtor(&arg);
    return sl_val_double(NAN);
}

sl_native_func *sl_builtin_date_constructor(void) {
    zend_string *name = zend_string_init("Date", 4, 0);
    sl_native_func *date = sl_native_new(name, sl_date_constructor_handler);
    zend_string_release(name);

    sl_native_set_prop_method(date, "now",   sl_date_now_handler);
    sl_native_set_prop_method(date, "parse", sl_date_parse_handler);

    return date;
}

/* ============================================================
 * JSON
 * ============================================================ */

/* Forward declaration: recursive JSON-safe-to-PHP-zval conversion */
static void sl_value_to_json_zval(sl_value v, zval *out);

static void sl_value_to_json_zval(sl_value v, zval *out) {
    switch (v.tag) {
        case SL_TAG_UNDEFINED:
        case SL_TAG_NULL:
            ZVAL_NULL(out);
            break;
        case SL_TAG_BOOL:
            ZVAL_BOOL(out, v.u.bval);
            break;
        case SL_TAG_INT:
            ZVAL_LONG(out, v.u.ival);
            break;
        case SL_TAG_DOUBLE:
            ZVAL_DOUBLE(out, v.u.dval);
            break;
        case SL_TAG_STRING:
            ZVAL_STR_COPY(out, v.u.str);
            break;
        case SL_TAG_ARRAY: {
            array_init_size(out, v.u.arr->length);
            for (uint32_t i = 0; i < v.u.arr->length; i++) {
                zval elem;
                sl_value_to_json_zval(v.u.arr->elements[i], &elem);
                zend_hash_next_index_insert(Z_ARRVAL_P(out), &elem);
            }
            break;
        }
        case SL_TAG_OBJECT: {
            /* Convert to stdClass-like associative array for json_encode */
            object_init(out);
            zend_string *key;
            zval *val;
            ZEND_HASH_FOREACH_STR_KEY_VAL(v.u.obj->properties, key, val) {
                if (key) {
                    sl_value sv = *(sl_value*)Z_PTR_P(val);
                    /* Skip functions in JSON output */
                    if (sv.tag == SL_TAG_CLOSURE || sv.tag == SL_TAG_NATIVE) continue;
                    zval elem;
                    sl_value_to_json_zval(sv, &elem);
                    zend_update_property(NULL, Z_OBJ_P(out), ZSTR_VAL(key), ZSTR_LEN(key), &elem);
                    zval_ptr_dtor(&elem);
                }
            } ZEND_HASH_FOREACH_END();
            break;
        }
        default:
            ZVAL_NULL(out);
            break;
    }
}

/* Recursive PHP zval -> sl_value conversion for JSON.parse results */
static sl_value sl_json_zval_to_value(zval *zv) {
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
            if (zend_array_is_list(ht)) {
                sl_js_array *arr = sl_array_new(zend_hash_num_elements(ht));
                zval *val;
                ZEND_HASH_FOREACH_VAL(ht, val) {
                    sl_value sv = sl_json_zval_to_value(val);
                    sl_array_push(arr, sv);
                } ZEND_HASH_FOREACH_END();
                return sl_val_array(arr);
            } else {
                sl_js_object *obj = sl_object_new();
                zend_string *key;
                zval *val;
                ZEND_HASH_FOREACH_STR_KEY_VAL(ht, key, val) {
                    if (key) {
                        sl_value sv = sl_json_zval_to_value(val);
                        sl_object_set(obj, key, sv);
                    }
                } ZEND_HASH_FOREACH_END();
                return sl_val_object(obj);
            }
        }
        default:
            return sl_val_null();
    }
}

static sl_value sl_json_stringify_handler(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1) return sl_val_undefined();

    zval php_val;
    sl_value_to_json_zval(args[0], &php_val);

    int options = PHP_JSON_UNESCAPED_UNICODE | PHP_JSON_UNESCAPED_SLASHES;

    /* Check for space/indent argument (3rd arg) */
    if (argc >= 3 && args[2].tag != SL_TAG_UNDEFINED) {
        options |= PHP_JSON_PRETTY_PRINT;
    }

    smart_str buf = {0};
    php_json_encode(&buf, &php_val, options);
    smart_str_0(&buf);

    zval_ptr_dtor(&php_val);

    if (buf.s) {
        zend_string *result = zend_string_copy(buf.s);
        smart_str_free(&buf);
        return sl_val_string(result);
    }
    smart_str_free(&buf);
    return sl_val_undefined();
}

static sl_value sl_json_parse_handler(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1 || args[0].tag != SL_TAG_STRING) {
        sl_vm_throw_type_error(vm, "JSON.parse: argument must be a string");
        return sl_val_undefined();
    }

    zend_string *json_str = args[0].u.str;
    zval result;

    php_json_decode(&result, ZSTR_VAL(json_str), ZSTR_LEN(json_str), 1 /* assoc */, 512);

    if (Z_TYPE(result) == IS_UNDEF) {
        sl_vm_throw_type_error(vm, "JSON.parse: unexpected token");
        return sl_val_undefined();
    }

    sl_value sv = sl_json_zval_to_value(&result);
    zval_ptr_dtor(&result);
    return sv;
}

sl_native_func *sl_builtin_json(void) {
    zend_string *name = zend_string_init("JSON", 4, 0);
    sl_native_func *json = sl_native_new(name, NULL);
    zend_string_release(name);

    sl_native_set_prop_method(json, "stringify", sl_json_stringify_handler);
    sl_native_set_prop_method(json, "parse",     sl_json_parse_handler);

    return json;
}

/* ============================================================
 * RegExp constructor
 * ============================================================ */

static sl_value sl_regexp_constructor_handler(sl_vm *vm, sl_value *args, int argc) {
    zend_string *pattern;
    zend_string *flags;

    if (argc < 1 || args[0].tag != SL_TAG_STRING) {
        pattern = zend_string_init("", 0, 0);
    } else {
        pattern = zend_string_copy(args[0].u.str);
    }

    if (argc < 2 || args[1].tag == SL_TAG_UNDEFINED) {
        flags = zend_string_init("", 0, 0);
    } else {
        flags = sl_to_js_string(args[1]);
    }

    sl_js_regex *r = sl_regex_new(pattern, flags);
    zend_string_release(pattern);
    zend_string_release(flags);
    return sl_val_regex(r);
}

sl_native_func *sl_builtin_regexp_constructor(void) {
    zend_string *name = zend_string_init("RegExp", 6, 0);
    sl_native_func *re = sl_native_new(name, sl_regexp_constructor_handler);
    zend_string_release(name);
    return re;
}

/* ============================================================
 * Global functions: parseInt, parseFloat, isNaN, isFinite
 * ============================================================ */

sl_value sl_builtin_parseint(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1) return sl_val_double(NAN);

    zend_string *str = sl_to_js_string(args[0]);
    const char *s = ZSTR_VAL(str);
    size_t len = ZSTR_LEN(str);

    /* Trim leading whitespace */
    while (len > 0 && (*s == ' ' || *s == '\t' || *s == '\n' || *s == '\r')) {
        s++;
        len--;
    }

    if (len == 0) {
        zend_string_release(str);
        return sl_val_double(NAN);
    }

    int base = 10;
    if (argc > 1 && args[1].tag != SL_TAG_UNDEFINED) {
        base = (int)sl_to_number(args[1]);
        if (base == 0) base = 10;
        if (base < 2 || base > 36) {
            zend_string_release(str);
            return sl_val_double(NAN);
        }
    }

    /* Handle 0x prefix for hex */
    if (base == 16 && len > 2 && s[0] == '0' && (s[1] == 'x' || s[1] == 'X')) {
        s += 2;
        len -= 2;
    }

    /* Parse digits valid for the given base */
    int sign = 1;
    if (len > 0 && (*s == '+' || *s == '-')) {
        if (*s == '-') sign = -1;
        s++;
        len--;
    }

    if (len == 0) {
        zend_string_release(str);
        return sl_val_double(NAN);
    }

    double result = 0;
    bool has_digit = false;
    for (size_t i = 0; i < len; i++) {
        int digit;
        char c = s[i];
        if (c >= '0' && c <= '9') {
            digit = c - '0';
        } else if (c >= 'a' && c <= 'z') {
            digit = c - 'a' + 10;
        } else if (c >= 'A' && c <= 'Z') {
            digit = c - 'A' + 10;
        } else {
            break;
        }
        if (digit >= base) break;
        result = result * base + digit;
        has_digit = true;
    }

    zend_string_release(str);

    if (!has_digit) return sl_val_double(NAN);
    return sl_val_double(sign * result);
}

sl_value sl_builtin_parsefloat(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1) return sl_val_double(NAN);

    zend_string *str = sl_to_js_string(args[0]);
    const char *s = ZSTR_VAL(str);
    size_t len = ZSTR_LEN(str);

    /* Trim leading whitespace */
    while (len > 0 && (*s == ' ' || *s == '\t' || *s == '\n' || *s == '\r')) {
        s++;
        len--;
    }

    if (len == 0) {
        zend_string_release(str);
        return sl_val_double(NAN);
    }

    char *end;
    double result = strtod(s, &end);
    zend_string_release(str);

    if (end == s) return sl_val_double(NAN);
    return sl_val_double(result);
}

sl_value sl_builtin_isnan(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1) return sl_val_bool(1); /* isNaN() === true */
    double n = sl_to_number(args[0]);
    return sl_val_bool(isnan(n));
}

sl_value sl_builtin_isfinite(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1) return sl_val_bool(0);
    double n = sl_to_number(args[0]);
    return sl_val_bool(isfinite(n));
}

/* ============================================================
 * URI encoding/decoding
 * ============================================================ */

sl_value sl_builtin_encode_uri_component(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1) return sl_val_string(zend_string_init("undefined", 9, 0));
    zend_string *str = sl_to_js_string(args[0]);

    /* Use php_url_encode which is similar to rawurlencode */
    zend_string *encoded = php_raw_url_encode(ZSTR_VAL(str), ZSTR_LEN(str));
    zend_string_release(str);
    return sl_val_string(encoded);
}

sl_value sl_builtin_decode_uri_component(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1) return sl_val_string(zend_string_init("undefined", 9, 0));
    zend_string *str = sl_to_js_string(args[0]);

    /* php_raw_url_decode modifies in place and returns new length */
    zend_string *decoded = zend_string_init(ZSTR_VAL(str), ZSTR_LEN(str), 0);
    size_t new_len = php_raw_url_decode(ZSTR_VAL(decoded), ZSTR_LEN(decoded));
    ZSTR_LEN(decoded) = new_len;
    zend_string_release(str);
    return sl_val_string(decoded);
}

sl_value sl_builtin_encode_uri(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1) return sl_val_string(zend_string_init("undefined", 9, 0));
    zend_string *str = sl_to_js_string(args[0]);

    zend_string *encoded = php_raw_url_encode(ZSTR_VAL(str), ZSTR_LEN(str));

    /* encodeURI does not encode: ;,/?:@&=+$-_.!~*'()# and alpha-numeric */
    /* The raw_url_encode already doesn't encode unreserved chars.
     * We need to un-encode the reserved chars that encodeURI preserves. */
    static const char *from[] = {
        "%3A", "%2F", "%3F", "%23", "%5B", "%5D", "%40",
        "%21", "%24", "%26", "%27", "%28", "%29", "%2A",
        "%2B", "%2C", "%3B", "%3D", NULL
    };
    static const char *to[] = {
        ":", "/", "?", "#", "[", "]", "@",
        "!", "$", "&", "'", "(", ")", "*",
        "+", ",", ";", "=", NULL
    };

    zend_string *result = zend_string_copy(encoded);
    zend_string_release(encoded);

    for (int i = 0; from[i] != NULL; i++) {
        zend_string *search = zend_string_init(from[i], strlen(from[i]), 0);
        zend_string *replace = zend_string_init(to[i], strlen(to[i]), 0);
        zend_string *new_result = php_str_to_str(ZSTR_VAL(result), ZSTR_LEN(result),
                                                  ZSTR_VAL(search), ZSTR_LEN(search),
                                                  ZSTR_VAL(replace), ZSTR_LEN(replace));
        zend_string_release(search);
        zend_string_release(replace);
        zend_string_release(result);
        result = new_result;
    }

    zend_string_release(str);
    return sl_val_string(result);
}

sl_value sl_builtin_decode_uri(sl_vm *vm, sl_value *args, int argc) {
    if (argc < 1) return sl_val_string(zend_string_init("undefined", 9, 0));
    zend_string *str = sl_to_js_string(args[0]);

    zend_string *decoded = zend_string_init(ZSTR_VAL(str), ZSTR_LEN(str), 0);
    size_t new_len = php_raw_url_decode(ZSTR_VAL(decoded), ZSTR_LEN(decoded));
    ZSTR_LEN(decoded) = new_len;
    zend_string_release(str);
    return sl_val_string(decoded);
}

/* ============================================================
 * sl_builtins_setup — wire everything into the global environment
 * ============================================================ */

void sl_builtins_setup(sl_vm *vm, sl_environment *env) {
    zend_string *key;

    /* ── console ── */
    sl_native_func *console = sl_builtin_console(vm);
    key = zend_string_init("console", 7, 0);
    sl_env_define(env, key, sl_val_native(console), false);
    zend_string_release(key);
    SL_GC_DELREF(console); /* env took ownership */

    /* Legacy alias expected by compatibility tests */
    zend_string *cl_name = zend_string_init("console_log", 11, 0);
    sl_native_func *console_log = sl_native_new(cl_name, sl_console_log_handler);
    zend_string_release(cl_name);
    key = zend_string_init("console_log", 11, 0);
    sl_env_define(env, key, sl_val_native(console_log), false);
    zend_string_release(key);
    SL_GC_DELREF(console_log);

    /* ── Math ── */
    sl_native_func *math = sl_builtin_math();
    key = zend_string_init("Math", 4, 0);
    sl_env_define(env, key, sl_val_native(math), false);
    zend_string_release(key);
    SL_GC_DELREF(math);

    /* ── Object ── */
    sl_native_func *obj = sl_builtin_object_constructor();
    key = zend_string_init("Object", 6, 0);
    sl_env_define(env, key, sl_val_native(obj), false);
    zend_string_release(key);
    SL_GC_DELREF(obj);

    /* ── Array ── */
    sl_native_func *arr = sl_builtin_array_constructor();
    key = zend_string_init("Array", 5, 0);
    sl_env_define(env, key, sl_val_native(arr), false);
    zend_string_release(key);
    SL_GC_DELREF(arr);

    /* ── Number (with shared parseInt/parseFloat) ── */
    sl_native_func *num = sl_builtin_number_constructor();

    /* Create parseInt and parseFloat native funcs */
    zend_string *pi_name = zend_string_init("parseInt", 8, 0);
    sl_native_func *parseint_fn = sl_native_new(pi_name, sl_builtin_parseint);
    zend_string_release(pi_name);

    zend_string *pf_name = zend_string_init("parseFloat", 10, 0);
    sl_native_func *parsefloat_fn = sl_native_new(pf_name, sl_builtin_parsefloat);
    zend_string_release(pf_name);

    /* Add to Number object */
    sl_native_set_prop(num, "parseInt", sl_val_native(parseint_fn));
    sl_native_set_prop(num, "parseFloat", sl_val_native(parsefloat_fn));

    key = zend_string_init("Number", 6, 0);
    sl_env_define(env, key, sl_val_native(num), false);
    zend_string_release(key);
    SL_GC_DELREF(num);

    /* ── String ── */
    sl_native_func *str = sl_builtin_string_constructor();
    key = zend_string_init("String", 6, 0);
    sl_env_define(env, key, sl_val_native(str), false);
    zend_string_release(key);
    SL_GC_DELREF(str);

    /* ── Date ── */
    sl_native_func *date = sl_builtin_date_constructor();
    key = zend_string_init("Date", 4, 0);
    sl_env_define(env, key, sl_val_native(date), false);
    zend_string_release(key);
    SL_GC_DELREF(date);

    /* ── JSON ── */
    sl_native_func *json = sl_builtin_json();
    key = zend_string_init("JSON", 4, 0);
    sl_env_define(env, key, sl_val_native(json), false);
    zend_string_release(key);
    SL_GC_DELREF(json);

    /* ── RegExp ── */
    sl_native_func *regexp = sl_builtin_regexp_constructor();
    key = zend_string_init("RegExp", 6, 0);
    sl_env_define(env, key, sl_val_native(regexp), false);
    zend_string_release(key);
    SL_GC_DELREF(regexp);

    /* ── Global functions ── */
    key = zend_string_init("parseInt", 8, 0);
    sl_env_define(env, key, sl_val_native(parseint_fn), false);
    zend_string_release(key);

    key = zend_string_init("parseFloat", 10, 0);
    sl_env_define(env, key, sl_val_native(parsefloat_fn), false);
    zend_string_release(key);

    /* Release local refs -- env has references now */
    SL_GC_DELREF(parseint_fn);
    SL_GC_DELREF(parsefloat_fn);

    /* isNaN */
    zend_string *isnan_name = zend_string_init("isNaN", 5, 0);
    sl_native_func *isnan_fn = sl_native_new(isnan_name, sl_builtin_isnan);
    zend_string_release(isnan_name);
    key = zend_string_init("isNaN", 5, 0);
    sl_env_define(env, key, sl_val_native(isnan_fn), false);
    zend_string_release(key);
    SL_GC_DELREF(isnan_fn);

    /* isFinite */
    zend_string *isfinite_name = zend_string_init("isFinite", 8, 0);
    sl_native_func *isfinite_fn = sl_native_new(isfinite_name, sl_builtin_isfinite);
    zend_string_release(isfinite_name);
    key = zend_string_init("isFinite", 8, 0);
    sl_env_define(env, key, sl_val_native(isfinite_fn), false);
    zend_string_release(key);
    SL_GC_DELREF(isfinite_fn);

    /* encodeURIComponent */
    zend_string *euc_name = zend_string_init("encodeURIComponent", 18, 0);
    sl_native_func *euc_fn = sl_native_new(euc_name, sl_builtin_encode_uri_component);
    zend_string_release(euc_name);
    key = zend_string_init("encodeURIComponent", 18, 0);
    sl_env_define(env, key, sl_val_native(euc_fn), false);
    zend_string_release(key);
    SL_GC_DELREF(euc_fn);

    /* decodeURIComponent */
    zend_string *duc_name = zend_string_init("decodeURIComponent", 18, 0);
    sl_native_func *duc_fn = sl_native_new(duc_name, sl_builtin_decode_uri_component);
    zend_string_release(duc_name);
    key = zend_string_init("decodeURIComponent", 18, 0);
    sl_env_define(env, key, sl_val_native(duc_fn), false);
    zend_string_release(key);
    SL_GC_DELREF(duc_fn);

    /* encodeURI */
    zend_string *eu_name = zend_string_init("encodeURI", 9, 0);
    sl_native_func *eu_fn = sl_native_new(eu_name, sl_builtin_encode_uri);
    zend_string_release(eu_name);
    key = zend_string_init("encodeURI", 9, 0);
    sl_env_define(env, key, sl_val_native(eu_fn), false);
    zend_string_release(key);
    SL_GC_DELREF(eu_fn);

    /* decodeURI */
    zend_string *du_name = zend_string_init("decodeURI", 9, 0);
    sl_native_func *du_fn = sl_native_new(du_name, sl_builtin_decode_uri);
    zend_string_release(du_name);
    key = zend_string_init("decodeURI", 9, 0);
    sl_env_define(env, key, sl_val_native(du_fn), false);
    zend_string_release(key);
    SL_GC_DELREF(du_fn);

    /* ── Global constants ── */
    key = zend_string_init("NaN", 3, 0);
    sl_env_define(env, key, sl_val_double(NAN), true);
    zend_string_release(key);

    key = zend_string_init("Infinity", 8, 0);
    sl_env_define(env, key, sl_val_double(INFINITY), true);
    zend_string_release(key);

    key = zend_string_init("undefined", 9, 0);
    sl_env_define(env, key, sl_val_undefined(), true);
    zend_string_release(key);
}
