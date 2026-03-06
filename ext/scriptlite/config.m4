dnl config.m4 for extension scriptlite

PHP_ARG_ENABLE(scriptlite, whether to enable scriptlite support,
[  --enable-scriptlite      Enable ScriptLite native extension])

if test "$PHP_SCRIPTLITE" != "no"; then
  PHP_NEW_EXTENSION(scriptlite,
    scriptlite.c sl_value.c sl_compiler.c sl_vm.c sl_runtime.c \
    sl_environment.c sl_builtins.c sl_string_methods.c \
    sl_array_methods.c sl_ast_reader.c,
    $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 -std=c11 -O3 -flto -Wall)
  PHP_SUBST(SCRIPTLITE_SHARED_LIBADD)
  PHP_CHECK_LIBRARY(pcre2-8, pcre2_compile_8,
    [SCRIPTLITE_SHARED_LIBADD="-lpcre2-8 -lm"],
    [AC_MSG_ERROR([pcre2 library not found, install libpcre2-dev])],
    [-lpcre2-8])
fi
