<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Migration;

use OCA\ArbeitszeitCheck\Migration\Version1046Date20260921120000;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class Version1046BackfillBudgetContractTest extends TestCase
{
	public function testPostSchemaChangeHasTimeBudgetConstant(): void
	{
		$ref = new ReflectionClass(Version1046Date20260921120000::class);
		$this->assertTrue($ref->hasConstant('BACKFILL_MAX_SECONDS'));
		$this->assertLessThanOrEqual(30, $ref->getConstant('BACKFILL_MAX_SECONDS'));
		$src = (string)file_get_contents($ref->getFileName());
		$this->assertStringContainsString('BACKFILL_MAX_SECONDS', $src);
		$this->assertStringContainsString('deferred', $src);
	}
}
