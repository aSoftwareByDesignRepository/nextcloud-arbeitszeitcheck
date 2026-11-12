<?php

declare(strict_types=1);

/**
 * Config & data transfer — typed failure codes.
 *
 * Every refusal/failure the transfer pipeline can produce carries a stable
 * code so the API envelope, the occ exit path, and the audit trail all speak
 * the same vocabulary. Codes prefixed TRANSFER_FILE_* are pre-decrypt and are
 * surfaced to the caller as one uniform "cannot read this file" error — never
 * as distinct oracles (config-transfer spec §10 FS-9 / AC-4c).
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\ConfigTransfer;

use OCP\AppFramework\Http;

class TransferException extends \RuntimeException
{
	/** Uniform pre-decrypt failure codes — same HTTP status + same wording bucket. */
	public const FILE_UNREADABLE = [
		'TRANSFER_FILE_INVALID',
		'TRANSFER_FILE_WRONG_APP',
		'TRANSFER_CANNOT_DECRYPT',
		'TRANSFER_FILE_TOO_LARGE',
		'TRANSFER_INNER_TOO_LARGE',
		'TRANSFER_PAYLOAD_INVALID',
		'TRANSFER_CHECKSUM_MISMATCH',
	];

	public const CODES = [
		// envelope / crypto (uniform 422 family)
		'TRANSFER_FILE_INVALID' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_FILE_WRONG_APP' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_CANNOT_DECRYPT' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_FILE_TOO_LARGE' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_INNER_TOO_LARGE' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_PAYLOAD_INVALID' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_CHECKSUM_MISMATCH' => Http::STATUS_UNPROCESSABLE_ENTITY,
		// semantic / permission refusals
		'TRANSFER_NEWER_VERSION' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_VALUE_INVALID' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_SCHEMA_INCOMPATIBLE' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_PENDING_NOT_FOUND' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_PENDING_EXPIRED' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_CONFIRM_REQUIRED' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_MAINTENANCE_REQUIRED' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_ADMIN_REQUIRED' => Http::STATUS_FORBIDDEN,
		'TRANSFER_IN_PROGRESS' => Http::STATUS_CONFLICT,
		'TRANSFER_BACKUP_IN_PROGRESS' => Http::STATUS_CONFLICT,
		'TRANSFER_THROTTLED' => Http::STATUS_TOO_MANY_REQUESTS,
		'TRANSFER_PREFLIGHT_FAILED' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_PASSPHRASE_WEAK' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'TRANSFER_APPLY_FAILED' => Http::STATUS_INTERNAL_SERVER_ERROR,
		'TRANSFER_NO_MANIFEST' => Http::STATUS_INTERNAL_SERVER_ERROR,
		'TRANSFER_SODIUM_MISSING' => Http::STATUS_INTERNAL_SERVER_ERROR,
	];

	/** @param array<string,mixed> $context */
	public function __construct(
		string $code,
		private array $context = [],
		?\Throwable $previous = null,
	) {
		if (!array_key_exists($code, self::CODES)) {
			$code = 'TRANSFER_APPLY_FAILED';
		}
		parent::__construct($code, self::CODES[$code], $previous);
	}

	/** True when the code is a pre-decrypt failure → uniform outward message. */
	public function isFileError(): bool
	{
		return in_array($this->getMessage(), self::FILE_UNREADABLE, true);
	}

	/** @return array<string,mixed> */
	public function getContext(): array
	{
		return $this->context;
	}

	public function getHttpStatus(): int
	{
		return self::CODES[$this->getMessage()] ?? Http::STATUS_INTERNAL_SERVER_ERROR;
	}
}
