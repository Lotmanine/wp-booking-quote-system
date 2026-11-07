# Stage 1: install the plugin's runtime Composer packages.
FROM composer:2.11.1 AS vendor
WORKDIR /build
COPY wordpress/plugin/quillmoor-booking/composer.json wordpress/plugin/quillmoor-booking/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --ignore-platform-reqs --optimize-autoloader

# Stage 2: the pinned WP-CLI build.
FROM wordpress:cli-2.12.0-php8.5 AS wpcli

# Stage 3: WordPress with pinned plugins (Wordfence among them), the plugin and the theme baked in.
FROM wordpress:7.1.4-php8.5-apache

ARG WOOCOMMERCE_VERSION=11.2.1
ARG STRIPE_GATEWAY_VERSION=11.1.1
ARG FLUENT_CRM_VERSION=3.3.1
ARG SCF_VERSION=6.9.7
ARG WORDFENCE_VERSION=9.0.2

RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends unzip; \
	rm -rf /var/lib/apt/lists/*; \
	cd /usr/src/wordpress/wp-content/plugins; \
	for pair in "woocommerce.${WOOCOMMERCE_VERSION}" "woocommerce-gateway-stripe.${STRIPE_GATEWAY_VERSION}" "fluent-crm.${FLUENT_CRM_VERSION}" "secure-custom-fields.${SCF_VERSION}" "wordfence.${WORDFENCE_VERSION}"; do \
		curl -fsSL --retry 6 --retry-all-errors --retry-delay 3 -o /tmp/p.zip "https://downloads.wordpress.org/plugin/${pair}.zip"; \
		unzip -q /tmp/p.zip; \
		rm /tmp/p.zip; \
	done; \
	rm -rf akismet hello.php; \
	cd ../themes; \
	rm -rf twentytwentythree twentytwentyfour; \
	a2enmod proxy proxy_http

COPY --from=wpcli /usr/local/bin/wp /usr/local/bin/wp
COPY docker/wordpress/php.ini /usr/local/etc/php/conf.d/zz-quillmoor.ini
COPY docker/wordpress/apache-limits.conf /etc/apache2/conf-enabled/zz-limits.conf
COPY docker/wordpress/apache-dispatch.conf /etc/apache2/conf-enabled/zz-dispatch.conf
COPY docker/wordpress/apache-security.conf /etc/apache2/conf-enabled/zz-security.conf
COPY docker/wordpress/wordfence-waf.php docker/wordpress/wordfence-config.php docker/wordpress/qhs-remoteip.php /usr/local/share/qhs/
COPY docker/wordpress/start.sh /usr/local/bin/qhs-start.sh
COPY docker/wordpress/init.sh /usr/local/bin/qhs-init.sh

COPY wordpress/plugin/quillmoor-booking /usr/src/wordpress/wp-content/plugins/quillmoor-booking
COPY --from=vendor /build/vendor /usr/src/wordpress/wp-content/plugins/quillmoor-booking/vendor
COPY wordpress/theme/quillmoor /usr/src/wordpress/wp-content/themes/quillmoor

RUN chmod +x /usr/local/bin/qhs-start.sh /usr/local/bin/qhs-init.sh \
	&& chown -R www-data:www-data /usr/src/wordpress/wp-content

ENTRYPOINT ["/usr/local/bin/qhs-start.sh"]
