<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Middleware;

use OCA\ArbeitszeitCheck\Middleware\ApiValidationErrorMiddleware;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\InvalidEnumParameterException;
use OCP\AppFramework\Http\InvalidStringParameterException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\ParameterOutOfRangeException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class ApiValidationErrorMiddlewareTest extends TestCase
{
	private function makeRequest(string $pathInfo): IRequest
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($pathInfo);
		return $request;
	}

	public function testOutOfRangeOnApiPathReturnsJsonEnvelope(): void
	{
		$mw = new ApiValidationErrorMiddleware(
			$this->makeRequest('/apps/arbeitszeitcheck/api/time-entries')
		);
		$ex = new ParameterOutOfRangeException('limit', 501, 1, 500);

		$res = $mw->afterException(null, 'apiIndex', $ex);

		$this->assertInstanceOf(JSONResponse::class, $res);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$data = $res->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('invalid_parameter', $data['error_code']);
		$this->assertArrayHasKey('limit', $data['fields']);
		$this->assertNotSame('', $data['error']);
	}

	public function testInvalidStringConstraintOnApiPathReturnsJsonEnvelope(): void
	{
		$mw = new ApiValidationErrorMiddleware(
			$this->makeRequest('/apps/arbeitszeitcheck/api/absences')
		);
		$ex = new InvalidStringParameterException('status', 'non-empty-string');

		$res = $mw->afterException(null, 'index', $ex);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertArrayHasKey('status', $res->getData()['fields']);
	}

	public function testInvalidEnumOnApiPathReturnsJsonEnvelope(): void
	{
		$mw = new ApiValidationErrorMiddleware(
			$this->makeRequest('/apps/arbeitszeitcheck/api/time-entries')
		);
		$ex = new InvalidEnumParameterException('status', 'bogus', \BackedEnum::class);

		$res = $mw->afterException(null, 'index', $ex);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertArrayHasKey('status', $res->getData()['fields']);
	}

	public function testNonApiPathRethrows(): void
	{
		$mw = new ApiValidationErrorMiddleware(
			$this->makeRequest('/apps/arbeitszeitcheck/time-entries')
		);
		$ex = new ParameterOutOfRangeException('limit', 501, 1, 500);

		$this->expectException(ParameterOutOfRangeException::class);
		$mw->afterException(null, 'index', $ex);
	}

	public function testUnrelatedExceptionRethrowsOnApiPath(): void
	{
		$mw = new ApiValidationErrorMiddleware(
			$this->makeRequest('/apps/arbeitszeitcheck/api/time-entries')
		);

		$this->expectException(\RuntimeException::class);
		$mw->afterException(null, 'index', new \RuntimeException('boom'));
	}
}
