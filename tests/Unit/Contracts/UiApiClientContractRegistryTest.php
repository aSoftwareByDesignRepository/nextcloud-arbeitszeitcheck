<?php

declare(strict_types=1);

/**
 * Pins the UI↔API client contract registry and in-app needles for this app.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

final class UiApiClientContractRegistryTest extends TestCase
{
	private string $appRoot;
	private string $workspaceRoot;

	protected function setUp(): void
	{
		parent::setUp();
		$this->appRoot = dirname(__DIR__, 3);
		// Host monorepo: …/nextcloud/apps/arbeitszeitcheck → three levels up is workspace.
		$this->workspaceRoot = dirname($this->appRoot, 3);
	}

	public function testContractRegistryExistsAndListsManualJustification(): void
	{
		$path = $this->appRoot . '/tests/contracts/ui-api-client.json';
		$this->assertFileExists($path);
		$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(1, $data['schemaVersion']);
		$this->assertSame('arbeitszeitcheck', $data['appId']);
		$ids = array_column($data['contracts'], 'id');
		$this->assertContains('manual-time-entry-justification', $ids);
		$this->assertContains('time-entry-correction-justification', $ids);
	}

	public function testServerAndWebNeedlesPresentInAppTree(): void
	{
		$path = $this->appRoot . '/tests/contracts/ui-api-client.json';
		$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		foreach ($data['contracts'] as $contract) {
			foreach (['server', 'web'] as $surface) {
				foreach ($contract[$surface] ?? [] as $entry) {
					$file = $this->appRoot . '/' . ltrim((string)$entry['file'], '/');
					$this->assertFileExists($file, $contract['id'] . ' missing ' . $entry['file']);
					$text = (string)file_get_contents($file);
					foreach ($entry['needles'] as $needle) {
						$this->assertStringContainsString(
							$needle,
							$text,
							$contract['id'] . ' / ' . $entry['file'] . ' missing needle: ' . $needle
						);
					}
				}
			}
		}
	}

	public function testWorkspaceContractCheckerPassesForThisApp(): void
	{
		$script = $this->workspaceRoot . '/scripts/check-ui-api-client-contracts.py';
		if (!is_file($script)) {
			$this->markTestSkipped('Workspace contract checker not present (Docker/standalone checkout).');
		}
		$python = trim((string)shell_exec('command -v python3 2>/dev/null'));
		if ($python === '') {
			$this->markTestSkipped('python3 not available');
		}
		$cmd = escapeshellarg($python) . ' ' . escapeshellarg($script)
			. ' --app arbeitszeitcheck --heuristic 2>&1';
		exec($cmd, $out, $code);
		$joined = implode("\n", $out);
		$this->assertSame(0, $code, $joined);
		$this->assertStringContainsString('OK', $joined);
	}
}
