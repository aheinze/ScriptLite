#ifndef SL_COMPILER_H
#define SL_COMPILER_H

#include "sl_vm.h"

/* ---- Compiler State ---- */
typedef struct _sl_compiler {
    /* Bytecode emission buffers */
    uint8_t  *ops;
    int32_t  *opA;
    int32_t  *opB;
    uint32_t  op_count;
    uint32_t  op_capacity;

    /* Constant pool (deduplicated) */
    sl_value *constants;
    uint32_t  const_count;
    uint32_t  const_capacity;
    HashTable *const_map;   /* For dedup: string key -> index */

    /* Name pool (deduplicated) */
    zend_string **names;
    uint32_t      name_count;
    uint32_t      name_capacity;
    HashTable    *name_map;

    /* Scope tracking for register allocation */
    HashTable *local_vars;      /* var name -> register slot */
    HashTable *captured_vars;   /* var names captured by inner functions */
    uint32_t   reg_count;

    /* Parameter info */
    zend_string **params;
    int32_t      *param_slots;
    uint32_t      param_count;
    uint32_t      param_capacity;
    zend_string  *rest_param;
    int32_t       rest_param_slot;

    /* Loop/switch context for break/continue */
    struct _sl_loop_ctx {
        uint32_t *break_patches;
        uint32_t  break_count;
        uint32_t  break_capacity;
        uint32_t *continue_patches;
        uint32_t  continue_count;
        uint32_t  continue_capacity;
        bool      is_switch;
    } *loop_stack;
    uint32_t loop_depth;
    uint32_t loop_capacity;

    /* Active try/finally contexts for control-flow exits */
    struct _sl_finally_ctx {
        HashTable *statements;
        uint32_t   loop_depth_at_entry;
    } *finally_stack;
    uint32_t finally_depth;
    uint32_t finally_capacity;
    uint32_t in_finalizer_depth;

    /* Function name (for debugging) */
    zend_string *func_name;
} sl_compiler;

/* ---- Compiler API ---- */
void sl_compiler_init(sl_compiler *c);
void sl_compiler_destroy(sl_compiler *c);
sl_compiled_script *sl_compiler_compile(sl_compiler *c, zval *program);
sl_func_descriptor *sl_compiler_build_descriptor(sl_compiler *c);

/* ---- Emission helpers ---- */
uint32_t sl_emit(sl_compiler *c, uint8_t op, int32_t a, int32_t b);
uint32_t sl_emit_const(sl_compiler *c, sl_value val);
uint32_t sl_emit_name(sl_compiler *c, zend_string *name);
void sl_patch_jump(sl_compiler *c, uint32_t addr, int32_t target);

/* ---- AST compilation entry points ---- */
void sl_compile_program(sl_compiler *c, zval *program);
void sl_compile_stmt(sl_compiler *c, zval *stmt);
void sl_compile_expr(sl_compiler *c, zval *expr);

#endif /* SL_COMPILER_H */
