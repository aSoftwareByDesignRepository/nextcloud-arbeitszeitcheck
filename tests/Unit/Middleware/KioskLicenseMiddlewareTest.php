<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Middleware;

use OCA\ArbeitszeitCheck\Middleware\KioskDisabledException;
use OCA\ArbeitszeitCheck\Middleware\KioskLicenseMiddleware;
use OCA\ArbeitszeitCheck\Middleware\KioskTerminalLicenseRequiredException;
use OCA\ArbeitszeitCheck\Middleware\KioskUnauthorizedException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskErrorMessages;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskSettingsService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskTerminalService;
use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class KioskLicenseMiddlewareTest extends TestCase
{
	private function l10nFactory(): IFactory
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return $factory;
	}

	private function errorMessages(): KioskErrorMessages
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new KioskErrorMessages($l10n);
	}

	public function testUnlicensedOrgGets402OnKioskAction(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/arbeitszeitcheck/api/kiosk/action');
		$request->method('getHeader')->willReturnCallback(function (string $name): string {
			return match (strtolower($name)) {
				'x-kiosk-terminal-id' => 'term-1',
				'x-kiosk-token' => 'secret',
				default => '',
			};
		});

		$settings = $this->createMock(KioskSettingsService::class);
		$settings->method('isKioskEnabled')->willReturn(true);

		$license = $this->createMock(LicenseService::class);
		$license->method('isTerminalPlanActive')->willReturn(false);

		$terminals = $this->createMock(KioskTerminalService::class);
		$terminals->expects($this->never())->method('validateTerminalToken');

		$middleware = new KioskLicenseMiddleware(
			$request,
			$settings,
			$license,
			$terminals,
			$this->l10nFactory(),
			$this->createMock(LoggerInterface::class),
			$this->errorMessages(),
		);

		$this->expectException(KioskTerminalLicenseRequiredException::class);
		$middleware->beforeController(new \stdClass(), 'action');
	}

	public function testPairPathSkipsTerminalLicenseCheck(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/arbeitszeitcheck/api/kiosk/pair');
		$request->method('getHeader')->willReturn('');

		$settings = $this->createMock(KioskSettingsService::class);
		$settings->method('isKioskEnabled')->willReturn(true);

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('isTerminalPlanActive');

		$middleware = new KioskLicenseMiddleware(
			$request,
			$settings,
			$license,
			$this->createMock(KioskTerminalService::class),
			$this->l10nFactory(),
			$this->createMock(LoggerInterface::class),
			$this->errorMessages(),
		);

		$middleware->beforeController(new \stdClass(), 'pair');
		$this->addToAssertionCount(1);
	}

	public function testAfterExceptionReturns402Json(): void
	{
		$middleware = new KioskLicenseMiddleware(
			$this->createMock(IRequest::class),
			$this->createMock(KioskSettingsService::class),
			$this->createMock(LicenseService::class),
			$this->createMock(KioskTerminalService::class),
			$this->l10nFactory(),
			$this->createMock(LoggerInterface::class),
			$this->errorMessages(),
		);

		$response = $middleware->afterException(
			new \stdClass(),
			'action',
			new KioskTerminalLicenseRequiredException(),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_PAYMENT_REQUIRED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('TERMINAL_LICENSE_REQUIRED', $data['code']);
		$this->assertSame('TERMINAL_LICENSE_REQUIRED', $data['error']);
		$this->assertSame('ArbeitszeitCheck Terminal is not licensed for this organisation.', $data['message']);
	}

	private function middlewareFor(IRequest $request, ?LoggerInterface $logger = null): KioskLicenseMiddleware
	{
		return new KioskLicenseMiddleware(
			$request,
			$this->createMock(KioskSettingsService::class),
			$this->createMock(LicenseService::class),
			$this->createMock(KioskTerminalService::class),
			$this->l10nFactory(),
			$logger ?? $this->createMock(LoggerInterface::class),
			$this->errorMessages(),
		);
	}

	public function testAfterExceptionMapsKioskDisabledTo404(): void
	{
		$response = $this->middlewareFor($this->createMock(IRequest::class))
			->afterException(new \stdClass(), 'action', new KioskDisabledException());

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('KIOSK_DISABLED', $response->getData()['code']);
	}

	public function testAfterExceptionMapsUnauthorizedTo401(): void
	{
		$response = $this->middlewareFor($this->createMock(IRequest::class))
			->afterException(new \stdClass(), 'action', new KioskUnauthorizedException());

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('KIOSK_TERMINAL_UNAUTHORIZED', $response->getData()['code']);
	}

	public function testAfterExceptionMapsKioskExceptionOnKioskPath(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/arbeitszeitcheck/api/kiosk/stamp');

		$response = $this->middlewareFor($request)
			->afterException(new \stdClass(), 'action', new KioskException('KIOSK_ALREADY_CLOCKED_IN'));

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('KIOSK_ALREADY_CLOCKED_IN', $response->getData()['error']);
	}

	public function testAfterExceptionMapsUnhandledKioskExceptionTo500AndLogs(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/arbeitszeitcheck/api/kiosk/stamp');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with(self::stringContains('Unhandled kiosk API exception'), self::anything());

		$response = $this->middlewareFor($request, $logger)
			->afterException(new \stdClass(), 'action', new \RuntimeException('boom'));

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('KIOSK_INTERNAL_ERROR', $response->getData()['error']);
	}

	public function testAfterExceptionRethrowsForNonKioskPath(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/arbeitszeitcheck/api/timeentries/1');

		$exception = new \RuntimeException('boom');
		$this->expectExceptionObject($exception);

		$this->middlewareFor($request)->afterException(new \stdClass(), 'action', $exception);
	}
}
