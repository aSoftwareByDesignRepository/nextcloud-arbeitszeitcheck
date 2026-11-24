<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Exception;

/**
 * Validation or business-rule failure while updating an employee profile.
 * Mapped to JSON by {@see \OCA\ArbeitszeitCheck\Controller\AdminController}.
 */
class AdminUserProfileUpdateException extends \RuntimeException
{
	/**
	 * @param array<string, string> $fieldErrors
	 */
	/**
	 * @param array<string, mixed> $details extra wire payload (e.g. colliding rows for ASSIGNMENT_OVERLAP)
	 */
	public function __construct(
		public readonly string $userMessage,
		public readonly int $httpStatus = 400,
		public readonly array $fieldErrors = [],
		public readonly ?string $errorCode = null,
		public readonly array $details = [],
	) {
		parent::__construct($userMessage);
	}
}
