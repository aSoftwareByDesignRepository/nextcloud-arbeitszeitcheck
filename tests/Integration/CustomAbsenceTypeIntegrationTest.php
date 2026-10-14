<?php

declare(strict_types=1);

/**
 * Integration test for custom (admin-defined) absence types against the real
 * database: schema shape, service CRUD, catalog merge, in-use deactivation
 * and the summary metadata contract the clients consume.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\CustomAbsenceTypeMapper;
use OCA\ArbeitszeitCheck\Exception\CatalogBusyException;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\CustomAbsenceTypeService;
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Test\TestCase;

final class CustomAbsenceTypeIntegrationTest extends TestCase
{
	private CustomAbsenceTypeService $service;
	private CustomAbsenceTypeMapper $mapper;
	private AbsenceMapper $absenceMapper;
	private IDBConnection $db;

	/** @var int[] */
	private array $createdTypeIds = [];
	/** @var int[] */
	private array $createdAbsenceIds = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->service = \OC::$server->get(CustomAbsenceTypeService::class);
		$this->mapper = \OC::$server->get(CustomAbsenceTypeMapper::class);
		$this->absenceMapper = \OC::$server->get(AbsenceMapper::class);
		$this->db = \OC::$server->get(IDBConnection::class);

		if (!$this->db->tableExists('at_custom_absence')) {
			self::markTestSkipped('at_custom_absence table not present — run the app upgrade first.');
		}
	}

	protected function tearDown(): void
	{
		foreach ($this->createdAbsenceIds as $id) {
			try {
				$this->absenceMapper->delete($this->absenceMapper->find($id));
			} catch (\Throwable $e) {
				// best effort cleanup
			}
		}
		foreach ($this->createdTypeIds as $id) {
			try {
				$this->mapper->delete($this->mapper->find($id));
			} catch (\Throwable $e) {
				// best effort cleanup
			}
		}
		AbsenceTypeCatalog::resetCustomTypeCache();
		parent::tearDown();
	}

	/** createSchema() returns storage-level (prefixed) names — match by suffix. */
	private function prefixedTable(\Doctrine\DBAL\Schema\Schema $schema, string $appName): string
	{
		foreach ($schema->getTableNames() as $name) {
			if (str_ends_with($name, '.' . $appName) || str_ends_with($name, $appName)) {
				return $name;
			}
		}
		self::fail("table $appName not found in schema");
	}

	private function insertAbsence(string $type, string $userId = '__azct_integration__'): int
	{
		$absence = new Absence();
		$absence->setUserId($userId);
		$absence->setType($type);
		$absence->setStartDate(new \DateTime('2026-10-20'));
		$absence->setEndDate(new \DateTime('2026-10-20'));
		$absence->setReason('integration test');
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setCreatedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());
		$inserted = $this->absenceMapper->insert($absence);
		$this->createdAbsenceIds[] = (int)$inserted->getId();
		return (int)$inserted->getId();
	}

	public function testSchemaShape(): void
	{
		self::assertTrue($this->db->tableExists('at_custom_absence'));

		$schema = $this->db->createSchema();
		$table = $schema->getTable($this->prefixedTable($schema, 'at_custom_absence'));
		foreach (['id', 'code', 'label', 'label_i18n', 'soll_credit', 'max_days', 'color', 'icon', 'active', 'created_at', 'created_by', 'updated_at', 'updated_by'] as $col) {
			self::assertTrue($table->hasColumn($col), "missing column $col");
		}
		self::assertSame(['id'], $table->getPrimaryKey()->getColumns());
		$indexNames = array_map(static fn ($idx): string => $idx->getName(), $table->getIndexes());
		self::assertContains('at_cabs_code_uk', $indexNames);
		$codeIndex = null;
		foreach ($table->getIndexes() as $idx) {
			if ($idx->getName() === 'at_cabs_code_uk') {
				$codeIndex = $idx;
			}
		}
		self::assertNotNull($codeIndex);
		self::assertTrue($codeIndex->isUnique(), 'code must be unique (slug collisions are a race target)');

		// at_absences.type must fit custom identifiers (was VARCHAR(20)).
		$absences = $schema->getTable($this->prefixedTable($schema, 'at_absences'));
		self::assertSame(64, $absences->getColumn('type')->getLength());
	}

	public function testCreateMergesIntoCatalog(): void
	{
		$result = $this->service->create([
			'label' => 'Betriebsausflug IT',
			'labelMap' => ['en' => 'Company outing IT'],
			'sollCredit' => true,
			'maxDays' => '3',
			'color' => 'success',
			'icon' => 'users',
		], 'it-admin');
		self::assertSame(201, $result['code'], json_encode($result));
		$id = (int)$result['data']['id'];
		$this->createdTypeIds[] = $id;
		$code = (string)$result['data']['code'];
		self::assertMatchesRegularExpression('/^custom_betriebsausflug_it(_\d+)?$/', $code);

		// Catalog merge — after reset so the fresh row is loaded.
		AbsenceTypeCatalog::resetCustomTypeCache();
		self::assertTrue(AbsenceTypeCatalog::isValid($code));
		self::assertTrue(AbsenceTypeCatalog::isCreatable($code));
		self::assertSame(3, AbsenceTypeCatalog::maxCalendarDays($code));
		self::assertSame('success', AbsenceTypeCatalog::color($code));
		self::assertSame('users', AbsenceTypeCatalog::icon($code));
		self::assertTrue(AbsenceTypeCatalog::isSollCreditable($code));
		self::assertNotNull(AbsenceTypeCatalog::resolvedLabel($code));
	}

	public function testDeactivateKeepsValidButBlocksCreatable(): void
	{
		$result = $this->service->create(['label' => 'Blutspende'], 'it-admin');
		$id = (int)$result['data']['id'];
		$this->createdTypeIds[] = $id;
		$code = (string)$result['data']['code'];

		$update = $this->service->update($id, ['active' => 'false'], 'it-admin');
		self::assertSame(200, $update['code']);

		AbsenceTypeCatalog::resetCustomTypeCache();
		self::assertTrue(AbsenceTypeCatalog::isValid($code), 'history must keep rendering');
		self::assertFalse(AbsenceTypeCatalog::isCreatable($code), 'deactivated must block new requests');
	}

	public function testDeleteUnusedVsUsed(): void
	{
		// Unused type → hard delete.
		$r1 = $this->service->create(['label' => 'Umzugstag'], 'it-admin');
		$id1 = (int)$r1['data']['id'];
		// don't register for cleanup — the service deletes it
		$d1 = $this->service->deleteOrDeactivate($id1, 'it-admin');
		self::assertSame('deleted', $d1['data']['mode']);
		$this->createdTypeIds[] = $id1; // cleanup is a no-op if already gone

		// Used type → deactivation only.
		$r2 = $this->service->create(['label' => 'Beerdigung'], 'it-admin');
		$id2 = (int)$r2['data']['id'];
		$this->createdTypeIds[] = $id2;
		$code2 = (string)$r2['data']['code'];
		$this->insertAbsence($code2);

		$d2 = $this->service->deleteOrDeactivate($id2, 'it-admin');
		self::assertSame('deactivated', $d2['data']['mode']);
		self::assertSame(1, $d2['data']['usage']);

		AbsenceTypeCatalog::resetCustomTypeCache();
		self::assertTrue(AbsenceTypeCatalog::isValid($code2), 'absence history must still resolve');
	}

	public function testSummaryCarriesResolvedMetadata(): void
	{
		$result = $this->service->create(['label' => 'Az-Spezialtag', 'color' => 'warning', 'icon' => 'heart-handshake'], 'it-admin');
		$this->createdTypeIds[] = (int)$result['data']['id'];
		$code = (string)$result['data']['code'];
		$id = $this->insertAbsence($code);

		$summary = $this->absenceMapper->find($id)->getSummary();
		self::assertSame($code, $summary['type']);
		self::assertSame('warning', $summary['typeColor']);
		self::assertSame('heart-handshake', $summary['typeIcon']);
		self::assertNotEmpty($summary['typeLabel']);
	}

	public function testTypeColumnStoresLongIdentifiers(): void
	{
		// VARCHAR(64) widening — a 40+ char slug must survive round-trip.
		$longSlug = 'averylongcustomtypename_for_integration';
		$result = $this->service->create(['label' => 'X', 'slug' => $longSlug], 'it-admin');
		$this->createdTypeIds[] = (int)$result['data']['id'];
		$code = (string)$result['data']['code'];
		self::assertSame('custom_' . $longSlug, $code);

		$id = $this->insertAbsence($code);
		$row = $this->absenceMapper->find($id);
		self::assertSame($code, $row->getType(), 'long custom code must not be truncated in at_absences.type');
	}

	/**
	 * TOCTOU guard: admin delete/deactivate runs the usage-count → delete
	 * sequence under an EXCLUSIVE catalog lock; absence create/update paths
	 * hold the SHARED twin while validating+writing a `custom_*` type. Prove
	 * both sides contend on the same key, so a delete can never interleave
	 * between a create's isCreatable() check and its insert.
	 */
	public function testCatalogLockMutualExclusion(): void
	{
		$locking = \OC::$server->get(ILockingProvider::class);
		$key = DbLockKeys::absenceTypeCatalog();
		$absenceService = \OC::$server->get(AbsenceService::class);

		$r = $this->service->create(['label' => 'Katalogsperre'], 'it-admin');
		$id = (int)$r['data']['id'];
		$this->createdTypeIds[] = $id;
		$code = (string)$r['data']['code'];
		AbsenceTypeCatalog::resetCustomTypeCache();

		// Reader (absence-write) side holds SHARED → admin delete must refuse
		// with the retryable-409 contract (never an opaque 500).
		$locking->acquireLock($key, ILockingProvider::LOCK_SHARED, 'test reader side');
		$deleteRefused = false;
		try {
			try {
				$result = $this->service->deleteOrDeactivate($id, 'it-admin');
				$deleteRefused = $result['code'] === 409 && ($result['error'] ?? '') !== '';
			} catch (LockedException $e) {
				$deleteRefused = true;
			}
		} finally {
			$locking->releaseLock($key, ILockingProvider::LOCK_SHARED);
		}
		self::assertTrue($deleteRefused, 'deleteOrDeactivate must refuse while a shared catalog lock is held');

		// Admin (writer) side holds EXCLUSIVE → custom-type absence create must refuse.
		$locking->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE, 'test writer side');
		$createRefused = false;
		$updateRefused = false;
		try {
			try {
				$absenceService->createAbsence([
					'type' => $code,
					'start_date' => '2026-10-21',
					'end_date' => '2026-10-21',
					'reason' => 'lock probe',
				], '__azct_integration__');
			} catch (CatalogBusyException | LockedException $e) {
				$createRefused = true;
			}
			// Same on the update path (type switch to a custom code).
			$absenceId = $this->insertAbsence(Absence::TYPE_PERSONAL_LEAVE);
			try {
				$absenceService->updateAbsence($absenceId, [
					'type' => $code,
					'start_date' => '2026-10-21',
					'end_date' => '2026-10-21',
				], '__azct_integration__');
			} catch (CatalogBusyException | LockedException $e) {
				$updateRefused = true;
			}
		} finally {
			$locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
		self::assertTrue($createRefused, 'createAbsence must refuse while the exclusive catalog lock is held');
		self::assertTrue($updateRefused, 'updateAbsence must refuse while the exclusive catalog lock is held');

		// Built-in type must NOT contend on the catalog lock at all.
		$locking->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE, 'test writer side');
		$builtinBlocked = false;
		try {
			try {
				$absenceService->createAbsence([
					'type' => Absence::TYPE_PERSONAL_LEAVE,
					'start_date' => '2026-10-22',
					'end_date' => '2026-10-22',
					'reason' => 'lock-free path',
				], '__azct_integration__');
			} catch (LockedException $e) {
				$builtinBlocked = true;
			} catch (\Throwable $e) {
				// domain validation failure is fine — the lock was skipped
			}
		} finally {
			$locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
		self::assertFalse($builtinBlocked, 'built-in absence types must not acquire the catalog lock');
	}
}
