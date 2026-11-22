#!/bin/sh
# Variant check of cphalcon (B5). It runs in the server container.
# Prints OK or FAIL for each check. Exit code 1 when a check fails.
set -u

status=0

result() {
    if [ "$2" -eq 0 ]; then
        echo "OK   $1"
    else
        echo "FAIL $1"
        status=1
    fi
}

php-fpm -m | grep -qx phalcon
result "php-fpm loads phalcon" $?

php --ri phalcon | grep -qx "Version => ${PHALCON_VERSION}"
result "phalcon version ${PHALCON_VERSION}" $?

php -r 'exit((new ReflectionClass(Phalcon\Mvc\Micro::class))->isInternal() ? 0 : 1);'
result "Micro is the extension class" $?

test ! -e /app/vendor/phalcon
result "no Composer copy of phalcon" $?

php -i | grep -qx 'Debug Build => no'
result "release build of PHP" $?

! php -m | grep -qi xdebug
result "no Xdebug" $?

php -m | grep -qx 'Zend OPcache'
result "OPcache loaded" $?

exit "$status"
