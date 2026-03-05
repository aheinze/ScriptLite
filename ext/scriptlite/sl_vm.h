#ifndef SL_VM_H
#define SL_VM_H

#include "sl_environment.h"
#include "ext/standard/php_smart_string.h"

/* ---- Call Frame ---- */
typedef struct _sl_call_frame {
    sl_func_descriptor *desc;
    sl_environment     *env;
    uint32_t            ip;
    uint32_t            stack_base;
    sl_js_object       *construct_target;
    sl_value           *registers;
    uint32_t            reg_count;
} sl_call_frame;

/* ---- Exception Handler ---- */
typedef struct _sl_exception_handler {
    uint32_t catch_ip;
    uint32_t frame_count;
    uint32_t sp;
    sl_environment *env;
} sl_exception_handler;

/* ---- VM State ---- */
#define SL_MAX_FRAMES 512
#define SL_INITIAL_STACK 1024

struct _sl_vm {
    /* Value stack */
    sl_value     *stack;
    uint32_t      sp;
    uint32_t      stack_capacity;

    /* Call frames */
    sl_call_frame *frames;
    uint32_t       frame_count;
    uint32_t       frame_capacity;

    /* Exception handlers */
    sl_exception_handler *handlers;
    uint32_t              handler_count;
    uint32_t              handler_capacity;

    /* Output buffer for console.log */
    smart_string  output;

    /* Global environment */
    sl_environment *global_env;

    /* JS exception state -- for longjmp-free exception handling */
    bool has_thrown;
    sl_value thrown_value;

    /* Re-entrancy support for sl_vm_invoke_function */
    uint32_t invoke_depth;       /* nesting level of invoke_function calls */
    uint32_t invoke_base_frame;  /* frame_count to return to when invoke_depth > 0 */
};

/* ---- VM API ---- */
sl_vm *sl_vm_new(void);
void sl_vm_free(sl_vm *vm);
sl_value sl_vm_execute(sl_vm *vm, sl_compiled_script *script);
sl_value sl_vm_invoke_function(sl_vm *vm, sl_value callee, sl_value *args, uint32_t argc);
void sl_vm_create_global_env(sl_vm *vm);
void sl_vm_inject_globals(sl_vm *vm, HashTable *globals);
zend_string *sl_vm_get_output(sl_vm *vm);

/* Internal helpers */
void sl_vm_push_frame(sl_vm *vm, sl_func_descriptor *desc, sl_environment *env, sl_js_object *construct_target);
void sl_vm_throw(sl_vm *vm, sl_value val);
void sl_vm_throw_type_error(sl_vm *vm, const char *msg);
void sl_vm_throw_reference_error(sl_vm *vm, const char *name);

#endif /* SL_VM_H */
