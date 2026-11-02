<?php
/**
 * No-op CRM.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Crm;

defined( 'ABSPATH' ) || exit;

/**
 * Used when FluentCRM is not active.
 */
final class NullCrm implements CrmPort {

	/**
	 * Do nothing.
	 *
	 * @param string   $email       Address.
	 * @param string   $full_name   Name.
	 * @param bool     $consent     Consent.
	 * @param string[] $add_tags    Tags to add.
	 * @param string[] $remove_tags Tags to remove.
	 */
	public function sync_contact( string $email, string $full_name, bool $consent, array $add_tags, array $remove_tags ): bool {
		return false;
	}

	/**
	 * No tags.
	 *
	 * @param string $email Address.
	 * @return string[]
	 */
	public function tags_for( string $email ): array {
		return array();
	}
}
