#!/bin/sh
# One-shot site bootstrap. Safe to run again: every step checks state first.
set -eu

cd /var/www/html

echo "Waiting for WordPress files and the database"
tries=0
until [ -f wp-config.php ] && php -r 'mysqli_report(MYSQLI_REPORT_OFF); $c = @mysqli_connect("db", getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME")); exit($c ? 0 : 1);' ; do
	tries=$((tries + 1))
	if [ "$tries" -gt 60 ]; then
		echo "Timed out waiting for the database" >&2
		exit 1
	fi
	sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
	wp core install \
		--url="${WP_HOME}" \
		--title="Quillmoor" \
		--admin_user="${WP_ADMIN_USER}" \
		--admin_email="${WP_ADMIN_EMAIL}" \
		--admin_password="${WP_ADMIN_PASSWORD}" \
		--skip-email
fi

wp rewrite structure '/%postname%/'
wp theme activate quillmoor
wp plugin activate secure-custom-fields woocommerce woocommerce-gateway-stripe fluent-crm quillmoor-booking
wp qhs setup
if [ "${QHS_DEMO_SITE:-0}" = "1" ] && ! wp user get demo-customer >/dev/null 2>&1; then
	wp qhs reset --yes
fi
echo "Site ready at ${WP_HOME}"
