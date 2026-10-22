<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\HolidayController;
use OCA\ArbeitszeitCheck\Controller\SettingsController;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AtlasHolidaySettingsSurfaceTest extends TestCase
{
	public function testHolidayIndexUnauthorizedAndHappy(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['start' => '2026-01-01', 'end' => '2026-01-31']);
		$holidays = $this->createMock(HolidayService::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

		$c = new HolidayController('arbeitszeitcheck', $request, $holidays, $session, $l10n, $logger);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $c->index()->getStatus());

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');
		$session2 = $this->createMock(IUserSession::class);
		$session2->method('getUser')->willReturn($user);
		$holidays->method('resolveStateForUser')->willReturn('NW');
		$holidays->method('getHolidaysForRange')->willReturn([]);
		$c2 = new HolidayController('arbeitszeitcheck', $request, $holidays, $session2, $l10n, $logger);
		$res = $c2->index('2026-01-01', '2026-01-31');
		$this->assertTrue($res->getData()['success']);
	}
}
