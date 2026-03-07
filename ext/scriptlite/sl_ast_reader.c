#include "sl_ast_reader.h"
#include "sl_value.h"
#include <string.h>

static zend_class_entry *sl_ast_lookup_or_synthetic(const char *classname) {
    zend_string *class_name = zend_string_init(classname, strlen(classname), 0);
    zend_class_entry *ce = zend_lookup_class(class_name);
    zend_string_release(class_name);

    if (ce) {
        return ce;
    }

    /*
     * Native parser mode: AST classes may not be loaded at all.
     * Create a minimal synthetic class-entry carrying only the class name,
     * so sl_ast_is() can still resolve node kinds by short-name.
     */
    ce = emalloc(sizeof(zend_class_entry));
    memset(ce, 0, sizeof(zend_class_entry));
    ce->name = zend_string_init(classname, strlen(classname), 0);

    if (!SL_G(ast_synthetic_entries)) {
        ALLOC_HASHTABLE(SL_G(ast_synthetic_entries));
        zend_hash_init(SL_G(ast_synthetic_entries), 16, NULL, NULL, 0);
    }
    zend_hash_next_index_insert_ptr(SL_G(ast_synthetic_entries), ce);

    return ce;
}

/**
 * Initialize the AST class entry cache.
 * Called lazily on first use (RINIT or first compile call).
 * In native-parser mode, missing classes are represented by synthetic
 * class-entry placeholders keyed by canonical class name.
 */
bool sl_ast_cache_init(void) {
    if (SL_G(ast_cache_initialized)) {
        return true;
    }

    sl_ast_class_cache *cache = &SL_G(ast_cache);

#define LOOKUP_CE(field, classname) do { \
    cache->field = sl_ast_lookup_or_synthetic(classname); \
} while(0)

    LOOKUP_CE(ce_program,             "ScriptLite\\Ast\\Program");
    LOOKUP_CE(ce_expression_stmt,     "ScriptLite\\Ast\\ExpressionStmt");
    LOOKUP_CE(ce_binary_expr,         "ScriptLite\\Ast\\BinaryExpr");
    LOOKUP_CE(ce_identifier,          "ScriptLite\\Ast\\Identifier");
    LOOKUP_CE(ce_number_literal,      "ScriptLite\\Ast\\NumberLiteral");
    LOOKUP_CE(ce_string_literal,      "ScriptLite\\Ast\\StringLiteral");
    LOOKUP_CE(ce_boolean_literal,     "ScriptLite\\Ast\\BooleanLiteral");
    LOOKUP_CE(ce_null_literal,        "ScriptLite\\Ast\\NullLiteral");
    LOOKUP_CE(ce_undefined_literal,   "ScriptLite\\Ast\\UndefinedLiteral");
    LOOKUP_CE(ce_call_expr,           "ScriptLite\\Ast\\CallExpr");
    LOOKUP_CE(ce_member_expr,         "ScriptLite\\Ast\\MemberExpr");
    LOOKUP_CE(ce_member_assign_expr,  "ScriptLite\\Ast\\MemberAssignExpr");
    LOOKUP_CE(ce_function_expr,       "ScriptLite\\Ast\\FunctionExpr");
    LOOKUP_CE(ce_function_decl,       "ScriptLite\\Ast\\FunctionDeclaration");
    LOOKUP_CE(ce_var_declaration,     "ScriptLite\\Ast\\VarDeclaration");
    LOOKUP_CE(ce_var_declaration_list,"ScriptLite\\Ast\\VarDeclarationList");
    LOOKUP_CE(ce_destructuring_decl,  "ScriptLite\\Ast\\DestructuringDeclaration");
    LOOKUP_CE(ce_block_stmt,          "ScriptLite\\Ast\\BlockStmt");
    LOOKUP_CE(ce_if_stmt,             "ScriptLite\\Ast\\IfStmt");
    LOOKUP_CE(ce_while_stmt,          "ScriptLite\\Ast\\WhileStmt");
    LOOKUP_CE(ce_do_while_stmt,       "ScriptLite\\Ast\\DoWhileStmt");
    LOOKUP_CE(ce_for_stmt,            "ScriptLite\\Ast\\ForStmt");
    LOOKUP_CE(ce_for_of_stmt,         "ScriptLite\\Ast\\ForOfStmt");
    LOOKUP_CE(ce_for_in_stmt,         "ScriptLite\\Ast\\ForInStmt");
    LOOKUP_CE(ce_switch_stmt,         "ScriptLite\\Ast\\SwitchStmt");
    LOOKUP_CE(ce_switch_case,         "ScriptLite\\Ast\\SwitchCase");
    LOOKUP_CE(ce_return_stmt,         "ScriptLite\\Ast\\ReturnStmt");
    LOOKUP_CE(ce_break_stmt,          "ScriptLite\\Ast\\BreakStmt");
    LOOKUP_CE(ce_continue_stmt,       "ScriptLite\\Ast\\ContinueStmt");
    LOOKUP_CE(ce_throw_stmt,          "ScriptLite\\Ast\\ThrowStmt");
    LOOKUP_CE(ce_try_catch_stmt,      "ScriptLite\\Ast\\TryCatchStmt");
    LOOKUP_CE(ce_catch_clause,        "ScriptLite\\Ast\\CatchClause");
    LOOKUP_CE(ce_assign_expr,         "ScriptLite\\Ast\\AssignExpr");
    LOOKUP_CE(ce_unary_expr,          "ScriptLite\\Ast\\UnaryExpr");
    LOOKUP_CE(ce_update_expr,         "ScriptLite\\Ast\\UpdateExpr");
    LOOKUP_CE(ce_logical_expr,        "ScriptLite\\Ast\\LogicalExpr");
    LOOKUP_CE(ce_conditional_expr,    "ScriptLite\\Ast\\ConditionalExpr");
    LOOKUP_CE(ce_sequence_expr,       "ScriptLite\\Ast\\SequenceExpr");
    LOOKUP_CE(ce_object_literal,      "ScriptLite\\Ast\\ObjectLiteral");
    LOOKUP_CE(ce_array_literal,       "ScriptLite\\Ast\\ArrayLiteral");
    LOOKUP_CE(ce_template_literal,    "ScriptLite\\Ast\\TemplateLiteral");
    LOOKUP_CE(ce_regex_literal,       "ScriptLite\\Ast\\RegexLiteral");
    LOOKUP_CE(ce_new_expr,            "ScriptLite\\Ast\\NewExpr");
    LOOKUP_CE(ce_this_expr,           "ScriptLite\\Ast\\ThisExpr");
    LOOKUP_CE(ce_typeof_expr,         "ScriptLite\\Ast\\TypeofExpr");
    LOOKUP_CE(ce_void_expr,           "ScriptLite\\Ast\\VoidExpr");
    LOOKUP_CE(ce_delete_expr,         "ScriptLite\\Ast\\DeleteExpr");
    LOOKUP_CE(ce_spread_element,      "ScriptLite\\Ast\\SpreadElement");
    LOOKUP_CE(ce_var_kind,            "ScriptLite\\Ast\\VarKind");

#undef LOOKUP_CE

    SL_G(ast_cache_initialized) = true;
    return true;
}

void sl_ast_cache_shutdown(void) {
    if (SL_G(ast_synthetic_entries)) {
        zend_class_entry *synthetic_ce;
        ZEND_HASH_FOREACH_PTR(SL_G(ast_synthetic_entries), synthetic_ce) {
            if (synthetic_ce) {
                if (synthetic_ce->name) {
                    zend_string_release(synthetic_ce->name);
                    synthetic_ce->name = NULL;
                }
                efree(synthetic_ce);
            }
        } ZEND_HASH_FOREACH_END();
        zend_hash_destroy(SL_G(ast_synthetic_entries));
        FREE_HASHTABLE(SL_G(ast_synthetic_entries));
        SL_G(ast_synthetic_entries) = NULL;
    }

    memset(&SL_G(ast_cache), 0, sizeof(SL_G(ast_cache)));
    SL_G(ast_cache_initialized) = false;
}
