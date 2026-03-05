/*
 * sl_array_methods.c — Array prototype methods for ScriptLite VM
 *
 * Implements: push, pop, shift, unshift, splice, reverse, sort, fill,
 *             join, indexOf, includes, slice, concat, flat, flatMap, at,
 *             forEach, map, filter, find, findIndex, findLast, findLastIndex,
 *             reduce, reduceRight, every, some
 */

#include "sl_array_methods.h"
#include "sl_runtime.h"
#include "sl_value.h"
#include "sl_vm.h"
#include <string.h>

/* ============================================================
 * Array method handlers
 *
 * Each handler's first arg (args[0]) is the bound array (SL_TAG_ARRAY).
 * Remaining args[1..argc-1] are the method arguments from JS call.
 * ============================================================ */

static sl_value sl_arr_push(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    for (int i = 1; i < argc; i++) {
        sl_array_push(arr, args[i]);
    }
    return sl_val_int((zend_long)arr->length);
}

static sl_value sl_arr_pop(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (arr->length == 0) return sl_val_undefined();
    return sl_array_pop(arr);
}

static sl_value sl_arr_shift(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (arr->length == 0) return sl_val_undefined();

    sl_value first = arr->elements[0];
    /* Don't delref -- transferring ownership */
    memmove(arr->elements, arr->elements + 1, sizeof(sl_value) * (arr->length - 1));
    arr->length--;
    return first;
}

static sl_value sl_arr_unshift(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    int count = argc - 1; /* number of items to prepend */
    if (count <= 0) return sl_val_int((zend_long)arr->length);

    sl_array_ensure_capacity(arr, arr->length + count);
    /* Shift existing elements to the right */
    memmove(arr->elements + count, arr->elements, sizeof(sl_value) * arr->length);
    /* Insert new elements */
    for (int i = 0; i < count; i++) {
        arr->elements[i] = args[i + 1];
        SL_ADDREF(arr->elements[i]);
    }
    arr->length += count;
    return sl_val_int((zend_long)arr->length);
}

static sl_value sl_arr_splice(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    zend_long len = (zend_long)arr->length;

    zend_long s = argc > 1 ? (zend_long)sl_to_number(args[1]) : 0;
    if (s < 0) s = len + s;
    if (s < 0) s = 0;
    if (s > len) s = len;

    zend_long dc;
    if (argc <= 2 || args[2].tag == SL_TAG_UNDEFINED) {
        dc = len - s;
    } else {
        dc = (zend_long)sl_to_number(args[2]);
        if (dc < 0) dc = 0;
        if (dc > len - s) dc = len - s;
    }

    /* Collect items to insert */
    int insert_count = argc > 3 ? argc - 3 : 0;

    /* Create removed array */
    sl_js_array *removed = sl_array_new(dc > 0 ? (uint32_t)dc : 1);
    for (zend_long i = 0; i < dc; i++) {
        sl_array_push(removed, arr->elements[s + i]);
    }

    /* Remove old elements and insert new ones */
    zend_long new_len = len - dc + insert_count;
    if (new_len > len) {
        sl_array_ensure_capacity(arr, (uint32_t)new_len);
    }

    /* Shift elements after the splice point */
    if (insert_count != dc) {
        memmove(arr->elements + s + insert_count,
                arr->elements + s + dc,
                sizeof(sl_value) * (len - s - dc));
    }

    /* Delref removed elements (after memmove so we don't use freed memory) */
    for (zend_long i = 0; i < dc; i++) {
        SL_DELREF(removed->elements[i]);
        /* Re-addref since sl_array_push already addrefed when building removed */
    }

    /* Insert new elements */
    for (int i = 0; i < insert_count; i++) {
        arr->elements[s + i] = args[i + 3];
        SL_ADDREF(arr->elements[s + i]);
    }

    arr->length = (uint32_t)new_len;
    return sl_val_array(removed);
}

static sl_value sl_arr_reverse(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    for (uint32_t i = 0; i < arr->length / 2; i++) {
        sl_value tmp = arr->elements[i];
        arr->elements[i] = arr->elements[arr->length - 1 - i];
        arr->elements[arr->length - 1 - i] = tmp;
    }
    return sl_value_copy(args[0]);
}

/* Sort comparison context for qsort_r */
typedef struct {
    sl_vm *vm;
    sl_value compare_fn;
    bool has_compare;
} sl_sort_ctx;

static int sl_sort_compare(const void *a, const void *b, void *ctx_ptr) {
    sl_sort_ctx *ctx = (sl_sort_ctx *)ctx_ptr;
    sl_value va = *(const sl_value *)a;
    sl_value vb = *(const sl_value *)b;

    if (ctx->has_compare) {
        sl_value cmp_args[2] = { va, vb };
        sl_value result = sl_vm_invoke_function(ctx->vm, ctx->compare_fn, cmp_args, 2);
        double r = sl_to_number(result);
        SL_DELREF(result);
        if (r < 0) return -1;
        if (r > 0) return 1;
        return 0;
    }

    /* Default: compare as strings */
    zend_string *sa = sl_to_js_string(va);
    zend_string *sb = sl_to_js_string(vb);
    int cmp = strcmp(ZSTR_VAL(sa), ZSTR_VAL(sb));
    zend_string_release(sa);
    zend_string_release(sb);
    return cmp;
}

static sl_value sl_arr_sort(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (arr->length <= 1) return sl_value_copy(args[0]);

    sl_sort_ctx ctx;
    ctx.vm = vm;
    ctx.has_compare = (argc > 1 && SL_IS_CALLABLE(args[1]));
    ctx.compare_fn = ctx.has_compare ? args[1] : sl_val_undefined();

    /* Use a simple insertion sort for stability (JS sort must be stable) */
    for (uint32_t i = 1; i < arr->length; i++) {
        sl_value key = arr->elements[i];
        int32_t j = (int32_t)i - 1;
        while (j >= 0 && sl_sort_compare(&arr->elements[j], &key, &ctx) > 0) {
            arr->elements[j + 1] = arr->elements[j];
            j--;
        }
        arr->elements[j + 1] = key;
    }

    return sl_value_copy(args[0]);
}

static sl_value sl_arr_fill(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    sl_value val = argc > 1 ? args[1] : sl_val_undefined();
    zend_long len = (zend_long)arr->length;

    zend_long s = argc > 2 ? (zend_long)sl_to_number(args[2]) : 0;
    if (s < 0) s = len + s;
    if (s < 0) s = 0;

    zend_long e;
    if (argc > 3 && args[3].tag != SL_TAG_UNDEFINED) {
        e = (zend_long)sl_to_number(args[3]);
        if (e < 0) e = len + e;
        if (e < 0) e = 0;
    } else {
        e = len;
    }

    for (zend_long i = s; i < e && i < len; i++) {
        SL_DELREF(arr->elements[i]);
        arr->elements[i] = val;
        SL_ADDREF(val);
    }

    return sl_value_copy(args[0]);
}

static sl_value sl_arr_join(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    zend_string *sep;

    if (argc > 1 && args[1].tag != SL_TAG_UNDEFINED) {
        sep = sl_to_js_string(args[1]);
    } else {
        sep = zend_string_init(",", 1, 0);
    }

    smart_string buf = {0};
    for (uint32_t i = 0; i < arr->length; i++) {
        if (i > 0) {
            smart_string_appendl(&buf, ZSTR_VAL(sep), ZSTR_LEN(sep));
        }
        if (arr->elements[i].tag != SL_TAG_NULL && arr->elements[i].tag != SL_TAG_UNDEFINED) {
            zend_string *s = sl_to_js_string(arr->elements[i]);
            smart_string_appendl(&buf, ZSTR_VAL(s), ZSTR_LEN(s));
            zend_string_release(s);
        }
    }

    zend_string_release(sep);
    smart_string_0(&buf);
    zend_string *result = buf.c ? zend_string_init(buf.c, buf.len, 0) : zend_string_init("", 0, 0);
    smart_string_free(&buf);
    return sl_val_string(result);
}

static sl_value sl_arr_indexOf(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2) return sl_val_int(-1);

    sl_value search = args[1];
    for (uint32_t i = 0; i < arr->length; i++) {
        if (sl_strict_equal(arr->elements[i], search)) {
            return sl_val_int((zend_long)i);
        }
    }
    return sl_val_int(-1);
}

static sl_value sl_arr_includes(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2) return sl_val_bool(0);

    sl_value search = args[1];
    for (uint32_t i = 0; i < arr->length; i++) {
        if (sl_strict_equal(arr->elements[i], search)) {
            return sl_val_bool(1);
        }
        /* Special: includes uses SameValueZero which treats NaN === NaN */
        if (search.tag == SL_TAG_DOUBLE && isnan(search.u.dval) &&
            arr->elements[i].tag == SL_TAG_DOUBLE && isnan(arr->elements[i].u.dval)) {
            return sl_val_bool(1);
        }
    }
    return sl_val_bool(0);
}

static sl_value sl_arr_slice(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    zend_long len = (zend_long)arr->length;

    zend_long s = argc > 1 ? (zend_long)sl_to_number(args[1]) : 0;
    if (s < 0) s = len + s;
    if (s < 0) s = 0;
    if (s > len) s = len;

    zend_long e;
    if (argc > 2 && args[2].tag != SL_TAG_UNDEFINED) {
        e = (zend_long)sl_to_number(args[2]);
        if (e < 0) e = len + e;
        if (e < 0) e = 0;
    } else {
        e = len;
    }
    if (e > len) e = len;

    zend_long count = e - s;
    if (count < 0) count = 0;

    sl_js_array *result = sl_array_new((uint32_t)count);
    for (zend_long i = s; i < e; i++) {
        sl_array_push(result, arr->elements[i]);
    }
    return sl_val_array(result);
}

static sl_value sl_arr_concat(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    sl_js_array *result = sl_array_new(arr->length);

    /* Copy this array's elements */
    for (uint32_t i = 0; i < arr->length; i++) {
        sl_array_push(result, arr->elements[i]);
    }

    /* Append arguments */
    for (int a = 1; a < argc; a++) {
        if (args[a].tag == SL_TAG_ARRAY) {
            sl_js_array *other = args[a].u.arr;
            for (uint32_t i = 0; i < other->length; i++) {
                sl_array_push(result, other->elements[i]);
            }
        } else {
            sl_array_push(result, args[a]);
        }
    }

    return sl_val_array(result);
}

/* Recursive flat helper */
static void sl_flatten(sl_js_array *result, sl_value *elements, uint32_t length, int depth) {
    for (uint32_t i = 0; i < length; i++) {
        if (elements[i].tag == SL_TAG_ARRAY && depth > 0) {
            sl_flatten(result, elements[i].u.arr->elements, elements[i].u.arr->length, depth - 1);
        } else {
            sl_array_push(result, elements[i]);
        }
    }
}

static sl_value sl_arr_flat(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    int depth = argc > 1 ? (int)sl_to_number(args[1]) : 1;

    sl_js_array *result = sl_array_new(arr->length);
    sl_flatten(result, arr->elements, arr->length, depth);
    return sl_val_array(result);
}

static sl_value sl_arr_flatMap(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2 || !SL_IS_CALLABLE(args[1])) {
        return sl_val_array(sl_array_new(0));
    }

    sl_value fn = args[1];
    sl_js_array *result = sl_array_new(arr->length);

    for (uint32_t i = 0; i < arr->length; i++) {
        sl_value cb_args[2] = { arr->elements[i], sl_val_int((zend_long)i) };
        sl_value mapped = sl_vm_invoke_function(vm, fn, cb_args, 2);

        if (mapped.tag == SL_TAG_ARRAY) {
            for (uint32_t j = 0; j < mapped.u.arr->length; j++) {
                sl_array_push(result, mapped.u.arr->elements[j]);
            }
        } else {
            sl_array_push(result, mapped);
        }
        SL_DELREF(mapped);
    }

    return sl_val_array(result);
}

static sl_value sl_arr_at(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    zend_long idx = argc > 1 ? (zend_long)sl_to_number(args[1]) : 0;

    if (idx < 0) idx += (zend_long)arr->length;
    if (idx < 0 || (uint32_t)idx >= arr->length) return sl_val_undefined();

    return sl_value_copy(arr->elements[idx]);
}

static sl_value sl_arr_forEach(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2 || !SL_IS_CALLABLE(args[1])) return sl_val_undefined();

    sl_value fn = args[1];
    for (uint32_t i = 0; i < arr->length; i++) {
        sl_value cb_args[2] = { arr->elements[i], sl_val_int((zend_long)i) };
        sl_value result = sl_vm_invoke_function(vm, fn, cb_args, 2);
        SL_DELREF(result);
        if (vm->has_thrown) return sl_val_undefined();
    }
    return sl_val_undefined();
}

static sl_value sl_arr_map(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2 || !SL_IS_CALLABLE(args[1])) {
        return sl_val_array(sl_array_new(0));
    }

    sl_value fn = args[1];
    sl_js_array *result = sl_array_new(arr->length);

    for (uint32_t i = 0; i < arr->length; i++) {
        sl_value cb_args[2] = { arr->elements[i], sl_val_int((zend_long)i) };
        sl_value mapped = sl_vm_invoke_function(vm, fn, cb_args, 2);
        sl_array_push(result, mapped);
        SL_DELREF(mapped);
        if (vm->has_thrown) return sl_val_array(result);
    }

    return sl_val_array(result);
}

static sl_value sl_arr_filter(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2 || !SL_IS_CALLABLE(args[1])) {
        return sl_val_array(sl_array_new(0));
    }

    sl_value fn = args[1];
    sl_js_array *result = sl_array_new(arr->length);

    for (uint32_t i = 0; i < arr->length; i++) {
        sl_value cb_args[2] = { arr->elements[i], sl_val_int((zend_long)i) };
        sl_value test = sl_vm_invoke_function(vm, fn, cb_args, 2);
        if (sl_is_truthy(test)) {
            sl_array_push(result, arr->elements[i]);
        }
        SL_DELREF(test);
        if (vm->has_thrown) return sl_val_array(result);
    }

    return sl_val_array(result);
}

static sl_value sl_arr_find(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2 || !SL_IS_CALLABLE(args[1])) return sl_val_undefined();

    sl_value fn = args[1];
    for (uint32_t i = 0; i < arr->length; i++) {
        sl_value cb_args[2] = { arr->elements[i], sl_val_int((zend_long)i) };
        sl_value test = sl_vm_invoke_function(vm, fn, cb_args, 2);
        bool found = sl_is_truthy(test);
        SL_DELREF(test);
        if (found) return sl_value_copy(arr->elements[i]);
        if (vm->has_thrown) return sl_val_undefined();
    }
    return sl_val_undefined();
}

static sl_value sl_arr_findIndex(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2 || !SL_IS_CALLABLE(args[1])) return sl_val_int(-1);

    sl_value fn = args[1];
    for (uint32_t i = 0; i < arr->length; i++) {
        sl_value cb_args[2] = { arr->elements[i], sl_val_int((zend_long)i) };
        sl_value test = sl_vm_invoke_function(vm, fn, cb_args, 2);
        bool found = sl_is_truthy(test);
        SL_DELREF(test);
        if (found) return sl_val_int((zend_long)i);
        if (vm->has_thrown) return sl_val_int(-1);
    }
    return sl_val_int(-1);
}

static sl_value sl_arr_findLast(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2 || !SL_IS_CALLABLE(args[1])) return sl_val_undefined();

    sl_value fn = args[1];
    for (int32_t i = (int32_t)arr->length - 1; i >= 0; i--) {
        sl_value cb_args[2] = { arr->elements[i], sl_val_int((zend_long)i) };
        sl_value test = sl_vm_invoke_function(vm, fn, cb_args, 2);
        bool found = sl_is_truthy(test);
        SL_DELREF(test);
        if (found) return sl_value_copy(arr->elements[i]);
        if (vm->has_thrown) return sl_val_undefined();
    }
    return sl_val_undefined();
}

static sl_value sl_arr_findLastIndex(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2 || !SL_IS_CALLABLE(args[1])) return sl_val_int(-1);

    sl_value fn = args[1];
    for (int32_t i = (int32_t)arr->length - 1; i >= 0; i--) {
        sl_value cb_args[2] = { arr->elements[i], sl_val_int((zend_long)i) };
        sl_value test = sl_vm_invoke_function(vm, fn, cb_args, 2);
        bool found = sl_is_truthy(test);
        SL_DELREF(test);
        if (found) return sl_val_int((zend_long)i);
        if (vm->has_thrown) return sl_val_int(-1);
    }
    return sl_val_int(-1);
}

static sl_value sl_arr_reduce(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2 || !SL_IS_CALLABLE(args[1])) {
        sl_vm_throw_type_error(vm, "Reduce of empty array with no initial value");
        return sl_val_undefined();
    }

    sl_value fn = args[1];
    sl_value acc;
    uint32_t start_idx;

    if (argc > 2 && args[2].tag != SL_TAG_UNDEFINED) {
        acc = sl_value_copy(args[2]);
        start_idx = 0;
    } else {
        if (arr->length == 0) {
            sl_vm_throw_type_error(vm, "Reduce of empty array with no initial value");
            return sl_val_undefined();
        }
        acc = sl_value_copy(arr->elements[0]);
        start_idx = 1;
    }

    for (uint32_t i = start_idx; i < arr->length; i++) {
        sl_value cb_args[3] = { acc, arr->elements[i], sl_val_int((zend_long)i) };
        sl_value new_acc = sl_vm_invoke_function(vm, fn, cb_args, 3);
        SL_DELREF(acc);
        acc = new_acc;
        if (vm->has_thrown) return acc;
    }

    return acc;
}

static sl_value sl_arr_reduceRight(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2 || !SL_IS_CALLABLE(args[1])) {
        sl_vm_throw_type_error(vm, "Reduce of empty array with no initial value");
        return sl_val_undefined();
    }

    sl_value fn = args[1];
    sl_value acc;
    int32_t start_idx;

    if (argc > 2 && args[2].tag != SL_TAG_UNDEFINED) {
        acc = sl_value_copy(args[2]);
        start_idx = (int32_t)arr->length - 1;
    } else {
        if (arr->length == 0) {
            sl_vm_throw_type_error(vm, "Reduce of empty array with no initial value");
            return sl_val_undefined();
        }
        start_idx = (int32_t)arr->length - 1;
        acc = sl_value_copy(arr->elements[start_idx]);
        start_idx--;
    }

    for (int32_t i = start_idx; i >= 0; i--) {
        sl_value cb_args[3] = { acc, arr->elements[i], sl_val_int((zend_long)i) };
        sl_value new_acc = sl_vm_invoke_function(vm, fn, cb_args, 3);
        SL_DELREF(acc);
        acc = new_acc;
        if (vm->has_thrown) return acc;
    }

    return acc;
}

static sl_value sl_arr_every(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2 || !SL_IS_CALLABLE(args[1])) return sl_val_bool(1);

    sl_value fn = args[1];
    for (uint32_t i = 0; i < arr->length; i++) {
        sl_value cb_args[2] = { arr->elements[i], sl_val_int((zend_long)i) };
        sl_value test = sl_vm_invoke_function(vm, fn, cb_args, 2);
        bool passed = sl_is_truthy(test);
        SL_DELREF(test);
        if (!passed) return sl_val_bool(0);
        if (vm->has_thrown) return sl_val_bool(0);
    }
    return sl_val_bool(1);
}

static sl_value sl_arr_some(sl_vm *vm, sl_value *args, int argc) {
    sl_js_array *arr = args[0].u.arr;
    if (argc < 2 || !SL_IS_CALLABLE(args[1])) return sl_val_bool(0);

    sl_value fn = args[1];
    for (uint32_t i = 0; i < arr->length; i++) {
        sl_value cb_args[2] = { arr->elements[i], sl_val_int((zend_long)i) };
        sl_value test = sl_vm_invoke_function(vm, fn, cb_args, 2);
        bool passed = sl_is_truthy(test);
        SL_DELREF(test);
        if (passed) return sl_val_bool(1);
        if (vm->has_thrown) return sl_val_bool(0);
    }
    return sl_val_bool(0);
}

/* ============================================================
 * Method dispatch table
 * ============================================================ */

typedef struct {
    const char *name;
    sl_native_handler handler;
} sl_arr_method_entry;

static const sl_arr_method_entry arr_methods[] = {
    { "push",          sl_arr_push },
    { "pop",           sl_arr_pop },
    { "shift",         sl_arr_shift },
    { "unshift",       sl_arr_unshift },
    { "splice",        sl_arr_splice },
    { "reverse",       sl_arr_reverse },
    { "sort",          sl_arr_sort },
    { "fill",          sl_arr_fill },
    { "join",          sl_arr_join },
    { "indexOf",       sl_arr_indexOf },
    { "includes",      sl_arr_includes },
    { "slice",         sl_arr_slice },
    { "concat",        sl_arr_concat },
    { "flat",          sl_arr_flat },
    { "flatMap",       sl_arr_flatMap },
    { "at",            sl_arr_at },
    { "forEach",       sl_arr_forEach },
    { "map",           sl_arr_map },
    { "filter",        sl_arr_filter },
    { "find",          sl_arr_find },
    { "findIndex",     sl_arr_findIndex },
    { "findLast",      sl_arr_findLast },
    { "findLastIndex", sl_arr_findLastIndex },
    { "reduce",        sl_arr_reduce },
    { "reduceRight",   sl_arr_reduceRight },
    { "every",         sl_arr_every },
    { "some",          sl_arr_some },
    { NULL, NULL }
};

/* ============================================================
 * Public API
 * ============================================================ */

/*
 * sl_array_get_method — returns a bound native function for the named method.
 *
 * The native function's handler expects args[0] to be the bound array.
 * The VM dispatches bound calls by prepending the target array to the arg list.
 */
sl_value sl_array_get_method(sl_vm *vm, sl_js_array *arr, zend_string *method_name) {
    const char *name = ZSTR_VAL(method_name);

    sl_value receiver;
    receiver.tag = SL_TAG_ARRAY;
    receiver.u.arr = arr;

    for (const sl_arr_method_entry *e = arr_methods; e->name != NULL; e++) {
        if (strcmp(name, e->name) == 0) {
            zend_string *fn_name = zend_string_init(e->name, strlen(e->name), 0);
            sl_native_func *fn = sl_native_new_bound(fn_name, e->handler, receiver);
            zend_string_release(fn_name);
            return sl_val_native(fn);
        }
    }

    return sl_val_undefined();
}
