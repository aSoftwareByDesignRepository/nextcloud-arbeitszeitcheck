<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignment;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\AdminUserProfileUpdateService;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Guards the "open & save" employee dialog against the data-integrity bug that
 * produced the "Benutzer konnte nicht aktualisiert werden" report: repeated
 * no-op saves must not accumulate duplicate work-schedule or vacation-policy
 * rows — including for future-dated ("Gültig von" ahead of today) employees.
 */
class AdminUserProfileUpdateIntegrationTest extends TestCase
{
	private const TEST_USER = '__arbeitszeitcheck_profile_int__';

	private IUserManager $userManager;
	private AdminUserProfileUpdateService $service;
	private UserWorkingTimeModelMapper $wtmMapper;
	private UserVacationPolicyAssignmentMapper $policyMapper;
	private WorkingTimeModelMapper $modelMapper;
	private int $modelId;

	protected function setUp(): void
	{
		parent::setUp();
		$this->userManager = \OCP\Server::get(IUserManager::class);
		$this->service = \OCP\Server::get(AdminUserProfileUpdateService::class);
		$this->wtmMapper = \OCP\Server::get(UserWorkingTimeModelMapper::class);
		$this->policyMapper = \OCP\Server::get(UserVacationPolicyAssignmentMapper::class);
		$this->modelMapper = \OCP\Server::get(WorkingTimeModelMapper::class);

		if (!$this->userManager->userExists(self::TEST_USER)) {
			$this->userManager->createUser(self::TEST_USER, bin2hex(random_bytes(16)) . 'Aa1!');
		}

		$models = $this->modelMapper->findAll();
		if ($models === []) {
			$model = new WorkingTimeModel();
			$model->setName('Integration full-time');
			$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
			$model->setWeeklyHours(40.0);
			$model->setDailyHours(8.0);
			$model->setWorkDaysPerWeek(5.0);
			$model = $this->modelMapper->insert($model);
			$this->modelId = (int)$model->getId();
		} else {
			$this->modelId = (int)$models[0]->getId();
		}

		$this->cleanUp();
	}

	protected function tearDown(): void
	{
		$this->cleanUp();
		if ($this->userManager->userExists(self::TEST_USER)) {
			$this->userManager->get(self::TEST_USER)?->delete();
		}
		parent::tearDown();
	}

	private function cleanUp(): void
	{
		$this->policyMapper->deleteByUser(self::TEST_USER);
		foreach ($this->wtmMapper->findByUser(self::TEST_USER) as $row) {
			$this->wtmMapper->delete($row);
		}
		$balanceMapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper::class);
		$balanceMapper->deleteByUserId(self::TEST_USER);
		$otBalanceMapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\UserOvertimeYearBalanceMapper::class);
		$otBalanceMapper->deleteByUserId(self::TEST_USER);
	}

	/**
	 * Mirrors js/admin-users.js buildUpdatePayloads() for a no-op save,
	 * including the policy round-trip (policyId + the policy's own effective_from
	 * when the schedule start has not changed).
	 *
	 * @return array<string, mixed>
	 */
	private function frontendPayload(string $start): array
	{
		$editable = $this->wtmMapper->findEditableByUser(self::TEST_USER);
		$policy = $this->policyMapper->findCurrentByUser(self::TEST_USER);
		$loadedStart = $editable?->getStartDate()?->format('Y-m-d');
		$policyFrom = $policy?->getEffectiveFrom()?->format('Y-m-d');
		$policyId = $policy?->getId();

		$effectiveFrom = $start;
		if ($policyId !== null && $policyFrom !== null && $loadedStart !== null && $start === $loadedStart) {
			$effectiveFrom = $policyFrom;
		}

		return [
			'workingTimeModel' => [
				'workingTimeModelId' => $this->modelId,
				'vacationDaysPerYear' => 28,
				'startDate' => $start,
				'endDate' => null,
				'germanState' => 'NW',
			],
			'vacationPolicy' => [
				'policyId' => $policyId,
				'vacationMode' => Constants::VACATION_MODE_MANUAL_FIXED,
				'inheritLowerLayers' => false,
				'manualDays' => 30.0,
				'effectiveFrom' => $effectiveFrom,
				'effectiveTo' => null,
			],
			'timeCapture' => [
				'clockStampingEnabled' => true,
				'manualTimeEntryEnabled' => true,
			],
			'overtime' => [
				'trackingFrom' => null,
				'openingBalance' => ['year' => (int)date('Y'), 'hours' => '0'],
			],
		];
	}

	public function testRepeatedSaveIsIdempotentForFutureDatedEmployee(): void
	{
		$futureStart = (new \DateTimeImmutable('first day of next month'))->format('Y-m-d');

		$seed = new UserWorkingTimeModel();
		$seed->setUserId(self::TEST_USER);
		$seed->setWorkingTimeModelId($this->modelId);
		$seed->setVacationDaysPerYear(28);
		$seed->setStartDate(new \DateTime($futureStart));
		$seed->setCreatedAt(new \DateTime());
		$seed->setUpdatedAt(new \DateTime());
		$this->wtmMapper->insert($seed);

		$policy = new UserVacationPolicyAssignment();
		$policy->setUserId(self::TEST_USER);
		$policy->setVacationMode(Constants::VACATION_MODE_MANUAL_FIXED);
		$policy->setManualDays(30.0);
		$policy->setEffectiveFrom(new \DateTime('2025-01-01'));
		$policy->setInheritLowerLayers(false);
		$policy->setCreatedBy('integration_test');
		$policy->setCreatedAt(new \DateTime());
		$policy->setUpdatedAt(new \DateTime());
		$this->policyMapper->insert($policy);

		for ($i = 0; $i < 3; $i++) {
			$result = $this->service->updateProfile(self::TEST_USER, $this->frontendPayload($futureStart), 'integration_test');
			$this->assertTrue($result['success']);
		}

		$this->assertCount(
			1,
			$this->wtmMapper->findByUser(self::TEST_USER),
			'A future-dated work-schedule assignment must be updated in place, never duplicated.'
		);
		$this->assertCount(
			1,
			$this->policyMapper->findByUser(self::TEST_USER),
			'A no-op save must not split or duplicate the vacation policy.'
		);
	}

	public function testRepeatedSaveIsIdempotentForActiveEmployee(): void
	{
		$pastStart = (new \DateTimeImmutable('first day of last month'))->format('Y-m-d');

		for ($i = 0; $i < 3; $i++) {
			$result = $this->service->updateProfile(self::TEST_USER, $this->frontendPayload($pastStart), 'integration_test');
			$this->assertTrue($result['success']);
		}

		$this->assertCount(
			1,
			$this->wtmMapper->findByUser(self::TEST_USER),
			'Repeated saves of an active employee must reuse the single assignment row.'
		);
		$this->assertCount(
			1,
			$this->policyMapper->findByUser(self::TEST_USER),
			'Repeated saves of an active employee must reuse the single policy row.'
		);
	}

	/**
	 * Regression for issue #15 (1.3.10): dialog defaulted to manual_fixed with empty
	 * manual days when no L3 policy existed, so save failed even on a no-op edit.
	 */
	public function testInheritModeSaveSucceedsWithoutExistingPolicy(): void
	{
		$start = (new \DateTimeImmutable('first day of last month'))->format('Y-m-d');

		$seed = new UserWorkingTimeModel();
		$seed->setUserId(self::TEST_USER);
		$seed->setWorkingTimeModelId($this->modelId);
		$seed->setVacationDaysPerYear(28);
		$seed->setStartDate(new \DateTime($start));
		$seed->setCreatedAt(new \DateTime());
		$seed->setUpdatedAt(new \DateTime());
		$this->wtmMapper->insert($seed);

		$payload = [
			'workingTimeModel' => [
				'workingTimeModelId' => $this->modelId,
				'vacationDaysPerYear' => 28,
				'startDate' => $start,
				'endDate' => null,
				'germanState' => 'NW',
			],
			'vacationPolicy' => [
				'policyId' => null,
				'vacationMode' => Constants::VACATION_MODE_INHERIT,
				'inheritLowerLayers' => true,
				'manualDays' => null,
				'effectiveFrom' => $start,
				'effectiveTo' => null,
			],
			'timeCapture' => [
				'clockStampingEnabled' => true,
				'manualTimeEntryEnabled' => true,
			],
			'overtime' => [
				'trackingFrom' => '2025-06-01',
				'openingBalance' => ['year' => (int)date('Y'), 'hours' => '0'],
			],
		];

		$result = $this->service->updateProfile(self::TEST_USER, $payload, 'integration_test');
		$this->assertTrue($result['success']);
		$this->assertSame('2025-06-01', $result['overtimeTrackingFrom']);
	}

	/**
	 * Regression: after a model is already assigned, saving only carryover must
	 * persist (complimant: early-return on unchanged assignment skipped upsert).
	 */
	public function testCarryoverPersistsWhenAssignmentUnchanged(): void
	{
		$start = (new \DateTimeImmutable('first day of last month'))->format('Y-m-d');
		$year = (int)date('Y');

		$seed = new UserWorkingTimeModel();
		$seed->setUserId(self::TEST_USER);
		$seed->setWorkingTimeModelId($this->modelId);
		$seed->setVacationDaysPerYear(28);
		$seed->setStartDate(new \DateTime($start));
		$seed->setCreatedAt(new \DateTime());
		$seed->setUpdatedAt(new \DateTime());
		$this->wtmMapper->insert($seed);

		$balanceMapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper::class);
		$this->assertSame(0.0, $balanceMapper->getCarryoverDays(self::TEST_USER, $year));

		$result = $this->service->applyWorkingTimeModel(self::TEST_USER, [
			'workingTimeModelId' => $this->modelId,
			'vacationDaysPerYear' => 28,
			'startDate' => $start,
			'vacationCarryoverDays' => '4.5',
			'vacationCarryoverYear' => $year,
		], 'integration_test');

		$this->assertArrayHasKey('userWorkingTimeModel', $result);
		$this->assertSame(4.5, $balanceMapper->getCarryoverDays(self::TEST_USER, $year));
		$this->assertSame(4.5, $result['vacationCarryoverDays']);
		$this->assertSame($year, $result['vacationCarryoverYear']);
		$this->assertCount(1, $this->wtmMapper->findByUser(self::TEST_USER));
	}

	public function testOvertimeOpeningBalancePersistsForNonCurrentYear(): void
	{
		$targetYear = (int)date('Y') - 1;
		$overtime = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService::class);

		$result = $this->service->applyOvertimeSettings(self::TEST_USER, [
			'openingBalance' => ['year' => $targetYear, 'hours' => '7.25'],
		], 'integration_test');

		$this->assertSame($targetYear, $result['overtimeOpeningBalanceYear']);
		$this->assertSame(7.25, $result['overtimeOpeningBalanceHours']);
		$this->assertSame(7.25, $overtime->getOpeningBalanceHours(self::TEST_USER, $targetYear));
	}

	/**
	 * Regression for issue #15 (1.3.10–1.3.12): unconverted dd.mm.yyyy in effectiveFrom
	 * must fail validation before any DB write (client converts; this guards the API).
	 */
	public function testRejectUnconvertedEuropeanDateInVacationPolicy(): void
	{
		$start = (new \DateTimeImmutable('first day of last month'))->format('Y-m-d');

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException::class);

		$this->service->updateProfile(self::TEST_USER, [
			'workingTimeModel' => [
				'workingTimeModelId' => $this->modelId,
				'vacationDaysPerYear' => 28,
				'startDate' => $start,
				'endDate' => null,
			],
			'vacationPolicy' => [
				'vacationMode' => Constants::VACATION_MODE_INHERIT,
				'inheritLowerLayers' => true,
				'effectiveFrom' => '27.03.2025',
			],
			'timeCapture' => [
				'clockStampingEnabled' => true,
				'manualTimeEntryEnabled' => true,
			],
			'overtime' => [
				'openingBalance' => ['year' => (int)date('Y'), 'hours' => '0'],
			],
		], 'integration_test');

		$this->assertCount(0, $this->wtmMapper->findByUser(self::TEST_USER));
	}
}
