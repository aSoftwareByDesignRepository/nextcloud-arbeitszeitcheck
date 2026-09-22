<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\PeopleSearchQuery;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class PeopleSearchQueryTest extends TestCase
{
	public function testPrefersRouteArgThenSearchThenQ(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([
			['search', null, 'from-search'],
			['q', null, 'from-q'],
		]);

		$this->assertSame('from-route', PeopleSearchQuery::fromRequest($request, 'from-route'));
		$this->assertSame('from-search', PeopleSearchQuery::fromRequest($request, '  '));
		$this->assertSame('from-search', PeopleSearchQuery::fromRequest($request, null));
	}

	public function testFallsBackToQWhenSearchEmpty(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static function (string $key) {
			return $key === 'q' ? 'legacy-q' : '';
		});

		$this->assertSame('legacy-q', PeopleSearchQuery::fromRequest($request));
	}

	public function testClamp(): void
	{
		$this->assertSame('ab', PeopleSearchQuery::clamp('abcdef', 2));
		$this->assertSame('ab', PeopleSearchQuery::clamp('ab', 10));
	}
}
