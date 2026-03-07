#ifndef PHP_SCRIPTLITE_H
#define PHP_SCRIPTLITE_H

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "ext/standard/php_smart_string.h"

#define PHP_SCRIPTLITE_VERSION "0.1.0"
#define PHP_SCRIPTLITE_EXTNAME "scriptlite"

extern zend_module_entry scriptlite_module_entry;
#define phpext_scriptlite_ptr &scriptlite_module_entry

/* Forward declarations */
typedef struct _sl_js_array sl_js_array;
typedef struct _sl_js_object sl_js_object;
typedef struct _sl_js_closure sl_js_closure;
typedef struct _sl_native_func sl_native_func;
typedef struct _sl_js_date sl_js_date;
typedef struct _sl_js_regex sl_js_regex;
typedef struct _sl_func_descriptor sl_func_descriptor;
typedef struct _sl_compiled_script sl_compiled_script;
typedef struct _sl_environment sl_environment;
typedef struct _sl_vm sl_vm;

/* ---- AST class entry cache ---- */
typedef struct _sl_ast_class_cache {
    zend_class_entry *ce_program;
    zend_class_entry *ce_expression_stmt;
    zend_class_entry *ce_binary_expr;
    zend_class_entry *ce_identifier;
    zend_class_entry *ce_number_literal;
    zend_class_entry *ce_string_literal;
    zend_class_entry *ce_boolean_literal;
    zend_class_entry *ce_null_literal;
    zend_class_entry *ce_undefined_literal;
    zend_class_entry *ce_call_expr;
    zend_class_entry *ce_member_expr;
    zend_class_entry *ce_member_assign_expr;
    zend_class_entry *ce_function_expr;
    zend_class_entry *ce_function_decl;
    zend_class_entry *ce_var_declaration;
    zend_class_entry *ce_var_declaration_list;
    zend_class_entry *ce_destructuring_decl;
    zend_class_entry *ce_block_stmt;
    zend_class_entry *ce_if_stmt;
    zend_class_entry *ce_while_stmt;
    zend_class_entry *ce_do_while_stmt;
    zend_class_entry *ce_for_stmt;
    zend_class_entry *ce_for_of_stmt;
    zend_class_entry *ce_for_in_stmt;
    zend_class_entry *ce_switch_stmt;
    zend_class_entry *ce_switch_case;
    zend_class_entry *ce_return_stmt;
    zend_class_entry *ce_break_stmt;
    zend_class_entry *ce_continue_stmt;
    zend_class_entry *ce_throw_stmt;
    zend_class_entry *ce_try_catch_stmt;
    zend_class_entry *ce_catch_clause;
    zend_class_entry *ce_assign_expr;
    zend_class_entry *ce_unary_expr;
    zend_class_entry *ce_update_expr;
    zend_class_entry *ce_logical_expr;
    zend_class_entry *ce_conditional_expr;
    zend_class_entry *ce_sequence_expr;
    zend_class_entry *ce_object_literal;
    zend_class_entry *ce_array_literal;
    zend_class_entry *ce_template_literal;
    zend_class_entry *ce_regex_literal;
    zend_class_entry *ce_new_expr;
    zend_class_entry *ce_this_expr;
    zend_class_entry *ce_typeof_expr;
    zend_class_entry *ce_void_expr;
    zend_class_entry *ce_delete_expr;
    zend_class_entry *ce_spread_element;
    zend_class_entry *ce_var_kind;
} sl_ast_class_cache;

/* ---- Module globals ---- */
ZEND_BEGIN_MODULE_GLOBALS(scriptlite)
    sl_ast_class_cache ast_cache;
    bool ast_cache_initialized;
    HashTable *ast_synthetic_entries;

    /* Interned strings for AST property names */
    zend_string *str_body;
    zend_string *str_statements;
    zend_string *str_expression;
    zend_string *str_left;
    zend_string *str_right;
    zend_string *str_operator;
    zend_string *str_name;
    zend_string *str_value;
    zend_string *str_params;
    zend_string *str_defaults;
    zend_string *str_condition;
    zend_string *str_consequent;
    zend_string *str_alternate;
    zend_string *str_test;
    zend_string *str_object;
    zend_string *str_property;
    zend_string *str_computed;
    zend_string *str_callee;
    zend_string *str_arguments;
    zend_string *str_init;
    zend_string *str_update;
    zend_string *str_declarations;
    zend_string *str_kind;
    zend_string *str_prefix;
    zend_string *str_argument;
    zend_string *str_operand;
    zend_string *str_restParam;
    zend_string *str_elements;
    zend_string *str_properties;
    zend_string *str_key;
    zend_string *str_shorthand;
    zend_string *str_parts;
    zend_string *str_expressions;
    zend_string *str_pattern;
    zend_string *str_flags;
    zend_string *str_cases;
    zend_string *str_discriminant;
    zend_string *str_catchClause;
    zend_string *str_finalizer;
    zend_string *str_param;
    zend_string *str_iterable;
    zend_string *str_variable;
    zend_string *str_bindings;
    zend_string *str_targets;
    zend_string *str_initializers;
    zend_string *str_rest;

    /* Interned strings for runtime property names */
    zend_string *str_length;
    zend_string *str_prototype;
    zend_string *str_constructor;
    zend_string *str_undefined;
    zend_string *str_null;
    zend_string *str_boolean;
    zend_string *str_number;
    zend_string *str_string;
    zend_string *str_function;
    zend_string *str_object_type;
ZEND_END_MODULE_GLOBALS(scriptlite)

ZEND_EXTERN_MODULE_GLOBALS(scriptlite)
#define SL_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(scriptlite, v)

/* PHP class entries */
extern zend_class_entry *ce_sl_compiled_script;
extern zend_class_entry *ce_sl_compiler;
extern zend_class_entry *ce_sl_virtual_machine;
extern zend_class_entry *ce_sl_engine;

#endif /* PHP_SCRIPTLITE_H */
