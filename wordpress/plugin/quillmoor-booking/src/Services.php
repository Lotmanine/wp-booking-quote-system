<?php
/**
 * Service locator.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking;

use Quillmoor\Booking\Crm\CrmPipeline;
use Quillmoor\Booking\Crm\CrmPort;
use Quillmoor\Booking\Crm\FluentCrmAdapter;
use Quillmoor\Booking\Crm\NullCrm;
use Quillmoor\Booking\Domain\Availability;
use Quillmoor\Booking\Domain\BookingRepository;
use Quillmoor\Booking\Domain\BookingService;
use Quillmoor\Booking\Domain\NightClaims;
use Quillmoor\Booking\Domain\TableSlots;
use Quillmoor\Booking\Domain\StateMachine;
use Quillmoor\Booking\Mail\BrevoMailer;
use Quillmoor\Booking\Mail\LogOnlyMailer;
use Quillmoor\Booking\Mail\MailPort;
use Quillmoor\Booking\Mail\MailService;
use Quillmoor\Booking\Payment\DepositPort;
use Quillmoor\Booking\Payment\Refunds;
use Quillmoor\Booking\Payment\WooDepositPort;
use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Security\Counters;
use Quillmoor\Booking\Security\Signer;
use Quillmoor\Booking\Security\Turnstile;

defined( 'ABSPATH' ) || exit;

/**
 * Lazy singletons. Ports can be swapped through filters.
 */
final class Services {

	/**
	 * Resolved instances.
	 *
	 * @var array<string,object>
	 */
	private static array $instances = array();

	/**
	 * Drop every instance, used between tests.
	 */
	public static function reset(): void {
		self::$instances = array();
	}

	/**
	 * Resolve once.
	 *
	 * @template T of object
	 * @param class-string<T> $key     Service key.
	 * @param callable():T    $factory Factory.
	 * @return T
	 */
	private static function once( string $key, callable $factory ): object {
		if ( ! isset( self::$instances[ $key ] ) ) {
			self::$instances[ $key ] = $factory();
		}
		/**
		 * Stored under its own class key.
		 *
		 * @var T
		 */
		return self::$instances[ $key ];
	}

	/**
	 * Booking repository.
	 */
	public static function bookings(): BookingRepository {
		return self::once( BookingRepository::class, static fn() => new BookingRepository() );
	}

	/**
	 * Signer.
	 */
	public static function signer(): Signer {
		return self::once( Signer::class, static fn() => new Signer() );
	}

	/**
	 * Counters.
	 */
	public static function counters(): Counters {
		return self::once( Counters::class, static fn() => new Counters() );
	}

	/**
	 * Outbox.
	 */
	public static function outbox(): Outbox {
		return self::once( Outbox::class, static fn() => new Outbox( self::bookings(), self::signer() ) );
	}

	/**
	 * State machine.
	 */
	public static function machine(): StateMachine {
		return self::once( StateMachine::class, static fn() => new StateMachine( self::outbox() ) );
	}

	/**
	 * Night claims for stays.
	 */
	public static function nights(): NightClaims {
		return self::once( NightClaims::class, static fn() => new NightClaims() );
	}

	/**
	 * Seat capacity for dining.
	 */
	public static function tables(): TableSlots {
		return self::once( TableSlots::class, static fn() => new TableSlots() );
	}

	/**
	 * Availability.
	 */
	public static function availability(): Availability {
		return self::once( Availability::class, static fn() => new Availability( self::nights(), self::tables() ) );
	}

	/**
	 * Booking policy.
	 */
	public static function policy(): BookingPolicy {
		return self::once( BookingPolicy::class, static fn() => new BookingPolicy( self::bookings() ) );
	}

	/**
	 * Turnstile verifier.
	 */
	public static function turnstile(): Turnstile {
		return self::once( Turnstile::class, static fn() => new Turnstile() );
	}

	/**
	 * Mail transport. Log-only until a Brevo key exists.
	 */
	public static function mail_port(): MailPort {
		return self::once(
			MailPort::class,
			static function (): MailPort {
				$key = Config::get( 'BREVO_API_KEY' );
				return apply_filters( 'quillmoor_mail_port', '' === $key ? new LogOnlyMailer() : new BrevoMailer( $key ) );
			}
		);
	}

	/**
	 * Mail service.
	 */
	public static function mail(): MailService {
		return self::once( MailService::class, static fn() => new MailService( self::mail_port(), self::counters() ) );
	}

	/**
	 * CRM port.
	 */
	public static function crm(): CrmPort {
		return self::once(
			CrmPort::class,
			static function (): CrmPort {
				$port = function_exists( 'FluentCrmApi' ) ? new FluentCrmAdapter() : new NullCrm();
				return apply_filters( 'quillmoor_crm_port', $port );
			}
		);
	}

	/**
	 * CRM pipeline.
	 */
	public static function pipeline(): CrmPipeline {
		return self::once( CrmPipeline::class, static fn() => new CrmPipeline( self::crm(), self::bookings() ) );
	}

	/**
	 * Deposit port.
	 */
	public static function deposit(): DepositPort {
		return self::once( DepositPort::class, static fn() => apply_filters( 'quillmoor_deposit_port', new WooDepositPort() ) );
	}

	/**
	 * Booking service.
	 */
	public static function service(): BookingService {
		return self::once(
			BookingService::class,
			static fn() => new BookingService( self::bookings(), self::machine(), self::nights(), self::tables(), self::availability(), self::outbox(), self::mail() )
		);
	}

	/**
	 * Deposit refunds.
	 */
	public static function refunds(): Refunds {
		return self::once( Refunds::class, static fn() => new Refunds( self::bookings() ) );
	}
}
