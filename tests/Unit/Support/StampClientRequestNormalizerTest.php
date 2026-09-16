<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Support\StampClientRequestNormalizer;
use PHPUnit\Framework\TestCase;

class StampClientRequestNormalizerTest extends TestCase
{
	public function testAcceptsUuid(): void
	{
		$id = '550e8400-e29b-41d4-a716-446655440000';
		self::assertSame($id, StampClientRequestNormalizer::normalize($id));
	}

	public function testNullForEmpty(): void
	{
		self::assertNull(StampClientRequestNormalizer::normalize(''));
		self::assertNull(StampClientRequestNormalizer::normalize(null));
	}

	public function testRejectsInvalidChars(): void
	{
		$this->expectException(StampReplayException::class);
		StampClientRequestNormalizer::normalize('bad id with spaces');
	}
}
