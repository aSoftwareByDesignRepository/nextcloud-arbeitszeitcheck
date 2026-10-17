<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class WorkforceSuiteConfigTest extends TestCase
{
	public function testUpgradeInitializationIsIdempotentAndPreservesExistingValues(): void
	{
		[$service, $values] = $this->service(['policy_cohorts_enabled' => '1']);
		$service->initialize(WorkforceSuiteConfig::STATE_UPGRADED_LEGACY);
		$service->initialize(WorkforceSuiteConfig::STATE_FRESH);

		self::assertSame(WorkforceSuiteConfig::STATE_UPGRADED_LEGACY, $values[WorkforceSuiteConfig::INSTALL_STATE_KEY]);
		self::assertSame('1', $values['policy_cohorts_enabled']);
		self::assertSame('0', $values['work_location_catalog_enabled']);
		self::assertSame('1', $values['legacy_home_absence_create_enabled']);
		self::assertSame('1', $values['legacy_trip_absence_create_enabled']);
	}

	public function testBooleanParsingDoesNotTreatFalseStringAsEnabled(): void
	{
		[$service] = $this->service([
			'policy_cohorts_enabled' => 'true',
			'work_location_catalog_enabled' => 'false',
		]);

		self::assertTrue($service->isEnabled('policy_cohorts_enabled'));
		self::assertFalse($service->isEnabled('work_location_catalog_enabled'));
	}

	public function testRejectsUnknownFlagsAndNonBooleanUpdates(): void
	{
		[$service] = $this->service();
		$this->expectException(\InvalidArgumentException::class);
		$service->updateFlags(['unknown' => 'false']);
	}

	public function testDependencyBlockersRejectInvalidCombinationBeforeWriting(): void
	{
		[$service, $values] = $this->service();
		$service->initialize(WorkforceSuiteConfig::STATE_UPGRADED_LEGACY);
		$before = $values->getArrayCopy();

		try {
			$service->updateFlags(['special_entitlements_enabled' => true]);
			self::fail('Invalid dependency combination was accepted.');
		} catch (\DomainException $e) {
			self::assertSame('SPECIAL_ENTITLEMENTS_REQUIRE_POLICY_COHORTS', $e->getMessage());
		}
		self::assertSame($before, $values->getArrayCopy());
	}

	public function testValidDependentFlagsArePersisted(): void
	{
		[$service, $values] = $this->service();
		$service->initialize(WorkforceSuiteConfig::STATE_UPGRADED_LEGACY);
		$service->updateFlags([
			'policy_cohorts_enabled' => true,
			'special_entitlements_enabled' => true,
			'business_trips_enabled' => true,
			'business_trip_credit_enabled' => true,
		]);

		self::assertSame('1', $values['policy_cohorts_enabled']);
		self::assertSame('1', $values['special_entitlements_enabled']);
		self::assertSame('1', $values['business_trips_enabled']);
		self::assertSame('1', $values['business_trip_credit_enabled']);
	}

	public function testInstallStateTransitionsAreForwardOnly(): void
	{
		[$service, $values] = $this->service();
		$service->initialize(WorkforceSuiteConfig::STATE_UPGRADED_LEGACY);
		$service->setInstallState(WorkforceSuiteConfig::STATE_PARTIALLY_MIGRATED);
		$service->setInstallState(WorkforceSuiteConfig::STATE_MIGRATED);
		self::assertSame(WorkforceSuiteConfig::STATE_MIGRATED, $values[WorkforceSuiteConfig::INSTALL_STATE_KEY]);

		$this->expectException(\DomainException::class);
		$service->setInstallState(WorkforceSuiteConfig::STATE_UPGRADED_LEGACY);
	}

	private function service(array $initial = []): array
	{
		$values = new \ArrayObject($initial);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($values): string {
				self::assertSame(WorkforceSuiteConfig::APP_ID, $app);
				return $values[$key] ?? $default;
			}
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($values): void {
				self::assertSame(WorkforceSuiteConfig::APP_ID, $app);
				$values[$key] = $value;
			}
		);
		return [new WorkforceSuiteConfig($config), $values];
	}
}
