/*
 * sl_string_methods.c — String prototype methods for ScriptLite VM
 *
 * Implements: charAt, charCodeAt, indexOf, lastIndexOf, includes, startsWith,
 *             endsWith, substring, slice, split, toLowerCase, toUpperCase,
 *             trim, trimStart, trimEnd, repeat, replace, replaceAll, match,
 *             matchAll, search, padStart, padEnd, at, concat
 */

#include "sl_string_methods.h"
#include "sl_runtime.h"
#include "sl_value.h"
#include "sl_vm.h"
#include <string.h>
#define PCRE2_CODE_UNIT_WIDTH 8
#include <pcre2.h>
#include <ctype.h>

#include "ext/pcre/php_pcre.h"

/* ============================================================
 * UTF-8 helpers
 * ============================================================ */

/* Count the number of UTF-8 characters in a string */
static size_t sl_utf8_strlen(const char *s, size_t byte_len) {
    size_t count = 0;
    size_t i = 0;
    while (i < byte_len) {
        unsigned char c = (unsigned char)s[i];
        if (c < 0x80)       i += 1;
        else if (c < 0xC0)  i += 1; /* continuation byte -- shouldn't happen at start */
        else if (c < 0xE0)  i += 2;
        else if (c < 0xF0)  i += 3;
        else                i += 4;
        count++;
    }
    return count;
}

/* Get the byte offset of the nth UTF-8 character. Returns byte_len if n >= char count */
static size_t sl_utf8_offset(const char *s, size_t byte_len, size_t char_index) {
    size_t i = 0;
    size_t count = 0;
    while (i < byte_len && count < char_index) {
        unsigned char c = (unsigned char)s[i];
        if (c < 0x80)       i += 1;
        else if (c < 0xC0)  i += 1;
        else if (c < 0xE0)  i += 2;
        else if (c < 0xF0)  i += 3;
        else                i += 4;
        count++;
    }
    return i;
}

/* Get the byte length of one UTF-8 character at position */
static size_t sl_utf8_char_len(const char *s, size_t byte_len, size_t byte_offset) {
    if (byte_offset >= byte_len) return 0;
    unsigned char c = (unsigned char)s[byte_offset];
    if (c < 0x80) return 1;
    if (c < 0xC0) return 1;
    if (c < 0xE0) return 2;
    if (c < 0xF0) return 3;
    return 4;
}

/* Get UTF-8 codepoint at byte offset */
static zend_long sl_utf8_codepoint(const char *s, size_t byte_len, size_t byte_offset) {
    if (byte_offset >= byte_len) return -1;
    unsigned char c = (unsigned char)s[byte_offset];
    if (c < 0x80) return c;
    if (c < 0xE0 && byte_offset + 1 < byte_len) {
        return ((c & 0x1F) << 6) | (s[byte_offset + 1] & 0x3F);
    }
    if (c < 0xF0 && byte_offset + 2 < byte_len) {
        return ((c & 0x0F) << 12) | ((s[byte_offset + 1] & 0x3F) << 6) | (s[byte_offset + 2] & 0x3F);
    }
    if (byte_offset + 3 < byte_len) {
        return ((c & 0x07) << 18) | ((s[byte_offset + 1] & 0x3F) << 12) |
               ((s[byte_offset + 2] & 0x3F) << 6) | (s[byte_offset + 3] & 0x3F);
    }
    return c;
}

/* Find byte position of substring starting from byte offset. Returns -1 if not found. */
static zend_long sl_byte_strpos(const char *haystack, size_t h_len, const char *needle, size_t n_len, size_t start_byte) {
    if (n_len == 0) return (zend_long)start_byte;
    if (start_byte + n_len > h_len) return -1;
    for (size_t i = start_byte; i + n_len <= h_len; i++) {
        if (memcmp(haystack + i, needle, n_len) == 0) {
            return (zend_long)i;
        }
    }
    return -1;
}

/* Find last byte position of substring. Returns -1 if not found. */
static zend_long sl_byte_strrpos(const char *haystack, size_t h_len, const char *needle, size_t n_len) {
    if (n_len == 0) return (zend_long)h_len;
    if (n_len > h_len) return -1;
    for (zend_long i = (zend_long)(h_len - n_len); i >= 0; i--) {
        if (memcmp(haystack + i, needle, n_len) == 0) {
            return i;
        }
    }
    return -1;
}

/* Convert byte offset to character index */
static zend_long sl_byte_to_char_index(const char *s, size_t byte_len, size_t byte_offset) {
    size_t count = 0;
    size_t i = 0;
    while (i < byte_len && i < byte_offset) {
        unsigned char c = (unsigned char)s[i];
        if (c < 0x80)       i += 1;
        else if (c < 0xC0)  i += 1;
        else if (c < 0xE0)  i += 2;
        else if (c < 0xF0)  i += 3;
        else                i += 4;
        count++;
    }
    return (zend_long)count;
}

/* ============================================================
 * String method handlers
 *
 * Each handler's first arg (args[0]) is the bound string value.
 * Remaining args[1..argc-1] are the method arguments.
 * ============================================================ */

static sl_value sl_str_charAt(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    zend_long idx = argc > 1 ? (zend_long)sl_to_number(args[1]) : 0;
    size_t char_count = sl_utf8_strlen(ZSTR_VAL(str), ZSTR_LEN(str));

    if (idx < 0 || (size_t)idx >= char_count) {
        return sl_val_string(zend_string_init("", 0, 0));
    }

    size_t byte_off = sl_utf8_offset(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)idx);
    size_t clen = sl_utf8_char_len(ZSTR_VAL(str), ZSTR_LEN(str), byte_off);
    return sl_val_string(zend_string_init(ZSTR_VAL(str) + byte_off, clen, 0));
}

static sl_value sl_str_charCodeAt(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    zend_long idx = argc > 1 ? (zend_long)sl_to_number(args[1]) : 0;
    size_t char_count = sl_utf8_strlen(ZSTR_VAL(str), ZSTR_LEN(str));

    if (idx < 0 || (size_t)idx >= char_count) {
        return sl_val_double(NAN);
    }

    size_t byte_off = sl_utf8_offset(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)idx);
    zend_long cp = sl_utf8_codepoint(ZSTR_VAL(str), ZSTR_LEN(str), byte_off);
    return sl_val_double((double)cp);
}

static sl_value sl_str_indexOf(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    if (argc < 2) return sl_val_int(-1);

    zend_string *search = sl_to_js_string(args[1]);
    zend_long from_char = argc > 2 ? (zend_long)sl_to_number(args[2]) : 0;
    if (from_char < 0) from_char = 0;

    size_t from_byte = sl_utf8_offset(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)from_char);
    zend_long byte_pos = sl_byte_strpos(ZSTR_VAL(str), ZSTR_LEN(str),
                                         ZSTR_VAL(search), ZSTR_LEN(search), from_byte);
    zend_string_release(search);

    if (byte_pos < 0) return sl_val_int(-1);
    return sl_val_int(sl_byte_to_char_index(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)byte_pos));
}

static sl_value sl_str_lastIndexOf(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    if (argc < 2) return sl_val_int(-1);

    zend_string *search = sl_to_js_string(args[1]);
    zend_long byte_pos = sl_byte_strrpos(ZSTR_VAL(str), ZSTR_LEN(str),
                                          ZSTR_VAL(search), ZSTR_LEN(search));
    zend_string_release(search);

    if (byte_pos < 0) return sl_val_int(-1);
    return sl_val_int(sl_byte_to_char_index(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)byte_pos));
}

static sl_value sl_str_includes(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    if (argc < 2) return sl_val_bool(0);

    zend_string *search = sl_to_js_string(args[1]);
    zend_long from_char = argc > 2 ? (zend_long)sl_to_number(args[2]) : 0;
    if (from_char < 0) from_char = 0;

    size_t from_byte = sl_utf8_offset(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)from_char);
    zend_long pos = sl_byte_strpos(ZSTR_VAL(str), ZSTR_LEN(str),
                                    ZSTR_VAL(search), ZSTR_LEN(search), from_byte);
    zend_string_release(search);
    return sl_val_bool(pos >= 0);
}

static sl_value sl_str_startsWith(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    if (argc < 2) return sl_val_bool(0);

    zend_string *prefix = sl_to_js_string(args[1]);
    zend_long pos_char = argc > 2 ? (zend_long)sl_to_number(args[2]) : 0;
    if (pos_char < 0) pos_char = 0;

    size_t pos_byte = sl_utf8_offset(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)pos_char);
    size_t remaining = ZSTR_LEN(str) - pos_byte;

    bool result = ZSTR_LEN(prefix) <= remaining &&
                  memcmp(ZSTR_VAL(str) + pos_byte, ZSTR_VAL(prefix), ZSTR_LEN(prefix)) == 0;
    zend_string_release(prefix);
    return sl_val_bool(result);
}

static sl_value sl_str_endsWith(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    if (argc < 2) return sl_val_bool(0);

    zend_string *suffix = sl_to_js_string(args[1]);
    size_t end_byte;

    if (argc > 2 && args[2].tag != SL_TAG_UNDEFINED) {
        zend_long end_char = (zend_long)sl_to_number(args[2]);
        end_byte = sl_utf8_offset(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)end_char);
    } else {
        end_byte = ZSTR_LEN(str);
    }

    bool result = ZSTR_LEN(suffix) <= end_byte &&
                  memcmp(ZSTR_VAL(str) + end_byte - ZSTR_LEN(suffix),
                         ZSTR_VAL(suffix), ZSTR_LEN(suffix)) == 0;
    zend_string_release(suffix);
    return sl_val_bool(result);
}

static sl_value sl_str_slice(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    size_t char_len = sl_utf8_strlen(ZSTR_VAL(str), ZSTR_LEN(str));

    zend_long s = argc > 1 ? (zend_long)sl_to_number(args[1]) : 0;
    if (s < 0) s = (zend_long)char_len + s;
    if (s < 0) s = 0;
    if ((size_t)s > char_len) s = (zend_long)char_len;

    zend_long e;
    if (argc > 2 && args[2].tag != SL_TAG_UNDEFINED) {
        e = (zend_long)sl_to_number(args[2]);
        if (e < 0) e = (zend_long)char_len + e;
        if (e < 0) e = 0;
        if ((size_t)e > char_len) e = (zend_long)char_len;
    } else {
        e = (zend_long)char_len;
    }

    if (e <= s) return sl_val_string(zend_string_init("", 0, 0));

    size_t start_byte = sl_utf8_offset(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)s);
    size_t end_byte = sl_utf8_offset(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)e);
    return sl_val_string(zend_string_init(ZSTR_VAL(str) + start_byte, end_byte - start_byte, 0));
}

static sl_value sl_str_substring(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    size_t char_len = sl_utf8_strlen(ZSTR_VAL(str), ZSTR_LEN(str));

    zend_long s = argc > 1 ? (zend_long)sl_to_number(args[1]) : 0;
    if (s < 0) s = 0;
    if ((size_t)s > char_len) s = (zend_long)char_len;

    zend_long e;
    if (argc > 2 && args[2].tag != SL_TAG_UNDEFINED) {
        e = (zend_long)sl_to_number(args[2]);
        if (e < 0) e = 0;
        if ((size_t)e > char_len) e = (zend_long)char_len;
    } else {
        e = (zend_long)char_len;
    }

    /* substring swaps if s > e */
    if (s > e) {
        zend_long tmp = s; s = e; e = tmp;
    }

    size_t start_byte = sl_utf8_offset(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)s);
    size_t end_byte = sl_utf8_offset(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)e);
    return sl_val_string(zend_string_init(ZSTR_VAL(str) + start_byte, end_byte - start_byte, 0));
}

static sl_value sl_str_toLowerCase(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    zend_string *result = zend_string_alloc(ZSTR_LEN(str), 0);
    for (size_t i = 0; i < ZSTR_LEN(str); i++) {
        ZSTR_VAL(result)[i] = tolower((unsigned char)ZSTR_VAL(str)[i]);
    }
    ZSTR_VAL(result)[ZSTR_LEN(str)] = '\0';
    return sl_val_string(result);
}

static sl_value sl_str_toUpperCase(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    zend_string *result = zend_string_alloc(ZSTR_LEN(str), 0);
    for (size_t i = 0; i < ZSTR_LEN(str); i++) {
        ZSTR_VAL(result)[i] = toupper((unsigned char)ZSTR_VAL(str)[i]);
    }
    ZSTR_VAL(result)[ZSTR_LEN(str)] = '\0';
    return sl_val_string(result);
}

static sl_value sl_str_trim(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    const char *s = ZSTR_VAL(str);
    size_t len = ZSTR_LEN(str);
    size_t start = 0, end = len;
    while (start < len && (s[start] == ' ' || s[start] == '\t' || s[start] == '\n' || s[start] == '\r')) start++;
    while (end > start && (s[end - 1] == ' ' || s[end - 1] == '\t' || s[end - 1] == '\n' || s[end - 1] == '\r')) end--;
    return sl_val_string(zend_string_init(s + start, end - start, 0));
}

static sl_value sl_str_trimStart(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    const char *s = ZSTR_VAL(str);
    size_t len = ZSTR_LEN(str);
    size_t start = 0;
    while (start < len && (s[start] == ' ' || s[start] == '\t' || s[start] == '\n' || s[start] == '\r')) start++;
    return sl_val_string(zend_string_init(s + start, len - start, 0));
}

static sl_value sl_str_trimEnd(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    const char *s = ZSTR_VAL(str);
    size_t len = ZSTR_LEN(str);
    while (len > 0 && (s[len - 1] == ' ' || s[len - 1] == '\t' || s[len - 1] == '\n' || s[len - 1] == '\r')) len--;
    return sl_val_string(zend_string_init(s, len, 0));
}

static sl_value sl_str_repeat(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    zend_long n = argc > 1 ? (zend_long)sl_to_number(args[1]) : 0;
    if (n <= 0 || ZSTR_LEN(str) == 0) {
        return sl_val_string(zend_string_init("", 0, 0));
    }
    size_t result_len = ZSTR_LEN(str) * (size_t)n;
    zend_string *result = zend_string_alloc(result_len, 0);
    for (zend_long i = 0; i < n; i++) {
        memcpy(ZSTR_VAL(result) + i * ZSTR_LEN(str), ZSTR_VAL(str), ZSTR_LEN(str));
    }
    ZSTR_VAL(result)[result_len] = '\0';
    return sl_val_string(result);
}

static sl_value sl_str_split(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;

    /* No separator or undefined: return [str] */
    if (argc < 2 || args[1].tag == SL_TAG_UNDEFINED) {
        sl_js_array *arr = sl_array_new(1);
        sl_array_push(arr, sl_val_string(zend_string_copy(str)));
        return sl_val_array(arr);
    }

    zend_long limit = -1;
    if (argc > 2 && args[2].tag != SL_TAG_UNDEFINED) {
        limit = (zend_long)sl_to_number(args[2]);
    }

    /* Regex separator */
    if (args[1].tag == SL_TAG_REGEX) {
        sl_js_regex *regex = args[1].u.regex;
        pcre2_code *re = (pcre2_code *)sl_regex_get_compiled_code(regex);
        if (!re) {
            sl_js_array *arr = sl_array_new(1);
            sl_array_push(arr, sl_val_string(zend_string_copy(str)));
            return sl_val_array(arr);
        }

        pcre2_match_data *match_data = pcre2_match_data_create_from_pattern(re, NULL);
        sl_js_array *result = sl_array_new(4);
        PCRE2_SIZE offset = 0;

        while ((limit < 0 || (zend_long)result->length < limit)) {
            int rc = pcre2_match(re, (PCRE2_SPTR)ZSTR_VAL(str), ZSTR_LEN(str),
                                 offset, 0, match_data, NULL);
            if (rc <= 0) break;
            PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);
            zend_string *part = zend_string_init(ZSTR_VAL(str) + offset, ovector[0] - offset, 0);
            sl_array_push(result, sl_val_string(part));
            zend_string_release(part);
            offset = ovector[1];
            if (ovector[0] == ovector[1]) offset++; /* avoid infinite loop on empty match */
        }

        if (limit < 0 || (zend_long)result->length < limit) {
            zend_string *tail = zend_string_init(ZSTR_VAL(str) + offset, ZSTR_LEN(str) - offset, 0);
            sl_array_push(result, sl_val_string(tail));
            zend_string_release(tail);
        }

        pcre2_match_data_free(match_data);
        return sl_val_array(result);
    }

    /* String separator */
    zend_string *sep = sl_to_js_string(args[1]);

    /* Empty separator: split into characters */
    if (ZSTR_LEN(sep) == 0) {
        zend_string_release(sep);
        size_t char_count = sl_utf8_strlen(ZSTR_VAL(str), ZSTR_LEN(str));
        sl_js_array *result = sl_array_new(char_count);
        size_t byte_off = 0;
        for (size_t i = 0; i < char_count; i++) {
            if (limit >= 0 && (zend_long)i >= limit) break;
            size_t clen = sl_utf8_char_len(ZSTR_VAL(str), ZSTR_LEN(str), byte_off);
            zend_string *ch = zend_string_init(ZSTR_VAL(str) + byte_off, clen, 0);
            sl_array_push(result, sl_val_string(ch));
            zend_string_release(ch);
            byte_off += clen;
        }
        return sl_val_array(result);
    }

    /* Normal string split */
    sl_js_array *result = sl_array_new(4);
    const char *s = ZSTR_VAL(str);
    size_t s_len = ZSTR_LEN(str);
    const char *sep_s = ZSTR_VAL(sep);
    size_t sep_len = ZSTR_LEN(sep);
    size_t pos = 0;

    while (pos <= s_len) {
        if (limit >= 0 && (zend_long)result->length >= limit) break;

        zend_long found = sl_byte_strpos(s, s_len, sep_s, sep_len, pos);
        if (found < 0) {
            zend_string *part = zend_string_init(s + pos, s_len - pos, 0);
            sl_array_push(result, sl_val_string(part));
            zend_string_release(part);
            break;
        }
        zend_string *part = zend_string_init(s + pos, (size_t)found - pos, 0);
        sl_array_push(result, sl_val_string(part));
        zend_string_release(part);
        pos = (size_t)found + sep_len;

        /* If we hit the end exactly, add trailing empty string */
        if (pos == s_len && (limit < 0 || (zend_long)result->length < limit)) {
            zend_string *empty = zend_string_init("", 0, 0);
            sl_array_push(result, sl_val_string(empty));
            zend_string_release(empty);
            break;
        }
    }

    zend_string_release(sep);
    return sl_val_array(result);
}

static sl_value sl_str_replace(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    if (argc < 2) return sl_val_string(zend_string_copy(str));

    sl_value search = args[1];
    sl_value replacement = argc > 2 ? args[2] : sl_val_string(zend_string_init("undefined", 9, 0));
    bool is_callback = SL_IS_CALLABLE(replacement);

    /* Regex search */
    if (search.tag == SL_TAG_REGEX) {
        sl_js_regex *regex = search.u.regex;
        pcre2_code *re = (pcre2_code *)sl_regex_get_compiled_code(regex);
        if (!re) return sl_val_string(zend_string_copy(str));

        pcre2_match_data *match_data = pcre2_match_data_create_from_pattern(re, NULL);
        bool global = sl_regex_is_global(regex);
        smart_string buf = {0};
        PCRE2_SIZE offset = 0;

        while (1) {
            int rc = pcre2_match(re, (PCRE2_SPTR)ZSTR_VAL(str), ZSTR_LEN(str),
                                 offset, 0, match_data, NULL);
            if (rc <= 0) break;

            PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);

            /* Append part before match */
            smart_string_appendl(&buf, ZSTR_VAL(str) + offset, ovector[0] - offset);

            if (is_callback) {
                /* Build args: matched groups */
                int ngroups = rc;
                sl_value *cb_args = emalloc(sizeof(sl_value) * (ngroups + 2));
                for (int g = 0; g < ngroups; g++) {
                    size_t start = ovector[g * 2];
                    size_t end = ovector[g * 2 + 1];
                    cb_args[g] = sl_val_string(zend_string_init(ZSTR_VAL(str) + start, end - start, 0));
                }
                /* Append offset and original string */
                cb_args[ngroups] = sl_val_int((zend_long)ovector[0]);
                cb_args[ngroups + 1] = sl_val_string(zend_string_copy(str));

                sl_value result = sl_vm_invoke_function(vm, replacement, cb_args, ngroups + 2);
                zend_string *rstr = sl_to_js_string(result);
                smart_string_appendl(&buf, ZSTR_VAL(rstr), ZSTR_LEN(rstr));
                zend_string_release(rstr);

                for (int g = 0; g < ngroups; g++) SL_DELREF(cb_args[g]);
                SL_DELREF(cb_args[ngroups + 1]);
                SL_DELREF(result);
                efree(cb_args);
            } else {
                zend_string *rstr = sl_to_js_string(replacement);
                smart_string_appendl(&buf, ZSTR_VAL(rstr), ZSTR_LEN(rstr));
                zend_string_release(rstr);
            }

            offset = ovector[1];
            if (ovector[0] == ovector[1]) offset++; /* avoid infinite loop */
            if (!global) break;
        }

        /* Append remainder */
        if (offset <= ZSTR_LEN(str)) {
            smart_string_appendl(&buf, ZSTR_VAL(str) + offset, ZSTR_LEN(str) - offset);
        }

        pcre2_match_data_free(match_data);

        smart_string_0(&buf);
        zend_string *result = buf.c ? zend_string_init(buf.c, buf.len, 0) : zend_string_init("", 0, 0);
        smart_string_free(&buf);
        return sl_val_string(result);
    }

    /* String search: replace first occurrence only */
    zend_string *search_str = sl_to_js_string(search);
    zend_long pos = sl_byte_strpos(ZSTR_VAL(str), ZSTR_LEN(str),
                                    ZSTR_VAL(search_str), ZSTR_LEN(search_str), 0);
    if (pos < 0) {
        zend_string_release(search_str);
        return sl_val_string(zend_string_copy(str));
    }

    smart_string buf = {0};
    smart_string_appendl(&buf, ZSTR_VAL(str), (size_t)pos);

    if (is_callback) {
        zend_long char_idx = sl_byte_to_char_index(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)pos);
        sl_value cb_args[3];
        cb_args[0] = sl_val_string(zend_string_copy(search_str));
        cb_args[1] = sl_val_int(char_idx);
        cb_args[2] = sl_val_string(zend_string_copy(str));

        sl_value result = sl_vm_invoke_function(vm, replacement, cb_args, 3);
        zend_string *rstr = sl_to_js_string(result);
        smart_string_appendl(&buf, ZSTR_VAL(rstr), ZSTR_LEN(rstr));
        zend_string_release(rstr);

        SL_DELREF(cb_args[0]);
        SL_DELREF(cb_args[2]);
        SL_DELREF(result);
    } else {
        zend_string *rstr = sl_to_js_string(replacement);
        smart_string_appendl(&buf, ZSTR_VAL(rstr), ZSTR_LEN(rstr));
        zend_string_release(rstr);
    }

    smart_string_appendl(&buf, ZSTR_VAL(str) + pos + ZSTR_LEN(search_str),
                         ZSTR_LEN(str) - (size_t)pos - ZSTR_LEN(search_str));

    zend_string_release(search_str);
    smart_string_0(&buf);
    zend_string *result = buf.c ? zend_string_init(buf.c, buf.len, 0) : zend_string_init("", 0, 0);
    smart_string_free(&buf);
    return sl_val_string(result);
}

static sl_value sl_str_replaceAll(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    if (argc < 2) return sl_val_string(zend_string_copy(str));

    zend_string *search_str = sl_to_js_string(args[1]);
    zend_string *repl_str = argc > 2 ? sl_to_js_string(args[2]) : zend_string_init("undefined", 9, 0);

    if (ZSTR_LEN(search_str) == 0) {
        /* Empty search string: insert replacement between every character and at boundaries */
        size_t char_count = sl_utf8_strlen(ZSTR_VAL(str), ZSTR_LEN(str));
        smart_string buf = {0};
        size_t byte_off = 0;
        smart_string_appendl(&buf, ZSTR_VAL(repl_str), ZSTR_LEN(repl_str));
        for (size_t i = 0; i < char_count; i++) {
            size_t clen = sl_utf8_char_len(ZSTR_VAL(str), ZSTR_LEN(str), byte_off);
            smart_string_appendl(&buf, ZSTR_VAL(str) + byte_off, clen);
            smart_string_appendl(&buf, ZSTR_VAL(repl_str), ZSTR_LEN(repl_str));
            byte_off += clen;
        }
        zend_string_release(search_str);
        zend_string_release(repl_str);
        smart_string_0(&buf);
        zend_string *result = buf.c ? zend_string_init(buf.c, buf.len, 0) : zend_string_init("", 0, 0);
        smart_string_free(&buf);
        return sl_val_string(result);
    }

    /* Replace all occurrences */
    smart_string buf = {0};
    const char *s = ZSTR_VAL(str);
    size_t s_len = ZSTR_LEN(str);
    size_t pos = 0;

    while (pos <= s_len) {
        zend_long found = sl_byte_strpos(s, s_len, ZSTR_VAL(search_str), ZSTR_LEN(search_str), pos);
        if (found < 0) {
            smart_string_appendl(&buf, s + pos, s_len - pos);
            break;
        }
        smart_string_appendl(&buf, s + pos, (size_t)found - pos);
        smart_string_appendl(&buf, ZSTR_VAL(repl_str), ZSTR_LEN(repl_str));
        pos = (size_t)found + ZSTR_LEN(search_str);
    }

    zend_string_release(search_str);
    zend_string_release(repl_str);

    smart_string_0(&buf);
    zend_string *result = buf.c ? zend_string_init(buf.c, buf.len, 0) : zend_string_init("", 0, 0);
    smart_string_free(&buf);
    return sl_val_string(result);
}

static sl_value sl_str_match(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    if (argc < 2 || args[1].tag != SL_TAG_REGEX) {
        return sl_val_null();
    }

    sl_js_regex *regex = args[1].u.regex;
    pcre2_code *re = (pcre2_code *)sl_regex_get_compiled_code(regex);
    if (!re) return sl_val_null();

    pcre2_match_data *match_data = pcre2_match_data_create_from_pattern(re, NULL);
    bool global = sl_regex_is_global(regex);

    if (global) {
        /* Return array of all matches */
        sl_js_array *result = sl_array_new(4);
        PCRE2_SIZE offset = 0;

        while (1) {
            int rc = pcre2_match(re, (PCRE2_SPTR)ZSTR_VAL(str), ZSTR_LEN(str),
                                 offset, 0, match_data, NULL);
            if (rc <= 0) break;
            PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);
            zend_string *m = zend_string_init(ZSTR_VAL(str) + ovector[0], ovector[1] - ovector[0], 0);
            sl_array_push(result, sl_val_string(m));
            zend_string_release(m);
            offset = ovector[1];
            if (ovector[0] == ovector[1]) offset++;
        }

        pcre2_match_data_free(match_data);

        if (result->length == 0) {
            sl_array_free(result);
            return sl_val_null();
        }
        return sl_val_array(result);
    }

    /* Non-global: return match object with index and input */
    int rc = pcre2_match(re, (PCRE2_SPTR)ZSTR_VAL(str), ZSTR_LEN(str),
                         0, 0, match_data, NULL);
    if (rc <= 0) {
        pcre2_match_data_free(match_data);
        return sl_val_null();
    }

    PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);
    sl_js_array *result = sl_array_new(rc);

    for (int i = 0; i < rc; i++) {
        PCRE2_SIZE start = ovector[i * 2];
        PCRE2_SIZE end = ovector[i * 2 + 1];
        if (start == PCRE2_UNSET) {
            sl_array_push(result, sl_val_undefined());
        } else {
            zend_string *m = zend_string_init(ZSTR_VAL(str) + start, end - start, 0);
            sl_array_push(result, sl_val_string(m));
            zend_string_release(m);
        }
    }

    /* Set index and input properties */
    if (!result->properties) {
        ALLOC_HASHTABLE(result->properties);
        zend_hash_init(result->properties, 4, NULL, NULL, 0);
    }
    zend_long char_index = sl_byte_to_char_index(ZSTR_VAL(str), ZSTR_LEN(str), ovector[0]);
    sl_value *idx_val = emalloc(sizeof(sl_value));
    *idx_val = sl_val_int(char_index);
    zval idx_zv;
    ZVAL_PTR(&idx_zv, idx_val);
    zend_string *idx_key = zend_string_init("index", 5, 0);
    zend_hash_add(result->properties, idx_key, &idx_zv);
    zend_string_release(idx_key);

    sl_value *inp_val = emalloc(sizeof(sl_value));
    *inp_val = sl_val_string(zend_string_copy(str));
    zval inp_zv;
    ZVAL_PTR(&inp_zv, inp_val);
    zend_string *inp_key = zend_string_init("input", 5, 0);
    zend_hash_add(result->properties, inp_key, &inp_zv);
    zend_string_release(inp_key);

    pcre2_match_data_free(match_data);
    return sl_val_array(result);
}

static sl_value sl_str_matchAll(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    if (argc < 2 || args[1].tag != SL_TAG_REGEX) {
        return sl_val_array(sl_array_new(0));
    }

    sl_js_regex *regex = args[1].u.regex;
    pcre2_code *re = (pcre2_code *)sl_regex_get_compiled_code(regex);
    if (!re) return sl_val_array(sl_array_new(0));

    pcre2_match_data *match_data = pcre2_match_data_create_from_pattern(re, NULL);
    sl_js_array *results = sl_array_new(4);
    PCRE2_SIZE offset = 0;

    while (1) {
        int rc = pcre2_match(re, (PCRE2_SPTR)ZSTR_VAL(str), ZSTR_LEN(str),
                             offset, 0, match_data, NULL);
        if (rc <= 0) break;

        PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);
        sl_js_array *entry = sl_array_new(rc);

        for (int i = 0; i < rc; i++) {
            PCRE2_SIZE start = ovector[i * 2];
            PCRE2_SIZE end = ovector[i * 2 + 1];
            if (start == PCRE2_UNSET) {
                sl_array_push(entry, sl_val_undefined());
            } else {
                zend_string *m = zend_string_init(ZSTR_VAL(str) + start, end - start, 0);
                sl_array_push(entry, sl_val_string(m));
                zend_string_release(m);
            }
        }

        /* Set index and input properties */
        if (!entry->properties) {
            ALLOC_HASHTABLE(entry->properties);
            zend_hash_init(entry->properties, 4, NULL, NULL, 0);
        }
        zend_long char_index = sl_byte_to_char_index(ZSTR_VAL(str), ZSTR_LEN(str), ovector[0]);
        sl_value *idx_val = emalloc(sizeof(sl_value));
        *idx_val = sl_val_int(char_index);
        zval idx_zv;
        ZVAL_PTR(&idx_zv, idx_val);
        zend_string *idx_key = zend_string_init("index", 5, 0);
        zend_hash_add(entry->properties, idx_key, &idx_zv);
        zend_string_release(idx_key);

        sl_value *inp_val = emalloc(sizeof(sl_value));
        *inp_val = sl_val_string(zend_string_copy(str));
        zval inp_zv;
        ZVAL_PTR(&inp_zv, inp_val);
        zend_string *inp_key = zend_string_init("input", 5, 0);
        zend_hash_add(entry->properties, inp_key, &inp_zv);
        zend_string_release(inp_key);

        sl_array_push(results, sl_val_array(entry));
        SL_GC_DELREF(entry);

        offset = ovector[1];
        if (ovector[0] == ovector[1]) offset++;
    }

    pcre2_match_data_free(match_data);
    return sl_val_array(results);
}

static sl_value sl_str_search(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    if (argc < 2 || args[1].tag != SL_TAG_REGEX) {
        return sl_val_int(-1);
    }

    sl_js_regex *regex = args[1].u.regex;
    pcre2_code *re = (pcre2_code *)sl_regex_get_compiled_code(regex);
    if (!re) return sl_val_int(-1);

    pcre2_match_data *match_data = pcre2_match_data_create_from_pattern(re, NULL);
    int rc = pcre2_match(re, (PCRE2_SPTR)ZSTR_VAL(str), ZSTR_LEN(str),
                         0, 0, match_data, NULL);

    if (rc <= 0) {
        pcre2_match_data_free(match_data);
        return sl_val_int(-1);
    }

    PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);
    zend_long char_index = sl_byte_to_char_index(ZSTR_VAL(str), ZSTR_LEN(str), ovector[0]);
    pcre2_match_data_free(match_data);
    return sl_val_int(char_index);
}

static sl_value sl_str_padStart(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    zend_long target = argc > 1 ? (zend_long)sl_to_number(args[1]) : 0;
    size_t char_len = sl_utf8_strlen(ZSTR_VAL(str), ZSTR_LEN(str));

    if ((size_t)target <= char_len) return sl_val_string(zend_string_copy(str));

    zend_string *pad;
    if (argc > 2 && args[2].tag != SL_TAG_UNDEFINED) {
        pad = sl_to_js_string(args[2]);
    } else {
        pad = zend_string_init(" ", 1, 0);
    }

    if (ZSTR_LEN(pad) == 0) {
        zend_string_release(pad);
        return sl_val_string(zend_string_copy(str));
    }

    size_t pad_char_len = sl_utf8_strlen(ZSTR_VAL(pad), ZSTR_LEN(pad));
    size_t needed = (size_t)target - char_len;

    /* Build padding string */
    smart_string buf = {0};
    size_t chars_added = 0;
    while (chars_added < needed) {
        size_t byte_off = 0;
        for (size_t i = 0; i < pad_char_len && chars_added < needed; i++) {
            size_t clen = sl_utf8_char_len(ZSTR_VAL(pad), ZSTR_LEN(pad), byte_off);
            smart_string_appendl(&buf, ZSTR_VAL(pad) + byte_off, clen);
            byte_off += clen;
            chars_added++;
        }
    }
    smart_string_appendl(&buf, ZSTR_VAL(str), ZSTR_LEN(str));
    smart_string_0(&buf);

    zend_string_release(pad);
    zend_string *result = buf.c ? zend_string_init(buf.c, buf.len, 0) : zend_string_init("", 0, 0);
    smart_string_free(&buf);
    return sl_val_string(result);
}

static sl_value sl_str_padEnd(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    zend_long target = argc > 1 ? (zend_long)sl_to_number(args[1]) : 0;
    size_t char_len = sl_utf8_strlen(ZSTR_VAL(str), ZSTR_LEN(str));

    if ((size_t)target <= char_len) return sl_val_string(zend_string_copy(str));

    zend_string *pad;
    if (argc > 2 && args[2].tag != SL_TAG_UNDEFINED) {
        pad = sl_to_js_string(args[2]);
    } else {
        pad = zend_string_init(" ", 1, 0);
    }

    if (ZSTR_LEN(pad) == 0) {
        zend_string_release(pad);
        return sl_val_string(zend_string_copy(str));
    }

    size_t pad_char_len = sl_utf8_strlen(ZSTR_VAL(pad), ZSTR_LEN(pad));
    size_t needed = (size_t)target - char_len;

    smart_string buf = {0};
    smart_string_appendl(&buf, ZSTR_VAL(str), ZSTR_LEN(str));
    size_t chars_added = 0;
    while (chars_added < needed) {
        size_t byte_off = 0;
        for (size_t i = 0; i < pad_char_len && chars_added < needed; i++) {
            size_t clen = sl_utf8_char_len(ZSTR_VAL(pad), ZSTR_LEN(pad), byte_off);
            smart_string_appendl(&buf, ZSTR_VAL(pad) + byte_off, clen);
            byte_off += clen;
            chars_added++;
        }
    }
    smart_string_0(&buf);

    zend_string_release(pad);
    zend_string *result = buf.c ? zend_string_init(buf.c, buf.len, 0) : zend_string_init("", 0, 0);
    smart_string_free(&buf);
    return sl_val_string(result);
}

static sl_value sl_str_at(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    zend_long idx = argc > 1 ? (zend_long)sl_to_number(args[1]) : 0;
    size_t char_len = sl_utf8_strlen(ZSTR_VAL(str), ZSTR_LEN(str));

    if (idx < 0) idx += (zend_long)char_len;
    if (idx < 0 || (size_t)idx >= char_len) return sl_val_undefined();

    size_t byte_off = sl_utf8_offset(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)idx);
    size_t clen = sl_utf8_char_len(ZSTR_VAL(str), ZSTR_LEN(str), byte_off);
    return sl_val_string(zend_string_init(ZSTR_VAL(str) + byte_off, clen, 0));
}

static sl_value sl_str_concat(sl_vm *vm, sl_value *args, int argc) {
    zend_string *str = args[0].u.str;
    smart_string buf = {0};
    smart_string_appendl(&buf, ZSTR_VAL(str), ZSTR_LEN(str));
    for (int i = 1; i < argc; i++) {
        zend_string *s = sl_to_js_string(args[i]);
        smart_string_appendl(&buf, ZSTR_VAL(s), ZSTR_LEN(s));
        zend_string_release(s);
    }
    smart_string_0(&buf);
    zend_string *result = buf.c ? zend_string_init(buf.c, buf.len, 0) : zend_string_init("", 0, 0);
    smart_string_free(&buf);
    return sl_val_string(result);
}

/* ============================================================
 * Method dispatch table
 *
 * Each bound method is a native function that receives:
 *   args[0] = the bound string (SL_TAG_STRING)
 *   args[1..] = actual arguments from JS call
 * ============================================================ */

typedef struct {
    const char *name;
    sl_native_handler handler;
} sl_str_method_entry;

static const sl_str_method_entry str_methods[] = {
    { "charAt",       sl_str_charAt },
    { "charCodeAt",   sl_str_charCodeAt },
    { "indexOf",      sl_str_indexOf },
    { "lastIndexOf",  sl_str_lastIndexOf },
    { "includes",     sl_str_includes },
    { "startsWith",   sl_str_startsWith },
    { "endsWith",     sl_str_endsWith },
    { "substring",    sl_str_substring },
    { "slice",        sl_str_slice },
    { "split",        sl_str_split },
    { "toLowerCase",  sl_str_toLowerCase },
    { "toUpperCase",  sl_str_toUpperCase },
    { "trim",         sl_str_trim },
    { "trimStart",    sl_str_trimStart },
    { "trimEnd",      sl_str_trimEnd },
    { "repeat",       sl_str_repeat },
    { "replace",      sl_str_replace },
    { "replaceAll",   sl_str_replaceAll },
    { "match",        sl_str_match },
    { "matchAll",     sl_str_matchAll },
    { "search",       sl_str_search },
    { "padStart",     sl_str_padStart },
    { "padEnd",       sl_str_padEnd },
    { "at",           sl_str_at },
    { "concat",       sl_str_concat },
    { NULL, NULL }
};

/* ============================================================
 * Public API
 * ============================================================ */

/*
 * sl_string_get_method — returns a bound native function for the named method.
 *
 * The native function's handler expects args[0] to be the bound string.
 * The VM dispatches bound calls by prepending the target string to the arg list.
 */
sl_value sl_string_get_method(sl_vm *vm, zend_string *str, zend_string *method_name) {
    const char *name = ZSTR_VAL(method_name);

    for (const sl_str_method_entry *e = str_methods; e->name != NULL; e++) {
        if (strcmp(name, e->name) == 0) {
            zend_string *fn_name = zend_string_init(e->name, strlen(e->name), 0);
            sl_native_func *fn = sl_native_new_bound(fn_name, e->handler, sl_val_string(zend_string_copy(str)));
            zend_string_release(fn_name);
            return sl_val_native(fn);
        }
    }

    return sl_val_undefined();
}

/*
 * sl_string_get_property — returns length or indexed character access.
 */
sl_value sl_string_get_property(zend_string *str, sl_value key) {
    /* "length" property */
    if (key.tag == SL_TAG_STRING && zend_string_equals_literal(key.u.str, "length")) {
        return sl_val_int((zend_long)sl_utf8_strlen(ZSTR_VAL(str), ZSTR_LEN(str)));
    }

    /* Numeric index: character access */
    zend_long idx = -1;
    if (key.tag == SL_TAG_INT) {
        idx = key.u.ival;
    } else if (key.tag == SL_TAG_DOUBLE && key.u.dval == (double)(zend_long)key.u.dval) {
        idx = (zend_long)key.u.dval;
    } else if (key.tag == SL_TAG_STRING) {
        zend_ulong tmp;
        if (ZEND_HANDLE_NUMERIC(key.u.str, tmp) && tmp <= ZEND_LONG_MAX) {
            idx = (zend_long)tmp;
        }
    }

    if (idx >= 0) {
        size_t char_count = sl_utf8_strlen(ZSTR_VAL(str), ZSTR_LEN(str));
        if ((size_t)idx < char_count) {
            size_t byte_off = sl_utf8_offset(ZSTR_VAL(str), ZSTR_LEN(str), (size_t)idx);
            size_t clen = sl_utf8_char_len(ZSTR_VAL(str), ZSTR_LEN(str), byte_off);
            return sl_val_string(zend_string_init(ZSTR_VAL(str) + byte_off, clen, 0));
        }
        return sl_val_undefined();
    }

    /* String key: method lookup */
    if (key.tag == SL_TAG_STRING) {
        return sl_string_get_method(NULL, str, key.u.str);
    }
    return sl_val_undefined();
}
