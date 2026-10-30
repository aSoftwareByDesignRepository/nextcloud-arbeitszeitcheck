<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

/**
 * Class tripwire for unescaped_quote_php_template_parse_fatal (r21):
 * every PHP file under templates/ and lib/ must pass `php -l`. A single
 * unescaped quote in a same-quoted literal is a shipped parse fatal that
 * 500s the including route — file_get_contents-based template contract
 * tests cannot see it.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
final class PhpSyntaxGauntletContractTest extends TestCase
{
	private string $appRoot;

	protected function setUp(): void
	{
		parent::setUp();
		$this->appRoot = dirname(__DIR__, 3);
	}

	public function testTemplatesAndLibPassPhpLint(): void
	{
		$files = [];
		foreach (['templates', 'lib'] as $dir) {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($this->appRoot . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
			);
			foreach ($it as $f) {
				if ($f->isFile() && $f->getExtension() === 'php') {
					$files[] = $f->getPathname();
				}
			}
		}
		sort($files);
		self::assertNotEmpty($files);

		$failures = [];
		foreach (array_chunk($files, 25) as $chunk) {
			$cmd = escapeshellarg(PHP_BINARY) . ' -l '
				. implode(' ', array_map('escapeshellarg', $chunk));
			exec($cmd . ' 2>&1', $out, $code);
			if ($code !== 0) {
				$failures[] = implode("\n", $out);
			}
			$out = [];
		}

		self::assertSame(
			[],
			$failures,
			"php -l failures (" . count($files) . " files scanned):\n" . implode("\n---\n", $failures)
		);
	}
}
