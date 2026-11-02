#!/bin/sh
# Runs the WordPress integration suite in the local stack. Extra arguments go to PHPUnit.
set -e
cd "$(dirname "$0")/.."
COMPOSE="docker compose --env-file .env -f docker-compose.yml -f docker-compose.local.yml --profile tests"
$COMPOSE run --rm -T wp-tests -c '
php -r "\$c = mysqli_connect(\"db\", \"root\", getenv(\"DB_ROOT_PASSWORD\")); mysqli_query(\$c, \"DROP DATABASE IF EXISTS wp_tests\"); mysqli_query(\$c, \"CREATE DATABASE wp_tests CHARACTER SET utf8mb4\"); mysqli_query(\$c, \"GRANT ALL ON wp_tests.* TO booking@\\\"%\\\"\");"
php vendor/bin/phpunit "$@"
' -- "$@"
