#!/bin/sh
# Build-host prerequisites only. Never run on an OPNsense router.
set -eu
[ "$(uname -s)" = Linux ] || { echo 'Runner preparation supports Linux only.' >&2; exit 1; }
[ -f /etc/debian_version ] || { echo 'Use the documented Debian/Ubuntu build host.' >&2; exit 1; }
privileged() {
    if [ "$(id -u)" = 0 ]; then "$@"
    elif command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then sudo -n "$@"
    else echo 'Missing build dependencies; an administrator must run the command below:' >&2
         printf 'sudo'; printf ' %s' "$@"; printf '\n'; exit 1
    fi
}
case "${1:-php}" in
php)
    if ! command -v php >/dev/null 2>&1; then
        echo 'Install PHP CLI 8.3+ and php-xml first; see docs/BUILD.md.' >&2; exit 1
    fi
    if ! php -r 'exit(extension_loaded("SimpleXML") && extension_loaded("dom") ? 0 : 1);'; then
        php_series=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
        case "$php_series" in [0-9].[0-9]) ;; *) echo 'Unsupported PHP version string' >&2; exit 1;; esac
        echo "Preparing missing PHP XML modules for PHP $php_series on the build host."
        privileged apt-get update
        privileged apt-get install -y --no-install-recommends "php${php_series}-xml"
    fi
    ;;
browser)
    if ! node -e 'require("playwright").chromium.launch({headless:true}).then(b=>b.close()).catch(()=>process.exit(1))'; then
        echo 'Preparing Chromium system libraries on the build host.'
        node_path=$(command -v node)
        privileged "$node_path" node_modules/playwright/cli.js install-deps chromium
    fi
    ;;
*) echo 'Usage: prepare-runner.sh [php|browser]' >&2; exit 1;;
esac
