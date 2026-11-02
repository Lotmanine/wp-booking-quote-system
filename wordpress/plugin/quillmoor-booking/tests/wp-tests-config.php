<?php
define( 'ABSPATH', '/usr/src/wordpress/' );
define( 'WP_DEFAULT_THEME', 'quillmoor' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wptests_';
