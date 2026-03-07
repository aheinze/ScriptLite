dnl config.m4 for extension scriptlite

PHP_ARG_ENABLE(scriptlite, whether to enable scriptlite support,
[  --enable-scriptlite      Enable ScriptLite native extension])

PHP_ARG_ENABLE(scriptlite-dev, whether to enable scriptlite hardening/sanitizer flags,
[  --enable-scriptlite-dev  Enable ScriptLite dev hardening/sanitizer flags], no, no)

PHP_ARG_ENABLE(scriptlite-msan, whether to enable scriptlite MemorySanitizer flags,
[  --enable-scriptlite-msan Enable ScriptLite MSan flags], no, no)

if test "$PHP_SCRIPTLITE" != "no"; then
  SCRIPTLITE_EXTRA_CFLAGS="-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 -std=c11"
  SCRIPTLITE_SAN_LIBS=""

  if test "$PHP_SCRIPTLITE_DEV" != "no"; then
    dnl ASan + UBSan development profile
    SCRIPTLITE_EXTRA_CFLAGS="$SCRIPTLITE_EXTRA_CFLAGS -O1 -g3 -fno-omit-frame-pointer -fno-common -fstack-protector-strong -D_FORTIFY_SOURCE=2 -Wall -Wextra -Werror -fsanitize=address,undefined -fno-sanitize-recover=all"
    SCRIPTLITE_SAN_LIBS="$SCRIPTLITE_SAN_LIBS -fsanitize=address,undefined"
  else
    dnl Production profile
    SCRIPTLITE_EXTRA_CFLAGS="$SCRIPTLITE_EXTRA_CFLAGS -O3 -flto -Wall"
  fi

  if test "$PHP_SCRIPTLITE_MSAN" != "no"; then
    dnl MSan requires an MSan-built PHP + deps toolchain
    SCRIPTLITE_EXTRA_CFLAGS="$SCRIPTLITE_EXTRA_CFLAGS -O1 -g3 -fno-omit-frame-pointer -fsanitize=memory -fsanitize-memory-track-origins=2"
    SCRIPTLITE_SAN_LIBS="$SCRIPTLITE_SAN_LIBS -fsanitize=memory -fsanitize-memory-track-origins=2"
  fi

  PHP_NEW_EXTENSION(scriptlite,
    scriptlite.c sl_value.c sl_compiler.c sl_vm.c sl_runtime.c \
    sl_environment.c sl_builtins.c sl_string_methods.c \
    sl_array_methods.c sl_ast_reader.c sl_parser.c,
    $ext_shared,, $SCRIPTLITE_EXTRA_CFLAGS)
  PHP_SUBST(SCRIPTLITE_SHARED_LIBADD)
  PHP_CHECK_LIBRARY(pcre2-8, pcre2_compile_8,
    [SCRIPTLITE_SHARED_LIBADD="$SCRIPTLITE_SHARED_LIBADD $SCRIPTLITE_SAN_LIBS -lpcre2-8 -lm"],
    [AC_MSG_ERROR([pcre2 library not found, install libpcre2-dev])],
    [-lpcre2-8])
fi
