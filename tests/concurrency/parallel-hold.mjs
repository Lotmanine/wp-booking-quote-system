// Fires truly parallel holds at the running stack, then checks the claim tables in the database.
//
//   (a) the same stay nights on one listing, several rounds: exactly one winner, exactly N night rows
//       for it, every other request refused with "unavailable";
//   (b) overlapping but different stay ranges on one listing: at most one booking per night;
//   (c) one dining slot: parties sized so exactly k fit, exactly k winners, seats never above capacity.
//
// Usage, from the repository root with the local stack up:
//   node tests/concurrency/parallel-hold.mjs [parallel] [stay_rounds]
// Winners are cancelled at the end, so the demo calendars are left as they were.
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://localhost:18091';
const API = `${ BASE }/wp-json/qhs/v1`;
const PARALLEL = Number( process.argv[ 2 ] || 10 );
const STAY_ROUNDS = Number( process.argv[ 3 ] || 3 );
const NIGHTS = 3;
const FIRST_DAY = 120; // Far beyond the seeded sample bookings, inside the 270-day stay window.
const DINING_FIRST_DAY = 30; // Inside the 60-day dining window, beyond the seeded table.
const TOKEN = 'XXXX.DUMMY.TOKEN.XXXX'; // Cloudflare's documented dummy token for its test keys.
const stamp = Date.now();

function sql( query ) {
	const compose = [ 'compose', '--env-file', '.env', '-f', 'docker-compose.yml', '-f', 'docker-compose.local.yml' ];
	const script = 'mariadb -N -ubooking -p"$MARIADB_PASSWORD" booking -e "$1"';
	return execFileSync( 'docker', [ ...compose, 'exec', '-T', 'db', 'sh', '-c', script, '--', query ], { encoding: 'utf8' } ).trim();
}

const num = ( query ) => Number( sql( query ) || 0 );

async function get( path ) {
	const response = await fetch( `${ API }/${ path }` );
	if ( ! response.ok ) {
		throw new Error( `GET ${ path } failed: ${ response.status }` );
	}
	return response.json();
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

function day( offset ) {
	return new Date( Date.now() + offset * 86400000 ).toISOString().slice( 0, 10 );
}

function plus( date, days ) {
	return new Date( Date.parse( `${ date }T00:00:00Z` ) + days * 86400000 ).toISOString().slice( 0, 10 );
}

async function quote( tag, index, fields ) {
	const { status, data } = await post( 'quote', {
		...fields,
		extras: [],
		full_name: 'Parallel Test',
		email: `parallel-${ stamp }-${ tag }-${ index }@example.com`,
		turnstile_token: TOKEN,
	} );
	if ( status !== 201 ) {
		throw new Error( `quote failed: ${ status } ${ JSON.stringify( data ) }` );
	}
	return { id: data.booking.id, token: data.token };
}

// Every quote is created first, then every hold is released at the same moment.
async function holdAll( bookings ) {
	return Promise.all(
		bookings.map( ( b ) => post( 'hold', { booking_id: b.id, turnstile_token: TOKEN }, { 'X-QHS-Token': b.token } ) )
	);
}

function maxInFlight( results ) {
	const edges = results.flatMap( ( r ) => [ [ r.started, 1 ], [ r.finished, -1 ] ] ).sort( ( a, b ) => a[ 0 ] - b[ 0 ] || a[ 1 ] - b[ 1 ] );
	let now = 0;
	let peak = 0;
	for ( const [ , change ] of edges ) {
		now += change;
		peak = Math.max( peak, now );
	}
	return peak;
}

function statuses( results ) {
	return results.reduce( ( map, r ) => {
		const key = r.status === 201 ? '201' : `${ r.status }:${ r.data.code }`;
		return { ...map, [ key ]: ( map[ key ] || 0 ) + 1 };
	}, {} );
}

const winners = [];

// A window of free nights on a stay listing, starting at or after the given day offset.
async function freeWindow( listing, fromOffset, length ) {
	const from = day( fromOffset );
	const to = day( fromOffset + 120 );
	const { unavailable } = await get( `listings/${ listing }/calendar?from=${ from }&to=${ to }` );
	for ( let start = fromOffset; start < fromOffset + 110; start++ ) {
		const nights = Array.from( { length }, ( _, i ) => day( start + i ) );
		if ( nights.every( ( n ) => ! unavailable[ n ] ) ) {
			return { offset: start, first: nights[ 0 ] };
		}
	}
	throw new Error( `no free window of ${ length } nights on listing ${ listing }` );
}

async function stayListing() {
	const { listings } = await get( 'listings?type=stay' );
	for ( const card of listings ) {
		const calendar = await get( `listings/${ card.id }/calendar` );
		if ( calendar.min_nights <= NIGHTS && calendar.max_nights >= NIGHTS ) {
			return card;
		}
	}
	throw new Error( `no stay listing takes ${ NIGHTS } nights` );
}

// (a) Everyone wants the same nights.
async function sameNights( listing, round, offset ) {
	const { first } = await freeWindow( listing.id, offset, NIGHTS );
	const last = plus( first, NIGHTS - 1 );
	const checkOut = plus( first, NIGHTS );
	const bookings = await Promise.all(
		Array.from( { length: PARALLEL }, ( _, i ) => quote( `a${ round }`, i, { listing_id: listing.id, check_in: first, check_out: checkOut, guests: 1 } ) )
	);
	const results = await holdAll( bookings );

	const won = bookings.filter( ( _, i ) => results[ i ].status === 201 );
	const refused = results.filter( ( r ) => r.status === 409 && r.data.code === 'unavailable' );
	const ids = bookings.map( ( b ) => b.id ).join( ',' );
	const winnerNights = won.length === 1 ? num( `SELECT COUNT(*) FROM qwp_qhs_night_claims WHERE booking_id = ${ won[ 0 ].id }` ) : -1;
	const rangeNights = num( `SELECT COUNT(*) FROM qwp_qhs_night_claims WHERE listing_id = ${ listing.id } AND night BETWEEN '${ first }' AND '${ last }'` );
	const loserNights = num( `SELECT COUNT(*) FROM qwp_qhs_night_claims WHERE booking_id IN (${ ids }) AND booking_id <> ${ won[ 0 ]?.id ?? 0 }` );
	const held = num( `SELECT COUNT(*) FROM qwp_qhs_bookings WHERE id IN (${ ids }) AND state = 'hold'` );
	winners.push( ...won );

	const pass = won.length === 1 && refused.length === PARALLEL - 1 && winnerNights === NIGHTS && rangeNights === NIGHTS && loserNights === 0 && held === 1;
	console.log(
		`${ pass ? 'PASS' : 'FAIL' } (a) same nights, round ${ round }: listing=${ listing.id } ${ first }..${ checkOut } parallel=${ PARALLEL } ` +
		`peak_in_flight=${ maxInFlight( results ) } statuses=${ JSON.stringify( statuses( results ) ) } winners=${ won.length } ` +
		`winner_nights=${ winnerNights } nights_in_range=${ rangeNights } loser_nights=${ loserNights } held=${ held }`
	);
	return pass;
}

// (b) Overlapping but different ranges: starts shift by 0 to 3 nights.
async function overlapping( listing, offset ) {
	const span = NIGHTS + 3;
	const { first } = await freeWindow( listing.id, offset, span );
	const last = plus( first, span - 1 );
	const ranges = Array.from( { length: PARALLEL }, ( _, i ) => {
		const checkIn = plus( first, i % 4 );
		return { check_in: checkIn, check_out: plus( checkIn, NIGHTS ) };
	} );
	const bookings = await Promise.all( ranges.map( ( r, i ) => quote( 'b', i, { listing_id: listing.id, guests: 1, ...r } ) ) );
	const results = await holdAll( bookings );

	const won = bookings.filter( ( _, i ) => results[ i ].status === 201 );
	const lost = bookings.filter( ( _, i ) => results[ i ].status !== 201 );
	const refusedOk = results.every( ( r ) => r.status === 201 || ( r.status === 409 && r.data.code === 'unavailable' ) );
	const perNightMax = num(
		`SELECT COALESCE(MAX(c), 0) FROM (SELECT COUNT(*) AS c FROM qwp_qhs_night_claims WHERE listing_id = ${ listing.id } AND night BETWEEN '${ first }' AND '${ last }' GROUP BY night) t`
	);
	const inWindow = num( `SELECT COUNT(*) FROM qwp_qhs_night_claims WHERE listing_id = ${ listing.id } AND night BETWEEN '${ first }' AND '${ last }'` );
	const wonEach = won.map( ( b ) => num( `SELECT COUNT(*) FROM qwp_qhs_night_claims WHERE booking_id = ${ b.id }` ) );
	const lostNights = lost.length ? num( `SELECT COUNT(*) FROM qwp_qhs_night_claims WHERE booking_id IN (${ lost.map( ( b ) => b.id ).join( ',' ) })` ) : 0;
	winners.push( ...won );

	const pass = won.length >= 1 && refusedOk && perNightMax <= 1 && wonEach.every( ( n ) => n === NIGHTS ) && inWindow === won.length * NIGHTS && lostNights === 0;
	console.log(
		`${ pass ? 'PASS' : 'FAIL' } (b) overlapping ranges: listing=${ listing.id } window=${ first }..${ plus( first, span ) } parallel=${ PARALLEL } ` +
		`peak_in_flight=${ maxInFlight( results ) } statuses=${ JSON.stringify( statuses( results ) ) } winners=${ won.length } ` +
		`max_claims_per_night=${ perNightMax } nights_in_window=${ inWindow } loser_nights=${ lostNights }`
	);
	return pass;
}

// (c) One dining slot with room for exactly k parties. With leftover seats, the party size is chosen so
// the seats left after k parties are fewer than one more party, which the database must still refuse.
async function diningSlot( round, fromOffset, leftover ) {
	const { listings } = await get( 'listings?type=dining' );
	for ( const card of listings ) {
		const covers = num( `SELECT meta_value FROM qwp_postmeta WHERE post_id = ${ card.id } AND meta_key = 'qm_covers'` );
		const minParty = num( `SELECT meta_value FROM qwp_postmeta WHERE post_id = ${ card.id } AND meta_key = 'qm_min_party'` );
		const maxParty = num( `SELECT meta_value FROM qwp_postmeta WHERE post_id = ${ card.id } AND meta_key = 'qm_max_party'` );
		let party = maxParty;
		while ( leftover && party > minParty && covers % party === 0 ) {
			party--;
		}
		const k = Math.floor( covers / party );
		if ( k < 2 || ( leftover && covers % party === 0 ) ) {
			continue;
		}
		for ( let offset = fromOffset; offset < 58; offset++ ) {
			const date = day( offset );
			const { slots } = await get( `listings/${ card.id }/slots?date=${ date }&party=${ party }` );
			const slot = slots.find( ( s ) => s.seats_left === covers );
			if ( ! slot ) {
				continue;
			}
			const requests = Math.max( PARALLEL, k + 4 );
			const bookings = await Promise.all(
				Array.from( { length: requests }, ( _, i ) => quote( `c${ round }`, i, { listing_id: card.id, slot_start: slot.slot_start, guests: party } ) )
			);
			const results = await holdAll( bookings );

			const won = bookings.filter( ( _, i ) => results[ i ].status === 201 );
			const refused = results.filter( ( r ) => r.status === 409 && r.data.code === 'unavailable' );
			const row = sql( `SELECT booked, capacity FROM qwp_qhs_table_slots WHERE listing_id = ${ card.id } AND slot_start = '${ slot.slot_start }'` ).split( /\s+/ ).map( Number );
			const claims = num( `SELECT COUNT(*) FROM qwp_qhs_table_claims WHERE listing_id = ${ card.id } AND slot_start = '${ slot.slot_start }'` );
			const seats = num( `SELECT COALESCE(SUM(covers), 0) FROM qwp_qhs_table_claims WHERE listing_id = ${ card.id } AND slot_start = '${ slot.slot_start }'` );
			const held = num( `SELECT COUNT(*) FROM qwp_qhs_bookings WHERE id IN (${ bookings.map( ( b ) => b.id ).join( ',' ) }) AND state = 'hold'` );
			winners.push( ...won );

			const [ booked, capacity ] = row;
			const pass = won.length === k && refused.length === requests - k && booked === k * party && booked <= capacity && capacity === covers && claims === k && seats === booked && held === k;
			console.log(
				`${ pass ? 'PASS' : 'FAIL' } (c) dining slot, round ${ round }: listing=${ card.id } slot="${ slot.slot_start }" capacity=${ capacity } party=${ party } k=${ k } parallel=${ requests } ` +
				`peak_in_flight=${ maxInFlight( results ) } statuses=${ JSON.stringify( statuses( results ) ) } winners=${ won.length } ` +
				`booked_in_db=${ booked } claims_in_db=${ claims } held=${ held }`
			);
			return pass;
		}
	}
	throw new Error( 'no dining listing with a free slot for two or more parties' );
}

let failed = 0;
let total = 0;
const run = async ( fn ) => {
	total++;
	failed += ( await fn() ) ? 0 : 1;
};

try {
	const listing = await stayListing();
	for ( let i = 0; i < STAY_ROUNDS; i++ ) {
		await run( () => sameNights( listing, i + 1, FIRST_DAY + i * 10 ) );
	}
	await run( () => overlapping( listing, FIRST_DAY + STAY_ROUNDS * 10 ) );
	await run( () => diningSlot( 1, DINING_FIRST_DAY, false ) );
	await run( () => diningSlot( 2, DINING_FIRST_DAY + 1, true ) );
} finally {
	// Cancel the winning holds so the nights and seats are free again.
	await Promise.all( winners.map( ( b ) => post( `bookings/${ b.id }/cancel`, {}, { 'X-QHS-Token': b.token } ) ) );
}

console.log( failed === 0 ? `All ${ total } rounds passed.` : `${ failed } of ${ total } rounds failed.` );
process.exit( failed === 0 ? 0 : 1 );
