<?php
/**
 * FluentCRM adapter.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Crm;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;

defined( 'ABSPATH' ) || exit;

/**
 * Contact status follows consent: subscribed only with consent, transactional otherwise.
 */
final class FluentCrmAdapter implements CrmPort {

	/**
	 * Create or update a contact.
	 *
	 * @param string   $email       Address.
	 * @param string   $full_name   Name.
	 * @param bool     $consent     Consent.
	 * @param string[] $add_tags    Tag slugs to add.
	 * @param string[] $remove_tags Tag slugs to remove.
	 */
	public function sync_contact( string $email, string $full_name, bool $consent, array $add_tags, array $remove_tags ): bool {
		$parts   = explode( ' ', $full_name, 2 );
		$contact = FluentCrmApi( 'contacts' )->createOrUpdate(
			array(
				'email'      => $email,
				'first_name' => $parts[0],
				'last_name'  => $parts[1] ?? '',
				'status'     => $consent ? 'subscribed' : 'transactional',
			),
			true
		);
		if ( ! $contact ) {
			return false;
		}
		$contact->status = $consent ? 'subscribed' : 'transactional';
		$contact->save();

		$add_ids = $this->tag_ids( $add_tags );
		if ( array() !== $add_ids ) {
			$contact->attachTags( $add_ids );
		}
		$remove_ids = $this->tag_ids( $remove_tags );
		if ( array() !== $remove_ids ) {
			$contact->detachTags( $remove_ids );
		}
		return true;
	}

	/**
	 * Tag slugs on a contact.
	 *
	 * @param string $email Address.
	 * @return string[]
	 */
	public function tags_for( string $email ): array {
		$contact = Subscriber::where( 'email', $email )->first();
		return $contact ? $contact->tags->pluck( 'slug' )->toArray() : array();
	}

	/**
	 * Resolve slugs to ids, creating missing tags.
	 *
	 * @param string[] $slugs Tag slugs.
	 * @return int[]
	 */
	private function tag_ids( array $slugs ): array {
		$ids = array();
		foreach ( $slugs as $slug ) {
			$tag = Tag::where( 'slug', $slug )->first();
			if ( ! $tag ) {
				$tag = Tag::create(
					array(
						'title' => ucwords( str_replace( '-', ' ', $slug ) ),
						'slug'  => $slug,
					)
				);
			}
			$ids[] = (int) $tag->id;
		}
		return $ids;
	}
}
