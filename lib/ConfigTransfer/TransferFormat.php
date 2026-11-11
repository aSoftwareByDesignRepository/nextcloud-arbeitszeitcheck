<?php

declare(strict_types=1);

/**
 * `checkapp-config-transfer/2` container format: envelope build/parse,
 * canonicalisation, checksums, and bounded payload (de)compression.
 *
 * Outer envelope (plaintext, machine-readable before decrypt):
 *   format / app / app_version / schema_version / exported_at /
 *   kdf / cipher / payload_b64
 * Inner payload (ciphertext → deflate → JSON):
 *   checksum / config / references / tables / appdata /
 *   source_instance / source_url
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\ConfigTransfer;

final class TransferFormat
{
	public const FORMAT = 'checkapp-config-transfer/2';

	public const REDACTED_MARKER = ['__redacted__' => true];

	// Hard bounds — enforced before any parsing so crafted files cannot
	// exhaust memory. The UI upload cap is lower (see TransferService).
	public const MAX_FILE_BYTES = 536870912;   // 512 MiB absolute ceiling
	public const MAX_INNER_BYTES = 268435456;  // 256 MiB decompressed payload
	public const MAX_JSON_DEPTH = 64;
	public const MAX_TABLE_ROWS = 250000;
	public const MAX_APPDATA_BYTES = 52428800; // 50 MiB appdata budget ceiling

	private const ENVELOPE_FIELDS = [
		'format', 'app', 'app_version', 'schema_version', 'exported_at',
		'source_instance', 'kdf', 'cipher', 'payload_b64',
	];

	private const PAYLOAD_FIELDS = [
		'checksum', 'config', 'references', 'tables', 'appdata',
		'source_instance', 'source_url', 'secrets_included',
	];

	/**
	 * Deterministic JSON encoding: objects sorted by key, no whitespace.
	 * Used for the integrity checksum so both sides hash identical bytes.
	 */
	public static function canonicalize(mixed $value): string
	{
		return json_encode(self::sortKeysRecursive($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	private static function sortKeysRecursive(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}
		if (array_is_list($value)) {
			return array_map(self::sortKeysRecursive(...), $value);
		}
		ksort($value);
		foreach ($value as $k => $v) {
			$value[$k] = self::sortKeysRecursive($v);
		}
		return $value;
	}

	/**
	 * sha256 checksum over the canonicalised payload minus the checksum field.
	 * Corruption detection only — authenticity comes from the AEAD cipher.
	 *
	 * @param array<string,mixed> $payload
	 */
	public static function checksum(array $payload): string
	{
		$copy = $payload;
		unset($copy['checksum']);
		return 'sha256:' . hash('sha256', self::canonicalize($copy));
	}

	/**
	 * Parse + structurally validate the outer envelope WITHOUT decrypting.
	 * Every failure is a pre-decrypt TransferException (uniform outward error).
	 *
	 * @return array{header:array<string,mixed>,payload_b64:string,aad:string}
	 */
	public static function parseEnvelope(string $bytes, string $appId, int $knownSchemaVersion): array
	{
		if ($bytes === '' || strlen($bytes) > self::MAX_FILE_BYTES) {
			throw new TransferException('TRANSFER_FILE_TOO_LARGE');
		}
		try {
			$envelope = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			// Non-JSON bytes are just another unreadable file — they must
			// collapse into the same file-error class as a wrong passphrase
			// (uniform pre-decrypt wording, no parseability oracle).
			throw new TransferException('TRANSFER_FILE_INVALID', [], $e);
		}
		if (!is_array($envelope) || array_is_list($envelope)) {
			throw new TransferException('TRANSFER_FILE_INVALID');
		}
		foreach (array_keys($envelope) as $field) {
			if (!in_array($field, self::ENVELOPE_FIELDS, true)) {
				throw new TransferException('TRANSFER_FILE_INVALID');
			}
		}
		foreach (self::ENVELOPE_FIELDS as $field) {
			if ($field === 'source_instance') {
				continue; // optional metadata
			}
			if (!array_key_exists($field, $envelope)) {
				throw new TransferException('TRANSFER_FILE_INVALID');
			}
		}
		if (($envelope['format'] ?? '') !== self::FORMAT) {
			throw new TransferException('TRANSFER_FILE_INVALID');
		}
		if (($envelope['app'] ?? '') !== $appId) {
			throw new TransferException('TRANSFER_FILE_WRONG_APP', ['expected' => $appId]);
		}
		$schema = $envelope['schema_version'] ?? null;
		if (!is_int($schema) || $schema < 1) {
			throw new TransferException('TRANSFER_FILE_INVALID');
		}
		// D4 — hard refuse BEFORE decrypt/validation: the target cannot know
		// what a newer file version means.
		if ($schema > $knownSchemaVersion) {
			throw new TransferException('TRANSFER_NEWER_VERSION', [
				'file' => $schema,
				'known' => $knownSchemaVersion,
			]);
		}
		foreach (['kdf', 'cipher'] as $section) {
			if (!is_array($envelope[$section])) {
				throw new TransferException('TRANSFER_FILE_INVALID');
			}
		}
		if (!is_string($envelope['payload_b64']) || $envelope['payload_b64'] === '') {
			throw new TransferException('TRANSFER_FILE_INVALID');
		}

		return [
			'header' => $envelope,
			'payload_b64' => $envelope['payload_b64'],
			'aad' => self::aadFor($envelope),
		];
	}

	/**
	 * AEAD additional data: the canonical STATIC header fields (everything
	 * known before sealing). KDF/cipher params are deliberately not part of
	 * the AAD — they cannot exist before encryption — but tampering with any
	 * of them still fails decryption because the derived key/nonce no longer
	 * matches, and tampering with a static header field fails explicitly.
	 *
	 * @param array<string,mixed> $envelope
	 */
	public static function aadFor(array $envelope): string
	{
		$aad = [];
		foreach (['format', 'app', 'app_version', 'schema_version', 'exported_at', 'source_instance'] as $field) {
			if (array_key_exists($field, $envelope)) {
				$aad[$field] = $envelope[$field];
			}
		}
		return self::canonicalize($aad);
	}

	/**
	 * Deflate the JSON payload for sealing.
	 */
	public static function compress(string $json): string
	{
		$out = gzdeflate($json, 6);
		if ($out === false) {
			throw new TransferException('TRANSFER_APPLY_FAILED');
		}
		return $out;
	}

	/**
	 * Inflate with a hard byte cap (incremental — a "zip bomb" is refused at
	 * the cap instead of exhausting memory first).
	 */
	public static function decompressCapped(string $deflated, int $cap = self::MAX_INNER_BYTES): string
	{
		// gzdeflate() output is RAW deflate — ZLIB_ENCODING_DEFLATE expects
		// the zlib wrapper and fails with "data error" on raw streams.
		$ctx = inflate_init(ZLIB_ENCODING_RAW);
		if ($ctx === false) {
			throw new TransferException('TRANSFER_CANNOT_DECRYPT');
		}
		$out = '';
		$offset = 0;
		$len = strlen($deflated);
		while ($offset < $len) {
			$slice = substr($deflated, $offset, 65536);
			// NO_FLUSH while feeding, FINISH on the last slice — SYNC_FLUSH
			// mid-stream triggers "data error" on raw deflate input.
			$chunk = inflate_add($ctx, $slice, ($offset + 65536 >= $len) ? ZLIB_FINISH : ZLIB_NO_FLUSH);
			if ($chunk === false) {
				throw new TransferException('TRANSFER_CANNOT_DECRYPT');
			}
			$out .= $chunk;
			if (strlen($out) > $cap) {
				throw new TransferException('TRANSFER_INNER_TOO_LARGE');
			}
			$offset += 65536;
		}
		// A truncated deflate stream must not pass: the JSON inside would look
		// fine but the file was cut mid-write — treat like a corrupt container.
		if (inflate_get_status($ctx) !== ZLIB_STREAM_END) {
			throw new TransferException('TRANSFER_CANNOT_DECRYPT');
		}
		return $out;
	}

	/**
	 * Decode + structurally validate the decrypted JSON payload.
	 *
	 * @return array<string,mixed>
	 */
	public static function parsePayload(string $json): array
	{
		if ($json === '' || strlen($json) > self::MAX_INNER_BYTES) {
			throw new TransferException('TRANSFER_INNER_TOO_LARGE');
		}
		try {
			$payload = json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new TransferException('TRANSFER_PAYLOAD_INVALID', [], $e);
		}
		if (!is_array($payload) || array_is_list($payload)) {
			throw new TransferException('TRANSFER_PAYLOAD_INVALID');
		}
		foreach (array_keys($payload) as $field) {
			if (!in_array($field, self::PAYLOAD_FIELDS, true)) {
				throw new TransferException('TRANSFER_PAYLOAD_INVALID');
			}
		}
		foreach (['checksum', 'config'] as $required) {
			if (!array_key_exists($required, $payload)) {
				throw new TransferException('TRANSFER_PAYLOAD_INVALID');
			}
		}
		if (!is_string($payload['checksum']) || !str_starts_with($payload['checksum'], 'sha256:')) {
			throw new TransferException('TRANSFER_PAYLOAD_INVALID');
		}
		if (!is_array($payload['config'])) {
			throw new TransferException('TRANSFER_PAYLOAD_INVALID');
		}
		foreach (['references', 'tables'] as $mapField) {
			if (array_key_exists($mapField, $payload) && !is_array($payload[$mapField])) {
				throw new TransferException('TRANSFER_PAYLOAD_INVALID');
			}
		}
		if (!hash_equals(self::checksum($payload), $payload['checksum'])) {
			throw new TransferException('TRANSFER_CHECKSUM_MISMATCH');
		}
		return $payload;
	}

	/**
	 * Redacted-secret marker detection (config values AND table cells).
	 */
	public static function isRedacted(mixed $value): bool
	{
		return is_array($value) && ($value['__redacted__'] ?? null) === true
			&& count($value) === 1;
	}
}
