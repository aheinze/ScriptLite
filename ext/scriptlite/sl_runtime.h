#ifndef SL_RUNTIME_H
#define SL_RUNTIME_H

#include "sl_value.h"

/* ---- Refcounted base ---- */
typedef struct _sl_gc_header {
    uint32_t refcount;
} sl_gc_header;

#define SL_GC_ADDREF(p) (++(p)->gc.refcount)
#define SL_GC_DELREF(p) (--(p)->gc.refcount)
#define SL_GC_REFCOUNT(p) ((p)->gc.refcount)

/* ---- JS Array ---- */
struct _sl_js_array {
    sl_gc_header gc;
    sl_value    *elements;
    uint32_t     length;
    uint32_t     capacity;
    HashTable   *properties;  /* named props (index, input on regex match) */
};

sl_js_array *sl_array_new(uint32_t initial_capacity);
sl_js_array *sl_array_new_from(sl_value *elements, uint32_t count);
void sl_array_free(sl_js_array *arr);
void sl_array_push(sl_js_array *arr, sl_value val);
sl_value sl_array_pop(sl_js_array *arr);
void sl_array_ensure_capacity(sl_js_array *arr, uint32_t needed);
sl_value sl_array_get(sl_js_array *arr, sl_vm *vm, sl_value key);
void sl_array_set(sl_js_array *arr, uint32_t index, sl_value val);

/* ---- JS Object ---- */
struct _sl_js_object {
    sl_gc_header gc;
    HashTable   *properties;
    sl_js_object *prototype;
    sl_js_closure *constructor;
};

sl_js_object *sl_object_new(void);
sl_js_object *sl_object_new_with_props(HashTable *props);
void sl_object_free(sl_js_object *obj);
sl_value sl_object_get(sl_js_object *obj, sl_vm *vm, zend_string *key);
void sl_object_set(sl_js_object *obj, zend_string *key, sl_value val);
bool sl_object_has_own(sl_js_object *obj, zend_string *key);
void sl_object_delete(sl_js_object *obj, zend_string *key);

/* ---- JS Closure ---- */
struct _sl_js_closure {
    sl_gc_header gc;
    sl_func_descriptor *descriptor;
    sl_environment     *captured_env;
    HashTable          *properties; /* function-as-object props (e.g. prototype) */
};

sl_js_closure *sl_closure_new(sl_func_descriptor *desc, sl_environment *env);
void sl_closure_free(sl_js_closure *c);
sl_js_object *sl_closure_get_prototype(sl_js_closure *closure, bool create_if_missing);

/* ---- Native Function ---- */
typedef sl_value (*sl_native_handler)(sl_vm *vm, sl_value *args, int argc);

struct _sl_native_func {
    sl_gc_header gc;
    zend_string  *name;
    sl_native_handler handler;   /* C handler, or NULL for php_callable */
    zval          php_callable;  /* If handler==NULL, call via zend_call_function */
    HashTable    *properties;    /* function-as-object (e.g., Date.now, Math.PI) */
    sl_value      bound_receiver; /* If tag != SL_TAG_UNDEFINED, prepended as args[0] on call */
};

sl_native_func *sl_native_new(zend_string *name, sl_native_handler handler);
sl_native_func *sl_native_new_bound(zend_string *name, sl_native_handler handler, sl_value receiver);
sl_native_func *sl_native_new_php(zval *callable);
void sl_native_free(sl_native_func *f);

/* ---- JS Date ---- */
struct _sl_js_date {
    sl_gc_header gc;
    double timestamp;  /* milliseconds since epoch */
};

sl_js_date *sl_date_new(double timestamp);
void sl_date_free(sl_js_date *d);

/* ---- JS Regex ---- */
struct _sl_js_regex {
    sl_gc_header gc;
    zend_string *pattern;
    zend_string *flags;
    zend_long    last_index;
    uint32_t     pcre_options;
    zend_bool    is_global;
    void        *compiled_code; /* pcre2_code* (kept as void* in header) */
};

sl_js_regex *sl_regex_new(zend_string *pattern, zend_string *flags);
void sl_regex_free(sl_js_regex *r);
bool sl_regex_test(sl_js_regex *r, zend_string *str);
sl_value sl_regex_exec(sl_js_regex *r, zend_string *str, sl_vm *vm);
void *sl_regex_get_compiled_code(sl_js_regex *r);
uint32_t sl_regex_get_options(const sl_js_regex *r);
bool sl_regex_is_global(const sl_js_regex *r);

/* ---- Compiled Bytecode ---- */
typedef enum {
    SL_OP_CONST = 0, SL_OP_POP = 1, SL_OP_DUP = 2,
    SL_OP_ADD = 10, SL_OP_SUB = 11, SL_OP_MUL = 12, SL_OP_DIV = 13,
    SL_OP_MOD = 14, SL_OP_NEGATE = 15, SL_OP_NOT = 16, SL_OP_TYPEOF = 17,
    SL_OP_EXP = 18, SL_OP_TYPEOF_VAR = 19,
    SL_OP_EQ = 20, SL_OP_NEQ = 21, SL_OP_STRICT_EQ = 22, SL_OP_STRICT_NEQ = 23,
    SL_OP_LT = 24, SL_OP_LTE = 25, SL_OP_GT = 26, SL_OP_GTE = 27,
    SL_OP_CONCAT = 28,
    SL_OP_GET_LOCAL = 30, SL_OP_SET_LOCAL = 31, SL_OP_DEFINE_VAR = 32,
    SL_OP_GET_REG = 33, SL_OP_SET_REG = 34,
    SL_OP_BIT_AND = 35, SL_OP_BIT_OR = 36, SL_OP_BIT_XOR = 37,
    SL_OP_BIT_NOT = 38, SL_OP_SHL = 39,
    SL_OP_JUMP = 40, SL_OP_JUMP_IF_FALSE = 41, SL_OP_JUMP_IF_TRUE = 42,
    SL_OP_JUMP_IF_NOT_NULLISH = 43, SL_OP_SHR = 44, SL_OP_USHR = 45,
    SL_OP_DELETE_PROP = 46, SL_OP_HAS_PROP = 47, SL_OP_INSTANCE_OF = 48,
    SL_OP_MAKE_CLOSURE = 50, SL_OP_CALL = 51, SL_OP_RETURN = 52,
    SL_OP_NEW = 53, SL_OP_CALL_SPREAD = 54, SL_OP_NEW_SPREAD = 55,
    SL_OP_CALL_OPT = 56, SL_OP_CALL_SPREAD_OPT = 57,
    SL_OP_SET_CATCH = 60, SL_OP_POP_CATCH = 61, SL_OP_THROW = 62,
    SL_OP_PUSH_SCOPE = 70, SL_OP_POP_SCOPE = 71,
    SL_OP_MAKE_ARRAY = 80, SL_OP_GET_PROPERTY = 81, SL_OP_SET_PROPERTY = 82,
    SL_OP_MAKE_OBJECT = 83, SL_OP_GET_PROPERTY_OPT = 84,
    SL_OP_ARRAY_PUSH = 85, SL_OP_ARRAY_SPREAD = 86,
    SL_OP_HALT = 99,
    SL_OP_MAX = 100  /* for dispatch table sizing */
} sl_opcode;

struct _sl_func_descriptor {
    sl_gc_header gc;
    zend_string  *name;
    uint32_t      op_count;
    uint8_t      *ops;
    int32_t      *opA;
    int32_t      *opB;
    sl_value     *constants;
    uint32_t      const_count;
    zend_string **names;
    uint32_t      name_count;
    zend_string **params;
    int32_t      *param_slots;
    uint32_t      param_count;
    zend_string  *rest_param;
    int32_t       rest_param_slot;
    uint32_t      reg_count;
    zend_bool     needs_call_env;
    int32_t      *local_ic_depth;
};

sl_func_descriptor *sl_func_descriptor_new(void);
void sl_func_descriptor_free(sl_func_descriptor *desc);

struct _sl_compiled_script {
    sl_gc_header gc;
    sl_func_descriptor *main;
};

sl_compiled_script *sl_compiled_script_new(sl_func_descriptor *main);
void sl_compiled_script_free(sl_compiled_script *script);

/* ---- Property access helpers ---- */
sl_value sl_get_property(sl_vm *vm, sl_value target, sl_value key);
sl_value sl_get_property_opt(sl_vm *vm, sl_value target, sl_value key);
void sl_set_property(sl_vm *vm, sl_value target, sl_value key, sl_value val);

#endif /* SL_RUNTIME_H */
