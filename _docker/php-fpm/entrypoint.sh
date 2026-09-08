#!/usr/bin/env sh
php /application/bin/console cache:pool:clear doctrine.system_cache_pool doctrine.result_cache_pool --no-interaction || true
exec php-fpm
