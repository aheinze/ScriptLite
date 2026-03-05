# ScriptLite C Extension Build Guide

This folder contains the native `scriptlite` PHP extension.

## Prerequisites

- PHP CLI + development package for the same version you will run
- `phpize` and `php-config` from that same PHP install
- C compiler with C11 support (`gcc` or `clang`)
- PCRE2 development library (`libpcre2-8` / `libpcre2-dev`)
- `make`

Quick sanity check:

```bash
php -v
phpize -v
php-config --version
```

`php`, `phpize`, and `php-config` should point to the same PHP version.

## Build

From repository root:

```bash
cd ext/scriptlite
phpize
./configure --enable-scriptlite
make -j"$(nproc)"
```

Build artifact:

- `ext/scriptlite/modules/scriptlite.so`

## Load The Extension

One-off CLI run:

```bash
php -d extension=/absolute/path/to/ext/scriptlite/modules/scriptlite.so -m | grep scriptlite
```

Persistent load (recommended via `conf.d`):

```bash
echo "extension=/absolute/path/to/ext/scriptlite/modules/scriptlite.so" > /path/to/php/conf.d/50-scriptlite.ini
```

Or install to `extension_dir`:

```bash
cd ext/scriptlite
make install
```

Then enable with:

```ini
extension=scriptlite
```

## Verify

```bash
php -r "var_dump(extension_loaded('scriptlite'));"
```

Expected output:

```text
bool(true)
```

## Run Project Tests With Native Backend

From repository root:

```bash
php -d extension=/absolute/path/to/ext/scriptlite/modules/scriptlite.so vendor/bin/phpunit
```

## Rebuild Notes

- Rebuild whenever C sources change:

```bash
cd ext/scriptlite
make clean
make -j"$(nproc)"
```

- Rebuild whenever PHP minor version changes (for example, 8.3 -> 8.4).
