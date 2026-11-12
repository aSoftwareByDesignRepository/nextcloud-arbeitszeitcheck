<?php

declare(strict_types=1);

/**
 * Request-boundary contract for GET /api/admin/work-location-policies:
 * an absent scope filter must list every policy (the admin settings page
 * renders the complete "existing default policies" list unfiltered),
 * while a half-specified filter fails closed instead of silently
 * widening the result set.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\WorkLocationAdminController;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicy;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper;
use OCA\ArbeitszeitCheck\Service\WorkLocationAdminService;
use OCA\ArbeitszeitCheck\Service\WorkLocationCatalog;
use OCA\ArbeitszeitCheck\Service\WorkLocationDefaultResolver;
use OCA\ArbeitszeitCheck\Service\WorkLocationMigrationReadinessService;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

final class WorkLocationAdminPoliciesContractTest extends TestCase
{
	private WorkLocationDefaultPolicyMapper $policyMapper;

	/** @var list<WorkLocationDefaultPolicy> */
	private array $policies = [];

	private function policy(string $scopeType, string $scopeId): WorkLocationDefaultPolicy
	{
		$p = new WorkLocationDefaultPolicy();
		$p->setScopeType($scopeType);
		$p->setScopeId($scopeId);
		$p->setLocationCode('home');
		$p->setPriority(100);
		$p->setEffectiveFrom(new \DateTime('2026-01-01'));
		$p->setRevision(1);
		return $p;
	}

	private function skeleton(string $class, array $props = []): object
	{
		$r = new \ReflectionClass($class);
		$obj = $r->newInstanceWithoutConstructor();
		foreach ($props as $name => $value) {
			$r->getProperty($name)->setValue($obj, $value);
		}
		return $obj;
	}

	private function controller(array $params, bool $catalogEnabled = true): WorkLocationAdminController
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default = '') => $catalogEnabled ? '1' : '0'
		);
		$suite = new WorkforceSuiteConfig($config);

		$this->policies = [
			$this->policy('org', 'org'),
			$this->policy('user', 'alice'),
			$this->policy('team', '7'),
		];
		$this->policyMapper = $this->createMock(WorkLocationDefaultPolicyMapper::class);
		$this->policyMapper->method('findAll')->willReturnCallback(fn () => $this->policies);
		$this->policyMapper->method('findByScope')->willReturnCallback(
			fn (string $type, string $id) => array_values(array_filter(
				$this->policies,
				fn ($p) => $p->getScopeType() === $type && $p->getScopeId() === $id
			))
		);

		// Empty cache → all() returns [] → labelFor falls back to the raw code.
		$catalog = $this->skeleton(WorkLocationCatalog::class, ['cache' => []]);
		$adminService = $this->skeleton(WorkLocationAdminService::class, [
			'policyMapper' => $this->policyMapper,
		]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('getUserLanguage')->willReturn('de');

		return new WorkLocationAdminController(
			'arbeitszeitcheck',
			$request,
			$session,
			$suite,
			$catalog,
			$adminService,
			$this->createMock(WorkLocationDefinitionMapper::class),
			$this->policyMapper,
			$this->skeleton(WorkLocationDefaultResolver::class),
			$this->skeleton(WorkLocationMigrationReadinessService::class),
			$l10nFactory,
			$l10n,
		);
	}

	public function testAbsentFilterListsAllPolicies(): void
	{
		$response = $this->controller([])->policies();
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($data['success']);
		self::assertCount(3, $data['items']);
		self::assertSame(
			['org', 'user', 'team'],
			array_map(fn (array $item) => $item['scopeType'], $data['items']),
		);
	}

	public function testScopeIdWithoutScopeTypeFailsClosed(): void
	{
		$response = $this->controller(['scopeId' => 'alice'])->policies();
		$data = $response->getData();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertFalse($data['success']);
		self::assertArrayHasKey('scopeType', $data['fields']);
	}

	public function testScopedFilterStillNarrows(): void
	{
		$response = $this->controller(['scopeType' => 'user', 'scopeId' => 'alice'])->policies();
		$data = $response->getData();

		self::assertTrue($data['success']);
		self::assertCount(1, $data['items']);
		self::assertSame('alice', $data['items'][0]['scopeId']);
	}

	public function testOrgScopeForcesCanonicalScopeId(): void
	{
		// scopeId is ignored for org — no field error even when absent.
		$response = $this->controller(['scopeType' => 'org'])->policies();
		$data = $response->getData();

		self::assertTrue($data['success']);
		self::assertCount(1, $data['items']);
		self::assertSame('org', $data['items'][0]['scopeType']);
	}

	public function testCatalogDisabledIsUniformNotFound(): void
	{
		$response = $this->controller([], catalogEnabled: false)->policies();
		$data = $response->getData();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertFalse($data['success']);
		self::assertSame('NOT_FOUND', $data['error']);
	}
}
