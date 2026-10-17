<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicy;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinition;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\WorkLocationConflictException;
use OCA\ArbeitszeitCheck\Service\WorkLocationAdminService;
use OCA\ArbeitszeitCheck\Service\WorkLocationCatalog;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WorkLocationAdminServiceTest extends TestCase
{
	private WorkLocationDefinitionMapper $defMapper;
	private WorkLocationDefaultPolicyMapper $policyMapper;
	private IUserManager $userManager;
	private WorkLocationAdminService $service;

	/** @var array<int, WorkLocationDefinition> */
	private array $defs = [];
	/** @var list<WorkLocationDefaultPolicy> */
	private array $policies = [];
	private int $nextId = 100;
	private int $entryRefs = 0;

	protected function setUp(): void
	{
		$this->defs = [];
		$this->policies = [];
		$this->nextId = 100;
		$this->entryRefs = 0;

		$this->defMapper = $this->createMock(WorkLocationDefinitionMapper::class);
		$this->defMapper->method('findAllIndexed')->willReturnCallback(function () {
			$out = [];
			foreach ($this->defs as $d) {
				$out[$d->getCode()] = $d;
			}
			return $out;
		});
		$this->defMapper->method('codeExists')->willReturnCallback(function (string $code) {
			foreach ($this->defs as $d) {
				if ($d->getCode() === $code) {
					return true;
				}
			}
			return false;
		});
		$this->defMapper->method('find')->willReturnCallback(function (int $id) {
			if (!isset($this->defs[$id])) {
				throw new DoesNotExistException('nope');
			}
			return $this->defs[$id];
		});
		$this->defMapper->method('insert')->willReturnCallback(function (WorkLocationDefinition $d) {
			$d->setId($this->nextId++);
			$this->defs[$d->getId()] = $d;
			return $d;
		});
		$this->defMapper->method('update')->willReturnCallback(fn (WorkLocationDefinition $d) => $d);
		$this->defMapper->method('delete')->willReturnCallback(function (WorkLocationDefinition $d) {
			unset($this->defs[$d->getId()]);
			return $d;
		});
		$this->defMapper->method('countReferencesByCode')->willReturnCallback(fn () => $this->entryRefs);

		$this->policyMapper = $this->createMock(WorkLocationDefaultPolicyMapper::class);
		$this->policyMapper->method('findOverlapping')->willReturnCallback(function ($type, $id, $from, $to, $exclude = null) {
			$hits = [];
			foreach ($this->policies as $p) {
				if ($p->getScopeType() !== $type || $p->getScopeId() !== $id) {
					continue;
				}
				if ($exclude !== null && (int)$p->getId() === $exclude) {
					continue;
				}
				$pf = $p->getEffectiveFrom()->format('Y-m-d');
				$pt = $p->getEffectiveTo()?->format('Y-m-d');
				// overlap: existing.to >= new.from AND (new.to === null OR existing.from <= new.to)
				if (($pt === null || $pt >= $from) && ($to === null || $pf <= $to)) {
					$hits[] = $p;
				}
			}
			return $hits;
		});
		$this->policyMapper->method('insert')->willReturnCallback(function (WorkLocationDefaultPolicy $p) {
			$p->setId($this->nextId++);
			$this->policies[] = $p;
			return $p;
		});
		$this->policyMapper->method('update')->willReturnCallback(fn (WorkLocationDefaultPolicy $p) => $p);
		$this->policyMapper->method('findByLocationCode')->willReturnCallback(function (string $code) {
			return array_values(array_filter($this->policies, fn ($p) => $p->getLocationCode() === $code));
		});
		$this->policyMapper->method('findActiveByLocationCode')->willReturnCallback(function (string $code, string $today) {
			return array_values(array_filter($this->policies, function ($p) use ($code, $today) {
				$to = $p->getEffectiveTo()?->format('Y-m-d');
				return $p->getLocationCode() === $code && ($to === null || $to >= $today);
			}));
		});
		$this->policyMapper->method('findByScope')->willReturnCallback(function (string $type, string $id) {
			return array_values(array_filter($this->policies, fn ($p) => $p->getScopeType() === $type && $p->getScopeId() === $id));
		});
		$this->policyMapper->method('find')->willReturnCallback(function (int $id) {
			foreach ($this->policies as $p) {
				if ((int)$p->getId() === $id) {
					return $p;
				}
			}
			throw new DoesNotExistException('nope');
		});

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('userExists')->willReturnCallback(fn (string $uid) => $uid === 'existing_user');

		$teamMapper = $this->createMock(TeamMapper::class);
		$teamMapper->method('find')->willReturnCallback(function (int $id) {
			if ($id !== 7) {
				throw new DoesNotExistException('no team');
			}
			return new \OCA\ArbeitszeitCheck\Db\Team();
		});
		$modelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$modelMapper->method('find')->willReturnCallback(function (int $id) {
			if ($id !== 5) {
				throw new DoesNotExistException('no model');
			}
			return new \OCA\ArbeitszeitCheck\Db\WorkingTimeModel();
		});

		$catalog = new WorkLocationCatalog($this->defMapper, new NullLogger());
		$this->service = new WorkLocationAdminService(
			$this->defMapper,
			$this->policyMapper,
			$catalog,
			$teamMapper,
			$modelMapper,
			$this->userManager,
			$this->createMock(AuditLogMapper::class),
			$this->createMock(ILockingProvider::class),
		);
	}

	public function testCreatePrefixesCodeAndPersists(): void
	{
		$row = $this->service->create([
			'label' => 'Standort Halberstadt',
			'code' => 'halberstadt',
			'category' => 'office',
			'color' => 'primary',
			'icon' => 'building',
		], 'admin1');
		self::assertSame('custom_halberstadt', $row->getCode());
		self::assertTrue((bool)$row->getActive());
		self::assertFalse((bool)$row->getBuiltIn());
		self::assertSame(1, (int)$row->getVersion());
	}

	public function testCreateDuplicateBuiltInCodeConflicts(): void
	{
		try {
			$this->service->create(['label' => 'X', 'code' => 'office', 'category' => 'office'], 'admin1');
			self::fail('expected conflict');
		} catch (WorkLocationConflictException $e) {
			self::assertSame(WorkLocationAdminService::ERR_CODE_EXISTS, $e->getReasonCode());
		}
	}

	public function testCreateDuplicateCustomCodeConflicts(): void
	{
		$this->service->create(['label' => 'A', 'code' => 'site', 'category' => 'other'], 'admin1');
		try {
			$this->service->create(['label' => 'B', 'code' => 'site', 'category' => 'office'], 'admin1');
			self::fail('expected conflict');
		} catch (WorkLocationConflictException $e) {
			self::assertSame(WorkLocationAdminService::ERR_CODE_EXISTS, $e->getReasonCode());
		}
	}

	public function testCreateRejectsBadCategoryAndLabel(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service->create(['label' => 'X', 'code' => 'ok_code', 'category' => 'spaceship'], 'admin1');
	}

	public function testUpdateBumpsVersionAndKeepsCodeImmutable(): void
	{
		$row = $this->service->create(['label' => 'A', 'code' => 'site', 'category' => 'other'], 'admin1');
		$updated = $this->service->update((int)$row->getId(), ['label' => 'Renamed', 'code' => 'evil'], 'admin2');
		self::assertSame('custom_site', $updated->getCode());
		self::assertSame('Renamed', $updated->getBaseLabel());
		self::assertSame(2, (int)$updated->getVersion());
	}

	public function testDeactivateBlockedByActivePolicy(): void
	{
		$row = $this->service->create(['label' => 'A', 'code' => 'site', 'category' => 'other'], 'admin1');
		$this->service->createPolicy([
			'scopeType' => 'org',
			'locationCode' => 'custom_site',
			'effectiveFrom' => '2026-01-01',
		], 'admin1');
		try {
			$this->service->update((int)$row->getId(), ['active' => false], 'admin1');
			self::fail('expected conflict');
		} catch (WorkLocationConflictException $e) {
			self::assertSame(WorkLocationAdminService::ERR_HAS_ACTIVE_DEFAULTS, $e->getReasonCode());
		}
	}

	public function testDeleteBlockedByEntryReferences(): void
	{
		$row = $this->service->create(['label' => 'A', 'code' => 'site', 'category' => 'other'], 'admin1');
		$this->entryRefs = 3;
		try {
			$this->service->delete((int)$row->getId(), 'admin1');
			self::fail('expected conflict');
		} catch (WorkLocationConflictException $e) {
			self::assertSame(WorkLocationAdminService::ERR_IN_USE, $e->getReasonCode());
		}
	}

	public function testDeleteUnreferencedCustomRowSucceeds(): void
	{
		$row = $this->service->create(['label' => 'A', 'code' => 'site', 'category' => 'other'], 'admin1');
		$this->service->delete((int)$row->getId(), 'admin1');
		self::assertEmpty($this->defs);
	}

	public function testPolicyOverlapRejected(): void
	{
		$this->service->createPolicy([
			'scopeType' => 'user', 'scopeId' => 'existing_user',
			'locationCode' => 'home', 'effectiveFrom' => '2026-01-01', 'effectiveTo' => '2026-12-31',
		], 'admin1');
		try {
			$this->service->createPolicy([
				'scopeType' => 'user', 'scopeId' => 'existing_user',
				'locationCode' => 'office', 'effectiveFrom' => '2026-06-01',
			], 'admin1');
			self::fail('expected overlap conflict');
		} catch (WorkLocationConflictException $e) {
			self::assertSame(WorkLocationAdminService::ERR_POLICY_OVERLAP, $e->getReasonCode());
		}
	}

	public function testPolicyAdjacentRangesAllowed(): void
	{
		$this->service->createPolicy([
			'scopeType' => 'user', 'scopeId' => 'existing_user',
			'locationCode' => 'home', 'effectiveFrom' => '2026-01-01', 'effectiveTo' => '2026-06-30',
		], 'admin1');
		$second = $this->service->createPolicy([
			'scopeType' => 'user', 'scopeId' => 'existing_user',
			'locationCode' => 'office', 'effectiveFrom' => '2026-07-01',
		], 'admin1');
		self::assertNotNull($second->getId());
	}

	public function testPolicyOnMissingScopeIs404NotFound(): void
	{
		try {
			$this->service->createPolicy([
				'scopeType' => 'user', 'scopeId' => 'ghost_user',
				'locationCode' => 'home', 'effectiveFrom' => '2026-01-01',
			], 'admin1');
			self::fail('expected not-found conflict');
		} catch (WorkLocationConflictException $e) {
			self::assertSame(WorkLocationAdminService::ERR_NOT_FOUND, $e->getReasonCode());
		}
	}

	public function testPolicyScopeIdRejectsNonDigits(): void
	{
		try {
			$this->service->createPolicy([
				'scopeType' => 'team', 'scopeId' => '7.9',
				'locationCode' => 'home', 'effectiveFrom' => '2026-01-01',
			], 'admin1');
			self::fail('expected not-found');
		} catch (WorkLocationConflictException $e) {
			self::assertSame(WorkLocationAdminService::ERR_NOT_FOUND, $e->getReasonCode());
		}
	}

	public function testPolicyPriorityOutOfRangeRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service->createPolicy([
			'scopeType' => 'org', 'locationCode' => 'home',
			'priority' => 2000, 'effectiveFrom' => '2026-01-01',
		], 'admin1');
	}

	public function testEndPolicySetsEffectiveTo(): void
	{
		$p = $this->service->createPolicy([
			'scopeType' => 'org', 'locationCode' => 'home', 'effectiveFrom' => '2026-01-01',
		], 'admin1');
		$ended = $this->service->endPolicy((int)$p->getId(), '2026-06-30', 'admin1');
		self::assertSame('2026-06-30', $ended->getEffectiveTo()->format('Y-m-d'));
		self::assertSame(2, (int)$ended->getRevision());
	}
}
