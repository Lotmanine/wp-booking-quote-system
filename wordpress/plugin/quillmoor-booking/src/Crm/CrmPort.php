<?php
/**
 * CRM contract.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Crm;

defined( 'ABSPATH' ) || exit;

/**
 * The operations the pipeline needs from a CRM.
 */
interface CrmPort {

	/**
	 * Create or update a contact and set its tags.
	 *
	 * @param string   $email        Address.
	 * @param string   $full_name    Name.
	 * @param bool     $consent      Marketing consent.
	 * @param string[] $add_tags     Tag slugs to add.
	 * @param string[] $remove_tags  Tag slugs to remove.
	 * @return bool
	 */
	public function sync_contact( string $email, string $full_name, bool $consent, array $add_tags, array $remove_tags ): bool;

	/**
	 * Tag slugs on a contact.
	 *
	 * @param string $email Address.
	 * @return string[]
	 */
	public function tags_for( string $email ): array;
}
