<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Pins l10n parity / runtime / placeholder gauntlets so missing catalog keys
 * and printf drift fail in CI before English leaks ship.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
final class L10nCatalogGauntletContractTest extends TestCase
{
	private string $appRoot;

	protected function setUp(): void
	{
		parent::setUp();
		$this->appRoot = dirname(__DIR__, 3);
	}

	public function testRuntimeAllStringsPresentInEnCatalog(): void
	{
		[$code, $out] = $this->runScript('scripts/check-l10n-runtime.php', ['--all']);
		self::assertSame(0, $code, $out);
		self::assertStringContainsString('l10n runtime OK', $out);
	}

	public function testLocaleCatalogsMatchEnKeySetAndOrder(): void
	{
		[$code, $out] = $this->runScript('scripts/check-l10n-parity.php');
		self::assertSame(0, $code, $out);
		self::assertStringContainsString('l10n parity OK', $out);
	}

	public function testPlaceholdersMatchEnAcrossLocales(): void
	{
		[$code, $out] = $this->runScript('scripts/check-l10n-placeholders.php');
		self::assertSame(0, $code, $out);
		self::assertStringContainsString('l10n placeholder check OK', $out);
	}

	public function testCriticalMultipageMsgidsHaveNonIdentityGerman(): void
	{
		$en = json_decode((string)file_get_contents($this->appRoot . '/l10n/en.json'), true, 512, JSON_THROW_ON_ERROR);
		$de = json_decode((string)file_get_contents($this->appRoot . '/l10n/de.json'), true, 512, JSON_THROW_ON_ERROR);
		$enT = $en['translations'] ?? [];
		$deT = $de['translations'] ?? [];

		$critical = [
			'Access',
			'Data & privacy',
			'Please wait until the current save finishes.',
			'Cannot delete the default working time model. Set another model as default first.',
			'Open Support & us',
			'Settings topics',
			'Choose a topic',
			'Loading subscription links…',
			'Could not load subscription links. Please try again.',
			'Your subscription links',
			'Create subscription link',
			'Compliant - automatic %d min break',
			'Compliant - %d min break provided',
			'%1$d minutes break required (%2$s)',
			'%d minutes',
		];
		foreach ($critical as $msgid) {
			self::assertArrayHasKey($msgid, $enT, "en missing {$msgid}");
			self::assertArrayHasKey($msgid, $deT, "de missing {$msgid}");
			self::assertNotSame(
				$enT[$msgid],
				$deT[$msgid],
				"de must translate critical UI string: {$msgid}",
			);
		}
	}

	/**
	 * @param list<string> $args
	 * @return array{0:int,1:string}
	 */
	private function runScript(string $relative, array $args = []): array
	{
		$script = $this->appRoot . '/' . $relative;
		self::assertFileExists($script);
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
		foreach ($args as $arg) {
			$cmd .= ' ' . escapeshellarg($arg);
		}
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$proc = proc_open($cmd, $descriptors, $pipes, $this->appRoot);
		self::assertIsResource($proc);
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]) ?: '';
		$stderr = stream_get_contents($pipes[2]) ?: '';
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		return [$code, $stdout . $stderr];
	}
}
