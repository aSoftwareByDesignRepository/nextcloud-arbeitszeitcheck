<?php

declare(strict_types=1);

/**
 * Request-boundary contract for the business-trips admin rollout API:
 * the rollback-warning ack gates every enable (form-encoded "false" must
 * not pass), credit postings depend on the trip workflow being on first,
 * and every flag flip is audited through the real service.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\BusinessTripAdminController;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Service\BusinessTripAdminService;
use OCA\ArbeitszeitCheck\Service\BusinessTripService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class BusinessTripAdminControllerTest extends TestCase
{
	/** @var array<string, string> */
	private array $store = [];

	private function config(array $flags = []): IConfig
	{
		$this->store = $flags;
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, $default = '') => $this->store[$key] ?? $default
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, $value): void {
				$this->store[$key] = (string)$value;
			}
		);
		return $config;
	}

	/**
	 * Real service, skeleton-constructed: only the props the enable paths
	 * touch are initialized (final class + 14-dep constructor otherwise).
	 */
	private function stubService(WorkforceSuiteConfig $config, AuditLogMapper $audit, LoggerInterface $logger): BusinessTripAdminService
	{
		$r = new \ReflectionClass(BusinessTripAdminService::class);
		$svc = $r->newInstanceWithoutConstructor();
		$r->getProperty('config')->setValue($svc, $config);
		$r->getProperty('audit')->setValue($svc, $audit);
		$r->getProperty('logger')->setValue($svc, $logger);
		return $svc;
	}

	private function controller(IConfig $config, AuditLogMapper $audit, array $params = []): BusinessTripAdminController
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$suite = new WorkforceSuiteConfig($config);
		// tripService is never reached by the enable paths — skeleton only.
		$tripService = (new \ReflectionClass(BusinessTripService::class))->newInstanceWithoutConstructor();
		return new BusinessTripAdminController(
			'arbeitszeitcheck',
			$request,
			$session,
			$this->stubService($suite, $audit, $this->createMock(LoggerInterface::class)),
			$tripService,
			$suite,
			$this->createMock(IURLGenerator::class),
			$this->createMock(IL10N::class),
			$this->createMock(PermissionService::class),
			$this->createMock(LocaleFormatService::class),
			$this->createMock(CSPService::class),
		);
	}

	public function testEnableRejectsMissingAck(): void
	{
		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->never())->method('logAction');
		$config = $this->config();
		$r = $this->controller($config, $audit)->enable();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
		$this->assertSame('VALIDATION', $r->getData()['error_code']);
		$this->assertArrayNotHasKey('business_trips_enabled', $this->store);
	}

	public function testEnableRejectsFormEncodedFalse(): void
	{
		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->never())->method('logAction');
		$config = $this->config();
		$r = $this->controller($config, $audit, ['acknowledgeRollbackWarning' => 'false'])->enable();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
		$this->assertArrayNotHasKey('business_trips_enabled', $this->store);
	}

	public function testEnableWithAckWritesFlagAndAudits(): void
	{
		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction')->with(
			'admin',
			'business_trip_feature_enabled',
			'feature_flag',
		);
		$config = $this->config();
		$r = $this->controller($config, $audit, ['acknowledgeRollbackWarning' => 'true'])->enable();
		$this->assertSame(Http::STATUS_OK, $r->getStatus());
		$this->assertTrue($r->getData()['success']);
		$this->assertSame('1', $this->store['business_trips_enabled']);
	}

	public function testEnableCreditWithoutTripsFlagFailsUniformNotFound(): void
	{
		// assertEnabled() in the real service fires before the credit flag
		// write — the wire payload is the uniform 404, not an oracle.
		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->never())->method('logAction');
		$config = $this->config();
		$r = $this->controller($config, $audit, ['acknowledgeRollbackWarning' => 'true'])->enableCredit();
		$this->assertSame(Http::STATUS_NOT_FOUND, $r->getStatus());
		$this->assertSame('NOT_FOUND', $r->getData()['error']);
		$this->assertArrayNotHasKey('business_trip_credit_enabled', $this->store);
	}

	public function testEnableCreditWithAckAndTripsOnWritesFlag(): void
	{
		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction')->with(
			'admin',
			'business_trip_credit_feature_enabled',
			'feature_flag',
		);
		$config = $this->config(['business_trips_enabled' => '1']);
		$r = $this->controller($config, $audit, ['acknowledgeRollbackWarning' => 'true'])->enableCredit();
		$this->assertSame(Http::STATUS_OK, $r->getStatus());
		$this->assertTrue($r->getData()['success']);
		$this->assertSame('1', $this->store['business_trip_credit_enabled']);
	}
}
