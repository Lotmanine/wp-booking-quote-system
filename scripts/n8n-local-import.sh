#!/bin/sh
# Imports the exported workflows into the local n8n container and activates them.
# Run from the repository root with the local stack up. n8n needs a restart to load webhooks.
set -e
COMPOSE="docker compose --env-file .env -f docker-compose.yml -f docker-compose.local.yml"

$COMPOSE exec -T n8n sh -c '
for file in /import/qhs-booking-*.json; do
	n8n import:workflow --input="$file"
done
for id in 5qpIqCXYRXAcyTwa hsEsqmQRhrriiLZW aONh1Sswi5Ude4Ix AoXOmSxBKSj8mGyV BX82JeAPish7AJhS; do
	n8n publish:workflow --id="$id"
done
'
$COMPOSE restart n8n
