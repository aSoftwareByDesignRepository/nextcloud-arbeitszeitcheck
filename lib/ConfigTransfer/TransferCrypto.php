<?php

declare(strict_types=1);

/**
 * Crypto for `checkapp-config-transfer/2` containers.
 *
 *  - Export/import: Argon2id KDF → XChaCha20-Poly1305 AEAD over a deflated
 *    JSON payload. The AEAD additional data is the canonical outer header so
 *    header tampering fails decryption like a wrong passphrase.
 *  - Pending blobs: the same payload re-wrapped server-side with a key
 *    derived from the instance `secret` so a queued background apply can
 *    decrypt it without ever storing the admin's passphrase.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\ConfigTransfer;

final class TransferCrypto
{
	public const KDF_ALGO = 'argon2id';
	public const CIPHER_ALGO = 'xchacha20-poly1305';

	// KDF bounds accepted at import. Export always uses DEFAULTS; imports
	// refuse params outside these bounds so a crafted file cannot force a
	// multi-GB allocation (resource-exhaustion vector).
	public const KDF_OPSLIMIT = 3;
	public const KDF_MEMLIMIT = 67108864; // 64 MiB
	public const KDF_OPSLIMIT_MAX = 10;
	public const KDF_MEMLIMIT_MAX = 268435456; // 256 MiB

	public const MIN_PASSPHRASE_LENGTH = 12;

	public static function sodiumAvailable(): bool
	{
		return extension_loaded('sodium')
			&& function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
			&& defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13');
	}

	public static function requireSodium(): void
	{
		if (!self::sodiumAvailable()) {
			throw new TransferException('TRANSFER_SODIUM_MISSING');
		}
	}

	public static function assertPassphraseStrength(string $passphrase): void
	{
		if (mb_strlen($passphrase) < self::MIN_PASSPHRASE_LENGTH) {
			throw new TransferException('TRANSFER_PASSPHRASE_WEAK', [
				'minLength' => self::MIN_PASSPHRASE_LENGTH,
			]);
		}
	}

	/**
	 * Derive the AEAD key from a passphrase + KDF parameter set.
	 *
	 * @param array{algo:string,opslimit:int,memlimit:int,salt:string} $kdf
	 */
	public static function deriveKey(string $passphrase, array $kdf): string
	{
		self::requireSodium();
		if (($kdf['algo'] ?? '') !== self::KDF_ALGO) {
			throw new TransferException('TRANSFER_FILE_INVALID');
		}
		$ops = (int)($kdf['opslimit'] ?? 0);
		$mem = (int)($kdf['memlimit'] ?? 0);
		if ($ops < 1 || $ops > self::KDF_OPSLIMIT_MAX
			|| $mem < (1 << 16) || $mem > self::KDF_MEMLIMIT_MAX) {
			throw new TransferException('TRANSFER_FILE_INVALID');
		}
		$salt = base64_decode((string)($kdf['salt'] ?? ''), true);
		if ($salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) {
			throw new TransferException('TRANSFER_FILE_INVALID');
		}
		return sodium_crypto_pwhash(
			SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
			$passphrase,
			$salt,
			$ops,
			$mem,
			SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
		);
	}

	/**
	 * Encrypt $plaintext with a passphrase.
	 *
	 * @return array{kdf:array{algo:string,opslimit:int,memlimit:int,salt:string},cipher:array{algo:string,nonce:string},payload_b64:string}
	 */
	public static function seal(string $plaintext, string $passphrase, string $aad): array
	{
		self::requireSodium();
		$kdf = [
			'algo' => self::KDF_ALGO,
			'opslimit' => self::KDF_OPSLIMIT,
			'memlimit' => self::KDF_MEMLIMIT,
			'salt' => base64_encode(random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES)),
		];
		$key = self::deriveKey($passphrase, $kdf);
		$nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
		$sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);
		sodium_memzero($key);
		return [
			'kdf' => $kdf,
			'cipher' => [
				'algo' => self::CIPHER_ALGO,
				'nonce' => base64_encode($nonce),
			],
			'payload_b64' => base64_encode($sealed),
		];
	}

	/**
	 * Decrypt a sealed payload. EVERY failure (bad params, tampered header,
	 * wrong passphrase, truncated ciphertext) is the identical
	 * TRANSFER_CANNOT_DECRYPT — never a distinct oracle.
	 *
	 * @param array{kdf:array,cipher:array,payload_b64:string} $sealed
	 */
	public static function open(array $sealed, string $passphrase, string $aad): string
	{
		self::requireSodium();
		try {
			if (($sealed['cipher']['algo'] ?? '') !== self::CIPHER_ALGO) {
				throw new TransferException('TRANSFER_CANNOT_DECRYPT');
			}
			$key = self::deriveKey($passphrase, $sealed['kdf'] ?? []);
			$nonce = base64_decode((string)($sealed['cipher']['nonce'] ?? ''), true);
			$ciphertext = base64_decode((string)($sealed['payload_b64'] ?? ''), true);
			if ($nonce === false || $ciphertext === false
				|| strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
				sodium_memzero($key);
				throw new TransferException('TRANSFER_CANNOT_DECRYPT');
			}
			$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $key);
			sodium_memzero($key);
			if ($plain === false) {
				throw new TransferException('TRANSFER_CANNOT_DECRYPT');
			}
			return $plain;
		} catch (TransferException $e) {
			// Any decrypt-domain failure collapses into the ONE uniform code —
			// kdf-param validation lives inside the try precisely so a crafted
			// file cannot be told apart from a wrong passphrase (spec AC-4c).
			if ($e->getMessage() === 'TRANSFER_SODIUM_MISSING') {
				throw $e;
			}
			throw new TransferException('TRANSFER_CANNOT_DECRYPT', [], $e);
		} catch (\Throwable $e) {
			throw new TransferException('TRANSFER_CANNOT_DECRYPT', [], $e);
		}
	}

	/**
	 * Server-side wrapping key for pending blobs: derived from the instance
	 * `secret` so the passphrase is never persisted while the payload at rest
	 * stays ciphertext. Returns raw 32-byte key.
	 */
	public static function instanceWrapKey(string $instanceSecret, string $appId): string
	{
		if ($instanceSecret === '') {
			// Refuse rather than store a payload unprotected.
			throw new TransferException('TRANSFER_SODIUM_MISSING');
		}
		return hash_hkdf('sha256', $instanceSecret, 32, 'config-transfer-pending|' . $appId);
	}

	/**
	 * @return string base64 blob: nonce || ciphertext
	 */
	public static function serverWrap(string $plaintext, string $wrapKey): string
	{
		self::requireSodium();
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$sealed = sodium_crypto_secretbox($plaintext, $nonce, $wrapKey);
		return base64_encode($nonce . $sealed);
	}

	public static function serverUnwrap(string $blobB64, string $wrapKey): string
	{
		self::requireSodium();
		$blob = base64_decode($blobB64, true);
		$plain = false;
		if ($blob !== false && strlen($blob) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			$plain = sodium_crypto_secretbox_open(
				substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
				substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
				$wrapKey,
			);
		}
		if ($plain === false) {
			throw new TransferException('TRANSFER_PENDING_NOT_FOUND');
		}
		return $plain;
	}
}
