<?php

declare(strict_types=1);

/**
 * Mutation-style assertions for multi-project same-day clocking and opaque
 * HTTP 400 hygiene (Kolberg Consulting report).
 */

namespace OCA\ArbeitszeitCheck\Tests\Mutation;

use OCA\ArbeitszeitCheck\BusinessRuleCode;
use OCA\ArbeitszeitCheck\Controller\TimeTrackingController;
use OCA\ArbeitszeitCheck\Db\MobileStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\MobileStampReplayService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\StampOccurredAtParser;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MultiProjectClockAndOpaque400MutationTest extends TestCase
{
	private function makeController(TimeTrackingService $service, IUserSession $userSession, IRequest $request, IL10N $l10n): TimeTrackingController
	{
		$tzConfig = $this->createMock(IConfig::class);
		$tzConfig->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => match ($key) {
			'app_timezone' => 'UTC',
			default => $default,
		});
		$tzDateTime = $this->createMock(IDateTimeZone::class);
		$tzDateTime->method('getTimeZone')->willReturn(new \DateTimeZone('UTC'));
		$tzUserSession = $this->createMock(IUserSession::class);
		$tzUserSession->method('getUser')->willReturn(null);
		$timeZoneService = new TimeZoneService($tzConfig, $tzDateTime, $tzUserSession, new NullLogger());
		$idempotency = $this->createMock(MobileStampIdempotencyMapper::class);
		$idempotency->method('findByUserAndRequestId')->willReturn(null);
		$idempotency->method('tryInsert')->willReturn(true);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(time());
		$stampReplay = new MobileStampReplayService(
			$service,
			$idempotency,
			new StampOccurredAtParser($timeZoneService, $tzConfig),
			$timeFactory,
		);
		return new TimeTrackingController(
			'arbeitszeitcheck',
			$request,
			$service,
			$stampReplay,
			$userSession,
			$l10n,
		);
	}

	public function testAlreadyClockedInReturnsActionableJsonNotBareStatus(): void
	{
		$service = $this->createMock(TimeTrackingService::class);
		$userSession = $this->createMock(IUserSession::class);
		$request = $this->createMock(IRequest::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($s) => $s);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('tobias');
		$userSession->method('getUser')->willReturn($user);

		$service->method('clockIn')->willThrowException(new BusinessRuleException(
			'User is already clocked in',
			BusinessRuleCode::ALREADY_CLOCKED_IN,
		));

		$controller = $this->makeController($service, $userSession, $request, $l10n);

		$response = $controller->clockIn('99');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('User is already clocked in', $data['error']);
		$this->assertSame('User is already clocked in', $data['message']);
		$this->assertSame(BusinessRuleCode::ALREADY_CLOCKED_IN, $data['error_code']);
		$this->assertNotSame('400', $data['error']);
	}

	public function testProjectNotAllowedReturnsLocalizedMessageAndCode(): void
	{
		$service = $this->createMock(TimeTrackingService::class);
		$userSession = $this->createMock(IUserSession::class);
		$request = $this->createMock(IRequest::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($s) => $s);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('tobias');
		$userSession->method('getUser')->willReturn($user);

		$service->method('clockIn')->willThrowException(new BusinessRuleException(
			'You cannot clock in on the selected project.',
			BusinessRuleCode::PROJECT_NOT_ALLOWED,
		));

		$controller = $this->makeController($service, $userSession, $request, $l10n);

		$response = $controller->clockIn('7');
		$data = $response->getData();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(BusinessRuleCode::PROJECT_NOT_ALLOWED, $data['error_code']);
		$this->assertStringContainsString('cannot clock in', strtolower($data['error']));
	}

	/**
	 * Source contract: toast + mapApiError must escape/reject HTML so a proxy
	 * HTML 400 page cannot render a giant bare "400" over the dashboard.
	 */
	public function testFrontendSourceRejectsHtmlErrorBodies(): void
	{
		$apiJs = file_get_contents(__DIR__ . '/../../js/common/api.js');
		$this->assertNotFalse($apiJs);
		$this->assertStringContainsString('isUnsafeApiErrorText', $apiJs);
		$this->assertStringContainsString('httpTransportFallbackMessage', $apiJs);
		$this->assertStringContainsString('empty-content|guest-box|body-login', $apiJs);

		$componentsJs = file_get_contents(__DIR__ . '/../../js/common/components.js');
		$this->assertNotFalse($componentsJs);
		$this->assertStringContainsString('safeMessage = this._escapeHtml', $componentsJs);
		$this->assertStringContainsString('safeTitle', $componentsJs);

		$mainJs = file_get_contents(__DIR__ . '/../../js/arbeitszeitcheck-main.js');
		$this->assertNotFalse($mainJs);
		$this->assertStringContainsString("'Accept': 'application/json'", $mainJs);
		$this->assertStringContainsString('resolveTransportError', $mainJs);
		$this->assertStringContainsString('isUnsafeApiErrorText', $mainJs);
	}

	public function testDashboardHelpDocumentsAttendanceVsProjectCheck(): void
	{
		$dashboard = file_get_contents(__DIR__ . '/../../templates/dashboard.php');
		$this->assertNotFalse($dashboard);
		$this->assertStringContainsString('log project hours in ProjectCheck', $dashboard);
		$this->assertStringNotContainsString(
			'clock out first, then clock in again with the other project',
			$dashboard
		);
	}
}
