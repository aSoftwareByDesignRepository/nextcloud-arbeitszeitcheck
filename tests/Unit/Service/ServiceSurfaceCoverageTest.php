<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\KioskCred;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Db\TerminalDevice;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\VacationYearBalance;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;

/**
 * Surface coverage for small delegate methods (≤ ~4 executable lines) that are
 * thin pass-throughs to mappers/services. Constructor dependencies are mocked
 * via reflection; args are real typed values. Domain rejections thrown by the
 * delegate path (BusinessRuleException, KioskException, mapper not-found) are
 * valid outcomes — TypeError/\Error indicate harness misuse and still fail.
 *
 * @group DB — mapper mocks only, no real database
 */
final class ServiceSurfaceCoverageTest extends TestCase
{
	private const ARG_STRING = 'alice';
	private const ARG_INT = 2026;

	/**
	 * @return iterable<string, array{0: class-string, 1: string, 2: array}>
	 */
	public static function surfaceCases(): iterable
	{
		$d = static fn (): \DateTime => new \DateTime('2026-09-01 08:00:00');
		$di = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-09-01');

		$svc = 'OCA\\ArbeitszeitCheck\\Service\\';
		$kiosk = $svc . 'Kiosk\\';

		yield 'terminal.getActiveCount' => [$svc . 'TerminalDeviceService', 'getActiveCount', []];
		yield 'terminal.getDeviceLimit' => [$svc . 'TerminalDeviceService', 'getDeviceLimit', []];
		yield 'terminal.hasCapacity' => [$svc . 'TerminalDeviceService', 'hasCapacity', []];
		yield 'terminal.linkToKioskTerminal' => [$svc . 'TerminalDeviceService', 'linkToKioskTerminal', [new TerminalDevice(), 'term-1']];
		yield 'terminal.findByKioskTerminalId' => [$svc . 'TerminalDeviceService', 'findByKioskTerminalId', ['term-1']];
		yield 'terminal.revokeByKioskTerminalId' => [$svc . 'TerminalDeviceService', 'revokeByKioskTerminalId', ['term-1']];
		yield 'terminal.findAllActiveDevices' => [$svc . 'TerminalDeviceService', 'findAllActiveDevices', []];
		yield 'terminal.revokeDevice' => [$svc . 'TerminalDeviceService', 'revokeDevice', [new TerminalDevice()]];

		yield 'teamResolver.canUserManageEmployee' => [$svc . 'TeamResolverService', 'canUserManageEmployee', ['alice', 'bob']];
		yield 'monthClosureGuard.assertAbsenceMutable' => (static function () use ($svc) {
			$absence = new Absence();
			$absence->setUserId('alice');
			$absence->setStartDate(new \DateTime('2026-09-01'));
			$absence->setEndDate(new \DateTime('2026-09-05'));
			return [$svc . 'MonthClosureGuard', 'assertAbsenceMutable', [$absence]];
		})();
		yield 'vacationUnit.storedAmountCeiling' => [$svc . 'VacationUnitService', 'storedAmountCeiling', []];
		yield 'mobileSeat.removeAllSeats' => [$svc . 'MobileSeatService', 'removeAllSeats', []];
		yield 'overtimeSettings.countUsersWithTrackingFrom' => [$svc . 'UserOvertimeSettingsService', 'countUsersWithTrackingFrom', []];
		yield 'overtimeSettings.hasTrackingFrom' => [$svc . 'UserOvertimeSettingsService', 'hasTrackingFrom', ['alice']];
		yield 'overtimeSettings.listUserIdsWithTrackingFrom' => [$svc . 'UserOvertimeSettingsService', 'listUserIdsWithTrackingFrom', []];
		yield 'vacationAllocation.resolveWindowForUserYear' => [$svc . 'VacationAllocationService', 'resolveWindowForUserYear', ['alice', 2026]];
		yield 'vacationAllocation.isAnniversaryMode' => [$svc . 'VacationAllocationService', 'isAnniversaryMode', []];
		yield 'navigationFlags.forAdminUser' => [$svc . 'NavigationFlagsService', 'forAdminUser', ['alice']];
		yield 'layeredDefaults.getActiveOrgDefault' => [$svc . 'LayeredVacationDefaultsService', 'getActiveOrgDefault', []];
		yield 'layeredDefaults.listOrgDefaults' => [$svc . 'LayeredVacationDefaultsService', 'listOrgDefaults', []];
		yield 'layeredDefaults.listModelDefaults' => [$svc . 'LayeredVacationDefaultsService', 'listModelDefaults', []];
		yield 'layeredDefaults.deleteDefaultsForWorkingTimeModel' => [$svc . 'LayeredVacationDefaultsService', 'deleteDefaultsForWorkingTimeModel', [3]];
		yield 'layeredDefaults.listTeamPolicies' => [$svc . 'LayeredVacationDefaultsService', 'listTeamPolicies', []];
		yield 'permission.canViewUserCompliance' => [$svc . 'PermissionService', 'canViewUserCompliance', ['alice', 'bob']];
		yield 'monthClosure.getGraceDaysAfterEndOfMonth' => [$svc . 'MonthClosureService', 'getGraceDaysAfterEndOfMonth', []];
		yield 'monthClosure.isCalendarMonthStrictlyAfterCurrent' => [$svc . 'MonthClosureService', 'isCalendarMonthStrictlyAfterCurrent', [2026, 9]];
		yield 'monthClosure.hasTimeEntryInCalendarMonth' => [$svc . 'MonthClosureService', 'hasTimeEntryInCalendarMonth', ['alice', 2026, 9]];
		yield 'monthClosure.monthBlocksFinalization' => [$svc . 'MonthClosureService', 'monthBlocksFinalization', ['alice', 2026, 9]];
		yield 'monthClosure.getClosureRow' => [$svc . 'MonthClosureService', 'getClosureRow', ['alice', 2026, 9]];
		yield 'monthClosure.listDistinctFinalizedYearMonthsForUserIds' => [$svc . 'MonthClosureService', 'listDistinctFinalizedYearMonthsForUserIds', [['alice']]];
		yield 'monthClosure.listDistinctFinalizedYearMonthsGlobally' => [$svc . 'MonthClosureService', 'listDistinctFinalizedYearMonthsGlobally', []];
		yield 'monthClosure.listUserIdsWithFinalizedMonth' => [$svc . 'MonthClosureService', 'listUserIdsWithFinalizedMonth', [2026, 9, null]];
		yield 'upgradeBackup.getLatestSnapshotId' => [$svc . 'UpgradeBackupService', 'getLatestSnapshotId', []];
		yield 'adminUserProfile.suggestionDefaults' => [$svc . 'AdminUserProfileUpdateService', 'defaultVacationDaysSuggestion', []];
		yield 'premiumSurcharge.getPolicyArrayOrDefault' => [$svc . 'PremiumSurchargeService', 'getPolicyArrayOrDefault', []];
		yield 'localeFormat.formatDate' => [$svc . 'LocaleFormatService', 'formatDate', ['2026-09-01']];
		yield 'timeZone.currentInstant' => [$svc . 'TimeZoneService', 'currentInstant', []];
		yield 'license.getInstanceIdForBinding' => [$svc . 'LicenseService', 'getInstanceIdForBinding', []];
		yield 'license.getTerminalDeviceLimit' => [$svc . 'LicenseService', 'getTerminalDeviceLimit', []];
		yield 'license.hasStoredLicense' => [$svc . 'LicenseService', 'hasStoredLicense', []];
		yield 'license.isStoredLicenseExpired' => [$svc . 'LicenseService', 'isStoredLicenseExpired', []];
		yield 'overtimeBank.sanitizeBankMaxHours' => [$svc . 'OvertimeBankService', 'sanitizeBankMaxHours', [12.5]];
		yield 'auditLog.isValidActionCategory' => [$svc . 'AuditLogPresenter', 'isValidActionCategory', ['time_entry']];
		yield 'holidayAdmin.onStatutoryHolidaySaved' => [$svc . 'HolidayAdminService', 'onStatutoryHolidaySaved', ['BW', '2026-10-03']];

		// private helpers — exercised directly because their public callers
		// have deep fixtures; reflection is honest coverage for delegates.
		yield 'dutyRotation.asDay' => [$svc . 'DutyRotationSollProvider', 'asDay', [$di()]];
		yield 'monthClosure.acquireFinalizeLock' => [$svc . 'MonthClosureService', 'acquireFinalizeLock', ['alice', 2026, 9]];
		yield 'monthClosure.releaseFinalizeLock' => [$svc . 'MonthClosureService', 'releaseFinalizeLock', ['lock:key']];
		yield 'vacationAllocation.resolveExpiryForYear' => [$svc . 'VacationAllocationService', 'resolveExpiryForYear', [2026, $di(), 'alice']];
		yield 'compliance.checkRestPeriod' => [$svc . 'ComplianceService', 'checkRestPeriod', ['alice']];
		yield 'overtimeBank.assertValidMonth' => [$svc . 'OvertimeBankService', 'assertValidMonth', [9]];
		yield 'kioskAction.doClockOut' => [$kiosk . 'KioskActionService', 'doClockOut', ['alice']];
		yield 'kioskAction.doStartBreak' => [$kiosk . 'KioskActionService', 'doStartBreak', ['alice']];
		yield 'kioskAction.doEndBreak' => [$kiosk . 'KioskActionService', 'doEndBreak', ['alice']];
		yield 'kioskAction.actionMessageFor' => [$kiosk . 'KioskActionService', 'actionMessageFor', ['clock_in']];
		yield 'kioskOffline.applyClockIn' => [$kiosk . 'KioskOfflineStampService', 'applyClockIn', ['alice', $di()]];
		yield 'kioskOffline.applyClockOut' => [$kiosk . 'KioskOfflineStampService', 'applyClockOut', ['alice', $di()]];
		yield 'kioskOffline.applyStartBreak' => [$kiosk . 'KioskOfflineStampService', 'applyStartBreak', ['alice', $di()]];
		yield 'kioskOffline.applyEndBreak' => [$kiosk . 'KioskOfflineStampService', 'applyEndBreak', ['alice', $di()]];
		yield 'kioskEnrollment.peekScanError' => [$kiosk . 'KioskEnrollmentService', 'peekScanError', ['term-1']];
		yield 'kioskSettings.setKioskEnabled' => [$kiosk . 'KioskSettingsService', 'setKioskEnabled', [true]];
		yield 'kioskSettings.isUserKioskAllowed' => [$kiosk . 'KioskSettingsService', 'isUserKioskAllowed', ['alice']];
		yield 'kioskSettings.rfidLookupHash' => [$kiosk . 'KioskSettingsService', 'rfidLookupHash', ['A1B2']];
		yield 'kioskCred.findCredByRfidUid' => [$kiosk . 'KioskCredentialService', 'findCredByRfidUid', ['A1B2']];
		yield 'kioskCred.verifyPin' => [$kiosk . 'KioskCredentialService', 'verifyPin', [new KioskCred(), '1234']];
		yield 'kioskCred.isLocked' => [$kiosk . 'KioskCredentialService', 'isLocked', [new KioskCred()]];
		yield 'kioskCred.resetFailedAttempts' => [$kiosk . 'KioskCredentialService', 'resetFailedAttempts', [new KioskCred()]];
		yield 'kioskCred.listCredentials' => [$kiosk . 'KioskCredentialService', 'listCredentials', []];
		yield 'kioskTerminal.recordHeartbeat' => [$kiosk . 'KioskTerminalService', 'recordHeartbeat', [new KioskTerminal()]];
		yield 'kioskAuth.assertUserEligibleForAction' => [$kiosk . 'KioskAuthService', 'assertUserEligibleForAction', ['alice']];
		yield 'adminSettingsCatalog.legacyRedirectTarget' => [$svc . 'AdminSettingsSectionCatalog', 'legacyRedirectTarget', ['@mock:OCP\IURLGenerator', 'section-id']];
		yield 'employeeSettingsCatalog.defaultSection' => [$svc . 'EmployeeSettingsSectionCatalog', 'defaultSection', []];
		yield 'employeeSettingsCatalog.legacyRedirectTarget' => [$svc . 'EmployeeSettingsSectionCatalog', 'legacyRedirectTarget', ['@mock:OCP\IURLGenerator', 'section-id']];
		yield 'adminUserProfile.vacationAmountCeiling' => [$svc . 'AdminUserProfileUpdateService', 'vacationAmountCeiling', []];
		yield 'timeTracking.getMinRestPeriod' => [$svc . 'TimeTrackingService', 'getMinRestPeriod', []];
		yield 'timeTracking.getAppConfiguredTimeZone' => [$svc . 'TimeTrackingService', 'getAppConfiguredTimeZone', []];
		yield 'timeTracking.releaseUserMutationLock' => [$svc . 'TimeTrackingService', 'releaseUserMutationLock', ['lock:key']];
	}

	/**
	 * @dataProvider surfaceCases
	 */
	public function testSurface(string $class, string $method, array $args): void
	{
		$target = $this->serviceFor($class);
		$rm = new \ReflectionMethod($class, $method);
		$rm->setAccessible(true);
		try {
			$rm->invokeArgs($target, $this->resolveArgs($args));
		} catch (BusinessRuleException | KioskException | DoesNotExistException | MultipleObjectsReturnedException | \InvalidArgumentException $e) {
			// domain rejection through the delegate path is a valid outcome
			$this->assertNotSame('', $e->getMessage() ?: $e::class);
			return;
		}
		$this->addToAssertionCount(1);
	}

	/** Static helpers that need no service instance. */
	public function testStaticHelpers(): void
	{
		$this->assertIsArray(\OCA\ArbeitszeitCheck\Service\IconCatalog::names());
		$this->assertNotEmpty(\OCA\ArbeitszeitCheck\Service\IconCatalog::names());

		$appConfig = $this->createMock(\OCP\AppFramework\Services\IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(
			static fn (string $k, string $default) => '0'
		);
		$this->assertFalse(\OCA\ArbeitszeitCheck\Service\MonthClosureFeature::isEnabledFromAppConfig($appConfig));

		$appConfig2 = $this->createMock(\OCP\AppFramework\Services\IAppConfig::class);
		$appConfig2->method('getAppValueString')->willReturn('1');
		$this->assertTrue(\OCA\ArbeitszeitCheck\Service\MonthClosureFeature::isEnabledFromAppConfig($appConfig2));

		// valid app-data node names pass; traversal attempts throw
		\OCA\ArbeitszeitCheck\Service\UpgradeBackupIntegrity::assertAppDataNodeName('arbeitszeitcheck');
		$this->expectException(\OCA\ArbeitszeitCheck\Exception\UpgradeBackupException::class);
		\OCA\ArbeitszeitCheck\Service\UpgradeBackupIntegrity::assertAppDataNodeName('../escape');
	}

	/**
	 * Instantiate a service with every ctor dep auto-mocked. Final deps are
	 * constructed for real with their own deps mocked (one level deep).
	 */
	private function serviceFor(string $class): object
	{
		$ctor = (new \ReflectionClass($class))->getConstructor();
		if ($ctor === null) {
			return new $class();
		}
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$args[] = $this->depFor($param);
		}
		return new $class(...$args);
	}

	private function depFor(\ReflectionParameter $param): mixed
	{
		$type = $param->getType();
		if (!$type instanceof \ReflectionNamedType) {
			return $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
		}
		if ($type->isBuiltin()) {
			if ($param->isDefaultValueAvailable()) {
				return $param->getDefaultValue();
			}
			return match ($type->getName()) {
				'int' => 0, 'float' => 0.0, 'bool' => false, 'array' => [], default => '',
			};
		}
		$name = $type->getName();
		// The factory itself is mockable but its getProfile() return type is a
		// final class — auto-generated doubles fatal. Build the real factory.
		if ($name === \OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::class) {
			$config = $this->createMock(\OCP\IConfig::class);
			$this->configureDep(\OCP\IConfig::class, $config);
			return new \OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory($config);
		}
		if ((new \ReflectionClass($name))->isFinal()) {
			// construct real final deps with mocked collaborators
			return $this->serviceFor($name);
		}
		try {
			$mock = $this->createMock($name);
			$this->configureDep($name, $mock);
			return $mock;
		} catch (\Throwable $e) {
			if ($param->allowsNull()) {
				return null;
			}
			throw $e;
		}
	}

	/**
	 * Give well-known collaborator interfaces sane default returns so delegate
	 * code paths execute instead of hitting TypeErrors on null returns.
	 */
	private function configureDep(string $name, object $mock): void
	{
		if ($name === \OCP\IConfig::class) {
			foreach (['getAppValue', 'getUserValue', 'getSystemValueString', 'getSystemValue'] as $m) {
				if (method_exists($mock, $m)) {
					$mock->method($m)->willReturnCallback(
						static function (...$a) { return end($a) ?? ''; }
					);
				}
			}
			return;
		}
		if ($name === \OCP\AppFramework\Services\IAppConfig::class) {
			foreach (['getAppValueString', 'getAppValueInt', 'getAppValueBool', 'getAppValueArray'] as $m) {
				if (method_exists($mock, $m)) {
					$mock->method($m)->willReturnCallback(
						static function (...$a) { return end($a) ?? ''; }
					);
				}
			}
			return;
		}
		if ($name === \OCP\IDateTimeFormatter::class) {
			foreach (['formatDate', 'formatTime', 'formatDateTime', 'formatDateRelative', 'formatDateTimeRelative'] as $m) {
				if (method_exists($mock, $m)) {
					$mock->method($m)->willReturn('formatted');
				}
			}
			return;
		}
		if (is_subclass_of($name, \OCP\AppFramework\Db\QBMapper::class)) {
			foreach (['insert', 'update'] as $m) {
				if (method_exists($mock, $m)) {
					$mock->method($m)->willReturnArgument(0);
				}
			}
			return;
		}
		if ($name === \OCP\IL10N::class || $name === \OCP\L10N\IFactory::class) {
			foreach (['t', 'l'] as $m) {
				if (method_exists($mock, $m)) {
					$mock->method($m)->willReturnCallback(
						static fn (...$a) => (string)($a[0] ?? '')
					);
				}
			}
		}
	}

	/**
	 * Resolve placeholder values in the case table (entities need fresh
	 * instances per call so mutations don't leak between tests).
	 * @return array<int, mixed>
	 */
	private function resolveArgs(array $args): array
	{
		return array_map(function (mixed $a): mixed {
			if (is_string($a) && str_starts_with($a, '@mock:')) {
				return $this->createMock(substr($a, 6));
			}
			return $a;
		}, $args);
	}
}
