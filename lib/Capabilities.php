<?php

declare(strict_types=1);

/**
 * Capabilities class for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck;

use OCA\ArbeitszeitCheck\Service\MonthClosureFeature;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\OfflineStampSkewPolicy;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Capabilities\ICapability;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * Class Capabilities
 */
class Capabilities implements ICapability {
	public function __construct(
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly OvertimeBankService $overtimeBankService,
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly TimeCaptureMethodService $timeCaptureMethodService,
		private readonly LaborLawProfileFactory $lawProfileFactory,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getCapabilities(): array {
		$user = $this->userSession->getUser();
		$pushAvailable = $user !== null
			&& $this->appManager->isEnabledForUser('notifications', $user);

		$timeCapture = $user !== null
			? $this->timeCaptureMethodService->getSettings($user->getUID())
			: [
				'clockStampingEnabled' => true,
				'manualTimeEntryEnabled' => true,
			];

		$projectCheckAvailable = $this->appManager->isInstalled(Constants::APP_ID_PROJECTCHECK) === true;

		$user = $this->userSession->getUser();
		$profile = $this->lawProfileFactory->getProfile($user !== null ? $user->getUID() : null);
		$featureTag = match ($profile->country) {
			RegionRegistry::COUNTRY_AT => 'azg-compliance',
			RegionRegistry::COUNTRY_CH => 'swiss-arg-compliance',
			default => 'arbzg-compliance',
		};
		$isGermany = $profile->country === RegionRegistry::COUNTRY_DE;
		$workforceSuite = new WorkforceSuiteConfig($this->config);

		// Effective limits: explicit admin config wins over the profile default
		// (same clamping as ComplianceService).
		$maxDailyHours = max(1.0, min(24.0, (float)$this->config->getAppValue(
			'arbeitszeitcheck', 'max_daily_hours', (string)$profile->dailyMaxHoursDefault
		)));
		$minRestHours = max(1.0, min(24.0, (float)$this->config->getAppValue(
			'arbeitszeitcheck', 'min_rest_period', (string)$profile->minRestHoursDefault
		)));

		// Org break policy — fail open to non-enforced on resolver faults so a
		// capabilities hiccup never locks companion clients into wrong UI.
		$breakPolicyEnforced = false;
		$breakPolicyActive = false;
		try {
			$policyResolver = \OCP\Server::get(\OCA\ArbeitszeitCheck\Support\BreakPolicyResolver::class);
			$breakPolicyEnforced = $policyResolver->isEnforced();
			$breakPolicyActive = $policyResolver->isPolicyActive($user !== null ? $user->getUID() : null);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'Capabilities: break-policy resolver failed — reporting non-enforced: ' . $e->getMessage(),
				['exception' => $e]
			);
			$breakPolicyEnforced = false;
			$breakPolicyActive = false;
		}

		return [
			'arbeitszeitcheck' => [
				// B-3: report the real installed app version, never a literal.
				'version' => $this->appManager->getAppVersion('arbeitszeitcheck'),
				'workforceSuite' => [
					'contractVersion' => 1,
					'installState' => $workforceSuite->installState(),
					'features' => $workforceSuite->flags(),
				],
				'features' => [
					'time-tracking',
					'compliance-monitoring',
					'absence-management',
					'reporting',
					'gdpr-compliance',
					$featureTag,
					'accessibility-wcag-aaa',
					'projectcheck-integration',
				],
				'mobile' => [
					'minAppVersion' => '1.0.0',
					'bootstrapEndpoint' => '/api/mobile/bootstrap',
					'pushAvailable' => $pushAvailable,
					'monthClosure' => MonthClosureFeature::isEnabledFromIConfig($this->config),
					'overtimeBank' => $this->overtimeBankService->isEnabled(),
					'layeredVacationEntitlements' => $this->appConfig->getAppValueString('layered_entitlements_enabled', '0') === '1',
					'vacationUnit' => (string)$this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_UNIT, Constants::DEFAULT_VACATION_UNIT) === Constants::VACATION_UNIT_HOURS
						? Constants::VACATION_UNIT_HOURS
						: Constants::VACATION_UNIT_DAYS,
					'vacationUnitAware' => true,
					'timeCapture' => $timeCapture,
					// GH #50: whether the owner may directly edit their own
					// completed/paused entries (PUT /api/time-entries/{id})
					// or must route every change through a correction request.
					// `editWindowDays` mirrors Constants::EDIT_WINDOW_DAYS so
					// clients hide the affordance for entries the server would
					// reject anyway. `changesRequireApproval` === true maps to
					// the org flag that answers direct edits with
					// `correction_required` (403).
					'timeEntryEditing' => [
						'editWindowDays' => Constants::EDIT_WINDOW_DAYS,
						'changesRequireApproval' => $this->config->getAppValue(
							'arbeitszeitcheck',
							Constants::CONFIG_TIME_ENTRY_CHANGES_REQUIRE_APPROVAL,
							'0'
						) === '1',
					],
					// GH #49: POST /api/manager/employee-absences exists and
					// stores the absence directly as approved. Clients gate the
					// manager "record absence" surface on this flag so older
					// servers fail closed instead of 404ing on submit.
					'managerAbsenceRecording' => true,
					// AZC-HALF-DAY-NON-VACATION: org allowlist for the day-length
					// control. Absent/malformed on old servers → clients parse
					// fail-closed to [] and never show the control.
					'halfDayEnabledTypes' => \OCA\ArbeitszeitCheck\Support\HalfDayTypePolicy::enabledTypesFromConfig($this->config),
					'projectCheck' => [
						'available' => $projectCheckAvailable,
						'linkingEnabled' => $projectCheckAvailable
							&& $this->appConfig->getAppValueString(
								Constants::CONFIG_PROJECTCHECK_INTEGRATION_ENABLED,
								Constants::CONFIG_PROJECTCHECK_INTEGRATION_DEFAULT,
							) === '1',
					],
					'offlineStampQueue' => [
						'enabled' => true,
						'maxQueueSize' => 32,
						'maxOccurredSkewPastHours' => OfflineStampSkewPolicy::fromConfig($this->config),
					],
				],
				// Companion API floor — clients fail closed to app_outdated when missing/mismatched.
				'arbeitszeitcheck.companion.min' => 1,
				'compliance' => [
					// Additive DACH block (§4.2): absent 'country' means the
					// client may assume the historical German behaviour.
					'country' => $profile->country,
					'lawLabels' => $profile->lawShortLabels,
					'breakTiers' => $profile->breakTiersAscending(),
					'maxDailyHours' => $maxDailyHours,
					'minRestHours' => $minRestHours,
					'minBreakMinutes' => $profile->minBreakMinutes,
					'allowedBreakSplitPatterns' => $profile->allowedBreakSplitPatterns,
					'weeklyAbsoluteMaxHours' => $profile->weeklyAbsoluteMaxHours,
					'german-labor-law' => $isGermany,
					// Additive: older clients ignore this; new clients use it
					// to lock break preferences and explain managed rules.
					'breakPolicy' => [
						'enforced' => $breakPolicyEnforced,
						'active' => $breakPolicyActive,
					],
					'gdpr' => true,
					'audit-logging' => true,
					'data-retention' => true,
				],
				'accessibility' => [
					'wcag-level' => 'AA',
					'screen-reader' => true,
					'keyboard-navigation' => true,
					'high-contrast' => true,
				],
			],
		];
	}
}
