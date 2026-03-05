#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_scriptlite.h"
#include "sl_value.h"
#include "sl_runtime.h"
#include "sl_environment.h"
#include "sl_vm.h"
#include "sl_compiler.h"
#include "sl_ast_reader.h"
#include "sl_builtins.h"
#include "zend_smart_str.h"

/* ---- Module globals ---- */
ZEND_DECLARE_MODULE_GLOBALS(scriptlite)

/* ---- Class entries ---- */
zend_class_entry *ce_sl_compiled_script;
zend_class_entry *ce_sl_compiler;
zend_class_entry *ce_sl_virtual_machine;

/* ---- Object handlers ---- */
static zend_object_handlers sl_compiled_script_handlers;
static zend_object_handlers sl_vm_handlers;

/* ============================================================
 * CompiledScript PHP object wrapper
 * ============================================================ */

typedef struct {
    sl_compiled_script *script;
    zend_object std;
} sl_compiled_script_obj;

static inline sl_compiled_script_obj *sl_compiled_script_from_obj(zend_object *obj) {
    return (sl_compiled_script_obj*)((char*)obj - XtOffsetOf(sl_compiled_script_obj, std));
}

static zend_object *sl_compiled_script_create(zend_class_entry *ce) {
    sl_compiled_script_obj *intern = zend_object_alloc(sizeof(sl_compiled_script_obj), ce);
    intern->script = NULL;
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &sl_compiled_script_handlers;
    return &intern->std;
}

static void sl_compiled_script_free_obj(zend_object *obj) {
    sl_compiled_script_obj *intern = sl_compiled_script_from_obj(obj);
    if (intern->script) {
        if (SL_GC_DELREF(intern->script) == 0) {
            sl_compiled_script_free(intern->script);
        }
        intern->script = NULL;
    }
    zend_object_std_dtor(obj);
}

/* ============================================================
 * VirtualMachine PHP object wrapper
 * ============================================================ */

typedef struct {
    sl_vm *vm;
    zend_object std;
} sl_vm_obj;

static inline sl_vm_obj *sl_vm_from_obj(zend_object *obj) {
    return (sl_vm_obj*)((char*)obj - XtOffsetOf(sl_vm_obj, std));
}

static zend_object *sl_vm_create(zend_class_entry *ce) {
    sl_vm_obj *intern = zend_object_alloc(sizeof(sl_vm_obj), ce);
    intern->vm = NULL;
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &sl_vm_handlers;
    return &intern->std;
}

static void sl_vm_free_obj(zend_object *obj) {
    sl_vm_obj *intern = sl_vm_from_obj(obj);
    if (intern->vm) {
        sl_vm_free(intern->vm);
        intern->vm = NULL;
    }
    zend_object_std_dtor(obj);
}

static bool sl_parser_cache_init(void) {
    if (SL_G(parser_cache_initialized)) {
        return true;
    }

    zend_string *class_name = zend_string_init("ScriptLite\\Ast\\Parser", sizeof("ScriptLite\\Ast\\Parser") - 1, 0);
    zend_class_entry *ce = zend_lookup_class(class_name);
    zend_string_release(class_name);

    if (!ce) {
        return false;
    }

    SL_G(ce_parser) = ce;
    SL_G(parser_cache_initialized) = true;
    return true;
}

static sl_compiled_script *sl_compile_ast(zval *program_zval) {
    sl_compiler compiler;
    sl_compiler_init(&compiler);
    sl_compiled_script *script = sl_compiler_compile(&compiler, program_zval);
    sl_compiler_destroy(&compiler);
    return script;
}

static sl_compiled_script *sl_compile_source(zend_string *source) {
    if (!sl_parser_cache_init()) {
        zend_throw_exception(zend_ce_exception,
            "Failed to initialize ScriptLite AST parser class. "
            "Ensure ScriptLite\\\\Ast\\\\Parser is autoloadable.", 0);
        return NULL;
    }

    if (!sl_ast_cache_init()) {
        zend_throw_exception(zend_ce_exception,
            "Failed to initialize ScriptLite AST class cache. "
            "Ensure ScriptLite PHP classes are autoloaded.", 0);
        return NULL;
    }

    zval parser_obj;
    zval source_arg;
    zval parsed;
    ZVAL_UNDEF(&parser_obj);
    ZVAL_UNDEF(&source_arg);
    ZVAL_UNDEF(&parsed);

    object_init_ex(&parser_obj, SL_G(ce_parser));
    ZVAL_STR_COPY(&source_arg, source);

    zend_call_method_with_1_params(
        Z_OBJ(parser_obj), SL_G(ce_parser), NULL, "__construct", NULL, &source_arg
    );
    if (EG(exception)) {
        goto fail;
    }

    zval *parsed_ret = zend_call_method_with_0_params(
        Z_OBJ(parser_obj), SL_G(ce_parser), NULL, "parse", &parsed
    );

    if (EG(exception) || !parsed_ret || !sl_ast_is(parsed_ret, SL_G(ast_cache).ce_program)) {
        if (!EG(exception)) {
            zend_throw_exception(
                zend_ce_type_error,
                "Parser did not return ScriptLite\\Ast\\Program", 0
            );
        }
        goto fail;
    }

    zval_ptr_dtor(&source_arg);
    zval_ptr_dtor(&parser_obj);

    sl_compiled_script *script = sl_compile_ast(&parsed);
    zval_ptr_dtor(&parsed);

    if (!script) {
        zend_throw_exception(zend_ce_exception,
            "Compilation failed", 0);
        return NULL;
    }

    return script;

fail:
    if (Z_TYPE(parsed) != IS_UNDEF) {
        zval_ptr_dtor(&parsed);
    }
    if (Z_TYPE(source_arg) != IS_UNDEF) {
        zval_ptr_dtor(&source_arg);
    }
    if (Z_TYPE(parser_obj) != IS_UNDEF) {
        zval_ptr_dtor(&parser_obj);
    }
    return NULL;
}

/* ============================================================
 * PHP Method: ScriptLiteNative\Compiler::compile(Program $program): CompiledScript
 * ============================================================ */

PHP_METHOD(ScriptLiteNative_Compiler, compile) {
    zval *program_zval;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT(program_zval)
    ZEND_PARSE_PARAMETERS_END();

    /* Ensure AST cache is initialized */
    if (!sl_ast_cache_init()) {
        zend_throw_exception(zend_ce_exception,
            "Failed to initialize ScriptLite AST class cache. "
            "Ensure ScriptLite PHP classes are autoloaded.", 0);
        RETURN_THROWS();
    }

    /* Verify it's a Program instance */
    if (!sl_ast_is(program_zval, SL_G(ast_cache).ce_program)) {
        zend_throw_exception(zend_ce_type_error,
            "Argument #1 must be an instance of ScriptLite\\Ast\\Program", 0);
        RETURN_THROWS();
    }

    sl_compiled_script *script = sl_compile_ast(program_zval);

    if (!script) {
        zend_throw_exception(zend_ce_exception,
            "Compilation failed", 0);
        RETURN_THROWS();
    }

    /* Wrap in PHP object */
    object_init_ex(return_value, ce_sl_compiled_script);
    sl_compiled_script_obj *intern = sl_compiled_script_from_obj(Z_OBJ_P(return_value));
    intern->script = script;
}

/* ============================================================
 * PHP Method: ScriptLiteNative\VirtualMachine::execute(CompiledScript|string $script, array $globals = []): mixed
 * ============================================================ */

PHP_METHOD(ScriptLiteNative_VirtualMachine, execute) {
    zval *input_zval;
    zval *globals_zval = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(input_zval)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY(globals_zval)
    ZEND_PARSE_PARAMETERS_END();

    sl_compiled_script *script = NULL;

    if (Z_TYPE_P(input_zval) == IS_OBJECT && Z_OBJCE_P(input_zval) == ce_sl_compiled_script) {
        sl_compiled_script_obj *script_obj = sl_compiled_script_from_obj(Z_OBJ_P(input_zval));
        if (!script_obj->script) {
            zend_throw_exception(zend_ce_exception,
                "CompiledScript is empty or corrupted", 0);
            RETURN_THROWS();
        }
        script = script_obj->script;
    } else if (Z_TYPE_P(input_zval) == IS_STRING) {
        script = sl_compile_source(Z_STR_P(input_zval));
        if (!script) {
            RETURN_THROWS();
        }
    } else {
        zend_throw_exception(zend_ce_type_error,
            "Argument #1 must be ScriptLiteNative\\CompiledScript or source string", 0);
        RETURN_THROWS();
    }

    sl_vm_obj *vm_intern = sl_vm_from_obj(Z_OBJ_P(getThis()));

    /* Create VM if not already created */
    if (!vm_intern->vm) {
        vm_intern->vm = sl_vm_new();
    }

    sl_vm *vm = vm_intern->vm;

    /* Set up global environment */
    sl_vm_create_global_env(vm);

    /* Inject user globals */
    if (globals_zval && Z_TYPE_P(globals_zval) == IS_ARRAY) {
        sl_vm_inject_globals(vm, Z_ARRVAL_P(globals_zval));
    }

    /* Execute */
    sl_value result = sl_vm_execute(vm, script);

    /* Convert result to PHP zval */
    sl_value_to_zval(&result, return_value);
    SL_DELREF(result);
}

/* ============================================================
 * PHP Method: ScriptLiteNative\VirtualMachine::getOutput(): string
 * ============================================================ */

PHP_METHOD(ScriptLiteNative_VirtualMachine, getOutput) {
    ZEND_PARSE_PARAMETERS_NONE();

    sl_vm_obj *intern = sl_vm_from_obj(Z_OBJ_P(getThis()));
    if (intern->vm) {
        zend_string *output = sl_vm_get_output(intern->vm);
        if (output) {
            RETURN_STR(output);
        }
    }
    RETURN_EMPTY_STRING();
}

/* ============================================================
 * Argument info (method signatures)
 * ============================================================ */

ZEND_BEGIN_ARG_INFO_EX(arginfo_compiler_compile, 0, 0, 1)
    ZEND_ARG_OBJ_INFO(0, program, ScriptLite\\Ast\\Program, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_vm_execute, 0, 0, 1)
    ZEND_ARG_INFO(0, script)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, globals, IS_ARRAY, 1, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_vm_getOutput, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()


/* ============================================================
 * Method tables
 * ============================================================ */

static const zend_function_entry sl_compiler_methods[] = {
    PHP_ME(ScriptLiteNative_Compiler, compile, arginfo_compiler_compile, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

static const zend_function_entry sl_vm_methods[] = {
    PHP_ME(ScriptLiteNative_VirtualMachine, execute, arginfo_vm_execute, ZEND_ACC_PUBLIC)
    PHP_ME(ScriptLiteNative_VirtualMachine, getOutput, arginfo_vm_getOutput, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

static const zend_function_entry sl_compiled_script_methods[] = {
    PHP_FE_END
};

/* ============================================================
 * Module initialization
 * ============================================================ */

static void sl_init_interned_strings(void) {
#define INTERN(field, str) SL_G(field) = zend_string_init_interned(str, strlen(str), 1)

    /* AST property names */
    INTERN(str_body, "body");
    INTERN(str_statements, "statements");
    INTERN(str_expression, "expression");
    INTERN(str_left, "left");
    INTERN(str_right, "right");
    INTERN(str_operator, "operator");
    INTERN(str_name, "name");
    INTERN(str_value, "value");
    INTERN(str_params, "params");
    INTERN(str_defaults, "defaults");
    INTERN(str_condition, "condition");
    INTERN(str_consequent, "consequent");
    INTERN(str_alternate, "alternate");
    INTERN(str_test, "test");
    INTERN(str_object, "object");
    INTERN(str_property, "property");
    INTERN(str_computed, "computed");
    INTERN(str_callee, "callee");
    INTERN(str_arguments, "arguments");
    INTERN(str_init, "init");
    INTERN(str_update, "update");
    INTERN(str_declarations, "declarations");
    INTERN(str_kind, "kind");
    INTERN(str_prefix, "prefix");
    INTERN(str_argument, "argument");
    INTERN(str_operand, "operand");
    INTERN(str_restParam, "restParam");
    INTERN(str_elements, "elements");
    INTERN(str_properties, "properties");
    INTERN(str_key, "key");
    INTERN(str_shorthand, "shorthand");
    INTERN(str_parts, "quasis");
    INTERN(str_expressions, "expressions");
    INTERN(str_pattern, "pattern");
    INTERN(str_flags, "flags");
    INTERN(str_cases, "cases");
    INTERN(str_discriminant, "discriminant");
    INTERN(str_catchClause, "catchClause");
    INTERN(str_finalizer, "finalizer");
    INTERN(str_param, "param");
    INTERN(str_iterable, "iterable");
    INTERN(str_variable, "variable");
    INTERN(str_bindings, "bindings");
    INTERN(str_targets, "targets");
    INTERN(str_initializers, "initializers");
    INTERN(str_rest, "rest");

    /* Runtime property names */
    INTERN(str_length, "length");
    INTERN(str_prototype, "prototype");
    INTERN(str_constructor, "constructor");
    INTERN(str_undefined, "undefined");
    INTERN(str_null, "null");
    INTERN(str_boolean, "boolean");
    INTERN(str_number, "number");
    INTERN(str_string, "string");
    INTERN(str_function, "function");
    INTERN(str_object_type, "object");

#undef INTERN
}

static void sl_release_interned_strings(void) {
#define RELEASE(field) do { \
    if (SL_G(field)) { \
        zend_string_release(SL_G(field)); \
        SL_G(field) = NULL; \
    } \
} while (0)

    RELEASE(str_body);
    RELEASE(str_statements);
    RELEASE(str_expression);
    RELEASE(str_left);
    RELEASE(str_right);
    RELEASE(str_operator);
    RELEASE(str_name);
    RELEASE(str_value);
    RELEASE(str_params);
    RELEASE(str_defaults);
    RELEASE(str_condition);
    RELEASE(str_consequent);
    RELEASE(str_alternate);
    RELEASE(str_test);
    RELEASE(str_object);
    RELEASE(str_property);
    RELEASE(str_computed);
    RELEASE(str_callee);
    RELEASE(str_arguments);
    RELEASE(str_init);
    RELEASE(str_update);
    RELEASE(str_declarations);
    RELEASE(str_kind);
    RELEASE(str_prefix);
    RELEASE(str_argument);
    RELEASE(str_operand);
    RELEASE(str_restParam);
    RELEASE(str_elements);
    RELEASE(str_properties);
    RELEASE(str_key);
    RELEASE(str_shorthand);
    RELEASE(str_parts);
    RELEASE(str_expressions);
    RELEASE(str_pattern);
    RELEASE(str_flags);
    RELEASE(str_cases);
    RELEASE(str_discriminant);
    RELEASE(str_catchClause);
    RELEASE(str_finalizer);
    RELEASE(str_param);
    RELEASE(str_iterable);
    RELEASE(str_variable);
    RELEASE(str_bindings);
    RELEASE(str_targets);
    RELEASE(str_initializers);
    RELEASE(str_rest);
    RELEASE(str_length);
    RELEASE(str_prototype);
    RELEASE(str_constructor);
    RELEASE(str_undefined);
    RELEASE(str_null);
    RELEASE(str_boolean);
    RELEASE(str_number);
    RELEASE(str_string);
    RELEASE(str_function);
    RELEASE(str_object_type);

#undef RELEASE
}

PHP_MINIT_FUNCTION(scriptlite) {
    zend_class_entry ce;

    /* Register ScriptLiteNative\CompiledScript */
    INIT_NS_CLASS_ENTRY(ce, "ScriptLiteNative", "CompiledScript", sl_compiled_script_methods);
    ce_sl_compiled_script = zend_register_internal_class(&ce);
    ce_sl_compiled_script->create_object = sl_compiled_script_create;
    ce_sl_compiled_script->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES;
    memcpy(&sl_compiled_script_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
    sl_compiled_script_handlers.offset = XtOffsetOf(sl_compiled_script_obj, std);
    sl_compiled_script_handlers.free_obj = sl_compiled_script_free_obj;
    sl_compiled_script_handlers.clone_obj = NULL;

    /* Register ScriptLiteNative\Compiler */
    INIT_NS_CLASS_ENTRY(ce, "ScriptLiteNative", "Compiler", sl_compiler_methods);
    ce_sl_compiler = zend_register_internal_class(&ce);
    ce_sl_compiler->ce_flags |= ZEND_ACC_FINAL;

    /* Register ScriptLiteNative\VirtualMachine */
    INIT_NS_CLASS_ENTRY(ce, "ScriptLiteNative", "VirtualMachine", sl_vm_methods);
    ce_sl_virtual_machine = zend_register_internal_class(&ce);
    ce_sl_virtual_machine->create_object = sl_vm_create;
    ce_sl_virtual_machine->ce_flags |= ZEND_ACC_FINAL;
    memcpy(&sl_vm_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
    sl_vm_handlers.offset = XtOffsetOf(sl_vm_obj, std);
    sl_vm_handlers.free_obj = sl_vm_free_obj;
    sl_vm_handlers.clone_obj = NULL;

    /* Init interned strings */
    sl_init_interned_strings();

    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(scriptlite) {
    sl_release_interned_strings();
    return SUCCESS;
}

PHP_RINIT_FUNCTION(scriptlite) {
    SL_G(ast_cache_initialized) = false;
    SL_G(parser_cache_initialized) = false;
    return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(scriptlite) {
    return SUCCESS;
}

PHP_MINFO_FUNCTION(scriptlite) {
    php_info_print_table_start();
    php_info_print_table_header(2, "ScriptLite Native Extension", "enabled");
    php_info_print_table_row(2, "Version", PHP_SCRIPTLITE_VERSION);
    php_info_print_table_row(2, "Components", "Compiler + VM (C), Lexer + Parser (PHP)");
    php_info_print_table_end();
}

static PHP_GINIT_FUNCTION(scriptlite) {
#if defined(COMPILE_DL_SCRIPTLITE) && defined(ZTS)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
    memset(scriptlite_globals, 0, sizeof(*scriptlite_globals));
}

/* ============================================================
 * Module entry
 * ============================================================ */

zend_module_entry scriptlite_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_SCRIPTLITE_EXTNAME,
    NULL,                       /* functions (we use classes instead) */
    PHP_MINIT(scriptlite),
    PHP_MSHUTDOWN(scriptlite),
    PHP_RINIT(scriptlite),
    PHP_RSHUTDOWN(scriptlite),
    PHP_MINFO(scriptlite),
    PHP_SCRIPTLITE_VERSION,
    PHP_MODULE_GLOBALS(scriptlite),
    PHP_GINIT(scriptlite),
    NULL,                       /* GSHUTDOWN */
    NULL,                       /* post_deactivate */
    STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_SCRIPTLITE
#ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#endif
ZEND_GET_MODULE(scriptlite)
#endif
