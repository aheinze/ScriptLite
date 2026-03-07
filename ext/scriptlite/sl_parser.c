#include "sl_parser.h"
#include "sl_ast_reader.h"
#include "zend_smart_str.h"

#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef enum _sl_tok_type {
    SL_TOK_EOF = 0,
    SL_TOK_IDENTIFIER,
    SL_TOK_NUMBER,
    SL_TOK_STRING,
    SL_TOK_REGEX,
    SL_TOK_TEMPLATE_HEAD,
    SL_TOK_TEMPLATE_MIDDLE,
    SL_TOK_TEMPLATE_TAIL,

    /* Keywords */
    SL_TOK_VAR,
    SL_TOK_LET,
    SL_TOK_CONST,
    SL_TOK_FUNCTION,
    SL_TOK_RETURN,
    SL_TOK_IF,
    SL_TOK_ELSE,
    SL_TOK_WHILE,
    SL_TOK_FOR,
    SL_TOK_BREAK,
    SL_TOK_CONTINUE,
    SL_TOK_TRUE,
    SL_TOK_FALSE,
    SL_TOK_NULL,
    SL_TOK_UNDEFINED,
    SL_TOK_THIS,
    SL_TOK_NEW,
    SL_TOK_TYPEOF,
    SL_TOK_VOID,
    SL_TOK_DELETE,
    SL_TOK_IN,
    SL_TOK_INSTANCEOF,
    SL_TOK_SWITCH,
    SL_TOK_CASE,
    SL_TOK_DEFAULT,
    SL_TOK_DO,
    SL_TOK_TRY,
    SL_TOK_CATCH,
    SL_TOK_THROW,

    /* Delimiters */
    SL_TOK_LPAREN,
    SL_TOK_RPAREN,
    SL_TOK_LBRACE,
    SL_TOK_RBRACE,
    SL_TOK_LBRACKET,
    SL_TOK_RBRACKET,
    SL_TOK_SEMICOLON,
    SL_TOK_COMMA,
    SL_TOK_DOT,
    SL_TOK_COLON,
    SL_TOK_QUESTION,

    /* Operators */
    SL_TOK_ASSIGN,
    SL_TOK_PLUS,
    SL_TOK_MINUS,
    SL_TOK_STAR,
    SL_TOK_SLASH,
    SL_TOK_PERCENT,
    SL_TOK_BANG,
    SL_TOK_LT,
    SL_TOK_LTE,
    SL_TOK_GT,
    SL_TOK_GTE,
    SL_TOK_EQ,
    SL_TOK_NEQ,
    SL_TOK_STRICT_EQ,
    SL_TOK_STRICT_NEQ,
    SL_TOK_AND_AND,
    SL_TOK_OR_OR,
    SL_TOK_PLUS_PLUS,
    SL_TOK_MINUS_MINUS,
    SL_TOK_PLUS_ASSIGN,
    SL_TOK_MINUS_ASSIGN,
    SL_TOK_STAR_ASSIGN,
    SL_TOK_SLASH_ASSIGN,
    SL_TOK_PERCENT_ASSIGN,
    SL_TOK_AMP,
    SL_TOK_PIPE,
    SL_TOK_CARET,
    SL_TOK_TILDE,
    SL_TOK_SHIFT_LEFT,
    SL_TOK_SHIFT_RIGHT,
    SL_TOK_SHIFT_RIGHT_UNSIGNED,
    SL_TOK_AND_ASSIGN,
    SL_TOK_PIPE_ASSIGN,
    SL_TOK_CARET_ASSIGN,
    SL_TOK_SHIFT_LEFT_ASSIGN,
    SL_TOK_SHIFT_RIGHT_ASSIGN,
    SL_TOK_SHIFT_RIGHT_UNSIGNED_ASSIGN,
    SL_TOK_STAR_STAR,
    SL_TOK_STAR_STAR_ASSIGN,
    SL_TOK_ARROW,
    SL_TOK_SPREAD,
    SL_TOK_NULLISH,
    SL_TOK_NULLISH_ASSIGN,
    SL_TOK_OPTIONAL_CHAIN
} sl_tok_type;

typedef struct _sl_token {
    sl_tok_type type;
    zend_string *lexeme; /* optional payload for identifiers/literals */
    uint32_t line;
    uint32_t col;
} sl_token;

typedef struct _sl_lexer {
    const char *src;
    size_t len;
    size_t pos;
    uint32_t line;
    uint32_t col;

    sl_token *tokens;
    size_t token_count;
    size_t token_cap;

    sl_tok_type last_significant;
    uint32_t *template_brace_stack;
    size_t template_brace_count;
    size_t template_brace_cap;
    bool unsupported;
    bool error;
} sl_lexer;

typedef struct _sl_parser {
    sl_token *tokens;
    size_t count;
    size_t pos;
    bool unsupported;
    bool error;
} sl_parser;

/* ------------------------------------------------------------------------- */
/* Forward decls                                                             */
/* ------------------------------------------------------------------------- */
static bool sl_parse_statement(sl_parser *p, zval *out);
static bool sl_parse_expression(sl_parser *p, zval *out);
static bool sl_parse_comma_expression(sl_parser *p, zval *out);
static bool sl_parse_assignment(sl_parser *p, zval *out);
static bool sl_parse_conditional(sl_parser *p, zval *out);
static bool sl_parse_nullish(sl_parser *p, zval *out);
static bool sl_parse_logical_or(sl_parser *p, zval *out);
static bool sl_parse_logical_and(sl_parser *p, zval *out);
static bool sl_parse_bitwise_or(sl_parser *p, zval *out);
static bool sl_parse_bitwise_xor(sl_parser *p, zval *out);
static bool sl_parse_bitwise_and(sl_parser *p, zval *out);
static bool sl_parse_equality(sl_parser *p, zval *out);
static bool sl_parse_relational(sl_parser *p, zval *out);
static bool sl_parse_shift(sl_parser *p, zval *out);
static bool sl_parse_additive(sl_parser *p, zval *out);
static bool sl_parse_multiplicative(sl_parser *p, zval *out);
static bool sl_parse_exponent(sl_parser *p, zval *out);
static bool sl_parse_unary(sl_parser *p, zval *out);
static bool sl_parse_postfix(sl_parser *p, zval *out);
static bool sl_parse_primary(sl_parser *p, zval *out);
static bool sl_parse_block_stmt(sl_parser *p, zval *out);
static bool sl_parse_function_decl(sl_parser *p, zval *out);
static bool sl_parse_function_expr(sl_parser *p, zval *out);
static bool sl_parse_var_decl_stmt(sl_parser *p, zval *out);
static bool sl_parse_var_decl_inner(sl_parser *p, zval *out);
static bool sl_parse_if_stmt(sl_parser *p, zval *out);
static bool sl_parse_while_stmt(sl_parser *p, zval *out);
static bool sl_parse_do_while_stmt(sl_parser *p, zval *out);
static bool sl_parse_for_stmt(sl_parser *p, zval *out);
static bool sl_parse_switch_stmt(sl_parser *p, zval *out);
static bool sl_parse_try_stmt(sl_parser *p, zval *out);
static bool sl_parse_throw_stmt(sl_parser *p, zval *out);
static bool sl_parse_return_stmt(sl_parser *p, zval *out);
static bool sl_parse_break_stmt(sl_parser *p, zval *out);
static bool sl_parse_continue_stmt(sl_parser *p, zval *out);
static bool sl_parse_expr_stmt(sl_parser *p, zval *out);
static bool sl_parse_array_literal(sl_parser *p, zval *out);
static bool sl_parse_object_literal(sl_parser *p, zval *out);
static bool sl_parse_template_literal(sl_parser *p, zval *out);
static bool sl_parse_call_arguments(sl_parser *p, zval *out_args);
static bool sl_parse_arrow_body(sl_parser *p, zval *out_body);
static bool sl_parse_nested_pattern(sl_parser *p, bool is_array, zval *out_pattern);
static bool sl_parse_array_destructuring_decl(sl_parser *p, const char *kind, zval *out);
static bool sl_parse_object_destructuring_decl(sl_parser *p, const char *kind, zval *out);
static bool sl_parse_function_params(
    sl_parser *p,
    zval *out_params,
    zval *out_defaults,
    zval *out_rest_param,
    zval *out_param_destructures
);

/* ------------------------------------------------------------------------- */
/* Token helpers                                                             */
/* ------------------------------------------------------------------------- */
static zend_always_inline const char *sl_tok_op_lexeme(sl_tok_type t) {
    switch (t) {
        case SL_TOK_ASSIGN: return "=";
        case SL_TOK_PLUS: return "+";
        case SL_TOK_MINUS: return "-";
        case SL_TOK_STAR: return "*";
        case SL_TOK_SLASH: return "/";
        case SL_TOK_PERCENT: return "%";
        case SL_TOK_BANG: return "!";
        case SL_TOK_LT: return "<";
        case SL_TOK_LTE: return "<=";
        case SL_TOK_GT: return ">";
        case SL_TOK_GTE: return ">=";
        case SL_TOK_EQ: return "==";
        case SL_TOK_NEQ: return "!=";
        case SL_TOK_STRICT_EQ: return "===";
        case SL_TOK_STRICT_NEQ: return "!==";
        case SL_TOK_AND_AND: return "&&";
        case SL_TOK_OR_OR: return "||";
        case SL_TOK_PLUS_PLUS: return "++";
        case SL_TOK_MINUS_MINUS: return "--";
        case SL_TOK_PLUS_ASSIGN: return "+=";
        case SL_TOK_MINUS_ASSIGN: return "-=";
        case SL_TOK_STAR_ASSIGN: return "*=";
        case SL_TOK_SLASH_ASSIGN: return "/=";
        case SL_TOK_PERCENT_ASSIGN: return "%=";
        case SL_TOK_AMP: return "&";
        case SL_TOK_PIPE: return "|";
        case SL_TOK_CARET: return "^";
        case SL_TOK_TILDE: return "~";
        case SL_TOK_SHIFT_LEFT: return "<<";
        case SL_TOK_SHIFT_RIGHT: return ">>";
        case SL_TOK_SHIFT_RIGHT_UNSIGNED: return ">>>";
        case SL_TOK_AND_ASSIGN: return "&=";
        case SL_TOK_PIPE_ASSIGN: return "|=";
        case SL_TOK_CARET_ASSIGN: return "^=";
        case SL_TOK_SHIFT_LEFT_ASSIGN: return "<<=";
        case SL_TOK_SHIFT_RIGHT_ASSIGN: return ">>=";
        case SL_TOK_SHIFT_RIGHT_UNSIGNED_ASSIGN: return ">>>=";
        case SL_TOK_STAR_STAR: return "**";
        case SL_TOK_STAR_STAR_ASSIGN: return "**=";
        case SL_TOK_NULLISH: return "??";
        case SL_TOK_NULLISH_ASSIGN: return "?""?=";
        case SL_TOK_IN: return "in";
        case SL_TOK_INSTANCEOF: return "instanceof";
        default: return "";
    }
}

static const char *sl_tok_name(sl_tok_type t) {
    switch (t) {
        case SL_TOK_EOF: return "EOF";
        case SL_TOK_IDENTIFIER: return "IDENTIFIER";
        case SL_TOK_NUMBER: return "NUMBER";
        case SL_TOK_STRING: return "STRING";
        case SL_TOK_REGEX: return "REGEX";
        case SL_TOK_TEMPLATE_HEAD: return "TEMPLATE_HEAD";
        case SL_TOK_TEMPLATE_MIDDLE: return "TEMPLATE_MIDDLE";
        case SL_TOK_TEMPLATE_TAIL: return "TEMPLATE_TAIL";
        case SL_TOK_VAR: return "VAR";
        case SL_TOK_LET: return "LET";
        case SL_TOK_CONST: return "CONST";
        case SL_TOK_FUNCTION: return "FUNCTION";
        case SL_TOK_RETURN: return "RETURN";
        case SL_TOK_IF: return "IF";
        case SL_TOK_ELSE: return "ELSE";
        case SL_TOK_WHILE: return "WHILE";
        case SL_TOK_FOR: return "FOR";
        case SL_TOK_BREAK: return "BREAK";
        case SL_TOK_CONTINUE: return "CONTINUE";
        case SL_TOK_TRUE: return "TRUE";
        case SL_TOK_FALSE: return "FALSE";
        case SL_TOK_NULL: return "NULL";
        case SL_TOK_UNDEFINED: return "UNDEFINED";
        case SL_TOK_THIS: return "THIS";
        case SL_TOK_NEW: return "NEW";
        case SL_TOK_TYPEOF: return "TYPEOF";
        case SL_TOK_VOID: return "VOID";
        case SL_TOK_DELETE: return "DELETE";
        case SL_TOK_IN: return "IN";
        case SL_TOK_INSTANCEOF: return "INSTANCEOF";
        case SL_TOK_SWITCH: return "SWITCH";
        case SL_TOK_CASE: return "CASE";
        case SL_TOK_DEFAULT: return "DEFAULT";
        case SL_TOK_DO: return "DO";
        case SL_TOK_TRY: return "TRY";
        case SL_TOK_CATCH: return "CATCH";
        case SL_TOK_THROW: return "THROW";
        case SL_TOK_LPAREN: return "LPAREN";
        case SL_TOK_RPAREN: return "RPAREN";
        case SL_TOK_LBRACE: return "LBRACE";
        case SL_TOK_RBRACE: return "RBRACE";
        case SL_TOK_LBRACKET: return "LBRACKET";
        case SL_TOK_RBRACKET: return "RBRACKET";
        case SL_TOK_SEMICOLON: return "SEMICOLON";
        case SL_TOK_COMMA: return "COMMA";
        case SL_TOK_DOT: return "DOT";
        case SL_TOK_COLON: return "COLON";
        case SL_TOK_QUESTION: return "QUESTION";
        case SL_TOK_ASSIGN: return "ASSIGN";
        case SL_TOK_PLUS: return "PLUS";
        case SL_TOK_MINUS: return "MINUS";
        case SL_TOK_STAR: return "STAR";
        case SL_TOK_SLASH: return "SLASH";
        case SL_TOK_PERCENT: return "PERCENT";
        case SL_TOK_BANG: return "BANG";
        case SL_TOK_LT: return "LT";
        case SL_TOK_LTE: return "LTE";
        case SL_TOK_GT: return "GT";
        case SL_TOK_GTE: return "GTE";
        case SL_TOK_EQ: return "EQ";
        case SL_TOK_NEQ: return "NEQ";
        case SL_TOK_STRICT_EQ: return "STRICT_EQ";
        case SL_TOK_STRICT_NEQ: return "STRICT_NEQ";
        case SL_TOK_AND_AND: return "AND_AND";
        case SL_TOK_OR_OR: return "OR_OR";
        case SL_TOK_PLUS_PLUS: return "PLUS_PLUS";
        case SL_TOK_MINUS_MINUS: return "MINUS_MINUS";
        case SL_TOK_PLUS_ASSIGN: return "PLUS_ASSIGN";
        case SL_TOK_MINUS_ASSIGN: return "MINUS_ASSIGN";
        case SL_TOK_STAR_ASSIGN: return "STAR_ASSIGN";
        case SL_TOK_SLASH_ASSIGN: return "SLASH_ASSIGN";
        case SL_TOK_PERCENT_ASSIGN: return "PERCENT_ASSIGN";
        case SL_TOK_AMP: return "AMP";
        case SL_TOK_PIPE: return "PIPE";
        case SL_TOK_CARET: return "CARET";
        case SL_TOK_TILDE: return "TILDE";
        case SL_TOK_SHIFT_LEFT: return "SHIFT_LEFT";
        case SL_TOK_SHIFT_RIGHT: return "SHIFT_RIGHT";
        case SL_TOK_SHIFT_RIGHT_UNSIGNED: return "SHIFT_RIGHT_UNSIGNED";
        case SL_TOK_AND_ASSIGN: return "AND_ASSIGN";
        case SL_TOK_PIPE_ASSIGN: return "PIPE_ASSIGN";
        case SL_TOK_CARET_ASSIGN: return "CARET_ASSIGN";
        case SL_TOK_SHIFT_LEFT_ASSIGN: return "SHIFT_LEFT_ASSIGN";
        case SL_TOK_SHIFT_RIGHT_ASSIGN: return "SHIFT_RIGHT_ASSIGN";
        case SL_TOK_SHIFT_RIGHT_UNSIGNED_ASSIGN: return "SHIFT_RIGHT_UNSIGNED_ASSIGN";
        case SL_TOK_STAR_STAR: return "STAR_STAR";
        case SL_TOK_STAR_STAR_ASSIGN: return "STAR_STAR_ASSIGN";
        case SL_TOK_ARROW: return "ARROW";
        case SL_TOK_SPREAD: return "SPREAD";
        case SL_TOK_NULLISH: return "NULLISH";
        case SL_TOK_NULLISH_ASSIGN: return "NULLISH_ASSIGN";
        case SL_TOK_OPTIONAL_CHAIN: return "OPTIONAL_CHAIN";
        default: return "UNKNOWN";
    }
}

static zend_always_inline bool sl_is_ident_start(char c) {
    return (c == '_' || c == '$'
        || (c >= 'a' && c <= 'z')
        || (c >= 'A' && c <= 'Z'));
}

static zend_always_inline bool sl_is_ident_part(char c) {
    return sl_is_ident_start(c) || (c >= '0' && c <= '9');
}

static zend_always_inline bool sl_is_digit(char c) {
    return c >= '0' && c <= '9';
}

static void sl_token_dtor(sl_token *tok) {
    if (tok->lexeme) {
        zend_string_release(tok->lexeme);
        tok->lexeme = NULL;
    }
}

static bool sl_lexer_emit(sl_lexer *lx, sl_tok_type type, zend_string *lexeme, uint32_t line, uint32_t col) {
    if (lx->token_count == lx->token_cap) {
        size_t new_cap = lx->token_cap ? lx->token_cap * 2 : 256;
        sl_token *grown = erealloc(lx->tokens, sizeof(sl_token) * new_cap);
        if (!grown) {
            if (lexeme) {
                zend_string_release(lexeme);
            }
            lx->error = true;
            return false;
        }
        lx->tokens = grown;
        lx->token_cap = new_cap;
    }

    sl_token *slot = &lx->tokens[lx->token_count++];
    slot->type = type;
    slot->lexeme = lexeme;
    slot->line = line;
    slot->col = col;

    if (type != SL_TOK_EOF) {
        lx->last_significant = type;
    }
    return true;
}

static zend_always_inline char sl_lex_peek(sl_lexer *lx, size_t off) {
    size_t idx = lx->pos + off;
    return idx < lx->len ? lx->src[idx] : '\0';
}

static zend_always_inline char sl_lex_advance(sl_lexer *lx) {
    char c = sl_lex_peek(lx, 0);
    if (c == '\0') {
        return '\0';
    }
    lx->pos++;
    if (c == '\n') {
        lx->line++;
        lx->col = 1;
    } else {
        lx->col++;
    }
    return c;
}

static void sl_lex_skip_line_comment(sl_lexer *lx) {
    while (lx->pos < lx->len) {
        char c = sl_lex_peek(lx, 0);
        if (c == '\n' || c == '\0') {
            return;
        }
        sl_lex_advance(lx);
    }
}

static void sl_lex_skip_block_comment(sl_lexer *lx) {
    /* assumes current char is '/' and next '*' */
    sl_lex_advance(lx);
    sl_lex_advance(lx);
    while (lx->pos < lx->len) {
        char c = sl_lex_peek(lx, 0);
        if (c == '*' && sl_lex_peek(lx, 1) == '/') {
            sl_lex_advance(lx);
            sl_lex_advance(lx);
            return;
        }
        sl_lex_advance(lx);
    }
}

static bool sl_token_can_end_expr(sl_tok_type t) {
    switch (t) {
        case SL_TOK_IDENTIFIER:
        case SL_TOK_NUMBER:
        case SL_TOK_STRING:
        case SL_TOK_REGEX:
        case SL_TOK_TEMPLATE_TAIL:
        case SL_TOK_TRUE:
        case SL_TOK_FALSE:
        case SL_TOK_NULL:
        case SL_TOK_UNDEFINED:
        case SL_TOK_THIS:
        case SL_TOK_RPAREN:
        case SL_TOK_RBRACKET:
        case SL_TOK_RBRACE:
        case SL_TOK_PLUS_PLUS:
        case SL_TOK_MINUS_MINUS:
            return true;
        default:
            return false;
    }
}

static bool sl_lex_can_be_regex(sl_lexer *lx) {
    if (lx->token_count == 0) {
        return true;
    }
    return !sl_token_can_end_expr(lx->last_significant);
}

static sl_tok_type sl_keyword_type(const char *s, size_t n) {
    switch (n) {
        case 2:
            if (memcmp(s, "if", 2) == 0) return SL_TOK_IF;
            if (memcmp(s, "in", 2) == 0) return SL_TOK_IN;
            if (memcmp(s, "do", 2) == 0) return SL_TOK_DO;
            break;
        case 3:
            if (memcmp(s, "var", 3) == 0) return SL_TOK_VAR;
            if (memcmp(s, "let", 3) == 0) return SL_TOK_LET;
            if (memcmp(s, "for", 3) == 0) return SL_TOK_FOR;
            if (memcmp(s, "new", 3) == 0) return SL_TOK_NEW;
            if (memcmp(s, "try", 3) == 0) return SL_TOK_TRY;
            break;
        case 4:
            if (memcmp(s, "this", 4) == 0) return SL_TOK_THIS;
            if (memcmp(s, "true", 4) == 0) return SL_TOK_TRUE;
            if (memcmp(s, "else", 4) == 0) return SL_TOK_ELSE;
            if (memcmp(s, "null", 4) == 0) return SL_TOK_NULL;
            if (memcmp(s, "case", 4) == 0) return SL_TOK_CASE;
            if (memcmp(s, "void", 4) == 0) return SL_TOK_VOID;
            break;
        case 5:
            if (memcmp(s, "const", 5) == 0) return SL_TOK_CONST;
            if (memcmp(s, "while", 5) == 0) return SL_TOK_WHILE;
            if (memcmp(s, "break", 5) == 0) return SL_TOK_BREAK;
            if (memcmp(s, "false", 5) == 0) return SL_TOK_FALSE;
            if (memcmp(s, "throw", 5) == 0) return SL_TOK_THROW;
            if (memcmp(s, "catch", 5) == 0) return SL_TOK_CATCH;
            break;
        case 6:
            if (memcmp(s, "return", 6) == 0) return SL_TOK_RETURN;
            if (memcmp(s, "typeof", 6) == 0) return SL_TOK_TYPEOF;
            if (memcmp(s, "switch", 6) == 0) return SL_TOK_SWITCH;
            if (memcmp(s, "delete", 6) == 0) return SL_TOK_DELETE;
            break;
        case 7:
            if (memcmp(s, "default", 7) == 0) return SL_TOK_DEFAULT;
            break;
        case 8:
            if (memcmp(s, "function", 8) == 0) return SL_TOK_FUNCTION;
            if (memcmp(s, "continue", 8) == 0) return SL_TOK_CONTINUE;
            break;
        case 9:
            if (memcmp(s, "undefined", 9) == 0) return SL_TOK_UNDEFINED;
            break;
        case 10:
            if (memcmp(s, "instanceof", 10) == 0) return SL_TOK_INSTANCEOF;
            break;
    }
    return SL_TOK_IDENTIFIER;
}

static bool sl_lex_read_identifier(sl_lexer *lx) {
    size_t start = lx->pos;
    uint32_t line = lx->line;
    uint32_t col = lx->col;

    sl_lex_advance(lx);
    while (sl_is_ident_part(sl_lex_peek(lx, 0))) {
        sl_lex_advance(lx);
    }

    size_t len = lx->pos - start;
    sl_tok_type t = sl_keyword_type(lx->src + start, len);
    zend_string *lex = NULL;

    if (t == SL_TOK_IDENTIFIER) {
        lex = zend_string_init(lx->src + start, len, 0);
    }
    return sl_lexer_emit(lx, t, lex, line, col);
}

static bool sl_lex_read_number(sl_lexer *lx) {
    size_t start = lx->pos;
    uint32_t line = lx->line;
    uint32_t col = lx->col;

    bool saw_dot = false;
    while (1) {
        char c = sl_lex_peek(lx, 0);
        if (sl_is_digit(c)) {
            sl_lex_advance(lx);
            continue;
        }
        if (c == '.' && !saw_dot) {
            saw_dot = true;
            sl_lex_advance(lx);
            continue;
        }
        break;
    }

    /* optional exponent */
    if (sl_lex_peek(lx, 0) == 'e' || sl_lex_peek(lx, 0) == 'E') {
        size_t save = lx->pos;
        uint32_t save_col = lx->col;
        sl_lex_advance(lx);
        if (sl_lex_peek(lx, 0) == '+' || sl_lex_peek(lx, 0) == '-') {
            sl_lex_advance(lx);
        }
        if (!sl_is_digit(sl_lex_peek(lx, 0))) {
            lx->pos = save;
            lx->col = save_col;
        } else {
            while (sl_is_digit(sl_lex_peek(lx, 0))) {
                sl_lex_advance(lx);
            }
        }
    }

    zend_string *num = zend_string_init(lx->src + start, lx->pos - start, 0);
    return sl_lexer_emit(lx, SL_TOK_NUMBER, num, line, col);
}

static bool sl_append_utf8_codepoint(smart_str *buf, uint32_t code) {
    if (code <= 0x7F) {
        smart_str_appendc(buf, (char) code);
        return true;
    }
    if (code <= 0x7FF) {
        smart_str_appendc(buf, (char) (0xC0 | (code >> 6)));
        smart_str_appendc(buf, (char) (0x80 | (code & 0x3F)));
        return true;
    }
    if (code <= 0xFFFF) {
        smart_str_appendc(buf, (char) (0xE0 | (code >> 12)));
        smart_str_appendc(buf, (char) (0x80 | ((code >> 6) & 0x3F)));
        smart_str_appendc(buf, (char) (0x80 | (code & 0x3F)));
        return true;
    }
    if (code <= 0x10FFFF) {
        smart_str_appendc(buf, (char) (0xF0 | (code >> 18)));
        smart_str_appendc(buf, (char) (0x80 | ((code >> 12) & 0x3F)));
        smart_str_appendc(buf, (char) (0x80 | ((code >> 6) & 0x3F)));
        smart_str_appendc(buf, (char) (0x80 | (code & 0x3F)));
        return true;
    }
    return false;
}

static bool sl_lex_read_hex(sl_lexer *lx, int digits, uint32_t *out) {
    uint32_t v = 0;
    int i;
    for (i = 0; i < digits; i++) {
        char c = sl_lex_peek(lx, 0);
        uint32_t n;
        if (c >= '0' && c <= '9') n = (uint32_t)(c - '0');
        else if (c >= 'a' && c <= 'f') n = (uint32_t)(10 + c - 'a');
        else if (c >= 'A' && c <= 'F') n = (uint32_t)(10 + c - 'A');
        else return false;
        v = (v << 4) | n;
        sl_lex_advance(lx);
    }
    *out = v;
    return true;
}

static bool sl_lex_read_string(sl_lexer *lx, char quote) {
    uint32_t line = lx->line;
    uint32_t col = lx->col;

    sl_lex_advance(lx); /* opening quote */
    smart_str buf = {0};

    while (lx->pos < lx->len) {
        char c = sl_lex_peek(lx, 0);
        if (c == quote) {
            sl_lex_advance(lx);
            smart_str_0(&buf);
            zend_string *s = buf.s ? buf.s : zend_string_init("", 0, 0);
            return sl_lexer_emit(lx, SL_TOK_STRING, s, line, col);
        }

        if (c == '\n' || c == '\0') {
            smart_str_free(&buf);
            lx->error = true;
            return false;
        }

        if (c != '\\') {
            smart_str_appendc(&buf, c);
            sl_lex_advance(lx);
            continue;
        }

        /* Escape */
        sl_lex_advance(lx);
        c = sl_lex_peek(lx, 0);
        if (c == '\0') {
            smart_str_free(&buf);
            lx->error = true;
            return false;
        }
        switch (c) {
            case 'n': smart_str_appendc(&buf, '\n'); sl_lex_advance(lx); break;
            case 't': smart_str_appendc(&buf, '\t'); sl_lex_advance(lx); break;
            case 'r': smart_str_appendc(&buf, '\r'); sl_lex_advance(lx); break;
            case 'b': smart_str_appendc(&buf, '\b'); sl_lex_advance(lx); break;
            case 'f': smart_str_appendc(&buf, '\f'); sl_lex_advance(lx); break;
            case 'v': smart_str_appendc(&buf, '\v'); sl_lex_advance(lx); break;
            case '0': smart_str_appendc(&buf, '\0'); sl_lex_advance(lx); break;
            case '\\': smart_str_appendc(&buf, '\\'); sl_lex_advance(lx); break;
            case '\'': smart_str_appendc(&buf, '\''); sl_lex_advance(lx); break;
            case '"': smart_str_appendc(&buf, '"'); sl_lex_advance(lx); break;
            case '`': smart_str_appendc(&buf, '`'); sl_lex_advance(lx); break;
            case 'x': {
                uint32_t code = 0;
                sl_lex_advance(lx);
                if (!sl_lex_read_hex(lx, 2, &code)) {
                    smart_str_free(&buf);
                    lx->error = true;
                    return false;
                }
                smart_str_appendc(&buf, (char)code);
                break;
            }
            case 'u': {
                uint32_t code = 0;
                sl_lex_advance(lx);
                if (!sl_lex_read_hex(lx, 4, &code)) {
                    smart_str_free(&buf);
                    lx->error = true;
                    return false;
                }
                if (!sl_append_utf8_codepoint(&buf, code)) {
                    smart_str_free(&buf);
                    lx->unsupported = true;
                    return false;
                }
                break;
            }
            default:
                smart_str_appendc(&buf, c);
                sl_lex_advance(lx);
                break;
        }
    }

    smart_str_free(&buf);
    lx->error = true;
    return false;
}

static bool sl_lex_template_push_depth(sl_lexer *lx, uint32_t depth) {
    if (lx->template_brace_count == lx->template_brace_cap) {
        size_t new_cap = lx->template_brace_cap ? lx->template_brace_cap * 2 : 8;
        uint32_t *grown = erealloc(lx->template_brace_stack, sizeof(uint32_t) * new_cap);
        if (!grown) {
            lx->error = true;
            return false;
        }
        lx->template_brace_stack = grown;
        lx->template_brace_cap = new_cap;
    }
    lx->template_brace_stack[lx->template_brace_count++] = depth;
    return true;
}

static bool sl_lex_scan_template_text(sl_lexer *lx, zend_string **text_out, bool *has_interpolation) {
    smart_str buf = {0};

    while (lx->pos < lx->len) {
        char c = sl_lex_peek(lx, 0);

        if (c == '`') {
            sl_lex_advance(lx);
            smart_str_0(&buf);
            *text_out = buf.s ? buf.s : zend_string_init("", 0, 0);
            *has_interpolation = false;
            return true;
        }

        if (c == '$' && sl_lex_peek(lx, 1) == '{') {
            sl_lex_advance(lx);
            sl_lex_advance(lx);
            smart_str_0(&buf);
            *text_out = buf.s ? buf.s : zend_string_init("", 0, 0);
            *has_interpolation = true;
            return true;
        }

        if (c == '\\' && sl_lex_peek(lx, 1) != '\0') {
            sl_lex_advance(lx); /* '\' */
            c = sl_lex_peek(lx, 0);
            switch (c) {
                case 'n': smart_str_appendc(&buf, '\n'); sl_lex_advance(lx); break;
                case 't': smart_str_appendc(&buf, '\t'); sl_lex_advance(lx); break;
                case 'r': smart_str_appendc(&buf, '\r'); sl_lex_advance(lx); break;
                case 'b': smart_str_appendc(&buf, '\b'); sl_lex_advance(lx); break;
                case 'f': smart_str_appendc(&buf, '\f'); sl_lex_advance(lx); break;
                case 'v': smart_str_appendc(&buf, '\v'); sl_lex_advance(lx); break;
                case '0': smart_str_appendc(&buf, '\0'); sl_lex_advance(lx); break;
                case '\\': smart_str_appendc(&buf, '\\'); sl_lex_advance(lx); break;
                case '\'': smart_str_appendc(&buf, '\''); sl_lex_advance(lx); break;
                case '"': smart_str_appendc(&buf, '"'); sl_lex_advance(lx); break;
                case '`': smart_str_appendc(&buf, '`'); sl_lex_advance(lx); break;
                case '$': smart_str_appendc(&buf, '$'); sl_lex_advance(lx); break;
                case 'x': {
                    uint32_t code = 0;
                    sl_lex_advance(lx); /* 'x' */
                    if (!sl_lex_read_hex(lx, 2, &code)) {
                        smart_str_free(&buf);
                        lx->error = true;
                        return false;
                    }
                    smart_str_appendc(&buf, (char)code);
                    break;
                }
                case 'u': {
                    uint32_t code = 0;
                    sl_lex_advance(lx); /* 'u' */
                    if (!sl_lex_read_hex(lx, 4, &code)) {
                        smart_str_free(&buf);
                        lx->error = true;
                        return false;
                    }
                    if (!sl_append_utf8_codepoint(&buf, code)) {
                        smart_str_free(&buf);
                        lx->unsupported = true;
                        return false;
                    }
                    break;
                }
                default:
                    smart_str_appendc(&buf, '\\');
                    smart_str_appendc(&buf, c);
                    sl_lex_advance(lx);
                    break;
            }
            continue;
        }

        smart_str_appendc(&buf, c);
        sl_lex_advance(lx);
    }

    smart_str_free(&buf);
    lx->error = true;
    return false;
}

static bool sl_lex_read_template_start(sl_lexer *lx) {
    uint32_t line = lx->line;
    uint32_t col = lx->col;
    sl_lex_advance(lx); /* opening ` */

    zend_string *text = NULL;
    bool has_interpolation = false;
    if (!sl_lex_scan_template_text(lx, &text, &has_interpolation)) {
        if (text) {
            zend_string_release(text);
        }
        return false;
    }

    if (has_interpolation) {
        if (!sl_lex_template_push_depth(lx, 0)) {
            zend_string_release(text);
            return false;
        }
        return sl_lexer_emit(lx, SL_TOK_TEMPLATE_HEAD, text, line, col);
    }

    /* No interpolation: same behavior as PHP lexer, emit plain string token. */
    return sl_lexer_emit(lx, SL_TOK_STRING, text, line, col);
}

static bool sl_lex_resume_template(sl_lexer *lx) {
    uint32_t line = lx->line;
    uint32_t col = lx->col;
    sl_lex_advance(lx); /* interpolation-closing '}' */

    zend_string *text = NULL;
    bool has_interpolation = false;
    if (!sl_lex_scan_template_text(lx, &text, &has_interpolation)) {
        if (text) {
            zend_string_release(text);
        }
        return false;
    }

    if (has_interpolation) {
        if (!sl_lex_template_push_depth(lx, 0)) {
            zend_string_release(text);
            return false;
        }
        return sl_lexer_emit(lx, SL_TOK_TEMPLATE_MIDDLE, text, line, col);
    }

    return sl_lexer_emit(lx, SL_TOK_TEMPLATE_TAIL, text, line, col);
}

static bool sl_lex_read_regex(sl_lexer *lx) {
    uint32_t line = lx->line;
    uint32_t col = lx->col;

    sl_lex_advance(lx); /* leading slash */

    smart_str pat = {0};
    bool in_class = false;
    while (lx->pos < lx->len) {
        char c = sl_lex_peek(lx, 0);
        if (c == '\n' || c == '\0') {
            smart_str_free(&pat);
            lx->error = true;
            return false;
        }
        if (c == '\\') {
            smart_str_appendc(&pat, c);
            sl_lex_advance(lx);
            c = sl_lex_peek(lx, 0);
            if (c == '\0') {
                smart_str_free(&pat);
                lx->error = true;
                return false;
            }
            smart_str_appendc(&pat, c);
            sl_lex_advance(lx);
            continue;
        }
        if (c == '[') {
            in_class = true;
            smart_str_appendc(&pat, c);
            sl_lex_advance(lx);
            continue;
        }
        if (c == ']') {
            in_class = false;
            smart_str_appendc(&pat, c);
            sl_lex_advance(lx);
            continue;
        }
        if (c == '/' && !in_class) {
            sl_lex_advance(lx);
            break;
        }
        smart_str_appendc(&pat, c);
        sl_lex_advance(lx);
    }

    smart_str flags = {0};
    while (1) {
        char c = sl_lex_peek(lx, 0);
        if ((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z')) {
            smart_str_appendc(&flags, c);
            sl_lex_advance(lx);
        } else {
            break;
        }
    }

    smart_str combo = {0};
    smart_str_0(&pat);
    smart_str_0(&flags);
    if (pat.s) {
        smart_str_appendl(&combo, ZSTR_VAL(pat.s), ZSTR_LEN(pat.s));
    }
    smart_str_appendl(&combo, "|||", 3);
    if (flags.s) {
        smart_str_appendl(&combo, ZSTR_VAL(flags.s), ZSTR_LEN(flags.s));
    }
    smart_str_0(&combo);

    zend_string *payload = combo.s ? combo.s : zend_string_init("|||", 3, 0);
    smart_str_free(&pat);
    smart_str_free(&flags);

    return sl_lexer_emit(lx, SL_TOK_REGEX, payload, line, col);
}

static bool sl_lex_emit_simple(sl_lexer *lx, sl_tok_type type, size_t consumed) {
    uint32_t line = lx->line;
    uint32_t col = lx->col;
    size_t i;
    for (i = 0; i < consumed; i++) {
        sl_lex_advance(lx);
    }
    return sl_lexer_emit(lx, type, NULL, line, col);
}

static bool sl_lex_tokenize(sl_lexer *lx) {
    while (lx->pos < lx->len) {
        char c = sl_lex_peek(lx, 0);

        if (c == ' ' || c == '\t' || c == '\r' || c == '\n') {
            sl_lex_advance(lx);
            continue;
        }

        if (c == '/' && sl_lex_peek(lx, 1) == '/') {
            sl_lex_skip_line_comment(lx);
            continue;
        }
        if (c == '/' && sl_lex_peek(lx, 1) == '*') {
            sl_lex_skip_block_comment(lx);
            continue;
        }

        if (c == '`') {
            if (!sl_lex_read_template_start(lx)) {
                return false;
            }
            continue;
        }

        if (c == '}' && lx->template_brace_count > 0) {
            size_t top = lx->template_brace_count - 1;
            if (lx->template_brace_stack[top] == 0) {
                lx->template_brace_count--;
                if (!sl_lex_resume_template(lx)) {
                    return false;
                }
                continue;
            }
            lx->template_brace_stack[top]--;
        }

        if (c == '{' && lx->template_brace_count > 0) {
            size_t top = lx->template_brace_count - 1;
            lx->template_brace_stack[top]++;
        }

        if (sl_is_ident_start(c)) {
            if (!sl_lex_read_identifier(lx)) {
                return false;
            }
            continue;
        }

        if (sl_is_digit(c)) {
            if (!sl_lex_read_number(lx)) {
                return false;
            }
            continue;
        }

        if (c == '\'' || c == '"') {
            if (!sl_lex_read_string(lx, c)) {
                return false;
            }
            continue;
        }

        if (c == '/' && sl_lex_can_be_regex(lx)) {
            if (!sl_lex_read_regex(lx)) {
                return false;
            }
            continue;
        }

        /* Multi-char operators */
        if (c == '=') {
            if (sl_lex_peek(lx, 1) == '=' && sl_lex_peek(lx, 2) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_STRICT_EQ, 3)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_EQ, 2)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '>') {
                if (!sl_lex_emit_simple(lx, SL_TOK_ARROW, 2)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_ASSIGN, 1)) return false;
            continue;
        }
        if (c == '!') {
            if (sl_lex_peek(lx, 1) == '=' && sl_lex_peek(lx, 2) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_STRICT_NEQ, 3)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_NEQ, 2)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_BANG, 1)) return false;
            continue;
        }
        if (c == '>') {
            if (sl_lex_peek(lx, 1) == '>' && sl_lex_peek(lx, 2) == '>' && sl_lex_peek(lx, 3) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_SHIFT_RIGHT_UNSIGNED_ASSIGN, 4)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '>' && sl_lex_peek(lx, 2) == '>') {
                if (!sl_lex_emit_simple(lx, SL_TOK_SHIFT_RIGHT_UNSIGNED, 3)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '>' && sl_lex_peek(lx, 2) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_SHIFT_RIGHT_ASSIGN, 3)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '>') {
                if (!sl_lex_emit_simple(lx, SL_TOK_SHIFT_RIGHT, 2)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_GTE, 2)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_GT, 1)) return false;
            continue;
        }
        if (c == '<') {
            if (sl_lex_peek(lx, 1) == '<' && sl_lex_peek(lx, 2) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_SHIFT_LEFT_ASSIGN, 3)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '<') {
                if (!sl_lex_emit_simple(lx, SL_TOK_SHIFT_LEFT, 2)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_LTE, 2)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_LT, 1)) return false;
            continue;
        }
        if (c == '*') {
            if (sl_lex_peek(lx, 1) == '*' && sl_lex_peek(lx, 2) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_STAR_STAR_ASSIGN, 3)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '*') {
                if (!sl_lex_emit_simple(lx, SL_TOK_STAR_STAR, 2)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_STAR_ASSIGN, 2)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_STAR, 1)) return false;
            continue;
        }
        if (c == '+') {
            if (sl_lex_peek(lx, 1) == '+') {
                if (!sl_lex_emit_simple(lx, SL_TOK_PLUS_PLUS, 2)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_PLUS_ASSIGN, 2)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_PLUS, 1)) return false;
            continue;
        }
        if (c == '-') {
            if (sl_lex_peek(lx, 1) == '-') {
                if (!sl_lex_emit_simple(lx, SL_TOK_MINUS_MINUS, 2)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_MINUS_ASSIGN, 2)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_MINUS, 1)) return false;
            continue;
        }
        if (c == '&') {
            if (sl_lex_peek(lx, 1) == '&') {
                if (!sl_lex_emit_simple(lx, SL_TOK_AND_AND, 2)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_AND_ASSIGN, 2)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_AMP, 1)) return false;
            continue;
        }
        if (c == '|') {
            if (sl_lex_peek(lx, 1) == '|') {
                if (!sl_lex_emit_simple(lx, SL_TOK_OR_OR, 2)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_PIPE_ASSIGN, 2)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_PIPE, 1)) return false;
            continue;
        }
        if (c == '^') {
            if (sl_lex_peek(lx, 1) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_CARET_ASSIGN, 2)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_CARET, 1)) return false;
            continue;
        }
        if (c == '%') {
            if (sl_lex_peek(lx, 1) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_PERCENT_ASSIGN, 2)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_PERCENT, 1)) return false;
            continue;
        }
        if (c == '/') {
            if (sl_lex_peek(lx, 1) == '=') {
                if (!sl_lex_emit_simple(lx, SL_TOK_SLASH_ASSIGN, 2)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_SLASH, 1)) return false;
            continue;
        }
        if (c == '?') {
            if (sl_lex_peek(lx, 1) == '?') {
                if (sl_lex_peek(lx, 2) == '=') {
                    if (!sl_lex_emit_simple(lx, SL_TOK_NULLISH_ASSIGN, 3)) return false;
                    continue;
                }
                if (!sl_lex_emit_simple(lx, SL_TOK_NULLISH, 2)) return false;
                continue;
            }
            if (sl_lex_peek(lx, 1) == '.'
                && !(sl_lex_peek(lx, 2) >= '0' && sl_lex_peek(lx, 2) <= '9')) {
                if (!sl_lex_emit_simple(lx, SL_TOK_OPTIONAL_CHAIN, 2)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_QUESTION, 1)) return false;
            continue;
        }
        if (c == '.') {
            if (sl_lex_peek(lx, 1) == '.' && sl_lex_peek(lx, 2) == '.') {
                if (!sl_lex_emit_simple(lx, SL_TOK_SPREAD, 3)) return false;
                continue;
            }
            if (!sl_lex_emit_simple(lx, SL_TOK_DOT, 1)) return false;
            continue;
        }

        /* Single-char delimiters */
        if (c == '(') { if (!sl_lex_emit_simple(lx, SL_TOK_LPAREN, 1)) return false; continue; }
        if (c == ')') { if (!sl_lex_emit_simple(lx, SL_TOK_RPAREN, 1)) return false; continue; }
        if (c == '{') { if (!sl_lex_emit_simple(lx, SL_TOK_LBRACE, 1)) return false; continue; }
        if (c == '}') { if (!sl_lex_emit_simple(lx, SL_TOK_RBRACE, 1)) return false; continue; }
        if (c == '[') { if (!sl_lex_emit_simple(lx, SL_TOK_LBRACKET, 1)) return false; continue; }
        if (c == ']') { if (!sl_lex_emit_simple(lx, SL_TOK_RBRACKET, 1)) return false; continue; }
        if (c == ';') { if (!sl_lex_emit_simple(lx, SL_TOK_SEMICOLON, 1)) return false; continue; }
        if (c == ',') { if (!sl_lex_emit_simple(lx, SL_TOK_COMMA, 1)) return false; continue; }
        if (c == ':') { if (!sl_lex_emit_simple(lx, SL_TOK_COLON, 1)) return false; continue; }
        if (c == '~') { if (!sl_lex_emit_simple(lx, SL_TOK_TILDE, 1)) return false; continue; }

        lx->unsupported = true;
        return false;
    }

    if (lx->template_brace_count != 0) {
        lx->error = true;
        return false;
    }

    return sl_lexer_emit(lx, SL_TOK_EOF, NULL, lx->line, lx->col);
}

/* ------------------------------------------------------------------------- */
/* AST object helpers (stdClass + __kind discriminator)                      */
/* ------------------------------------------------------------------------- */
static bool sl_node_init(zval *node, const char *kind) {
    object_init(node); /* stdClass */
    add_property_string(node, "__kind", kind);
    return true;
}

static zend_always_inline zval *sl_node_prop(zval *node, const char *name) {
    if (!node) {
        return NULL;
    }

    const size_t name_len = strlen(name);
    if (Z_TYPE_P(node) == IS_OBJECT) {
        return zend_hash_str_find(Z_OBJPROP_P(node), name, name_len);
    }
    if (Z_TYPE_P(node) == IS_ARRAY) {
        return zend_hash_str_find(Z_ARRVAL_P(node), name, name_len);
    }

    return NULL;
}

static bool sl_node_is_kind(zval *node, const char *kind) {
    if (!node || Z_TYPE_P(node) != IS_OBJECT) {
        return false;
    }
    zval *k = sl_node_prop(node, "__kind");
    if (!k || Z_TYPE_P(k) != IS_STRING) {
        return false;
    }
    return zend_string_equals_cstr(Z_STR_P(k), kind, strlen(kind));
}

static bool sl_make_program(zval *body, zval *out) {
    sl_node_init(out, "Program");
    add_property_zval(out, "body", body);
    ZVAL_UNDEF(body);
    return true;
}

static bool sl_make_expr_stmt(zval *expr, zval *out) {
    sl_node_init(out, "ExpressionStmt");
    add_property_zval(out, "expression", expr);
    ZVAL_UNDEF(expr);
    return true;
}

static bool sl_make_identifier(zend_string *name, zval *out) {
    sl_node_init(out, "Identifier");
    add_property_str(out, "name", zend_string_copy(name));
    return true;
}

static bool sl_make_number(double v, zval *out) {
    sl_node_init(out, "NumberLiteral");
    add_property_double(out, "value", v);
    return true;
}

static bool sl_make_string(zend_string *s, zval *out) {
    sl_node_init(out, "StringLiteral");
    add_property_str(out, "value", zend_string_copy(s));
    return true;
}

static bool sl_make_boolean(bool b, zval *out) {
    sl_node_init(out, "BooleanLiteral");
    add_property_bool(out, "value", b);
    return true;
}

static bool sl_make_null(zval *out) {
    sl_node_init(out, "NullLiteral");
    return true;
}

static bool sl_make_undefined(zval *out) {
    sl_node_init(out, "UndefinedLiteral");
    return true;
}

static bool sl_make_this(zval *out) {
    sl_node_init(out, "ThisExpr");
    return true;
}

static bool sl_make_unary(const char *op, zval *operand, zval *out) {
    sl_node_init(out, "UnaryExpr");
    add_property_string(out, "operator", op);
    add_property_zval(out, "operand", operand);
    ZVAL_UNDEF(operand);
    return true;
}

static bool sl_make_typeof(zval *operand, zval *out) {
    sl_node_init(out, "TypeofExpr");
    add_property_zval(out, "operand", operand);
    ZVAL_UNDEF(operand);
    return true;
}

static bool sl_make_void(zval *operand, zval *out) {
    sl_node_init(out, "VoidExpr");
    add_property_zval(out, "operand", operand);
    ZVAL_UNDEF(operand);
    return true;
}

static bool sl_make_delete(zval *operand, zval *out) {
    sl_node_init(out, "DeleteExpr");
    add_property_zval(out, "operand", operand);
    ZVAL_UNDEF(operand);
    return true;
}

static bool sl_make_binary(const char *op, zval *left, zval *right, bool logical, zval *out) {
    sl_node_init(out, logical ? "LogicalExpr" : "BinaryExpr");
    add_property_zval(out, "left", left);
    add_property_string(out, "operator", op);
    add_property_zval(out, "right", right);
    ZVAL_UNDEF(left);
    ZVAL_UNDEF(right);
    return true;
}

static bool sl_make_conditional(zval *cond, zval *cons, zval *alt, zval *out) {
    sl_node_init(out, "ConditionalExpr");
    add_property_zval(out, "condition", cond);
    add_property_zval(out, "consequent", cons);
    add_property_zval(out, "alternate", alt);
    ZVAL_UNDEF(cond);
    ZVAL_UNDEF(cons);
    ZVAL_UNDEF(alt);
    return true;
}

static bool sl_make_update(const char *op, zval *arg, bool prefix, zval *out) {
    sl_node_init(out, "UpdateExpr");
    add_property_string(out, "operator", op);
    add_property_zval(out, "argument", arg);
    add_property_bool(out, "prefix", prefix);
    ZVAL_UNDEF(arg);
    return true;
}

static bool sl_make_assign_identifier(zend_string *name, const char *op, zval *value, zval *out) {
    sl_node_init(out, "AssignExpr");
    add_property_str(out, "name", zend_string_copy(name));
    add_property_string(out, "operator", op);
    add_property_zval(out, "value", value);
    ZVAL_UNDEF(value);
    return true;
}

static bool sl_make_member_expr(
    zval *object,
    zval *property,
    bool computed,
    bool optional,
    bool optional_chain,
    zval *out
) {
    sl_node_init(out, "MemberExpr");
    add_property_zval(out, "object", object);
    add_property_zval(out, "property", property);
    add_property_bool(out, "computed", computed);
    add_property_bool(out, "optional", optional);
    add_property_bool(out, "optionalChain", optional_chain);
    ZVAL_UNDEF(object);
    ZVAL_UNDEF(property);
    return true;
}

static bool sl_make_member_assign(zval *object, zval *property, bool computed, const char *op, zval *value, zval *out) {
    sl_node_init(out, "MemberAssignExpr");
    add_property_zval(out, "object", object);
    add_property_zval(out, "property", property);
    add_property_bool(out, "computed", computed);
    add_property_string(out, "operator", op);
    add_property_zval(out, "value", value);
    ZVAL_UNDEF(object);
    ZVAL_UNDEF(property);
    ZVAL_UNDEF(value);
    return true;
}

static bool sl_make_call(zval *callee, zval *args, bool optional, bool optional_chain, zval *out) {
    sl_node_init(out, "CallExpr");
    add_property_zval(out, "callee", callee);
    add_property_zval(out, "arguments", args);
    add_property_bool(out, "optional", optional);
    add_property_bool(out, "optionalChain", optional_chain);
    ZVAL_UNDEF(callee);
    ZVAL_UNDEF(args);
    return true;
}

static bool sl_make_spread(zval *argument, zval *out) {
    sl_node_init(out, "SpreadElement");
    add_property_zval(out, "argument", argument);
    ZVAL_UNDEF(argument);
    return true;
}

static bool sl_make_new(zval *callee, zval *args, zval *out) {
    sl_node_init(out, "NewExpr");
    add_property_zval(out, "callee", callee);
    add_property_zval(out, "arguments", args);
    ZVAL_UNDEF(callee);
    ZVAL_UNDEF(args);
    return true;
}

static bool sl_make_array_literal(zval *elements, zval *out) {
    sl_node_init(out, "ArrayLiteral");
    add_property_zval(out, "elements", elements);
    ZVAL_UNDEF(elements);
    return true;
}

static bool sl_make_object_property(
    zend_string *key,
    zval *value,
    bool computed,
    zval *computed_key,
    zval *out
) {
    sl_node_init(out, "ObjectProperty");
    if (key) {
        add_property_str(out, "key", zend_string_copy(key));
    } else {
        add_property_null(out, "key");
    }
    add_property_zval(out, "value", value);
    add_property_bool(out, "computed", computed);
    if (computed_key) {
        add_property_zval(out, "computedKey", computed_key);
        ZVAL_UNDEF(computed_key);
    } else {
        add_property_null(out, "computedKey");
    }
    ZVAL_UNDEF(value);
    return true;
}

static bool sl_make_object_literal(zval *properties, zval *out) {
    sl_node_init(out, "ObjectLiteral");
    add_property_zval(out, "properties", properties);
    ZVAL_UNDEF(properties);
    return true;
}

static bool sl_make_regex(zend_string *pattern, zend_string *flags, zval *out) {
    sl_node_init(out, "RegexLiteral");
    add_property_str(out, "pattern", zend_string_copy(pattern));
    add_property_str(out, "flags", zend_string_copy(flags));
    return true;
}

static bool sl_make_var_decl(const char *kind, zend_string *name, zval *initializer_or_null, zval *out) {
    sl_node_init(out, "VarDeclaration");
    add_property_string(out, "kind", kind);
    add_property_str(out, "name", zend_string_copy(name));
    add_property_zval(out, "initializer", initializer_or_null);
    ZVAL_UNDEF(initializer_or_null);
    return true;
}

static bool sl_make_var_decl_list(zval *decls, zval *out) {
    sl_node_init(out, "VarDeclarationList");
    add_property_zval(out, "declarations", decls);
    ZVAL_UNDEF(decls);
    return true;
}

static bool sl_make_destructuring_decl(
    const char *kind,
    zval *bindings,
    zval *rest_name,
    zval *initializer,
    bool is_array,
    zval *out
) {
    sl_node_init(out, "DestructuringDeclaration");
    add_property_string(out, "kind", kind);
    add_property_zval(out, "bindings", bindings);
    add_property_zval(out, "restName", rest_name);
    add_property_zval(out, "initializer", initializer);
    add_property_bool(out, "isArray", is_array);
    ZVAL_UNDEF(bindings);
    ZVAL_UNDEF(rest_name);
    ZVAL_UNDEF(initializer);
    return true;
}

static bool sl_make_function_decl(
    zend_string *name,
    zval *params,
    zval *body,
    zval *rest_param,
    zval *defaults,
    zval *param_destr,
    zval *out
) {
    sl_node_init(out, "FunctionDeclaration");
    add_property_str(out, "name", zend_string_copy(name));
    add_property_zval(out, "params", params);
    add_property_zval(out, "body", body);
    add_property_zval(out, "restParam", rest_param);
    add_property_zval(out, "defaults", defaults);
    add_property_zval(out, "paramDestructures", param_destr);
    ZVAL_UNDEF(params);
    ZVAL_UNDEF(body);
    ZVAL_UNDEF(rest_param);
    ZVAL_UNDEF(defaults);
    ZVAL_UNDEF(param_destr);
    return true;
}

static bool sl_make_function_expr(
    zval *name_or_null,
    zval *params,
    zval *body,
    bool is_arrow,
    zval *rest_param,
    zval *defaults,
    zval *param_destr,
    zval *out
) {
    sl_node_init(out, "FunctionExpr");
    add_property_zval(out, "name", name_or_null);
    add_property_zval(out, "params", params);
    add_property_zval(out, "body", body);
    add_property_bool(out, "isArrow", is_arrow);
    add_property_zval(out, "restParam", rest_param);
    add_property_zval(out, "defaults", defaults);
    add_property_zval(out, "paramDestructures", param_destr);
    ZVAL_UNDEF(name_or_null);
    ZVAL_UNDEF(params);
    ZVAL_UNDEF(body);
    ZVAL_UNDEF(rest_param);
    ZVAL_UNDEF(defaults);
    ZVAL_UNDEF(param_destr);
    return true;
}

static bool sl_make_return_stmt(zval *value_or_null, zval *out) {
    sl_node_init(out, "ReturnStmt");
    add_property_zval(out, "value", value_or_null);
    ZVAL_UNDEF(value_or_null);
    return true;
}

static bool sl_make_block_stmt(zval *statements, zval *out) {
    sl_node_init(out, "BlockStmt");
    add_property_zval(out, "statements", statements);
    ZVAL_UNDEF(statements);
    return true;
}

static bool sl_make_if_stmt(zval *condition, zval *consequent, zval *alternate_or_null, zval *out) {
    sl_node_init(out, "IfStmt");
    add_property_zval(out, "condition", condition);
    add_property_zval(out, "consequent", consequent);
    add_property_zval(out, "alternate", alternate_or_null);
    ZVAL_UNDEF(condition);
    ZVAL_UNDEF(consequent);
    ZVAL_UNDEF(alternate_or_null);
    return true;
}

static bool sl_make_while_stmt(zval *condition, zval *body, zval *out) {
    sl_node_init(out, "WhileStmt");
    add_property_zval(out, "condition", condition);
    add_property_zval(out, "body", body);
    ZVAL_UNDEF(condition);
    ZVAL_UNDEF(body);
    return true;
}

static bool sl_make_do_while_stmt(zval *condition, zval *body, zval *out) {
    sl_node_init(out, "DoWhileStmt");
    add_property_zval(out, "condition", condition);
    add_property_zval(out, "body", body);
    ZVAL_UNDEF(condition);
    ZVAL_UNDEF(body);
    return true;
}

static bool sl_make_for_stmt(zval *init, zval *condition, zval *update, zval *body, zval *out) {
    sl_node_init(out, "ForStmt");
    add_property_zval(out, "init", init);
    add_property_zval(out, "condition", condition);
    add_property_zval(out, "update", update);
    add_property_zval(out, "body", body);
    ZVAL_UNDEF(init);
    ZVAL_UNDEF(condition);
    ZVAL_UNDEF(update);
    ZVAL_UNDEF(body);
    return true;
}

static bool sl_make_for_of_stmt(
    const char *kind,
    zend_string *name,
    zval *iterable,
    zval *body,
    zval *out
) {
    sl_node_init(out, "ForOfStmt");
    add_property_string(out, "kind", kind);
    add_property_str(out, "name", zend_string_copy(name));
    add_property_zval(out, "iterable", iterable);
    add_property_zval(out, "body", body);
    ZVAL_UNDEF(iterable);
    ZVAL_UNDEF(body);
    return true;
}

static bool sl_make_for_in_stmt(
    const char *kind,
    zend_string *name,
    zval *object,
    zval *body,
    zval *out
) {
    sl_node_init(out, "ForInStmt");
    add_property_string(out, "kind", kind);
    add_property_str(out, "name", zend_string_copy(name));
    add_property_zval(out, "object", object);
    add_property_zval(out, "body", body);
    ZVAL_UNDEF(object);
    ZVAL_UNDEF(body);
    return true;
}

static bool sl_make_switch_case(zval *test_or_null, zval *consequent, zval *out) {
    sl_node_init(out, "SwitchCase");
    add_property_zval(out, "test", test_or_null);
    add_property_zval(out, "consequent", consequent);
    ZVAL_UNDEF(test_or_null);
    ZVAL_UNDEF(consequent);
    return true;
}

static bool sl_make_switch_stmt(zval *discriminant, zval *cases, zval *out) {
    sl_node_init(out, "SwitchStmt");
    add_property_zval(out, "discriminant", discriminant);
    add_property_zval(out, "cases", cases);
    ZVAL_UNDEF(discriminant);
    ZVAL_UNDEF(cases);
    return true;
}

static bool sl_make_break_stmt(zval *out) {
    sl_node_init(out, "BreakStmt");
    return true;
}

static bool sl_make_continue_stmt(zval *out) {
    sl_node_init(out, "ContinueStmt");
    return true;
}

static bool sl_make_throw_stmt(zval *argument, zval *out) {
    sl_node_init(out, "ThrowStmt");
    add_property_zval(out, "argument", argument);
    ZVAL_UNDEF(argument);
    return true;
}

static bool sl_make_catch_clause(zval *param_or_null, zval *body, zval *out) {
    sl_node_init(out, "CatchClause");
    add_property_zval(out, "param", param_or_null);
    add_property_zval(out, "body", body);
    ZVAL_UNDEF(param_or_null);
    ZVAL_UNDEF(body);
    return true;
}

static bool sl_make_try_catch_stmt(zval *block, zval *handler, zval *finalizer, zval *out) {
    sl_node_init(out, "TryCatchStmt");
    add_property_zval(out, "block", block);
    add_property_zval(out, "handler", handler);
    add_property_zval(out, "finalizer", finalizer);
    ZVAL_UNDEF(block);
    ZVAL_UNDEF(handler);
    ZVAL_UNDEF(finalizer);
    return true;
}

static bool sl_make_sequence(zval *expressions, zval *out) {
    sl_node_init(out, "SequenceExpr");
    add_property_zval(out, "expressions", expressions);
    ZVAL_UNDEF(expressions);
    return true;
}

static bool sl_make_template_literal(zval *quasis, zval *expressions, zval *out) {
    sl_node_init(out, "TemplateLiteral");
    add_property_zval(out, "quasis", quasis);
    add_property_zval(out, "expressions", expressions);
    ZVAL_UNDEF(quasis);
    ZVAL_UNDEF(expressions);
    return true;
}

/* ------------------------------------------------------------------------- */
/* Parser primitives                                                         */
/* ------------------------------------------------------------------------- */
static zend_always_inline sl_token *sl_p_cur(sl_parser *p) {
    if (p->pos >= p->count) {
        return &p->tokens[p->count - 1];
    }
    return &p->tokens[p->pos];
}

static zend_always_inline sl_token *sl_p_prev(sl_parser *p) {
    if (p->pos == 0) {
        return &p->tokens[0];
    }
    return &p->tokens[p->pos - 1];
}

static zend_always_inline bool sl_p_check(sl_parser *p, sl_tok_type t) {
    return sl_p_cur(p)->type == t;
}

static zend_always_inline bool sl_p_check_ident_lexeme(sl_parser *p, const char *lex) {
    sl_token *tok = sl_p_cur(p);
    if (tok->type != SL_TOK_IDENTIFIER || !tok->lexeme) {
        return false;
    }
    return zend_string_equals_cstr(tok->lexeme, lex, strlen(lex));
}

static zend_always_inline bool sl_p_match(sl_parser *p, sl_tok_type t) {
    if (sl_p_check(p, t)) {
        p->pos++;
        return true;
    }
    return false;
}

static bool sl_p_expect(sl_parser *p, sl_tok_type t) {
    if (sl_p_match(p, t)) {
        return true;
    }
    p->error = true;
    return false;
}

static void sl_p_consume_semicolon(sl_parser *p) {
    if (sl_p_match(p, SL_TOK_SEMICOLON)) {
        return;
    }
    /* permissive ASI for this subset */
}

static bool sl_is_assignment_op(sl_tok_type t) {
    switch (t) {
        case SL_TOK_ASSIGN:
        case SL_TOK_PLUS_ASSIGN:
        case SL_TOK_MINUS_ASSIGN:
        case SL_TOK_STAR_ASSIGN:
        case SL_TOK_SLASH_ASSIGN:
        case SL_TOK_PERCENT_ASSIGN:
        case SL_TOK_AND_ASSIGN:
        case SL_TOK_PIPE_ASSIGN:
        case SL_TOK_CARET_ASSIGN:
        case SL_TOK_SHIFT_LEFT_ASSIGN:
        case SL_TOK_SHIFT_RIGHT_ASSIGN:
        case SL_TOK_SHIFT_RIGHT_UNSIGNED_ASSIGN:
        case SL_TOK_STAR_STAR_ASSIGN:
        case SL_TOK_NULLISH_ASSIGN:
            return true;
        default:
            return false;
    }
}

static int sl_rel_prec(sl_tok_type t) {
    switch (t) {
        case SL_TOK_LT:
        case SL_TOK_LTE:
        case SL_TOK_GT:
        case SL_TOK_GTE:
        case SL_TOK_IN:
        case SL_TOK_INSTANCEOF:
            return 1;
        default:
            return 0;
    }
}

static bool sl_parse_program(sl_parser *p, zval *out) {
    zval body;
    array_init(&body);

    while (!sl_p_check(p, SL_TOK_EOF)) {
        zval stmt;
        ZVAL_UNDEF(&stmt);
        if (!sl_parse_statement(p, &stmt)) {
            zval_ptr_dtor(&body);
            if (Z_TYPE(stmt) != IS_UNDEF) {
                zval_ptr_dtor(&stmt);
            }
            return false;
        }
        add_next_index_zval(&body, &stmt);
        ZVAL_UNDEF(&stmt);
    }

    return sl_make_program(&body, out);
}

static bool sl_parse_statement(sl_parser *p, zval *out) {
    sl_tok_type t = sl_p_cur(p)->type;
    switch (t) {
        case SL_TOK_VAR:
        case SL_TOK_LET:
        case SL_TOK_CONST:
            return sl_parse_var_decl_stmt(p, out);
        case SL_TOK_FUNCTION:
            return sl_parse_function_decl(p, out);
        case SL_TOK_RETURN:
            return sl_parse_return_stmt(p, out);
        case SL_TOK_LBRACE:
            return sl_parse_block_stmt(p, out);
        case SL_TOK_IF:
            return sl_parse_if_stmt(p, out);
        case SL_TOK_WHILE:
            return sl_parse_while_stmt(p, out);
        case SL_TOK_DO:
            return sl_parse_do_while_stmt(p, out);
        case SL_TOK_FOR:
            return sl_parse_for_stmt(p, out);
        case SL_TOK_SWITCH:
            return sl_parse_switch_stmt(p, out);
        case SL_TOK_TRY:
            return sl_parse_try_stmt(p, out);
        case SL_TOK_THROW:
            return sl_parse_throw_stmt(p, out);
        case SL_TOK_BREAK:
            return sl_parse_break_stmt(p, out);
        case SL_TOK_CONTINUE:
            return sl_parse_continue_stmt(p, out);
        case SL_TOK_CASE:
        case SL_TOK_DEFAULT:
        case SL_TOK_CATCH:
            p->error = true;
            return false;
        default:
            return sl_parse_expr_stmt(p, out);
    }
}

static bool sl_parse_var_decl_inner(sl_parser *p, zval *out) {
    const char *kind = "var";
    if (sl_p_match(p, SL_TOK_VAR)) kind = "var";
    else if (sl_p_match(p, SL_TOK_LET)) kind = "let";
    else if (sl_p_match(p, SL_TOK_CONST)) kind = "const";
    else {
        p->error = true;
        return false;
    }

    if (sl_p_check(p, SL_TOK_LBRACKET)) {
        return sl_parse_array_destructuring_decl(p, kind, out);
    }
    if (sl_p_check(p, SL_TOK_LBRACE)) {
        return sl_parse_object_destructuring_decl(p, kind, out);
    }

    zval decls;
    array_init(&decls);
    int count = 0;

    while (1) {
        if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
            zval_ptr_dtor(&decls);
            p->error = true;
            return false;
        }
        zend_string *name = sl_p_cur(p)->lexeme;
        p->pos++;

        zval init;
        ZVAL_NULL(&init);

        if (sl_p_match(p, SL_TOK_ASSIGN)) {
            if (!sl_parse_assignment(p, &init)) {
                zval_ptr_dtor(&decls);
                if (Z_TYPE(init) != IS_UNDEF) zval_ptr_dtor(&init);
                return false;
            }
        }

        zval one_decl;
        ZVAL_UNDEF(&one_decl);
        sl_make_var_decl(kind, name, &init, &one_decl);
        add_next_index_zval(&decls, &one_decl);
        ZVAL_UNDEF(&one_decl);
        count++;

        if (!sl_p_match(p, SL_TOK_COMMA)) {
            break;
        }
    }

    if (count == 1) {
        zval *first = zend_hash_index_find(Z_ARRVAL(decls), 0);
        if (!first) {
            zval_ptr_dtor(&decls);
            p->error = true;
            return false;
        }
        ZVAL_COPY(out, first);
        zval_ptr_dtor(&decls);
        return true;
    }

    return sl_make_var_decl_list(&decls, out);
}

static bool sl_parse_var_decl_stmt(sl_parser *p, zval *out) {
    if (!sl_parse_var_decl_inner(p, out)) {
        return false;
    }
    sl_p_consume_semicolon(p);
    return true;
}

static bool sl_parse_block_stmt(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_LBRACE)) {
        return false;
    }

    zval statements;
    array_init(&statements);

    while (!sl_p_check(p, SL_TOK_RBRACE) && !sl_p_check(p, SL_TOK_EOF)) {
        zval stmt;
        ZVAL_UNDEF(&stmt);
        if (!sl_parse_statement(p, &stmt)) {
            if (Z_TYPE(stmt) != IS_UNDEF) zval_ptr_dtor(&stmt);
            zval_ptr_dtor(&statements);
            return false;
        }
        add_next_index_zval(&statements, &stmt);
        ZVAL_UNDEF(&stmt);
    }

    if (!sl_p_expect(p, SL_TOK_RBRACE)) {
        zval_ptr_dtor(&statements);
        return false;
    }

    return sl_make_block_stmt(&statements, out);
}

static bool sl_pattern_add_binding(
    zval *bindings,
    zval *name_or_null,
    zval *source,
    zval *default_or_null,
    zval *nested_or_null
) {
    zval binding;
    array_init(&binding);

    if (name_or_null && Z_TYPE_P(name_or_null) == IS_STRING) {
        add_assoc_str(&binding, "name", zend_string_copy(Z_STR_P(name_or_null)));
    } else {
        add_assoc_null(&binding, "name");
    }
    add_assoc_zval(&binding, "source", source);
    ZVAL_UNDEF(source);
    add_assoc_zval(&binding, "default", default_or_null);
    ZVAL_UNDEF(default_or_null);
    if (nested_or_null) {
        add_assoc_zval(&binding, "nested", nested_or_null);
        ZVAL_UNDEF(nested_or_null);
    }

    add_next_index_zval(bindings, &binding);
    ZVAL_UNDEF(&binding);
    return true;
}

static bool sl_parse_nested_pattern(sl_parser *p, bool is_array, zval *out_pattern) {
    zval bindings;
    array_init(&bindings);
    zval rest_name;
    ZVAL_NULL(&rest_name);

    if (is_array) {
        if (!sl_p_expect(p, SL_TOK_LBRACKET)) {
            zval_ptr_dtor(&bindings);
            zval_ptr_dtor(&rest_name);
            return false;
        }

        zend_long index = 0;
        while (!sl_p_check(p, SL_TOK_RBRACKET) && !sl_p_check(p, SL_TOK_EOF)) {
            if (sl_p_match(p, SL_TOK_COMMA)) {
                index++;
                continue;
            }

            if (sl_p_match(p, SL_TOK_SPREAD)) {
                if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                    zval_ptr_dtor(&bindings);
                    zval_ptr_dtor(&rest_name);
                    p->error = true;
                    return false;
                }
                ZVAL_STR_COPY(&rest_name, sl_p_cur(p)->lexeme);
                p->pos++;
                break;
            }

            if (sl_p_check(p, SL_TOK_LBRACKET) || sl_p_check(p, SL_TOK_LBRACE)) {
                bool nested_is_array = sl_p_check(p, SL_TOK_LBRACKET);
                zval nested;
                ZVAL_UNDEF(&nested);
                if (!sl_parse_nested_pattern(p, nested_is_array, &nested)) {
                    zval_ptr_dtor(&bindings);
                    zval_ptr_dtor(&rest_name);
                    if (Z_TYPE(nested) != IS_UNDEF) {
                        zval_ptr_dtor(&nested);
                    }
                    return false;
                }

                zval def;
                ZVAL_NULL(&def);
                if (sl_p_match(p, SL_TOK_ASSIGN)) {
                    if (!sl_parse_assignment(p, &def)) {
                        zval_ptr_dtor(&bindings);
                        zval_ptr_dtor(&rest_name);
                        zval_ptr_dtor(&nested);
                        if (Z_TYPE(def) != IS_UNDEF) {
                            zval_ptr_dtor(&def);
                        }
                        return false;
                    }
                }

                zval name_null;
                ZVAL_NULL(&name_null);
                zval source;
                ZVAL_LONG(&source, index);
                sl_pattern_add_binding(&bindings, &name_null, &source, &def, &nested);
                index++;

                if (!sl_p_match(p, SL_TOK_COMMA)) {
                    break;
                }
                continue;
            }

            if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                zval_ptr_dtor(&bindings);
                zval_ptr_dtor(&rest_name);
                p->error = true;
                return false;
            }

            zend_string *name = sl_p_cur(p)->lexeme;
            p->pos++;

            zval def;
            ZVAL_NULL(&def);
            if (sl_p_match(p, SL_TOK_ASSIGN)) {
                if (!sl_parse_assignment(p, &def)) {
                    zval_ptr_dtor(&bindings);
                    zval_ptr_dtor(&rest_name);
                    if (Z_TYPE(def) != IS_UNDEF) {
                        zval_ptr_dtor(&def);
                    }
                    return false;
                }
            }

            zval name_zv;
            ZVAL_STR_COPY(&name_zv, name);
            zval source;
            ZVAL_LONG(&source, index);
            sl_pattern_add_binding(&bindings, &name_zv, &source, &def, NULL);
            zval_ptr_dtor(&name_zv);
            index++;

            if (!sl_p_match(p, SL_TOK_COMMA)) {
                break;
            }
        }

        if (!sl_p_expect(p, SL_TOK_RBRACKET)) {
            zval_ptr_dtor(&bindings);
            zval_ptr_dtor(&rest_name);
            return false;
        }
    } else {
        if (!sl_p_expect(p, SL_TOK_LBRACE)) {
            zval_ptr_dtor(&bindings);
            zval_ptr_dtor(&rest_name);
            return false;
        }

        while (!sl_p_check(p, SL_TOK_RBRACE) && !sl_p_check(p, SL_TOK_EOF)) {
            if (sl_p_match(p, SL_TOK_SPREAD)) {
                if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                    zval_ptr_dtor(&bindings);
                    zval_ptr_dtor(&rest_name);
                    p->error = true;
                    return false;
                }
                ZVAL_STR_COPY(&rest_name, sl_p_cur(p)->lexeme);
                p->pos++;
                break;
            }

            if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                zval_ptr_dtor(&bindings);
                zval_ptr_dtor(&rest_name);
                p->error = true;
                return false;
            }
            zend_string *key = sl_p_cur(p)->lexeme;
            p->pos++;

            if (sl_p_match(p, SL_TOK_COLON)
                && (sl_p_check(p, SL_TOK_LBRACE) || sl_p_check(p, SL_TOK_LBRACKET))) {
                bool nested_is_array = sl_p_check(p, SL_TOK_LBRACKET);
                zval nested;
                ZVAL_UNDEF(&nested);
                if (!sl_parse_nested_pattern(p, nested_is_array, &nested)) {
                    zval_ptr_dtor(&bindings);
                    zval_ptr_dtor(&rest_name);
                    if (Z_TYPE(nested) != IS_UNDEF) {
                        zval_ptr_dtor(&nested);
                    }
                    return false;
                }

                zval def;
                ZVAL_NULL(&def);
                if (sl_p_match(p, SL_TOK_ASSIGN)) {
                    if (!sl_parse_assignment(p, &def)) {
                        zval_ptr_dtor(&bindings);
                        zval_ptr_dtor(&rest_name);
                        zval_ptr_dtor(&nested);
                        if (Z_TYPE(def) != IS_UNDEF) {
                            zval_ptr_dtor(&def);
                        }
                        return false;
                    }
                }

                zval name_null;
                ZVAL_NULL(&name_null);
                zval source;
                ZVAL_STR_COPY(&source, key);
                sl_pattern_add_binding(&bindings, &name_null, &source, &def, &nested);

                if (!sl_p_match(p, SL_TOK_COMMA)) {
                    break;
                }
                continue;
            }

            zend_string *local_name = key;
            if (sl_p_prev(p)->type == SL_TOK_COLON) {
                if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                    zval_ptr_dtor(&bindings);
                    zval_ptr_dtor(&rest_name);
                    p->error = true;
                    return false;
                }
                local_name = sl_p_cur(p)->lexeme;
                p->pos++;
            }

            zval def;
            ZVAL_NULL(&def);
            if (sl_p_match(p, SL_TOK_ASSIGN)) {
                if (!sl_parse_assignment(p, &def)) {
                    zval_ptr_dtor(&bindings);
                    zval_ptr_dtor(&rest_name);
                    if (Z_TYPE(def) != IS_UNDEF) {
                        zval_ptr_dtor(&def);
                    }
                    return false;
                }
            }

            zval name_zv, source;
            ZVAL_STR_COPY(&name_zv, local_name);
            ZVAL_STR_COPY(&source, key);
            sl_pattern_add_binding(&bindings, &name_zv, &source, &def, NULL);
            zval_ptr_dtor(&name_zv);

            if (!sl_p_match(p, SL_TOK_COMMA)) {
                break;
            }
        }

        if (!sl_p_expect(p, SL_TOK_RBRACE)) {
            zval_ptr_dtor(&bindings);
            zval_ptr_dtor(&rest_name);
            return false;
        }
    }

    array_init(out_pattern);
    add_assoc_bool(out_pattern, "isArray", is_array);
    add_assoc_zval(out_pattern, "bindings", &bindings);
    ZVAL_UNDEF(&bindings);
    add_assoc_zval(out_pattern, "restName", &rest_name);
    ZVAL_UNDEF(&rest_name);
    return true;
}

static bool sl_parse_array_destructuring_decl(sl_parser *p, const char *kind, zval *out) {
    zval pattern;
    ZVAL_UNDEF(&pattern);
    if (!sl_parse_nested_pattern(p, true, &pattern)) {
        if (Z_TYPE(pattern) != IS_UNDEF) {
            zval_ptr_dtor(&pattern);
        }
        return false;
    }

    if (!sl_p_expect(p, SL_TOK_ASSIGN)) {
        zval_ptr_dtor(&pattern);
        return false;
    }

    zval initializer;
    ZVAL_UNDEF(&initializer);
    if (!sl_parse_expression(p, &initializer)) {
        zval_ptr_dtor(&pattern);
        if (Z_TYPE(initializer) != IS_UNDEF) {
            zval_ptr_dtor(&initializer);
        }
        return false;
    }

    zval *bindings_zv = zend_hash_str_find(Z_ARRVAL(pattern), "bindings", sizeof("bindings") - 1);
    zval *rest_zv = zend_hash_str_find(Z_ARRVAL(pattern), "restName", sizeof("restName") - 1);
    zval bindings_copy, rest_copy;
    if (bindings_zv && Z_TYPE_P(bindings_zv) == IS_ARRAY) {
        ZVAL_COPY(&bindings_copy, bindings_zv);
    } else {
        array_init(&bindings_copy);
    }
    if (rest_zv) {
        ZVAL_COPY(&rest_copy, rest_zv);
    } else {
        ZVAL_NULL(&rest_copy);
    }

    zval_ptr_dtor(&pattern);
    return sl_make_destructuring_decl(kind, &bindings_copy, &rest_copy, &initializer, true, out);
}

static bool sl_parse_object_destructuring_decl(sl_parser *p, const char *kind, zval *out) {
    zval pattern;
    ZVAL_UNDEF(&pattern);
    if (!sl_parse_nested_pattern(p, false, &pattern)) {
        if (Z_TYPE(pattern) != IS_UNDEF) {
            zval_ptr_dtor(&pattern);
        }
        return false;
    }

    if (!sl_p_expect(p, SL_TOK_ASSIGN)) {
        zval_ptr_dtor(&pattern);
        return false;
    }

    zval initializer;
    ZVAL_UNDEF(&initializer);
    if (!sl_parse_expression(p, &initializer)) {
        zval_ptr_dtor(&pattern);
        if (Z_TYPE(initializer) != IS_UNDEF) {
            zval_ptr_dtor(&initializer);
        }
        return false;
    }

    zval *bindings_zv = zend_hash_str_find(Z_ARRVAL(pattern), "bindings", sizeof("bindings") - 1);
    zval *rest_zv = zend_hash_str_find(Z_ARRVAL(pattern), "restName", sizeof("restName") - 1);
    zval bindings_copy, rest_copy;
    if (bindings_zv && Z_TYPE_P(bindings_zv) == IS_ARRAY) {
        ZVAL_COPY(&bindings_copy, bindings_zv);
    } else {
        array_init(&bindings_copy);
    }
    if (rest_zv) {
        ZVAL_COPY(&rest_copy, rest_zv);
    } else {
        ZVAL_NULL(&rest_copy);
    }

    zval_ptr_dtor(&pattern);
    return sl_make_destructuring_decl(kind, &bindings_copy, &rest_copy, &initializer, false, out);
}

static bool sl_parse_function_params(
    sl_parser *p,
    zval *out_params,
    zval *out_defaults,
    zval *out_rest_param,
    zval *out_param_destructures
) {
    array_init(out_params);
    array_init(out_defaults);
    ZVAL_NULL(out_rest_param);
    array_init(out_param_destructures);

    if (!sl_p_expect(p, SL_TOK_LPAREN)) {
        return false;
    }

    bool has_defaults = false;
    int synthetic_idx = 0;
    zend_ulong param_index = 0;

    if (!sl_p_check(p, SL_TOK_RPAREN)) {
        while (1) {
            if (sl_p_match(p, SL_TOK_SPREAD)) {
                if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                    p->error = true;
                    return false;
                }
                ZVAL_STR_COPY(out_rest_param, sl_p_cur(p)->lexeme);
                p->pos++;
                break;
            }

            zval def;
            ZVAL_NULL(&def);

            if (sl_p_check(p, SL_TOK_LBRACE) || sl_p_check(p, SL_TOK_LBRACKET)) {
                bool is_array = sl_p_check(p, SL_TOK_LBRACKET);
                zval pattern;
                ZVAL_UNDEF(&pattern);
                if (!sl_parse_nested_pattern(p, is_array, &pattern)) {
                    if (Z_TYPE(pattern) != IS_UNDEF) {
                        zval_ptr_dtor(&pattern);
                    }
                    if (Z_TYPE(def) != IS_UNDEF) {
                        zval_ptr_dtor(&def);
                    }
                    return false;
                }

                char tmp_buf[32];
                int n = snprintf(tmp_buf, sizeof(tmp_buf), "__p%d", synthetic_idx++);
                if (n < 0) {
                    zval_ptr_dtor(&pattern);
                    zval_ptr_dtor(&def);
                    p->error = true;
                    return false;
                }
                add_next_index_str(out_params, zend_string_init(tmp_buf, (size_t)n, 0));

                if (sl_p_match(p, SL_TOK_ASSIGN)) {
                    if (!sl_parse_assignment(p, &def)) {
                        zval_ptr_dtor(&pattern);
                        if (Z_TYPE(def) != IS_UNDEF) {
                            zval_ptr_dtor(&def);
                        }
                        return false;
                    }
                    has_defaults = true;
                }
                add_next_index_zval(out_defaults, &def);
                ZVAL_UNDEF(&def);

                zend_hash_index_update(Z_ARRVAL_P(out_param_destructures), param_index, &pattern);
                ZVAL_UNDEF(&pattern);
                param_index++;
            } else {
                if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                    p->error = true;
                    if (Z_TYPE(def) != IS_UNDEF) {
                        zval_ptr_dtor(&def);
                    }
                    return false;
                }
                zend_string *name = sl_p_cur(p)->lexeme;
                p->pos++;

                add_next_index_str(out_params, zend_string_copy(name));

                if (sl_p_match(p, SL_TOK_ASSIGN)) {
                    if (!sl_parse_assignment(p, &def)) {
                        if (Z_TYPE(def) != IS_UNDEF) zval_ptr_dtor(&def);
                        return false;
                    }
                    has_defaults = true;
                }
                add_next_index_zval(out_defaults, &def);
                ZVAL_UNDEF(&def);
                param_index++;
            }

            if (!sl_p_match(p, SL_TOK_COMMA)) {
                break;
            }
            if (sl_p_check(p, SL_TOK_RPAREN)) {
                break;
            }
        }
    }

    if (!sl_p_expect(p, SL_TOK_RPAREN)) {
        return false;
    }

    if (!has_defaults) {
        zval_ptr_dtor(out_defaults);
        array_init(out_defaults);
    }

    return true;
}

static bool sl_parse_function_decl(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_FUNCTION)) {
        return false;
    }
    if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
        p->error = true;
        return false;
    }

    zend_string *name = sl_p_cur(p)->lexeme;
    p->pos++;

    zval params, defaults, rest_param, param_destr;
    ZVAL_UNDEF(&params);
    ZVAL_UNDEF(&defaults);
    ZVAL_UNDEF(&rest_param);
    ZVAL_UNDEF(&param_destr);

    if (!sl_parse_function_params(p, &params, &defaults, &rest_param, &param_destr)) {
        if (Z_TYPE(params) != IS_UNDEF) zval_ptr_dtor(&params);
        if (Z_TYPE(defaults) != IS_UNDEF) zval_ptr_dtor(&defaults);
        if (Z_TYPE(rest_param) != IS_UNDEF) zval_ptr_dtor(&rest_param);
        if (Z_TYPE(param_destr) != IS_UNDEF) zval_ptr_dtor(&param_destr);
        return false;
    }

    zval block;
    ZVAL_UNDEF(&block);
    if (!sl_parse_block_stmt(p, &block)) {
        zval_ptr_dtor(&params);
        zval_ptr_dtor(&defaults);
        zval_ptr_dtor(&rest_param);
        zval_ptr_dtor(&param_destr);
        if (Z_TYPE(block) != IS_UNDEF) zval_ptr_dtor(&block);
        return false;
    }

    zval *body = sl_node_prop(&block, "statements");
    zval body_copy;
    if (body) {
        ZVAL_COPY(&body_copy, body);
    } else {
        array_init(&body_copy);
    }
    zval_ptr_dtor(&block);

    return sl_make_function_decl(name, &params, &body_copy, &rest_param, &defaults, &param_destr, out);
}

static bool sl_parse_function_expr(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_FUNCTION)) {
        return false;
    }

    zval name_zv;
    if (sl_p_check(p, SL_TOK_IDENTIFIER)) {
        ZVAL_STR_COPY(&name_zv, sl_p_cur(p)->lexeme);
        p->pos++;
    } else {
        ZVAL_NULL(&name_zv);
    }

    zval params, defaults, rest_param, param_destr;
    ZVAL_UNDEF(&params);
    ZVAL_UNDEF(&defaults);
    ZVAL_UNDEF(&rest_param);
    ZVAL_UNDEF(&param_destr);

    if (!sl_parse_function_params(p, &params, &defaults, &rest_param, &param_destr)) {
        zval_ptr_dtor(&name_zv);
        if (Z_TYPE(params) != IS_UNDEF) zval_ptr_dtor(&params);
        if (Z_TYPE(defaults) != IS_UNDEF) zval_ptr_dtor(&defaults);
        if (Z_TYPE(rest_param) != IS_UNDEF) zval_ptr_dtor(&rest_param);
        if (Z_TYPE(param_destr) != IS_UNDEF) zval_ptr_dtor(&param_destr);
        return false;
    }

    zval block;
    ZVAL_UNDEF(&block);
    if (!sl_parse_block_stmt(p, &block)) {
        zval_ptr_dtor(&name_zv);
        zval_ptr_dtor(&params);
        zval_ptr_dtor(&defaults);
        zval_ptr_dtor(&rest_param);
        zval_ptr_dtor(&param_destr);
        if (Z_TYPE(block) != IS_UNDEF) zval_ptr_dtor(&block);
        return false;
    }

    zval *body = sl_node_prop(&block, "statements");
    zval body_copy;
    if (body) {
        ZVAL_COPY(&body_copy, body);
    } else {
        array_init(&body_copy);
    }
    zval_ptr_dtor(&block);

    return sl_make_function_expr(&name_zv, &params, &body_copy, false, &rest_param, &defaults, &param_destr, out);
}

static bool sl_parse_if_stmt(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_IF)) {
        return false;
    }
    if (!sl_p_expect(p, SL_TOK_LPAREN)) {
        return false;
    }

    zval cond;
    ZVAL_UNDEF(&cond);
    if (!sl_parse_expression(p, &cond)) {
        if (Z_TYPE(cond) != IS_UNDEF) zval_ptr_dtor(&cond);
        return false;
    }
    if (!sl_p_expect(p, SL_TOK_RPAREN)) {
        zval_ptr_dtor(&cond);
        return false;
    }

    zval cons;
    ZVAL_UNDEF(&cons);
    if (!sl_parse_statement(p, &cons)) {
        zval_ptr_dtor(&cond);
        if (Z_TYPE(cons) != IS_UNDEF) zval_ptr_dtor(&cons);
        return false;
    }

    zval alt;
    ZVAL_NULL(&alt);
    if (sl_p_match(p, SL_TOK_ELSE)) {
        zval alt_stmt;
        ZVAL_UNDEF(&alt_stmt);
        if (!sl_parse_statement(p, &alt_stmt)) {
            zval_ptr_dtor(&cond);
            zval_ptr_dtor(&cons);
            if (Z_TYPE(alt_stmt) != IS_UNDEF) zval_ptr_dtor(&alt_stmt);
            return false;
        }
        ZVAL_COPY_VALUE(&alt, &alt_stmt);
    }

    return sl_make_if_stmt(&cond, &cons, &alt, out);
}

static bool sl_parse_while_stmt(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_WHILE)) {
        return false;
    }
    if (!sl_p_expect(p, SL_TOK_LPAREN)) {
        return false;
    }

    zval cond;
    ZVAL_UNDEF(&cond);
    if (!sl_parse_expression(p, &cond)) {
        if (Z_TYPE(cond) != IS_UNDEF) zval_ptr_dtor(&cond);
        return false;
    }
    if (!sl_p_expect(p, SL_TOK_RPAREN)) {
        zval_ptr_dtor(&cond);
        return false;
    }

    zval body;
    ZVAL_UNDEF(&body);
    if (!sl_parse_statement(p, &body)) {
        zval_ptr_dtor(&cond);
        if (Z_TYPE(body) != IS_UNDEF) zval_ptr_dtor(&body);
        return false;
    }

    return sl_make_while_stmt(&cond, &body, out);
}

static bool sl_parse_do_while_stmt(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_DO)) {
        return false;
    }

    zval body;
    ZVAL_UNDEF(&body);
    if (!sl_parse_statement(p, &body)) {
        if (Z_TYPE(body) != IS_UNDEF) {
            zval_ptr_dtor(&body);
        }
        return false;
    }

    if (!sl_p_expect(p, SL_TOK_WHILE) || !sl_p_expect(p, SL_TOK_LPAREN)) {
        zval_ptr_dtor(&body);
        return false;
    }

    zval cond;
    ZVAL_UNDEF(&cond);
    if (!sl_parse_expression(p, &cond)) {
        zval_ptr_dtor(&body);
        if (Z_TYPE(cond) != IS_UNDEF) {
            zval_ptr_dtor(&cond);
        }
        return false;
    }

    if (!sl_p_expect(p, SL_TOK_RPAREN)) {
        zval_ptr_dtor(&body);
        zval_ptr_dtor(&cond);
        return false;
    }

    sl_p_consume_semicolon(p);
    return sl_make_do_while_stmt(&cond, &body, out);
}

static bool sl_parse_for_stmt(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_FOR)) {
        return false;
    }
    if (!sl_p_expect(p, SL_TOK_LPAREN)) {
        return false;
    }

    zval init;
    ZVAL_NULL(&init);

    if (!sl_p_check(p, SL_TOK_SEMICOLON)) {
        if (sl_p_check(p, SL_TOK_VAR) || sl_p_check(p, SL_TOK_LET) || sl_p_check(p, SL_TOK_CONST)) {
            const char *kind = "var";
            size_t keyword_pos = p->pos;
            if (sl_p_match(p, SL_TOK_VAR)) kind = "var";
            else if (sl_p_match(p, SL_TOK_LET)) kind = "let";
            else if (sl_p_match(p, SL_TOK_CONST)) kind = "const";

            if (sl_p_check(p, SL_TOK_IDENTIFIER)) {
                zend_string *name = sl_p_cur(p)->lexeme;
                p->pos++;

                if (sl_p_check_ident_lexeme(p, "of")) {
                    p->pos++;
                    zval iterable;
                    ZVAL_UNDEF(&iterable);
                    if (!sl_parse_expression(p, &iterable)) {
                        if (Z_TYPE(iterable) != IS_UNDEF) zval_ptr_dtor(&iterable);
                        return false;
                    }
                    if (!sl_p_expect(p, SL_TOK_RPAREN)) {
                        zval_ptr_dtor(&iterable);
                        return false;
                    }
                    zval body;
                    ZVAL_UNDEF(&body);
                    if (!sl_parse_statement(p, &body)) {
                        zval_ptr_dtor(&iterable);
                        if (Z_TYPE(body) != IS_UNDEF) zval_ptr_dtor(&body);
                        return false;
                    }
                    return sl_make_for_of_stmt(kind, name, &iterable, &body, out);
                }

                if (sl_p_match(p, SL_TOK_IN)) {
                    zval object;
                    ZVAL_UNDEF(&object);
                    if (!sl_parse_expression(p, &object)) {
                        if (Z_TYPE(object) != IS_UNDEF) zval_ptr_dtor(&object);
                        return false;
                    }
                    if (!sl_p_expect(p, SL_TOK_RPAREN)) {
                        zval_ptr_dtor(&object);
                        return false;
                    }
                    zval body;
                    ZVAL_UNDEF(&body);
                    if (!sl_parse_statement(p, &body)) {
                        zval_ptr_dtor(&object);
                        if (Z_TYPE(body) != IS_UNDEF) zval_ptr_dtor(&body);
                        return false;
                    }
                    return sl_make_for_in_stmt(kind, name, &object, &body, out);
                }

                zval first_init;
                ZVAL_NULL(&first_init);
                if (sl_p_match(p, SL_TOK_ASSIGN)) {
                    if (!sl_parse_assignment(p, &first_init)) {
                        if (Z_TYPE(first_init) != IS_UNDEF) zval_ptr_dtor(&first_init);
                        return false;
                    }
                }

                zval first_decl;
                ZVAL_UNDEF(&first_decl);
                sl_make_var_decl(kind, name, &first_init, &first_decl);

                if (!sl_p_match(p, SL_TOK_COMMA)) {
                    ZVAL_COPY_VALUE(&init, &first_decl);
                } else {
                    zval decls;
                    array_init(&decls);
                    add_next_index_zval(&decls, &first_decl);
                    ZVAL_UNDEF(&first_decl);

                    do {
                        if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                            zval_ptr_dtor(&decls);
                            p->error = true;
                            return false;
                        }
                        zend_string *n = sl_p_cur(p)->lexeme;
                        p->pos++;

                        zval next_init;
                        ZVAL_NULL(&next_init);
                        if (sl_p_match(p, SL_TOK_ASSIGN)) {
                            if (!sl_parse_assignment(p, &next_init)) {
                                zval_ptr_dtor(&decls);
                                if (Z_TYPE(next_init) != IS_UNDEF) zval_ptr_dtor(&next_init);
                                return false;
                            }
                        }

                        zval decl;
                        ZVAL_UNDEF(&decl);
                        sl_make_var_decl(kind, n, &next_init, &decl);
                        add_next_index_zval(&decls, &decl);
                        ZVAL_UNDEF(&decl);
                    } while (sl_p_match(p, SL_TOK_COMMA));

                    zval init_list;
                    ZVAL_UNDEF(&init_list);
                    sl_make_var_decl_list(&decls, &init_list);
                    ZVAL_COPY_VALUE(&init, &init_list);
                }
            } else {
                /* Let full var/let/const parser handle destructuring and errors. */
                p->pos = keyword_pos;
                if (!sl_parse_var_decl_inner(p, &init)) {
                    if (Z_TYPE(init) != IS_UNDEF) zval_ptr_dtor(&init);
                    return false;
                }
            }
        } else {
            zval init_expr;
            ZVAL_UNDEF(&init_expr);
            if (!sl_parse_comma_expression(p, &init_expr)) {
                if (Z_TYPE(init_expr) != IS_UNDEF) zval_ptr_dtor(&init_expr);
                return false;
            }
            zval init_stmt;
            ZVAL_UNDEF(&init_stmt);
            sl_make_expr_stmt(&init_expr, &init_stmt);
            ZVAL_COPY_VALUE(&init, &init_stmt);
        }
    }

    if (!sl_p_expect(p, SL_TOK_SEMICOLON)) {
        zval_ptr_dtor(&init);
        return false;
    }

    zval cond;
    ZVAL_NULL(&cond);
    if (!sl_p_check(p, SL_TOK_SEMICOLON)) {
        zval cond_expr;
        ZVAL_UNDEF(&cond_expr);
        if (!sl_parse_expression(p, &cond_expr)) {
            zval_ptr_dtor(&init);
            if (Z_TYPE(cond_expr) != IS_UNDEF) zval_ptr_dtor(&cond_expr);
            return false;
        }
        ZVAL_COPY_VALUE(&cond, &cond_expr);
    }

    if (!sl_p_expect(p, SL_TOK_SEMICOLON)) {
        zval_ptr_dtor(&init);
        zval_ptr_dtor(&cond);
        return false;
    }

    zval upd;
    ZVAL_NULL(&upd);
    if (!sl_p_check(p, SL_TOK_RPAREN)) {
        zval upd_expr;
        ZVAL_UNDEF(&upd_expr);
        if (!sl_parse_comma_expression(p, &upd_expr)) {
            zval_ptr_dtor(&init);
            zval_ptr_dtor(&cond);
            if (Z_TYPE(upd_expr) != IS_UNDEF) zval_ptr_dtor(&upd_expr);
            return false;
        }
        ZVAL_COPY_VALUE(&upd, &upd_expr);
    }

    if (!sl_p_expect(p, SL_TOK_RPAREN)) {
        zval_ptr_dtor(&init);
        zval_ptr_dtor(&cond);
        zval_ptr_dtor(&upd);
        return false;
    }

    zval body;
    ZVAL_UNDEF(&body);
    if (!sl_parse_statement(p, &body)) {
        zval_ptr_dtor(&init);
        zval_ptr_dtor(&cond);
        zval_ptr_dtor(&upd);
        if (Z_TYPE(body) != IS_UNDEF) zval_ptr_dtor(&body);
        return false;
    }

    return sl_make_for_stmt(&init, &cond, &upd, &body, out);
}

static bool sl_parse_switch_stmt(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_SWITCH)
        || !sl_p_expect(p, SL_TOK_LPAREN)) {
        return false;
    }

    zval discriminant;
    ZVAL_UNDEF(&discriminant);
    if (!sl_parse_expression(p, &discriminant)) {
        if (Z_TYPE(discriminant) != IS_UNDEF) zval_ptr_dtor(&discriminant);
        return false;
    }

    if (!sl_p_expect(p, SL_TOK_RPAREN)
        || !sl_p_expect(p, SL_TOK_LBRACE)) {
        zval_ptr_dtor(&discriminant);
        return false;
    }

    zval cases;
    array_init(&cases);

    while (!sl_p_check(p, SL_TOK_RBRACE) && !sl_p_check(p, SL_TOK_EOF)) {
        zval test;
        ZVAL_NULL(&test);

        if (sl_p_match(p, SL_TOK_CASE)) {
            if (!sl_parse_expression(p, &test)) {
                zval_ptr_dtor(&cases);
                if (Z_TYPE(test) != IS_UNDEF) zval_ptr_dtor(&test);
                zval_ptr_dtor(&discriminant);
                return false;
            }
        } else if (!sl_p_match(p, SL_TOK_DEFAULT)) {
            zval_ptr_dtor(&cases);
            zval_ptr_dtor(&discriminant);
            p->error = true;
            return false;
        }

        if (!sl_p_expect(p, SL_TOK_COLON)) {
            zval_ptr_dtor(&cases);
            zval_ptr_dtor(&test);
            zval_ptr_dtor(&discriminant);
            return false;
        }

        zval consequent;
        array_init(&consequent);
        while (!sl_p_check(p, SL_TOK_CASE)
            && !sl_p_check(p, SL_TOK_DEFAULT)
            && !sl_p_check(p, SL_TOK_RBRACE)
            && !sl_p_check(p, SL_TOK_EOF)) {
            zval stmt;
            ZVAL_UNDEF(&stmt);
            if (!sl_parse_statement(p, &stmt)) {
                zval_ptr_dtor(&cases);
                zval_ptr_dtor(&test);
                zval_ptr_dtor(&consequent);
                zval_ptr_dtor(&discriminant);
                if (Z_TYPE(stmt) != IS_UNDEF) zval_ptr_dtor(&stmt);
                return false;
            }
            add_next_index_zval(&consequent, &stmt);
            ZVAL_UNDEF(&stmt);
        }

        zval case_node;
        ZVAL_UNDEF(&case_node);
        sl_make_switch_case(&test, &consequent, &case_node);
        add_next_index_zval(&cases, &case_node);
        ZVAL_UNDEF(&case_node);
    }

    if (!sl_p_expect(p, SL_TOK_RBRACE)) {
        zval_ptr_dtor(&cases);
        zval_ptr_dtor(&discriminant);
        return false;
    }

    return sl_make_switch_stmt(&discriminant, &cases, out);
}

static bool sl_parse_try_stmt(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_TRY)) {
        return false;
    }

    zval block;
    ZVAL_UNDEF(&block);
    if (!sl_parse_block_stmt(p, &block)) {
        if (Z_TYPE(block) != IS_UNDEF) zval_ptr_dtor(&block);
        return false;
    }

    zval handler;
    ZVAL_NULL(&handler);
    if (sl_p_match(p, SL_TOK_CATCH)) {
        zval param;
        ZVAL_NULL(&param);
        if (sl_p_match(p, SL_TOK_LPAREN)) {
            if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                zval_ptr_dtor(&block);
                zval_ptr_dtor(&param);
                p->error = true;
                return false;
            }
            ZVAL_STR_COPY(&param, sl_p_cur(p)->lexeme);
            p->pos++;
            if (!sl_p_expect(p, SL_TOK_RPAREN)) {
                zval_ptr_dtor(&block);
                zval_ptr_dtor(&param);
                return false;
            }
        }

        zval body;
        ZVAL_UNDEF(&body);
        if (!sl_parse_block_stmt(p, &body)) {
            zval_ptr_dtor(&block);
            zval_ptr_dtor(&param);
            if (Z_TYPE(body) != IS_UNDEF) zval_ptr_dtor(&body);
            return false;
        }

        zval catch_node;
        ZVAL_UNDEF(&catch_node);
        sl_make_catch_clause(&param, &body, &catch_node);
        ZVAL_COPY_VALUE(&handler, &catch_node);
    }

    zval finalizer;
    ZVAL_NULL(&finalizer);
    if (sl_p_check_ident_lexeme(p, "finally")) {
        p->pos++;
        zval fin_block;
        ZVAL_UNDEF(&fin_block);
        if (!sl_parse_block_stmt(p, &fin_block)) {
            zval_ptr_dtor(&block);
            zval_ptr_dtor(&handler);
            if (Z_TYPE(fin_block) != IS_UNDEF) zval_ptr_dtor(&fin_block);
            return false;
        }
        ZVAL_COPY_VALUE(&finalizer, &fin_block);
    }

    return sl_make_try_catch_stmt(&block, &handler, &finalizer, out);
}

static bool sl_parse_throw_stmt(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_THROW)) {
        return false;
    }

    zval arg;
    ZVAL_UNDEF(&arg);
    if (!sl_parse_expression(p, &arg)) {
        if (Z_TYPE(arg) != IS_UNDEF) zval_ptr_dtor(&arg);
        return false;
    }

    sl_p_consume_semicolon(p);
    return sl_make_throw_stmt(&arg, out);
}

static bool sl_parse_return_stmt(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_RETURN)) {
        return false;
    }

    zval value;
    ZVAL_NULL(&value);

    if (!sl_p_check(p, SL_TOK_SEMICOLON)
        && !sl_p_check(p, SL_TOK_RBRACE)
        && !sl_p_check(p, SL_TOK_EOF)) {
        zval expr;
        ZVAL_UNDEF(&expr);
        if (!sl_parse_expression(p, &expr)) {
            if (Z_TYPE(expr) != IS_UNDEF) zval_ptr_dtor(&expr);
            return false;
        }
        ZVAL_COPY_VALUE(&value, &expr);
    }

    sl_p_consume_semicolon(p);
    return sl_make_return_stmt(&value, out);
}

static bool sl_parse_break_stmt(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_BREAK)) {
        return false;
    }
    if (sl_p_check(p, SL_TOK_IDENTIFIER)) {
        p->error = true; /* labeled break not supported in AST model */
        return false;
    }
    sl_p_consume_semicolon(p);
    return sl_make_break_stmt(out);
}

static bool sl_parse_continue_stmt(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_CONTINUE)) {
        return false;
    }
    if (sl_p_check(p, SL_TOK_IDENTIFIER)) {
        p->error = true; /* labeled continue not supported in AST model */
        return false;
    }
    sl_p_consume_semicolon(p);
    return sl_make_continue_stmt(out);
}

static bool sl_parse_expr_stmt(sl_parser *p, zval *out) {
    zval expr;
    ZVAL_UNDEF(&expr);
    if (!sl_parse_expression(p, &expr)) {
        if (Z_TYPE(expr) != IS_UNDEF) zval_ptr_dtor(&expr);
        return false;
    }
    sl_p_consume_semicolon(p);
    return sl_make_expr_stmt(&expr, out);
}

static bool sl_arrow_param_from_expr(
    sl_parser *p,
    zval *param_expr,
    zval *params,
    zval *defaults,
    bool *has_defaults
) {
    if (sl_node_is_kind(param_expr, "Identifier")) {
        zval *name_zv = sl_node_prop(param_expr, "name");
        if (!name_zv || Z_TYPE_P(name_zv) != IS_STRING) {
            p->error = true;
            return false;
        }
        add_next_index_str(params, zend_string_copy(Z_STR_P(name_zv)));
        add_next_index_null(defaults);
        return true;
    }

    if (sl_node_is_kind(param_expr, "AssignExpr")) {
        zval *op_zv = sl_node_prop(param_expr, "operator");
        zval *name_zv = sl_node_prop(param_expr, "name");
        zval *value_zv = sl_node_prop(param_expr, "value");
        if (!op_zv || Z_TYPE_P(op_zv) != IS_STRING
            || !zend_string_equals_literal(Z_STR_P(op_zv), "=")
            || !name_zv || Z_TYPE_P(name_zv) != IS_STRING
            || !value_zv) {
            p->error = true;
            return false;
        }
        add_next_index_str(params, zend_string_copy(Z_STR_P(name_zv)));
        zval def_copy;
        ZVAL_COPY(&def_copy, value_zv);
        add_next_index_zval(defaults, &def_copy);
        if (has_defaults) {
            *has_defaults = true;
        }
        return true;
    }

    p->unsupported = true;
    return false;
}

static bool sl_parse_arrow_body(sl_parser *p, zval *out_body) {
    if (sl_p_check(p, SL_TOK_LBRACE)) {
        zval block;
        ZVAL_UNDEF(&block);
        if (!sl_parse_block_stmt(p, &block)) {
            if (Z_TYPE(block) != IS_UNDEF) {
                zval_ptr_dtor(&block);
            }
            return false;
        }

        zval *stmts = sl_node_prop(&block, "statements");
        if (stmts && Z_TYPE_P(stmts) == IS_ARRAY) {
            ZVAL_COPY(out_body, stmts);
        } else {
            array_init(out_body);
        }
        zval_ptr_dtor(&block);
        return true;
    }

    zval expr;
    ZVAL_UNDEF(&expr);
    if (!sl_parse_assignment(p, &expr)) {
        if (Z_TYPE(expr) != IS_UNDEF) {
            zval_ptr_dtor(&expr);
        }
        return false;
    }

    zval ret_stmt;
    ZVAL_UNDEF(&ret_stmt);
    sl_make_return_stmt(&expr, &ret_stmt);

    array_init(out_body);
    add_next_index_zval(out_body, &ret_stmt);
    ZVAL_UNDEF(&ret_stmt);
    return true;
}

static bool sl_parse_expression(sl_parser *p, zval *out) {
    return sl_parse_assignment(p, out);
}

static bool sl_parse_comma_expression(sl_parser *p, zval *out) {
    zval first;
    ZVAL_UNDEF(&first);
    if (!sl_parse_assignment(p, &first)) {
        if (Z_TYPE(first) != IS_UNDEF) zval_ptr_dtor(&first);
        return false;
    }

    if (!sl_p_match(p, SL_TOK_COMMA)) {
        ZVAL_COPY_VALUE(out, &first);
        return true;
    }

    zval exprs;
    array_init(&exprs);
    add_next_index_zval(&exprs, &first);
    ZVAL_UNDEF(&first);

    do {
        zval next_expr;
        ZVAL_UNDEF(&next_expr);
        if (!sl_parse_assignment(p, &next_expr)) {
            if (Z_TYPE(next_expr) != IS_UNDEF) zval_ptr_dtor(&next_expr);
            zval_ptr_dtor(&exprs);
            return false;
        }
        add_next_index_zval(&exprs, &next_expr);
        ZVAL_UNDEF(&next_expr);
    } while (sl_p_match(p, SL_TOK_COMMA));

    return sl_make_sequence(&exprs, out);
}

static bool sl_parse_assignment(sl_parser *p, zval *out) {
    if (sl_p_check(p, SL_TOK_IDENTIFIER)
        && (p->pos + 1) < p->count
        && p->tokens[p->pos + 1].type == SL_TOK_ARROW) {
        zend_string *param_name = sl_p_cur(p)->lexeme;
        p->pos += 2; /* ident => */

        zval params, defaults, rest_param, param_destr, body, name_zv;
        array_init(&params);
        add_next_index_str(&params, zend_string_copy(param_name));
        array_init(&defaults);
        ZVAL_NULL(&rest_param);
        array_init(&param_destr);
        ZVAL_UNDEF(&body);
        ZVAL_NULL(&name_zv);

        if (!sl_parse_arrow_body(p, &body)) {
            zval_ptr_dtor(&params);
            zval_ptr_dtor(&defaults);
            zval_ptr_dtor(&rest_param);
            zval_ptr_dtor(&param_destr);
            if (Z_TYPE(body) != IS_UNDEF) {
                zval_ptr_dtor(&body);
            }
            zval_ptr_dtor(&name_zv);
            return false;
        }

        return sl_make_function_expr(
            &name_zv, &params, &body, true, &rest_param, &defaults, &param_destr, out
        );
    }

    zval left;
    ZVAL_UNDEF(&left);
    if (!sl_parse_conditional(p, &left)) {
        if (Z_TYPE(left) != IS_UNDEF) zval_ptr_dtor(&left);
        return false;
    }

    sl_tok_type op = sl_p_cur(p)->type;
    if (!sl_is_assignment_op(op)) {
        ZVAL_COPY_VALUE(out, &left);
        return true;
    }

    const char *op_lex = sl_tok_op_lexeme(op);
    p->pos++;

    zval right;
    ZVAL_UNDEF(&right);
    if (!sl_parse_assignment(p, &right)) {
        zval_ptr_dtor(&left);
        if (Z_TYPE(right) != IS_UNDEF) zval_ptr_dtor(&right);
        return false;
    }

    if (sl_node_is_kind(&left, "Identifier")) {
        zval *name_zv = sl_node_prop(&left, "name");
        if (!name_zv || Z_TYPE_P(name_zv) != IS_STRING) {
            zval_ptr_dtor(&left);
            zval_ptr_dtor(&right);
            p->error = true;
            return false;
        }
        sl_make_assign_identifier(Z_STR_P(name_zv), op_lex, &right, out);
        zval_ptr_dtor(&left);
        return true;
    }

    if (sl_node_is_kind(&left, "MemberExpr")) {
        zval *obj_zv = sl_node_prop(&left, "object");
        zval *prop_zv = sl_node_prop(&left, "property");
        zval *comp_zv = sl_node_prop(&left, "computed");
        if (!obj_zv || !prop_zv || !comp_zv) {
            zval_ptr_dtor(&left);
            zval_ptr_dtor(&right);
            p->error = true;
            return false;
        }

        zval obj_copy, prop_copy;
        ZVAL_COPY(&obj_copy, obj_zv);
        ZVAL_COPY(&prop_copy, prop_zv);
        bool computed = zend_is_true(comp_zv);
        sl_make_member_assign(&obj_copy, &prop_copy, computed, op_lex, &right, out);
        zval_ptr_dtor(&left);
        return true;
    }

    zval_ptr_dtor(&left);
    zval_ptr_dtor(&right);
    p->unsupported = true;
    return false;
}

static bool sl_parse_conditional(sl_parser *p, zval *out) {
    zval cond;
    ZVAL_UNDEF(&cond);
    if (!sl_parse_nullish(p, &cond)) {
        if (Z_TYPE(cond) != IS_UNDEF) zval_ptr_dtor(&cond);
        return false;
    }

    if (!sl_p_match(p, SL_TOK_QUESTION)) {
        ZVAL_COPY_VALUE(out, &cond);
        return true;
    }

    zval cons, alt;
    ZVAL_UNDEF(&cons);
    ZVAL_UNDEF(&alt);
    if (!sl_parse_expression(p, &cons)) {
        zval_ptr_dtor(&cond);
        if (Z_TYPE(cons) != IS_UNDEF) zval_ptr_dtor(&cons);
        return false;
    }
    if (!sl_p_expect(p, SL_TOK_COLON)) {
        zval_ptr_dtor(&cond);
        zval_ptr_dtor(&cons);
        return false;
    }
    if (!sl_parse_expression(p, &alt)) {
        zval_ptr_dtor(&cond);
        zval_ptr_dtor(&cons);
        if (Z_TYPE(alt) != IS_UNDEF) zval_ptr_dtor(&alt);
        return false;
    }

    return sl_make_conditional(&cond, &cons, &alt, out);
}

static bool sl_parse_nullish(sl_parser *p, zval *out) {
    zval left;
    ZVAL_UNDEF(&left);
    if (!sl_parse_logical_or(p, &left)) {
        if (Z_TYPE(left) != IS_UNDEF) zval_ptr_dtor(&left);
        return false;
    }

    while (sl_p_match(p, SL_TOK_NULLISH)) {
        zval right;
        ZVAL_UNDEF(&right);
        if (!sl_parse_logical_or(p, &right)) {
            zval_ptr_dtor(&left);
            if (Z_TYPE(right) != IS_UNDEF) zval_ptr_dtor(&right);
            return false;
        }
        zval merged;
        ZVAL_UNDEF(&merged);
        sl_make_binary("??", &left, &right, true, &merged);
        ZVAL_COPY_VALUE(&left, &merged);
    }

    ZVAL_COPY_VALUE(out, &left);
    return true;
}

static bool sl_parse_logical_or(sl_parser *p, zval *out) {
    zval left;
    ZVAL_UNDEF(&left);
    if (!sl_parse_logical_and(p, &left)) {
        if (Z_TYPE(left) != IS_UNDEF) zval_ptr_dtor(&left);
        return false;
    }

    while (sl_p_match(p, SL_TOK_OR_OR)) {
        zval right;
        ZVAL_UNDEF(&right);
        if (!sl_parse_logical_and(p, &right)) {
            zval_ptr_dtor(&left);
            if (Z_TYPE(right) != IS_UNDEF) zval_ptr_dtor(&right);
            return false;
        }
        zval merged;
        ZVAL_UNDEF(&merged);
        sl_make_binary("||", &left, &right, true, &merged);
        ZVAL_COPY_VALUE(&left, &merged);
    }

    ZVAL_COPY_VALUE(out, &left);
    return true;
}

static bool sl_parse_logical_and(sl_parser *p, zval *out) {
    zval left;
    ZVAL_UNDEF(&left);
    if (!sl_parse_bitwise_or(p, &left)) {
        if (Z_TYPE(left) != IS_UNDEF) zval_ptr_dtor(&left);
        return false;
    }

    while (sl_p_match(p, SL_TOK_AND_AND)) {
        zval right;
        ZVAL_UNDEF(&right);
        if (!sl_parse_bitwise_or(p, &right)) {
            zval_ptr_dtor(&left);
            if (Z_TYPE(right) != IS_UNDEF) zval_ptr_dtor(&right);
            return false;
        }
        zval merged;
        ZVAL_UNDEF(&merged);
        sl_make_binary("&&", &left, &right, true, &merged);
        ZVAL_COPY_VALUE(&left, &merged);
    }

    ZVAL_COPY_VALUE(out, &left);
    return true;
}

static bool sl_parse_bitwise_or(sl_parser *p, zval *out) {
    zval left;
    ZVAL_UNDEF(&left);
    if (!sl_parse_bitwise_xor(p, &left)) {
        if (Z_TYPE(left) != IS_UNDEF) zval_ptr_dtor(&left);
        return false;
    }

    while (sl_p_match(p, SL_TOK_PIPE)) {
        zval right;
        ZVAL_UNDEF(&right);
        if (!sl_parse_bitwise_xor(p, &right)) {
            zval_ptr_dtor(&left);
            if (Z_TYPE(right) != IS_UNDEF) zval_ptr_dtor(&right);
            return false;
        }
        zval merged;
        ZVAL_UNDEF(&merged);
        sl_make_binary("|", &left, &right, false, &merged);
        ZVAL_COPY_VALUE(&left, &merged);
    }

    ZVAL_COPY_VALUE(out, &left);
    return true;
}

static bool sl_parse_bitwise_xor(sl_parser *p, zval *out) {
    zval left;
    ZVAL_UNDEF(&left);
    if (!sl_parse_bitwise_and(p, &left)) {
        if (Z_TYPE(left) != IS_UNDEF) zval_ptr_dtor(&left);
        return false;
    }

    while (sl_p_match(p, SL_TOK_CARET)) {
        zval right;
        ZVAL_UNDEF(&right);
        if (!sl_parse_bitwise_and(p, &right)) {
            zval_ptr_dtor(&left);
            if (Z_TYPE(right) != IS_UNDEF) zval_ptr_dtor(&right);
            return false;
        }
        zval merged;
        ZVAL_UNDEF(&merged);
        sl_make_binary("^", &left, &right, false, &merged);
        ZVAL_COPY_VALUE(&left, &merged);
    }

    ZVAL_COPY_VALUE(out, &left);
    return true;
}

static bool sl_parse_bitwise_and(sl_parser *p, zval *out) {
    zval left;
    ZVAL_UNDEF(&left);
    if (!sl_parse_equality(p, &left)) {
        if (Z_TYPE(left) != IS_UNDEF) zval_ptr_dtor(&left);
        return false;
    }

    while (sl_p_match(p, SL_TOK_AMP)) {
        zval right;
        ZVAL_UNDEF(&right);
        if (!sl_parse_equality(p, &right)) {
            zval_ptr_dtor(&left);
            if (Z_TYPE(right) != IS_UNDEF) zval_ptr_dtor(&right);
            return false;
        }
        zval merged;
        ZVAL_UNDEF(&merged);
        sl_make_binary("&", &left, &right, false, &merged);
        ZVAL_COPY_VALUE(&left, &merged);
    }

    ZVAL_COPY_VALUE(out, &left);
    return true;
}

static bool sl_parse_equality(sl_parser *p, zval *out) {
    zval left;
    ZVAL_UNDEF(&left);
    if (!sl_parse_relational(p, &left)) {
        if (Z_TYPE(left) != IS_UNDEF) zval_ptr_dtor(&left);
        return false;
    }

    while (1) {
        sl_tok_type op = sl_p_cur(p)->type;
        if (!(op == SL_TOK_EQ || op == SL_TOK_NEQ || op == SL_TOK_STRICT_EQ || op == SL_TOK_STRICT_NEQ)) {
            break;
        }
        const char *op_lex = sl_tok_op_lexeme(op);
        p->pos++;

        zval right;
        ZVAL_UNDEF(&right);
        if (!sl_parse_relational(p, &right)) {
            zval_ptr_dtor(&left);
            if (Z_TYPE(right) != IS_UNDEF) zval_ptr_dtor(&right);
            return false;
        }
        zval merged;
        ZVAL_UNDEF(&merged);
        sl_make_binary(op_lex, &left, &right, false, &merged);
        ZVAL_COPY_VALUE(&left, &merged);
    }

    ZVAL_COPY_VALUE(out, &left);
    return true;
}

static bool sl_parse_relational(sl_parser *p, zval *out) {
    zval left;
    ZVAL_UNDEF(&left);
    if (!sl_parse_shift(p, &left)) {
        if (Z_TYPE(left) != IS_UNDEF) zval_ptr_dtor(&left);
        return false;
    }

    while (sl_rel_prec(sl_p_cur(p)->type)) {
        sl_tok_type op = sl_p_cur(p)->type;
        const char *op_lex = sl_tok_op_lexeme(op);
        p->pos++;

        zval right;
        ZVAL_UNDEF(&right);
        if (!sl_parse_shift(p, &right)) {
            zval_ptr_dtor(&left);
            if (Z_TYPE(right) != IS_UNDEF) zval_ptr_dtor(&right);
            return false;
        }
        zval merged;
        ZVAL_UNDEF(&merged);
        sl_make_binary(op_lex, &left, &right, false, &merged);
        ZVAL_COPY_VALUE(&left, &merged);
    }

    ZVAL_COPY_VALUE(out, &left);
    return true;
}

static bool sl_parse_shift(sl_parser *p, zval *out) {
    zval left;
    ZVAL_UNDEF(&left);
    if (!sl_parse_additive(p, &left)) {
        if (Z_TYPE(left) != IS_UNDEF) zval_ptr_dtor(&left);
        return false;
    }

    while (sl_p_check(p, SL_TOK_SHIFT_LEFT)
        || sl_p_check(p, SL_TOK_SHIFT_RIGHT)
        || sl_p_check(p, SL_TOK_SHIFT_RIGHT_UNSIGNED)) {
        sl_tok_type op = sl_p_cur(p)->type;
        const char *op_lex = sl_tok_op_lexeme(op);
        p->pos++;

        zval right;
        ZVAL_UNDEF(&right);
        if (!sl_parse_additive(p, &right)) {
            zval_ptr_dtor(&left);
            if (Z_TYPE(right) != IS_UNDEF) zval_ptr_dtor(&right);
            return false;
        }
        zval merged;
        ZVAL_UNDEF(&merged);
        sl_make_binary(op_lex, &left, &right, false, &merged);
        ZVAL_COPY_VALUE(&left, &merged);
    }

    ZVAL_COPY_VALUE(out, &left);
    return true;
}

static bool sl_parse_additive(sl_parser *p, zval *out) {
    zval left;
    ZVAL_UNDEF(&left);
    if (!sl_parse_multiplicative(p, &left)) {
        if (Z_TYPE(left) != IS_UNDEF) zval_ptr_dtor(&left);
        return false;
    }

    while (sl_p_check(p, SL_TOK_PLUS) || sl_p_check(p, SL_TOK_MINUS)) {
        sl_tok_type op = sl_p_cur(p)->type;
        const char *op_lex = sl_tok_op_lexeme(op);
        p->pos++;

        zval right;
        ZVAL_UNDEF(&right);
        if (!sl_parse_multiplicative(p, &right)) {
            zval_ptr_dtor(&left);
            if (Z_TYPE(right) != IS_UNDEF) zval_ptr_dtor(&right);
            return false;
        }
        zval merged;
        ZVAL_UNDEF(&merged);
        sl_make_binary(op_lex, &left, &right, false, &merged);
        ZVAL_COPY_VALUE(&left, &merged);
    }

    ZVAL_COPY_VALUE(out, &left);
    return true;
}

static bool sl_parse_multiplicative(sl_parser *p, zval *out) {
    zval left;
    ZVAL_UNDEF(&left);
    if (!sl_parse_exponent(p, &left)) {
        if (Z_TYPE(left) != IS_UNDEF) zval_ptr_dtor(&left);
        return false;
    }

    while (sl_p_check(p, SL_TOK_STAR) || sl_p_check(p, SL_TOK_SLASH) || sl_p_check(p, SL_TOK_PERCENT)) {
        sl_tok_type op = sl_p_cur(p)->type;
        const char *op_lex = sl_tok_op_lexeme(op);
        p->pos++;

        zval right;
        ZVAL_UNDEF(&right);
        if (!sl_parse_exponent(p, &right)) {
            zval_ptr_dtor(&left);
            if (Z_TYPE(right) != IS_UNDEF) zval_ptr_dtor(&right);
            return false;
        }
        zval merged;
        ZVAL_UNDEF(&merged);
        sl_make_binary(op_lex, &left, &right, false, &merged);
        ZVAL_COPY_VALUE(&left, &merged);
    }

    ZVAL_COPY_VALUE(out, &left);
    return true;
}

static bool sl_parse_exponent(sl_parser *p, zval *out) {
    zval left;
    ZVAL_UNDEF(&left);
    if (!sl_parse_unary(p, &left)) {
        if (Z_TYPE(left) != IS_UNDEF) zval_ptr_dtor(&left);
        return false;
    }

    if (sl_p_match(p, SL_TOK_STAR_STAR)) {
        zval right;
        ZVAL_UNDEF(&right);
        if (!sl_parse_exponent(p, &right)) {
            zval_ptr_dtor(&left);
            if (Z_TYPE(right) != IS_UNDEF) zval_ptr_dtor(&right);
            return false;
        }
        zval merged;
        ZVAL_UNDEF(&merged);
        sl_make_binary("**", &left, &right, false, &merged);
        ZVAL_COPY_VALUE(out, &merged);
        return true;
    }

    ZVAL_COPY_VALUE(out, &left);
    return true;
}

static bool sl_parse_unary(sl_parser *p, zval *out) {
    sl_tok_type t = sl_p_cur(p)->type;

    if (t == SL_TOK_PLUS) {
        /* Keep parity with PHP parser limitations: unary plus is not supported. */
        p->unsupported = true;
        return false;
    }

    if (t == SL_TOK_BANG || t == SL_TOK_MINUS || t == SL_TOK_TILDE) {
        const char *op_lex = sl_tok_op_lexeme(t);
        p->pos++;
        zval operand;
        ZVAL_UNDEF(&operand);
        if (!sl_parse_unary(p, &operand)) {
            if (Z_TYPE(operand) != IS_UNDEF) zval_ptr_dtor(&operand);
            return false;
        }
        return sl_make_unary(op_lex, &operand, out);
    }

    if (t == SL_TOK_TYPEOF) {
        p->pos++;
        zval operand;
        ZVAL_UNDEF(&operand);
        if (!sl_parse_unary(p, &operand)) {
            if (Z_TYPE(operand) != IS_UNDEF) zval_ptr_dtor(&operand);
            return false;
        }
        return sl_make_typeof(&operand, out);
    }

    if (t == SL_TOK_VOID) {
        p->pos++;
        zval operand;
        ZVAL_UNDEF(&operand);
        if (!sl_parse_unary(p, &operand)) {
            if (Z_TYPE(operand) != IS_UNDEF) zval_ptr_dtor(&operand);
            return false;
        }
        return sl_make_void(&operand, out);
    }

    if (t == SL_TOK_DELETE) {
        p->pos++;
        zval operand;
        ZVAL_UNDEF(&operand);
        if (!sl_parse_unary(p, &operand)) {
            if (Z_TYPE(operand) != IS_UNDEF) zval_ptr_dtor(&operand);
            return false;
        }
        return sl_make_delete(&operand, out);
    }

    if (t == SL_TOK_PLUS_PLUS || t == SL_TOK_MINUS_MINUS) {
        const char *op_lex = sl_tok_op_lexeme(t);
        p->pos++;
        zval arg;
        ZVAL_UNDEF(&arg);
        if (!sl_parse_unary(p, &arg)) {
            if (Z_TYPE(arg) != IS_UNDEF) zval_ptr_dtor(&arg);
            return false;
        }
        if (!(sl_node_is_kind(&arg, "Identifier") || sl_node_is_kind(&arg, "MemberExpr"))) {
            zval_ptr_dtor(&arg);
            p->error = true;
            return false;
        }
        return sl_make_update(op_lex, &arg, true, out);
    }

    if (t == SL_TOK_NEW) {
        p->pos++;

        zval callee;
        ZVAL_UNDEF(&callee);
        if (!sl_parse_primary(p, &callee)) {
            if (Z_TYPE(callee) != IS_UNDEF) zval_ptr_dtor(&callee);
            return false;
        }

        while (1) {
            if (sl_p_match(p, SL_TOK_DOT)) {
                if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                    zval_ptr_dtor(&callee);
                    p->error = true;
                    return false;
                }
                zend_string *prop_name = sl_p_cur(p)->lexeme;
                p->pos++;

                zval prop_ident;
                ZVAL_UNDEF(&prop_ident);
                sl_make_identifier(prop_name, &prop_ident);

                zval next;
                ZVAL_UNDEF(&next);
                sl_make_member_expr(&callee, &prop_ident, false, false, false, &next);
                ZVAL_COPY_VALUE(&callee, &next);
                continue;
            }

            if (sl_p_match(p, SL_TOK_LBRACKET)) {
                zval prop_expr;
                ZVAL_UNDEF(&prop_expr);
                if (!sl_parse_expression(p, &prop_expr)) {
                    zval_ptr_dtor(&callee);
                    if (Z_TYPE(prop_expr) != IS_UNDEF) zval_ptr_dtor(&prop_expr);
                    return false;
                }
                if (!sl_p_expect(p, SL_TOK_RBRACKET)) {
                    zval_ptr_dtor(&callee);
                    zval_ptr_dtor(&prop_expr);
                    return false;
                }
                zval next;
                ZVAL_UNDEF(&next);
                sl_make_member_expr(&callee, &prop_expr, true, false, false, &next);
                ZVAL_COPY_VALUE(&callee, &next);
                continue;
            }

            break;
        }

        zval args;
        array_init(&args);
        if (sl_p_match(p, SL_TOK_LPAREN)) {
            if (!sl_parse_call_arguments(p, &args)) {
                zval_ptr_dtor(&callee);
                zval_ptr_dtor(&args);
                return false;
            }
        }

        zval expr;
        ZVAL_UNDEF(&expr);
        if (!sl_make_new(&callee, &args, &expr)) {
            if (Z_TYPE(expr) != IS_UNDEF) {
                zval_ptr_dtor(&expr);
            }
            return false;
        }

        while (1) {
            if (sl_p_match(p, SL_TOK_DOT)) {
                if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                    zval_ptr_dtor(&expr);
                    p->error = true;
                    return false;
                }
                zend_string *prop_name = sl_p_cur(p)->lexeme;
                p->pos++;

                zval prop_ident;
                ZVAL_UNDEF(&prop_ident);
                sl_make_identifier(prop_name, &prop_ident);

                zval next;
                ZVAL_UNDEF(&next);
                sl_make_member_expr(&expr, &prop_ident, false, false, false, &next);
                ZVAL_COPY_VALUE(&expr, &next);
                continue;
            }

            if (sl_p_match(p, SL_TOK_LBRACKET)) {
                zval prop_expr;
                ZVAL_UNDEF(&prop_expr);
                if (!sl_parse_expression(p, &prop_expr)) {
                    zval_ptr_dtor(&expr);
                    if (Z_TYPE(prop_expr) != IS_UNDEF) {
                        zval_ptr_dtor(&prop_expr);
                    }
                    return false;
                }
                if (!sl_p_expect(p, SL_TOK_RBRACKET)) {
                    zval_ptr_dtor(&expr);
                    zval_ptr_dtor(&prop_expr);
                    return false;
                }

                zval next;
                ZVAL_UNDEF(&next);
                sl_make_member_expr(&expr, &prop_expr, true, false, false, &next);
                ZVAL_COPY_VALUE(&expr, &next);
                continue;
            }

            if (sl_p_match(p, SL_TOK_LPAREN)) {
                zval call_args;
                array_init(&call_args);
                if (!sl_parse_call_arguments(p, &call_args)) {
                    zval_ptr_dtor(&expr);
                    zval_ptr_dtor(&call_args);
                    return false;
                }

                zval next;
                ZVAL_UNDEF(&next);
                sl_make_call(&expr, &call_args, false, false, &next);
                ZVAL_COPY_VALUE(&expr, &next);
                continue;
            }

            break;
        }

        ZVAL_COPY_VALUE(out, &expr);
        return true;
    }

    return sl_parse_postfix(p, out);
}

static bool sl_parse_call_arguments(sl_parser *p, zval *out_args) {
    /* assumes opening '(' already consumed */
    if (!sl_p_check(p, SL_TOK_RPAREN)) {
        while (1) {
            if (sl_p_match(p, SL_TOK_SPREAD)) {
                zval spread_arg;
                ZVAL_UNDEF(&spread_arg);
                if (!sl_parse_assignment(p, &spread_arg)) {
                    if (Z_TYPE(spread_arg) != IS_UNDEF) zval_ptr_dtor(&spread_arg);
                    return false;
                }
                zval spread_node;
                ZVAL_UNDEF(&spread_node);
                sl_make_spread(&spread_arg, &spread_node);
                add_next_index_zval(out_args, &spread_node);
                ZVAL_UNDEF(&spread_node);
            } else {
                zval arg;
                ZVAL_UNDEF(&arg);
                if (!sl_parse_assignment(p, &arg)) {
                    if (Z_TYPE(arg) != IS_UNDEF) zval_ptr_dtor(&arg);
                    return false;
                }
                add_next_index_zval(out_args, &arg);
                ZVAL_UNDEF(&arg);
            }
            if (!sl_p_match(p, SL_TOK_COMMA)) {
                break;
            }
        }
    }
    return sl_p_expect(p, SL_TOK_RPAREN);
}

static bool sl_parse_postfix(sl_parser *p, zval *out) {
    zval expr;
    ZVAL_UNDEF(&expr);
    if (!sl_parse_primary(p, &expr)) {
        if (Z_TYPE(expr) != IS_UNDEF) zval_ptr_dtor(&expr);
        return false;
    }

    bool in_optional_chain = false;
    while (1) {
        if (sl_p_match(p, SL_TOK_OPTIONAL_CHAIN)) {
            in_optional_chain = true;

            if (sl_p_match(p, SL_TOK_LBRACKET)) {
                zval prop_expr;
                ZVAL_UNDEF(&prop_expr);
                if (!sl_parse_expression(p, &prop_expr)) {
                    zval_ptr_dtor(&expr);
                    if (Z_TYPE(prop_expr) != IS_UNDEF) zval_ptr_dtor(&prop_expr);
                    return false;
                }
                if (!sl_p_expect(p, SL_TOK_RBRACKET)) {
                    zval_ptr_dtor(&expr);
                    zval_ptr_dtor(&prop_expr);
                    return false;
                }

                zval next;
                ZVAL_UNDEF(&next);
                sl_make_member_expr(&expr, &prop_expr, true, true, false, &next);
                ZVAL_COPY_VALUE(&expr, &next);
                continue;
            }

            if (sl_p_match(p, SL_TOK_LPAREN)) {
                zval args;
                array_init(&args);
                if (!sl_parse_call_arguments(p, &args)) {
                    zval_ptr_dtor(&expr);
                    zval_ptr_dtor(&args);
                    return false;
                }
                zval next;
                ZVAL_UNDEF(&next);
                sl_make_call(&expr, &args, true, false, &next);
                ZVAL_COPY_VALUE(&expr, &next);
                continue;
            }

            if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                zval_ptr_dtor(&expr);
                p->error = true;
                return false;
            }

            zend_string *prop_name = sl_p_cur(p)->lexeme;
            p->pos++;
            zval prop_ident;
            ZVAL_UNDEF(&prop_ident);
            sl_make_identifier(prop_name, &prop_ident);

            zval next;
            ZVAL_UNDEF(&next);
            sl_make_member_expr(&expr, &prop_ident, false, true, false, &next);
            ZVAL_COPY_VALUE(&expr, &next);
            continue;
        }

        if (sl_p_match(p, SL_TOK_DOT)) {
            if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                zval_ptr_dtor(&expr);
                p->error = true;
                return false;
            }
            zend_string *prop_name = sl_p_cur(p)->lexeme;
            p->pos++;

            zval prop_ident;
            ZVAL_UNDEF(&prop_ident);
            sl_make_identifier(prop_name, &prop_ident);

            zval next;
            ZVAL_UNDEF(&next);
            sl_make_member_expr(&expr, &prop_ident, false, false, in_optional_chain, &next);
            ZVAL_COPY_VALUE(&expr, &next);
            continue;
        }

        if (sl_p_match(p, SL_TOK_LBRACKET)) {
            zval prop_expr;
            ZVAL_UNDEF(&prop_expr);
            if (!sl_parse_expression(p, &prop_expr)) {
                zval_ptr_dtor(&expr);
                if (Z_TYPE(prop_expr) != IS_UNDEF) zval_ptr_dtor(&prop_expr);
                return false;
            }
            if (!sl_p_expect(p, SL_TOK_RBRACKET)) {
                zval_ptr_dtor(&expr);
                zval_ptr_dtor(&prop_expr);
                return false;
            }
            zval next;
            ZVAL_UNDEF(&next);
            sl_make_member_expr(&expr, &prop_expr, true, false, in_optional_chain, &next);
            ZVAL_COPY_VALUE(&expr, &next);
            continue;
        }

        if (sl_p_match(p, SL_TOK_LPAREN)) {
            zval args;
            array_init(&args);
            if (!sl_parse_call_arguments(p, &args)) {
                zval_ptr_dtor(&expr);
                zval_ptr_dtor(&args);
                return false;
            }
            zval next;
            ZVAL_UNDEF(&next);
            sl_make_call(&expr, &args, false, in_optional_chain, &next);
            ZVAL_COPY_VALUE(&expr, &next);
            continue;
        }

        if (sl_p_check(p, SL_TOK_PLUS_PLUS) || sl_p_check(p, SL_TOK_MINUS_MINUS)) {
            const char *op_lex = sl_tok_op_lexeme(sl_p_cur(p)->type);
            p->pos++;
            if (!(sl_node_is_kind(&expr, "Identifier") || sl_node_is_kind(&expr, "MemberExpr"))) {
                zval_ptr_dtor(&expr);
                p->error = true;
                return false;
            }
            zval next;
            ZVAL_UNDEF(&next);
            sl_make_update(op_lex, &expr, false, &next);
            ZVAL_COPY_VALUE(&expr, &next);
            continue;
        }

        break;
    }

    ZVAL_COPY_VALUE(out, &expr);
    return true;
}

static bool sl_parse_array_literal(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_LBRACKET)) {
        return false;
    }

    zval elements;
    array_init(&elements);

    if (!sl_p_check(p, SL_TOK_RBRACKET)) {
        while (1) {
            if (sl_p_check(p, SL_TOK_COMMA)) {
                /* hole -> undefined */
                zval undef_node;
                ZVAL_UNDEF(&undef_node);
                sl_make_undefined(&undef_node);
                add_next_index_zval(&elements, &undef_node);
                ZVAL_UNDEF(&undef_node);
                p->pos++;
                continue;
            }

            if (sl_p_match(p, SL_TOK_SPREAD)) {
                zval spread_arg;
                ZVAL_UNDEF(&spread_arg);
                if (!sl_parse_assignment(p, &spread_arg)) {
                    if (Z_TYPE(spread_arg) != IS_UNDEF) zval_ptr_dtor(&spread_arg);
                    zval_ptr_dtor(&elements);
                    return false;
                }
                zval spread_node;
                ZVAL_UNDEF(&spread_node);
                sl_make_spread(&spread_arg, &spread_node);
                add_next_index_zval(&elements, &spread_node);
                ZVAL_UNDEF(&spread_node);

                if (!sl_p_match(p, SL_TOK_COMMA)) {
                    break;
                }
                if (sl_p_check(p, SL_TOK_RBRACKET)) {
                    break;
                }
                continue;
            }

            zval el;
            ZVAL_UNDEF(&el);
            if (!sl_parse_assignment(p, &el)) {
                if (Z_TYPE(el) != IS_UNDEF) zval_ptr_dtor(&el);
                zval_ptr_dtor(&elements);
                return false;
            }
            add_next_index_zval(&elements, &el);
            ZVAL_UNDEF(&el);

            if (!sl_p_match(p, SL_TOK_COMMA)) {
                break;
            }
            if (sl_p_check(p, SL_TOK_RBRACKET)) {
                break;
            }
        }
    }

    if (!sl_p_expect(p, SL_TOK_RBRACKET)) {
        zval_ptr_dtor(&elements);
        return false;
    }

    return sl_make_array_literal(&elements, out);
}

static bool sl_parse_object_literal(sl_parser *p, zval *out) {
    if (!sl_p_expect(p, SL_TOK_LBRACE)) {
        return false;
    }

    zval props;
    array_init(&props);

    if (!sl_p_check(p, SL_TOK_RBRACE)) {
        while (1) {
            zval prop_node;
            ZVAL_UNDEF(&prop_node);

            if (sl_p_match(p, SL_TOK_LBRACKET)) {
                zval key_expr;
                ZVAL_UNDEF(&key_expr);
                if (!sl_parse_expression(p, &key_expr)) {
                    if (Z_TYPE(key_expr) != IS_UNDEF) zval_ptr_dtor(&key_expr);
                    zval_ptr_dtor(&props);
                    return false;
                }
                if (!sl_p_expect(p, SL_TOK_RBRACKET) || !sl_p_expect(p, SL_TOK_COLON)) {
                    zval_ptr_dtor(&key_expr);
                    zval_ptr_dtor(&props);
                    return false;
                }
                zval value;
                ZVAL_UNDEF(&value);
                if (!sl_parse_assignment(p, &value)) {
                    if (Z_TYPE(value) != IS_UNDEF) zval_ptr_dtor(&value);
                    zval_ptr_dtor(&key_expr);
                    zval_ptr_dtor(&props);
                    return false;
                }
                sl_make_object_property(NULL, &value, true, &key_expr, &prop_node);
            } else {
                zend_string *key = NULL;
                if (sl_p_check(p, SL_TOK_IDENTIFIER) || sl_p_check(p, SL_TOK_STRING) || sl_p_check(p, SL_TOK_NUMBER)) {
                    key = sl_p_cur(p)->lexeme;
                    p->pos++;
                } else {
                    zval_ptr_dtor(&props);
                    p->error = true;
                    return false;
                }

                if (sl_p_match(p, SL_TOK_COLON)) {
                    zval value;
                    ZVAL_UNDEF(&value);
                    if (!sl_parse_assignment(p, &value)) {
                        if (Z_TYPE(value) != IS_UNDEF) zval_ptr_dtor(&value);
                        zval_ptr_dtor(&props);
                        return false;
                    }
                    sl_make_object_property(key, &value, false, NULL, &prop_node);
                } else {
                    /* shorthand */
                    zval value;
                    ZVAL_UNDEF(&value);
                    sl_make_identifier(key, &value);
                    sl_make_object_property(key, &value, false, NULL, &prop_node);
                }
            }

            add_next_index_zval(&props, &prop_node);
            ZVAL_UNDEF(&prop_node);

            if (!sl_p_match(p, SL_TOK_COMMA)) {
                break;
            }
            if (sl_p_check(p, SL_TOK_RBRACE)) {
                break;
            }
        }
    }

    if (!sl_p_expect(p, SL_TOK_RBRACE)) {
        zval_ptr_dtor(&props);
        return false;
    }

    return sl_make_object_literal(&props, out);
}

static bool sl_parse_template_literal(sl_parser *p, zval *out) {
    if (!sl_p_check(p, SL_TOK_TEMPLATE_HEAD)) {
        p->error = true;
        return false;
    }

    zval quasis;
    zval expressions;
    array_init(&quasis);
    array_init(&expressions);

    if (sl_p_cur(p)->lexeme) {
        add_next_index_str(&quasis, zend_string_copy(sl_p_cur(p)->lexeme));
    } else {
        add_next_index_string(&quasis, "");
    }
    p->pos++;

    while (1) {
        zval expr;
        ZVAL_UNDEF(&expr);
        if (!sl_parse_expression(p, &expr)) {
            zval_ptr_dtor(&quasis);
            zval_ptr_dtor(&expressions);
            if (Z_TYPE(expr) != IS_UNDEF) zval_ptr_dtor(&expr);
            return false;
        }
        add_next_index_zval(&expressions, &expr);
        ZVAL_UNDEF(&expr);

        if (sl_p_check(p, SL_TOK_TEMPLATE_MIDDLE)) {
            if (sl_p_cur(p)->lexeme) {
                add_next_index_str(&quasis, zend_string_copy(sl_p_cur(p)->lexeme));
            } else {
                add_next_index_string(&quasis, "");
            }
            p->pos++;
            continue;
        }

        if (!sl_p_check(p, SL_TOK_TEMPLATE_TAIL)) {
            zval_ptr_dtor(&quasis);
            zval_ptr_dtor(&expressions);
            p->error = true;
            return false;
        }

        if (sl_p_cur(p)->lexeme) {
            add_next_index_str(&quasis, zend_string_copy(sl_p_cur(p)->lexeme));
        } else {
            add_next_index_string(&quasis, "");
        }
        p->pos++;
        break;
    }

    return sl_make_template_literal(&quasis, &expressions, out);
}

static bool sl_parse_primary(sl_parser *p, zval *out) {
    sl_token *tok = sl_p_cur(p);
    switch (tok->type) {
        case SL_TOK_NUMBER: {
            double n = zend_strtod(ZSTR_VAL(tok->lexeme), NULL);
            p->pos++;
            return sl_make_number(n, out);
        }
        case SL_TOK_STRING:
            p->pos++;
            return sl_make_string(tok->lexeme, out);
        case SL_TOK_TRUE:
            p->pos++;
            return sl_make_boolean(true, out);
        case SL_TOK_FALSE:
            p->pos++;
            return sl_make_boolean(false, out);
        case SL_TOK_NULL:
            p->pos++;
            return sl_make_null(out);
        case SL_TOK_UNDEFINED:
            p->pos++;
            return sl_make_undefined(out);
        case SL_TOK_THIS:
            p->pos++;
            return sl_make_this(out);
        case SL_TOK_IDENTIFIER:
            p->pos++;
            return sl_make_identifier(tok->lexeme, out);
        case SL_TOK_FUNCTION:
            return sl_parse_function_expr(p, out);
        case SL_TOK_REGEX: {
            p->pos++;
            const char *payload = ZSTR_VAL(tok->lexeme);
            size_t len = ZSTR_LEN(tok->lexeme);
            const char *sep = strstr(payload, "|||");
            zend_string *pattern;
            zend_string *flags;
            if (!sep) {
                pattern = zend_string_copy(tok->lexeme);
                flags = zend_string_init("", 0, 0);
            } else {
                size_t plen = (size_t)(sep - payload);
                pattern = zend_string_init(payload, plen, 0);
                flags = zend_string_init(sep + 3, len - plen - 3, 0);
            }
            bool ok = sl_make_regex(pattern, flags, out);
            zend_string_release(pattern);
            zend_string_release(flags);
            return ok;
        }
        case SL_TOK_TEMPLATE_HEAD:
            return sl_parse_template_literal(p, out);
        case SL_TOK_LPAREN: {
            p->pos++;
            if (sl_p_match(p, SL_TOK_RPAREN)) {
                if (!sl_p_match(p, SL_TOK_ARROW)) {
                    p->error = true; /* plain empty grouping "()" is invalid */
                    return false;
                }

                zval params, defaults, rest_param, param_destr, body, name_zv;
                array_init(&params);
                array_init(&defaults);
                ZVAL_NULL(&rest_param);
                array_init(&param_destr);
                ZVAL_UNDEF(&body);
                ZVAL_NULL(&name_zv);

                if (!sl_parse_arrow_body(p, &body)) {
                    zval_ptr_dtor(&params);
                    zval_ptr_dtor(&defaults);
                    zval_ptr_dtor(&rest_param);
                    zval_ptr_dtor(&param_destr);
                    if (Z_TYPE(body) != IS_UNDEF) {
                        zval_ptr_dtor(&body);
                    }
                    zval_ptr_dtor(&name_zv);
                    return false;
                }

                return sl_make_function_expr(
                    &name_zv, &params, &body, true, &rest_param, &defaults, &param_destr, out
                );
            }

            if (sl_p_match(p, SL_TOK_SPREAD)) {
                if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                    p->error = true;
                    return false;
                }
                zval params, defaults, rest_param, param_destr, body, name_zv;
                array_init(&params);
                array_init(&defaults);
                ZVAL_STR_COPY(&rest_param, sl_p_cur(p)->lexeme);
                p->pos++;
                array_init(&param_destr);
                ZVAL_UNDEF(&body);
                ZVAL_NULL(&name_zv);

                if (!sl_p_expect(p, SL_TOK_RPAREN) || !sl_p_expect(p, SL_TOK_ARROW)) {
                    zval_ptr_dtor(&params);
                    zval_ptr_dtor(&defaults);
                    zval_ptr_dtor(&rest_param);
                    zval_ptr_dtor(&param_destr);
                    if (Z_TYPE(body) != IS_UNDEF) {
                        zval_ptr_dtor(&body);
                    }
                    zval_ptr_dtor(&name_zv);
                    return false;
                }

                if (!sl_parse_arrow_body(p, &body)) {
                    zval_ptr_dtor(&params);
                    zval_ptr_dtor(&defaults);
                    zval_ptr_dtor(&rest_param);
                    zval_ptr_dtor(&param_destr);
                    if (Z_TYPE(body) != IS_UNDEF) {
                        zval_ptr_dtor(&body);
                    }
                    zval_ptr_dtor(&name_zv);
                    return false;
                }

                return sl_make_function_expr(
                    &name_zv, &params, &body, true, &rest_param, &defaults, &param_destr, out
                );
            }

            zval first_expr;
            ZVAL_UNDEF(&first_expr);
            if (!sl_parse_expression(p, &first_expr)) {
                if (Z_TYPE(first_expr) != IS_UNDEF) zval_ptr_dtor(&first_expr);
                return false;
            }

            if (!sl_p_check(p, SL_TOK_COMMA)) {
                if (!sl_p_expect(p, SL_TOK_RPAREN)) {
                    zval_ptr_dtor(&first_expr);
                    return false;
                }

                if (!sl_p_match(p, SL_TOK_ARROW)) {
                    ZVAL_COPY_VALUE(out, &first_expr);
                    return true;
                }

                zval params, defaults, rest_param, param_destr, body, name_zv;
                bool has_defaults = false;
                array_init(&params);
                array_init(&defaults);
                ZVAL_NULL(&rest_param);
                array_init(&param_destr);
                ZVAL_UNDEF(&body);
                ZVAL_NULL(&name_zv);

                bool ok = sl_arrow_param_from_expr(
                    p,
                    &first_expr,
                    &params,
                    &defaults,
                    &has_defaults
                );
                zval_ptr_dtor(&first_expr);

                if (!ok) {
                    zval_ptr_dtor(&params);
                    zval_ptr_dtor(&defaults);
                    zval_ptr_dtor(&rest_param);
                    zval_ptr_dtor(&param_destr);
                    zval_ptr_dtor(&name_zv);
                    return false;
                }

                if (!has_defaults) {
                    zval_ptr_dtor(&defaults);
                    array_init(&defaults);
                }

                if (!sl_parse_arrow_body(p, &body)) {
                    zval_ptr_dtor(&params);
                    zval_ptr_dtor(&defaults);
                    zval_ptr_dtor(&rest_param);
                    zval_ptr_dtor(&param_destr);
                    if (Z_TYPE(body) != IS_UNDEF) {
                        zval_ptr_dtor(&body);
                    }
                    zval_ptr_dtor(&name_zv);
                    return false;
                }

                return sl_make_function_expr(
                    &name_zv, &params, &body, true, &rest_param, &defaults, &param_destr, out
                );
            }

            zval exprs;
            zval rest_param;
            bool has_defaults = false;
            array_init(&exprs);
            add_next_index_zval(&exprs, &first_expr);
            ZVAL_UNDEF(&first_expr);
            ZVAL_NULL(&rest_param);

            while (sl_p_match(p, SL_TOK_COMMA)) {
                if (sl_p_check(p, SL_TOK_RPAREN)) {
                    break; /* trailing comma */
                }

                if (sl_p_match(p, SL_TOK_SPREAD)) {
                    if (!sl_p_check(p, SL_TOK_IDENTIFIER)) {
                        zval_ptr_dtor(&exprs);
                        zval_ptr_dtor(&rest_param);
                        p->error = true;
                        return false;
                    }
                    ZVAL_STR_COPY(&rest_param, sl_p_cur(p)->lexeme);
                    p->pos++;
                    break;
                }

                zval param_expr;
                ZVAL_UNDEF(&param_expr);
                if (!sl_parse_expression(p, &param_expr)) {
                    if (Z_TYPE(param_expr) != IS_UNDEF) {
                        zval_ptr_dtor(&param_expr);
                    }
                    zval_ptr_dtor(&exprs);
                    zval_ptr_dtor(&rest_param);
                    return false;
                }
                add_next_index_zval(&exprs, &param_expr);
                ZVAL_UNDEF(&param_expr);
            }

            if (!sl_p_expect(p, SL_TOK_RPAREN)) {
                zval_ptr_dtor(&exprs);
                zval_ptr_dtor(&rest_param);
                return false;
            }

            if (sl_p_match(p, SL_TOK_ARROW)) {
                zval params, defaults, param_destr, body, name_zv;
                array_init(&params);
                array_init(&defaults);
                array_init(&param_destr);
                ZVAL_UNDEF(&body);
                ZVAL_NULL(&name_zv);

                bool ok = true;
                zval *param_expr;
                ZEND_HASH_FOREACH_VAL(Z_ARRVAL(exprs), param_expr) {
                    if (!sl_arrow_param_from_expr(p, param_expr, &params, &defaults, &has_defaults)) {
                        ok = false;
                        break;
                    }
                } ZEND_HASH_FOREACH_END();
                zval_ptr_dtor(&exprs);

                if (!ok) {
                    zval_ptr_dtor(&params);
                    zval_ptr_dtor(&defaults);
                    zval_ptr_dtor(&rest_param);
                    zval_ptr_dtor(&param_destr);
                    zval_ptr_dtor(&name_zv);
                    return false;
                }

                if (!has_defaults) {
                    zval_ptr_dtor(&defaults);
                    array_init(&defaults);
                }

                if (!sl_parse_arrow_body(p, &body)) {
                    zval_ptr_dtor(&params);
                    zval_ptr_dtor(&defaults);
                    zval_ptr_dtor(&rest_param);
                    zval_ptr_dtor(&param_destr);
                    if (Z_TYPE(body) != IS_UNDEF) {
                        zval_ptr_dtor(&body);
                    }
                    zval_ptr_dtor(&name_zv);
                    return false;
                }

                return sl_make_function_expr(
                    &name_zv, &params, &body, true, &rest_param, &defaults, &param_destr, out
                );
            }

            if (Z_TYPE(rest_param) != IS_NULL) {
                zval_ptr_dtor(&exprs);
                zval_ptr_dtor(&rest_param);
                p->error = true;
                return false;
            }

            if (zend_hash_num_elements(Z_ARRVAL(exprs)) != 1) {
                zval_ptr_dtor(&exprs);
                zval_ptr_dtor(&rest_param);
                p->error = true;
                return false;
            }

            zval *only = zend_hash_index_find(Z_ARRVAL(exprs), 0);
            if (!only) {
                zval_ptr_dtor(&exprs);
                zval_ptr_dtor(&rest_param);
                p->error = true;
                return false;
            }
            ZVAL_COPY(out, only);
            zval_ptr_dtor(&exprs);
            zval_ptr_dtor(&rest_param);
            return true;
        }
        case SL_TOK_LBRACKET:
            return sl_parse_array_literal(p, out);
        case SL_TOK_LBRACE:
            return sl_parse_object_literal(p, out);
        default:
            p->error = true;
            return false;
    }
}

/* ------------------------------------------------------------------------- */
/* Public entry                                                              */
/* ------------------------------------------------------------------------- */
sl_parse_status sl_native_parse_source(zend_string *source, zval *program_out) {
    ZVAL_UNDEF(program_out);

    sl_lexer lx;
    memset(&lx, 0, sizeof(lx));
    lx.src = ZSTR_VAL(source);
    lx.len = ZSTR_LEN(source);
    lx.pos = 0;
    lx.line = 1;
    lx.col = 1;
    lx.last_significant = SL_TOK_EOF;

    if (!sl_lex_tokenize(&lx)) {
        size_t i;
        for (i = 0; i < lx.token_count; i++) {
            sl_token_dtor(&lx.tokens[i]);
        }
        if (lx.tokens) {
            efree(lx.tokens);
        }
        if (lx.template_brace_stack) {
            efree(lx.template_brace_stack);
        }
        if (lx.unsupported) return SL_PARSE_STATUS_UNSUPPORTED;
        if (lx.error) return SL_PARSE_STATUS_ERROR;
        return SL_PARSE_STATUS_UNSUPPORTED;
    }

    sl_parser p;
    memset(&p, 0, sizeof(p));
    p.tokens = lx.tokens;
    p.count = lx.token_count;
    p.pos = 0;

    bool ok = sl_parse_program(&p, program_out);
    if (ok && !sl_p_check(&p, SL_TOK_EOF)) {
        ok = false;
        p.error = true;
    }

    if (!ok && getenv("SCRIPTLITE_DEBUG_PARSER")) {
        fprintf(stderr, "[scriptlite-parser] parse failed (unsupported=%d error=%d) at pos=%zu/%zu\n",
            p.unsupported ? 1 : 0, p.error ? 1 : 0, p.pos, p.count);
        size_t ti;
        for (ti = 0; ti < p.count; ti++) {
            sl_token *tok = &p.tokens[ti];
            fprintf(stderr, "  [%s] %s", (ti == p.pos ? ">>" : "  "), sl_tok_name(tok->type));
            if (tok->lexeme) {
                fprintf(stderr, " \"%s\"", ZSTR_VAL(tok->lexeme));
            }
            fprintf(stderr, " @%u:%u\n", tok->line, tok->col);
        }
    }

    if (!ok && Z_TYPE_P(program_out) != IS_UNDEF) {
        zval_ptr_dtor(program_out);
        ZVAL_UNDEF(program_out);
    }

    sl_parse_status status = SL_PARSE_STATUS_OK;
    if (!ok) {
        if (p.unsupported || lx.unsupported) status = SL_PARSE_STATUS_UNSUPPORTED;
        else if (p.error || lx.error) status = SL_PARSE_STATUS_ERROR;
        else status = SL_PARSE_STATUS_UNSUPPORTED;
    }

    size_t i;
    for (i = 0; i < lx.token_count; i++) {
        sl_token_dtor(&lx.tokens[i]);
    }
    efree(lx.tokens);
    if (lx.template_brace_stack) {
        efree(lx.template_brace_stack);
    }

    return status;
}
