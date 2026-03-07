#ifndef SL_PARSER_H
#define SL_PARSER_H

#include "php_scriptlite.h"

typedef enum _sl_parse_status {
    SL_PARSE_STATUS_OK = 0,
    SL_PARSE_STATUS_UNSUPPORTED = 1,
    SL_PARSE_STATUS_ERROR = 2
} sl_parse_status;

/*
 * Parse JS source with the native C parser and emit an AST node tree compatible
 * with the compiler.
 *
 * On success:
 *   - returns SL_PARSE_STATUS_OK
 *   - initializes program_out with a Program-like AST node object
 *
 * On failure:
 *   - returns SL_PARSE_STATUS_UNSUPPORTED for syntax/features the native parser
 *     deliberately does not cover yet
 *   - returns SL_PARSE_STATUS_ERROR for malformed input in covered grammar
 *   - leaves program_out as IS_UNDEF
 *
 * This function does not throw; callers can transparently fall back to the PHP
 * parser to preserve full language compatibility.
 */
sl_parse_status sl_native_parse_source(zend_string *source, zval *program_out);

#endif /* SL_PARSER_H */
