<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Suite legacy isolation (CHECK-SUITE L1): ArbeitszeitCheck must remain usable
 * without CustomerCheck / the invoicing app / InventoryCheck / MaintenanceCheck.
 *
 * @see planning/check-productivity-suite/LEGACY-SAFETY.md
 */
final class SuiteLegacyIsolationContractTest extends TestCase
{
	private const FORBIDDEN_HARD_DEPS = [
		'customercheck',
		'invoicecheck',
		'inventorycheck',
		'maintenancecheck',
	];

	private string $infoXml;

	protected function setUp(): void
	{
		parent::setUp();
		$path = dirname(__DIR__, 2) . '/appinfo/info.xml';
		$this->assertFileExists($path);
		$this->infoXml = (string)file_get_contents($path);
		$this->assertNotSame('', trim($this->infoXml), 'info.xml must not be empty');
	}

	public function testInfoXmlDeclaresArbeitszeitcheckId(): void
	{
		$this->assertMatchesRegularExpression('/<id>\s*arbeitszeitcheck\s*<\/id>/', $this->infoXml);
	}

	public function testHardDependenciesDoNotRequireSuiteSpineApps(): void
	{
		$hardBlock = $this->dependenciesInnerXml('dependencies');
		foreach (self::FORBIDDEN_HARD_DEPS as $appId) {
			$this->assertDoesNotMatchRegularExpression(
				'/<app\b[^>]*>\s*' . preg_quote($appId, '/') . '\s*<\/app>/i',
				$hardBlock,
				"Hard <dependencies> must not require {$appId} (suite L1 / legacy isolation)"
			);
		}
	}

	public function testOptionalProjectcheckIsNotAHardDependency(): void
	{
		$hardBlock = $this->dependenciesInnerXml('dependencies');
		$this->assertDoesNotMatchRegularExpression(
			'/<app\b[^>]*>\s*projectcheck\s*<\/app>/i',
			$hardBlock,
			'projectcheck may be optional only — never a hard dependency'
		);
		$optional = $this->dependenciesInnerXml('optional-dependencies');
		$this->assertMatchesRegularExpression(
			'/<app\b[^>]*>\s*projectcheck\s*<\/app>/i',
			$optional,
			'optional-dependencies should keep projectcheck soft-integration discoverable (compose when present)'
		);
	}

	public function testComposabilityDoctrineStandaloneAndOptionalPeer(): void
	{
		// D21: AZC alone is valid; optional PC enables compose without forcing install.
		$hardBlock = $this->dependenciesInnerXml('dependencies');
		$this->assertDoesNotMatchRegularExpression(
			'/<app\b/i',
			$hardBlock,
			'AZC hard dependencies must not list Check sibling apps'
		);
		$optional = $this->dependenciesInnerXml('optional-dependencies');
		$this->assertNotSame('', trim($optional), 'optional-dependencies block required for composability discovery');
		$this->assertMatchesRegularExpression(
			'/<app\b[^>]*>\s*projectcheck\s*<\/app>/i',
			$optional,
			'optional projectcheck unlocks compose-when-present (stronger together)'
		);
	}

	public function testPhpSourcesDoNotStaticallyUseForbiddenSuiteNamespaces(): void
	{
		$root = dirname(__DIR__, 2) . '/lib';
		$hits = $this->scanPhpForForbiddenUse($root);
		$this->assertSame(
			[],
			$hits,
			"Static use of suite spine namespaces is forbidden without a capability probe layer:\n" . implode("\n", $hits)
		);
	}

	/**
	 * @return list<string>
	 */
	private function scanPhpForForbiddenUse(string $root): array
	{
		$forbidden = [
			'OCA\\CustomerCheck\\',
			'OCA\\InvoiceCheck\\',
			'OCA\\InventoryCheck\\',
			'OCA\\MaintenanceCheck\\',
		];
		$hits = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
		);
		/** @var \SplFileInfo $file */
		foreach ($iterator as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}
			$contents = (string)file_get_contents($file->getPathname());
			foreach ($forbidden as $ns) {
				if (str_contains($contents, 'use ' . $ns) || str_contains($contents, 'new ' . $ns)) {
					$hits[] = $file->getPathname() . ' → ' . $ns;
				}
			}
		}
		return $hits;
	}

	private function dependenciesInnerXml(string $tag): string
	{
		if (!preg_match(
			'/' . preg_quote('<' . $tag . '>', '/') . '(.*?)' . preg_quote('</' . $tag . '>', '/') . '/is',
			$this->infoXml,
			$m
		)) {
			return '';
		}
		return (string)$m[1];
	}
}
