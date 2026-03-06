/*
 * sl_vm.c -- VM dispatch loop for the ScriptLite C extension.
 *
 * This is the performance-critical hot loop that executes compiled
 * JavaScript bytecode.  It uses computed goto (GCC/Clang) with a
 * fallback to switch dispatch.
 *
 * The design mirrors VirtualMachine.php exactly in semantics but
 * takes advantage of C-level control: localized hot variables,
 * computed goto, and zero-cost exception-handler unwinding.
 */

#include "sl_vm.h"
#include "sl_builtins.h"
#include "ext/spl/spl_exceptions.h"
#include <math.h>
#include <string.h>

/* ================================================================
 * Stack helpers (grow-on-demand)
 * ================================================================ */

static inline void sl_vm_ensure_stack(sl_vm *vm, uint32_t needed) {
    if (EXPECTED(needed <= vm->stack_capacity)) return;
    uint32_t new_cap = vm->stack_capacity;
    while (new_cap < needed) new_cap *= 2;
    vm->stack = erealloc(vm->stack, sizeof(sl_value) * new_cap);
    vm->stack_capacity = new_cap;
}

static inline void sl_vm_ensure_handlers(sl_vm *vm) {
    if (EXPECTED(vm->handler_count < vm->handler_capacity)) return;
    vm->handler_capacity *= 2;
    vm->handlers = erealloc(vm->handlers, sizeof(sl_exception_handler) * vm->handler_capacity);
}

/* ================================================================
 * VM allocation / deallocation
 * ================================================================ */

sl_vm *sl_vm_new(void) {
    sl_vm *vm = ecalloc(1, sizeof(sl_vm));

    vm->stack_capacity = SL_INITIAL_STACK;
    vm->stack = emalloc(sizeof(sl_value) * vm->stack_capacity);
    vm->sp = 0;

    vm->frame_capacity = 64;
    vm->frames = emalloc(sizeof(sl_call_frame) * vm->frame_capacity);
    vm->frame_count = 0;

    vm->handler_capacity = 16;
    vm->handlers = emalloc(sizeof(sl_exception_handler) * vm->handler_capacity);
    vm->handler_count = 0;

    memset(&vm->output, 0, sizeof(smart_string));

    vm->global_env = NULL;
    vm->has_thrown = false;
    vm->thrown_value = sl_val_undefined();

    return vm;
}

void sl_vm_free(sl_vm *vm) {
    /* Release remaining stack values */
    for (uint32_t i = 0; i < vm->sp; i++) {
        SL_DELREF(vm->stack[i]);
    }
    efree(vm->stack);

    /* Release register files */
    for (uint32_t i = 0; i < vm->frame_count; i++) {
        if (vm->frames[i].registers) {
            for (uint32_t j = 0; j < vm->frames[i].reg_count; j++) {
                SL_DELREF(vm->frames[i].registers[j]);
            }
            efree(vm->frames[i].registers);
        }
    }
    efree(vm->frames);

    /* Release any outstanding exception-handler environment refs. */
    for (uint32_t i = 0; i < vm->handler_count; i++) {
        if (SL_GC_DELREF(vm->handlers[i].env) == 0) {
            sl_env_free(vm->handlers[i].env);
        }
    }
    efree(vm->handlers);

    smart_string_free(&vm->output);

    if (vm->global_env) {
        if (SL_GC_DELREF(vm->global_env) == 0) {
            sl_env_free(vm->global_env);
        }
    }

    SL_DELREF(vm->thrown_value);

    efree(vm);
}

/* ================================================================
 * Frame management
 * ================================================================ */

void sl_vm_push_frame(sl_vm *vm, sl_func_descriptor *desc, sl_environment *env,
                      sl_js_object *construct_target) {
    if (UNEXPECTED(vm->frame_count >= vm->frame_capacity)) {
        vm->frame_capacity *= 2;
        vm->frames = erealloc(vm->frames, sizeof(sl_call_frame) * vm->frame_capacity);
    }

    sl_call_frame *f = &vm->frames[vm->frame_count];
    f->desc = desc;
    f->env = env;
    SL_GC_ADDREF(env);
    f->ip = 0;
    f->stack_base = vm->sp;
    f->construct_target = construct_target;
    f->registers = NULL;
    f->reg_count = 0;

    /* Allocate register file */
    if (desc->reg_count > 0) {
        f->reg_count = desc->reg_count;
        f->registers = emalloc(sizeof(sl_value) * desc->reg_count);
        for (uint32_t i = 0; i < desc->reg_count; i++) {
            f->registers[i] = sl_val_undefined();
        }
    }

    vm->frame_count++;
}

static inline void sl_vm_pop_frame(sl_vm *vm) {
    vm->frame_count--;
    sl_call_frame *f = &vm->frames[vm->frame_count];

    /* Release register file */
    if (f->registers) {
        for (uint32_t i = 0; i < f->reg_count; i++) {
            SL_DELREF(f->registers[i]);
        }
        efree(f->registers);
        f->registers = NULL;
        f->reg_count = 0;
    }

    /* Release environment ref */
    if (SL_GC_DELREF(f->env) == 0) {
        sl_env_free(f->env);
    }
}

/* ================================================================
 * Exception throwing
 * ================================================================ */

void sl_vm_throw(sl_vm *vm, sl_value val) {
    vm->has_thrown = true;
    SL_ADDREF(val);
    vm->thrown_value = val;
}

void sl_vm_throw_type_error(sl_vm *vm, const char *msg) {
    zend_string *s = zend_string_init(msg, strlen(msg), 0);
    sl_vm_throw(vm, sl_val_string(s));
}

void sl_vm_throw_reference_error(sl_vm *vm, const char *name) {
    char buf[256];
    int len = snprintf(buf, sizeof(buf), "ReferenceError: %s is not defined", name);
    zend_string *s = zend_string_init(buf, len, 0);
    sl_vm_throw(vm, sl_val_string(s));
}

/* ================================================================
 * Exception handler unwinding
 * ================================================================ */

static void sl_vm_handle_throw(sl_vm *vm) {
    if (vm->handler_count == 0) {
        /* No handler -- propagate as PHP exception */
        zend_string *msg = sl_to_js_string(vm->thrown_value);
        zend_class_entry *throw_ce = spl_ce_RuntimeException;

        if (ZSTR_LEN(msg) == sizeof("RangeError: Maximum call stack size exceeded") - 1 &&
            memcmp(ZSTR_VAL(msg), "RangeError: Maximum call stack size exceeded",
                   sizeof("RangeError: Maximum call stack size exceeded") - 1) == 0) {
            zend_string *vm_ex_name = zend_string_init("ScriptLite\\Vm\\VmException",
                sizeof("ScriptLite\\Vm\\VmException") - 1, 0);
            zend_class_entry *vm_ex_ce = zend_lookup_class(vm_ex_name);
            zend_string_release(vm_ex_name);
            if (vm_ex_ce) {
                throw_ce = vm_ex_ce;
            }
        }

        SL_DELREF(vm->thrown_value);
        vm->thrown_value = sl_val_undefined();
        vm->has_thrown = false;
        zend_throw_exception(throw_ce, ZSTR_VAL(msg), 0);
        zend_string_release(msg);
        return;
    }

    sl_exception_handler handler = vm->handlers[--vm->handler_count];

    /* Unwind frames */
    while (vm->frame_count > handler.frame_count) {
        sl_vm_pop_frame(vm);
    }

    /* Restore stack pointer */
    /* Release values above the handler's sp */
    while (vm->sp > handler.sp) {
        vm->sp--;
        SL_DELREF(vm->stack[vm->sp]);
    }

    /* Push the thrown value onto the stack (catch handler will pop via SetLocal) */
    sl_vm_ensure_stack(vm, vm->sp + 1);
    vm->stack[vm->sp++] = vm->thrown_value;
    /* Ownership transferred to stack -- clear thrown state without delref */
    vm->thrown_value = sl_val_undefined();
    vm->has_thrown = false;

    /* Restore environment and jump to catch IP */
    sl_call_frame *frame = &vm->frames[vm->frame_count - 1];
    if (SL_GC_DELREF(frame->env) == 0) {
        sl_env_free(frame->env);
    }
    frame->env = handler.env;
    SL_GC_ADDREF(handler.env);
    frame->ip = handler.catch_ip;

    /* Release the handler's retained env reference. */
    if (SL_GC_DELREF(handler.env) == 0) {
        sl_env_free(handler.env);
    }
}

/* ================================================================
 * Bind parameters into a call environment and register file.
 *
 * Shared by call, doNew, invokeFunction, and spread variants.
 * ================================================================ */

static void sl_vm_bind_params(sl_vm *vm, sl_func_descriptor *desc,
                              sl_environment *call_env, sl_call_frame *frame,
                              sl_value *args, uint32_t argc) {
    uint32_t param_count = desc->param_count;
    uint32_t last_pos = argc > param_count ? param_count : argc;

    /* Bind environment-allocated parameters */
    for (uint32_t i = 0; i < param_count; i++) {
        int32_t slot = desc->param_slots ? desc->param_slots[i] : -1;
        if (slot < 0) {
            sl_value val = i < last_pos ? args[i] : sl_val_undefined();
            sl_env_define(call_env, desc->params[i], val, false);
        }
    }

    /* Rest parameter (env-allocated) */
    if (desc->rest_param && desc->rest_param_slot < 0) {
        sl_js_array *rest;
        if (argc > param_count) {
            rest = sl_array_new_from(args + param_count, argc - param_count);
        } else {
            rest = sl_array_new(0);
        }
        sl_env_define(call_env, desc->rest_param, sl_val_array(rest), false);
    }

    /* Register-allocated parameters */
    if (frame->registers) {
        for (uint32_t i = 0; i < param_count; i++) {
            int32_t slot = desc->param_slots ? desc->param_slots[i] : -1;
            if (slot >= 0 && (uint32_t)slot < frame->reg_count) {
                sl_value val = i < last_pos ? args[i] : sl_val_undefined();
                SL_DELREF(frame->registers[slot]);
                frame->registers[slot] = val;
                SL_ADDREF(val);
            }
        }

        /* Register-allocated rest param */
        if (desc->rest_param && desc->rest_param_slot >= 0 &&
            (uint32_t)desc->rest_param_slot < frame->reg_count) {
            sl_js_array *rest;
            if (argc > param_count) {
                rest = sl_array_new_from(args + param_count, argc - param_count);
            } else {
                rest = sl_array_new(0);
            }
            sl_value rv = sl_val_array(rest);
            SL_DELREF(frame->registers[desc->rest_param_slot]);
            frame->registers[desc->rest_param_slot] = rv;
            SL_ADDREF(rv);
        }
    }
}

/* ================================================================
 * Function call helpers
 * ================================================================ */

static void sl_vm_call_closure(sl_vm *vm, sl_js_closure *closure,
                               sl_value *args, uint32_t argc,
                               sl_js_object *construct_target) {
    if (UNEXPECTED(vm->frame_count >= SL_MAX_FRAMES)) {
        sl_vm_throw_type_error(vm, "RangeError: Maximum call stack size exceeded");
        return;
    }

    sl_func_descriptor *desc = closure->descriptor;
    sl_environment *call_env = sl_env_extend(closure->captured_env);

    /* If constructor call, define 'this' */
    if (construct_target) {
        sl_env_define(call_env, zend_string_init("this", 4, 0), sl_val_object(construct_target), false);
    }

    sl_vm_push_frame(vm, desc, call_env, construct_target);
    sl_call_frame *frame = &vm->frames[vm->frame_count - 1];
    sl_vm_bind_params(vm, desc, call_env, frame, args, argc);

    /* sl_vm_push_frame added a ref, sl_env_extend set refcount to 1,
     * and push_frame added another via SL_GC_ADDREF.  Release the
     * extra from sl_env_extend. */
    if (SL_GC_DELREF(call_env) == 0) {
        /* Should not happen -- frame holds a ref */
        sl_env_free(call_env);
    }
}

static sl_value sl_vm_call_native(sl_vm *vm, sl_native_func *native,
                                  sl_value *args, uint32_t argc) {
    sl_value result;

    /* If the native function has a bound receiver, prepend it as args[0] */
    sl_value *actual_args = args;
    uint32_t actual_argc = argc;
    sl_value bound_args_buf[16]; /* Stack buffer for bound calls (avoids emalloc) */
    sl_value *bound_args_heap = NULL;
    bool has_bound = (native->bound_receiver.tag != SL_TAG_UNDEFINED);
    if (has_bound) {
        actual_argc = argc + 1;
        if (actual_argc <= 16) {
            actual_args = bound_args_buf;
        } else {
            bound_args_heap = emalloc(sizeof(sl_value) * actual_argc);
            actual_args = bound_args_heap;
        }
        actual_args[0] = native->bound_receiver;
        if (argc > 0) {
            memcpy(&actual_args[1], args, sizeof(sl_value) * argc);
        }
    }

    if (native->handler) {
        result = native->handler(vm, actual_args, (int)actual_argc);
    } else if (!Z_ISUNDEF(native->php_callable)) {
        /* Call PHP callable via zend_call_function */
        zval retval;
        zval *zargs = NULL;

        if (actual_argc > 0) {
            zargs = emalloc(sizeof(zval) * actual_argc);
            for (uint32_t i = 0; i < actual_argc; i++) {
                sl_value_to_zval(&actual_args[i], &zargs[i]);
            }
        }

        zend_fcall_info fci;
        zend_fcall_info_cache fcc;
        memset(&fci, 0, sizeof(fci));
        memset(&fcc, 0, sizeof(fcc));

        char *error = NULL;
        if (zend_fcall_info_init(&native->php_callable, 0, &fci, &fcc, NULL, &error) == SUCCESS) {
            fci.retval = &retval;
            fci.param_count = actual_argc;
            fci.params = zargs;

            if (zend_call_function(&fci, &fcc) == SUCCESS) {
                result = sl_zval_to_value(&retval);
                zval_ptr_dtor(&retval);
            } else {
                result = sl_val_undefined();
            }
        } else {
            result = sl_val_undefined();
        }

        if (error) efree(error);
        if (zargs) {
            for (uint32_t i = 0; i < actual_argc; i++) {
                zval_ptr_dtor(&zargs[i]);
            }
            efree(zargs);
        }
    } else {
        result = sl_val_undefined();
    }

    if (bound_args_heap) {
        efree(bound_args_heap);
    }

    return result;
}

/* sl_vm_call -- handle CALL opcode.
 * Stack layout: [callee, arg0, ..., argN-1]  (callee below args)
 * After: callee and args consumed, result pushed (for native), or
 * new frame pushed (for closure).
 */
static void sl_vm_call(sl_vm *vm, uint32_t argc) {
    uint32_t callee_idx = vm->sp - argc - 1;
    sl_value callee = vm->stack[callee_idx];
    sl_value stack_args_buf[16];
    sl_value *args = stack_args_buf;
    sl_value *heap_args = NULL;

    if (argc > 16) {
        heap_args = emalloc(sizeof(sl_value) * argc);
        args = heap_args;
    }
    if (argc > 0) {
        memcpy(args, &vm->stack[callee_idx + 1], sizeof(sl_value) * argc);
    }

    /* Reset sp to before callee */
    vm->sp = callee_idx;

    if (callee.tag == SL_TAG_CLOSURE) {
        sl_vm_call_closure(vm, callee.u.closure, args, argc, NULL);
    } else if (callee.tag == SL_TAG_NATIVE) {
        sl_value result = sl_vm_call_native(vm, callee.u.native, args, argc);
        sl_vm_ensure_stack(vm, vm->sp + 1);
        vm->stack[vm->sp++] = result;
        SL_ADDREF(result);
    } else {
        sl_vm_throw_type_error(vm, "TypeError: is not a function");
    }

    /* Release callee and args refs */
    SL_DELREF(callee);
    for (uint32_t i = 0; i < argc; i++) {
        SL_DELREF(args[i]);
    }
    if (heap_args) {
        efree(heap_args);
    }
}

/* sl_vm_do_new -- handle NEW opcode. */
static void sl_vm_do_new(sl_vm *vm, uint32_t argc) {
    uint32_t callee_idx = vm->sp - argc - 1;
    sl_value callee = vm->stack[callee_idx];
    sl_value stack_args_buf[16];
    sl_value *args = stack_args_buf;
    sl_value *heap_args = NULL;

    if (argc > 16) {
        heap_args = emalloc(sizeof(sl_value) * argc);
        args = heap_args;
    }
    if (argc > 0) {
        memcpy(args, &vm->stack[callee_idx + 1], sizeof(sl_value) * argc);
    }

    vm->sp = callee_idx;

    if (callee.tag == SL_TAG_NATIVE) {
        sl_value result = sl_vm_call_native(vm, callee.u.native, args, argc);
        sl_vm_ensure_stack(vm, vm->sp + 1);
        vm->stack[vm->sp++] = result;
        SL_ADDREF(result);
    } else if (callee.tag == SL_TAG_CLOSURE) {
        sl_js_object *new_obj = sl_object_new();
        new_obj->constructor = callee.u.closure;
        SL_GC_ADDREF(callee.u.closure);
        new_obj->prototype = sl_closure_get_prototype(callee.u.closure, true);
        if (new_obj->prototype) {
            SL_GC_ADDREF(new_obj->prototype);
        }

        sl_vm_call_closure(vm, callee.u.closure, args, argc, new_obj);
    } else {
        sl_vm_throw_type_error(vm, "TypeError: is not a constructor");
    }

    SL_DELREF(callee);
    for (uint32_t i = 0; i < argc; i++) {
        SL_DELREF(args[i]);
    }
    if (heap_args) {
        efree(heap_args);
    }
}

/* sl_vm_do_return -- handle RETURN opcode. */
static void sl_vm_do_return(sl_vm *vm) {
    sl_value return_value = vm->stack[--vm->sp];
    sl_call_frame *frame = &vm->frames[vm->frame_count - 1];
    sl_js_object *construct_target = frame->construct_target;

    /* Restore stack to frame's base, releasing any leftover values */
    while (vm->sp > frame->stack_base) {
        vm->sp--;
        SL_DELREF(vm->stack[vm->sp]);
    }

    sl_vm_pop_frame(vm);

    /* JS constructor return semantics:
     * If called with `new` and return value is not an object type, use
     * the constructed object instead. */
    if (construct_target != NULL) {
        if (return_value.tag == SL_TAG_OBJECT ||
            return_value.tag == SL_TAG_ARRAY ||
            return_value.tag == SL_TAG_DATE ||
            return_value.tag == SL_TAG_REGEX) {
            sl_vm_ensure_stack(vm, vm->sp + 1);
            vm->stack[vm->sp++] = return_value;
        } else {
            SL_DELREF(return_value);
            sl_value obj_val = sl_val_object(construct_target);
            SL_ADDREF(obj_val);
            sl_vm_ensure_stack(vm, vm->sp + 1);
            vm->stack[vm->sp++] = obj_val;
        }
    } else {
        sl_vm_ensure_stack(vm, vm->sp + 1);
        vm->stack[vm->sp++] = return_value;
    }
}

/* ================================================================
 * Relational comparison helpers.
 *
 * Keep parity with the PHP VM path (VirtualMachine::binaryLt/Lte/Gt/Gte):
 * - bool  -> 0/1
 * - null  -> 0
 * - string -> numeric coercion
 * - object-like operands are handled by caller as non-comparable (false)
 * ================================================================ */

static inline double sl_val_to_compare_num(sl_value v) {
    if (v.tag == SL_TAG_INT) return (double)v.u.ival;
    if (v.tag == SL_TAG_DOUBLE) return v.u.dval;
    if (v.tag == SL_TAG_BOOL) return v.u.bval ? 1.0 : 0.0;
    if (v.tag == SL_TAG_NULL) return 0.0;
    if (v.tag == SL_TAG_STRING) return sl_to_number(v);
    return NAN;
}

static inline bool sl_is_object_type(sl_value v) {
    return v.tag >= SL_TAG_ARRAY && v.tag != SL_TAG_STRING;
}

static inline bool sl_str_try_numeric(zend_string *str, double *out) {
    zend_long lval = 0;
    double dval = 0.0;
    uint8_t t = is_numeric_string(
        ZSTR_VAL(str),
        ZSTR_LEN(str),
        &lval,
        &dval,
        false
    );
    if (t == IS_LONG) {
        *out = (double)lval;
        return true;
    }
    if (t == IS_DOUBLE) {
        *out = dval;
        return true;
    }
    return false;
}

/* ================================================================
 * Concat helper: convert to string, concatenate.
 * ================================================================ */

static inline sl_value sl_string_concat(sl_value a, sl_value b) {
    zend_string *sa = sl_to_js_string(a);
    zend_string *sb = sl_to_js_string(b);
    size_t la = ZSTR_LEN(sa), lb = ZSTR_LEN(sb);
    zend_string *result = zend_string_alloc(la + lb, 0);
    memcpy(ZSTR_VAL(result), ZSTR_VAL(sa), la);
    memcpy(ZSTR_VAL(result) + la, ZSTR_VAL(sb), lb);
    ZSTR_VAL(result)[la + lb] = '\0';
    zend_string_release(sa);
    zend_string_release(sb);
    return sl_val_string(result);
}

/* ================================================================
 * The Hot Dispatch Loop
 * ================================================================ */

/*
 * Computed goto dispatch.
 *
 * GCC/Clang: we build a table of label addresses and jump directly.
 * Other compilers: fall back to a switch statement.
 */

#ifdef __GNUC__
#define USE_COMPUTED_GOTO 1
#else
#define USE_COMPUTED_GOTO 0
#endif

#if USE_COMPUTED_GOTO

#define DISPATCH()                                         \
    do {                                                   \
        ci = ip++;                                         \
        op = ops[ci];                                      \
        goto *dispatch_table[op];                          \
    } while (0)

#define TARGET(name) lbl_##name

#else /* switch dispatch */

#define DISPATCH() continue
#define TARGET(name) case name

#endif

/* Sync local state back to VM/frame before a call or throw. */
#define SYNC_STATE() do {                                  \
    vm->sp = sp;                                           \
    frame->ip = ip;                                        \
    if (frame->env != env) {                               \
        SL_GC_ADDREF(env);                                 \
        if (SL_GC_DELREF(frame->env) == 0)                 \
            sl_env_free(frame->env);                       \
        frame->env = env;                                  \
    }                                                      \
} while (0)

/* Reload local state from VM/frame after a call or exception. */
#define RELOAD_STATE() do {                                \
    frame = &vm->frames[vm->frame_count - 1];              \
    desc  = frame->desc;                                   \
    ops   = desc->ops;                                     \
    opA   = desc->opA;                                     \
    opB   = desc->opB;                                     \
    consts = desc->constants;                              \
    names  = desc->names;                                  \
    env    = frame->env;                                   \
    ip     = frame->ip;                                    \
    sp     = vm->sp;                                       \
    regs   = frame->registers;                             \
    stack  = vm->stack;                                    \
} while (0)

/* Check for thrown exceptions after a call that may throw. */
#define CHECK_THROWN() do {                                 \
    if (UNEXPECTED(vm->has_thrown)) {                       \
        SYNC_STATE();                                      \
        sl_vm_handle_throw(vm);                            \
        if (EG(exception)) return sl_val_undefined();      \
        RELOAD_STATE();                                    \
        DISPATCH();                                        \
    }                                                      \
} while (0)

/* Ensure stack capacity for pushes. */
#define ENSURE_STACK(n) do {                               \
    if (UNEXPECTED(sp + (n) > vm->stack_capacity)) {       \
        vm->sp = sp;                                       \
        sl_vm_ensure_stack(vm, sp + (n));                   \
        stack = vm->stack;                                 \
    }                                                      \
} while (0)

static sl_value sl_vm_run(sl_vm *vm) {

#if USE_COMPUTED_GOTO
    /* Build dispatch table -- index by opcode number. */
    static const void *dispatch_table[SL_OP_MAX] = {
        [0 ... SL_OP_MAX - 1] = &&lbl_DEFAULT,

        [SL_OP_CONST]          = &&lbl_SL_OP_CONST,
        [SL_OP_POP]            = &&lbl_SL_OP_POP,
        [SL_OP_DUP]            = &&lbl_SL_OP_DUP,

        [SL_OP_ADD]            = &&lbl_SL_OP_ADD,
        [SL_OP_SUB]            = &&lbl_SL_OP_SUB,
        [SL_OP_MUL]            = &&lbl_SL_OP_MUL,
        [SL_OP_DIV]            = &&lbl_SL_OP_DIV,
        [SL_OP_MOD]            = &&lbl_SL_OP_MOD,
        [SL_OP_NEGATE]         = &&lbl_SL_OP_NEGATE,
        [SL_OP_NOT]            = &&lbl_SL_OP_NOT,
        [SL_OP_TYPEOF]         = &&lbl_SL_OP_TYPEOF,
        [SL_OP_EXP]            = &&lbl_SL_OP_EXP,
        [SL_OP_TYPEOF_VAR]     = &&lbl_SL_OP_TYPEOF_VAR,

        [SL_OP_EQ]             = &&lbl_SL_OP_EQ,
        [SL_OP_NEQ]            = &&lbl_SL_OP_NEQ,
        [SL_OP_STRICT_EQ]      = &&lbl_SL_OP_STRICT_EQ,
        [SL_OP_STRICT_NEQ]     = &&lbl_SL_OP_STRICT_NEQ,
        [SL_OP_LT]             = &&lbl_SL_OP_LT,
        [SL_OP_LTE]            = &&lbl_SL_OP_LTE,
        [SL_OP_GT]             = &&lbl_SL_OP_GT,
        [SL_OP_GTE]            = &&lbl_SL_OP_GTE,
        [SL_OP_CONCAT]         = &&lbl_SL_OP_CONCAT,

        [SL_OP_GET_LOCAL]      = &&lbl_SL_OP_GET_LOCAL,
        [SL_OP_SET_LOCAL]      = &&lbl_SL_OP_SET_LOCAL,
        [SL_OP_DEFINE_VAR]     = &&lbl_SL_OP_DEFINE_VAR,
        [SL_OP_GET_REG]        = &&lbl_SL_OP_GET_REG,
        [SL_OP_SET_REG]        = &&lbl_SL_OP_SET_REG,

        [SL_OP_BIT_AND]        = &&lbl_SL_OP_BIT_AND,
        [SL_OP_BIT_OR]         = &&lbl_SL_OP_BIT_OR,
        [SL_OP_BIT_XOR]        = &&lbl_SL_OP_BIT_XOR,
        [SL_OP_BIT_NOT]        = &&lbl_SL_OP_BIT_NOT,
        [SL_OP_SHL]            = &&lbl_SL_OP_SHL,
        [SL_OP_SHR]            = &&lbl_SL_OP_SHR,
        [SL_OP_USHR]           = &&lbl_SL_OP_USHR,

        [SL_OP_JUMP]           = &&lbl_SL_OP_JUMP,
        [SL_OP_JUMP_IF_FALSE]  = &&lbl_SL_OP_JUMP_IF_FALSE,
        [SL_OP_JUMP_IF_TRUE]   = &&lbl_SL_OP_JUMP_IF_TRUE,
        [SL_OP_JUMP_IF_NOT_NULLISH] = &&lbl_SL_OP_JUMP_IF_NOT_NULLISH,

        [SL_OP_DELETE_PROP]    = &&lbl_SL_OP_DELETE_PROP,
        [SL_OP_HAS_PROP]      = &&lbl_SL_OP_HAS_PROP,
        [SL_OP_INSTANCE_OF]   = &&lbl_SL_OP_INSTANCE_OF,

        [SL_OP_MAKE_CLOSURE]   = &&lbl_SL_OP_MAKE_CLOSURE,
        [SL_OP_CALL]           = &&lbl_SL_OP_CALL,
        [SL_OP_RETURN]         = &&lbl_SL_OP_RETURN,
        [SL_OP_NEW]            = &&lbl_SL_OP_NEW,
        [SL_OP_CALL_SPREAD]    = &&lbl_SL_OP_CALL_SPREAD,
        [SL_OP_NEW_SPREAD]     = &&lbl_SL_OP_NEW_SPREAD,
        [SL_OP_CALL_OPT]       = &&lbl_SL_OP_CALL_OPT,
        [SL_OP_CALL_SPREAD_OPT] = &&lbl_SL_OP_CALL_SPREAD_OPT,

        [SL_OP_SET_CATCH]      = &&lbl_SL_OP_SET_CATCH,
        [SL_OP_POP_CATCH]      = &&lbl_SL_OP_POP_CATCH,
        [SL_OP_THROW]          = &&lbl_SL_OP_THROW,

        [SL_OP_PUSH_SCOPE]     = &&lbl_SL_OP_PUSH_SCOPE,
        [SL_OP_POP_SCOPE]      = &&lbl_SL_OP_POP_SCOPE,

        [SL_OP_MAKE_ARRAY]     = &&lbl_SL_OP_MAKE_ARRAY,
        [SL_OP_GET_PROPERTY]   = &&lbl_SL_OP_GET_PROPERTY,
        [SL_OP_SET_PROPERTY]   = &&lbl_SL_OP_SET_PROPERTY,
        [SL_OP_MAKE_OBJECT]    = &&lbl_SL_OP_MAKE_OBJECT,
        [SL_OP_GET_PROPERTY_OPT] = &&lbl_SL_OP_GET_PROPERTY_OPT,
        [SL_OP_ARRAY_PUSH]     = &&lbl_SL_OP_ARRAY_PUSH,
        [SL_OP_ARRAY_SPREAD]   = &&lbl_SL_OP_ARRAY_SPREAD,

        [SL_OP_HALT]           = &&lbl_SL_OP_HALT,
    };
#endif

    /* ---- Localize hot variables ---- */
    sl_call_frame *frame;
    sl_func_descriptor *desc;
    uint8_t       *ops;
    int32_t       *opA;
    int32_t       *opB;
    sl_value      *consts;
    zend_string  **names;
    sl_environment *env;
    uint32_t       ip;
    uint32_t       sp;
    sl_value      *regs;
    sl_value      *stack;
    uint32_t       ci;      /* current instruction index */
    uint8_t        op;      /* current opcode */

    RELOAD_STATE();

    /* ---- Begin dispatch ---- */
#if USE_COMPUTED_GOTO
    DISPATCH();
#else
    for (;;) {
        ci = ip++;
        op = ops[ci];
        switch (op) {
#endif

    /* ================================================================
     * Constants & Stack
     * ================================================================ */

    TARGET(SL_OP_CONST): {
        ENSURE_STACK(1);
        sl_value v = consts[opA[ci]];
        SL_ADDREF(v);
        stack[sp++] = v;
        DISPATCH();
    }

    TARGET(SL_OP_POP): {
        sp--;
        SL_DELREF(stack[sp]);
        DISPATCH();
    }

    TARGET(SL_OP_DUP): {
        ENSURE_STACK(1);
        sl_value v = stack[sp - 1];
        SL_ADDREF(v);
        stack[sp] = v;
        sp++;
        DISPATCH();
    }

    /* ================================================================
     * Arithmetic
     * ================================================================ */

    TARGET(SL_OP_ADD): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];

        /* Fast path: int + int */
        if (EXPECTED(a.tag == SL_TAG_INT && b.tag == SL_TAG_INT)) {
            stack[sp - 1] = sl_val_int(a.u.ival + b.u.ival);
            DISPATCH();
        }
        /* Fast path: numeric + numeric */
        if (SL_IS_NUMERIC(a) && SL_IS_NUMERIC(b)) {
            double da = (a.tag == SL_TAG_INT) ? (double)a.u.ival : a.u.dval;
            double db = (b.tag == SL_TAG_INT) ? (double)b.u.ival : b.u.dval;
            SL_DELREF(stack[sp - 1]);
            stack[sp - 1] = sl_val_double(da + db);
            DISPATCH();
        }

        /* Slow path: ToPrimitive + type coercion */
        /* If either operand is array/object, convert to string */
        sl_value oa = a, ob = b;
        if (a.tag == SL_TAG_ARRAY || a.tag == SL_TAG_OBJECT) {
            zend_string *s = sl_to_js_string(a);
            oa = sl_val_string(s);
        }
        if (b.tag == SL_TAG_ARRAY || b.tag == SL_TAG_OBJECT) {
            zend_string *s = sl_to_js_string(b);
            ob = sl_val_string(s);
        }

        if (oa.tag == SL_TAG_STRING || ob.tag == SL_TAG_STRING) {
            /* String concatenation */
            sl_value result = sl_string_concat(oa, ob);
            SL_DELREF(stack[sp - 1]);
            SL_DELREF(b);
            /* Release temporaries if we created them */
            if (oa.tag == SL_TAG_STRING && a.tag != SL_TAG_STRING) {
                zend_string_release(oa.u.str);
            }
            if (ob.tag == SL_TAG_STRING && b.tag != SL_TAG_STRING) {
                zend_string_release(ob.u.str);
            }
            stack[sp - 1] = result;
        } else {
            /* Numeric addition */
            double da = sl_to_number(oa);
            double db = sl_to_number(ob);
            SL_DELREF(stack[sp - 1]);
            SL_DELREF(b);
            if (oa.tag == SL_TAG_STRING && a.tag != SL_TAG_STRING) {
                zend_string_release(oa.u.str);
            }
            if (ob.tag == SL_TAG_STRING && b.tag != SL_TAG_STRING) {
                zend_string_release(ob.u.str);
            }
            stack[sp - 1] = sl_val_double(da + db);
        }
        DISPATCH();
    }

    TARGET(SL_OP_SUB): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        double da = SL_IS_NUMERIC(a) ? sl_to_double(a) : sl_to_number(a);
        double db = SL_IS_NUMERIC(b) ? sl_to_double(b) : sl_to_number(b);
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_double(da - db);
        DISPATCH();
    }

    TARGET(SL_OP_MUL): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        double da = SL_IS_NUMERIC(a) ? sl_to_double(a) : sl_to_number(a);
        double db = SL_IS_NUMERIC(b) ? sl_to_double(b) : sl_to_number(b);
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_double(da * db);
        DISPATCH();
    }

    TARGET(SL_OP_DIV): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        double da = sl_to_double(a);
        double db = sl_to_double(b);
        double result;
        if (db == 0.0) {
            if (da == 0.0) result = NAN;
            else if (da > 0.0) result = INFINITY;
            else result = -INFINITY;
        } else {
            result = da / db;
        }
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_double(result);
        DISPATCH();
    }

    TARGET(SL_OP_MOD): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        double da = SL_IS_NUMERIC(a) ? sl_to_double(a) : sl_to_number(a);
        double db = SL_IS_NUMERIC(b) ? sl_to_double(b) : sl_to_number(b);
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_double(fmod(da, db));
        DISPATCH();
    }

    TARGET(SL_OP_NEGATE): {
        sl_value a = stack[sp - 1];
        double da = SL_IS_NUMERIC(a) ? sl_to_double(a) : sl_to_number(a);
        SL_DELREF(stack[sp - 1]);
        stack[sp - 1] = sl_val_double(-da);
        DISPATCH();
    }

    TARGET(SL_OP_NOT): {
        sl_value v = stack[sp - 1];
        bool truthy = sl_is_truthy(v);
        SL_DELREF(stack[sp - 1]);
        stack[sp - 1] = sl_val_bool(!truthy);
        DISPATCH();
    }

    TARGET(SL_OP_TYPEOF): {
        sl_value v = stack[sp - 1];
        zend_string *t = sl_js_typeof(v);
        SL_DELREF(stack[sp - 1]);
        stack[sp - 1] = sl_val_string(t);
        DISPATCH();
    }

    TARGET(SL_OP_TYPEOF_VAR): {
        /* Safe typeof on identifier -- returns "undefined" if variable not defined */
        zend_string *name = names[opA[ci]];
        ENSURE_STACK(1);
        if (sl_env_has(env, name)) {
            sl_value val = sl_env_get(env, name);
            zend_string *t = sl_js_typeof(val);
            SL_DELREF(val);
            stack[sp++] = sl_val_string(t);
        } else {
            stack[sp++] = sl_val_string(zend_string_init("undefined", 9, 0));
        }
        DISPATCH();
    }

    TARGET(SL_OP_EXP): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        double da = sl_to_double(a);
        double db = sl_to_double(b);
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_double(pow(da, db));
        DISPATCH();
    }

    /* ================================================================
     * Bitwise
     * ================================================================ */

    TARGET(SL_OP_BIT_AND): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        int32_t ia = (int32_t)sl_to_number(a);
        int32_t ib = (int32_t)sl_to_number(b);
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_int(ia & ib);
        DISPATCH();
    }

    TARGET(SL_OP_BIT_OR): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        int32_t ia = (int32_t)sl_to_number(a);
        int32_t ib = (int32_t)sl_to_number(b);
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_int(ia | ib);
        DISPATCH();
    }

    TARGET(SL_OP_BIT_XOR): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        int32_t ia = (int32_t)sl_to_number(a);
        int32_t ib = (int32_t)sl_to_number(b);
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_int(ia ^ ib);
        DISPATCH();
    }

    TARGET(SL_OP_BIT_NOT): {
        sl_value a = stack[sp - 1];
        int32_t ia = (int32_t)sl_to_number(a);
        SL_DELREF(stack[sp - 1]);
        stack[sp - 1] = sl_val_int(~ia);
        DISPATCH();
    }

    TARGET(SL_OP_SHL): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        int32_t ia = (int32_t)sl_to_number(a);
        int32_t ib = (int32_t)sl_to_number(b) & 0x1F;
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_int(ia << ib);
        DISPATCH();
    }

    TARGET(SL_OP_SHR): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        int32_t ia = (int32_t)sl_to_number(a);
        int32_t ib = (int32_t)sl_to_number(b) & 0x1F;
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_int(ia >> ib);
        DISPATCH();
    }

    TARGET(SL_OP_USHR): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        uint32_t ua = (uint32_t)(int32_t)sl_to_number(a);
        int32_t ib = (int32_t)sl_to_number(b) & 0x1F;
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_int((zend_long)(ua >> ib));
        DISPATCH();
    }

    /* ================================================================
     * Comparison
     * ================================================================ */

    TARGET(SL_OP_EQ): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        bool result = sl_loose_equal(a, b);
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_bool(result);
        DISPATCH();
    }

    TARGET(SL_OP_NEQ): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        bool result = !sl_loose_equal(a, b);
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_bool(result);
        DISPATCH();
    }

    TARGET(SL_OP_STRICT_EQ): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        bool result = sl_strict_equal(a, b);
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_bool(result);
        DISPATCH();
    }

    TARGET(SL_OP_STRICT_NEQ): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        bool result = !sl_strict_equal(a, b);
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_bool(result);
        DISPATCH();
    }

    TARGET(SL_OP_LT): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        bool result;
        if (a.tag == SL_TAG_STRING && b.tag == SL_TAG_STRING) {
            double da = 0.0, db = 0.0;
            bool a_numeric = sl_str_try_numeric(a.u.str, &da);
            bool b_numeric = sl_str_try_numeric(b.u.str, &db);
            if (a_numeric && b_numeric) {
                result = da < db;
            } else {
                result = zend_binary_strcmp(
                    ZSTR_VAL(a.u.str), ZSTR_LEN(a.u.str),
                    ZSTR_VAL(b.u.str), ZSTR_LEN(b.u.str)
                ) < 0;
            }
        } else if (sl_is_object_type(a) || sl_is_object_type(b)) {
            /* Objects are non-comparable in this VM model. */
            result = false;
        } else {
            double da = sl_val_to_compare_num(a);
            double db = sl_val_to_compare_num(b);
            result = da < db;  /* NaN comparisons are false */
        }
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_bool(result);
        DISPATCH();
    }

    TARGET(SL_OP_LTE): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        bool result;
        if (a.tag == SL_TAG_STRING && b.tag == SL_TAG_STRING) {
            double da = 0.0, db = 0.0;
            bool a_numeric = sl_str_try_numeric(a.u.str, &da);
            bool b_numeric = sl_str_try_numeric(b.u.str, &db);
            if (a_numeric && b_numeric) {
                result = da <= db;
            } else {
                result = zend_binary_strcmp(
                    ZSTR_VAL(a.u.str), ZSTR_LEN(a.u.str),
                    ZSTR_VAL(b.u.str), ZSTR_LEN(b.u.str)
                ) <= 0;
            }
        } else if (sl_is_object_type(a) || sl_is_object_type(b)) {
            result = false;
        } else {
            double da = sl_val_to_compare_num(a);
            double db = sl_val_to_compare_num(b);
            result = da <= db;
        }
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_bool(result);
        DISPATCH();
    }

    TARGET(SL_OP_GT): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        bool result;
        if (a.tag == SL_TAG_STRING && b.tag == SL_TAG_STRING) {
            double da = 0.0, db = 0.0;
            bool a_numeric = sl_str_try_numeric(a.u.str, &da);
            bool b_numeric = sl_str_try_numeric(b.u.str, &db);
            if (a_numeric && b_numeric) {
                result = da > db;
            } else {
                result = zend_binary_strcmp(
                    ZSTR_VAL(a.u.str), ZSTR_LEN(a.u.str),
                    ZSTR_VAL(b.u.str), ZSTR_LEN(b.u.str)
                ) > 0;
            }
        } else if (sl_is_object_type(a) || sl_is_object_type(b)) {
            result = false;
        } else {
            double da = sl_val_to_compare_num(a);
            double db = sl_val_to_compare_num(b);
            result = da > db;
        }
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_bool(result);
        DISPATCH();
    }

    TARGET(SL_OP_GTE): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        bool result;
        if (a.tag == SL_TAG_STRING && b.tag == SL_TAG_STRING) {
            double da = 0.0, db = 0.0;
            bool a_numeric = sl_str_try_numeric(a.u.str, &da);
            bool b_numeric = sl_str_try_numeric(b.u.str, &db);
            if (a_numeric && b_numeric) {
                result = da >= db;
            } else {
                result = zend_binary_strcmp(
                    ZSTR_VAL(a.u.str), ZSTR_LEN(a.u.str),
                    ZSTR_VAL(b.u.str), ZSTR_LEN(b.u.str)
                ) >= 0;
            }
        } else if (sl_is_object_type(a) || sl_is_object_type(b)) {
            result = false;
        } else {
            double da = sl_val_to_compare_num(a);
            double db = sl_val_to_compare_num(b);
            result = da >= db;
        }
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = sl_val_bool(result);
        DISPATCH();
    }

    /* ================================================================
     * String Concatenation (explicit opcode)
     * ================================================================ */

    TARGET(SL_OP_CONCAT): {
        sl_value b = stack[--sp];
        sl_value a = stack[sp - 1];
        sl_value result = sl_string_concat(a, b);
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(b);
        stack[sp - 1] = result;
        DISPATCH();
    }

    /* ================================================================
     * Variables
     * ================================================================ */

    TARGET(SL_OP_GET_LOCAL): {
        ENSURE_STACK(1);
        sl_value val = sl_env_get(env, names[opA[ci]]);
        if (UNEXPECTED(EG(exception))) {
            /* sl_env_get threw ReferenceError */
            zend_string *msg = zval_get_string(zend_read_property(
                zend_ce_exception, EG(exception), "message", sizeof("message") - 1, 1, NULL));
            zend_clear_exception();
            sl_vm_throw_type_error(vm, ZSTR_VAL(msg));
            zend_string_release(msg);
            SYNC_STATE();
            sl_vm_handle_throw(vm);
            if (UNEXPECTED(EG(exception))) return sl_val_undefined();
            RELOAD_STATE();
            DISPATCH();
        }
        stack[sp++] = val;
        DISPATCH();
    }

    TARGET(SL_OP_SET_LOCAL): {
        sl_value val = stack[--sp];
        sl_env_set(env, names[opA[ci]], val);
        SL_DELREF(val);
        if (UNEXPECTED(EG(exception))) {
            /* sl_env_set throws a PHP exception for const reassignment.
             * Let it propagate to PHP caller as-is. */
            SYNC_STATE();
            return sl_val_undefined();
        }
        DISPATCH();
    }

    TARGET(SL_OP_DEFINE_VAR): {
        sl_value val = stack[--sp];
        bool is_const = (opB[ci] == 2);
        sl_env_define(env, names[opA[ci]], val, is_const);
        SL_DELREF(val);
        DISPATCH();
    }

    TARGET(SL_OP_GET_REG): {
        ENSURE_STACK(1);
        sl_value v = regs[opA[ci]];
        SL_ADDREF(v);
        stack[sp++] = v;
        DISPATCH();
    }

    TARGET(SL_OP_SET_REG): {
        sl_value val = stack[--sp];
        uint32_t reg = (uint32_t)opA[ci];
        SL_DELREF(regs[reg]);
        regs[reg] = val;
        /* val ownership transferred to register -- no addref needed since
         * we popped it off the stack (which held the reference). */
        DISPATCH();
    }

    /* ================================================================
     * Control Flow
     * ================================================================ */

    TARGET(SL_OP_JUMP): {
        ip = (uint32_t)opA[ci];
        DISPATCH();
    }

    TARGET(SL_OP_JUMP_IF_FALSE): {
        sl_value v = stack[--sp];
        if (!sl_is_truthy(v)) {
            ip = (uint32_t)opA[ci];
        }
        SL_DELREF(v);
        DISPATCH();
    }

    TARGET(SL_OP_JUMP_IF_TRUE): {
        sl_value v = stack[--sp];
        if (sl_is_truthy(v)) {
            ip = (uint32_t)opA[ci];
        }
        SL_DELREF(v);
        DISPATCH();
    }

    TARGET(SL_OP_JUMP_IF_NOT_NULLISH): {
        sl_value v = stack[--sp];
        if (!SL_IS_NULLISH(v)) {
            ip = (uint32_t)opA[ci];
        }
        SL_DELREF(v);
        DISPATCH();
    }

    /* ================================================================
     * Exception Handling
     * ================================================================ */

    TARGET(SL_OP_SET_CATCH): {
        sl_vm_ensure_handlers(vm);
        sl_exception_handler *h = &vm->handlers[vm->handler_count++];
        h->catch_ip = (uint32_t)opA[ci];
        h->frame_count = vm->frame_count;
        h->sp = sp;
        h->env = env;
        SL_GC_ADDREF(env);
        DISPATCH();
    }

    TARGET(SL_OP_POP_CATCH): {
        if (vm->handler_count > 0) {
            sl_exception_handler *h = &vm->handlers[--vm->handler_count];
            if (SL_GC_DELREF(h->env) == 0) {
                sl_env_free(h->env);
            }
        }
        DISPATCH();
    }

    TARGET(SL_OP_THROW): {
        sl_value thrown = stack[--sp];
        sl_vm_throw(vm, thrown);
        SL_DELREF(thrown);  /* sl_vm_throw addreffed it */
        SYNC_STATE();
        sl_vm_handle_throw(vm);
        if (UNEXPECTED(EG(exception))) return sl_val_undefined();
        RELOAD_STATE();
        DISPATCH();
    }

    /* ================================================================
     * Scope
     * ================================================================ */

    TARGET(SL_OP_PUSH_SCOPE): {
        sl_environment *new_env = sl_env_extend(env);
        /* Keep frame->env synchronized with lexical scope transitions.
         * Transfer ownership of new_env's initial ref directly to frame->env. */
        if (SL_GC_DELREF(frame->env) == 0) {
            sl_env_free(frame->env);
        }
        frame->env = new_env;
        env = new_env;
        DISPATCH();
    }

    TARGET(SL_OP_POP_SCOPE): {
        if (!env->parent) {
            DISPATCH();
        }
        sl_environment *parent = env->parent;
        /* New frame owner reference for parent scope. */
        SL_GC_ADDREF(parent);
        if (SL_GC_DELREF(frame->env) == 0) {
            sl_env_free(frame->env);
        }
        frame->env = parent;
        env = parent;
        DISPATCH();
    }

    /* ================================================================
     * Property Tests / Delete
     * ================================================================ */

    TARGET(SL_OP_HAS_PROP): {
        sl_value obj = stack[--sp];     /* right operand (object) */
        sl_value key = stack[sp - 1];   /* left operand (key) */
        bool result = false;

        if (obj.tag == SL_TAG_OBJECT) {
            zend_string *skey = sl_to_js_string(key);
            result = sl_object_has_own(obj.u.obj, skey);
            zend_string_release(skey);
        } else if (obj.tag == SL_TAG_ARRAY) {
            if (key.tag == SL_TAG_INT) {
                zend_long idx = key.u.ival;
                result = (idx >= 0 && (uint32_t)idx < obj.u.arr->length);
            } else if (key.tag == SL_TAG_STRING) {
                zend_ulong idx;
                if (ZEND_HANDLE_NUMERIC(key.u.str, idx)) {
                    result = (idx < (zend_ulong)obj.u.arr->length);
                }
            }
        }

        SL_DELREF(stack[sp - 1]);
        SL_DELREF(obj);
        stack[sp - 1] = sl_val_bool(result);
        DISPATCH();
    }

    TARGET(SL_OP_INSTANCE_OF): {
        sl_value ctor = stack[--sp];    /* right operand (constructor) */
        sl_value obj = stack[sp - 1];   /* left operand (object) */
        bool result = (obj.tag == SL_TAG_OBJECT &&
                       ctor.tag == SL_TAG_CLOSURE &&
                       obj.u.obj->constructor == ctor.u.closure);
        SL_DELREF(stack[sp - 1]);
        SL_DELREF(ctor);
        stack[sp - 1] = sl_val_bool(result);
        DISPATCH();
    }

    TARGET(SL_OP_DELETE_PROP): {
        sl_value key = stack[--sp];
        sl_value obj = stack[--sp];

        if (obj.tag == SL_TAG_OBJECT) {
            zend_string *skey = sl_to_js_string(key);
            sl_object_delete(obj.u.obj, skey);
            zend_string_release(skey);
        } else if (obj.tag == SL_TAG_ARRAY && key.tag == SL_TAG_INT) {
            zend_long idx = key.u.ival;
            if (idx >= 0 && (uint32_t)idx < obj.u.arr->length) {
                SL_DELREF(obj.u.arr->elements[idx]);
                obj.u.arr->elements[idx] = sl_val_undefined();
            }
        }

        SL_DELREF(key);
        SL_DELREF(obj);
        ENSURE_STACK(1);
        stack[sp++] = sl_val_bool(true);
        DISPATCH();
    }

    /* ================================================================
     * Functions
     * ================================================================ */

    TARGET(SL_OP_MAKE_CLOSURE): {
        ENSURE_STACK(1);
        /* The constant at opA[ci] holds a FunctionDescriptor.
         * In the C constant pool this is stored as an sl_value with
         * tag SL_TAG_CLOSURE wrapping a prototype closure whose
         * descriptor is what we need; or as a raw pointer via the
         * union.  Handle both cases. */
        sl_value cv = consts[opA[ci]];
        sl_func_descriptor *fd;
        if (cv.tag == SL_TAG_CLOSURE) {
            fd = cv.u.closure->descriptor;
        } else {
            /* Raw descriptor pointer stored via the union by the
             * bytecode deserialization layer. */
            fd = (sl_func_descriptor *)(void *)cv.u.closure;
        }
        sl_js_closure *cls = sl_closure_new(fd, env);
        stack[sp++] = sl_val_closure(cls);
        DISPATCH();
    }

    TARGET(SL_OP_CALL): {
        SYNC_STATE();
        sl_vm_call(vm, (uint32_t)opA[ci]);
        CHECK_THROWN();
        RELOAD_STATE();
        DISPATCH();
    }

    TARGET(SL_OP_CALL_OPT): {
        uint32_t argc = (uint32_t)opA[ci];
        uint32_t callee_idx = sp - argc - 1;
        sl_value callee = stack[callee_idx];
        if (SL_IS_NULLISH(callee)) {
            /* Pop callee and args, push undefined */
            for (uint32_t i = callee_idx; i < sp; i++) {
                SL_DELREF(stack[i]);
            }
            sp = callee_idx;
            ENSURE_STACK(1);
            stack[sp++] = sl_val_undefined();
            DISPATCH();
        }
        SYNC_STATE();
        sl_vm_call(vm, argc);
        CHECK_THROWN();
        RELOAD_STATE();
        DISPATCH();
    }

    TARGET(SL_OP_RETURN): {
        SYNC_STATE();
        sl_vm_do_return(vm);

        if (vm->frame_count == 0 ||
            (vm->invoke_depth > 0 && vm->frame_count == vm->invoke_base_frame)) {
            /* Return from callback or top-level function during re-entrant run. */
            RELOAD_STATE();
            sp = vm->sp;
            goto halt;
        }

        RELOAD_STATE();
        DISPATCH();
    }

    TARGET(SL_OP_NEW): {
        SYNC_STATE();
        sl_vm_do_new(vm, (uint32_t)opA[ci]);
        CHECK_THROWN();
        RELOAD_STATE();
        DISPATCH();
    }

    TARGET(SL_OP_CALL_SPREAD): {
        /* Stack: [callee, argsArray] */
        sl_value args_val = stack[--sp];
        sl_value callee = stack[--sp];

        if (callee.tag == SL_TAG_CLOSURE) {
            sl_js_array *arr = args_val.u.arr;
            vm->sp = sp;
            SYNC_STATE();
            sl_vm_call_closure(vm, callee.u.closure, arr->elements, arr->length, NULL);
            CHECK_THROWN();
            RELOAD_STATE();
        } else if (callee.tag == SL_TAG_NATIVE) {
            sl_js_array *arr = args_val.u.arr;
            sl_value result = sl_vm_call_native(vm, callee.u.native, arr->elements, arr->length);
            ENSURE_STACK(1);
            SL_ADDREF(result);
            stack[sp++] = result;
        } else {
            SL_DELREF(callee);
            SL_DELREF(args_val);
            sl_vm_throw_type_error(vm, "TypeError: is not a function");
            SYNC_STATE();
            sl_vm_handle_throw(vm);
            if (UNEXPECTED(EG(exception))) return sl_val_undefined();
            RELOAD_STATE();
            DISPATCH();
        }

        SL_DELREF(callee);
        SL_DELREF(args_val);
        DISPATCH();
    }

    TARGET(SL_OP_CALL_SPREAD_OPT): {
        sl_value args_val = stack[--sp];
        sl_value callee = stack[--sp];

        if (SL_IS_NULLISH(callee)) {
            SL_DELREF(callee);
            SL_DELREF(args_val);
            ENSURE_STACK(1);
            stack[sp++] = sl_val_undefined();
            DISPATCH();
        }

        if (callee.tag == SL_TAG_CLOSURE) {
            sl_js_array *arr = args_val.u.arr;
            vm->sp = sp;
            SYNC_STATE();
            sl_vm_call_closure(vm, callee.u.closure, arr->elements, arr->length, NULL);
            CHECK_THROWN();
            RELOAD_STATE();
        } else if (callee.tag == SL_TAG_NATIVE) {
            sl_js_array *arr = args_val.u.arr;
            sl_value result = sl_vm_call_native(vm, callee.u.native, arr->elements, arr->length);
            ENSURE_STACK(1);
            SL_ADDREF(result);
            stack[sp++] = result;
        } else {
            SL_DELREF(callee);
            SL_DELREF(args_val);
            sl_vm_throw_type_error(vm, "TypeError: is not a function");
            SYNC_STATE();
            sl_vm_handle_throw(vm);
            if (UNEXPECTED(EG(exception))) return sl_val_undefined();
            RELOAD_STATE();
            DISPATCH();
        }

        SL_DELREF(callee);
        SL_DELREF(args_val);
        DISPATCH();
    }

    TARGET(SL_OP_NEW_SPREAD): {
        /* Stack: [callee, argsArray] */
        sl_value args_val = stack[--sp];
        sl_value callee = stack[--sp];

        if (callee.tag == SL_TAG_NATIVE) {
            sl_js_array *arr = args_val.u.arr;
            sl_value result = sl_vm_call_native(vm, callee.u.native, arr->elements, arr->length);
            ENSURE_STACK(1);
            SL_ADDREF(result);
            stack[sp++] = result;
        } else if (callee.tag == SL_TAG_CLOSURE) {
            sl_js_array *arr = args_val.u.arr;
            sl_js_object *new_obj = sl_object_new();
            new_obj->constructor = callee.u.closure;
            SL_GC_ADDREF(callee.u.closure);
            new_obj->prototype = sl_closure_get_prototype(callee.u.closure, true);
            if (new_obj->prototype) {
                SL_GC_ADDREF(new_obj->prototype);
            }

            vm->sp = sp;
            SYNC_STATE();
            sl_vm_call_closure(vm, callee.u.closure, arr->elements, arr->length, new_obj);
            CHECK_THROWN();
            RELOAD_STATE();
        } else {
            SL_DELREF(callee);
            SL_DELREF(args_val);
            sl_vm_throw_type_error(vm, "TypeError: is not a constructor");
            SYNC_STATE();
            sl_vm_handle_throw(vm);
            if (UNEXPECTED(EG(exception))) return sl_val_undefined();
            RELOAD_STATE();
            DISPATCH();
        }

        SL_DELREF(callee);
        SL_DELREF(args_val);
        DISPATCH();
    }

    /* ================================================================
     * Arrays / Objects
     * ================================================================ */

    TARGET(SL_OP_MAKE_ARRAY): {
        uint32_t count = (uint32_t)opA[ci];
        sl_js_array *arr = sl_array_new(count);
        arr->length = count;

        /* Pop elements in reverse (last pushed is highest index) */
        for (int32_t j = (int32_t)count - 1; j >= 0; j--) {
            sl_value v = stack[--sp];
            arr->elements[j] = v;
            /* Ownership transfers from stack to array -- array_new_from
             * addrefs, but here we're moving, not copying. The stack
             * held a ref which we transfer to the array, so no extra
             * addref/delref needed. */
        }
        ENSURE_STACK(1);
        sl_value av = sl_val_array(arr);
        stack[sp++] = av;
        DISPATCH();
    }

    TARGET(SL_OP_MAKE_OBJECT): {
        uint32_t count = (uint32_t)opA[ci];
        sl_js_object *obj = sl_object_new();

        /* Stack has pairs: [key0, val0, key1, val1, ...]
         * Pushed in order, so key0 is deepest.  Pop in reverse. */
        /* Allocate temp arrays for keys and values */
        zend_string **keys = NULL;
        sl_value *vals = NULL;
        if (count > 0) {
            keys = emalloc(sizeof(zend_string *) * count);
            vals = emalloc(sizeof(sl_value) * count);

            for (int32_t j = (int32_t)count - 1; j >= 0; j--) {
                vals[j] = stack[--sp];
                sl_value kv = stack[--sp];
                keys[j] = sl_to_js_string(kv);
                SL_DELREF(kv);
            }

            for (uint32_t j = 0; j < count; j++) {
                sl_object_set(obj, keys[j], vals[j]);
                SL_DELREF(vals[j]); /* set addrefs internally */
                zend_string_release(keys[j]);
            }

            efree(keys);
            efree(vals);
        }

        ENSURE_STACK(1);
        stack[sp++] = sl_val_object(obj);
        DISPATCH();
    }

    TARGET(SL_OP_GET_PROPERTY): {
        sl_value key = stack[--sp];
        sl_value obj = stack[--sp];

        if (UNEXPECTED(SL_IS_NULLISH(obj))) {
            const char *type = (obj.tag == SL_TAG_NULL) ? "null" : "undefined";
            zend_string *ks = sl_to_js_string(key);
            char buf[256];
            int len = snprintf(buf, sizeof(buf),
                "TypeError: Cannot read properties of %s (reading '%s')", type, ZSTR_VAL(ks));
            zend_string_release(ks);
            SL_DELREF(key);
            SL_DELREF(obj);

            zend_string *msg = zend_string_init(buf, len, 0);
            sl_vm_throw(vm, sl_val_string(msg));
            SYNC_STATE();
            sl_vm_handle_throw(vm);
            if (UNEXPECTED(EG(exception))) return sl_val_undefined();
            RELOAD_STATE();
            DISPATCH();
        }

        sl_value result = sl_get_property(vm, obj, key);
        SL_DELREF(key);
        SL_DELREF(obj);
        ENSURE_STACK(1);
        stack[sp++] = result;
        DISPATCH();
    }

    TARGET(SL_OP_GET_PROPERTY_OPT): {
        sl_value key = stack[--sp];
        sl_value obj = stack[--sp];

        if (SL_IS_NULLISH(obj)) {
            SL_DELREF(key);
            SL_DELREF(obj);
            ENSURE_STACK(1);
            stack[sp++] = sl_val_undefined();
            DISPATCH();
        }

        sl_value result = sl_get_property(vm, obj, key);
        SL_DELREF(key);
        SL_DELREF(obj);
        ENSURE_STACK(1);
        stack[sp++] = result;
        DISPATCH();
    }

    TARGET(SL_OP_SET_PROPERTY): {
        sl_value val = stack[--sp];
        sl_value key = stack[--sp];
        sl_value obj = stack[--sp];

        sl_set_property(vm, obj, key, val);

        SL_DELREF(key);
        SL_DELREF(obj);
        /* Push the assigned value back */
        ENSURE_STACK(1);
        stack[sp++] = val;
        /* val ownership: we popped it from stack (1 ref), passed to
         * set_property which addrefs internally.  We push it back,
         * so the stack ref is restored. No extra addref needed. */
        DISPATCH();
    }

    TARGET(SL_OP_ARRAY_PUSH): {
        sl_value val = stack[--sp];
        /* The array is at TOS (sp-1) */
        sl_js_array *arr = stack[sp - 1].u.arr;
        sl_array_push(arr, val);
        SL_DELREF(val); /* push addrefs, we release the stack's ref */
        DISPATCH();
    }

    TARGET(SL_OP_ARRAY_SPREAD): {
        sl_value iterable = stack[--sp];
        sl_js_array *arr = stack[sp - 1].u.arr;

        if (iterable.tag == SL_TAG_ARRAY) {
            sl_js_array *src = iterable.u.arr;
            sl_array_ensure_capacity(arr, arr->length + src->length);
            for (uint32_t j = 0; j < src->length; j++) {
                sl_array_push(arr, src->elements[j]);
            }
        } else if (iterable.tag == SL_TAG_STRING) {
            /* Spread string into individual characters */
            const char *s = ZSTR_VAL(iterable.u.str);
            size_t len = ZSTR_LEN(iterable.u.str);
            /* Simple byte-level iteration for ASCII; for full UTF-8
             * we'd need mbstring, but match PHP VM behavior */
            for (size_t j = 0; j < len; j++) {
                zend_string *ch = zend_string_init(&s[j], 1, 0);
                sl_value cv = sl_val_string(ch);
                sl_array_push(arr, cv);
            }
        }

        SL_DELREF(iterable);
        DISPATCH();
    }

    /* ================================================================
     * Halt
     * ================================================================ */

    TARGET(SL_OP_HALT): {
        goto halt;
    }

#if USE_COMPUTED_GOTO
    lbl_DEFAULT: {
        /* Unknown opcode */
        char buf[64];
        snprintf(buf, sizeof(buf), "Unknown opcode: %d", op);
        sl_vm_throw_type_error(vm, buf);
        SYNC_STATE();
        sl_vm_handle_throw(vm);
        if (UNEXPECTED(EG(exception))) return sl_val_undefined();
        RELOAD_STATE();
        DISPATCH();
    }
#else
        default: {
            char buf[64];
            snprintf(buf, sizeof(buf), "Unknown opcode: %d", op);
            sl_vm_throw_type_error(vm, buf);
            SYNC_STATE();
            sl_vm_handle_throw(vm);
            if (UNEXPECTED(EG(exception))) return sl_val_undefined();
            RELOAD_STATE();
            break;
        }
        } /* end switch */
    } /* end for */
#endif

halt:
    /* Sync final sp */
    vm->sp = sp;

    /* Sync environment back to frame */
    if (vm->frame_count > 0) {
        frame = &vm->frames[vm->frame_count - 1];
        if (frame->env != env) {
            SL_GC_ADDREF(env);
            if (SL_GC_DELREF(frame->env) == 0) {
                sl_env_free(frame->env);
            }
            frame->env = env;
        }
    }

    if (sp > 0) {
        sl_value result = stack[--vm->sp];
        return result;
    }
    return sl_val_undefined();
}

/* ================================================================
 * Public API: sl_vm_execute
 * ================================================================ */

sl_value sl_vm_execute(sl_vm *vm, sl_compiled_script *script) {
    if (!vm->global_env) {
        sl_vm_create_global_env(vm);
    }

    uint32_t base_frame_count = vm->frame_count;
    uint32_t base_handler_count = vm->handler_count;
    uint32_t base_sp = vm->sp;

    sl_vm_push_frame(vm, script->main, vm->global_env, NULL);

    sl_value result = sl_vm_run(vm);

    /* Ensure top-level execute never leaks VM state across runs. */
    while (vm->frame_count > base_frame_count) {
        sl_vm_pop_frame(vm);
    }
    while (vm->handler_count > base_handler_count) {
        sl_exception_handler *h = &vm->handlers[--vm->handler_count];
        if (SL_GC_DELREF(h->env) == 0) {
            sl_env_free(h->env);
        }
    }
    vm->sp = base_sp;

    return result;
}

/* ================================================================
 * Re-entrant execution: sl_vm_invoke_function
 *
 * Used by native functions (e.g., Array.map callback) to call back
 * into the VM.
 * ================================================================ */

sl_value sl_vm_invoke_function(sl_vm *vm, sl_value callee, sl_value *args, uint32_t argc) {
    if (callee.tag == SL_TAG_NATIVE) {
        return sl_vm_call_native(vm, callee.u.native, args, argc);
    }

    if (callee.tag != SL_TAG_CLOSURE) {
        sl_vm_throw_type_error(vm, "TypeError: is not a function");
        return sl_val_undefined();
    }

    sl_js_closure *closure = callee.u.closure;
    sl_func_descriptor *desc = closure->descriptor;
    sl_environment *call_env = sl_env_extend(closure->captured_env);

    /* Save the current frame count so the inner sl_vm_run knows when to
     * stop: it must halt when frame_count drops back to this level. */
    uint32_t saved_frame_count = vm->frame_count;
    uint32_t saved_invoke_base = vm->invoke_base_frame;
    uint32_t saved_handler_count = vm->handler_count;
    uint32_t saved_sp = vm->sp;

    sl_vm_push_frame(vm, desc, call_env, NULL);

    /* Release extra ref from env_extend (push_frame took its own) */
    if (SL_GC_DELREF(call_env) == 0) {
        sl_env_free(call_env);
    }

    sl_call_frame *frame = &vm->frames[vm->frame_count - 1];
    sl_vm_bind_params(vm, desc, call_env, frame, args, argc);

    vm->invoke_depth++;
    vm->invoke_base_frame = saved_frame_count;
    sl_value result = sl_vm_run(vm);
    vm->invoke_depth--;
    vm->invoke_base_frame = saved_invoke_base;

    /* Defensive cleanup for abnormal control-flow paths. */
    while (vm->frame_count > saved_frame_count) {
        sl_vm_pop_frame(vm);
    }
    while (vm->handler_count > saved_handler_count) {
        sl_exception_handler *h = &vm->handlers[--vm->handler_count];
        if (SL_GC_DELREF(h->env) == 0) {
            sl_env_free(h->env);
        }
    }
    while (vm->sp > saved_sp) {
        vm->sp--;
        SL_DELREF(vm->stack[vm->sp]);
    }

    return result;
}

/* ================================================================
 * Global Environment Setup
 * ================================================================ */

void sl_vm_create_global_env(sl_vm *vm) {
    if (vm->global_env) {
        if (SL_GC_DELREF(vm->global_env) == 0) {
            sl_env_free(vm->global_env);
        }
        vm->global_env = NULL;
    }

    sl_environment *env = sl_env_new(NULL);
    vm->global_env = env;
    sl_builtins_setup(vm, env);
}

/* ================================================================
 * Inject PHP variables into the global environment
 * ================================================================ */

void sl_vm_inject_globals(sl_vm *vm, HashTable *globals) {
    if (!vm->global_env) {
        sl_vm_create_global_env(vm);
    }

    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL(globals, key, val) {
        if (key) {
            sl_value sv = sl_zval_to_value(val);
            sl_env_define(vm->global_env, key, sv, false);
            SL_DELREF(sv); /* env_define addrefs */
        }
    } ZEND_HASH_FOREACH_END();
}

/* ================================================================
 * Get console output
 * ================================================================ */

zend_string *sl_vm_get_output(sl_vm *vm) {
    if (vm->output.c) {
        smart_string_0(&vm->output);
        return zend_string_init(vm->output.c, vm->output.len, 0);
    }
    return zend_string_init("", 0, 0);
}
