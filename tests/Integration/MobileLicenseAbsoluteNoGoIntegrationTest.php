<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Middleware\ClientLicenseMiddleware;
use OCA\ArbeitszeitCheck\Middleware\ClientLicenseRequiredException;
use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCA\ArbeitszeitCheck\Service\MobileSeatService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Absolute No-Go: Basic-auth mobile clock mutations must not proceed without
 * an active mobile plan + assigned seat. Prevents silent license bypass.
 */
final class MobileLicenseAbsoluteNoGoIntegrationTest extends TestCase
{
	public function testClockInBlockedWhenMobilePlanInactive(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('POST');
		$request->method('getPathInfo')->willReturn('/apps/arbeitszeitcheck/api/clock/in');
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => strtolower($name) === 'authorization'
				? 'Basic ZTJlOmFwcA=='
				: '',
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('e2e_employee');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$license = $this->createMock(LicenseService::class);
		$license->method('isMobilePlanActive')->willReturn(false);
		$seats = $this->createMock(MobileSeatService::class);
		$seats->expects($this->never())->method('isUserAllowed');

		$middleware = new ClientLicenseMiddleware(
			$request,
			$session,
			$license,
			$seats,
			\OC::$server->get(IFactory::class),
			\OC::$server->get(LoggerInterface::class),
		);

		try {
			$middleware->beforeController(new \stdClass(), 'clockIn');
			$this->fail('Expected ClientLicenseRequiredException');
		} catch (ClientLicenseRequiredException $e) {
			$this->assertSame('no_plan', $e->getReason());
		}

		$response = $middleware->afterException(
			new \stdClass(),
			'clockIn',
			new ClientLicenseRequiredException('no_plan'),
		);
		$this->assertSame(402, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('LICENSE_REQUIRED', $data['code'] ?? null);
	}

	public function testClockInBlockedWhenSeatMissingDespiteActivePlan(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('POST');
		$request->method('getPathInfo')->willReturn('/apps/arbeitszeitcheck/api/clock/in');
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => strtolower($name) === 'authorization'
				? 'Basic ZTJlOmFwcA=='
				: '',
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('no_seat_user');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$license = $this->createMock(LicenseService::class);
		$license->method('isMobilePlanActive')->willReturn(true);
		$seats = $this->createMock(MobileSeatService::class);
		$seats->method('isUserAllowed')->with('no_seat_user')->willReturn(false);

		$middleware = new ClientLicenseMiddleware(
			$request,
			$session,
			$license,
			$seats,
			\OC::$server->get(IFactory::class),
			\OC::$server->get(LoggerInterface::class),
		);

		$this->expectException(ClientLicenseRequiredException::class);
		$middleware->beforeController(new \stdClass(), 'clockIn');
	}
}
