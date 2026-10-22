<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\ComplianceController;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The compliance page shows one permitted person. A normal user never receives
 * the admin scan, and a foreign id never reaches the mapper.
 */
final class ComplianceControllerDashboardTest extends TestCase {
	private ComplianceController $controller;

	private PermissionService&MockObject $permissions;

	private ComplianceViolationMapper&MockObject $violations;

	private ComplianceService&MockObject $compliance;

	private IUserManager&MockObject $users;

	/** @var list<string> */
	private array $loadedUsers = [];

	/** @var list<string> */
	private array $statusUsers = [];

	private string $uid = 'employee';

	private mixed $requestedUserId = null;

	private bool $admin = false;

	private bool $canResolve = false;

	/** @var list<string> */
	private array $allowedTargets = [];

	/** @var list<ComplianceViolation|string> */
	private array $rows = [];

	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(function (string $key) {
			return $key === 'userId' ? $this->requestedUserId : null;
		});

		$this->permissions = $this->createMock(PermissionService::class);
		$this->permissions->method('isAdmin')->willReturnCallback(fn (): bool => $this->admin);
		$this->permissions->method('canAccessManagerDashboard')->willReturn(false);
		$this->permissions->method('canViewUserCompliance')->willReturnCallback(
			fn (string $actor, string $target): bool => $actor === $this->uid && in_array($target, $this->allowedTargets, true)
		);
		$this->permissions->method('canResolveViolation')->willReturnCallback(
			fn (string $actor, string $owner): bool => $this->canResolve && $actor === $this->uid
		);

		$this->violations = $this->createMock(ComplianceViolationMapper::class);
		$this->violations->method('findByUser')->willReturnCallback(function (string $userId) {
			$this->loadedUsers[] = $userId;
			return $this->rows;
		});

		$this->compliance = $this->createMock(ComplianceService::class);
		$this->compliance->method('getComplianceStatus')->willReturnCallback(function (string $userId): array {
			$this->statusUsers[] = $userId;
			return [
				'compliant' => true,
				'score' => 80,
				'has_data' => true,
				'violation_count' => 0,
				'critical_violations' => 0,
				'warning_violations' => 0,
				'info_violations' => 0,
			];
		});

		$session = $this->createMock(IUserSession::class);
		$current = $this->createMock(IUser::class);
		$current->method('getUID')->willReturnCallback(fn (): string => $this->uid);
		$current->method('getDisplayName')->willReturn('Current');
		$session->method('getUser')->willReturn($current);

		$ada = $this->createMock(IUser::class);
		$ada->method('getDisplayName')->willReturn('Ada');
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturnCallback(fn (string $id) => $id === 'employee1' ? $ada : null);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(function (string $route, array $params = []): string {
			if ($route === 'arbeitszeitcheck.compliance.dashboard') {
				if (isset($params['userId'])) {
					return '/apps/arbeitszeitcheck/compliance?userId=' . rawurlencode((string)$params['userId']);
				}
				return '/apps/arbeitszeitcheck/compliance';
			}
			return '/apps/arbeitszeitcheck/x';
		});
		$urls->method('linkToDefaultPageUrl')->willReturn('/');

		$locale = $this->createMock(LocaleFormatService::class);
		$locale->method('clientHints')->willReturn([
			'locale' => 'de-DE',
			'htmlLang' => 'de-DE',
			'timezone' => 'Europe/Berlin',
			'displayTimezone' => 'Europe/Berlin',
		]);

		$absences = $this->createMock(AbsenceMapper::class);
		$absences->method('findSubstitutePendingForUser')->willReturn([]);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$flags = new NavigationFlagsService($absences, $this->permissions, $config);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$labor = $this->createMock(LaborLawProfileFactory::class);
		$labor->method('getEffectiveCountry')->willReturn('DE');
		$labor->method('getConfiguredCountry')->willReturn('DE');

		$csp = $this->createMock(CSPService::class);
		$csp->method('applyPolicyWithNonce')->willReturnArgument(0);

		$this->controller = new ComplianceController(
			'arbeitszeitcheck',
			$request,
			$this->compliance,
			$this->violations,
			$this->createMock(AuditLogMapper::class),
			$this->permissions,
			$session,
			$this->users,
			$urls,
			$csp,
			$locale,
			$l10n,
			$flags,
			$labor,
		);
	}

	public function testEmployeeSeesOnlyTheirOwnReportAndNoAdminScan(): void {
		$this->rows = [
			$this->violation(1, 'employee', 'missing_break', 'error', false),
			$this->violation(2, 'employee', 'sunday_work', 'warning', true),
			'not-a-violation',
		];

		$params = $this->dashboardParams();

		self::assertSame(['employee'], $this->loadedUsers);
		self::assertSame(['employee'], $this->statusUsers);
		self::assertFalse($params['showComplianceRunCheck']);
		self::assertFalse($params['collapsePageHelp']);
		self::assertSame('compliance', $params['pageId']);
		self::assertSame('wide', $params['shellWidth']);
		self::assertNull($params['filterUserId']);
		self::assertSame('employee', $params['roleSlug']);
		self::assertCount(2, $params['violations']);
		self::assertSame(2, $params['violationTotal']);
		self::assertSame(2, $params['violationPager']['total']);
		self::assertSame(25, $params['violationPager']['perPage']);
		self::assertSame(2, $params['typePager']['total']);
		self::assertFalse($params['violations'][0]['can_resolve']);
		self::assertSame('21.10.2026', $params['violations'][0]['date']);
		self::assertSame('Missing break', $params['violations'][0]['description']);
		self::assertSame(2, $params['reportData']['total_violations']);
		self::assertSame(1, $params['reportData']['unresolved']);
		self::assertSame(['missing_break' => 1, 'sunday_work' => 1], $params['reportData']['by_type']);
		self::assertArrayNotHasKey('btn-run-compliance-check', $params);
	}

	public function testAdminScanStaysOnTheAdminsOwnRows(): void {
		$this->uid = 'admin';
		$this->admin = true;
		$this->rows = [$this->violation(9, 'admin', 'night_work', 'info', false)];
		$this->canResolve = true;

		$params = $this->dashboardParams();

		self::assertTrue($params['showComplianceRunCheck']);
		self::assertSame(['admin'], $this->loadedUsers);
		self::assertSame(['admin'], $this->statusUsers);
		self::assertSame('administrator', $params['roleSlug']);
		self::assertTrue($params['violations'][0]['can_resolve']);
	}

	public function testForeignIdDoesNotLoadThatPersonsData(): void {
		$this->requestedUserId = 'boss';
		$this->admin = true;
		$this->permissions->expects($this->once())
			->method('logPermissionDenied')
			->with('employee', 'view_compliance', 'compliance', 'boss');

		$params = $this->dashboardParams();

		self::assertSame([], $this->loadedUsers);
		self::assertSame([], $this->statusUsers);
		self::assertSame([], $params['violations']);
		self::assertSame(0, $params['violationTotal']);
		self::assertSame(0, $params['reportData']['total_violations']);
		self::assertNull($params['filterUserId']);
		self::assertNull($params['filterDisplayName']);
		self::assertFalse($params['showComplianceRunCheck']);
		self::assertTrue($params['complianceStatus']['load_error']);
		self::assertStringNotContainsString('boss', (string)$params['error']);
	}

	public function testManagerSeesTheEmployeeTheyAreAllowedToOpen(): void {
		$this->uid = 'manager';
		$this->requestedUserId = '  employee1  ';
		$this->allowedTargets = ['employee1'];
		$this->canResolve = true;
		$this->rows = [$this->violation(4, 'employee1', 'missing_break', 'error', false)];

		$params = $this->dashboardParams();

		self::assertSame(['employee1'], $this->loadedUsers);
		self::assertSame(['employee1'], $this->statusUsers);
		self::assertSame('employee1', $params['filterUserId']);
		self::assertSame('Ada', $params['filterDisplayName']);
		self::assertFalse($params['showComplianceRunCheck']);
		self::assertTrue($params['violations'][0]['can_resolve']);
		self::assertSame('missing_break', $params['violations'][0]['type']);
	}

	public function testResolvedRowHasNoFixActionEvenForAManager(): void {
		$this->uid = 'manager';
		$this->requestedUserId = 'employee1';
		$this->allowedTargets = ['employee1'];
		$this->canResolve = true;
		$this->rows = [$this->violation(5, 'employee1', 'missing_break', 'error', true)];

		$params = $this->dashboardParams();

		self::assertFalse($params['violations'][0]['can_resolve']);
		self::assertSame(0, $params['reportData']['unresolved']);
	}

	public function testPagePaintsTheLatestFiftyAndKeepsTheFullTotal(): void {
		$this->rows = [];
		for ($i = 1; $i <= 51; $i++) {
			$this->rows[] = $this->violation($i, 'employee', 'missing_break', 'info', false);
		}

		$params = $this->dashboardParams();

		self::assertCount(50, $params['violations']);
		self::assertSame(51, $params['violationTotal']);
		self::assertSame(50, $params['violationPager']['total']);
		self::assertSame(2, $params['violationPager']['pages']);
		self::assertSame(51, $params['reportData']['total_violations']);
		self::assertSame(51, $params['reportData']['unresolved']);
	}

	public function testArrayUserIdIsIgnoredAndStaysOnTheViewersPage(): void {
		$this->requestedUserId = ['boss'];
		$this->rows = [$this->violation(1, 'employee', 'missing_break', 'error', false)];

		$params = $this->dashboardParams();

		self::assertSame(['employee'], $this->loadedUsers);
		self::assertNull($params['filterUserId']);
		self::assertFalse($params['complianceStatus']['load_error'] ?? false);
	}

	public function testOldViolationsAddressKeepsAPermittedUserAndTheListAnchor(): void {
		// The merged implementation keeps the violations list as a real page
		// (whole-scope discovery lives here) — a permitted userId opens the
		// list filtered to that person instead of bouncing to the dashboard.
		$this->uid = 'manager';
		$this->requestedUserId = '  employee1  ';
		$this->allowedTargets = ['employee1'];

		$response = $this->controller->violations();

		self::assertInstanceOf(TemplateResponse::class, $response);
		$params = $response->getParams();
		self::assertSame('employee1', $params['filterUserId']);
		self::assertSame('Ada', $params['filterDisplayName']);
	}

	public function testOldReportsAddressDropsANonStringUserAndOpensTheStatus(): void {
		$this->requestedUserId = ['employee1'];
		$response = $this->controller->reports();
		self::assertSame('/apps/arbeitszeitcheck/compliance#compliance-status', $response->getRedirectURL());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function dashboardParams(): array {
		$response = $this->controller->dashboard();
		self::assertInstanceOf(TemplateResponse::class, $response);
		$params = $response->getParams();
		self::assertIsArray($params);
		return $params;
	}

	private function violation(int $id, string $owner, string $type, string $severity, bool $resolved): ComplianceViolation {
		$row = new ComplianceViolation();
		$row->setId($id);
		$row->setUserId($owner);
		$row->setViolationType($type);
		$row->setSeverity($severity);
		$row->setDescription('Missing break');
		$row->setDate(new \DateTime('2026-10-21'));
		$row->setResolved($resolved);
		return $row;
	}
}
