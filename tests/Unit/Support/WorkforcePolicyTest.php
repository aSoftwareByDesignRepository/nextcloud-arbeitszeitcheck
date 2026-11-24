<?php

declare(strict_types=1);

/**
 * Unit tests for WorkforcePolicy — the normalized value object behind the
 * optional-workforce feature gates (§9, ADR-01/ADR-04).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\WorkforcePolicy;
use PHPUnit\Framework\TestCase;

class WorkforcePolicyTest extends TestCase
{
	public function testDefaultsAreAllOff(): void
	{
		$policy = WorkforcePolicy::defaults('UTC');
		$arr = $policy->toArray();
		$this->assertFalse($arr['pastCancellation']['enabled']);
		$this->assertFalse($arr['pastCancellation']['employeeRequests']);
		$this->assertFalse($arr['pastCancellation']['scopedManagerApproval']);
		$this->assertSame([], $arr['hourlyAbsenceTypes']);
		$this->assertFalse($arr['yearEnd']['manualEnabled']);
		$this->assertFalse($arr['yearEnd']['annualEnabled']);
		$this->assertFalse($arr['yearEnd']['resetPositive']);
		$this->assertFalse($arr['yearEnd']['waiveNegative']);
		$this->assertSame([], $arr['yearEnd']['targetGroupIds']);
		$this->assertSame('UTC', $arr['yearEnd']['timezone']);
	}

	public function testFromArrayToleratesMissingKeys(): void
	{
		$policy = WorkforcePolicy::fromArray([], 'Europe/Berlin');
		$this->assertSame(WorkforcePolicy::defaults('Europe/Berlin')->toArray(), $policy->toArray());
	}

	public function testFromArrayDropsUnknownHourlyTypes(): void
	{
		$policy = WorkforcePolicy::fromArray([
			'hourlyAbsenceTypes' => ['unpaid_leave', 'not_a_type', 'overtime_comp', 42],
		], 'UTC');
		$this->assertSame(['unpaid_leave', 'overtime_comp'], $policy->toArray()['hourlyAbsenceTypes']);
	}

	public function testFromArrayDeduplicatesHourlyTypes(): void
	{
		$policy = WorkforcePolicy::fromArray([
			'hourlyAbsenceTypes' => ['unpaid_leave', 'unpaid_leave'],
		], 'UTC');
		$this->assertSame(['unpaid_leave'], $policy->toArray()['hourlyAbsenceTypes']);
	}

	public function testFromArrayNonArrayHourlyTypesBecomesEmpty(): void
	{
		$policy = WorkforcePolicy::fromArray(['hourlyAbsenceTypes' => 'unpaid_leave'], 'UTC');
		$this->assertSame([], $policy->toArray()['hourlyAbsenceTypes']);
	}

	public function testFromArrayFiltersTargetGroups(): void
	{
		$policy = WorkforcePolicy::fromArray([
			'yearEnd' => ['targetGroupIds' => [' admins ', '', 7, 'sales']],
		], 'UTC');
		$this->assertSame(['admins', 'sales'], $policy->toArray()['yearEnd']['targetGroupIds']);
	}

	public function testFromArrayEmptyTimezoneFallsBackToStorage(): void
	{
		$policy = WorkforcePolicy::fromArray(['yearEnd' => ['timezone' => '']], 'Europe/Berlin');
		$this->assertSame('Europe/Berlin', $policy->toArray()['yearEnd']['timezone']);
	}

	public function testIsHourlyTypeEnabled(): void
	{
		$policy = WorkforcePolicy::fromArray(['hourlyAbsenceTypes' => ['unpaid_leave']], 'UTC');
		$this->assertTrue($policy->isHourlyTypeEnabled('unpaid_leave'));
		$this->assertFalse($policy->isHourlyTypeEnabled('overtime_comp'));
		$this->assertFalse($policy->isHourlyTypeEnabled(''));
	}

	public function testCapabilitiesContractVersion(): void
	{
		$policy = WorkforcePolicy::defaults('UTC');
		$this->assertSame(2, $policy->capabilities(true, true)['absenceContractVersion']);
	}

	public function testCapabilitiesAreRoleScoped(): void
	{
		$policy = WorkforcePolicy::fromArray([
			'pastCancellation' => [
				'enabled' => true,
				'employeeRequests' => true,
				'scopedManagerApproval' => true,
			],
			'yearEnd' => ['manualEnabled' => true, 'annualEnabled' => true],
		], 'UTC');

		$employee = $policy->capabilities(false, false);
		$this->assertTrue($employee['pastCorrectionEnabled']);
		$this->assertTrue($employee['employeeCorrectionRequestsEnabled']);
		$this->assertFalse($employee['scopedManagerCorrectionApprovalEnabled']);
		$this->assertFalse($employee['yearEndAdminEnabled']);
		$this->assertFalse($employee['yearEndAnnualEnabled']);

		$manager = $policy->capabilities(false, true);
		$this->assertTrue($manager['scopedManagerCorrectionApprovalEnabled']);
		$this->assertFalse($manager['yearEndAdminEnabled']);

		$admin = $policy->capabilities(true, false);
		$this->assertTrue($admin['yearEndAdminEnabled']);
		$this->assertTrue($admin['yearEndAnnualEnabled']);
	}

	public function testCapabilitiesSubGatesRequireParentFlag(): void
	{
		// employeeRequests without the parent flag never leaks as enabled.
		$policy = WorkforcePolicy::fromArray([
			'pastCancellation' => ['enabled' => false, 'employeeRequests' => true],
			'yearEnd' => ['manualEnabled' => true],
		], 'UTC');
		$caps = $policy->capabilities(true, true);
		$this->assertFalse($caps['employeeCorrectionRequestsEnabled']);
		$this->assertTrue($caps['yearEndAdminEnabled']);
	}

	public function testToArrayFromArrayRoundTrip(): void
	{
		$raw = [
			'pastCancellation' => [
				'enabled' => true,
				'employeeRequests' => true,
				'scopedManagerApproval' => false,
			],
			'hourlyAbsenceTypes' => ['unpaid_leave', 'overtime_comp'],
			'yearEnd' => [
				'manualEnabled' => true,
				'annualEnabled' => false,
				'resetPositive' => true,
				'waiveNegative' => false,
				'targetGroupIds' => ['g1'],
				'timezone' => 'Europe/Berlin',
			],
		];
		$policy = WorkforcePolicy::fromArray($raw, 'UTC');
		$this->assertSame($policy->toArray(), WorkforcePolicy::fromArray($policy->toArray(), 'UTC')->toArray());
		$this->assertSame($raw['hourlyAbsenceTypes'], $policy->toArray()['hourlyAbsenceTypes']);
	}
}
