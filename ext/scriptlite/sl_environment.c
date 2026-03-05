#include "sl_environment.h"
#include "ext/spl/spl_exceptions.h"

/* Custom dtor for environment values (stores sl_value* in zval PTR) */
static void sl_env_val_dtor(zval *zv) {
    sl_value *v = (sl_value*)Z_PTR_P(zv);
    SL_DELREF(*v);
    efree(v);
}

sl_environment *sl_env_new(sl_environment *parent) {
    sl_environment *env = emalloc(sizeof(sl_environment));
    env->gc.refcount = 1;
    ALLOC_HASHTABLE(env->values);
    zend_hash_init(env->values, 8, NULL, sl_env_val_dtor, 0);
    env->const_bindings = NULL;
    env->parent = parent;
    if (parent) {
        SL_GC_ADDREF(parent);
    }
    return env;
}

void sl_env_free(sl_environment *env) {
    zend_hash_destroy(env->values);
    FREE_HASHTABLE(env->values);
    if (env->const_bindings) {
        zend_hash_destroy(env->const_bindings);
        FREE_HASHTABLE(env->const_bindings);
    }
    if (env->parent) {
        if (SL_GC_DELREF(env->parent) == 0) {
            sl_env_free(env->parent);
        }
    }
    efree(env);
}

void sl_env_define(sl_environment *env, zend_string *name, sl_value val, bool is_const) {
    sl_value *sv = emalloc(sizeof(sl_value));
    *sv = val;
    SL_ADDREF(val);

    zval zv;
    ZVAL_PTR(&zv, sv);

    /* Replace if exists, add if not */
    zend_hash_update(env->values, name, &zv);

    if (is_const) {
        if (!env->const_bindings) {
            ALLOC_HASHTABLE(env->const_bindings);
            zend_hash_init(env->const_bindings, 4, NULL, NULL, 0);
        }
        zval btrue;
        ZVAL_TRUE(&btrue);
        zend_hash_update(env->const_bindings, name, &btrue);
    }
}

sl_value sl_env_get(sl_environment *env, zend_string *name) {
    zval *found = zend_hash_find(env->values, name);
    if (found) {
        sl_value *sv = (sl_value*)Z_PTR_P(found);
        return sl_value_copy(*sv);
    }

    if (env->parent) {
        return sl_env_get(env->parent, name);
    }

    /* ReferenceError */
    zend_throw_exception_ex(spl_ce_RuntimeException, 0,
        "ReferenceError: %s is not defined", ZSTR_VAL(name));
    return sl_val_undefined();
}

bool sl_env_has(sl_environment *env, zend_string *name) {
    if (zend_hash_exists(env->values, name)) {
        return true;
    }
    if (env->parent) {
        return sl_env_has(env->parent, name);
    }
    return false;
}

void sl_env_set(sl_environment *env, zend_string *name, sl_value val) {
    zval *found = zend_hash_find(env->values, name);
    if (found) {
        /* Check const */
        if (env->const_bindings && zend_hash_exists(env->const_bindings, name)) {
            zend_throw_exception_ex(spl_ce_RuntimeException, 0,
                "TypeError: Assignment to constant variable '%s'", ZSTR_VAL(name));
            return;
        }
        sl_value *sv = (sl_value*)Z_PTR_P(found);
        SL_DELREF(*sv);
        *sv = val;
        SL_ADDREF(val);
        return;
    }

    if (env->parent) {
        sl_env_set(env->parent, name, val);
        return;
    }

    zend_throw_exception_ex(spl_ce_RuntimeException, 0,
        "ReferenceError: %s is not defined", ZSTR_VAL(name));
}

sl_environment *sl_env_extend(sl_environment *env) {
    return sl_env_new(env);
}
