#include "sl_runtime.h"
#include "sl_environment.h"
#include "sl_string_methods.h"
#include "sl_array_methods.h"
#include "sl_vm.h"
#define PCRE2_CODE_UNIT_WIDTH 8
#include <pcre2.h>
#include <time.h>
#include <math.h>
#include <string.h>
#include <stdlib.h>

/* ============================================================
 * JS Array
 * ============================================================ */

sl_js_array *sl_array_new(uint32_t initial_capacity) {
    sl_js_array *arr = emalloc(sizeof(sl_js_array));
    arr->gc.refcount = 1;
    arr->length = 0;
    arr->capacity = initial_capacity > 4 ? initial_capacity : 4;
    arr->elements = emalloc(sizeof(sl_value) * arr->capacity);
    arr->properties = NULL;
    return arr;
}

sl_js_array *sl_array_new_from(sl_value *elements, uint32_t count) {
    sl_js_array *arr = sl_array_new(count);
    arr->length = count;
    for (uint32_t i = 0; i < count; i++) {
        arr->elements[i] = elements[i];
        SL_ADDREF(arr->elements[i]);
    }
    return arr;
}

void sl_array_free(sl_js_array *arr) {
    for (uint32_t i = 0; i < arr->length; i++) {
        SL_DELREF(arr->elements[i]);
    }
    efree(arr->elements);
    if (arr->properties) {
        zend_hash_destroy(arr->properties);
        FREE_HASHTABLE(arr->properties);
    }
    efree(arr);
}

void sl_array_ensure_capacity(sl_js_array *arr, uint32_t needed) {
    if (needed <= arr->capacity) return;
    uint32_t new_cap = arr->capacity;
    while (new_cap < needed) new_cap *= 2;
    arr->elements = erealloc(arr->elements, sizeof(sl_value) * new_cap);
    arr->capacity = new_cap;
}

void sl_array_push(sl_js_array *arr, sl_value val) {
    sl_array_ensure_capacity(arr, arr->length + 1);
    arr->elements[arr->length++] = val;
    SL_ADDREF(val);
}

sl_value sl_array_pop(sl_js_array *arr) {
    if (arr->length == 0) return sl_val_undefined();
    sl_value val = arr->elements[--arr->length];
    /* Transfer ownership -- don't delref */
    return val;
}

void sl_array_set(sl_js_array *arr, uint32_t index, sl_value val) {
    if (index >= arr->length) {
        sl_array_ensure_capacity(arr, index + 1);
        /* Fill gaps with undefined */
        for (uint32_t i = arr->length; i < index; i++) {
            arr->elements[i] = sl_val_undefined();
        }
        arr->length = index + 1;
    } else {
        SL_DELREF(arr->elements[index]);
    }
    arr->elements[index] = val;
    SL_ADDREF(val);
}

sl_value sl_array_get(sl_js_array *arr, sl_vm *vm, sl_value key) {
    /* Check for "length" */
    if (key.tag == SL_TAG_STRING && zend_string_equals_literal(key.u.str, "length")) {
        return sl_val_int(arr->length);
    }

    /* Numeric index */
    if (key.tag == SL_TAG_INT) {
        zend_long idx = key.u.ival;
        if (idx >= 0 && (uint32_t)idx < arr->length) {
            return sl_value_copy(arr->elements[idx]);
        }
        return sl_val_undefined();
    }

    if (key.tag == SL_TAG_DOUBLE) {
        zend_long idx = (zend_long)key.u.dval;
        if ((double)idx == key.u.dval && idx >= 0 && (uint32_t)idx < arr->length) {
            return sl_value_copy(arr->elements[idx]);
        }
    }

    /* String key: try numeric parse, then method lookup */
    if (key.tag == SL_TAG_STRING) {
        /* Check named properties first */
        if (arr->properties) {
            zval *found = zend_hash_find(arr->properties, key.u.str);
            if (found) {
                sl_value sv = *(sl_value*)Z_PTR_P(found);
                return sl_value_copy(sv);
            }
        }

        /* Try to parse as integer */
        zend_ulong idx;
        if (ZEND_HANDLE_NUMERIC(key.u.str, idx)) {
            if (idx < (zend_ulong)arr->length) {
                return sl_value_copy(arr->elements[(uint32_t)idx]);
            }
            return sl_val_undefined();
        }

        /* Method lookup */
        return sl_array_get_method(vm, arr, key.u.str);
    }

    return sl_val_undefined();
}

static sl_value sl_object_has_own_property_method(sl_vm *vm, sl_value *args, int argc) {
    (void) vm;

    if (argc < 2 || args[0].tag != SL_TAG_OBJECT) {
        return sl_val_bool(0);
    }

    bool tmp = false;
    zend_string *name;

    if (args[1].tag == SL_TAG_STRING) {
        name = args[1].u.str;
    } else {
        name = sl_to_js_string(args[1]);
        tmp = true;
    }

    bool result = sl_object_has_own(args[0].u.obj, name);
    if (tmp) {
        zend_string_release(name);
    }

    return sl_val_bool(result);
}

/* ============================================================
 * JS Object
 * ============================================================ */

static void sl_object_props_dtor(zval *zv) {
    sl_value *v = (sl_value*)Z_PTR_P(zv);
    SL_DELREF(*v);
    efree(v);
}

static sl_value sl_props_get(HashTable *props, zend_string *key) {
    if (!props) {
        return sl_val_undefined();
    }
    zval *found = zend_hash_find(props, key);
    if (!found) {
        return sl_val_undefined();
    }
    sl_value sv = *(sl_value*)Z_PTR_P(found);
    return sl_value_copy(sv);
}

static bool sl_props_set(HashTable **props_ptr, zend_string *key, sl_value val) {
    if (!*props_ptr) {
        ALLOC_HASHTABLE(*props_ptr);
        zend_hash_init(*props_ptr, 8, NULL, sl_object_props_dtor, 0);
    }

    sl_value *sv = emalloc(sizeof(sl_value));
    *sv = val;
    SL_ADDREF(val);

    zval zv;
    ZVAL_PTR(&zv, sv);
    if (!zend_hash_update(*props_ptr, key, &zv)) {
        SL_DELREF(*sv);
        efree(sv);
        return false;
    }
    return true;
}

sl_js_object *sl_object_new(void) {
    sl_js_object *obj = emalloc(sizeof(sl_js_object));
    obj->gc.refcount = 1;
    ALLOC_HASHTABLE(obj->properties);
    zend_hash_init(obj->properties, 8, NULL, sl_object_props_dtor, 0);
    obj->prototype = NULL;
    obj->constructor = NULL;
    return obj;
}

sl_js_object *sl_object_new_with_props(HashTable *props) {
    sl_js_object *obj = sl_object_new();
    /* Copy properties from existing hash table */
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL(props, key, val) {
        if (key) {
            sl_value *sv = emalloc(sizeof(sl_value));
            *sv = sl_zval_to_value(val);
            zval zv;
            ZVAL_PTR(&zv, sv);
            zend_hash_add(obj->properties, key, &zv);
        }
    } ZEND_HASH_FOREACH_END();
    return obj;
}

void sl_object_free(sl_js_object *obj) {
    zend_hash_destroy(obj->properties);
    FREE_HASHTABLE(obj->properties);
    if (obj->prototype) {
        if (SL_GC_DELREF(obj->prototype) == 0) {
            sl_object_free(obj->prototype);
        }
    }
    if (obj->constructor) {
        if (SL_GC_DELREF(obj->constructor) == 0) {
            sl_closure_free(obj->constructor);
        }
    }
    efree(obj);
}

sl_value sl_object_get(sl_js_object *obj, sl_vm *vm, zend_string *key) {
    /* Check own properties */
    zval *found = zend_hash_find(obj->properties, key);
    if (found) {
        sl_value sv = *(sl_value*)Z_PTR_P(found);
        return sl_value_copy(sv);
    }

    /* Check hasOwnProperty method */
    if (zend_string_equals_literal(key, "hasOwnProperty")) {
        sl_value receiver = sl_val_object(obj);
        zend_string *name = zend_string_init("hasOwnProperty", 14, 0);
        sl_native_func *fn = sl_native_new_bound(name, sl_object_has_own_property_method, receiver);
        zend_string_release(name);
        return sl_val_native(fn);
    }

    /* Walk prototype chain */
    if (obj->prototype) {
        return sl_object_get(obj->prototype, vm, key);
    }

    return sl_val_undefined();
}

void sl_object_set(sl_js_object *obj, zend_string *key, sl_value val) {
    zval *existing = zend_hash_find(obj->properties, key);
    if (existing) {
        sl_value *sv = (sl_value*)Z_PTR_P(existing);
        SL_DELREF(*sv);
        *sv = val;
        SL_ADDREF(val);
    } else {
        sl_value *sv = emalloc(sizeof(sl_value));
        *sv = val;
        SL_ADDREF(val);
        zval zv;
        ZVAL_PTR(&zv, sv);
        zend_hash_add(obj->properties, key, &zv);
    }
}

bool sl_object_has_own(sl_js_object *obj, zend_string *key) {
    return zend_hash_exists(obj->properties, key);
}

void sl_object_delete(sl_js_object *obj, zend_string *key) {
    zend_hash_del(obj->properties, key);
}

/* ============================================================
 * JS Closure
 * ============================================================ */

sl_js_closure *sl_closure_new(sl_func_descriptor *desc, sl_environment *env) {
    sl_js_closure *c = emalloc(sizeof(sl_js_closure));
    c->gc.refcount = 1;
    c->descriptor = desc;
    SL_GC_ADDREF(desc);
    c->captured_env = env;
    if (env) {
        SL_GC_ADDREF(env);
    }
    c->properties = NULL;
    return c;
}

void sl_closure_free(sl_js_closure *c) {
    if (SL_GC_DELREF(c->descriptor) == 0) {
        sl_func_descriptor_free(c->descriptor);
    }
    if (c->captured_env && SL_GC_DELREF(c->captured_env) == 0) {
        sl_env_free(c->captured_env);
    }
    if (c->properties) {
        zend_hash_destroy(c->properties);
        FREE_HASHTABLE(c->properties);
    }
    efree(c);
}

sl_js_object *sl_closure_get_prototype(sl_js_closure *closure, bool create_if_missing) {
    zend_string *prototype_key = SL_G(str_prototype);
    bool release_prototype_key = false;
    if (!prototype_key) {
        prototype_key = zend_string_init("prototype", sizeof("prototype") - 1, 0);
        release_prototype_key = true;
    }

    sl_value existing = sl_props_get(closure->properties, prototype_key);
    if (existing.tag == SL_TAG_OBJECT) {
        sl_js_object *obj = existing.u.obj;
        SL_DELREF(existing);
        if (release_prototype_key) {
            zend_string_release(prototype_key);
        }
        return obj;
    }
    if (existing.tag != SL_TAG_UNDEFINED) {
        SL_DELREF(existing);
    }

    if (!create_if_missing) {
        if (release_prototype_key) {
            zend_string_release(prototype_key);
        }
        return NULL;
    }

    sl_js_object *proto = sl_object_new();

    zend_string *constructor_key = SL_G(str_constructor);
    bool release_constructor_key = false;
    if (!constructor_key) {
        constructor_key = zend_string_init("constructor", sizeof("constructor") - 1, 0);
        release_constructor_key = true;
    }

    sl_object_set(proto, constructor_key, sl_val_closure(closure));
    if (release_constructor_key) {
        zend_string_release(constructor_key);
    }

    if (!sl_props_set(&closure->properties, prototype_key, sl_val_object(proto))) {
        if (SL_GC_DELREF(proto) == 0) {
            sl_object_free(proto);
        }
        if (release_prototype_key) {
            zend_string_release(prototype_key);
        }
        return NULL;
    }

    if (release_prototype_key) {
        zend_string_release(prototype_key);
    }
    if (SL_GC_DELREF(proto) == 0) {
        sl_object_free(proto);
        return NULL;
    }
    return proto;
}

/* ============================================================
 * Native Function
 * ============================================================ */

sl_native_func *sl_native_new(zend_string *name, sl_native_handler handler) {
    sl_native_func *f = emalloc(sizeof(sl_native_func));
    f->gc.refcount = 1;
    f->name = name ? zend_string_copy(name) : NULL;
    f->handler = handler;
    ZVAL_UNDEF(&f->php_callable);
    f->properties = NULL;
    f->bound_receiver = sl_val_undefined();
    return f;
}

sl_native_func *sl_native_new_bound(zend_string *name, sl_native_handler handler, sl_value receiver) {
    sl_native_func *f = sl_native_new(name, handler);
    f->bound_receiver = receiver;
    SL_ADDREF(receiver);
    return f;
}

sl_native_func *sl_native_new_php(zval *callable) {
    sl_native_func *f = emalloc(sizeof(sl_native_func));
    f->gc.refcount = 1;
    f->name = NULL;
    f->handler = NULL;
    ZVAL_COPY(&f->php_callable, callable);
    f->properties = NULL;
    f->bound_receiver = sl_val_undefined();
    return f;
}

void sl_native_free(sl_native_func *f) {
    if (f->name) zend_string_release(f->name);
    if (!Z_ISUNDEF(f->php_callable)) {
        zval_ptr_dtor(&f->php_callable);
    }
    if (f->properties) {
        zend_hash_destroy(f->properties);
        FREE_HASHTABLE(f->properties);
    }
    SL_DELREF(f->bound_receiver);
    efree(f);
}

/* ============================================================
 * JS Date
 * ============================================================ */

sl_js_date *sl_date_new(double timestamp) {
    sl_js_date *d = emalloc(sizeof(sl_js_date));
    d->gc.refcount = 1;
    d->timestamp = timestamp;
    return d;
}

void sl_date_free(sl_js_date *d) {
    efree(d);
}

/* ============================================================
 * JS Regex
 * ============================================================ */

sl_js_regex *sl_regex_new(zend_string *pattern, zend_string *flags) {
    sl_js_regex *r = emalloc(sizeof(sl_js_regex));
    r->gc.refcount = 1;
    r->pattern = zend_string_copy(pattern);
    r->flags = flags ? zend_string_copy(flags) : zend_string_init("", 0, 0);
    r->last_index = 0;
    r->pcre_options = PCRE2_UTF | PCRE2_UCP;
    r->is_global = 0;
    r->compiled_code = NULL;

    for (size_t i = 0; i < ZSTR_LEN(r->flags); i++) {
        switch (ZSTR_VAL(r->flags)[i]) {
            case 'i':
                r->pcre_options |= PCRE2_CASELESS;
                break;
            case 'm':
                r->pcre_options |= PCRE2_MULTILINE;
                break;
            case 's':
                r->pcre_options |= PCRE2_DOTALL;
                break;
            case 'g':
                r->is_global = 1;
                break;
            default:
                break;
        }
    }

    return r;
}

void sl_regex_free(sl_js_regex *r) {
    zend_string_release(r->pattern);
    zend_string_release(r->flags);
    if (r->compiled_code) {
        pcre2_code_free((pcre2_code *)r->compiled_code);
    }
    efree(r);
}

void *sl_regex_get_compiled_code(sl_js_regex *r) {
    if (!r) return NULL;
    if (r->compiled_code) return r->compiled_code;

    PCRE2_SIZE erroffset = 0;
    int errcode = 0;
    pcre2_code *re = pcre2_compile((PCRE2_SPTR)ZSTR_VAL(r->pattern),
                                   ZSTR_LEN(r->pattern),
                                   r->pcre_options,
                                   &errcode,
                                   &erroffset,
                                   NULL);
    if (!re) return NULL;

    /* Best-effort JIT: ignored if unavailable for this build/pattern. */
    (void)pcre2_jit_compile(re, PCRE2_JIT_COMPLETE);

    r->compiled_code = (void *)re;
    return r->compiled_code;
}

uint32_t sl_regex_get_options(const sl_js_regex *r) {
    return r ? r->pcre_options : (PCRE2_UTF | PCRE2_UCP);
}

bool sl_regex_is_global(const sl_js_regex *r) {
    return r && r->is_global;
}

bool sl_regex_test(sl_js_regex *r, zend_string *str) {
    pcre2_code *re = (pcre2_code *)sl_regex_get_compiled_code(r);
    pcre2_match_data *match_data;
    int rc;
    if (!re) return false;

    match_data = pcre2_match_data_create_from_pattern(re, NULL);

    bool is_global = sl_regex_is_global(r);
    PCRE2_SIZE start = is_global ? (PCRE2_SIZE)r->last_index : 0;
    if (is_global) {
        if (start > ZSTR_LEN(str)) start = 0;
    }

    rc = pcre2_match(re, (PCRE2_SPTR)ZSTR_VAL(str), ZSTR_LEN(str),
                     start, 0, match_data, NULL);

    if (rc > 0 && is_global) {
        PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);
        r->last_index = (zend_long)ovector[1];
    } else if (rc <= 0 && is_global) {
        r->last_index = 0;
    }

    pcre2_match_data_free(match_data);
    return rc > 0;
}

sl_value sl_regex_exec(sl_js_regex *r, zend_string *str, sl_vm *vm) {
    (void)vm;

    pcre2_code *re = (pcre2_code *)sl_regex_get_compiled_code(r);
    pcre2_match_data *match_data;
    int rc;
    bool is_global = sl_regex_is_global(r);
    if (!re) return sl_val_null();

    match_data = pcre2_match_data_create_from_pattern(re, NULL);
    PCRE2_SIZE start = is_global ? (PCRE2_SIZE)r->last_index : 0;
    if (is_global && start > ZSTR_LEN(str)) start = 0;

    rc = pcre2_match(re, (PCRE2_SPTR)ZSTR_VAL(str), ZSTR_LEN(str),
                     start, 0, match_data, NULL);
    if (rc <= 0) {
        if (is_global) {
            r->last_index = 0;
        }
        pcre2_match_data_free(match_data);
        return sl_val_null();
    }

    PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);
    if (is_global) {
        r->last_index = (zend_long)ovector[1];
    }

    sl_js_array *result = sl_array_new((uint32_t)rc);
    for (int i = 0; i < rc; i++) {
        PCRE2_SIZE s = ovector[2 * i];
        PCRE2_SIZE e = ovector[2 * i + 1];
        if (s == PCRE2_UNSET || e == PCRE2_UNSET) {
            sl_array_push(result, sl_val_undefined());
        } else {
            zend_string *match = zend_string_init(ZSTR_VAL(str) + s, e - s, 0);
            sl_array_push(result, sl_val_string(match));
            zend_string_release(match);
        }
    }

    if (!result->properties) {
        ALLOC_HASHTABLE(result->properties);
        zend_hash_init(result->properties, 2, NULL, NULL, 0);
    }

    sl_value *idx_v = emalloc(sizeof(sl_value));
    *idx_v = sl_val_int((zend_long)ovector[0]);
    zval idx_zv;
    ZVAL_PTR(&idx_zv, idx_v);
    zend_hash_str_add(result->properties, "index", 5, &idx_zv);

    sl_value *input_v = emalloc(sizeof(sl_value));
    *input_v = sl_val_string(zend_string_copy(str));
    zval input_zv;
    ZVAL_PTR(&input_zv, input_v);
    zend_hash_str_add(result->properties, "input", 5, &input_zv);

    pcre2_match_data_free(match_data);
    return sl_val_array(result);
}
static sl_value sl_num_toString(sl_vm *vm, sl_value *args, int argc); /* forward declaration */

static sl_value sl_num_toPrecision(sl_vm *vm, sl_value *args, int argc) {
    double val = (args[0].tag == SL_TAG_INT) ? (double)args[0].u.ival : args[0].u.dval;
    if (argc <= 1 || args[1].tag == SL_TAG_UNDEFINED) {
        return sl_num_toString(vm, args, 1);
    }

    if (isnan(val) || isinf(val)) {
        return sl_val_string(sl_to_js_string(args[0]));
    }

    int precision = (int)sl_to_number(args[1]);
    if (precision < 1 || precision > 21) {
        return sl_val_string(sl_to_js_string(args[0]));
    }

    char buf[256];
    int len = snprintf(buf, sizeof(buf), "%.*g", precision, val);
    return sl_val_string(zend_string_init(buf, len, 0));
}

static sl_value sl_num_toExponential(sl_vm *vm, sl_value *args, int argc) {
    (void)vm;

    double val = (args[0].tag == SL_TAG_INT) ? (double)args[0].u.ival : args[0].u.dval;
    if (isnan(val) || isinf(val)) {
        return sl_val_string(sl_to_js_string(args[0]));
    }

    int precision = 0;
    if (argc > 1 && args[1].tag != SL_TAG_UNDEFINED) {
        precision = (int)sl_to_number(args[1]);
    }
    if (precision < 0) precision = 0;
    if (precision > 20) precision = 20;

    char buf[256];
    int len = snprintf(buf, sizeof(buf), "%.*e", precision, val);
    if (len <= 0) {
        return sl_val_string(zend_string_init("", 0, 0));
    }

    char *exp = strchr(buf, 'e');
    if (!exp) {
        return sl_val_string(zend_string_init(buf, len, 0));
    }

    int exponent = atoi(exp + 1);
    *exp = '\0';

    char exp_buf[64];
    int exp_len = snprintf(exp_buf, sizeof(exp_buf), "e%+d", exponent);
    if (exp_len < 0) {
        return sl_val_string(zend_string_init("", 0, 0));
    }

    char out[320];
    int out_len = snprintf(out, sizeof(out), "%s%s", buf, exp_buf);
    return sl_val_string(zend_string_init(out, out_len < 0 ? 0 : out_len, 0));
}

/* ============================================================
 * Function Descriptor
 * ============================================================ */

sl_func_descriptor *sl_func_descriptor_new(void) {
    sl_func_descriptor *desc = ecalloc(1, sizeof(sl_func_descriptor));
    desc->gc.refcount = 1;
    desc->rest_param_slot = -1;
    return desc;
}

void sl_func_descriptor_free(sl_func_descriptor *desc) {
    if (desc->name) zend_string_release(desc->name);
    if (desc->ops) efree(desc->ops);
    if (desc->opA) efree(desc->opA);
    if (desc->opB) efree(desc->opB);
    if (desc->constants) {
        for (uint32_t i = 0; i < desc->const_count; i++) {
            SL_DELREF(desc->constants[i]);
        }
        efree(desc->constants);
    }
    if (desc->names) {
        for (uint32_t i = 0; i < desc->name_count; i++) {
            zend_string_release(desc->names[i]);
        }
        efree(desc->names);
    }
    if (desc->params) {
        for (uint32_t i = 0; i < desc->param_count; i++) {
            zend_string_release(desc->params[i]);
        }
        efree(desc->params);
    }
    if (desc->param_slots) efree(desc->param_slots);
    if (desc->rest_param) zend_string_release(desc->rest_param);
    efree(desc);
}

/* ============================================================
 * Compiled Script
 * ============================================================ */

sl_compiled_script *sl_compiled_script_new(sl_func_descriptor *main) {
    sl_compiled_script *script = emalloc(sizeof(sl_compiled_script));
    script->gc.refcount = 1;
    script->main = main;
    SL_GC_ADDREF(main);
    return script;
}

void sl_compiled_script_free(sl_compiled_script *script) {
    if (SL_GC_DELREF(script->main) == 0) {
        sl_func_descriptor_free(script->main);
    }
    efree(script);
}

/* ============================================================
 * Date method handlers (bound: args[0] = date)
 * ============================================================ */

static sl_value sl_date_getTime(sl_vm *vm, sl_value *args, int argc) {
    return sl_val_double(args[0].u.date->timestamp);
}

static sl_value sl_date_getFullYear(sl_vm *vm, sl_value *args, int argc) {
    time_t t = (time_t)(args[0].u.date->timestamp / 1000.0);
    struct tm *gm = gmtime(&t);
    return sl_val_int(gm ? gm->tm_year + 1900 : 0);
}

static sl_value sl_date_getMonth(sl_vm *vm, sl_value *args, int argc) {
    time_t t = (time_t)(args[0].u.date->timestamp / 1000.0);
    struct tm *gm = gmtime(&t);
    return sl_val_int(gm ? gm->tm_mon : 0);
}

static sl_value sl_date_getDate(sl_vm *vm, sl_value *args, int argc) {
    time_t t = (time_t)(args[0].u.date->timestamp / 1000.0);
    struct tm *gm = gmtime(&t);
    return sl_val_int(gm ? gm->tm_mday : 0);
}

static sl_value sl_date_getDay(sl_vm *vm, sl_value *args, int argc) {
    time_t t = (time_t)(args[0].u.date->timestamp / 1000.0);
    struct tm *gm = gmtime(&t);
    return sl_val_int(gm ? gm->tm_wday : 0);
}

static sl_value sl_date_getHours(sl_vm *vm, sl_value *args, int argc) {
    time_t t = (time_t)(args[0].u.date->timestamp / 1000.0);
    struct tm *gm = gmtime(&t);
    return sl_val_int(gm ? gm->tm_hour : 0);
}

static sl_value sl_date_getMinutes(sl_vm *vm, sl_value *args, int argc) {
    time_t t = (time_t)(args[0].u.date->timestamp / 1000.0);
    struct tm *gm = gmtime(&t);
    return sl_val_int(gm ? gm->tm_min : 0);
}

static sl_value sl_date_getSeconds(sl_vm *vm, sl_value *args, int argc) {
    time_t t = (time_t)(args[0].u.date->timestamp / 1000.0);
    struct tm *gm = gmtime(&t);
    return sl_val_int(gm ? gm->tm_sec : 0);
}

static sl_value sl_date_getMilliseconds(sl_vm *vm, sl_value *args, int argc) {
    double ms = fmod(args[0].u.date->timestamp, 1000.0);
    if (ms < 0) ms += 1000.0;
    return sl_val_int((zend_long)ms);
}

static sl_value sl_date_toISOString(sl_vm *vm, sl_value *args, int argc) {
    double ts = args[0].u.date->timestamp;
    time_t t = (time_t)(ts / 1000.0);
    struct tm *gm = gmtime(&t);
    if (!gm) return sl_val_string(zend_string_init("Invalid Date", 12, 0));
    double abs_ms = fmod(fabs(ts), 1000.0);
    char buf[64];
    int len = snprintf(buf, sizeof(buf), "%04d-%02d-%02dT%02d:%02d:%02d.%03dZ",
        gm->tm_year + 1900, gm->tm_mon + 1, gm->tm_mday,
        gm->tm_hour, gm->tm_min, gm->tm_sec, (int)abs_ms);
    return sl_val_string(zend_string_init(buf, len, 0));
}

static sl_value sl_date_toString(sl_vm *vm, sl_value *args, int argc) {
    double ts = args[0].u.date->timestamp;
    time_t t = (time_t)(ts / 1000.0);
    struct tm *gm = gmtime(&t);
    if (!gm) return sl_val_string(zend_string_init("Invalid Date", 12, 0));
    static const char *days[] = {"Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"};
    static const char *months[] = {"Jan", "Feb", "Mar", "Apr", "May", "Jun",
                                   "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"};
    char buf[128];
    int len = snprintf(buf, sizeof(buf), "%s %s %02d %04d %02d:%02d:%02d GMT+0000 (UTC)",
        days[gm->tm_wday], months[gm->tm_mon], gm->tm_mday,
        gm->tm_year + 1900, gm->tm_hour, gm->tm_min, gm->tm_sec);
    return sl_val_string(zend_string_init(buf, len, 0));
}

static sl_value sl_date_toLocaleDateString(sl_vm *vm, sl_value *args, int argc) {
    double ts = args[0].u.date->timestamp;
    time_t t = (time_t)(ts / 1000.0);
    struct tm *gm = gmtime(&t);
    if (!gm) return sl_val_string(zend_string_init("Invalid Date", 12, 0));
    char buf[32];
    int len = snprintf(buf, sizeof(buf), "%d/%d/%04d",
        gm->tm_mon + 1, gm->tm_mday, gm->tm_year + 1900);
    return sl_val_string(zend_string_init(buf, len, 0));
}

static sl_value sl_date_valueOf(sl_vm *vm, sl_value *args, int argc) {
    return sl_val_double(args[0].u.date->timestamp);
}

static sl_value sl_date_setTime(sl_vm *vm, sl_value *args, int argc) {
    if (argc > 1) {
        double new_ts = sl_to_number(args[1]);
        args[0].u.date->timestamp = new_ts;
        return sl_val_double(new_ts);
    }
    return sl_val_double(args[0].u.date->timestamp);
}

typedef struct { const char *name; sl_native_handler handler; } sl_method_entry;

static const sl_method_entry date_methods[] = {
    { "getTime",            sl_date_getTime },
    { "getFullYear",        sl_date_getFullYear },
    { "getMonth",           sl_date_getMonth },
    { "getDate",            sl_date_getDate },
    { "getDay",             sl_date_getDay },
    { "getHours",           sl_date_getHours },
    { "getMinutes",         sl_date_getMinutes },
    { "getSeconds",         sl_date_getSeconds },
    { "getMilliseconds",    sl_date_getMilliseconds },
    { "toISOString",        sl_date_toISOString },
    { "toString",           sl_date_toString },
    { "toLocaleDateString", sl_date_toLocaleDateString },
    { "valueOf",            sl_date_valueOf },
    { "setTime",            sl_date_setTime },
    { NULL, NULL }
};

static sl_value sl_date_get_property(sl_js_date *date, zend_string *key) {
    const char *name = ZSTR_VAL(key);
    sl_value receiver;
    receiver.tag = SL_TAG_DATE;
    receiver.u.date = date;

    for (const sl_method_entry *e = date_methods; e->name != NULL; e++) {
        if (strcmp(name, e->name) == 0) {
            zend_string *fn_name = zend_string_init(e->name, strlen(e->name), 0);
            sl_native_func *fn = sl_native_new_bound(fn_name, e->handler, receiver);
            zend_string_release(fn_name);
            return sl_val_native(fn);
        }
    }
    return sl_val_undefined();
}

/* ============================================================
 * Regex method handlers (bound: args[0] = regex)
 * ============================================================ */

static sl_value sl_regex_test_method(sl_vm *vm, sl_value *args, int argc) {
    sl_js_regex *r = args[0].u.regex;
    if (argc < 2) return sl_val_bool(0);
    zend_string *str = sl_to_js_string(args[1]);
    bool result = sl_regex_test(r, str);
    zend_string_release(str);
    return sl_val_bool(result);
}

static sl_value sl_regex_exec_method(sl_vm *vm, sl_value *args, int argc) {
    sl_js_regex *r = args[0].u.regex;
    if (argc < 2) return sl_val_null();
    zend_string *str = sl_to_js_string(args[1]);
    sl_value result = sl_regex_exec(r, str, vm);
    zend_string_release(str);
    return result;
}

static sl_value sl_regex_get_property(sl_js_regex *regex, zend_string *key) {
    const char *name = ZSTR_VAL(key);

    /* Properties */
    if (strcmp(name, "source") == 0) return sl_val_string(zend_string_copy(regex->pattern));
    if (strcmp(name, "flags") == 0) return sl_val_string(zend_string_copy(regex->flags));
    if (strcmp(name, "lastIndex") == 0) return sl_val_int(regex->last_index);
    if (strcmp(name, "global") == 0) {
        for (size_t i = 0; i < ZSTR_LEN(regex->flags); i++) {
            if (ZSTR_VAL(regex->flags)[i] == 'g') return sl_val_bool(1);
        }
        return sl_val_bool(0);
    }
    if (strcmp(name, "ignoreCase") == 0) {
        for (size_t i = 0; i < ZSTR_LEN(regex->flags); i++) {
            if (ZSTR_VAL(regex->flags)[i] == 'i') return sl_val_bool(1);
        }
        return sl_val_bool(0);
    }
    if (strcmp(name, "multiline") == 0) {
        for (size_t i = 0; i < ZSTR_LEN(regex->flags); i++) {
            if (ZSTR_VAL(regex->flags)[i] == 'm') return sl_val_bool(1);
        }
        return sl_val_bool(0);
    }

    /* Methods */
    sl_value receiver;
    receiver.tag = SL_TAG_REGEX;
    receiver.u.regex = regex;

    if (strcmp(name, "test") == 0) {
        zend_string *fn_name = zend_string_init("test", 4, 0);
        sl_native_func *fn = sl_native_new_bound(fn_name, sl_regex_test_method, receiver);
        zend_string_release(fn_name);
        return sl_val_native(fn);
    }
    if (strcmp(name, "exec") == 0) {
        zend_string *fn_name = zend_string_init("exec", 4, 0);
        sl_native_func *fn = sl_native_new_bound(fn_name, sl_regex_exec_method, receiver);
        zend_string_release(fn_name);
        return sl_val_native(fn);
    }

    return sl_val_undefined();
}

/* ============================================================
 * Number method handlers (bound: args[0] = number)
 * ============================================================ */

static sl_value sl_num_toString(sl_vm *vm, sl_value *args, int argc) {
    int radix = 10;
    if (argc > 1 && args[1].tag == SL_TAG_INT) radix = (int)args[1].u.ival;
    else if (argc > 1 && args[1].tag == SL_TAG_DOUBLE) radix = (int)args[1].u.dval;

    double val = (args[0].tag == SL_TAG_INT) ? (double)args[0].u.ival : args[0].u.dval;

    if (radix == 10 || radix < 2 || radix > 36) {
        return sl_val_string(sl_to_js_string(args[0]));
    }

    /* Non-base-10 toString */
    zend_long ival = (zend_long)val;
    char buf[128];
    int pos = sizeof(buf) - 1;
    buf[pos] = '\0';
    bool neg = ival < 0;
    if (neg) ival = -ival;
    if (ival == 0) { buf[--pos] = '0'; }
    else {
        while (ival > 0) {
            int digit = ival % radix;
            buf[--pos] = digit < 10 ? '0' + digit : 'a' + digit - 10;
            ival /= radix;
        }
    }
    if (neg) buf[--pos] = '-';
    int len = sizeof(buf) - 1 - pos;
    return sl_val_string(zend_string_init(&buf[pos], len, 0));
}

static sl_value sl_num_toFixed(sl_vm *vm, sl_value *args, int argc) {
    double val = (args[0].tag == SL_TAG_INT) ? (double)args[0].u.ival : args[0].u.dval;
    int digits = 0;
    if (argc > 1) digits = (int)sl_to_number(args[1]);
    if (digits < 0) digits = 0;
    if (digits > 100) digits = 100;
    char buf[256];
    int len = snprintf(buf, sizeof(buf), "%.*f", digits, val);
    return sl_val_string(zend_string_init(buf, len, 0));
}

static sl_value sl_number_get_property(sl_value num, zend_string *key) {
    const char *name = ZSTR_VAL(key);

    sl_native_handler handler = NULL;
    if (strcmp(name, "toString") == 0) handler = sl_num_toString;
    else if (strcmp(name, "toFixed") == 0) handler = sl_num_toFixed;
    else if (strcmp(name, "toPrecision") == 0) handler = sl_num_toPrecision;
    else if (strcmp(name, "toExponential") == 0) handler = sl_num_toExponential;

    if (handler) {
        zend_string *fn_name = zend_string_init(name, strlen(name), 0);
        sl_native_func *fn = sl_native_new_bound(fn_name, handler, num);
        zend_string_release(fn_name);
        return sl_val_native(fn);
    }
    return sl_val_undefined();
}

/* ============================================================
 * Property Access Helpers
 * ============================================================ */

sl_value sl_get_property(sl_vm *vm, sl_value target, sl_value key) {
    switch (target.tag) {
        case SL_TAG_ARRAY:
            return sl_array_get(target.u.arr, vm, key);
        case SL_TAG_OBJECT: {
            zend_string *skey;
            if (key.tag == SL_TAG_STRING) {
                skey = key.u.str;
            } else {
                skey = sl_to_js_string(key);
            }
            sl_value result = sl_object_get(target.u.obj, vm, skey);
            if (key.tag != SL_TAG_STRING) zend_string_release(skey);
            return result;
        }
        case SL_TAG_STRING:
            return sl_string_get_property(target.u.str, key);
        case SL_TAG_DATE: {
            if (key.tag == SL_TAG_STRING) {
                return sl_date_get_property(target.u.date, key.u.str);
            }
            return sl_val_undefined();
        }
        case SL_TAG_REGEX: {
            if (key.tag == SL_TAG_STRING) {
                return sl_regex_get_property(target.u.regex, key.u.str);
            }
            return sl_val_undefined();
        }
        case SL_TAG_NATIVE: {
            /* Function-as-object: check properties */
            if (key.tag == SL_TAG_STRING && target.u.native->properties) {
                zval *found = zend_hash_find(target.u.native->properties, key.u.str);
                if (found) {
                    sl_value sv = *(sl_value*)Z_PTR_P(found);
                    return sl_value_copy(sv);
                }
            }
            return sl_val_undefined();
        }
        case SL_TAG_CLOSURE: {
            if (key.tag != SL_TAG_STRING) {
                return sl_val_undefined();
            }

            if (zend_string_equals_literal(key.u.str, "length")) {
                return sl_val_int((zend_long)target.u.closure->descriptor->param_count);
            }
            if (zend_string_equals_literal(key.u.str, "name")) {
                zend_string *name = target.u.closure->descriptor->name
                    ? target.u.closure->descriptor->name
                    : ZSTR_EMPTY_ALLOC();
                return sl_val_string(zend_string_copy(name));
            }
            if (zend_string_equals_literal(key.u.str, "prototype")) {
                sl_value existing = sl_props_get(target.u.closure->properties, key.u.str);
                if (existing.tag != SL_TAG_UNDEFINED) {
                    return existing;
                }
                sl_js_object *prototype = sl_closure_get_prototype(target.u.closure, true);
                if (!prototype) {
                    return sl_val_undefined();
                }
                sl_value result = sl_val_object(prototype);
                return sl_value_copy(result);
            }

            return sl_props_get(target.u.closure->properties, key.u.str);
        }
        case SL_TAG_INT:
        case SL_TAG_DOUBLE: {
            if (key.tag == SL_TAG_STRING) {
                return sl_number_get_property(target, key.u.str);
            }
            return sl_val_undefined();
        }
        default:
            return sl_val_undefined();
    }
}

sl_value sl_get_property_opt(sl_vm *vm, sl_value target, sl_value key) {
    if (SL_IS_NULLISH(target)) {
        return sl_val_undefined();
    }
    return sl_get_property(vm, target, key);
}

void sl_set_property(sl_vm *vm, sl_value target, sl_value key, sl_value val) {
    switch (target.tag) {
        case SL_TAG_ARRAY: {
            if (key.tag == SL_TAG_INT && key.u.ival >= 0) {
                sl_array_set(target.u.arr, (uint32_t)key.u.ival, val);
            } else if (key.tag == SL_TAG_DOUBLE) {
                zend_long idx = (zend_long)key.u.dval;
                if ((double)idx == key.u.dval && idx >= 0) {
                    sl_array_set(target.u.arr, (uint32_t)idx, val);
                }
            } else if (key.tag == SL_TAG_STRING) {
                if (zend_string_equals_literal(key.u.str, "length")) {
                    /* Setting length truncates array */
                    zend_long new_len = (zend_long)sl_to_number(val);
                    if (new_len >= 0 && (uint32_t)new_len < target.u.arr->length) {
                        for (uint32_t i = (uint32_t)new_len; i < target.u.arr->length; i++) {
                            SL_DELREF(target.u.arr->elements[i]);
                        }
                        target.u.arr->length = (uint32_t)new_len;
                    }
                    return;
                }
                /* Named property on array */
                zend_ulong idx;
                if (ZEND_HANDLE_NUMERIC(key.u.str, idx) && idx <= UINT32_MAX) {
                    sl_array_set(target.u.arr, (uint32_t)idx, val);
                }
            }
            break;
        }
        case SL_TAG_OBJECT: {
            zend_string *skey;
            if (key.tag == SL_TAG_STRING) {
                skey = key.u.str;
            } else {
                skey = sl_to_js_string(key);
            }
            sl_object_set(target.u.obj, skey, val);
            if (key.tag != SL_TAG_STRING) zend_string_release(skey);
            break;
        }
        case SL_TAG_REGEX: {
            if (key.tag == SL_TAG_STRING && zend_string_equals_literal(key.u.str, "lastIndex")) {
                target.u.regex->last_index = (zend_long)sl_to_number(val);
            }
            break;
        }
        case SL_TAG_NATIVE:
        case SL_TAG_CLOSURE: {
            zend_string *skey;
            bool release_skey = false;
            if (key.tag == SL_TAG_STRING) {
                skey = key.u.str;
            } else {
                skey = sl_to_js_string(key);
                release_skey = true;
            }
            if (target.tag == SL_TAG_NATIVE) {
                sl_props_set(&target.u.native->properties, skey, val);
            } else {
                sl_props_set(&target.u.closure->properties, skey, val);
            }
            if (release_skey) {
                zend_string_release(skey);
            }
            break;
        }
        default:
            break;
    }
}
