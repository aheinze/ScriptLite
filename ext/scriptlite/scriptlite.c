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
#include "sl_parser_runtime_bundle.h"
#include "zend_smart_str.h"
#include "ext/standard/base64.h"
#include "ext/spl/spl_exceptions.h"
#include <string.h>

/* ---- Module globals ---- */
ZEND_DECLARE_MODULE_GLOBALS(scriptlite)

/* ---- Class entries ---- */
zend_class_entry *ce_sl_compiled_script;
zend_class_entry *ce_sl_compiler;
zend_class_entry *ce_sl_virtual_machine;
zend_class_entry *ce_sl_engine;

/* ---- Object handlers ---- */
static zend_object_handlers sl_compiled_script_handlers;
static zend_object_handlers sl_vm_handlers;
static zend_object_handlers sl_engine_handlers;
typedef struct _sl_engine_obj sl_engine_obj;

static bool sl_scriptlite_class_exists(const char *name);
static bool sl_scriptlite_eval_embedded_parser_runtime(void);
static sl_compiled_script *sl_compile_source(zend_string *source);
static zend_string *sl_scriptlite_make_transpiled_payload(zend_string *source);
static inline bool sl_scriptlite_is_transpiler_payload(zval *payload);
static bool sl_scriptlite_extract_transpiled_source(zval *payload, zend_string **source);
static bool sl_engine_execute_transpiler_payload(sl_engine_obj *engine, zval *payload, zval *globals, zval *return_value);
static void sl_engine_execute(sl_engine_obj *engine, zval *script, zval *globals, zval *return_value);

/* ---- Helpers ------------------------------------------------- */

#define SCRIPTLITE_TRANSPILER_PAYLOAD_PREFIX "__SL_NATIVE_TP__:"
#define SCRIPTLITE_TRANSPILER_PAYLOAD_PREFIX_LEN (sizeof(SCRIPTLITE_TRANSPILER_PAYLOAD_PREFIX) - 1)

#define SCRIPTLITE_BACKEND_AUTO "auto"
#define SCRIPTLITE_BACKEND_NATIVE "native"
#define SCRIPTLITE_BACKEND_VM "vm"
#define SCRIPTLITE_BACKEND_TRANSPILER "transpiler"

static inline bool sl_engine_backend_is_native(const zend_string *backend) {
    return !backend
        || zend_string_equals_literal(backend, SCRIPTLITE_BACKEND_AUTO)
        || zend_string_equals_literal(backend, SCRIPTLITE_BACKEND_NATIVE)
        || zend_string_equals_literal(backend, SCRIPTLITE_BACKEND_VM);
}

static inline bool sl_engine_backend_is_transpiler(const zend_string *backend) {
    if (!backend) {
        return false;
    }
    return zend_string_equals_literal(backend, SCRIPTLITE_BACKEND_TRANSPILER);
}

static bool sl_engine_validate_backend(const zend_string *backend) {
    if (sl_engine_backend_is_native(backend)) {
        return true;
    }
    if (sl_engine_backend_is_transpiler(backend)) {
        return true;
    }

    zend_throw_exception(
        spl_ce_InvalidArgumentException,
        "Unsupported backend for ScriptLiteExt\\Engine. "
        "Use BACKEND_AUTO, BACKEND_NATIVE, BACKEND_VM or BACKEND_TRANSPILER in the native extension build.",
        0
    );
    return false;
}

static zend_string *sl_scriptlite_make_transpiled_payload(zend_string *source) {
    zend_string *encoded = php_base64_encode((const unsigned char *)ZSTR_VAL(source), ZSTR_LEN(source));
    if (!encoded) {
        return NULL;
    }

    smart_str payload = {0};
    smart_str_appendl(&payload, SCRIPTLITE_TRANSPILER_PAYLOAD_PREFIX, SCRIPTLITE_TRANSPILER_PAYLOAD_PREFIX_LEN);
    smart_str_appendl(&payload, ZSTR_VAL(encoded), ZSTR_LEN(encoded));
    smart_str_0(&payload);
    zend_string_release(encoded);

    if (!payload.s) {
        return zend_string_init("", 0, 0);
    }
    return payload.s;
}

static bool sl_scriptlite_extract_transpiled_source(zval *payload, zend_string **source) {
    if (!payload || Z_TYPE_P(payload) != IS_STRING) {
        return false;
    }

    const char *value = Z_STRVAL_P(payload);
    size_t len = Z_STRLEN_P(payload);

    if (len < SCRIPTLITE_TRANSPILER_PAYLOAD_PREFIX_LEN
            || memcmp(value, SCRIPTLITE_TRANSPILER_PAYLOAD_PREFIX, SCRIPTLITE_TRANSPILER_PAYLOAD_PREFIX_LEN) != 0) {
        return false;
    }

    const unsigned char *encoded = (const unsigned char *)(value + SCRIPTLITE_TRANSPILER_PAYLOAD_PREFIX_LEN);
    size_t encoded_len = len - SCRIPTLITE_TRANSPILER_PAYLOAD_PREFIX_LEN;
    if (encoded_len == 0) {
        *source = zend_string_init("", 0, 0);
        return true;
    }

    *source = php_base64_decode_ex(encoded, encoded_len, false);
    return *source != NULL;
}

static inline bool sl_scriptlite_is_transpiler_payload(zval *payload) {
    if (!payload || Z_TYPE_P(payload) != IS_STRING) {
        return false;
    }
    return Z_STRLEN_P(payload) >= SCRIPTLITE_TRANSPILER_PAYLOAD_PREFIX_LEN
        && memcmp(Z_STRVAL_P(payload), SCRIPTLITE_TRANSPILER_PAYLOAD_PREFIX, SCRIPTLITE_TRANSPILER_PAYLOAD_PREFIX_LEN) == 0;
}

static bool sl_engine_execute_transpiler_payload(sl_engine_obj *engine, zval *payload, zval *globals, zval *return_value) {
    zend_string *source = NULL;
    if (!sl_scriptlite_extract_transpiled_source(payload, &source)) {
        zend_throw_exception(
            zend_ce_exception,
            "Invalid ScriptLite transpiler payload. Use ScriptLite\\Engine::transpile() to produce a compatible payload.",
            0
        );
        return false;
    }

    zval source_arg;
    ZVAL_STR(&source_arg, source);
    sl_engine_execute(engine, &source_arg, globals, return_value);
    zend_string_release(source);
    return !EG(exception);
}

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

/* ============================================================
 * ScriptLiteExt\Engine PHP object wrapper
 * ============================================================ */

struct _sl_engine_obj {
    bool use_native;
    zval vm_obj;
    zend_object std;
};

static inline sl_engine_obj *sl_engine_from_obj(zend_object *obj) {
    return (sl_engine_obj*)((char*)obj - XtOffsetOf(sl_engine_obj, std));
}

static zend_object *sl_engine_create(zend_class_entry *ce) {
    sl_engine_obj *intern = zend_object_alloc(sizeof(sl_engine_obj), ce);
    intern->use_native = true;
    ZVAL_UNDEF(&intern->vm_obj);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &sl_engine_handlers;
    return &intern->std;
}

static void sl_engine_free_obj(zend_object *obj) {
    sl_engine_obj *intern = sl_engine_from_obj(obj);
    if (Z_TYPE(intern->vm_obj) != IS_UNDEF) {
        zval_ptr_dtor(&intern->vm_obj);
    }
    zend_object_std_dtor(obj);
}

static zend_object *sl_engine_ensure_vm(sl_engine_obj *engine) {
    if (Z_TYPE(engine->vm_obj) == IS_UNDEF) {
        object_init_ex(&engine->vm_obj, ce_sl_virtual_machine);
    }
    return Z_OBJ(engine->vm_obj);
}

static void sl_engine_execute(sl_engine_obj *engine, zval *script, zval *globals, zval *return_value) {
    zval *vm = &engine->vm_obj;
    sl_engine_ensure_vm(engine);

    if (globals) {
        zend_call_method_with_2_params(
            Z_OBJ_P(vm), ce_sl_virtual_machine, NULL,
            "execute", return_value,
            script,
            globals
        );
    } else {
        zend_call_method_with_1_params(
            Z_OBJ_P(vm), ce_sl_virtual_machine, NULL,
            "execute", return_value,
            script
        );
    }
}

static bool sl_scriptlite_class_exists(const char *name) {
    zend_string *class_name = zend_string_init(name, strlen(name), 0);
    zend_class_entry *ce = zend_lookup_class(class_name);
    zend_string_release(class_name);
    return ce != NULL;
}

static bool sl_scriptlite_eval_embedded_parser_runtime(void) {
    zend_string *bundle = php_base64_decode_ex(
        (const unsigned char *)sl_parser_runtime_bundle_b64,
        sl_parser_runtime_bundle_b64_len,
        false
    );
    if (!bundle) {
        zend_throw_exception(
            zend_ce_exception,
            "Failed to decode embedded ScriptLite parser runtime bundle.",
            0
        );
        return false;
    }

    zend_result result = zend_eval_stringl(
        ZSTR_VAL(bundle),
        ZSTR_LEN(bundle),
        NULL,
        "scriptlite_parser_runtime"
    );
    zend_string_release(bundle);
    if (result == FAILURE) {
        if (!EG(exception)) {
            zend_throw_exception(
                zend_ce_exception,
                "Failed to execute embedded ScriptLite parser runtime.",
                0
            );
        }
        return false;
    }
    return true;
}

bool sl_scriptlite_bootstrap_parser_runtime(void) {
    if (sl_scriptlite_class_exists("ScriptLite\\Ast\\Parser")) {
        return true;
    }

    if (!sl_scriptlite_eval_embedded_parser_runtime()) {
        return false;
    }

    if (!sl_scriptlite_class_exists("ScriptLite\\Ast\\Parser")) {
        if (!EG(exception)) {
            zend_throw_exception(
                zend_ce_exception,
                "ScriptLite parser bootstrap did not define ScriptLite\\Ast\\Parser.",
                0
            );
        }
        return false;
    }
    return true;
}

/* ============================================================
 * PHP Method: ScriptLiteExt\Engine::__construct(bool $useNative = true)
 * ============================================================ */

PHP_METHOD(ScriptLite_Engine, __construct) {
    bool use_native = true;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(use_native)
    ZEND_PARSE_PARAMETERS_END();

    sl_engine_obj *engine = sl_engine_from_obj(Z_OBJ_P(getThis()));
    engine->use_native = use_native;
}

/* ============================================================
 * PHP Method: ScriptLiteExt\Engine::compile(string $source, string $backend = "auto", array $globals = []): CompiledScript
 * ============================================================ */

PHP_METHOD(ScriptLite_Engine, compile) {
    zend_string *source;
    zend_string *backend = NULL;
    zval *globals = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STR(source)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR_OR_NULL(backend)
        Z_PARAM_ARRAY(globals)
    ZEND_PARSE_PARAMETERS_END();

    (void) globals;

    if (!sl_engine_validate_backend(backend)) {
        RETURN_THROWS();
    }

    sl_engine_obj *engine = sl_engine_from_obj(Z_OBJ_P(getThis()));
    if (!engine->use_native) {
        zend_throw_exception(
            zend_ce_exception,
            "Native Engine execution is disabled for this ScriptLiteExt\\Engine instance.",
            0
        );
        RETURN_THROWS();
    }

    if (sl_engine_backend_is_transpiler(backend)) {
        zend_string *payload = sl_scriptlite_make_transpiled_payload(source);
        if (!payload) {
            zend_throw_exception(
                zend_ce_exception,
                "Failed to generate transpiler payload for ScriptLiteExt\\Engine::compile().",
                0
            );
            RETURN_THROWS();
        }
        RETURN_STR(payload);
    }

    sl_compiled_script *script = sl_compile_source(source);
    if (!script) {
        RETURN_THROWS();
    }

    object_init_ex(return_value, ce_sl_compiled_script);
    sl_compiled_script_obj *compiled = sl_compiled_script_from_obj(Z_OBJ_P(return_value));
    compiled->script = script;
}

/* ============================================================
 * PHP Method: ScriptLiteExt\Engine::eval(string $source, array $globals = [], string $backend = "auto")
 * ============================================================ */

PHP_METHOD(ScriptLite_Engine, eval) {
    zend_string *source;
    zval *globals = NULL;
    zend_string *backend = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STR(source)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY(globals)
        Z_PARAM_STR(backend)
    ZEND_PARSE_PARAMETERS_END();

    if (!sl_engine_validate_backend(backend)) {
        RETURN_THROWS();
    }

    sl_engine_obj *engine = sl_engine_from_obj(Z_OBJ_P(getThis()));
    if (!engine->use_native) {
        zend_throw_exception(
            zend_ce_exception,
            "Native Engine execution is disabled for this ScriptLiteExt\\Engine instance.",
            0
        );
        RETURN_THROWS();
    }

    if (sl_engine_backend_is_transpiler(backend)) {
        zend_string *payload = sl_scriptlite_make_transpiled_payload(source);
        if (!payload) {
            zend_throw_exception(
                zend_ce_exception,
                "Failed to generate ScriptLite transpiler payload.",
                0
            );
            RETURN_THROWS();
        }

        zval transpiled;
        ZVAL_STR_COPY(&transpiled, payload);
        sl_engine_execute_transpiler_payload(engine, &transpiled, globals, return_value);
        zval_ptr_dtor(&transpiled);
        zend_string_release(payload);
        return;
    }

    zval source_arg;
    ZVAL_STR(&source_arg, source);
    sl_engine_execute(engine, &source_arg, globals, return_value);
}

/* ============================================================
 * PHP Method: ScriptLiteExt\Engine::transpile(string $source, array $globals = []): string
 * ============================================================ */

PHP_METHOD(ScriptLite_Engine, transpile) {
    zend_string *source;
    zval *globals = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(source)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY(globals)
    ZEND_PARSE_PARAMETERS_END();

    (void) globals;

    zend_string *payload = sl_scriptlite_make_transpiled_payload(source);
    if (!payload) {
        zend_throw_exception(
            zend_ce_exception,
            "Failed to generate ScriptLite transpiler payload.",
            0
        );
        return;
    }
    RETURN_STR(payload);
}

/* ============================================================
 * PHP Method: ScriptLiteExt\Engine::runTranspiled(string $script, array $globals = []): mixed
 * ============================================================ */

PHP_METHOD(ScriptLite_Engine, runTranspiled) {
    zval *script;
    zval *globals = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(script)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY(globals)
    ZEND_PARSE_PARAMETERS_END();

    sl_engine_obj *engine = sl_engine_from_obj(Z_OBJ_P(getThis()));

    if (Z_TYPE_P(script) == IS_STRING && sl_scriptlite_is_transpiler_payload(script)) {
        sl_engine_execute_transpiler_payload(engine, script, globals, return_value);
        return;
    }

    zend_throw_exception(
        zend_ce_exception,
        "Invalid ScriptLite transpiled payload passed to runTranspiled().",
        0
    );
}

/* ============================================================
 * PHP Method: ScriptLiteExt\Engine::evalTranspiled(string $script, array $globals = []): mixed
 * ============================================================ */

PHP_METHOD(ScriptLite_Engine, evalTranspiled) {
    zval *script;
    zval *globals = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(script)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY(globals)
    ZEND_PARSE_PARAMETERS_END();

    if (globals) {
        zend_call_method_with_2_params(
            Z_OBJ_P(getThis()),
            NULL,
            NULL,
            "runTranspiled",
            return_value,
            script,
            globals
        );
    } else {
        zend_call_method_with_1_params(
            Z_OBJ_P(getThis()),
            NULL,
            NULL,
            "runTranspiled",
            return_value,
            script
        );
    }
}

/* ============================================================
 * PHP Method: ScriptLiteExt\Engine::transpileAndEval(string $source, array $globals = [], array $opts = []): mixed
 * ============================================================ */

PHP_METHOD(ScriptLite_Engine, transpileAndEval) {
    zend_string *source;
    zval *globals = NULL;
    zval *opts = NULL;
    zval *use_eval_opt = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STR(source)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY(globals)
        Z_PARAM_ARRAY_OR_NULL(opts)
    ZEND_PARSE_PARAMETERS_END();

    zend_string *payload = sl_scriptlite_make_transpiled_payload(source);
    if (!payload) {
        zend_throw_exception(
            zend_ce_exception,
            "Failed to generate ScriptLite transpiler payload.",
            0
        );
        return;
    }

    if (opts && Z_TYPE_P(opts) == IS_ARRAY) {
        use_eval_opt = zend_hash_str_find(Z_ARRVAL_P(opts), "use_eval", sizeof("use_eval") - 1);
    }

    if (use_eval_opt && zend_is_true(use_eval_opt)) {
        zval payload_zv;
        ZVAL_STR(&payload_zv, payload);
        if (globals) {
            zend_call_method_with_2_params(
                Z_OBJ_P(getThis()),
                NULL,
                NULL,
                "evalTranspiled",
                return_value,
                &payload_zv,
                globals
            );
        } else {
            zend_call_method_with_1_params(
                Z_OBJ_P(getThis()),
                NULL,
                NULL,
                "evalTranspiled",
                return_value,
                &payload_zv
            );
        }
    } else {
        zval payload_zv;
        ZVAL_STR(&payload_zv, payload);
        if (globals) {
            zend_call_method_with_2_params(
                Z_OBJ_P(getThis()),
                NULL,
                NULL,
                "runTranspiled",
                return_value,
                &payload_zv,
                globals
            );
        } else {
            zend_call_method_with_1_params(
                Z_OBJ_P(getThis()),
                NULL,
                NULL,
                "runTranspiled",
                return_value,
                &payload_zv
            );
        }
    }
    zend_string_release(payload);
}

/* ============================================================
 * PHP Method: ScriptLiteExt\Engine::run(CompiledScript|string $script, array $globals = []): mixed
 * ============================================================ */

PHP_METHOD(ScriptLite_Engine, run) {
    zval *script;
    zval *globals = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(script)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY(globals)
    ZEND_PARSE_PARAMETERS_END();

    sl_engine_obj *engine = sl_engine_from_obj(Z_OBJ_P(getThis()));
    if (!engine->use_native) {
        zend_throw_exception(
            zend_ce_exception,
            "Native Engine execution is disabled for this ScriptLiteExt\\Engine instance.",
            0
        );
        RETURN_THROWS();
    }

    if (Z_TYPE_P(script) != IS_OBJECT && Z_TYPE_P(script) != IS_STRING) {
        zend_throw_exception(
            zend_ce_type_error,
            "Argument #1 must be ScriptLiteExt\\CompiledScript or source string",
            0
        );
        RETURN_THROWS();
    }

    if (Z_TYPE_P(script) == IS_OBJECT && Z_OBJCE_P(script) != ce_sl_compiled_script) {
        zend_throw_exception(
            zend_ce_type_error,
            "Argument #1 must be ScriptLiteExt\\CompiledScript or source string",
            0
        );
        RETURN_THROWS();
    }

    if (Z_TYPE_P(script) == IS_STRING && sl_scriptlite_is_transpiler_payload(script)) {
        sl_engine_execute_transpiler_payload(engine, script, globals, return_value);
        return;
    }

    sl_engine_execute(engine, script, globals, return_value);
}

/* ============================================================
 * PHP Method: ScriptLiteExt\Engine::getOutput(): string
 * ============================================================ */

PHP_METHOD(ScriptLite_Engine, getOutput) {
    ZEND_PARSE_PARAMETERS_NONE();

    sl_engine_obj *engine = sl_engine_from_obj(Z_OBJ_P(getThis()));
    if (Z_TYPE(engine->vm_obj) == IS_OBJECT) {
        zval *vm = &engine->vm_obj;
        zend_call_method_with_0_params(
            Z_OBJ_P(vm),
            ce_sl_virtual_machine,
            NULL,
            "getOutput",
            return_value
        );
        return;
    }

    RETURN_EMPTY_STRING();
}

static bool sl_parser_cache_init(void) {
    if (SL_G(parser_cache_initialized)) {
        return true;
    }

    if (!sl_scriptlite_bootstrap_parser_runtime()) {
        return false;
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
 * PHP Method: ScriptLiteExt\Compiler::compile(Program $program): CompiledScript
 * ============================================================ */

PHP_METHOD(ScriptLiteExt_Compiler, compile) {
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
 * PHP Method: ScriptLiteExt\VirtualMachine::execute(CompiledScript|string $script, array $globals = []): mixed
 * ============================================================ */

PHP_METHOD(ScriptLiteExt_VirtualMachine, execute) {
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
            "Argument #1 must be ScriptLiteExt\\CompiledScript or source string", 0);
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
 * PHP Method: ScriptLiteExt\VirtualMachine::getOutput(): string
 * ============================================================ */

PHP_METHOD(ScriptLiteExt_VirtualMachine, getOutput) {
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
    PHP_ME(ScriptLiteExt_Compiler, compile, arginfo_compiler_compile, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

static const zend_function_entry sl_vm_methods[] = {
    PHP_ME(ScriptLiteExt_VirtualMachine, execute, arginfo_vm_execute, ZEND_ACC_PUBLIC)
    PHP_ME(ScriptLiteExt_VirtualMachine, getOutput, arginfo_vm_getOutput, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

static const zend_function_entry sl_compiled_script_methods[] = {
    PHP_FE_END
};

ZEND_BEGIN_ARG_INFO_EX(arginfo_engine_construct, 0, 0, 0)
    ZEND_ARG_TYPE_INFO(0, useNative, _IS_BOOL, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_engine_compile, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, source, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, backend, IS_STRING, 1)
    ZEND_ARG_TYPE_INFO(0, globals, IS_ARRAY, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_engine_eval, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, source, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, globals, IS_ARRAY, 1, "[]")
    ZEND_ARG_TYPE_INFO(0, backend, IS_STRING, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_engine_transpile, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, source, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, globals, IS_ARRAY, 1, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_engine_transpileAndEval, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, source, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, globals, IS_ARRAY, 1, "[]")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, opts, IS_ARRAY, 1, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_engine_run_transpiled, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, source, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, globals, IS_ARRAY, 1, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_engine_eval_transpiled, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, source, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, globals, IS_ARRAY, 1, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_engine_run, 0, 0, 1)
    ZEND_ARG_INFO(0, script)
    ZEND_ARG_TYPE_INFO(0, globals, IS_ARRAY, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_engine_get_output, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry sl_engine_methods[] = {
    PHP_ME(ScriptLite_Engine, __construct, arginfo_engine_construct, ZEND_ACC_PUBLIC)
    PHP_ME(ScriptLite_Engine, compile, arginfo_engine_compile, ZEND_ACC_PUBLIC)
    PHP_ME(ScriptLite_Engine, eval, arginfo_engine_eval, ZEND_ACC_PUBLIC)
    PHP_ME(ScriptLite_Engine, transpile, arginfo_engine_transpile, ZEND_ACC_PUBLIC)
    PHP_ME(ScriptLite_Engine, runTranspiled, arginfo_engine_run_transpiled, ZEND_ACC_PUBLIC)
    PHP_ME(ScriptLite_Engine, evalTranspiled, arginfo_engine_eval_transpiled, ZEND_ACC_PUBLIC)
    PHP_ME(ScriptLite_Engine, transpileAndEval, arginfo_engine_transpileAndEval, ZEND_ACC_PUBLIC)
    PHP_ME(ScriptLite_Engine, run, arginfo_engine_run, ZEND_ACC_PUBLIC)
    PHP_ME(ScriptLite_Engine, getOutput, arginfo_engine_get_output, ZEND_ACC_PUBLIC)
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

    /* Register ScriptLiteExt\CompiledScript */
    INIT_NS_CLASS_ENTRY(ce, "ScriptLiteExt", "CompiledScript", sl_compiled_script_methods);
    ce_sl_compiled_script = zend_register_internal_class(&ce);
    ce_sl_compiled_script->create_object = sl_compiled_script_create;
    ce_sl_compiled_script->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES;
    memcpy(&sl_compiled_script_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
    sl_compiled_script_handlers.offset = XtOffsetOf(sl_compiled_script_obj, std);
    sl_compiled_script_handlers.free_obj = sl_compiled_script_free_obj;
    sl_compiled_script_handlers.clone_obj = NULL;

    /* Register ScriptLiteExt\Compiler */
    INIT_NS_CLASS_ENTRY(ce, "ScriptLiteExt", "Compiler", sl_compiler_methods);
    ce_sl_compiler = zend_register_internal_class(&ce);
    ce_sl_compiler->ce_flags |= ZEND_ACC_FINAL;

    /* Register ScriptLiteExt\VirtualMachine */
    INIT_NS_CLASS_ENTRY(ce, "ScriptLiteExt", "VirtualMachine", sl_vm_methods);
    ce_sl_virtual_machine = zend_register_internal_class(&ce);
    ce_sl_virtual_machine->create_object = sl_vm_create;
    ce_sl_virtual_machine->ce_flags |= ZEND_ACC_FINAL;
    memcpy(&sl_vm_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
    sl_vm_handlers.offset = XtOffsetOf(sl_vm_obj, std);
    sl_vm_handlers.free_obj = sl_vm_free_obj;
    sl_vm_handlers.clone_obj = NULL;

    /* Legacy aliases for backward compatibility. */
    if (zend_register_class_alias_ex(
            "ScriptLiteNative\\CompiledScript",
            sizeof("ScriptLiteNative\\CompiledScript") - 1,
            ce_sl_compiled_script,
            1
        ) == FAILURE
    ) {
        return FAILURE;
    }
    if (zend_register_class_alias_ex(
            "ScriptLiteNative\\Compiler",
            sizeof("ScriptLiteNative\\Compiler") - 1,
            ce_sl_compiler,
            1
        ) == FAILURE
    ) {
        return FAILURE;
    }
    if (zend_register_class_alias_ex(
            "ScriptLiteNative\\VirtualMachine",
            sizeof("ScriptLiteNative\\VirtualMachine") - 1,
            ce_sl_virtual_machine,
            1
        ) == FAILURE
    ) {
        return FAILURE;
    }

    /* Register ScriptLiteExt\Engine (extension frontend, no conflict with userland ScriptLite\Engine) */
    INIT_NS_CLASS_ENTRY(ce, "ScriptLiteExt", "Engine", sl_engine_methods);
    ce_sl_engine = zend_register_internal_class(&ce);
    ce_sl_engine->create_object = sl_engine_create;
    ce_sl_engine->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES;
    memcpy(&sl_engine_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
    sl_engine_handlers.offset = XtOffsetOf(sl_engine_obj, std);
    sl_engine_handlers.free_obj = sl_engine_free_obj;
    sl_engine_handlers.clone_obj = NULL;
    zend_declare_class_constant_stringl(
        ce_sl_engine,
        "BACKEND_AUTO",
        sizeof("BACKEND_AUTO") - 1,
        SCRIPTLITE_BACKEND_AUTO,
        sizeof(SCRIPTLITE_BACKEND_AUTO) - 1
    );
    zend_declare_class_constant_stringl(
        ce_sl_engine,
        "BACKEND_NATIVE",
        sizeof("BACKEND_NATIVE") - 1,
        SCRIPTLITE_BACKEND_NATIVE,
        sizeof(SCRIPTLITE_BACKEND_NATIVE) - 1
    );
    zend_declare_class_constant_stringl(
        ce_sl_engine,
        "BACKEND_VM",
        sizeof("BACKEND_VM") - 1,
        SCRIPTLITE_BACKEND_VM,
        sizeof(SCRIPTLITE_BACKEND_VM) - 1
    );
    zend_declare_class_constant_stringl(
        ce_sl_engine,
        "BACKEND_TRANSPILER",
        sizeof("BACKEND_TRANSPILER") - 1,
        SCRIPTLITE_BACKEND_TRANSPILER,
        sizeof(SCRIPTLITE_BACKEND_TRANSPILER) - 1
    );

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
    php_info_print_table_row(2, "Components", "Compiler + VM + Embedded Parser Runtime (all in C extension)");
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
