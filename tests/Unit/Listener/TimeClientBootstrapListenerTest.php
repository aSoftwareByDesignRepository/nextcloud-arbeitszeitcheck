<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Listener;

use OCA\ArbeitszeitCheck\Listener\TimeClientBootstrapListener;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\TimeClientBootstrap;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TimeClientBootstrapListenerTest extends TestCase {
	protected function tearDown(): void {
		$ref = new \ReflectionClass(TimeClientBootstrap::class);
		foreach (['configRegistered', 'scriptsRegistered'] as $property) {
			$prop = $ref->getProperty($property);
			$prop->setAccessible(true);
			$prop->setValue(null, false);
		}
		parent::tearDown();
	}

	private function createBootstrap(IInitialState $initialState): TimeClientBootstrap {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default) => match ($key) {
			'app_timezone' => 'Europe/Berlin',
			default => $default,
		});
		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$timeZoneService = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());

		return new TimeClientBootstrap($timeZoneService, $dateTimeZone, $initialState);
	}

	public function testUserDisplayTimeZoneReturnsUserZone(): void {
		$tcb = $this->createBootstrap($this->createMock(IInitialState::class));
		$this->assertSame('Europe/Berlin', $tcb->userDisplayTimeZone()->getName());
	}

	public function testUserDisplayTimeZoneFallsBackToStorageZoneOnError(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default) => $default);
		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willThrowException(new \RuntimeException('tz gone'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$timeZoneService = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());
		$tcb = new TimeClientBootstrap($timeZoneService, $dateTimeZone, $this->createMock(IInitialState::class));

		// storage fallback must not propagate the user-tz failure
		$this->assertInstanceOf(\DateTimeZone::class, $tcb->userDisplayTimeZone());
	}

	public function testRegistersConfigOnArbeitszeitCheckPage(): void {
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects($this->once())->method('provideInitialState')->with('time', $this->isType('array'));

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/arbeitszeitcheck/dashboard');
		$request->method('getRequestUri')->willReturn('/index.php/apps/arbeitszeitcheck/dashboard');

		$listener = new TimeClientBootstrapListener($this->createBootstrap($initialState), $request);
		$event = $this->createMock(BeforeTemplateRenderedEvent::class);
		$event->method('isLoggedIn')->willReturn(true);

		$listener->handle($event);
	}

	public function testSkipsUnrelatedPages(): void {
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects($this->never())->method('provideInitialState');

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/files/');
		$request->method('getRequestUri')->willReturn('/index.php/apps/files/');

		$listener = new TimeClientBootstrapListener($this->createBootstrap($initialState), $request);
		$event = $this->createMock(BeforeTemplateRenderedEvent::class);
		$event->method('isLoggedIn')->willReturn(true);

		$listener->handle($event);
	}

	public function testSkipsGuests(): void {
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects($this->never())->method('provideInitialState');

		$request = $this->createMock(IRequest::class);
		$listener = new TimeClientBootstrapListener($this->createBootstrap($initialState), $request);
		$event = $this->createMock(BeforeTemplateRenderedEvent::class);
		$event->method('isLoggedIn')->willReturn(false);

		$listener->handle($event);
	}
}
