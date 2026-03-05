#include "sl_compiler.h"
#include "sl_ast_reader.h"
#include "sl_runtime.h"
#include "sl_value.h"
#include "ext/spl/spl_exceptions.h"

#include <string.h>

/* ============================================================
 * Forward declarations
 * ============================================================ */
static void sl_compile_function_body(sl_compiler *c, zval *name_zv,
    HashTable *params_ht, HashTable *body_ht, zval *rest_param_zv,
    HashTable *defaults_ht, HashTable *param_destructures_ht);
static void sl_compile_destructuring_pattern(sl_compiler *c,
    uint32_t src_name, bool is_array, HashTable *bindings,
    zval *rest_name_zv, int32_t kind_val);
static void sl_compile_param_destructuring(sl_compiler *c,
    zend_string *param_name, zval *pattern);
static void sl_analyze_locals(sl_compiler *c, HashTable *params_ht,
    HashTable *body_ht, zval *rest_param_zv, HashTable *extra_locals);
static void sl_collect_declarations(HashTable *stmts, HashTable *locals);
static void sl_collect_inner_fn_refs(HashTable *stmts, HashTable *refs);
static void sl_walk_for_inner_fn_refs(zval *node, HashTable *refs);
static void sl_deep_collect_ids(HashTable *stmts, HashTable *refs);
static void sl_deep_collect_stmt(zval *stmt, HashTable *refs);
static void sl_deep_collect_expr(zval *expr, HashTable *refs);
static void sl_collect_binding_names(HashTable *bindings, zval *rest_name_zv,
    HashTable *locals);
static bool sl_block_needs_scope(sl_compiler *c, HashTable *stmts);
static int32_t sl_resolve_var_kind(zval *kind_zv);
static zval *sl_ast_prop_param_destructures(zval *obj);
static bool sl_has_spread(HashTable *args);
static void sl_compile_finalizer_stmts(sl_compiler *c, HashTable *fin_stmts);

/* Statement compilation forward declarations */
static void sl_compile_function_decl_hoist(sl_compiler *c, zval *decl);
static void sl_compile_var_declaration(sl_compiler *c, zval *decl);
static void sl_compile_var_declaration_list(sl_compiler *c, zval *list);
static void sl_compile_block(sl_compiler *c, zval *block);
static void sl_compile_if(sl_compiler *c, zval *stmt);
static void sl_compile_while(sl_compiler *c, zval *stmt);
static void sl_compile_do_while(sl_compiler *c, zval *stmt);
static void sl_compile_for(sl_compiler *c, zval *stmt);
static void sl_compile_for_of(sl_compiler *c, zval *stmt);
static void sl_compile_for_in(sl_compiler *c, zval *stmt);
static void sl_compile_switch(sl_compiler *c, zval *stmt);
static void sl_compile_try_catch(sl_compiler *c, zval *stmt);
static void sl_compile_destructuring(sl_compiler *c, zval *decl);

/* Expression compilation forward declarations */
static void sl_compile_binary(sl_compiler *c, zval *expr);
static void sl_compile_unary(sl_compiler *c, zval *expr);
static void sl_compile_update(sl_compiler *c, zval *expr);
static void sl_compile_conditional(sl_compiler *c, zval *expr);
static void sl_compile_typeof(sl_compiler *c, zval *expr);
static void sl_compile_logical(sl_compiler *c, zval *expr);
static void sl_compile_assign(sl_compiler *c, zval *expr);
static void sl_compile_call(sl_compiler *c, zval *expr);
static void sl_compile_new(sl_compiler *c, zval *expr);
static void sl_compile_member_expr(sl_compiler *c, zval *expr);
static void sl_compile_member_assign(sl_compiler *c, zval *expr);
static void sl_compile_array_literal(sl_compiler *c, zval *expr);
static void sl_compile_object_literal(sl_compiler *c, zval *expr);
static void sl_compile_regex(sl_compiler *c, zval *expr);
static void sl_compile_template_literal(sl_compiler *c, zval *expr);
static void sl_compile_delete(sl_compiler *c, zval *expr);
static void sl_compile_sequence(sl_compiler *c, zval *expr);
static void sl_compile_function_expr(sl_compiler *c, zval *expr);

/* ============================================================
 * Internal helpers: grow buffers
 * ============================================================ */
#define SL_INITIAL_OP_CAPACITY   64
#define SL_INITIAL_CONST_CAPACITY 16
#define SL_INITIAL_NAME_CAPACITY  16
#define SL_INITIAL_PARAM_CAPACITY  4
#define SL_INITIAL_LOOP_CAPACITY   4
#define SL_INITIAL_BREAK_CAPACITY  4
#define SL_INITIAL_FINALLY_CAPACITY 4

static void sl_ensure_op_capacity(sl_compiler *c, uint32_t needed) {
    if (needed <= c->op_capacity) return;
    uint32_t new_cap = c->op_capacity;
    while (new_cap < needed) new_cap *= 2;
    c->ops = erealloc(c->ops, new_cap * sizeof(uint8_t));
    c->opA = erealloc(c->opA, new_cap * sizeof(int32_t));
    c->opB = erealloc(c->opB, new_cap * sizeof(int32_t));
    c->op_capacity = new_cap;
}

static void sl_ensure_const_capacity(sl_compiler *c, uint32_t needed) {
    if (needed <= c->const_capacity) return;
    uint32_t new_cap = c->const_capacity;
    while (new_cap < needed) new_cap *= 2;
    c->constants = erealloc(c->constants, new_cap * sizeof(sl_value));
    c->const_capacity = new_cap;
}

static void sl_ensure_name_capacity(sl_compiler *c, uint32_t needed) {
    if (needed <= c->name_capacity) return;
    uint32_t new_cap = c->name_capacity;
    while (new_cap < needed) new_cap *= 2;
    c->names = erealloc(c->names, new_cap * sizeof(zend_string *));
    c->name_capacity = new_cap;
}

static void sl_ensure_param_capacity(sl_compiler *c, uint32_t needed) {
    if (needed <= c->param_capacity) return;
    uint32_t new_cap = c->param_capacity;
    while (new_cap < needed) new_cap *= 2;
    c->params = erealloc(c->params, new_cap * sizeof(zend_string *));
    c->param_slots = erealloc(c->param_slots, new_cap * sizeof(int32_t));
    c->param_capacity = new_cap;
}

/* ============================================================
 * Loop context helpers
 * ============================================================ */
static void sl_push_loop(sl_compiler *c) {
    if (c->loop_depth >= c->loop_capacity) {
        uint32_t new_cap = c->loop_capacity * 2;
        c->loop_stack = erealloc(c->loop_stack,
            new_cap * sizeof(struct _sl_loop_ctx));
        c->loop_capacity = new_cap;
    }
    struct _sl_loop_ctx *lp = &c->loop_stack[c->loop_depth++];
    lp->break_patches = emalloc(SL_INITIAL_BREAK_CAPACITY * sizeof(uint32_t));
    lp->break_count = 0;
    lp->break_capacity = SL_INITIAL_BREAK_CAPACITY;
    lp->continue_patches = emalloc(SL_INITIAL_BREAK_CAPACITY * sizeof(uint32_t));
    lp->continue_count = 0;
    lp->continue_capacity = SL_INITIAL_BREAK_CAPACITY;
    lp->is_switch = false;
}

static int32_t sl_find_break_target_depth(const sl_compiler *c) {
    for (int32_t i = (int32_t)c->loop_depth - 1; i >= 0; i--) {
        return i; /* break targets nearest loop/switch */
    }
    return -1;
}

static int32_t sl_find_continue_target_depth(const sl_compiler *c) {
    for (int32_t i = (int32_t)c->loop_depth - 1; i >= 0; i--) {
        if (!c->loop_stack[i].is_switch) {
            return i; /* continue skips switch frames */
        }
    }
    return -1;
}

static void sl_add_break_patch_at(sl_compiler *c, int32_t target_depth, uint32_t addr) {
    if (target_depth < 0 || target_depth >= (int32_t)c->loop_depth) {
        return;
    }
    struct _sl_loop_ctx *lp = &c->loop_stack[target_depth];
    if (lp->break_count >= lp->break_capacity) {
        lp->break_capacity *= 2;
        lp->break_patches = erealloc(lp->break_patches,
            lp->break_capacity * sizeof(uint32_t));
    }
    lp->break_patches[lp->break_count++] = addr;
}

static void sl_add_continue_patch_at(sl_compiler *c, int32_t target_depth, uint32_t addr) {
    if (target_depth < 0 || target_depth >= (int32_t)c->loop_depth) {
        return;
    }
    struct _sl_loop_ctx *lp = &c->loop_stack[target_depth];
    if (lp->continue_count >= lp->continue_capacity) {
        lp->continue_capacity *= 2;
        lp->continue_patches = erealloc(lp->continue_patches,
            lp->continue_capacity * sizeof(uint32_t));
    }
    lp->continue_patches[lp->continue_count++] = addr;
}

static void sl_patch_continues(sl_compiler *c, int32_t target) {
    struct _sl_loop_ctx *lp = &c->loop_stack[c->loop_depth - 1];
    for (uint32_t i = 0; i < lp->continue_count; i++) {
        c->opA[lp->continue_patches[i]] = target;
    }
}

static void sl_patch_breaks(sl_compiler *c) {
    struct _sl_loop_ctx *lp = &c->loop_stack[--c->loop_depth];
    int32_t target = (int32_t)c->op_count;
    for (uint32_t i = 0; i < lp->break_count; i++) {
        c->opA[lp->break_patches[i]] = target;
    }
    efree(lp->break_patches);
    efree(lp->continue_patches);
}

static void sl_push_finally_ctx(sl_compiler *c, HashTable *fin_stmts) {
    if (c->finally_depth >= c->finally_capacity) {
        uint32_t new_cap = c->finally_capacity * 2;
        c->finally_stack = erealloc(c->finally_stack,
            new_cap * sizeof(struct _sl_finally_ctx));
        c->finally_capacity = new_cap;
    }
    struct _sl_finally_ctx *ctx = &c->finally_stack[c->finally_depth++];
    ctx->statements = fin_stmts;
    ctx->loop_depth_at_entry = c->loop_depth;
}

static void sl_pop_finally_ctx(sl_compiler *c) {
    if (c->finally_depth > 0) {
        c->finally_depth--;
    }
}

static void sl_emit_pending_finalizers_for_target(sl_compiler *c, int32_t target_depth) {
    if (c->in_finalizer_depth > 0 || c->finally_depth == 0) {
        return;
    }

    for (int32_t i = (int32_t)c->finally_depth - 1; i >= 0; i--) {
        if (target_depth < (int32_t)c->finally_stack[i].loop_depth_at_entry) {
            sl_compile_finalizer_stmts(c, c->finally_stack[i].statements);
        }
    }
}

/* ============================================================
 * Temp variable counter (per-compiler instance)
 * ============================================================ */
static uint32_t sl_temp_counter = 0;

static zend_string *sl_make_temp_name(const char *prefix) {
    char buf[64];
    snprintf(buf, sizeof(buf), "%s%u", prefix, sl_temp_counter++);
    return zend_string_init(buf, strlen(buf), 0);
}

/* ============================================================
 * VarKind resolution: read a VarKind enum object -> int
 * ============================================================ */
static int32_t sl_resolve_var_kind(zval *kind_zv) {
    if (!kind_zv || Z_TYPE_P(kind_zv) != IS_OBJECT) return 0;
    /* VarKind is a string-backed enum; read ->value */
    zval *val = sl_ast_prop(kind_zv, SL_G(str_value));
    if (val && Z_TYPE_P(val) == IS_STRING) {
        zend_string *s = Z_STR_P(val);
        if (zend_string_equals_literal(s, "let")) return 1;
        if (zend_string_equals_literal(s, "const")) return 2;
    }
    return 0; /* var */
}

/* Check if a VarKind zval is 'var' */
static bool sl_kind_is_var(zval *kind_zv) {
    return sl_resolve_var_kind(kind_zv) == 0;
}

/* ============================================================
 * Check if args array has any SpreadElement
 * ============================================================ */
static bool sl_has_spread(HashTable *args) {
    zval *el;
    if (!args) return false;
    ZEND_HASH_FOREACH_VAL(args, el) {
        if (sl_ast_is(el, SL_G(ast_cache).ce_spread_element)) return true;
    } ZEND_HASH_FOREACH_END();
    return false;
}

static void sl_compile_finalizer_stmts(sl_compiler *c, HashTable *fin_stmts) {
    if (!fin_stmts) return;

    c->in_finalizer_depth++;
    zval *s;
    ZEND_HASH_FOREACH_VAL(fin_stmts, s) {
        sl_compile_stmt(c, s);
    } ZEND_HASH_FOREACH_END();
    c->in_finalizer_depth--;
}

/* ============================================================
 * Compiler Init / Destroy
 * ============================================================ */
void sl_compiler_init(sl_compiler *c) {
    memset(c, 0, sizeof(sl_compiler));

    c->op_capacity = SL_INITIAL_OP_CAPACITY;
    c->ops = emalloc(c->op_capacity * sizeof(uint8_t));
    c->opA = emalloc(c->op_capacity * sizeof(int32_t));
    c->opB = emalloc(c->op_capacity * sizeof(int32_t));

    c->const_capacity = SL_INITIAL_CONST_CAPACITY;
    c->constants = emalloc(c->const_capacity * sizeof(sl_value));
    ALLOC_HASHTABLE(c->const_map);
    zend_hash_init(c->const_map, 16, NULL, NULL, 0);

    c->name_capacity = SL_INITIAL_NAME_CAPACITY;
    c->names = emalloc(c->name_capacity * sizeof(zend_string *));
    ALLOC_HASHTABLE(c->name_map);
    zend_hash_init(c->name_map, 16, NULL, NULL, 0);

    ALLOC_HASHTABLE(c->local_vars);
    zend_hash_init(c->local_vars, 16, NULL, NULL, 0);
    ALLOC_HASHTABLE(c->captured_vars);
    zend_hash_init(c->captured_vars, 16, NULL, NULL, 0);

    c->param_capacity = SL_INITIAL_PARAM_CAPACITY;
    c->params = emalloc(c->param_capacity * sizeof(zend_string *));
    c->param_slots = emalloc(c->param_capacity * sizeof(int32_t));

    c->loop_capacity = SL_INITIAL_LOOP_CAPACITY;
    c->loop_stack = emalloc(c->loop_capacity * sizeof(struct _sl_loop_ctx));

    c->finally_capacity = SL_INITIAL_FINALLY_CAPACITY;
    c->finally_stack = emalloc(c->finally_capacity * sizeof(struct _sl_finally_ctx));
}

void sl_compiler_destroy(sl_compiler *c) {
    if (c->ops) efree(c->ops);
    if (c->opA) efree(c->opA);
    if (c->opB) efree(c->opB);

    if (c->constants) {
        for (uint32_t i = 0; i < c->const_count; i++) {
            SL_DELREF(c->constants[i]);
        }
        efree(c->constants);
    }
    if (c->const_map) {
        zend_hash_destroy(c->const_map);
        FREE_HASHTABLE(c->const_map);
    }

    if (c->names) {
        for (uint32_t i = 0; i < c->name_count; i++) {
            zend_string_release(c->names[i]);
        }
        efree(c->names);
    }
    if (c->name_map) {
        zend_hash_destroy(c->name_map);
        FREE_HASHTABLE(c->name_map);
    }

    if (c->local_vars) {
        zend_hash_destroy(c->local_vars);
        FREE_HASHTABLE(c->local_vars);
    }
    if (c->captured_vars) {
        zend_hash_destroy(c->captured_vars);
        FREE_HASHTABLE(c->captured_vars);
    }

    if (c->params) efree(c->params);
    if (c->param_slots) efree(c->param_slots);
    if (c->rest_param) zend_string_release(c->rest_param);
    if (c->func_name) zend_string_release(c->func_name);

    /* Free any remaining loop contexts */
    for (uint32_t i = 0; i < c->loop_depth; i++) {
        efree(c->loop_stack[i].break_patches);
        efree(c->loop_stack[i].continue_patches);
    }
    if (c->loop_stack) efree(c->loop_stack);
    if (c->finally_stack) efree(c->finally_stack);
}

/* ============================================================
 * Emission Helpers
 * ============================================================ */
uint32_t sl_emit(sl_compiler *c, uint8_t op, int32_t a, int32_t b) {
    sl_ensure_op_capacity(c, c->op_count + 1);
    uint32_t idx = c->op_count++;
    c->ops[idx] = op;
    c->opA[idx] = a;
    c->opB[idx] = b;
    return idx;
}

static uint32_t sl_emit_jump(sl_compiler *c, uint8_t op) {
    return sl_emit(c, op, 0xFFFF, 0);
}

void sl_patch_jump(sl_compiler *c, uint32_t addr, int32_t target) {
    c->opA[addr] = target;
}

/* Patch jump to current IP */
static void sl_patch_jump_here(sl_compiler *c, uint32_t addr) {
    c->opA[addr] = (int32_t)c->op_count;
}

uint32_t sl_emit_const(sl_compiler *c, sl_value val) {
    /* Try dedup for non-closure values */
    if (val.tag != SL_TAG_CLOSURE) {
        char key_buf[256];
        int key_len = 0;

        switch (val.tag) {
            case SL_TAG_UNDEFINED:
                key_len = snprintf(key_buf, sizeof(key_buf), "u:");
                break;
            case SL_TAG_NULL:
                key_len = snprintf(key_buf, sizeof(key_buf), "n:");
                break;
            case SL_TAG_BOOL:
                key_len = snprintf(key_buf, sizeof(key_buf), "b:%d",
                    val.u.bval ? 1 : 0);
                break;
            case SL_TAG_INT:
                key_len = snprintf(key_buf, sizeof(key_buf), "i:%ld",
                    (long)val.u.ival);
                break;
            case SL_TAG_DOUBLE:
                key_len = snprintf(key_buf, sizeof(key_buf), "d:%.17g",
                    val.u.dval);
                break;
            case SL_TAG_STRING:
                key_len = snprintf(key_buf, sizeof(key_buf), "s:%.*s",
                    (int)(sizeof(key_buf) - 3 < ZSTR_LEN(val.u.str)
                        ? sizeof(key_buf) - 3 : ZSTR_LEN(val.u.str)),
                    ZSTR_VAL(val.u.str));
                break;
            default:
                key_len = 0; /* no dedup for complex types */
                break;
        }

        if (key_len > 0) {
            zend_string *key = zend_string_init(key_buf, key_len, 0);
            zval *existing = zend_hash_find(c->const_map, key);
            if (existing) {
                uint32_t idx = (uint32_t)Z_LVAL_P(existing);
                zend_string_release(key);
                return sl_emit(c, SL_OP_CONST, (int32_t)idx, 0);
            }
            sl_ensure_const_capacity(c, c->const_count + 1);
            uint32_t idx = c->const_count++;
            c->constants[idx] = val;
            SL_ADDREF(val);

            zval idx_zv;
            ZVAL_LONG(&idx_zv, idx);
            zend_hash_add(c->const_map, key, &idx_zv);
            zend_string_release(key);
            return sl_emit(c, SL_OP_CONST, (int32_t)idx, 0);
        }
    }

    /* No dedup: closures, regex, etc. */
    sl_ensure_const_capacity(c, c->const_count + 1);
    uint32_t idx = c->const_count++;
    c->constants[idx] = val;
    SL_ADDREF(val);
    return sl_emit(c, SL_OP_CONST, (int32_t)idx, 0);
}

/* Add constant without emitting CONST opcode, return index */
static uint32_t sl_add_const(sl_compiler *c, sl_value val) {
    /* Try dedup for simple types */
    char key_buf[256];
    int key_len = 0;

    switch (val.tag) {
        case SL_TAG_UNDEFINED:
            key_len = snprintf(key_buf, sizeof(key_buf), "u:");
            break;
        case SL_TAG_NULL:
            key_len = snprintf(key_buf, sizeof(key_buf), "n:");
            break;
        case SL_TAG_BOOL:
            key_len = snprintf(key_buf, sizeof(key_buf), "b:%d",
                val.u.bval ? 1 : 0);
            break;
        case SL_TAG_INT:
            key_len = snprintf(key_buf, sizeof(key_buf), "i:%ld",
                (long)val.u.ival);
            break;
        case SL_TAG_DOUBLE:
            key_len = snprintf(key_buf, sizeof(key_buf), "d:%.17g",
                val.u.dval);
            break;
        case SL_TAG_STRING:
            key_len = snprintf(key_buf, sizeof(key_buf), "s:%.*s",
                (int)(sizeof(key_buf) - 3 < ZSTR_LEN(val.u.str)
                    ? sizeof(key_buf) - 3 : ZSTR_LEN(val.u.str)),
                ZSTR_VAL(val.u.str));
            break;
        default:
            key_len = 0;
            break;
    }

    if (key_len > 0) {
        zend_string *key = zend_string_init(key_buf, key_len, 0);
        zval *existing = zend_hash_find(c->const_map, key);
        if (existing) {
            uint32_t idx = (uint32_t)Z_LVAL_P(existing);
            zend_string_release(key);
            return idx;
        }
        sl_ensure_const_capacity(c, c->const_count + 1);
        uint32_t idx = c->const_count++;
        c->constants[idx] = val;
        SL_ADDREF(val);

        zval idx_zv;
        ZVAL_LONG(&idx_zv, idx);
        zend_hash_add(c->const_map, key, &idx_zv);
        zend_string_release(key);
        return idx;
    }

    /* No dedup */
    sl_ensure_const_capacity(c, c->const_count + 1);
    uint32_t idx = c->const_count++;
    c->constants[idx] = val;
    SL_ADDREF(val);
    return idx;
}

uint32_t sl_emit_name(sl_compiler *c, zend_string *name) {
    zval *existing = zend_hash_find(c->name_map, name);
    if (existing) {
        return (uint32_t)Z_LVAL_P(existing);
    }
    sl_ensure_name_capacity(c, c->name_count + 1);
    uint32_t idx = c->name_count++;
    c->names[idx] = zend_string_copy(name);

    zval idx_zv;
    ZVAL_LONG(&idx_zv, idx);
    zend_hash_add(c->name_map, name, &idx_zv);
    return idx;
}

/* ============================================================
 * Register allocation helpers
 * ============================================================ */
static int32_t sl_get_reg(sl_compiler *c, zend_string *name) {
    zval *slot = zend_hash_find(c->local_vars, name);
    if (slot) return (int32_t)Z_LVAL_P(slot);
    return -1;
}

static bool sl_is_reg_allocated(sl_compiler *c, zend_string *name) {
    return zend_hash_exists(c->local_vars, name) != 0;
}

static void sl_alloc_reg(sl_compiler *c, zend_string *name) {
    zval slot_zv;
    ZVAL_LONG(&slot_zv, c->reg_count++);
    zend_hash_update(c->local_vars, name, &slot_zv);
}

/* ============================================================
 * Build descriptor from compiler state
 * ============================================================ */
sl_func_descriptor *sl_compiler_build_descriptor(sl_compiler *c) {
    sl_func_descriptor *desc = sl_func_descriptor_new();

    if (c->func_name) {
        desc->name = zend_string_copy(c->func_name);
    }

    desc->op_count = c->op_count;
    desc->ops = emalloc(c->op_count * sizeof(uint8_t));
    memcpy(desc->ops, c->ops, c->op_count * sizeof(uint8_t));
    desc->opA = emalloc(c->op_count * sizeof(int32_t));
    memcpy(desc->opA, c->opA, c->op_count * sizeof(int32_t));
    desc->opB = emalloc(c->op_count * sizeof(int32_t));
    memcpy(desc->opB, c->opB, c->op_count * sizeof(int32_t));

    desc->const_count = c->const_count;
    if (c->const_count > 0) {
        desc->constants = emalloc(c->const_count * sizeof(sl_value));
        for (uint32_t i = 0; i < c->const_count; i++) {
            desc->constants[i] = c->constants[i];
            SL_ADDREF(desc->constants[i]);
        }
    }

    desc->name_count = c->name_count;
    if (c->name_count > 0) {
        desc->names = emalloc(c->name_count * sizeof(zend_string *));
        for (uint32_t i = 0; i < c->name_count; i++) {
            desc->names[i] = zend_string_copy(c->names[i]);
        }
    }

    desc->param_count = c->param_count;
    if (c->param_count > 0) {
        desc->params = emalloc(c->param_count * sizeof(zend_string *));
        desc->param_slots = emalloc(c->param_count * sizeof(int32_t));
        for (uint32_t i = 0; i < c->param_count; i++) {
            desc->params[i] = zend_string_copy(c->params[i]);
            desc->param_slots[i] = c->param_slots[i];
        }
    }

    if (c->rest_param) {
        desc->rest_param = zend_string_copy(c->rest_param);
        desc->rest_param_slot = c->rest_param_slot;
    }

    desc->reg_count = c->reg_count;

    return desc;
}

/* ============================================================
 * Emit identifier get/set helpers
 * ============================================================ */
static void sl_emit_get_var(sl_compiler *c, zend_string *name) {
    int32_t reg = sl_get_reg(c, name);
    if (reg >= 0) {
        sl_emit(c, SL_OP_GET_REG, reg, 0);
    } else {
        sl_emit(c, SL_OP_GET_LOCAL, (int32_t)sl_emit_name(c, name), 0);
    }
}

static void sl_emit_set_var(sl_compiler *c, zend_string *name) {
    int32_t reg = sl_get_reg(c, name);
    if (reg >= 0) {
        sl_emit(c, SL_OP_SET_REG, reg, 0);
    } else {
        sl_emit(c, SL_OP_SET_LOCAL, (int32_t)sl_emit_name(c, name), 0);
    }
}

/* ============================================================
 * Operator string -> opcode mapping
 * ============================================================ */
static uint8_t sl_binary_op(zend_string *op) {
    const char *s = ZSTR_VAL(op);
    size_t len = ZSTR_LEN(op);

    if (len == 1) {
        switch (s[0]) {
            case '+': return SL_OP_ADD;
            case '-': return SL_OP_SUB;
            case '*': return SL_OP_MUL;
            case '/': return SL_OP_DIV;
            case '%': return SL_OP_MOD;
            case '&': return SL_OP_BIT_AND;
            case '|': return SL_OP_BIT_OR;
            case '^': return SL_OP_BIT_XOR;
            case '<': return SL_OP_LT;
            case '>': return SL_OP_GT;
        }
    } else if (len == 2) {
        if (s[0] == '*' && s[1] == '*') return SL_OP_EXP;
        if (s[0] == '=' && s[1] == '=') return SL_OP_EQ;
        if (s[0] == '!' && s[1] == '=') return SL_OP_NEQ;
        if (s[0] == '<' && s[1] == '=') return SL_OP_LTE;
        if (s[0] == '>' && s[1] == '=') return SL_OP_GTE;
        if (s[0] == '<' && s[1] == '<') return SL_OP_SHL;
        if (s[0] == '>' && s[1] == '>') return SL_OP_SHR;
        if (s[0] == 'i' && s[1] == 'n') return SL_OP_HAS_PROP;
    } else if (len == 3) {
        if (memcmp(s, "===", 3) == 0) return SL_OP_STRICT_EQ;
        if (memcmp(s, "!==", 3) == 0) return SL_OP_STRICT_NEQ;
        if (memcmp(s, ">>>", 3) == 0) return SL_OP_USHR;
    } else if (len == 10 && memcmp(s, "instanceof", 10) == 0) {
        return SL_OP_INSTANCE_OF;
    }

    return SL_OP_ADD; /* fallback */
}

static uint8_t sl_compound_assign_op(zend_string *op) {
    const char *s = ZSTR_VAL(op);
    size_t len = ZSTR_LEN(op);

    if (len == 2) {
        if (s[0] == '+') return SL_OP_ADD;
        if (s[0] == '-') return SL_OP_SUB;
        if (s[0] == '*') return SL_OP_MUL;
        if (s[0] == '/') return SL_OP_DIV;
        if (s[0] == '%') return SL_OP_MOD;
        if (s[0] == '&') return SL_OP_BIT_AND;
        if (s[0] == '|') return SL_OP_BIT_OR;
        if (s[0] == '^') return SL_OP_BIT_XOR;
    } else if (len == 3) {
        if (memcmp(s, "**=", 3) == 0) return SL_OP_EXP;
        if (memcmp(s, "<<=", 3) == 0) return SL_OP_SHL;
        if (memcmp(s, ">>=", 3) == 0) return SL_OP_SHR;
    } else if (len == 4 && memcmp(s, ">>>=", 4) == 0) {
        return SL_OP_USHR;
    }

    return SL_OP_ADD;
}

/* ============================================================
 * Program compilation (top-level entry)
 * ============================================================ */
sl_compiled_script *sl_compiler_compile(sl_compiler *c, zval *program) {
    sl_ast_cache_init();

    HashTable *body = sl_ast_prop_array(program, SL_G(str_body));
    if (!body) {
        /* Empty program */
        sl_emit(c, SL_OP_HALT, 0, 0);
        c->func_name = zend_string_init("<main>", 6, 0);
        sl_func_descriptor *main_desc = sl_compiler_build_descriptor(c);
        return sl_compiled_script_new(main_desc);
    }

    /* Analyze locals for register allocation */
    sl_analyze_locals(c, NULL, body, NULL, NULL);

    /* Hoist function declarations */
    zval *stmt;
    ZEND_HASH_FOREACH_VAL(body, stmt) {
        if (sl_ast_is(stmt, SL_G(ast_cache).ce_function_decl)) {
            sl_compile_function_decl_hoist(c, stmt);
        }
    } ZEND_HASH_FOREACH_END();

    /* Compile non-hoisted statements */
    uint32_t total = zend_hash_num_elements(body);
    uint32_t idx = 0;
    ZEND_HASH_FOREACH_VAL(body, stmt) {
        idx++;
        if (sl_ast_is(stmt, SL_G(ast_cache).ce_function_decl)) {
            continue;
        }
        /* Last statement: if ExpressionStmt, leave value on stack */
        if (idx == total
            && sl_ast_is(stmt, SL_G(ast_cache).ce_expression_stmt)) {
            zval *expr = sl_ast_prop(stmt, SL_G(str_expression));
            sl_compile_expr(c, expr);
        } else {
            sl_compile_stmt(c, stmt);
        }
    } ZEND_HASH_FOREACH_END();

    sl_emit(c, SL_OP_HALT, 0, 0);

    c->func_name = zend_string_init("<main>", 6, 0);
    sl_func_descriptor *main_desc = sl_compiler_build_descriptor(c);
    return sl_compiled_script_new(main_desc);
}

void sl_compile_program(sl_compiler *c, zval *program) {
    /* Delegate to sl_compiler_compile; this is the external entry point */
    /* (Not typically used standalone, but required by the header) */
    HashTable *body = sl_ast_prop_array(program, SL_G(str_body));
    if (!body) return;

    zval *stmt;
    ZEND_HASH_FOREACH_VAL(body, stmt) {
        sl_compile_stmt(c, stmt);
    } ZEND_HASH_FOREACH_END();
}

/* ============================================================
 * Function declaration hoisting
 * ============================================================ */
static void sl_compile_function_decl_hoist(sl_compiler *c, zval *decl) {
    zend_string *name = sl_ast_prop_str(decl, SL_G(str_name));
    HashTable *params_ht = sl_ast_prop_array(decl, SL_G(str_params));
    HashTable *body_ht = sl_ast_prop_array(decl, SL_G(str_body));
    zval *rest_param_zv = sl_ast_prop(decl, SL_G(str_restParam));
    HashTable *defaults_ht = sl_ast_prop_array(decl, SL_G(str_defaults));
    zval *pd_zv = sl_ast_prop_param_destructures(decl);
    HashTable *param_destr = (pd_zv && Z_TYPE_P(pd_zv) == IS_ARRAY)
        ? Z_ARRVAL_P(pd_zv) : NULL;

    /* Build a child compiler for the function body */
    sl_compiler child;
    sl_compiler_init(&child);

    zval name_zv;
    if (name) {
        ZVAL_STR_COPY(&name_zv, name);
    } else {
        ZVAL_NULL(&name_zv);
    }

    sl_compile_function_body(&child, &name_zv, params_ht, body_ht,
        rest_param_zv, defaults_ht, param_destr);

    sl_func_descriptor *desc = sl_compiler_build_descriptor(&child);
    sl_compiler_destroy(&child);

    if (Z_TYPE(name_zv) == IS_STRING) {
        zval_ptr_dtor(&name_zv);
    }

    /* Store descriptor in constant pool, emit MakeClosure */
    sl_value desc_val = sl_val_closure(sl_closure_new(desc, NULL));
    /* Actually, function descriptors go into constant pool directly.
     * The VM expects the constant to be a closure whose descriptor it extracts.
     * However, looking at the PHP compiler: it stores the FunctionDescriptor
     * object. In C, we wrap in a closure with NULL env. */
    uint32_t desc_idx = sl_add_const(c, desc_val);
    SL_DELREF(desc_val); /* add_const already addref'd */
    sl_emit(c, SL_OP_MAKE_CLOSURE, (int32_t)desc_idx, 0);

    /* Store in variable */
    if (name && sl_is_reg_allocated(c, name)) {
        sl_emit(c, SL_OP_SET_REG, sl_get_reg(c, name), 0);
    } else if (name) {
        uint32_t name_idx = sl_emit_name(c, name);
        sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)name_idx, 0);
    }
}

/* ============================================================
 * Statement compilation dispatch
 * ============================================================ */
void sl_compile_stmt(sl_compiler *c, zval *stmt) {
    sl_ast_class_cache *cc = &SL_G(ast_cache);
    zend_class_entry *ce = Z_OBJCE_P(stmt);

    if (ce == cc->ce_expression_stmt) {
        zval *expr = sl_ast_prop(stmt, SL_G(str_expression));
        sl_compile_expr(c, expr);
        sl_emit(c, SL_OP_POP, 0, 0);
    }
    else if (ce == cc->ce_var_declaration) {
        sl_compile_var_declaration(c, stmt);
    }
    else if (ce == cc->ce_function_decl) {
        sl_compile_function_decl_hoist(c, stmt);
    }
    else if (ce == cc->ce_return_stmt) {
        zval *val = sl_ast_prop(stmt, SL_G(str_value));
        if (val && Z_TYPE_P(val) != IS_NULL) {
            sl_compile_expr(c, val);
        } else {
            sl_emit_const(c, sl_val_undefined());
        }
        sl_emit(c, SL_OP_RETURN, 0, 0);
    }
    else if (ce == cc->ce_block_stmt) {
        sl_compile_block(c, stmt);
    }
    else if (ce == cc->ce_if_stmt) {
        sl_compile_if(c, stmt);
    }
    else if (ce == cc->ce_while_stmt) {
        sl_compile_while(c, stmt);
    }
    else if (ce == cc->ce_for_stmt) {
        sl_compile_for(c, stmt);
    }
    else if (ce == cc->ce_for_of_stmt) {
        sl_compile_for_of(c, stmt);
    }
    else if (ce == cc->ce_for_in_stmt) {
        sl_compile_for_in(c, stmt);
    }
    else if (ce == cc->ce_destructuring_decl) {
        sl_compile_destructuring(c, stmt);
    }
    else if (ce == cc->ce_var_declaration_list) {
        sl_compile_var_declaration_list(c, stmt);
    }
    else if (ce == cc->ce_break_stmt) {
        int32_t target_depth = sl_find_break_target_depth(c);
        if (target_depth < 0) {
            zend_throw_exception_ex(spl_ce_RuntimeException, 0,
                "Illegal break statement");
            return;
        }
        sl_emit_pending_finalizers_for_target(c, target_depth);
        sl_add_break_patch_at(c, target_depth, sl_emit_jump(c, SL_OP_JUMP));
    }
    else if (ce == cc->ce_continue_stmt) {
        int32_t target_depth = sl_find_continue_target_depth(c);
        if (target_depth < 0) {
            zend_throw_exception_ex(spl_ce_RuntimeException, 0,
                "Illegal continue statement");
            return;
        }
        sl_emit_pending_finalizers_for_target(c, target_depth);
        sl_add_continue_patch_at(c, target_depth, sl_emit_jump(c, SL_OP_JUMP));
    }
    else if (ce == cc->ce_do_while_stmt) {
        sl_compile_do_while(c, stmt);
    }
    else if (ce == cc->ce_switch_stmt) {
        sl_compile_switch(c, stmt);
    }
    else if (ce == cc->ce_throw_stmt) {
        zval *arg = sl_ast_prop(stmt, SL_G(str_argument));
        sl_compile_expr(c, arg);
        sl_emit(c, SL_OP_THROW, 0, 0);
    }
    else if (ce == cc->ce_try_catch_stmt) {
        sl_compile_try_catch(c, stmt);
    }
}

/* ============================================================
 * Statement compilation: individual node types
 * ============================================================ */
static zval *sl_ast_prop_initializer(zval *obj) {
    zval rv;
    zend_string *s = zend_string_init("initializer", 11, 0);
    zval *v = zend_read_property_ex(Z_OBJCE_P(obj), Z_OBJ_P(obj), s, 1, &rv);
    zend_string_release(s);
    return v;
}

static zval *sl_ast_prop_block(zval *obj) {
    zval rv;
    zend_string *s = zend_string_init("block", 5, 0);
    zval *v = zend_read_property_ex(Z_OBJCE_P(obj), Z_OBJ_P(obj), s, 1, &rv);
    zend_string_release(s);
    return v;
}

static zval *sl_ast_prop_handler(zval *obj) {
    zval rv;
    zend_string *s = zend_string_init("handler", 7, 0);
    zval *v = zend_read_property_ex(Z_OBJCE_P(obj), Z_OBJ_P(obj), s, 1, &rv);
    zend_string_release(s);
    return v;
}

static zval *sl_ast_prop_is_array(zval *obj) {
    zval rv;
    zend_string *s = zend_string_init("isArray", 7, 0);
    zval *v = zend_read_property_ex(Z_OBJCE_P(obj), Z_OBJ_P(obj), s, 1, &rv);
    zend_string_release(s);
    return v;
}

static zval *sl_ast_prop_rest_name(zval *obj) {
    zval rv;
    zend_string *s = zend_string_init("restName", 8, 0);
    zval *v = zend_read_property_ex(Z_OBJCE_P(obj), Z_OBJ_P(obj), s, 1, &rv);
    zend_string_release(s);
    return v;
}

static zval *sl_ast_prop_optional(zval *obj) {
    zval rv;
    zend_string *s = zend_string_init("optional", 8, 0);
    zval *v = zend_read_property_ex(Z_OBJCE_P(obj), Z_OBJ_P(obj), s, 1, &rv);
    zend_string_release(s);
    return v;
}

static zval *sl_ast_prop_optional_chain(zval *obj) {
    zval rv;
    zend_string *s = zend_string_init("optionalChain", 13, 0);
    zval *v = zend_read_property_ex(Z_OBJCE_P(obj), Z_OBJ_P(obj), s, 1, &rv);
    zend_string_release(s);
    return v;
}

static bool sl_ast_is_optional(zval *obj) {
    zval *opt = sl_ast_prop_optional(obj);
    zval *oc = sl_ast_prop_optional_chain(obj);
    return (opt && zend_is_true(opt)) || (oc && zend_is_true(oc));
}

static zval *sl_ast_prop_param_destructures(zval *obj) {
    zval rv;
    zend_string *s = zend_string_init("paramDestructures", 17, 0);
    zval *v = zend_read_property_ex(Z_OBJCE_P(obj), Z_OBJ_P(obj), s, 1, &rv);
    zend_string_release(s);
    return v;
}

static zval *sl_ast_prop_computed_key(zval *obj) {
    zval rv;
    zend_string *s = zend_string_init("computedKey", 11, 0);
    zval *v = zend_read_property_ex(Z_OBJCE_P(obj), Z_OBJ_P(obj), s, 1, &rv);
    zend_string_release(s);
    return v;
}

static void sl_compile_var_declaration(sl_compiler *c, zval *decl) {
    zval *init = sl_ast_prop_initializer(decl);
    if (!init || Z_TYPE_P(init) == IS_NULL) {
        init = NULL;
    }

    if (init && Z_TYPE_P(init) != IS_NULL) {
        sl_compile_expr(c, init);
    } else {
        sl_emit_const(c, sl_val_undefined());
    }

    zend_string *name = sl_ast_prop_str(decl, SL_G(str_name));
    if (!name) return;

    if (sl_is_reg_allocated(c, name)) {
        sl_emit(c, SL_OP_SET_REG, sl_get_reg(c, name), 0);
    } else {
        uint32_t name_idx = sl_emit_name(c, name);
        zval *kind_zv = sl_ast_prop(decl, SL_G(str_kind));
        int32_t kind_val = sl_resolve_var_kind(kind_zv);
        sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)name_idx, kind_val);
    }
}

static void sl_compile_var_declaration_list(sl_compiler *c, zval *list) {
    HashTable *decls = sl_ast_prop_array(list, SL_G(str_declarations));
    if (!decls) return;
    zval *decl;
    ZEND_HASH_FOREACH_VAL(decls, decl) {
        sl_compile_var_declaration(c, decl);
    } ZEND_HASH_FOREACH_END();
}

static void sl_compile_block(sl_compiler *c, zval *block) {
    HashTable *stmts = sl_ast_prop_array(block, SL_G(str_statements));
    if (!stmts) return;

    bool needs_scope = sl_block_needs_scope(c, stmts);
    if (needs_scope) sl_emit(c, SL_OP_PUSH_SCOPE, 0, 0);

    zval *s;
    ZEND_HASH_FOREACH_VAL(stmts, s) {
        sl_compile_stmt(c, s);
    } ZEND_HASH_FOREACH_END();

    if (needs_scope) sl_emit(c, SL_OP_POP_SCOPE, 0, 0);
}

static bool sl_block_needs_scope(sl_compiler *c, HashTable *stmts) {
    zval *s;
    ZEND_HASH_FOREACH_VAL(stmts, s) {
        if (sl_ast_is(s, SL_G(ast_cache).ce_var_declaration)) {
            zval *kind_zv = sl_ast_prop(s, SL_G(str_kind));
            if (!sl_kind_is_var(kind_zv)) {
                zend_string *name = sl_ast_prop_str(s, SL_G(str_name));
                if (name && !sl_is_reg_allocated(c, name)) {
                    return true;
                }
            }
        }
    } ZEND_HASH_FOREACH_END();
    return false;
}

static void sl_compile_if(sl_compiler *c, zval *stmt) {
    zval *cond = sl_ast_prop(stmt, SL_G(str_condition));
    zval *cons = sl_ast_prop(stmt, SL_G(str_consequent));
    zval *alt = sl_ast_prop(stmt, SL_G(str_alternate));

    sl_compile_expr(c, cond);
    uint32_t jump_false = sl_emit_jump(c, SL_OP_JUMP_IF_FALSE);

    sl_compile_stmt(c, cons);

    if (alt && Z_TYPE_P(alt) != IS_NULL) {
        uint32_t jump_over = sl_emit_jump(c, SL_OP_JUMP);
        sl_patch_jump_here(c, jump_false);
        sl_compile_stmt(c, alt);
        sl_patch_jump_here(c, jump_over);
    } else {
        sl_patch_jump_here(c, jump_false);
    }
}

static void sl_compile_while(sl_compiler *c, zval *stmt) {
    zval *cond = sl_ast_prop(stmt, SL_G(str_condition));
    zval *body = sl_ast_prop(stmt, SL_G(str_body));

    uint32_t loop_start = c->op_count;
    sl_push_loop(c);

    sl_compile_expr(c, cond);
    uint32_t exit_jump = sl_emit_jump(c, SL_OP_JUMP_IF_FALSE);

    sl_compile_stmt(c, body);

    sl_patch_continues(c, (int32_t)loop_start);
    sl_emit(c, SL_OP_JUMP, (int32_t)loop_start, 0);
    sl_patch_jump_here(c, exit_jump);
    sl_patch_breaks(c);
}

static void sl_compile_do_while(sl_compiler *c, zval *stmt) {
    zval *cond = sl_ast_prop(stmt, SL_G(str_condition));
    zval *body = sl_ast_prop(stmt, SL_G(str_body));

    uint32_t body_start = c->op_count;
    sl_push_loop(c);

    sl_compile_stmt(c, body);

    uint32_t cond_start = c->op_count;
    sl_patch_continues(c, (int32_t)cond_start);

    sl_compile_expr(c, cond);
    sl_emit(c, SL_OP_JUMP_IF_TRUE, (int32_t)body_start, 0);

    sl_patch_breaks(c);
}

static void sl_compile_for(sl_compiler *c, zval *stmt) {
    zval *init = sl_ast_prop(stmt, SL_G(str_init));
    zval *cond = sl_ast_prop(stmt, SL_G(str_condition));
    zval *update = sl_ast_prop(stmt, SL_G(str_update));
    zval *body = sl_ast_prop(stmt, SL_G(str_body));

    sl_ast_class_cache *cc = &SL_G(ast_cache);

    /* Determine if we need a scope for let/const init */
    bool needs_scope = false;
    if (init && Z_TYPE_P(init) == IS_OBJECT) {
        zend_class_entry *ice = Z_OBJCE_P(init);
        if (ice == cc->ce_var_declaration) {
            zval *kind_zv = sl_ast_prop(init, SL_G(str_kind));
            zend_string *name = sl_ast_prop_str(init, SL_G(str_name));
            if (!sl_kind_is_var(kind_zv) && name
                && !sl_is_reg_allocated(c, name)) {
                needs_scope = true;
            }
        } else if (ice == cc->ce_var_declaration_list) {
            HashTable *decls = sl_ast_prop_array(init, SL_G(str_declarations));
            if (decls) {
                zval *first;
                ZEND_HASH_FOREACH_VAL(decls, first) {
                    zval *kind_zv = sl_ast_prop(first, SL_G(str_kind));
                    if (!sl_kind_is_var(kind_zv)) needs_scope = true;
                    break;
                } ZEND_HASH_FOREACH_END();
            }
        }
    }

    if (needs_scope) sl_emit(c, SL_OP_PUSH_SCOPE, 0, 0);

    /* Init */
    if (init && Z_TYPE_P(init) == IS_OBJECT) {
        zend_class_entry *ice = Z_OBJCE_P(init);
        if (ice == cc->ce_var_declaration) {
            sl_compile_var_declaration(c, init);
        } else if (ice == cc->ce_var_declaration_list) {
            sl_compile_var_declaration_list(c, init);
        } else if (ice == cc->ce_destructuring_decl) {
            sl_compile_destructuring(c, init);
        } else if (ice == cc->ce_expression_stmt) {
            zval *expr = sl_ast_prop(init, SL_G(str_expression));
            sl_compile_expr(c, expr);
            sl_emit(c, SL_OP_POP, 0, 0);
        }
    }

    uint32_t loop_start = c->op_count;
    sl_push_loop(c);

    /* Condition */
    int32_t exit_jump = -1;
    if (cond && Z_TYPE_P(cond) != IS_NULL) {
        sl_compile_expr(c, cond);
        exit_jump = (int32_t)sl_emit_jump(c, SL_OP_JUMP_IF_FALSE);
    }

    /* Body */
    sl_compile_stmt(c, body);

    /* Continue target = update */
    uint32_t update_start = c->op_count;
    sl_patch_continues(c, (int32_t)update_start);

    /* Update */
    if (update && Z_TYPE_P(update) != IS_NULL) {
        sl_compile_expr(c, update);
        sl_emit(c, SL_OP_POP, 0, 0);
    }

    sl_emit(c, SL_OP_JUMP, (int32_t)loop_start, 0);

    if (exit_jump >= 0) {
        sl_patch_jump_here(c, (uint32_t)exit_jump);
    }

    sl_patch_breaks(c);

    if (needs_scope) sl_emit(c, SL_OP_POP_SCOPE, 0, 0);
}

static void sl_compile_for_of(sl_compiler *c, zval *stmt) {
    zval *kind_zv = sl_ast_prop(stmt, SL_G(str_kind));
    zend_string *var_name = sl_ast_prop_str(stmt, SL_G(str_name));
    zval *iterable = sl_ast_prop(stmt, SL_G(str_iterable));
    zval *body = sl_ast_prop(stmt, SL_G(str_body));
    int32_t kind_val = sl_resolve_var_kind(kind_zv);

    bool needs_scope = kind_val != 0; /* not var */
    if (needs_scope) sl_emit(c, SL_OP_PUSH_SCOPE, 0, 0);

    /* Store iterable in temp */
    sl_compile_expr(c, iterable);
    zend_string *arr_tmp = sl_make_temp_name("__forof_arr");
    uint32_t arr_name = sl_emit_name(c, arr_tmp);
    sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)arr_name, 0);

    /* Init index = 0 */
    sl_emit_const(c, sl_val_double(0.0));
    zend_string *idx_tmp = sl_make_temp_name("__forof_idx");
    uint32_t idx_name = sl_emit_name(c, idx_tmp);
    sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)idx_name, 0);

    /* Loop start */
    uint32_t loop_start = c->op_count;
    sl_push_loop(c);

    /* Condition: idx < arr.length */
    sl_emit(c, SL_OP_GET_LOCAL, (int32_t)idx_name, 0);
    sl_emit(c, SL_OP_GET_LOCAL, (int32_t)arr_name, 0);
    sl_emit_const(c, sl_val_string(zend_string_init("length", 6, 0)));
    sl_emit(c, SL_OP_GET_PROPERTY, 0, 0);
    sl_emit(c, SL_OP_LT, 0, 0);
    uint32_t exit_jump = sl_emit_jump(c, SL_OP_JUMP_IF_FALSE);

    /* x = arr[idx] */
    sl_emit(c, SL_OP_GET_LOCAL, (int32_t)arr_name, 0);
    sl_emit(c, SL_OP_GET_LOCAL, (int32_t)idx_name, 0);
    sl_emit(c, SL_OP_GET_PROPERTY, 0, 0);
    if (var_name && sl_is_reg_allocated(c, var_name)) {
        sl_emit(c, SL_OP_SET_REG, sl_get_reg(c, var_name), 0);
    } else if (var_name) {
        uint32_t vn = sl_emit_name(c, var_name);
        sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)vn, kind_val);
    }

    /* Body */
    sl_compile_stmt(c, body);

    /* Continue point */
    uint32_t update_start = c->op_count;
    sl_patch_continues(c, (int32_t)update_start);

    /* idx++ */
    sl_emit(c, SL_OP_GET_LOCAL, (int32_t)idx_name, 0);
    sl_emit_const(c, sl_val_double(1.0));
    sl_emit(c, SL_OP_ADD, 0, 0);
    sl_emit(c, SL_OP_SET_LOCAL, (int32_t)idx_name, 0);

    sl_emit(c, SL_OP_JUMP, (int32_t)loop_start, 0);
    sl_patch_jump_here(c, exit_jump);
    sl_patch_breaks(c);

    if (needs_scope) sl_emit(c, SL_OP_POP_SCOPE, 0, 0);

    zend_string_release(arr_tmp);
    zend_string_release(idx_tmp);
}

static void sl_compile_for_in(sl_compiler *c, zval *stmt) {
    zval *kind_zv = sl_ast_prop(stmt, SL_G(str_kind));
    zend_string *var_name = sl_ast_prop_str(stmt, SL_G(str_name));
    zval *object = sl_ast_prop(stmt, SL_G(str_object));
    zval *body = sl_ast_prop(stmt, SL_G(str_body));
    int32_t kind_val = sl_resolve_var_kind(kind_zv);

    bool needs_scope = kind_val != 0;
    if (needs_scope) sl_emit(c, SL_OP_PUSH_SCOPE, 0, 0);

    /* Object.keys(object) */
    zend_string *obj_str = zend_string_init("Object", 6, 0);
    sl_emit(c, SL_OP_GET_LOCAL, (int32_t)sl_emit_name(c, obj_str), 0);
    zend_string_release(obj_str);
    sl_emit_const(c, sl_val_string(zend_string_init("keys", 4, 0)));
    sl_emit(c, SL_OP_GET_PROPERTY, 0, 0);
    sl_compile_expr(c, object);
    sl_emit(c, SL_OP_CALL, 1, 0);

    zend_string *arr_tmp = sl_make_temp_name("__forin_arr");
    uint32_t arr_name = sl_emit_name(c, arr_tmp);
    sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)arr_name, 0);

    /* Init index = 0 */
    sl_emit_const(c, sl_val_double(0.0));
    zend_string *idx_tmp = sl_make_temp_name("__forin_idx");
    uint32_t idx_name = sl_emit_name(c, idx_tmp);
    sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)idx_name, 0);

    uint32_t loop_start = c->op_count;
    sl_push_loop(c);

    /* Condition */
    sl_emit(c, SL_OP_GET_LOCAL, (int32_t)idx_name, 0);
    sl_emit(c, SL_OP_GET_LOCAL, (int32_t)arr_name, 0);
    sl_emit_const(c, sl_val_string(zend_string_init("length", 6, 0)));
    sl_emit(c, SL_OP_GET_PROPERTY, 0, 0);
    sl_emit(c, SL_OP_LT, 0, 0);
    uint32_t exit_jump = sl_emit_jump(c, SL_OP_JUMP_IF_FALSE);

    /* x = arr[idx] */
    sl_emit(c, SL_OP_GET_LOCAL, (int32_t)arr_name, 0);
    sl_emit(c, SL_OP_GET_LOCAL, (int32_t)idx_name, 0);
    sl_emit(c, SL_OP_GET_PROPERTY, 0, 0);
    if (var_name && sl_is_reg_allocated(c, var_name)) {
        sl_emit(c, SL_OP_SET_REG, sl_get_reg(c, var_name), 0);
    } else if (var_name) {
        uint32_t vn = sl_emit_name(c, var_name);
        sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)vn, kind_val);
    }

    sl_compile_stmt(c, body);

    uint32_t update_start = c->op_count;
    sl_patch_continues(c, (int32_t)update_start);

    /* idx++ */
    sl_emit(c, SL_OP_GET_LOCAL, (int32_t)idx_name, 0);
    sl_emit_const(c, sl_val_double(1.0));
    sl_emit(c, SL_OP_ADD, 0, 0);
    sl_emit(c, SL_OP_SET_LOCAL, (int32_t)idx_name, 0);

    sl_emit(c, SL_OP_JUMP, (int32_t)loop_start, 0);
    sl_patch_jump_here(c, exit_jump);
    sl_patch_breaks(c);

    if (needs_scope) sl_emit(c, SL_OP_POP_SCOPE, 0, 0);

    zend_string_release(arr_tmp);
    zend_string_release(idx_tmp);
}

static void sl_compile_switch(sl_compiler *c, zval *stmt) {
    zval *disc = sl_ast_prop(stmt, SL_G(str_discriminant));
    HashTable *cases = sl_ast_prop_array(stmt, SL_G(str_cases));

    sl_compile_expr(c, disc);
    sl_push_loop(c); /* break works in switch */
    c->loop_stack[c->loop_depth - 1].is_switch = true;

    if (!cases) {
        sl_emit(c, SL_OP_POP, 0, 0);
        sl_patch_breaks(c);
        return;
    }

    uint32_t case_count = zend_hash_num_elements(cases);
    uint32_t *case_jumps = emalloc(case_count * sizeof(uint32_t));
    memset(case_jumps, 0, case_count * sizeof(uint32_t));
    int32_t default_index = -1;

    /* Phase 1: Jump table */
    uint32_t ci = 0;
    zval *cs;
    ZEND_HASH_FOREACH_VAL(cases, cs) {
        zval *test = sl_ast_prop(cs, SL_G(str_test));
        if (test && Z_TYPE_P(test) != IS_NULL) {
            sl_emit(c, SL_OP_DUP, 0, 0);
            sl_compile_expr(c, test);
            sl_emit(c, SL_OP_STRICT_EQ, 0, 0);
            case_jumps[ci] = sl_emit_jump(c, SL_OP_JUMP_IF_TRUE);
        } else {
            default_index = (int32_t)ci;
        }
        ci++;
    } ZEND_HASH_FOREACH_END();

    /* Jump to default or end */
    uint32_t default_jump = 0;
    uint32_t end_jump = 0;
    if (default_index >= 0) {
        default_jump = sl_emit_jump(c, SL_OP_JUMP);
    } else {
        end_jump = sl_emit_jump(c, SL_OP_JUMP);
    }

    /* Phase 2: Case bodies */
    ci = 0;
    ZEND_HASH_FOREACH_VAL(cases, cs) {
        if (case_jumps[ci]) {
            sl_patch_jump_here(c, case_jumps[ci]);
        }
        if ((int32_t)ci == default_index && default_index >= 0) {
            sl_patch_jump_here(c, default_jump);
        }
        HashTable *conseq = sl_ast_prop_array(cs, SL_G(str_consequent));
        if (conseq) {
            zval *s;
            ZEND_HASH_FOREACH_VAL(conseq, s) {
                sl_compile_stmt(c, s);
            } ZEND_HASH_FOREACH_END();
        }
        ci++;
    } ZEND_HASH_FOREACH_END();

    if (default_index < 0 && end_jump) {
        sl_patch_jump_here(c, end_jump);
    }

    sl_emit(c, SL_OP_POP, 0, 0); /* pop discriminant */
    sl_patch_breaks(c);

    efree(case_jumps);
}

static void sl_compile_try_catch(sl_compiler *c, zval *stmt) {
    zval *block = sl_ast_prop_block(stmt);
    HashTable *try_stmts = NULL;
    if (block && Z_TYPE_P(block) == IS_OBJECT) {
        try_stmts = sl_ast_prop_array(block, SL_G(str_statements));
    }

    zval *handler = sl_ast_prop_handler(stmt);
    zval *finalizer = sl_ast_prop(stmt, SL_G(str_finalizer));
    bool has_finalizer = finalizer && Z_TYPE_P(finalizer) != IS_NULL;
    bool has_handler = handler && Z_TYPE_P(handler) != IS_NULL;
    HashTable *fin_stmts = has_finalizer
        ? sl_ast_prop_array(finalizer, SL_G(str_statements))
        : NULL;

    if (has_finalizer) {
        sl_push_finally_ctx(c, fin_stmts);
    }

    /* SetCatch with placeholder */
    uint32_t set_catch_idx = sl_emit(c, SL_OP_SET_CATCH, 0xFFFF, 0);

    /* Compile try body */
    if (try_stmts) {
        zval *s;
        ZEND_HASH_FOREACH_VAL(try_stmts, s) {
            sl_compile_stmt(c, s);
        } ZEND_HASH_FOREACH_END();
    }

    /* Normal completion: PopCatch, run finally, jump to end */
    sl_emit(c, SL_OP_POP_CATCH, 0, 0);

    /* Inline finally for normal path */
    if (has_finalizer) {
        sl_compile_finalizer_stmts(c, fin_stmts);
    }

    uint32_t jump_to_end_1 = sl_emit_jump(c, SL_OP_JUMP);

    /* Exception handler starts here */
    sl_patch_jump(c, set_catch_idx, (int32_t)c->op_count);

    uint32_t jump_to_end_2 = 0;
    bool have_jump_2 = false;

    if (has_handler) {
        /* Inner try for finally around catch body */
        uint32_t inner_catch_idx = 0;
        bool has_inner_catch = false;
        if (has_finalizer) {
            inner_catch_idx = sl_emit(c, SL_OP_SET_CATCH, 0xFFFF, 0);
            has_inner_catch = true;
        }

        /* Catch param */
        zval *param = sl_ast_prop(handler, SL_G(str_param));
        zval *catch_body = sl_ast_prop(handler, SL_G(str_body));
        HashTable *catch_stmts = catch_body
            ? sl_ast_prop_array(catch_body, SL_G(str_statements)) : NULL;

        if (param && Z_TYPE_P(param) == IS_STRING) {
            sl_emit(c, SL_OP_PUSH_SCOPE, 0, 0);
            zend_string *param_name = Z_STR_P(param);
            uint32_t name_idx = sl_emit_name(c, param_name);
            sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)name_idx, 1); /* let */

            if (catch_stmts) {
                zval *s;
                ZEND_HASH_FOREACH_VAL(catch_stmts, s) {
                    sl_compile_stmt(c, s);
                } ZEND_HASH_FOREACH_END();
            }
            sl_emit(c, SL_OP_POP_SCOPE, 0, 0);
        } else {
            /* No catch param: discard exception */
            sl_emit(c, SL_OP_POP, 0, 0);
            if (catch_stmts) {
                zval *s;
                ZEND_HASH_FOREACH_VAL(catch_stmts, s) {
                    sl_compile_stmt(c, s);
                } ZEND_HASH_FOREACH_END();
            }
        }

        if (has_inner_catch) {
            sl_emit(c, SL_OP_POP_CATCH, 0, 0);
        }

        /* Normal catch exit: run finally, jump to end */
        if (has_finalizer) {
            sl_compile_finalizer_stmts(c, fin_stmts);
        }
        jump_to_end_2 = sl_emit_jump(c, SL_OP_JUMP);
        have_jump_2 = true;

        if (has_inner_catch) {
            /* Exception in catch body: run finally, re-throw */
            sl_patch_jump(c, inner_catch_idx, (int32_t)c->op_count);
            if (has_finalizer) {
                sl_compile_finalizer_stmts(c, fin_stmts);
            }
            sl_emit(c, SL_OP_THROW, 0, 0);
        }
    } else {
        /* No catch: try/finally only - run finally, re-throw */
        if (has_finalizer) {
            sl_compile_finalizer_stmts(c, fin_stmts);
        }
        sl_emit(c, SL_OP_THROW, 0, 0);
    }

    /* Patch jumps to end */
    sl_patch_jump_here(c, jump_to_end_1);
    if (have_jump_2) {
        sl_patch_jump_here(c, jump_to_end_2);
    }

    if (has_finalizer) {
        sl_pop_finally_ctx(c);
    }
}

static void sl_compile_destructuring(sl_compiler *c, zval *decl) {
    /* Evaluate initializer */
    zval *init = sl_ast_prop_initializer(decl);
    if (!init || Z_TYPE_P(init) == IS_NULL) {
        init = sl_ast_prop(decl, SL_G(str_value));
    }
    sl_compile_expr(c, init);

    zend_string *tmp_name = sl_make_temp_name("__destr_tmp");
    uint32_t tmp_name_idx = sl_emit_name(c, tmp_name);
    sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)tmp_name_idx, 0);

    zval *kind_zv = sl_ast_prop(decl, SL_G(str_kind));
    int32_t kind_val = sl_resolve_var_kind(kind_zv);
    zval *is_arr_zv = sl_ast_prop_is_array(decl);
    bool is_array = is_arr_zv && zend_is_true(is_arr_zv);

    HashTable *bindings = sl_ast_prop_array(decl, SL_G(str_bindings));
    zval *rn_zv = sl_ast_prop_rest_name(decl);

    sl_compile_destructuring_pattern(c, tmp_name_idx, is_array,
        bindings, rn_zv, kind_val);

    zend_string_release(tmp_name);
}

/* ============================================================
 * Destructuring pattern compilation
 * ============================================================ */
static void sl_compile_destructuring_pattern(sl_compiler *c,
    uint32_t src_name, bool is_array, HashTable *bindings,
    zval *rest_name_zv, int32_t kind_val)
{
    if (!bindings) return;

    uint32_t binding_count = 0;
    zval *b;
    ZEND_HASH_FOREACH_VAL(bindings, b) {
        if (Z_TYPE_P(b) != IS_ARRAY) { binding_count++; continue; }
        HashTable *bht = Z_ARRVAL_P(b);

        sl_emit(c, SL_OP_GET_LOCAL, (int32_t)src_name, 0);

        /* Get source key */
        zval *source_zv = zend_hash_str_find(bht, "source", 6);
        if (is_array && source_zv) {
            double idx_d = (Z_TYPE_P(source_zv) == IS_LONG)
                ? (double)Z_LVAL_P(source_zv) : zval_get_double(source_zv);
            sl_emit_const(c, sl_val_double(idx_d));
        } else if (source_zv && Z_TYPE_P(source_zv) == IS_STRING) {
            sl_emit_const(c, sl_val_string(zend_string_copy(Z_STR_P(source_zv))));
        } else if (source_zv && Z_TYPE_P(source_zv) == IS_LONG) {
            sl_emit_const(c, sl_val_double((double)Z_LVAL_P(source_zv)));
        }
        sl_emit(c, SL_OP_GET_PROPERTY, 0, 0);

        /* Default value handling */
        zval *default_zv = zend_hash_str_find(bht, "default", 7);
        if (default_zv && Z_TYPE_P(default_zv) != IS_NULL
            && Z_TYPE_P(default_zv) == IS_OBJECT) {
            sl_emit(c, SL_OP_DUP, 0, 0);
            sl_emit_const(c, sl_val_undefined());
            sl_emit(c, SL_OP_STRICT_EQ, 0, 0);
            uint32_t skip = sl_emit_jump(c, SL_OP_JUMP_IF_FALSE);
            sl_emit(c, SL_OP_POP, 0, 0);
            sl_compile_expr(c, default_zv);
            sl_patch_jump_here(c, skip);
        }

        /* Check for nested pattern */
        zval *name_zv = zend_hash_str_find(bht, "name", 4);
        zval *nested_zv = zend_hash_str_find(bht, "nested", 6);
        if ((!name_zv || Z_TYPE_P(name_zv) == IS_NULL)
            && nested_zv && Z_TYPE_P(nested_zv) == IS_ARRAY) {
            HashTable *nested = Z_ARRVAL_P(nested_zv);
            zend_string *ntmp = sl_make_temp_name("__destr_tmp");
            uint32_t ntmp_idx = sl_emit_name(c, ntmp);
            sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)ntmp_idx, 0);

            zval *n_is_array = zend_hash_str_find(nested, "isArray", 7);
            bool nested_is_array = n_is_array && zend_is_true(n_is_array);
            HashTable *n_bindings = NULL;
            zval *nb_zv = zend_hash_str_find(nested, "bindings", 8);
            if (nb_zv && Z_TYPE_P(nb_zv) == IS_ARRAY) {
                n_bindings = Z_ARRVAL_P(nb_zv);
            }
            zval *n_rest = zend_hash_str_find(nested, "restName", 8);

            sl_compile_destructuring_pattern(c, ntmp_idx, nested_is_array,
                n_bindings, n_rest, kind_val);
            zend_string_release(ntmp);
            binding_count++;
            continue;
        }

        /* Store in variable */
        if (name_zv && Z_TYPE_P(name_zv) == IS_STRING) {
            zend_string *bname = Z_STR_P(name_zv);
            if (sl_is_reg_allocated(c, bname)) {
                sl_emit(c, SL_OP_SET_REG, sl_get_reg(c, bname), 0);
            } else {
                uint32_t ni = sl_emit_name(c, bname);
                sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)ni, kind_val);
            }
        }
        binding_count++;
    } ZEND_HASH_FOREACH_END();

    /* Handle rest element */
    if (rest_name_zv && Z_TYPE_P(rest_name_zv) == IS_STRING && is_array) {
        zend_string *rest_name = Z_STR_P(rest_name_zv);
        sl_emit(c, SL_OP_GET_LOCAL, (int32_t)src_name, 0);
        sl_emit_const(c, sl_val_string(zend_string_init("slice", 5, 0)));
        sl_emit(c, SL_OP_GET_PROPERTY, 0, 0);
        sl_emit_const(c, sl_val_double((double)binding_count));
        sl_emit(c, SL_OP_CALL, 1, 0);
        if (sl_is_reg_allocated(c, rest_name)) {
            sl_emit(c, SL_OP_SET_REG, sl_get_reg(c, rest_name), 0);
        } else {
            uint32_t ni = sl_emit_name(c, rest_name);
            sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)ni, kind_val);
        }
    }
}

static void sl_compile_param_destructuring(sl_compiler *c,
    zend_string *param_name, zval *pattern)
{
    if (!pattern || Z_TYPE_P(pattern) != IS_ARRAY) return;
    HashTable *pht = Z_ARRVAL_P(pattern);

    zend_string *tmp = sl_make_temp_name("__destr_tmp");
    /* Load param value */
    sl_emit_get_var(c, param_name);
    uint32_t tmp_idx = sl_emit_name(c, tmp);
    sl_emit(c, SL_OP_DEFINE_VAR, (int32_t)tmp_idx, 0);

    zval *is_arr_zv = zend_hash_str_find(pht, "isArray", 7);
    bool is_array = is_arr_zv && zend_is_true(is_arr_zv);
    HashTable *bindings = NULL;
    zval *b_zv = zend_hash_str_find(pht, "bindings", 8);
    if (b_zv && Z_TYPE_P(b_zv) == IS_ARRAY) bindings = Z_ARRVAL_P(b_zv);
    zval *rest_zv = zend_hash_str_find(pht, "restName", 8);

    sl_compile_destructuring_pattern(c, tmp_idx, is_array, bindings, rest_zv, 0);
    zend_string_release(tmp);
}

/* ============================================================
 * Expression compilation dispatch
 * ============================================================ */
void sl_compile_expr(sl_compiler *c, zval *expr) {
    if (!expr || Z_TYPE_P(expr) != IS_OBJECT) return;

    sl_ast_class_cache *cc = &SL_G(ast_cache);
    zend_class_entry *ce = Z_OBJCE_P(expr);

    if (ce == cc->ce_number_literal) {
        double val = sl_ast_prop_double(expr, SL_G(str_value));
        /* Store as int if it's an integer value */
        if (val == (double)(zend_long)val && !isinf(val) && !isnan(val)
            && val >= (double)ZEND_LONG_MIN && val <= (double)ZEND_LONG_MAX) {
            sl_emit_const(c, sl_val_int((zend_long)val));
        } else {
            sl_emit_const(c, sl_val_double(val));
        }
    }
    else if (ce == cc->ce_string_literal) {
        zend_string *val = sl_ast_prop_str(expr, SL_G(str_value));
        if (val) {
            sl_emit_const(c, sl_val_string(zend_string_copy(val)));
        } else {
            sl_emit_const(c, sl_val_string(zend_string_init("", 0, 0)));
        }
    }
    else if (ce == cc->ce_boolean_literal) {
        bool val = sl_ast_prop_bool(expr, SL_G(str_value));
        sl_emit_const(c, sl_val_bool(val));
    }
    else if (ce == cc->ce_null_literal) {
        sl_emit_const(c, sl_val_null());
    }
    else if (ce == cc->ce_undefined_literal) {
        sl_emit_const(c, sl_val_undefined());
    }
    else if (ce == cc->ce_identifier) {
        zend_string *name = sl_ast_prop_str(expr, SL_G(str_name));
        if (name) sl_emit_get_var(c, name);
    }
    else if (ce == cc->ce_binary_expr) {
        sl_compile_binary(c, expr);
    }
    else if (ce == cc->ce_unary_expr) {
        sl_compile_unary(c, expr);
    }
    else if (ce == cc->ce_assign_expr) {
        sl_compile_assign(c, expr);
    }
    else if (ce == cc->ce_call_expr) {
        sl_compile_call(c, expr);
    }
    else if (ce == cc->ce_function_expr) {
        sl_compile_function_expr(c, expr);
    }
    else if (ce == cc->ce_logical_expr) {
        sl_compile_logical(c, expr);
    }
    else if (ce == cc->ce_conditional_expr) {
        sl_compile_conditional(c, expr);
    }
    else if (ce == cc->ce_typeof_expr) {
        sl_compile_typeof(c, expr);
    }
    else if (ce == cc->ce_array_literal) {
        sl_compile_array_literal(c, expr);
    }
    else if (ce == cc->ce_object_literal) {
        sl_compile_object_literal(c, expr);
    }
    else if (ce == cc->ce_member_expr) {
        sl_compile_member_expr(c, expr);
    }
    else if (ce == cc->ce_member_assign_expr) {
        sl_compile_member_assign(c, expr);
    }
    else if (ce == cc->ce_this_expr) {
        zend_string *this_str = zend_string_init("this", 4, 0);
        sl_emit(c, SL_OP_GET_LOCAL, (int32_t)sl_emit_name(c, this_str), 0);
        zend_string_release(this_str);
    }
    else if (ce == cc->ce_new_expr) {
        sl_compile_new(c, expr);
    }
    else if (ce == cc->ce_regex_literal) {
        sl_compile_regex(c, expr);
    }
    else if (ce == cc->ce_template_literal) {
        sl_compile_template_literal(c, expr);
    }
    else if (ce == cc->ce_update_expr) {
        sl_compile_update(c, expr);
    }
    else if (ce == cc->ce_void_expr) {
        zval *operand = sl_ast_prop(expr, SL_G(str_operand));
        if (operand && Z_TYPE_P(operand) == IS_OBJECT) {
            sl_compile_expr(c, operand);
            sl_emit(c, SL_OP_POP, 0, 0);
        }
        sl_emit_const(c, sl_val_undefined());
    }
    else if (ce == cc->ce_delete_expr) {
        sl_compile_delete(c, expr);
    }
    else if (ce == cc->ce_sequence_expr) {
        sl_compile_sequence(c, expr);
    }
    else if (ce == cc->ce_spread_element) {
        /* Spread in expression context - compile the argument */
        zval *arg = sl_ast_prop(expr, SL_G(str_argument));
        sl_compile_expr(c, arg);
    }
}

/* ============================================================
 * Individual expression compilers
 * ============================================================ */
static void sl_compile_binary(sl_compiler *c, zval *expr) {
    zval *left = sl_ast_prop(expr, SL_G(str_left));
    zval *right = sl_ast_prop(expr, SL_G(str_right));
    zend_string *op = sl_ast_prop_str(expr, SL_G(str_operator));

    sl_compile_expr(c, left);
    sl_compile_expr(c, right);
    sl_emit(c, sl_binary_op(op), 0, 0);
}

static void sl_compile_unary(sl_compiler *c, zval *expr) {
    zval *operand = sl_ast_prop(expr, SL_G(str_operand));
    zend_string *op = sl_ast_prop_str(expr, SL_G(str_operator));

    sl_compile_expr(c, operand);

    if (!op) return;
    const char *s = ZSTR_VAL(op);
    if (s[0] == '-' && ZSTR_LEN(op) == 1) {
        sl_emit(c, SL_OP_NEGATE, 0, 0);
    } else if (s[0] == '!' && ZSTR_LEN(op) == 1) {
        sl_emit(c, SL_OP_NOT, 0, 0);
    } else if (s[0] == '~' && ZSTR_LEN(op) == 1) {
        sl_emit(c, SL_OP_BIT_NOT, 0, 0);
    }
}

static void sl_compile_update(sl_compiler *c, zval *expr) {
    zend_string *op = sl_ast_prop_str(expr, SL_G(str_operator));
    zval *arg = sl_ast_prop(expr, SL_G(str_argument));
    bool prefix = sl_ast_prop_bool(expr, SL_G(str_prefix));

    uint8_t inc_op = (op && ZSTR_VAL(op)[0] == '+') ? SL_OP_ADD : SL_OP_SUB;

    sl_ast_class_cache *cc = &SL_G(ast_cache);

    if (sl_ast_is(arg, cc->ce_identifier)) {
        zend_string *name = sl_ast_prop_str(arg, SL_G(str_name));
        if (!name) return;

        if (prefix) {
            /* ++x: load, add 1, dup, store */
            sl_emit_get_var(c, name);
            sl_emit_const(c, sl_val_int(1));
            sl_emit(c, inc_op, 0, 0);
            sl_emit(c, SL_OP_DUP, 0, 0);
            sl_emit_set_var(c, name);
        } else {
            /* x++: load, dup, add 1, store -> old remains */
            sl_emit_get_var(c, name);
            sl_emit(c, SL_OP_DUP, 0, 0);
            sl_emit_const(c, sl_val_int(1));
            sl_emit(c, inc_op, 0, 0);
            sl_emit_set_var(c, name);
        }
    } else if (sl_ast_is(arg, cc->ce_member_expr)) {
        zval *obj = sl_ast_prop(arg, SL_G(str_object));
        zval *prop = sl_ast_prop(arg, SL_G(str_property));
        bool computed = sl_ast_prop_bool(arg, SL_G(str_computed));

        /* Helper macros to emit obj+key */
        #define EMIT_OBJ_KEY() do { \
            sl_compile_expr(c, obj); \
            if (computed) { \
                sl_compile_expr(c, prop); \
            } else { \
                zend_string *pname = sl_ast_prop_str(prop, SL_G(str_name)); \
                if (pname) sl_emit_const(c, sl_val_string(zend_string_copy(pname))); \
            } \
        } while(0)

        if (prefix) {
            EMIT_OBJ_KEY();
            EMIT_OBJ_KEY();
            sl_emit(c, SL_OP_GET_PROPERTY, 0, 0);
            sl_emit_const(c, sl_val_int(1));
            sl_emit(c, inc_op, 0, 0);
            sl_emit(c, SL_OP_SET_PROPERTY, 0, 0);
        } else {
            EMIT_OBJ_KEY();
            sl_emit(c, SL_OP_GET_PROPERTY, 0, 0);
            EMIT_OBJ_KEY();
            EMIT_OBJ_KEY();
            sl_emit(c, SL_OP_GET_PROPERTY, 0, 0);
            sl_emit_const(c, sl_val_int(1));
            sl_emit(c, inc_op, 0, 0);
            sl_emit(c, SL_OP_SET_PROPERTY, 0, 0);
            sl_emit(c, SL_OP_POP, 0, 0);
        }
        #undef EMIT_OBJ_KEY
    }
}

static void sl_compile_conditional(sl_compiler *c, zval *expr) {
    zval *cond = sl_ast_prop(expr, SL_G(str_condition));
    zval *cons = sl_ast_prop(expr, SL_G(str_consequent));
    zval *alt = sl_ast_prop(expr, SL_G(str_alternate));

    sl_compile_expr(c, cond);
    uint32_t jf = sl_emit_jump(c, SL_OP_JUMP_IF_FALSE);
    sl_compile_expr(c, cons);
    uint32_t jo = sl_emit_jump(c, SL_OP_JUMP);
    sl_patch_jump_here(c, jf);
    sl_compile_expr(c, alt);
    sl_patch_jump_here(c, jo);
}

static void sl_compile_typeof(sl_compiler *c, zval *expr) {
    zval *operand = sl_ast_prop(expr, SL_G(str_operand));

    if (operand && sl_ast_is(operand, SL_G(ast_cache).ce_identifier)) {
        zend_string *name = sl_ast_prop_str(operand, SL_G(str_name));
        if (name && sl_is_reg_allocated(c, name)) {
            sl_emit(c, SL_OP_GET_REG, sl_get_reg(c, name), 0);
            sl_emit(c, SL_OP_TYPEOF, 0, 0);
        } else if (name) {
            sl_emit(c, SL_OP_TYPEOF_VAR,
                (int32_t)sl_emit_name(c, name), 0);
        }
        return;
    }
    sl_compile_expr(c, operand);
    sl_emit(c, SL_OP_TYPEOF, 0, 0);
}

static void sl_compile_logical(sl_compiler *c, zval *expr) {
    zval *left = sl_ast_prop(expr, SL_G(str_left));
    zval *right = sl_ast_prop(expr, SL_G(str_right));
    zend_string *op = sl_ast_prop_str(expr, SL_G(str_operator));

    sl_compile_expr(c, left);

    if (op && ZSTR_LEN(op) == 2
        && ZSTR_VAL(op)[0] == '&' && ZSTR_VAL(op)[1] == '&') {
        /* && short-circuit */
        sl_emit(c, SL_OP_DUP, 0, 0);
        uint32_t jf = sl_emit_jump(c, SL_OP_JUMP_IF_FALSE);
        sl_emit(c, SL_OP_POP, 0, 0);
        sl_compile_expr(c, right);
        sl_patch_jump_here(c, jf);
    } else if (op && ZSTR_LEN(op) == 2
        && ZSTR_VAL(op)[0] == '?' && ZSTR_VAL(op)[1] == '?') {
        /* ?? nullish coalescing */
        sl_emit(c, SL_OP_DUP, 0, 0);
        uint32_t jnn = sl_emit_jump(c, SL_OP_JUMP_IF_NOT_NULLISH);
        sl_emit(c, SL_OP_POP, 0, 0);
        sl_compile_expr(c, right);
        sl_patch_jump_here(c, jnn);
    } else {
        /* || short-circuit */
        sl_emit(c, SL_OP_DUP, 0, 0);
        uint32_t jt = sl_emit_jump(c, SL_OP_JUMP_IF_TRUE);
        sl_emit(c, SL_OP_POP, 0, 0);
        sl_compile_expr(c, right);
        sl_patch_jump_here(c, jt);
    }
}

static void sl_compile_assign(sl_compiler *c, zval *expr) {
    zend_string *name = sl_ast_prop_str(expr, SL_G(str_name));
    zend_string *op = sl_ast_prop_str(expr, SL_G(str_operator));
    zval *val = sl_ast_prop(expr, SL_G(str_value));

    if (!name || !op) return;

    /* \?\?= short-circuit */
    if (ZSTR_LEN(op) == 3 && ZSTR_VAL(op)[0] == '?' && ZSTR_VAL(op)[1] == '?' && ZSTR_VAL(op)[2] == '=') {
        sl_emit_get_var(c, name);
        sl_emit(c, SL_OP_DUP, 0, 0);
        uint32_t skip = sl_emit_jump(c, SL_OP_JUMP_IF_NOT_NULLISH);
        sl_emit(c, SL_OP_POP, 0, 0);
        sl_compile_expr(c, val);
        sl_emit(c, SL_OP_DUP, 0, 0);
        sl_emit_set_var(c, name);
        sl_patch_jump_here(c, skip);
        return;
    }

    if (ZSTR_LEN(op) == 1 && ZSTR_VAL(op)[0] == '=') {
        /* Simple assignment */
        sl_compile_expr(c, val);
    } else {
        /* Compound assignment: load, compute, store */
        sl_emit_get_var(c, name);
        sl_compile_expr(c, val);
        sl_emit(c, sl_compound_assign_op(op), 0, 0);
    }

    sl_emit(c, SL_OP_DUP, 0, 0);
    sl_emit_set_var(c, name);
}

static void sl_compile_call(sl_compiler *c, zval *expr) {
    zval *callee = sl_ast_prop(expr, SL_G(str_callee));
    HashTable *args = sl_ast_prop_array(expr, SL_G(str_arguments));
    bool has_spread_args = sl_has_spread(args);

    bool use_opt = sl_ast_is_optional(expr);

    sl_compile_expr(c, callee);

    if (!has_spread_args) {
        uint32_t argc = 0;
        if (args) {
            zval *arg;
            ZEND_HASH_FOREACH_VAL(args, arg) {
                sl_compile_expr(c, arg);
                argc++;
            } ZEND_HASH_FOREACH_END();
        }
        sl_emit(c, use_opt ? SL_OP_CALL_OPT : SL_OP_CALL,
            (int32_t)argc, 0);
    } else {
        /* Spread path */
        sl_emit(c, SL_OP_MAKE_ARRAY, 0, 0);
        if (args) {
            zval *arg;
            ZEND_HASH_FOREACH_VAL(args, arg) {
                if (sl_ast_is(arg, SL_G(ast_cache).ce_spread_element)) {
                    zval *spread_arg = sl_ast_prop(arg, SL_G(str_argument));
                    sl_compile_expr(c, spread_arg);
                    sl_emit(c, SL_OP_ARRAY_SPREAD, 0, 0);
                } else {
                    sl_compile_expr(c, arg);
                    sl_emit(c, SL_OP_ARRAY_PUSH, 0, 0);
                }
            } ZEND_HASH_FOREACH_END();
        }
        sl_emit(c, use_opt ? SL_OP_CALL_SPREAD_OPT : SL_OP_CALL_SPREAD,
            0, 0);
    }
}

static void sl_compile_new(sl_compiler *c, zval *expr) {
    zval *callee = sl_ast_prop(expr, SL_G(str_callee));
    HashTable *args = sl_ast_prop_array(expr, SL_G(str_arguments));
    bool has_spread_args = sl_has_spread(args);

    sl_compile_expr(c, callee);

    if (!has_spread_args) {
        uint32_t argc = 0;
        if (args) {
            zval *arg;
            ZEND_HASH_FOREACH_VAL(args, arg) {
                sl_compile_expr(c, arg);
                argc++;
            } ZEND_HASH_FOREACH_END();
        }
        sl_emit(c, SL_OP_NEW, (int32_t)argc, 0);
    } else {
        sl_emit(c, SL_OP_MAKE_ARRAY, 0, 0);
        if (args) {
            zval *arg;
            ZEND_HASH_FOREACH_VAL(args, arg) {
                if (sl_ast_is(arg, SL_G(ast_cache).ce_spread_element)) {
                    zval *spread_arg = sl_ast_prop(arg, SL_G(str_argument));
                    sl_compile_expr(c, spread_arg);
                    sl_emit(c, SL_OP_ARRAY_SPREAD, 0, 0);
                } else {
                    sl_compile_expr(c, arg);
                    sl_emit(c, SL_OP_ARRAY_PUSH, 0, 0);
                }
            } ZEND_HASH_FOREACH_END();
        }
        sl_emit(c, SL_OP_NEW_SPREAD, 0, 0);
    }
}

static void sl_compile_member_expr(sl_compiler *c, zval *expr) {
    zval *obj = sl_ast_prop(expr, SL_G(str_object));
    zval *prop = sl_ast_prop(expr, SL_G(str_property));
    bool computed = sl_ast_prop_bool(expr, SL_G(str_computed));

    bool use_opt = sl_ast_is_optional(expr);

    sl_compile_expr(c, obj);
    if (computed) {
        sl_compile_expr(c, prop);
    } else {
        zend_string *pname = sl_ast_prop_str(prop, SL_G(str_name));
        if (pname) {
            sl_emit_const(c, sl_val_string(zend_string_copy(pname)));
        }
    }
    sl_emit(c, use_opt ? SL_OP_GET_PROPERTY_OPT : SL_OP_GET_PROPERTY, 0, 0);
}

static void sl_compile_member_assign(sl_compiler *c, zval *expr) {
    zval *obj = sl_ast_prop(expr, SL_G(str_object));
    zval *prop = sl_ast_prop(expr, SL_G(str_property));
    bool computed = sl_ast_prop_bool(expr, SL_G(str_computed));
    zend_string *op = sl_ast_prop_str(expr, SL_G(str_operator));
    zval *val = sl_ast_prop(expr, SL_G(str_value));

    /* Helper to emit obj+key */
    #define EMIT_MBR_OBJ_KEY() do { \
        sl_compile_expr(c, obj); \
        if (computed) { \
            sl_compile_expr(c, prop); \
        } else { \
            zend_string *pn = sl_ast_prop_str(prop, SL_G(str_name)); \
            if (pn) sl_emit_const(c, sl_val_string(zend_string_copy(pn))); \
        } \
    } while(0)

    if (!op) return;

    if (ZSTR_LEN(op) == 1 && ZSTR_VAL(op)[0] == '=') {
        /* Simple: obj[key] = val */
        EMIT_MBR_OBJ_KEY();
        sl_compile_expr(c, val);
        sl_emit(c, SL_OP_SET_PROPERTY, 0, 0);
    }
    else if (ZSTR_LEN(op) == 3 && ZSTR_VAL(op)[0] == '?' && ZSTR_VAL(op)[1] == '?' && ZSTR_VAL(op)[2] == '=') {
        /* obj[key] ??= val */
        EMIT_MBR_OBJ_KEY();
        EMIT_MBR_OBJ_KEY();
        sl_emit(c, SL_OP_GET_PROPERTY, 0, 0);
        sl_emit(c, SL_OP_DUP, 0, 0);
        uint32_t skip = sl_emit_jump(c, SL_OP_JUMP_IF_NOT_NULLISH);
        sl_emit(c, SL_OP_POP, 0, 0);
        /* Re-emit obj+key for SetProperty */
        EMIT_MBR_OBJ_KEY();
        sl_compile_expr(c, val);
        sl_emit(c, SL_OP_SET_PROPERTY, 0, 0);
        sl_patch_jump_here(c, skip);
    }
    else {
        /* Compound: obj[key] += val */
        EMIT_MBR_OBJ_KEY();
        EMIT_MBR_OBJ_KEY();
        sl_emit(c, SL_OP_GET_PROPERTY, 0, 0);
        sl_compile_expr(c, val);
        sl_emit(c, sl_compound_assign_op(op), 0, 0);
        sl_emit(c, SL_OP_SET_PROPERTY, 0, 0);
    }

    #undef EMIT_MBR_OBJ_KEY
}

static void sl_compile_array_literal(sl_compiler *c, zval *expr) {
    HashTable *elements = sl_ast_prop_array(expr, SL_G(str_elements));
    if (!elements) {
        sl_emit(c, SL_OP_MAKE_ARRAY, 0, 0);
        return;
    }

    bool has_spread_el = sl_has_spread(elements);

    if (!has_spread_el) {
        uint32_t count = 0;
        zval *el;
        ZEND_HASH_FOREACH_VAL(elements, el) {
            sl_compile_expr(c, el);
            count++;
        } ZEND_HASH_FOREACH_END();
        sl_emit(c, SL_OP_MAKE_ARRAY, (int32_t)count, 0);
    } else {
        sl_emit(c, SL_OP_MAKE_ARRAY, 0, 0);
        zval *el;
        ZEND_HASH_FOREACH_VAL(elements, el) {
            if (sl_ast_is(el, SL_G(ast_cache).ce_spread_element)) {
                zval *arg = sl_ast_prop(el, SL_G(str_argument));
                sl_compile_expr(c, arg);
                sl_emit(c, SL_OP_ARRAY_SPREAD, 0, 0);
            } else {
                sl_compile_expr(c, el);
                sl_emit(c, SL_OP_ARRAY_PUSH, 0, 0);
            }
        } ZEND_HASH_FOREACH_END();
    }
}

static void sl_compile_object_literal(sl_compiler *c, zval *expr) {
    HashTable *props = sl_ast_prop_array(expr, SL_G(str_properties));
    uint32_t count = 0;

    if (props) {
        zval *p;
        ZEND_HASH_FOREACH_VAL(props, p) {
            bool computed = sl_ast_prop_bool(p, SL_G(str_computed));
            if (computed) {
                zval *ck = sl_ast_prop_computed_key(p);
                if (ck && Z_TYPE_P(ck) != IS_NULL) {
                    sl_compile_expr(c, ck);
                } else {
                    zval *pk = sl_ast_prop(p, SL_G(str_key));
                    if (pk) sl_compile_expr(c, pk);
                }
            } else {
                zend_string *key = sl_ast_prop_str(p, SL_G(str_key));
                if (key) {
                    sl_emit_const(c, sl_val_string(zend_string_copy(key)));
                }
            }
            zval *pval = sl_ast_prop(p, SL_G(str_value));
            sl_compile_expr(c, pval);
            count++;
        } ZEND_HASH_FOREACH_END();
    }

    sl_emit(c, SL_OP_MAKE_OBJECT, (int32_t)count, 0);
}

static void sl_compile_regex(sl_compiler *c, zval *expr) {
    zend_string *pattern = sl_ast_prop_str(expr, SL_G(str_pattern));
    zend_string *flags = sl_ast_prop_str(expr, SL_G(str_flags));

    sl_js_regex *regex = sl_regex_new(
        pattern ? zend_string_copy(pattern) : zend_string_init("", 0, 0),
        flags ? zend_string_copy(flags) : zend_string_init("", 0, 0));
    sl_value val = sl_val_regex(regex);

    /* Regex never deduplicated */
    sl_ensure_const_capacity(c, c->const_count + 1);
    uint32_t idx = c->const_count++;
    c->constants[idx] = val;
    SL_ADDREF(val);
    sl_emit(c, SL_OP_CONST, (int32_t)idx, 0);
}

static void sl_compile_template_literal(sl_compiler *c, zval *expr) {
    HashTable *parts = sl_ast_prop_array(expr, SL_G(str_parts));
    HashTable *expressions = sl_ast_prop_array(expr, SL_G(str_expressions));

    if (!parts && !expressions) return;
    if (!parts) return;

    /* Start with first quasi */
    uint32_t expr_count = expressions
        ? zend_hash_num_elements(expressions) : 0;

    zval *first_quasi = zend_hash_index_find(parts, 0);
    if (first_quasi && Z_TYPE_P(first_quasi) == IS_STRING) {
        sl_emit_const(c, sl_val_string(zend_string_copy(Z_STR_P(first_quasi))));
    } else {
        sl_emit_const(c, sl_val_string(zend_string_init("", 0, 0)));
    }

    for (uint32_t ei = 0; ei < expr_count; ei++) {
        zval *e = zend_hash_index_find(expressions, ei);
        if (e) {
            sl_compile_expr(c, e);
            sl_emit(c, SL_OP_ADD, 0, 0);
        }

        zval *next_q = zend_hash_index_find(parts, ei + 1);
        if (next_q && Z_TYPE_P(next_q) == IS_STRING
            && ZSTR_LEN(Z_STR_P(next_q)) > 0) {
            sl_emit_const(c,
                sl_val_string(zend_string_copy(Z_STR_P(next_q))));
            sl_emit(c, SL_OP_ADD, 0, 0);
        }
    }
}

static void sl_compile_delete(sl_compiler *c, zval *expr) {
    zval *operand = sl_ast_prop(expr, SL_G(str_operand));
    sl_ast_class_cache *cc = &SL_G(ast_cache);

    if (operand && sl_ast_is(operand, cc->ce_member_expr)) {
        zval *obj = sl_ast_prop(operand, SL_G(str_object));
        zval *prop = sl_ast_prop(operand, SL_G(str_property));
        bool computed = sl_ast_prop_bool(operand, SL_G(str_computed));

        sl_compile_expr(c, obj);
        if (computed) {
            sl_compile_expr(c, prop);
        } else {
            zend_string *pn = sl_ast_prop_str(prop, SL_G(str_name));
            if (pn) sl_emit_const(c, sl_val_string(zend_string_copy(pn)));
        }
        sl_emit(c, SL_OP_DELETE_PROP, 0, 0);
    } else {
        /* delete on non-member: evaluate for side-effects, return true */
        if (operand && Z_TYPE_P(operand) == IS_OBJECT
            && !sl_ast_is(operand, cc->ce_identifier)) {
            sl_compile_expr(c, operand);
            sl_emit(c, SL_OP_POP, 0, 0);
        }
        sl_emit_const(c, sl_val_bool(1));
    }
}

static void sl_compile_sequence(sl_compiler *c, zval *expr) {
    HashTable *expressions = sl_ast_prop_array(expr, SL_G(str_expressions));
    if (!expressions) return;

    uint32_t count = zend_hash_num_elements(expressions);
    uint32_t i = 0;
    zval *e;
    ZEND_HASH_FOREACH_VAL(expressions, e) {
        sl_compile_expr(c, e);
        i++;
        if (i < count) {
            sl_emit(c, SL_OP_POP, 0, 0);
        }
    } ZEND_HASH_FOREACH_END();
}

static void sl_compile_function_expr(sl_compiler *c, zval *expr) {
    zend_string *name = sl_ast_prop_str(expr, SL_G(str_name));
    HashTable *params_ht = sl_ast_prop_array(expr, SL_G(str_params));
    HashTable *body_ht = sl_ast_prop_array(expr, SL_G(str_body));
    zval *rest_param_zv = sl_ast_prop(expr, SL_G(str_restParam));
    HashTable *defaults_ht = sl_ast_prop_array(expr, SL_G(str_defaults));
    zval *pd_raw = sl_ast_prop_param_destructures(expr);
    HashTable *param_destr = (pd_raw && Z_TYPE_P(pd_raw) == IS_ARRAY)
        ? Z_ARRVAL_P(pd_raw) : NULL;

    sl_compiler child;
    sl_compiler_init(&child);

    zval name_zv;
    if (name) {
        ZVAL_STR_COPY(&name_zv, name);
    } else {
        ZVAL_NULL(&name_zv);
    }

    sl_compile_function_body(&child, &name_zv, params_ht, body_ht,
        rest_param_zv, defaults_ht, param_destr);

    sl_func_descriptor *desc = sl_compiler_build_descriptor(&child);
    sl_compiler_destroy(&child);

    if (Z_TYPE(name_zv) == IS_STRING) {
        zval_ptr_dtor(&name_zv);
    }

    sl_value desc_val = sl_val_closure(sl_closure_new(desc, NULL));
    uint32_t desc_idx = sl_add_const(c, desc_val);
    SL_DELREF(desc_val);
    sl_emit(c, SL_OP_MAKE_CLOSURE, (int32_t)desc_idx, 0);
}

/* ============================================================
 * Function body compilation (used by both decl and expr)
 * ============================================================ */
static void sl_compile_function_body(sl_compiler *c, zval *name_zv,
    HashTable *params_ht, HashTable *body_ht, zval *rest_param_zv,
    HashTable *defaults_ht, HashTable *param_destructures_ht)
{
    /* Set function name */
    if (name_zv && Z_TYPE_P(name_zv) == IS_STRING) {
        c->func_name = zend_string_copy(Z_STR_P(name_zv));
    }

    /* Collect extra locals from param destructuring patterns */
    HashTable extra_locals_ht;
    zend_hash_init(&extra_locals_ht, 8, NULL, NULL, 0);
    if (param_destructures_ht) {
        zval *pd;
        ZEND_HASH_FOREACH_VAL(param_destructures_ht, pd) {
            if (Z_TYPE_P(pd) != IS_ARRAY) continue;
            HashTable *pdh = Z_ARRVAL_P(pd);
            zval *pb = zend_hash_str_find(pdh, "bindings", 8);
            zval *pr = zend_hash_str_find(pdh, "restName", 8);
            if (pb && Z_TYPE_P(pb) == IS_ARRAY) {
                sl_collect_binding_names(Z_ARRVAL_P(pb), pr,
                    &extra_locals_ht);
            }
        } ZEND_HASH_FOREACH_END();
    }

    /* Analyze locals for register allocation */
    sl_analyze_locals(c, params_ht, body_ht, rest_param_zv,
        &extra_locals_ht);
    zend_hash_destroy(&extra_locals_ht);

    /* Store param info */
    if (params_ht) {
        zval *p;
        ZEND_HASH_FOREACH_VAL(params_ht, p) {
            if (Z_TYPE_P(p) != IS_STRING) continue;
            zend_string *pname = Z_STR_P(p);
            sl_ensure_param_capacity(c, c->param_count + 1);
            c->params[c->param_count] = zend_string_copy(pname);
            c->param_slots[c->param_count] = sl_get_reg(c, pname);
            c->param_count++;
        } ZEND_HASH_FOREACH_END();
    }

    /* Rest param */
    if (rest_param_zv && Z_TYPE_P(rest_param_zv) == IS_STRING) {
        c->rest_param = zend_string_copy(Z_STR_P(rest_param_zv));
        c->rest_param_slot = sl_get_reg(c, Z_STR_P(rest_param_zv));
    }

    /* Emit default parameter assignments */
    if (defaults_ht) {
        uint32_t di = 0;
        zval *def;
        ZEND_HASH_FOREACH_VAL(defaults_ht, def) {
            if (Z_TYPE_P(def) == IS_NULL || Z_TYPE_P(def) != IS_OBJECT) {
                di++;
                continue;
            }
            /* Get param name at index di */
            zval *param_zv = params_ht
                ? zend_hash_index_find(params_ht, di) : NULL;
            if (!param_zv || Z_TYPE_P(param_zv) != IS_STRING) {
                di++;
                continue;
            }
            zend_string *pname = Z_STR_P(param_zv);

            /* Load param, check if undefined */
            sl_emit_get_var(c, pname);
            sl_emit_const(c, sl_val_undefined());
            sl_emit(c, SL_OP_STRICT_NEQ, 0, 0);
            uint32_t skip = sl_emit_jump(c, SL_OP_JUMP_IF_TRUE);

            /* Param is undefined -> assign default */
            sl_compile_expr(c, def);
            sl_emit_set_var(c, pname);
            sl_patch_jump_here(c, skip);

            di++;
        } ZEND_HASH_FOREACH_END();
    }

    /* Emit destructuring for pattern parameters */
    if (param_destructures_ht && params_ht) {
        zend_ulong idx;
        zval *pd;
        ZEND_HASH_FOREACH_NUM_KEY_VAL(param_destructures_ht, idx, pd) {
            zval *param_zv = zend_hash_index_find(params_ht, idx);
            if (!param_zv || Z_TYPE_P(param_zv) != IS_STRING) continue;
            sl_compile_param_destructuring(c, Z_STR_P(param_zv), pd);
        } ZEND_HASH_FOREACH_END();
    }

    /* Hoist function declarations */
    if (body_ht) {
        zval *s;
        ZEND_HASH_FOREACH_VAL(body_ht, s) {
            if (sl_ast_is(s, SL_G(ast_cache).ce_function_decl)) {
                sl_compile_function_decl_hoist(c, s);
            }
        } ZEND_HASH_FOREACH_END();
    }

    /* Compile body statements (skip hoisted function decls) */
    if (body_ht) {
        zval *s;
        ZEND_HASH_FOREACH_VAL(body_ht, s) {
            if (sl_ast_is(s, SL_G(ast_cache).ce_function_decl)) continue;
            sl_compile_stmt(c, s);
        } ZEND_HASH_FOREACH_END();
    }

    /* Implicit return undefined */
    sl_emit_const(c, sl_val_undefined());
    sl_emit(c, SL_OP_RETURN, 0, 0);
}

/* ============================================================
 * Register allocation: analyze locals
 * ============================================================ */
static void sl_analyze_locals(sl_compiler *c, HashTable *params_ht,
    HashTable *body_ht, zval *rest_param_zv, HashTable *extra_locals)
{
    /* Reset */
    zend_hash_clean(c->local_vars);
    c->reg_count = 0;

    /* Step 1: Collect all local declarations */
    HashTable locals;
    zend_hash_init(&locals, 32, NULL, NULL, 0);

    /* Add params */
    if (params_ht) {
        zval *p;
        ZEND_HASH_FOREACH_VAL(params_ht, p) {
            if (Z_TYPE_P(p) == IS_STRING) {
                zval one; ZVAL_TRUE(&one);
                zend_hash_update(&locals, Z_STR_P(p), &one);
            }
        } ZEND_HASH_FOREACH_END();
    }

    /* Add rest param */
    if (rest_param_zv && Z_TYPE_P(rest_param_zv) == IS_STRING) {
        zval one; ZVAL_TRUE(&one);
        zend_hash_update(&locals, Z_STR_P(rest_param_zv), &one);
    }

    /* Add extra locals (from destructuring patterns) */
    if (extra_locals) {
        zend_string *key;
        ZEND_HASH_FOREACH_STR_KEY(extra_locals, key) {
            if (key) {
                zval one; ZVAL_TRUE(&one);
                zend_hash_update(&locals, key, &one);
            }
        } ZEND_HASH_FOREACH_END();
    }

    /* Collect var declarations from body */
    if (body_ht) {
        sl_collect_declarations(body_ht, &locals);
    }

    /* Step 2: Collect identifiers referenced inside inner functions */
    HashTable inner_refs;
    zend_hash_init(&inner_refs, 32, NULL, NULL, 0);
    if (body_ht) {
        sl_collect_inner_fn_refs(body_ht, &inner_refs);
    }

    /* Step 3: Assign register slots to non-captured params first */
    if (params_ht) {
        zval *p;
        ZEND_HASH_FOREACH_VAL(params_ht, p) {
            if (Z_TYPE_P(p) != IS_STRING) continue;
            if (!zend_hash_exists(&inner_refs, Z_STR_P(p))) {
                sl_alloc_reg(c, Z_STR_P(p));
            }
        } ZEND_HASH_FOREACH_END();
    }

    /* Then remaining non-captured locals */
    zend_string *key;
    ZEND_HASH_FOREACH_STR_KEY(&locals, key) {
        if (!key) continue;
        if (!sl_is_reg_allocated(c, key)
            && !zend_hash_exists(&inner_refs, key)) {
            sl_alloc_reg(c, key);
        }
    } ZEND_HASH_FOREACH_END();

    zend_hash_destroy(&locals);
    zend_hash_destroy(&inner_refs);
}

/* ============================================================
 * Collect var/function declarations (function-scoped)
 * ============================================================ */
static void sl_collect_declarations(HashTable *stmts, HashTable *locals) {
    sl_ast_class_cache *cc = &SL_G(ast_cache);
    zval *s;
    zval one;
    ZVAL_TRUE(&one);

    ZEND_HASH_FOREACH_VAL(stmts, s) {
        if (Z_TYPE_P(s) != IS_OBJECT) continue;
        zend_class_entry *ce = Z_OBJCE_P(s);

        if (ce == cc->ce_var_declaration) {
            zval *kind_zv = sl_ast_prop(s, SL_G(str_kind));
            if (sl_kind_is_var(kind_zv)) {
                zend_string *name = sl_ast_prop_str(s, SL_G(str_name));
                if (name) zend_hash_update(locals, name, &one);
            }
        }
        else if (ce == cc->ce_function_decl) {
            zend_string *name = sl_ast_prop_str(s, SL_G(str_name));
            if (name) zend_hash_update(locals, name, &one);
            /* Don't recurse into function body */
        }
        else if (ce == cc->ce_block_stmt) {
            HashTable *inner = sl_ast_prop_array(s, SL_G(str_statements));
            if (inner) sl_collect_declarations(inner, locals);
        }
        else if (ce == cc->ce_if_stmt) {
            zval *cons = sl_ast_prop(s, SL_G(str_consequent));
            if (cons && Z_TYPE_P(cons) == IS_OBJECT) {
                HashTable tmp;
                zend_hash_init(&tmp, 4, NULL, NULL, 0);
                zval tmp_zv;
                ZVAL_OBJ(&tmp_zv, Z_OBJ_P(cons));
                zend_hash_next_index_insert(&tmp, &tmp_zv);
                sl_collect_declarations(&tmp, locals);
                zend_hash_destroy(&tmp);
            }
            zval *alt = sl_ast_prop(s, SL_G(str_alternate));
            if (alt && Z_TYPE_P(alt) == IS_OBJECT) {
                HashTable tmp;
                zend_hash_init(&tmp, 4, NULL, NULL, 0);
                zval tmp_zv;
                ZVAL_OBJ(&tmp_zv, Z_OBJ_P(alt));
                zend_hash_next_index_insert(&tmp, &tmp_zv);
                sl_collect_declarations(&tmp, locals);
                zend_hash_destroy(&tmp);
            }
        }
        else if (ce == cc->ce_while_stmt || ce == cc->ce_do_while_stmt) {
            zval *body = sl_ast_prop(s, SL_G(str_body));
            if (body && Z_TYPE_P(body) == IS_OBJECT) {
                HashTable tmp;
                zend_hash_init(&tmp, 4, NULL, NULL, 0);
                zval tmp_zv;
                ZVAL_OBJ(&tmp_zv, Z_OBJ_P(body));
                zend_hash_next_index_insert(&tmp, &tmp_zv);
                sl_collect_declarations(&tmp, locals);
                zend_hash_destroy(&tmp);
            }
        }
        else if (ce == cc->ce_var_declaration_list) {
            HashTable *decls = sl_ast_prop_array(s, SL_G(str_declarations));
            if (decls) {
                zval *d;
                ZEND_HASH_FOREACH_VAL(decls, d) {
                    if (Z_TYPE_P(d) != IS_OBJECT) continue;
                    zval *kind_zv = sl_ast_prop(d, SL_G(str_kind));
                    if (sl_kind_is_var(kind_zv)) {
                        zend_string *nm = sl_ast_prop_str(d, SL_G(str_name));
                        if (nm) zend_hash_update(locals, nm, &one);
                    }
                } ZEND_HASH_FOREACH_END();
            }
        }
        else if (ce == cc->ce_for_stmt) {
            zval *init = sl_ast_prop(s, SL_G(str_init));
            if (init && Z_TYPE_P(init) == IS_OBJECT) {
                zend_class_entry *ice = Z_OBJCE_P(init);
                if (ice == cc->ce_var_declaration) {
                    zval *kind_zv = sl_ast_prop(init, SL_G(str_kind));
                    if (sl_kind_is_var(kind_zv)) {
                        zend_string *nm = sl_ast_prop_str(init,
                            SL_G(str_name));
                        if (nm) zend_hash_update(locals, nm, &one);
                    }
                } else if (ice == cc->ce_var_declaration_list) {
                    HashTable *decls = sl_ast_prop_array(init,
                        SL_G(str_declarations));
                    if (decls) {
                        zval *d;
                        ZEND_HASH_FOREACH_VAL(decls, d) {
                            if (Z_TYPE_P(d) != IS_OBJECT) continue;
                            zval *kind_zv = sl_ast_prop(d, SL_G(str_kind));
                            if (sl_kind_is_var(kind_zv)) {
                                zend_string *nm = sl_ast_prop_str(d,
                                    SL_G(str_name));
                                if (nm)
                                    zend_hash_update(locals, nm, &one);
                            }
                        } ZEND_HASH_FOREACH_END();
                    }
                }
            }
            zval *body = sl_ast_prop(s, SL_G(str_body));
            if (body && Z_TYPE_P(body) == IS_OBJECT) {
                HashTable tmp;
                zend_hash_init(&tmp, 4, NULL, NULL, 0);
                zval tmp_zv;
                ZVAL_OBJ(&tmp_zv, Z_OBJ_P(body));
                zend_hash_next_index_insert(&tmp, &tmp_zv);
                sl_collect_declarations(&tmp, locals);
                zend_hash_destroy(&tmp);
            }
        }
        else if (ce == cc->ce_for_of_stmt || ce == cc->ce_for_in_stmt) {
            zval *kind_zv = sl_ast_prop(s, SL_G(str_kind));
            if (sl_kind_is_var(kind_zv)) {
                zend_string *nm = sl_ast_prop_str(s, SL_G(str_name));
                if (nm) zend_hash_update(locals, nm, &one);
            }
            zval *body = sl_ast_prop(s, SL_G(str_body));
            if (body && Z_TYPE_P(body) == IS_OBJECT) {
                HashTable tmp;
                zend_hash_init(&tmp, 4, NULL, NULL, 0);
                zval tmp_zv;
                ZVAL_OBJ(&tmp_zv, Z_OBJ_P(body));
                zend_hash_next_index_insert(&tmp, &tmp_zv);
                sl_collect_declarations(&tmp, locals);
                zend_hash_destroy(&tmp);
            }
        }
        else if (ce == cc->ce_destructuring_decl) {
            zval *kind_zv = sl_ast_prop(s, SL_G(str_kind));
            if (sl_kind_is_var(kind_zv)) {
                HashTable *bindings = sl_ast_prop_array(s,
                    SL_G(str_bindings));
                zval *rn = sl_ast_prop_rest_name(s);
                if (bindings) {
                    sl_collect_binding_names(bindings, rn, locals);
                }
            }
        }
        else if (ce == cc->ce_switch_stmt) {
            HashTable *cases = sl_ast_prop_array(s, SL_G(str_cases));
            if (cases) {
                zval *cs;
                ZEND_HASH_FOREACH_VAL(cases, cs) {
                    HashTable *conseq = sl_ast_prop_array(cs,
                        SL_G(str_consequent));
                    if (conseq) sl_collect_declarations(conseq, locals);
                } ZEND_HASH_FOREACH_END();
            }
        }
        else if (ce == cc->ce_try_catch_stmt) {
            zval *block = sl_ast_prop_block(s);
            if (block && Z_TYPE_P(block) == IS_OBJECT) {
                HashTable *bs = sl_ast_prop_array(block,
                    SL_G(str_statements));
                if (bs) sl_collect_declarations(bs, locals);
            }
            zval *handler = sl_ast_prop_handler(s);
            if (handler && Z_TYPE_P(handler) == IS_OBJECT) {
                zval *hbody = sl_ast_prop(handler, SL_G(str_body));
                if (hbody && Z_TYPE_P(hbody) == IS_OBJECT) {
                    HashTable *hs = sl_ast_prop_array(hbody,
                        SL_G(str_statements));
                    if (hs) sl_collect_declarations(hs, locals);
                }
            }
            zval *fin = sl_ast_prop(s, SL_G(str_finalizer));
            if (fin && Z_TYPE_P(fin) == IS_OBJECT) {
                HashTable *fs = sl_ast_prop_array(fin,
                    SL_G(str_statements));
                if (fs) sl_collect_declarations(fs, locals);
            }
        }
    } ZEND_HASH_FOREACH_END();
}

static void sl_collect_binding_names(HashTable *bindings, zval *rest_name_zv,
    HashTable *locals)
{
    zval one;
    ZVAL_TRUE(&one);

    zval *b;
    ZEND_HASH_FOREACH_VAL(bindings, b) {
        if (Z_TYPE_P(b) != IS_ARRAY) continue;
        HashTable *bht = Z_ARRVAL_P(b);
        zval *name_zv = zend_hash_str_find(bht, "name", 4);
        zval *nested_zv = zend_hash_str_find(bht, "nested", 6);

        if ((!name_zv || Z_TYPE_P(name_zv) == IS_NULL)
            && nested_zv && Z_TYPE_P(nested_zv) == IS_ARRAY) {
            HashTable *nh = Z_ARRVAL_P(nested_zv);
            zval *nb = zend_hash_str_find(nh, "bindings", 8);
            zval *nr = zend_hash_str_find(nh, "restName", 8);
            if (nb && Z_TYPE_P(nb) == IS_ARRAY) {
                sl_collect_binding_names(Z_ARRVAL_P(nb), nr, locals);
            }
        } else if (name_zv && Z_TYPE_P(name_zv) == IS_STRING) {
            zend_hash_update(locals, Z_STR_P(name_zv), &one);
        }
    } ZEND_HASH_FOREACH_END();

    if (rest_name_zv && Z_TYPE_P(rest_name_zv) == IS_STRING) {
        zend_hash_update(locals, Z_STR_P(rest_name_zv), &one);
    }
}

/* ============================================================
 * Inner function reference collection (capture analysis)
 * ============================================================ */

/* Walk top-level body looking for inner functions, then deeply
 * collect all identifiers from their bodies. */
static void sl_collect_inner_fn_refs(HashTable *stmts, HashTable *refs) {
    zval *s;
    ZEND_HASH_FOREACH_VAL(stmts, s) {
        if (Z_TYPE_P(s) == IS_OBJECT) {
            sl_walk_for_inner_fn_refs(s, refs);
        }
    } ZEND_HASH_FOREACH_END();
}

static void sl_walk_for_inner_fn_refs(zval *node, HashTable *refs) {
    if (Z_TYPE_P(node) != IS_OBJECT) return;

    sl_ast_class_cache *cc = &SL_G(ast_cache);
    zend_class_entry *ce = Z_OBJCE_P(node);

    /* Inner function found -- deeply collect all identifiers */
    if (ce == cc->ce_function_decl) {
        HashTable *body = sl_ast_prop_array(node, SL_G(str_body));
        if (body) sl_deep_collect_ids(body, refs);
        return;
    }
    if (ce == cc->ce_function_expr) {
        HashTable *body = sl_ast_prop_array(node, SL_G(str_body));
        if (body) sl_deep_collect_ids(body, refs);
        return;
    }

    /* Recurse into children to find more inner functions */
    if (ce == cc->ce_expression_stmt) {
        zval *e = sl_ast_prop(node, SL_G(str_expression));
        if (e) sl_walk_for_inner_fn_refs(e, refs);
    }
    else if (ce == cc->ce_var_declaration) {
        zval *init = sl_ast_prop_initializer(node);
        if (init && Z_TYPE_P(init) == IS_OBJECT)
            sl_walk_for_inner_fn_refs(init, refs);
    }
    else if (ce == cc->ce_return_stmt) {
        zval *v = sl_ast_prop(node, SL_G(str_value));
        if (v && Z_TYPE_P(v) == IS_OBJECT)
            sl_walk_for_inner_fn_refs(v, refs);
    }
    else if (ce == cc->ce_block_stmt) {
        HashTable *ss = sl_ast_prop_array(node, SL_G(str_statements));
        if (ss) sl_collect_inner_fn_refs(ss, refs);
    }
    else if (ce == cc->ce_if_stmt) {
        zval *cond = sl_ast_prop(node, SL_G(str_condition));
        zval *cons = sl_ast_prop(node, SL_G(str_consequent));
        zval *alt  = sl_ast_prop(node, SL_G(str_alternate));
        if (cond) sl_walk_for_inner_fn_refs(cond, refs);
        if (cons) sl_walk_for_inner_fn_refs(cons, refs);
        if (alt && Z_TYPE_P(alt) != IS_NULL)
            sl_walk_for_inner_fn_refs(alt, refs);
    }
    else if (ce == cc->ce_while_stmt || ce == cc->ce_do_while_stmt) {
        zval *cond = sl_ast_prop(node, SL_G(str_condition));
        zval *body = sl_ast_prop(node, SL_G(str_body));
        if (cond) sl_walk_for_inner_fn_refs(cond, refs);
        if (body) sl_walk_for_inner_fn_refs(body, refs);
    }
    else if (ce == cc->ce_for_stmt) {
        zval *init = sl_ast_prop(node, SL_G(str_init));
        if (init && Z_TYPE_P(init) == IS_OBJECT) {
            zend_class_entry *ice = Z_OBJCE_P(init);
            if (ice == cc->ce_var_declaration) {
                zval *vi = sl_ast_prop_initializer(init);
                if (vi && Z_TYPE_P(vi) == IS_OBJECT)
                    sl_walk_for_inner_fn_refs(vi, refs);
            } else if (ice == cc->ce_expression_stmt) {
                zval *e = sl_ast_prop(init, SL_G(str_expression));
                if (e) sl_walk_for_inner_fn_refs(e, refs);
            }
        }
        zval *cond = sl_ast_prop(node, SL_G(str_condition));
        if (cond && Z_TYPE_P(cond) != IS_NULL)
            sl_walk_for_inner_fn_refs(cond, refs);
        zval *upd = sl_ast_prop(node, SL_G(str_update));
        if (upd && Z_TYPE_P(upd) != IS_NULL)
            sl_walk_for_inner_fn_refs(upd, refs);
        zval *body = sl_ast_prop(node, SL_G(str_body));
        if (body) sl_walk_for_inner_fn_refs(body, refs);
    }
    else if (ce == cc->ce_for_of_stmt) {
        zval *iter = sl_ast_prop(node, SL_G(str_iterable));
        if (iter) sl_walk_for_inner_fn_refs(iter, refs);
        zval *body = sl_ast_prop(node, SL_G(str_body));
        if (body) sl_walk_for_inner_fn_refs(body, refs);
    }
    else if (ce == cc->ce_for_in_stmt) {
        zval *obj = sl_ast_prop(node, SL_G(str_object));
        if (obj) sl_walk_for_inner_fn_refs(obj, refs);
        zval *body = sl_ast_prop(node, SL_G(str_body));
        if (body) sl_walk_for_inner_fn_refs(body, refs);
    }
    else if (ce == cc->ce_destructuring_decl) {
        zval *init = sl_ast_prop_initializer(node);
        if (init && Z_TYPE_P(init) == IS_OBJECT)
            sl_walk_for_inner_fn_refs(init, refs);
    }
    else if (ce == cc->ce_binary_expr || ce == cc->ce_logical_expr) {
        zval *l = sl_ast_prop(node, SL_G(str_left));
        zval *r = sl_ast_prop(node, SL_G(str_right));
        if (l) sl_walk_for_inner_fn_refs(l, refs);
        if (r) sl_walk_for_inner_fn_refs(r, refs);
    }
    else if (ce == cc->ce_unary_expr) {
        zval *op = sl_ast_prop(node, SL_G(str_operand));
        if (op && Z_TYPE_P(op) == IS_OBJECT)
            sl_walk_for_inner_fn_refs(op, refs);
    }
    else if (ce == cc->ce_conditional_expr) {
        zval *cond = sl_ast_prop(node, SL_G(str_condition));
        zval *cons = sl_ast_prop(node, SL_G(str_consequent));
        zval *alt  = sl_ast_prop(node, SL_G(str_alternate));
        if (cond) sl_walk_for_inner_fn_refs(cond, refs);
        if (cons) sl_walk_for_inner_fn_refs(cons, refs);
        if (alt)  sl_walk_for_inner_fn_refs(alt, refs);
    }
    else if (ce == cc->ce_assign_expr) {
        zval *v = sl_ast_prop(node, SL_G(str_value));
        if (v) sl_walk_for_inner_fn_refs(v, refs);
    }
    else if (ce == cc->ce_call_expr) {
        zval *callee = sl_ast_prop(node, SL_G(str_callee));
        if (callee) sl_walk_for_inner_fn_refs(callee, refs);
        HashTable *args = sl_ast_prop_array(node, SL_G(str_arguments));
        if (args) {
            zval *a;
            ZEND_HASH_FOREACH_VAL(args, a) {
                sl_walk_for_inner_fn_refs(a, refs);
            } ZEND_HASH_FOREACH_END();
        }
    }
    else if (ce == cc->ce_array_literal) {
        HashTable *els = sl_ast_prop_array(node, SL_G(str_elements));
        if (els) {
            zval *el;
            ZEND_HASH_FOREACH_VAL(els, el) {
                sl_walk_for_inner_fn_refs(el, refs);
            } ZEND_HASH_FOREACH_END();
        }
    }
    else if (ce == cc->ce_object_literal) {
        HashTable *props = sl_ast_prop_array(node, SL_G(str_properties));
        if (props) {
            zval *p;
            ZEND_HASH_FOREACH_VAL(props, p) {
                zval *pv = sl_ast_prop(p, SL_G(str_value));
                if (pv) sl_walk_for_inner_fn_refs(pv, refs);
            } ZEND_HASH_FOREACH_END();
        }
    }
    else if (ce == cc->ce_member_expr) {
        zval *obj = sl_ast_prop(node, SL_G(str_object));
        if (obj) sl_walk_for_inner_fn_refs(obj, refs);
        bool computed = sl_ast_prop_bool(node, SL_G(str_computed));
        if (computed) {
            zval *prop = sl_ast_prop(node, SL_G(str_property));
            if (prop) sl_walk_for_inner_fn_refs(prop, refs);
        }
    }
    else if (ce == cc->ce_member_assign_expr) {
        zval *obj = sl_ast_prop(node, SL_G(str_object));
        if (obj) sl_walk_for_inner_fn_refs(obj, refs);
        bool computed = sl_ast_prop_bool(node, SL_G(str_computed));
        if (computed) {
            zval *prop = sl_ast_prop(node, SL_G(str_property));
            if (prop) sl_walk_for_inner_fn_refs(prop, refs);
        }
        zval *v = sl_ast_prop(node, SL_G(str_value));
        if (v) sl_walk_for_inner_fn_refs(v, refs);
    }
    else if (ce == cc->ce_new_expr) {
        zval *callee = sl_ast_prop(node, SL_G(str_callee));
        if (callee) sl_walk_for_inner_fn_refs(callee, refs);
        HashTable *args = sl_ast_prop_array(node, SL_G(str_arguments));
        if (args) {
            zval *a;
            ZEND_HASH_FOREACH_VAL(args, a) {
                sl_walk_for_inner_fn_refs(a, refs);
            } ZEND_HASH_FOREACH_END();
        }
    }
    else if (ce == cc->ce_template_literal) {
        HashTable *exprs = sl_ast_prop_array(node, SL_G(str_expressions));
        if (exprs) {
            zval *e;
            ZEND_HASH_FOREACH_VAL(exprs, e) {
                sl_walk_for_inner_fn_refs(e, refs);
            } ZEND_HASH_FOREACH_END();
        }
    }
    else if (ce == cc->ce_typeof_expr || ce == cc->ce_void_expr || ce == cc->ce_delete_expr) {
        zval *op = sl_ast_prop(node, SL_G(str_operand));
        if (op && Z_TYPE_P(op) == IS_OBJECT)
            sl_walk_for_inner_fn_refs(op, refs);
    }
    else if (ce == cc->ce_update_expr) {
        zval *arg = sl_ast_prop(node, SL_G(str_argument));
        if (arg) sl_walk_for_inner_fn_refs(arg, refs);
    }
    else if (ce == cc->ce_spread_element) {
        zval *arg = sl_ast_prop(node, SL_G(str_argument));
        if (arg) sl_walk_for_inner_fn_refs(arg, refs);
    }
    else if (ce == cc->ce_sequence_expr) {
        HashTable *exprs = sl_ast_prop_array(node, SL_G(str_expressions));
        if (exprs) {
            zval *e;
            ZEND_HASH_FOREACH_VAL(exprs, e) {
                sl_walk_for_inner_fn_refs(e, refs);
            } ZEND_HASH_FOREACH_END();
        }
    }
}

/* ============================================================
 * Deep identifier collection from inner function bodies
 * ============================================================ */
static void sl_deep_collect_ids(HashTable *stmts, HashTable *refs) {
    zval *s;
    ZEND_HASH_FOREACH_VAL(stmts, s) {
        if (Z_TYPE_P(s) == IS_OBJECT) {
            sl_deep_collect_stmt(s, refs);
        }
    } ZEND_HASH_FOREACH_END();
}

static void sl_deep_collect_stmt(zval *stmt, HashTable *refs) {
    if (Z_TYPE_P(stmt) != IS_OBJECT) return;

    sl_ast_class_cache *cc = &SL_G(ast_cache);
    zend_class_entry *ce = Z_OBJCE_P(stmt);

    if (ce == cc->ce_expression_stmt) {
        zval *e = sl_ast_prop(stmt, SL_G(str_expression));
        if (e) sl_deep_collect_expr(e, refs);
    }
    else if (ce == cc->ce_var_declaration) {
        zval *init = sl_ast_prop_initializer(stmt);
        if (init && Z_TYPE_P(init) == IS_OBJECT)
            sl_deep_collect_expr(init, refs);
    }
    else if (ce == cc->ce_return_stmt) {
        zval *v = sl_ast_prop(stmt, SL_G(str_value));
        if (v && Z_TYPE_P(v) == IS_OBJECT)
            sl_deep_collect_expr(v, refs);
    }
    else if (ce == cc->ce_block_stmt) {
        HashTable *ss = sl_ast_prop_array(stmt, SL_G(str_statements));
        if (ss) sl_deep_collect_ids(ss, refs);
    }
    else if (ce == cc->ce_if_stmt) {
        zval *cond = sl_ast_prop(stmt, SL_G(str_condition));
        if (cond) sl_deep_collect_expr(cond, refs);
        zval *cons = sl_ast_prop(stmt, SL_G(str_consequent));
        if (cons) sl_deep_collect_stmt(cons, refs);
        zval *alt = sl_ast_prop(stmt, SL_G(str_alternate));
        if (alt && Z_TYPE_P(alt) != IS_NULL)
            sl_deep_collect_stmt(alt, refs);
    }
    else if (ce == cc->ce_while_stmt || ce == cc->ce_do_while_stmt) {
        zval *cond = sl_ast_prop(stmt, SL_G(str_condition));
        if (cond) sl_deep_collect_expr(cond, refs);
        zval *body = sl_ast_prop(stmt, SL_G(str_body));
        if (body) sl_deep_collect_stmt(body, refs);
    }
    else if (ce == cc->ce_for_stmt) {
        zval *init = sl_ast_prop(stmt, SL_G(str_init));
        if (init && Z_TYPE_P(init) == IS_OBJECT) {
            zend_class_entry *ice = Z_OBJCE_P(init);
            if (ice == cc->ce_var_declaration) {
                zval *vi = sl_ast_prop_initializer(init);
                if (vi && Z_TYPE_P(vi) == IS_OBJECT)
                    sl_deep_collect_expr(vi, refs);
            } else if (ice == cc->ce_expression_stmt) {
                zval *e = sl_ast_prop(init, SL_G(str_expression));
                if (e) sl_deep_collect_expr(e, refs);
            }
        }
        zval *cond = sl_ast_prop(stmt, SL_G(str_condition));
        if (cond && Z_TYPE_P(cond) != IS_NULL)
            sl_deep_collect_expr(cond, refs);
        zval *upd = sl_ast_prop(stmt, SL_G(str_update));
        if (upd && Z_TYPE_P(upd) != IS_NULL)
            sl_deep_collect_expr(upd, refs);
        zval *body = sl_ast_prop(stmt, SL_G(str_body));
        if (body) sl_deep_collect_stmt(body, refs);
    }
    else if (ce == cc->ce_function_decl) {
        /* Recurse into nested inner functions too */
        HashTable *body = sl_ast_prop_array(stmt, SL_G(str_body));
        if (body) sl_deep_collect_ids(body, refs);
    }
}

static void sl_deep_collect_expr(zval *expr, HashTable *refs) {
    if (Z_TYPE_P(expr) != IS_OBJECT) return;

    sl_ast_class_cache *cc = &SL_G(ast_cache);
    zend_class_entry *ce = Z_OBJCE_P(expr);
    zval one;
    ZVAL_TRUE(&one);

    if (ce == cc->ce_identifier) {
        zend_string *name = sl_ast_prop_str(expr, SL_G(str_name));
        if (name) zend_hash_update(refs, name, &one);
    }
    else if (ce == cc->ce_assign_expr) {
        zend_string *name = sl_ast_prop_str(expr, SL_G(str_name));
        if (name) zend_hash_update(refs, name, &one);
        zval *v = sl_ast_prop(expr, SL_G(str_value));
        if (v) sl_deep_collect_expr(v, refs);
    }
    else if (ce == cc->ce_binary_expr || ce == cc->ce_logical_expr) {
        zval *l = sl_ast_prop(expr, SL_G(str_left));
        zval *r = sl_ast_prop(expr, SL_G(str_right));
        if (l) sl_deep_collect_expr(l, refs);
        if (r) sl_deep_collect_expr(r, refs);
    }
    else if (ce == cc->ce_unary_expr) {
        zval *op = sl_ast_prop(expr, SL_G(str_operand));
        if (op && Z_TYPE_P(op) == IS_OBJECT)
            sl_deep_collect_expr(op, refs);
    }
    else if (ce == cc->ce_conditional_expr) {
        zval *cond = sl_ast_prop(expr, SL_G(str_condition));
        zval *cons = sl_ast_prop(expr, SL_G(str_consequent));
        zval *alt  = sl_ast_prop(expr, SL_G(str_alternate));
        if (cond) sl_deep_collect_expr(cond, refs);
        if (cons) sl_deep_collect_expr(cons, refs);
        if (alt)  sl_deep_collect_expr(alt, refs);
    }
    else if (ce == cc->ce_call_expr) {
        zval *callee = sl_ast_prop(expr, SL_G(str_callee));
        if (callee) sl_deep_collect_expr(callee, refs);
        HashTable *args = sl_ast_prop_array(expr, SL_G(str_arguments));
        if (args) {
            zval *a;
            ZEND_HASH_FOREACH_VAL(args, a) {
                sl_deep_collect_expr(a, refs);
            } ZEND_HASH_FOREACH_END();
        }
    }
    else if (ce == cc->ce_function_expr) {
        /* Recurse into nested inner functions */
        HashTable *body = sl_ast_prop_array(expr, SL_G(str_body));
        if (body) {
            zval *s;
            ZEND_HASH_FOREACH_VAL(body, s) {
                if (Z_TYPE_P(s) == IS_OBJECT)
                    sl_deep_collect_stmt(s, refs);
            } ZEND_HASH_FOREACH_END();
        }
    }
    else if (ce == cc->ce_array_literal) {
        HashTable *els = sl_ast_prop_array(expr, SL_G(str_elements));
        if (els) {
            zval *el;
            ZEND_HASH_FOREACH_VAL(els, el) {
                sl_deep_collect_expr(el, refs);
            } ZEND_HASH_FOREACH_END();
        }
    }
    else if (ce == cc->ce_object_literal) {
        HashTable *props = sl_ast_prop_array(expr, SL_G(str_properties));
        if (props) {
            zval *p;
            ZEND_HASH_FOREACH_VAL(props, p) {
                zval *pv = sl_ast_prop(p, SL_G(str_value));
                if (pv) sl_deep_collect_expr(pv, refs);
            } ZEND_HASH_FOREACH_END();
        }
    }
    else if (ce == cc->ce_member_expr) {
        zval *obj = sl_ast_prop(expr, SL_G(str_object));
        if (obj) sl_deep_collect_expr(obj, refs);
        bool computed = sl_ast_prop_bool(expr, SL_G(str_computed));
        if (computed) {
            zval *prop = sl_ast_prop(expr, SL_G(str_property));
            if (prop) sl_deep_collect_expr(prop, refs);
        }
    }
    else if (ce == cc->ce_member_assign_expr) {
        zval *obj = sl_ast_prop(expr, SL_G(str_object));
        if (obj) sl_deep_collect_expr(obj, refs);
        bool computed = sl_ast_prop_bool(expr, SL_G(str_computed));
        if (computed) {
            zval *prop = sl_ast_prop(expr, SL_G(str_property));
            if (prop) sl_deep_collect_expr(prop, refs);
        }
        zval *v = sl_ast_prop(expr, SL_G(str_value));
        if (v) sl_deep_collect_expr(v, refs);
    }
    else if (ce == cc->ce_new_expr) {
        zval *callee = sl_ast_prop(expr, SL_G(str_callee));
        if (callee) sl_deep_collect_expr(callee, refs);
        HashTable *args = sl_ast_prop_array(expr, SL_G(str_arguments));
        if (args) {
            zval *a;
            ZEND_HASH_FOREACH_VAL(args, a) {
                sl_deep_collect_expr(a, refs);
            } ZEND_HASH_FOREACH_END();
        }
    }
    else if (ce == cc->ce_template_literal) {
        HashTable *exprs = sl_ast_prop_array(expr, SL_G(str_expressions));
        if (exprs) {
            zval *e;
            ZEND_HASH_FOREACH_VAL(exprs, e) {
                sl_deep_collect_expr(e, refs);
            } ZEND_HASH_FOREACH_END();
        }
    }
    else if (ce == cc->ce_typeof_expr || ce == cc->ce_void_expr || ce == cc->ce_delete_expr) {
        zval *op = sl_ast_prop(expr, SL_G(str_operand));
        if (op && Z_TYPE_P(op) == IS_OBJECT)
            sl_deep_collect_expr(op, refs);
    }
    else if (ce == cc->ce_update_expr) {
        zval *arg = sl_ast_prop(expr, SL_G(str_argument));
        if (arg) sl_deep_collect_expr(arg, refs);
    }
    else if (ce == cc->ce_spread_element) {
        zval *arg = sl_ast_prop(expr, SL_G(str_argument));
        if (arg) sl_deep_collect_expr(arg, refs);
    }
    else if (ce == cc->ce_sequence_expr) {
        HashTable *exprs = sl_ast_prop_array(expr, SL_G(str_expressions));
        if (exprs) {
            zval *e;
            ZEND_HASH_FOREACH_VAL(exprs, e) {
                sl_deep_collect_expr(e, refs);
            } ZEND_HASH_FOREACH_END();
        }
    }
    /* Literals (Number, String, Boolean, Null, Undefined, This, Regex) -> no ids */
}
