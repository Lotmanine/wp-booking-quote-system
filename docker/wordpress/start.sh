#!/bin/bash
# Site setup runs beside Apache, so a slow install never fails the health check.
set -e
mkdir -p /var/www/html/wp-content/uploads
chown www-data:www-data /var/www/html/wp-content /var/www/html/wp-content/uploads
# The official image keeps /var/www/html in an anonymous volume that survives a redeploy, so a new
# image would otherwise keep serving old code. Sync from the image on every start.
rm -rf /var/www/html/wp-content/plugins/quillmoor-booking /var/www/html/wp-content/themes/quillmoor
cp -a /usr/src/wordpress/. /var/www/html/
runuser -u www-data -- /usr/local/bin/qhs-init.sh &
exec docker-entrypoint.sh apache2-foreground
