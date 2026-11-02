// Fires truly parallel holds at the running stack, then checks the database.
// Usage, from the repository root with the local stack up: node tests/concurrency/parallel-hold.mjs [parallel] [rounds]
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://localhost:18091';
const API = `${ BASE }/wp-json/qhs/v1`;
const PARALLEL = Number( process.argv[ 2 ] || 12 );
const ROUNDS = Number( process.argv[ 3 ] || 5 );
const TOKEN = 'XXXX.DUMMY.TOKEN.XXXX'; // Cloudflare's documented dummy token for its test keys.

function sql( query ) {
	const compose = [ 'compose', '--env-file', '.env', '-f', 'docker-compose.yml', '-f', 'docker-compose.local.yml' ];
	const script = 'mariadb -N -ubooking -p"$MARIADB_PASSWORD" booking -e "$1"';
	return execFileSync( 'docker', [ ...compose, 'exec', '-T', 'db', 'sh', '-c', script, '--', query ], { encoding: 'utf8' } ).trim();
}

async function post( path, body, headers = {} ) {
	const started = performance.now();
	const response = await fetch( `${ API }/${ path }`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', ...headers },
		body: JSON.stringify( body ),
	} );
	return { status: response.status, data: await response.json(), started, finished: performance.now() };
}

async function quote( run, index, area ) {
	const { status, data } = await post( 'quote', {
		service: 'cleaning',
		size: 'small',
		area,
		extras: [],
		full_name: 'Parallel Test',
		email: `parallel-${ run }-${ index }@example.com`,
		turnstile_token: TOKEN,
	} );
	if ( status !== 201 ) {
		throw new Error( `quote failed: ${ status } ${ JSON.stringify( data ) }` );
	}
	return { id: data.booking.id, token: data.token };
}

function maxInFlight( results ) {
	const edges = results.flatMap( ( r ) => [ [ r.started, 1 ], [ r.finished, -1 ] ] ).sort( ( a, b ) => a[ 0 ] - b[ 0 ] );
	let now = 0;
	let peak = 0;
	for ( const [ , change ] of edges ) {
		now += change;
		peak = Math.max( peak, now );
	}
	return peak;
}

async function round( run, area, capacity, slotIndex ) {
	const days = ( await ( await fetch( `${ API }/availability?area=${ area }` ) ).json() ).days;
	const slot = Object.values( days ).flat()[ slotIndex ];
	const bookings = await Promise.all( Array.from( { length: PARALLEL }, ( _, i ) => quote( run, i, area ) ) );

	const results = await Promise.all(
		bookings.map( ( b ) => post( 'hold', { booking_id: b.id, slot_start: slot, turnstile_token: TOKEN }, { 'X-QHS-Token': b.token } ) )
	);

	const winners = results.filter( ( r ) => r.status === 201 );
	const taken = results.filter( ( r ) => r.status === 409 && r.data.code === 'slot_taken' );
	const claims = Number( sql( `SELECT COUNT(*) FROM qwp_qhs_slot_claims WHERE area = '${ area }' AND slot_start = '${ slot }'` ) );
	const held = Number( sql( `SELECT COUNT(*) FROM qwp_qhs_bookings WHERE state = 'hold' AND area = '${ area }' AND slot_start = '${ slot }'` ) );
	const distinct = Number( sql( `SELECT COUNT(DISTINCT unit) FROM qwp_qhs_slot_claims WHERE area = '${ area }' AND slot_start = '${ slot }'` ) );
	const statuses = results.reduce( ( map, r ) => ( { ...map, [ r.status ]: ( map[ r.status ] || 0 ) + 1 } ), {} );
	const pass = winners.length === capacity && taken.length === PARALLEL - capacity && claims === capacity && held === capacity && distinct === capacity;

	console.log(
		`${ pass ? 'PASS' : 'FAIL' } round ${ run } area=${ area } slot="${ slot }" parallel=${ PARALLEL } peak_in_flight=${ maxInFlight( results ) } ` +
		`statuses=${ JSON.stringify( statuses ) } winners=${ winners.length } claims_in_db=${ claims } held_in_db=${ held }`
	);
	return pass;
}

const stamp = Date.now();
let failed = 0;
for ( let i = 0; i < ROUNDS; i++ ) {
	failed += ( await round( `${ stamp }-${ i }`, 'north', 1, i ) ) ? 0 : 1;
}
failed += ( await round( `${ stamp }-cap`, 'central', 2, 0 ) ) ? 0 : 1;
console.log( failed === 0 ? `All ${ ROUNDS + 1 } rounds passed.` : `${ failed } rounds failed.` );
process.exit( failed === 0 ? 0 : 1 );
