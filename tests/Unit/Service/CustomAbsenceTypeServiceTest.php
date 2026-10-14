<?php

declare(strict_types=1);

/**
 * Unit tests for CustomAbsenceTypeService — validation, slug resolution,
 * delete-vs-deactivate semantics, audit writes.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\CustomAbsenceType;
use OCA\ArbeitszeitCheck\Db\CustomAbsenceTypeMapper;
use OCA\ArbeitszeitCheck\Service\CustomAbsenceTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CustomAbsenceTypeServiceTest extends TestCase
{
	private CustomAbsenceTypeMapper&MockObject $mapper;
	private AuditLogMapper&MockObject $auditLogMapper;
	private IDBConnection&MockObject $db;
	private CustomAbsenceTypeService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = $this->createMock(CustomAbsenceTypeMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->db = $this->createMock(IDBConnection::class);

		// TTransactional uses begin/commit/rollBack — stub them as no-ops.
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->db->method('rollBack');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $text, array $params = []) {
			foreach ($params as $i => $p) {
				$text = str_replace('%' . ($i + 1) . '$s', (string)$p, str_replace('%d', (string)$p, $text));
			}
			return $text;
		});

		$this->service = new CustomAbsenceTypeService(
			$this->mapper,
			$this->auditLogMapper,
			$this->db,
			$l10n,
			$this->createMock(\OCP\Lock\ILockingProvider::class),
		);
	}

	private function makeRow(array $overrides = []): CustomAbsenceType
	{
		$row = new CustomAbsenceType();
		$row->setId($overrides['id'] ?? 7);
		$row->setCode($overrides['code'] ?? 'custom_betriebsausflug');
		$row->setLabel($overrides['label'] ?? 'Betriebsausflug');
		$row->setLabelMap($overrides['labelMap'] ?? []);
		$row->setSollCredit($overrides['sollCredit'] ?? false);
		$row->setMaxDays($overrides['maxDays'] ?? 365);
		$row->setColor($overrides['color'] ?? 'info');
		$row->setIcon($overrides['icon'] ?? 'calendar');
		$row->setActive($overrides['active'] ?? true);
		$row->setCreatedAt(new \DateTime('2026-10-01'));
		$row->setCreatedBy('admin');
		return $row;
	}

	public function testCreateHappyPathDerivesSlugAndAudits(): void
	{
		$this->mapper->method('codeExists')->willReturn(false);
		$this->mapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (CustomAbsenceType $t) {
				self::assertSame('custom_betriebsausflug', $t->getCode());
				self::assertSame('Betriebsausflug', $t->getLabel());
				self::assertTrue($t->getSollCredit());
				self::assertSame(2, $t->getMaxDays());
				self::assertSame('success', $t->getColor());
				self::assertTrue($t->getActive());
				return $t;
			});
		$this->auditLogMapper->expects(self::once())
			->method('logAction')
			->with('admin', CustomAbsenceTypeService::ACTION_CREATED, 'custom_absence_type', self::anything(), null, self::anything(), 'admin');

		$result = $this->service->create([
			'label' => 'Betriebsausflug',
			'labelMap' => ['de' => 'Betriebsausflug', 'en' => 'Company outing'],
			'sollCredit' => 'true',
			'maxDays' => '2',
			'color' => 'success',
		], 'admin');

		self::assertSame(201, $result['code']);
		self::assertSame('custom_betriebsausflug', $result['data']['code']);
	}

	public function testCreateTranslatesUmlautsInSlug(): void
	{
		$this->mapper->method('codeExists')->willReturn(false);
		$this->mapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (CustomAbsenceType $t) {
				self::assertMatchesRegularExpression('/^custom_[a-z0-9_]+$/', $t->getCode());
				self::assertStringNotContainsString('ä', $t->getCode());
				return $t;
			});
		$this->auditLogMapper->method('logAction')->willReturn(new \OCA\ArbeitszeitCheck\Db\AuditLog());

		$result = $this->service->create(['label' => 'Pflegefälle Übung'], 'admin');
		self::assertSame(201, $result['code']);
	}

	public function testCreateRejectsMissingLabel(): void
	{
		$this->mapper->expects(self::never())->method('insert');
		$result = $this->service->create(['label' => '  '], 'admin');
		self::assertSame(400, $result['code']);
		self::assertArrayHasKey('label', $result['errors']);
	}

	public function testCreateRejectsLabelOver80Chars(): void
	{
		$result = $this->service->create(['label' => str_repeat('a', 81)], 'admin');
		self::assertSame(400, $result['code']);
		self::assertArrayHasKey('label', $result['errors']);
	}

	public function testCreateRejectsLabelWithHtmlBrackets(): void
	{
		$this->mapper->expects(self::never())->method('insert');
		$result = $this->service->create(['label' => '<script>alert(1)</script>'], 'admin');
		self::assertSame(400, $result['code']);
		self::assertArrayHasKey('label', $result['errors']);
	}

	public function testCreateExplicitSlugCollisionReturns409(): void
	{
		$this->mapper->method('codeExists')->willReturn(true);
		$this->mapper->expects(self::never())->method('insert');
		$result = $this->service->create(['label' => 'X', 'slug' => 'betriebsausflug'], 'admin');
		self::assertSame(409, $result['code']);
		self::assertArrayHasKey('code', $result['errors']);
	}

	public function testCreateDerivedSlugCollisionAutoSuffixes(): void
	{
		$this->mapper->method('codeExists')
			->willReturnCallback(static fn (string $code): bool => $code !== 'custom_betriebsausflug_2');
		$this->mapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (CustomAbsenceType $t) {
				self::assertSame('custom_betriebsausflug_2', $t->getCode());
				return $t;
			});
		$this->auditLogMapper->method('logAction')->willReturn(new \OCA\ArbeitszeitCheck\Db\AuditLog());

		$result = $this->service->create(['label' => 'Betriebsausflug'], 'admin');
		self::assertSame(201, $result['code']);
	}

	public function testCreateRejectsMalformedSlug(): void
	{
		$result = $this->service->create(['label' => 'X', 'slug' => '-bad_slug-'], 'admin');
		self::assertSame(400, $result['code']);
		self::assertArrayHasKey('code', $result['errors']);
	}

	public function testCreateRejectsInvalidColorAndIcon(): void
	{
		$result = $this->service->create([
			'label' => 'X',
			'color' => 'hotpink',
			'icon' => 'skull',
		], 'admin');
		self::assertSame(400, $result['code']);
		self::assertArrayHasKey('color', $result['errors']);
		self::assertArrayHasKey('icon', $result['errors']);
	}

	public function testCreateRejectsInvalidMaxDays(): void
	{
		foreach (['0', '-1', 'abc', '3651'] as $bad) {
			$result = $this->service->create(['label' => 'X', 'maxDays' => $bad], 'admin');
			self::assertSame(400, $result['code'], "maxDays=$bad must fail");
			self::assertArrayHasKey('maxDays', $result['errors']);
		}
	}

	public function testCreateRejectsInvalidLabelMapLocaleKey(): void
	{
		$result = $this->service->create([
			'label' => 'X',
			'labelMap' => ['not a locale' => 'text'],
		], 'admin');
		self::assertSame(400, $result['code']);
		self::assertArrayHasKey('labelMap', $result['errors']);
	}

	public function testCreateRejectsLabelMapHtmlValue(): void
	{
		$result = $this->service->create([
			'label' => 'X',
			'labelMap' => ['de' => '<b>fett</b>'],
		], 'admin');
		self::assertSame(400, $result['code']);
		self::assertArrayHasKey('labelMap', $result['errors']);
	}

	public function testCreateFormEncodedFalseIsNotTruthy(): void
	{
		// "false" arrives as a string from form-encoded clients — must not
		// silently enable the credit flag (API security rule §4).
		$this->mapper->method('codeExists')->willReturn(false);
		$this->mapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (CustomAbsenceType $t) {
				self::assertFalse($t->getSollCredit());
				return $t;
			});
		$this->auditLogMapper->method('logAction')->willReturn(new \OCA\ArbeitszeitCheck\Db\AuditLog());

		$result = $this->service->create(['label' => 'X', 'sollCredit' => 'false'], 'admin');
		self::assertSame(201, $result['code']);
	}

	public function testUpdateReturns404WhenMissing(): void
	{
		$this->mapper->method('find')->willThrowException(new DoesNotExistException(''));
		$result = $this->service->update(99, ['label' => 'X'], 'admin');
		self::assertSame(404, $result['code']);
	}

	public function testUpdateAppliesPartialChangesAndAudits(): void
	{
		$row = $this->makeRow();
		$this->mapper->method('find')->willReturn($row);
		$this->mapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (CustomAbsenceType $t) {
				self::assertSame('Firmenausflug', $t->getLabel());
				self::assertFalse($t->getActive());
				// Untouched fields keep their values.
				self::assertSame('custom_betriebsausflug', $t->getCode());
				self::assertSame(365, $t->getMaxDays());
				return $t;
			});
		$this->auditLogMapper->expects(self::once())
			->method('logAction')
			->with('admin', CustomAbsenceTypeService::ACTION_UPDATED, 'custom_absence_type', 7, self::anything(), self::anything(), 'admin');

		$result = $this->service->update(7, ['label' => 'Firmenausflug', 'active' => 'false'], 'admin');
		self::assertSame(200, $result['code']);
	}

	public function testUpdateDoesNotResurrectActiveByDefault(): void
	{
		$row = $this->makeRow(['active' => false]);
		$this->mapper->method('find')->willReturn($row);
		$this->mapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (CustomAbsenceType $t) {
				self::assertFalse($t->getActive());
				return $t;
			});
		$this->auditLogMapper->method('logAction')->willReturn(new \OCA\ArbeitszeitCheck\Db\AuditLog());

		$this->service->update(7, ['color' => 'error'], 'admin');
	}

	public function testDeleteUnusedRowHardDeletes(): void
	{
		$row = $this->makeRow();
		$this->mapper->method('find')->willReturn($row);
		$this->mapper->method('countAbsenceUsage')->willReturn(0);
		$this->mapper->expects(self::once())->method('delete');
		$this->mapper->expects(self::never())->method('update');
		$this->auditLogMapper->expects(self::once())
			->method('logAction')
			->with('admin', CustomAbsenceTypeService::ACTION_DELETED, 'custom_absence_type', 7, self::anything(), null, 'admin');

		$result = $this->service->deleteOrDeactivate(7, 'admin');
		self::assertSame(200, $result['code']);
		self::assertSame('deleted', $result['data']['mode']);
	}

	public function testDeleteUsedRowDeactivates(): void
	{
		$row = $this->makeRow();
		$this->mapper->method('find')->willReturn($row);
		$this->mapper->method('countAbsenceUsage')->willReturn(5);
		$this->mapper->expects(self::never())->method('delete');
		$this->mapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (CustomAbsenceType $t) {
				self::assertFalse($t->getActive());
				return $t;
			});
		$this->auditLogMapper->expects(self::once())
			->method('logAction')
			->with('admin', CustomAbsenceTypeService::ACTION_DEACTIVATED, 'custom_absence_type', 7, self::anything(), self::anything(), 'admin');

		$result = $this->service->deleteOrDeactivate(7, 'admin');
		self::assertSame(200, $result['code']);
		self::assertSame('deactivated', $result['data']['mode']);
		self::assertSame(5, $result['data']['usage']);
	}

	public function testDeleteReturns404WhenMissing(): void
	{
		$this->mapper->method('find')->willThrowException(new DoesNotExistException(''));
		$result = $this->service->deleteOrDeactivate(99, 'admin');
		self::assertSame(404, $result['code']);
	}

	public function testCatalogLockContentionReturnsRetryable409(): void
	{
		// Fail-fast lock contention must surface as a distinct retryable 409,
		// never propagate as an opaque 500 (lock_contention_opaque_error).
		$locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$locking->method('acquireLock')
			->willThrowException(new \OCP\Lock\LockedException('absence_type_catalog'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$service = new CustomAbsenceTypeService(
			$this->mapper,
			$this->auditLogMapper,
			$this->db,
			$l10n,
			$locking,
		);

		$this->mapper->expects(self::never())->method('delete');
		$this->mapper->expects(self::never())->method('update');

		$result = $service->deleteOrDeactivate(7, 'admin');
		self::assertSame(409, $result['code']);
		self::assertNull($result['data']);
		self::assertSame(
			'Another change to the absence type catalog is in progress. Please wait a moment and try again.',
			$result['error']
		);
	}
}
