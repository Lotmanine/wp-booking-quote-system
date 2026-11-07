#!/bin/bash
# Site setup runs beside Apache, so a slow install never fails the health check.
set -e
# Trust X-Forwarded-For only from the networks this container is attached to, where the proxy connects from.
php /usr/local/share/qhs/qhs-remoteip.php /etc/apache2/conf-available/remoteip.conf
mkdir -p /var/www/html/wp-content/uploads /var/www/html/wp-content/wflogs
chown www-data:www-data /var/www/html/wp-content /var/www/html/wp-content/uploads /var/www/html/wp-content/wflogs
# The official image keeps /var/www/html in an anonymous volume that survives a redeploy, so a new
# image would otherwise keep serving old code. Sync from the image on every start.
rm -rf /var/www/html/wp-content/plugins/quillmoor-booking /var/www/html/wp-content/themes/quillmoor
cp -a /usr/src/wordpress/. /var/www/html/
runuser -u www-data -- /usr/local/bin/qhs-init.sh &
exec docker-entrypoint.sh apache2-foreground
