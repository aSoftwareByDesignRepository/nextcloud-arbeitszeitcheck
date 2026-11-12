<?php

declare(strict_types=1);

/**
 * Per-app transfer manifest contract.
 *
 * The engine (TransferService/Crypto/Format) is pack-synced and exercised by
 * the reference app's TransferPackTest + ConfigTransferIntegrationTest.
 * What can NOT be covered there is THIS app's declaration: a manifest that
 * parses, self-validates, and never wire-classes a secret-looking key name.
 * The manifest gate (scripts/check-config-transfer-manifest.py) enforces the
 * same policy from CI; this test keeps it inside the app's own phpunit run.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\ConfigTransfer;

use OCA\ArbeitszeitCheck\ConfigTransfer\AbstractTransferManifest;
use OCA\ArbeitszeitCheck\ConfigTransfer\TransferManifest;
use PHPUnit\Framework\TestCase;

final class TransferManifestContractTest extends TestCase
{
	/**
	 * Strong secret-bearing name signals. Deliberately narrower than the
	 * gate's WARN pattern (no bare 'token'/'private'): a FAIL here means a
	 * credential-shaped name rides a plaintext wire class, not a judgment
	 * call. Mirrors SECRET_NAME_RE/WIRE_CLASSES in the manifest gate.
	 */
	private const SECRET_NAME_RE = '/secret|salt|passw|credential|hmac|api[_-]?key|private_key|signing|_p8\b|_pem\b/i';
	private const WIRE_CLASSES = ['plain', 'uid-ref', 'gid-ref', 'json-refs', 'mirrored'];

	public function testManifestSelfValidates(): void
	{
		$manifest = new TransferManifest();
		$this->assertSame([], $manifest->validate(), implode('; ', $manifest->validate()));
	}

	public function testSchemaVersionPositive(): void
	{
		$this->assertGreaterThan(0, (new TransferManifest())->schemaVersion());
	}

	public function testManifestDeclaresKeys(): void
	{
		$this->assertNotEmpty((new TransferManifest())->keys());
	}

	public function testSecretLookingNamesAreNotWireClassed(): void
	{
		$manifest = new TransferManifest();
		$violations = [];
		foreach ($manifest->keys() as $key => $_spec) {
			$class = $manifest->keySpec($key)['class'];
			if (in_array($class, self::WIRE_CLASSES, true)
				&& preg_match(self::SECRET_NAME_RE, (string)$key) === 1) {
				$violations[] = "{$key} ({$class})";
			}
		}
		$this->assertSame(
			[],
			$violations,
			'secret-bearing key names in wire classes — class them secret/env/internal or rename'
		);
	}

	public function testWireClassesAreTransferMeaningful(): void
	{
		$manifest = new TransferManifest();
		foreach ($manifest->keys() as $key => $_spec) {
			$this->assertContains(
				$manifest->keySpec($key)['class'],
				AbstractTransferManifest::KEY_CLASSES,
				"key '{$key}' has an unknown class"
			);
		}
		foreach ($manifest->tables() as $table => $_spec) {
			$this->assertContains(
				$manifest->tableSpec($table)['class'],
				AbstractTransferManifest::TABLE_CLASSES,
				"table '{$table}' has an unknown class"
			);
		}
	}
}
