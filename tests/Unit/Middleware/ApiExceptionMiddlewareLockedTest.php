<?php

declare(strict_types=1);

/**
 * A contended mutation lock must surface as a retryable client-visible
 * state, never as a 500: concurrent creates/end-operations on work-location
 * policies (and every other locked API mutation) lose the race with
 * LockedException, which previously fell through to `internal_error`.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Middleware;

use OCA\ArbeitszeitCheck\Middleware\ApiExceptionMiddleware;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ApiExceptionMiddlewareLockedTest extends TestCase
{
	private function middleware(string $pathInfo): ApiExceptionMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($pathInfo);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return new ApiExceptionMiddleware($request, $this->createMock(LoggerInterface::class), $l10n);
	}

	public function testLockedExceptionOnApiPathMapsTo423(): void
	{
		$res = $this->middleware('/apps/arbeitszeitcheck/api/admin/work-location-policies')
			->afterException(null, 'createPolicy', new LockedException('azc-work-location-policy'));

		$this->assertInstanceOf(JSONResponse::class, $res);
		$this->assertSame(Http::STATUS_LOCKED, $res->getStatus());
		$data = $res->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('locked', $data['error_code']);
		$this->assertNotSame('', $data['error']);
	}

	public function testLockedExceptionOnNonApiPathRethrows(): void
	{
		$this->expectException(LockedException::class);
		$this->middleware('/apps/arbeitszeitcheck/dashboard')
			->afterException(null, 'index', new LockedException('x'));
	}

	public function testGenericExceptionStillMapsToInternalError(): void
	{
		$res = $this->middleware('/apps/arbeitszeitcheck/api/admin/work-location-policies')
			->afterException(null, 'createPolicy', new \RuntimeException('boom'));

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $res->getStatus());
		$data = $res->getData();
		$this->assertSame('internal_error', $data['error_code']);
		$this->assertStringNotContainsString('boom', (string)$data['error']);
	}
}
