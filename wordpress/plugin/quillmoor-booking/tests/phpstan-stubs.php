<?php
// Minimal shapes of the third-party APIs used here. FluentCRM ships no stubs.
namespace FluentCrm\App\Models {
	class Subscriber {
		public string $status = '';
		/** @var \FluentCrm\App\Services\Collection */
		public $tags;
		/** @param mixed ...$args */
		public static function where( ...$args ): self { return new self(); }
		/** @param int[] $ids */
		public static function filterByTags( array $ids ): self { return new self(); }
		public function first(): ?self { return null; }
		public function pluck( string $key ): \FluentCrm\App\Services\Collection { return new \FluentCrm\App\Services\Collection(); }
		public function save(): bool { return true; }
		/** @param int[] $ids */
		public function attachTags( array $ids ): self { return $this; }
		/** @param int[] $ids */
		public function detachTags( array $ids ): self { return $this; }
	}
	class Tag {
		public int $id = 0;
		/** @param mixed ...$args */
		public static function where( ...$args ): self { return new self(); }
		public function first(): ?self { return null; }
		/** @param array<string,string> $data */
		public static function create( array $data ): self { return new self(); }
	}
}
namespace FluentCrm\App\Services {
	class Collection {
		public function pluck( string $key ): self { return $this; }
		/** @return array<int,mixed> */
		public function toArray(): array { return array(); }
	}
	class Helper {
		/** @param int[] $ids */
		public static function deleteContacts( array $ids ): void {}
	}
}
namespace {
	class WP_CLI {
		public static function add_command( string $name, string $callable ): void {}
		/** @param array<string,mixed> $assoc_args */
		public static function confirm( string $question, array $assoc_args = array() ): void {}
		public static function error( string $message ): void {}
		public static function success( string $message ): void {}
	}
	class QHS_Fluent_Contacts_Api {
		/** @param array<string,string> $data */
		public function createOrUpdate( array $data, bool $force = false ): \FluentCrm\App\Models\Subscriber|false { return false; }
	}
	function FluentCrmApi( string $name ): QHS_Fluent_Contacts_Api { return new QHS_Fluent_Contacts_Api(); }
}
