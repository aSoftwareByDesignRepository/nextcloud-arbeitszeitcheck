<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Every app page keeps a clear area under the last block.
 */
class PageEndInsetContractTest extends TestCase
{
	public function testShellBottomInsetIsFiveRem(): void
	{
		$tokens = (string)file_get_contents(dirname(__DIR__, 3) . '/css/common/tokens.css');
		$app = (string)file_get_contents(dirname(__DIR__, 3) . '/css/app.css');
		$patterns = (string)file_get_contents(dirname(__DIR__, 3) . '/css/common/page-patterns.css');

		$this->assertMatchesRegularExpression('/--azc-page-end:\s*5rem;/', $tokens);
		$this->assertMatchesRegularExpression(
			'/azc-shell::after\s*\{[^}]*height:\s*calc\(var\(--azc-page-end\) \+ env\(safe-area-inset-bottom, 0px\)\);/s',
			$app
		);
		$this->assertDoesNotMatchRegularExpression(
			'/azc-shell--minimal\s*\{[^}]*padding:\s*var\(--azc-space-6\)\s*var\(--azc-space-5\);/',
			$patterns
		);
	}
}
