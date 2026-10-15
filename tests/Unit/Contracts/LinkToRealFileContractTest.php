<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * IURLGenerator::linkTo() only emits the /apps/<appid>/ prefix when the
 * referenced file physically exists in the app directory; otherwise it
 * silently returns WEBROOT/<appid>/<file>, which routes nowhere (the 1.8.0
 * request-correction 404 regression). This contract forbids linkTo() literals
 * that do not point at a real file in this app's tree.
 */
class LinkToRealFileContractTest extends TestCase
{
	public function testLinkToLiteralsReferenceRealAppFiles(): void
	{
		$appRoot = dirname(__DIR__, 3);
		$offenders = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($appRoot, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			$path = (string)$file;
			if ($file->getExtension() !== 'php') {
				continue;
			}
			if (str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
				continue;
			}
			$src = (string)file_get_contents($path);
			if (!preg_match_all("#linkTo\\(\\s*'arbeitszeitcheck'\\s*,\\s*'([^']+)'#", $src, $matches)) {
				continue;
			}
			foreach ($matches[1] as $rel) {
				if (!is_file($appRoot . '/' . $rel)) {
					$offenders[] = str_replace($appRoot . '/', '', $path) . ' → linkTo(\'' . $rel . '\')';
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"linkTo() only works for real files; use Support\\RouteUrlTemplate for route URLs:\n" . implode("\n", $offenders)
		);
	}
}
