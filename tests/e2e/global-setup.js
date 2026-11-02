import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '../..' );

export const compose = [ 'compose', '--env-file', '.env', '-f', 'docker-compose.yml', '-f', 'docker-compose.local.yml' ];

export function wp( ...args ) {
	return execFileSync( 'docker', [ ...compose, 'exec', '-T', '-u', 'www-data', 'wordpress', 'wp', ...args ], { cwd: root, encoding: 'utf8' } ).trim();
}

export function sql( query ) {
	const script = 'mariadb -N -ubooking -p"$MARIADB_PASSWORD" booking -e "$1"';
	return execFileSync( 'docker', [ ...compose, 'exec', '-T', 'db', 'sh', '-c', script, '--', query ], { cwd: root, encoding: 'utf8' } ).trim();
}

export default async function globalSetup() {
	wp( 'qhs', 'reset', '--yes' );
}
