<?php

declare(strict_types=1);

/**
 * Request-boundary contract for the special-entitlements admin enable API:
 * the rollback-warning ack is a hard gate (including the form-encoded
 * "false" trap), the success path flips BOTH flags atomically through the
 * real service and writes an audit record, and unauthenticated calls fail.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\SpecialEntitlementAdminController;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Exception\NotAuthenticatedException;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\SpecialEntitlementAdminService;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

final class SpecialEntitlementAdminControllerTest extends TestCase
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
	 * Real service, skeleton-constructed: only the props the enable path
	 * touches are initialized (final class + 20-dep constructor otherwise).
	 */
	private function stubService(WorkforceSuiteConfig $suite, AuditLogMapper $audit): SpecialEntitlementAdminService
	{
		$r = new \ReflectionClass(SpecialEntitlementAdminService::class);
		$svc = $r->newInstanceWithoutConstructor();
		$r->getProperty('suiteConfig')->setValue($svc, $suite);
		$r->getProperty('audit')->setValue($svc, $audit);
		return $svc;
	}

	private function controller(IConfig $config, AuditLogMapper $audit, array $params = [], bool $authenticated = true): SpecialEntitlementAdminController
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default
		);
		$session = $this->createMock(IUserSession::class);
		if ($authenticated) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('admin');
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}
		$suite = new WorkforceSuiteConfig($config);
		return new SpecialEntitlementAdminController(
			'arbeitszeitcheck',
			$request,
			$session,
			$this->stubService($suite, $audit),
			$suite,
			$this->createMock(IURLGenerator::class),
			$this->createMock(IFactory::class),
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
		$this->assertSame('VALIDATION_ERROR', $r->getData()['error_code']);
		$this->assertArrayNotHasKey('special_entitlements_enabled', $this->store);
	}

	public function testEnableRejectsFormEncodedFalse(): void
	{
		// The api-security-patterns rule: "false" arrives as a string and
		// must NOT count as acknowledgement.
		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->never())->method('logAction');
		$config = $this->config();
		$r = $this->controller($config, $audit, ['acknowledgeRollbackWarning' => 'false'])->enable();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
		$this->assertArrayNotHasKey('special_entitlements_enabled', $this->store);
	}

	public function testEnableWithAckFlipsBothFlagsAtomicallyAndAudits(): void
	{
		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction')->with(
			'admin',
			'special_entitlements_feature_enabled',
			'feature_flag',
		);
		$config = $this->config();
		$r = $this->controller($config, $audit, ['acknowledgeRollbackWarning' => 'true'])->enable();
		$this->assertSame(Http::STATUS_OK, $r->getStatus());
		$this->assertTrue($r->getData()['success']);
		// Atomicity contract: both flags in one updateFlags call — never a
		// half-enabled state (entitlements without cohorts resolve broken).
		$this->assertSame('1', $this->store['special_entitlements_enabled']);
		$this->assertSame('1', $this->store['policy_cohorts_enabled']);
	}

	public function testEnableAcceptsStringOneAck(): void
	{
		$config = $this->config();
		$r = $this->controller($config, $this->createMock(AuditLogMapper::class), ['acknowledgeRollbackWarning' => '1'])->enable();
		$this->assertTrue($r->getData()['success']);
	}

	public function testEnableUnauthenticatedThrows(): void
	{
		$this->expectException(NotAuthenticatedException::class);
		$this->controller($this->config(), $this->createMock(AuditLogMapper::class), ['acknowledgeRollbackWarning' => 'true'], false)->enable();
	}
}
