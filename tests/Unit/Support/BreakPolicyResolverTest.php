<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\BreakPolicyResolver;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Merge contract (SPEC §9): max(law, org, model), tightening only,
 * whole-second exclusive thresholds, fail-open on corrupt storage.
 *
 * DE profile used throughout: tiers >6h→30 / >9h→45, floor 15 min.
 */
class BreakPolicyResolverTest extends TestCase
{
	/** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	/** @var UserWorkingTimeModelMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $userWtmMapper;

	/** @var WorkingTimeModelMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $wtmMapper;

	/** @var TimeEntryMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var UserSettingsMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $userSettingsMapper;

	private BreakPolicyResolver $resolver;

	/** @var array<string,string> mutable per-test app config */
	private array $appValues = [];

	/** Mutable model assignment for the mocked mapper (set per-test). */
	private ?UserWorkingTimeModel $assignment = null;

	/** Mutable entry list for the mocked mapper (set per-test). */
	private array $overlappingEntries = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->appValues = [
			'country' => 'DE',
			'app_timezone' => 'Europe/Berlin',
		];
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, $default) => $this->appValues[$key] ?? $default
		);
		$this->config->method('getUserValue')->willReturn('');

		$this->userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$this->userSettingsMapper->method('findUserIdsWithNonEmptySetting')->willReturn([]);

		$this->userWtmMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->userWtmMapper->method('findCurrentByUser')->willReturnCallback(
			fn (string $uid) => $uid === 'u1' ? $this->assignment : null
		);
		$this->userWtmMapper->method('findByUserAndDate')->willReturnCallback(
			fn (string $uid, \DateTime $date) => $uid === 'u1' ? $this->assignment : null
		);

		$this->wtmMapper = $this->createMock(WorkingTimeModelMapper::class);
		$this->wtmMapper->method('find')->willReturnCallback(function (int $id): WorkingTimeModel {
			$model = new WorkingTimeModel();
			$model->setId($id);
			$model->setBreakRules(json_encode($this->modelBreakRules));
			return $model;
		});
		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->timeEntryMapper->method('findOverlapping')->willReturnCallback(
			fn () => $this->overlappingEntries
		);

		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$timeZoneService = new TimeZoneService($this->config, $dateTimeZone, $userSession, new NullLogger());

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $text, array $params = []): string {
			return vsprintf(str_replace(['%1$s','%2$s','%3$s','%4$d','%5$d','%1$d','%2$d','%3$d'], '%s', $text), $params) ?: $text;
		});

		$this->resolver = new BreakPolicyResolver(
			$this->config,
			new LaborLawProfileFactory($this->config, $this->userSettingsMapper),
			$this->userSettingsMapper,
			$this->userWtmMapper,
			$this->wtmMapper,
			$this->timeEntryMapper,
			$timeZoneService,
			$l10n,
		);
	}

	private function setPolicy(array $policy): void
	{
		$this->appValues[Constants::CONFIG_BREAK_POLICY_JSON] = json_encode($policy);
		$this->resolver->clearCache();
	}

	/** @var array<string,mixed> break_rules handed to the mocked model */
	private array $modelBreakRules = [];

	private function assignModel(array $breakRules): void
	{
		$this->modelBreakRules = $breakRules;
		$assignment = new UserWorkingTimeModel();
		$assignment->setWorkingTimeModelId(7);
		$this->assignment = $assignment;
		$this->resolver->clearCache();
	}

	// ------------------------------------------------------------------
	// Merge matrix — requiredBreakDetailed
	// ------------------------------------------------------------------

	public function testLawOnlyBaseline(): void
	{
		$this->assertSame(0, $this->resolver->requiredBreakMinutes(6 * 3600));           // exactly 6h: no break (exclusive >)
		$this->assertSame(30, $this->resolver->requiredBreakMinutes(6 * 3600 + 1));      // >6h → 30
		$this->assertSame(30, $this->resolver->requiredBreakMinutes(9 * 3600));          // exactly 9h: still 30
		$this->assertSame(45, $this->resolver->requiredBreakMinutes(9 * 3600 + 1));      // >9h → 45
	}

	public function testOrgTierTightens(): void
	{
		$this->setPolicy(['tiers' => [['afterHours' => 6, 'breakMinutes' => 45]]]);
		$d = $this->resolver->requiredBreakDetailed(7 * 3600);
		$this->assertSame(45, $d['minutes']);
		$this->assertSame('org', $d['source']);
	}

	public function testOrgTierWeakerIsIgnored(): void
	{
		$this->setPolicy(['tiers' => [['afterHours' => 6, 'breakMinutes' => 10]]]);
		$d = $this->resolver->requiredBreakDetailed(7 * 3600);
		$this->assertSame(30, $d['minutes']); // DE law still wins
		$this->assertSame('law', $d['source']);
	}

	public function testOrgTierNewThreshold(): void
	{
		// Law has no tier at 4h; org adds one.
		$this->setPolicy(['tiers' => [['afterHours' => 4, 'breakMinutes' => 15]]]);
		$d = $this->resolver->requiredBreakDetailed(5 * 3600);
		$this->assertSame(15, $d['minutes']);
		$this->assertSame('org', $d['source']);
	}

	public function testModelTierTightens(): void
	{
		$this->assignModel(['tiers' => [['afterHours' => 6, 'breakMinutes' => 60]]]);
		$d = $this->resolver->requiredBreakDetailed(7 * 3600, 'u1');
		$this->assertSame(60, $d['minutes']);
		$this->assertSame('model', $d['source']);
	}

	public function testOrgWinsTieOverModelAndLaw(): void
	{
		$this->setPolicy(['tiers' => [['afterHours' => 6, 'breakMinutes' => 30]]]);
		$this->assignModel(['tiers' => [['afterHours' => 6, 'breakMinutes' => 30]]]);
		$d = $this->resolver->requiredBreakDetailed(7 * 3600, 'u1');
		$this->assertSame('org', $d['source']); // documented tie precedence org > model > law
	}

	public function testModelWithoutAssignmentIsInert(): void
	{
		$d = $this->resolver->requiredBreakDetailed(7 * 3600, 'nobody');
		$this->assertSame(30, $d['minutes']);
		$this->assertSame('law', $d['source']);
	}

	// ------------------------------------------------------------------
	// Floor merge
	// ------------------------------------------------------------------

	public function testEffectiveFloorMaxOfLayers(): void
	{
		$this->assertSame(15, $this->resolver->effectiveFloor()); // DE statutory
		$this->setPolicy(['min_break_minutes' => 20]);
		$this->assertSame(20, $this->resolver->effectiveFloor());
		$this->setPolicy(['min_break_minutes' => 5]);
		$this->assertSame(15, $this->resolver->effectiveFloor()); // weaker org floor ignored
	}

	public function testModelFloorTightens(): void
	{
		$this->assignModel(['min_break_minutes' => 20]);
		$this->assertSame(20, $this->resolver->effectiveFloor('u1'));
		$this->assertSame(15, $this->resolver->effectiveFloor('other')); // no model → law floor
	}

	// ------------------------------------------------------------------
	// Corrupt storage — fail open
	// ------------------------------------------------------------------

	public function testCorruptJsonFailsOpenToLaw(): void
	{
		$this->appValues[Constants::CONFIG_BREAK_POLICY_JSON] = '{not json';
		$this->resolver->clearCache();
		$this->assertFalse($this->resolver->isEnforced());
		$this->assertSame(30, $this->resolver->requiredBreakMinutes(7 * 3600));
		$this->assertTrue($this->resolver->adminPayload()['malformed']);
	}

	public function testMalformedFragmentsDropped(): void
	{
		$this->setPolicy([
			'enforced' => true,
			'tiers' => [
				['afterHours' => 6, 'breakMinutes' => 45], // valid
				['afterHours' => 'bogus', 'breakMinutes' => 10], // dropped
				'garbage', // dropped
			],
			'windows' => [['start' => '12:00', 'end' => '13:00', 'mode' => 'placement'], ['start' => 'x', 'end' => 'y']],
			'min_break_minutes' => 'abc',
		]);
		$org = $this->resolver->orgConfig();
		$this->assertTrue($org['enforced']);
		$this->assertCount(1, $org['tiers']);
		$this->assertCount(1, $org['windows']);
		$this->assertNull($org['min_break_minutes']);
		$this->assertTrue($this->resolver->adminPayload()['malformed']);
	}

	// ------------------------------------------------------------------
	// isEnforced / isPolicyActive
	// ------------------------------------------------------------------

	public function testEnforcementAndActiveFlags(): void
	{
		$this->assertFalse($this->resolver->isEnforced());
		$this->assertFalse($this->resolver->isPolicyActive());
		$this->setPolicy(['enforced' => 'true']);
		$this->assertTrue($this->resolver->isEnforced());
		$this->assertTrue($this->resolver->isPolicyActive());
	}

	public function testModelPresenceWindowsMakePolicyActive(): void
	{
		$this->assignModel(['presence_windows' => [['weekday' => 1, 'start' => '08:00', 'end' => '12:00']]]);
		$this->assertTrue($this->resolver->isPolicyActive('u1'));
		$this->assertFalse($this->resolver->isPolicyActive('other'));
	}

	// ------------------------------------------------------------------
	// validatePolicy — strict admin boundary
	// ------------------------------------------------------------------

	public function testValidatePolicyAcceptsWellFormed(): void
	{
		$r = $this->resolver->validatePolicy([
			'enforced' => 'true',
			'min_break_minutes' => 20,
			'tiers' => [['afterHours' => '9', 'breakMinutes' => 45], ['afterHours' => 6, 'breakMinutes' => 30]],
			'windows' => [['start' => '12:00', 'end' => '13:00', 'mode' => 'placement']],
			'presence_exemptions' => [['start' => '2026-07-01', 'end' => '2026-08-15']],
		]);
		$this->assertSame([], $r['errors']);
		$this->assertTrue($r['normalized']['enforced']);
		$this->assertSame(20, $r['normalized']['min_break_minutes']);
		// Tiers sorted ascending by afterHours.
		$this->assertSame(6.0, $r['normalized']['tiers'][0]['afterHours']);
	}

	public function testValidatePolicyRejectsFormStringFalseTrap(): void
	{
		// "false" string must parse to false, not truthy.
		$r = $this->resolver->validatePolicy(['enforced' => 'false']);
		$this->assertSame([], $r['errors']);
		$this->assertFalse($r['normalized']['enforced']);
	}

	public function testValidatePolicyErrorCodes(): void
	{
		$r = $this->resolver->validatePolicy([
			'enforced' => 'maybe',
			'min_break_minutes' => 999,
			'tiers' => array_fill(0, 9, ['afterHours' => 6, 'breakMinutes' => 30]),
		]);
		$this->assertSame('VALIDATION_BOOLEAN', $r['errors'][BreakPolicyResolver::FIELD_ENFORCED]);
		$this->assertSame('VALIDATION_RANGE', $r['errors'][BreakPolicyResolver::FIELD_MIN_BREAK]);
		$this->assertSame('VALIDATION_TOO_MANY', $r['errors'][BreakPolicyResolver::FIELD_TIERS]);
	}

	public function testValidatePolicyTierFieldErrors(): void
	{
		$r = $this->resolver->validatePolicy([
			'tiers' => [
				['afterHours' => '2e3', 'breakMinutes' => 30], // float-smuggle — strict reject
				['afterHours' => 6, 'breakMinutes' => 0],      // 0 minutes — reject
			],
		]);
		$this->assertSame('VALIDATION_TIER', $r['errors']['breakPolicyTiers.0']);
		$this->assertSame('VALIDATION_TIER', $r['errors']['breakPolicyTiers.1']);
	}

	public function testValidatePolicyWindowErrors(): void
	{
		$r = $this->resolver->validatePolicy([
			'windows' => [
				['start' => '12:00', 'end' => '12:00', 'mode' => 'placement'],     // empty window
				['start' => 'x', 'end' => '13:00', 'mode' => 'placement'],          // bad time
				['start' => '12:00', 'end' => '13:00', 'mode' => 'sideways'],       // bad mode
				['start' => '12:00', 'end' => '13:00', 'mode' => 'required', 'minutes' => 90], // minutes > length
			],
		]);
		$this->assertSame('VALIDATION_EMPTY_WINDOW', $r['errors']['breakPolicyWindows.0']);
		$this->assertSame('VALIDATION_TIME', $r['errors']['breakPolicyWindows.1']);
		$this->assertSame('VALIDATION_MODE', $r['errors']['breakPolicyWindows.2']);
		$this->assertSame('VALIDATION_MINUTES_EXCEED_WINDOW', $r['errors']['breakPolicyWindows.3']);
	}

	public function testValidatePolicyWindowOverlapIncludingWrap(): void
	{
		$r = $this->resolver->validatePolicy([
			'windows' => [
				['start' => '12:00', 'end' => '13:00', 'mode' => 'placement'],
				['start' => '12:30', 'end' => '14:00', 'mode' => 'placement'],
			],
		]);
		$this->assertSame('VALIDATION_OVERLAP', $r['errors'][BreakPolicyResolver::FIELD_WINDOWS]);

		// Overnight window wraps past midnight → overlaps a 00:30 window.
		$r2 = $this->resolver->validatePolicy([
			'windows' => [
				['start' => '22:00', 'end' => '02:00', 'mode' => 'placement'],
				['start' => '00:30', 'end' => '01:00', 'mode' => 'placement'],
			],
		]);
		$this->assertSame('VALIDATION_OVERLAP', $r2['errors'][BreakPolicyResolver::FIELD_WINDOWS]);
	}

	public function testRequiredWindowWithoutMinutesDefaultsToWholeWindow(): void
	{
		$r = $this->resolver->validatePolicy([
			'windows' => [['start' => '12:00', 'end' => '13:00', 'mode' => 'required']],
		]);
		$this->assertSame([], $r['errors']);
		$this->assertSame(60, $r['normalized']['windows'][0]['minutes']);
	}

	public function testValidatePolicyExemptionErrors(): void
	{
		$r = $this->resolver->validatePolicy([
			'presence_exemptions' => [
				['start' => '2026-02-31', 'end' => '2026-03-01'], // impossible date
				['start' => '2026-08-01', 'end' => '2026-07-01'], // end before start
				['start' => 'x', 'end' => '2026-07-01'],
			],
		]);
		$this->assertSame('VALIDATION_RANGE', $r['errors']['presenceExemptions.0']);
		$this->assertSame('VALIDATION_RANGE', $r['errors']['presenceExemptions.1']);
		$this->assertSame('VALIDATION_RANGE', $r['errors']['presenceExemptions.2']);
	}

	// ------------------------------------------------------------------
	// Presence windows (strict write + tolerant read + exemption ranges)
	// ------------------------------------------------------------------

	public function testValidatePresenceWindowsHappyPath(): void
	{
		$r = $this->resolver->validatePresenceWindows([
			['weekday' => 1, 'start' => '08:00', 'end' => '12:00'],
			['weekday' => 1, 'start' => '13:00', 'end' => '15:00'],
			['weekday' => 5, 'start' => '08:00', 'end' => '12:00'], // same times, different weekday — fine
		]);
		$this->assertSame([], $r['errors']);
		$this->assertCount(3, $r['normalized']);
	}

	public function testValidatePresenceWindowsRejects(): void
	{
		$r = $this->resolver->validatePresenceWindows([
			['weekday' => 0, 'start' => '08:00', 'end' => '09:00'],  // weekday range
			['weekday' => 8, 'start' => '08:00', 'end' => '09:00'],
			['weekday' => 1, 'start' => '8:00', 'end' => '09:00'],   // not HH:MM
			['weekday' => 1, 'start' => '12:00', 'end' => '08:00'],  // start ≥ end (wrap not allowed)
			['weekday' => 1, 'start' => '08:00', 'end' => '10:00'],
			['weekday' => 1, 'start' => '09:00', 'end' => '11:00'],  // overlaps previous
		]);
		$this->assertSame('VALIDATION_WEEKDAY', $r['errors']['presence_windows.0']);
		$this->assertSame('VALIDATION_WEEKDAY', $r['errors']['presence_windows.1']);
		$this->assertSame('VALIDATION_TIME', $r['errors']['presence_windows.2']);
		$this->assertSame('VALIDATION_WINDOW_ORDER', $r['errors']['presence_windows.3']);
		$this->assertSame('VALIDATION_OVERLAP', $r['errors']['presence_windows']);
	}

	public function testValidatePresenceWindowsShapeAndCount(): void
	{
		$r = $this->resolver->validatePresenceWindows('not-a-list');
		$this->assertSame('VALIDATION_SHAPE', $r['errors']['presence_windows']);
		$r = $this->resolver->validatePresenceWindows(
			array_fill(0, 9, ['weekday' => 1, 'start' => '08:00', 'end' => '09:00'])
		);
		$this->assertSame('VALIDATION_TOO_MANY', $r['errors']['presence_windows']);
	}

	public function testPresenceWindowsPerWeekdayAndExempt(): void
	{
		$this->assignModel([
			'presence_windows' => [
				['weekday' => 1, 'start' => '08:00', 'end' => '12:00'],
				['weekday' => 3, 'start' => '09:00', 'end' => '10:00'],
			],
		]);
		$this->assertCount(1, $this->resolver->presenceWindows('u1', 1));
		$this->assertCount(1, $this->resolver->presenceWindows('u1', 3));
		$this->assertSame([], $this->resolver->presenceWindows('u1', 2));

		$this->setPolicy(['presence_exemptions' => [['start' => '2026-07-20', 'end' => '2026-08-30']]]);
		$this->assertTrue($this->resolver->presenceExempt('2026-08-01'));
		$this->assertFalse($this->resolver->presenceExempt('2026-09-01'));
		$this->assertTrue($this->resolver->presenceExempt('2026-07-20')); // inclusive bounds
		$this->assertTrue($this->resolver->presenceExempt('2026-08-30'));
	}

	// ------------------------------------------------------------------
	// Confirm-gate + shadow warnings
	// ------------------------------------------------------------------

	public function testPolicyIneffectiveEverywhereGate(): void
	{
		// Tier ≤ law at the same threshold → fully shadowed → confirm required.
		$shadowed = ['tiers' => [['afterHours' => 6, 'breakMinutes' => 30]], 'min_break_minutes' => null, 'windows' => [], 'presence_exemptions' => []];
		$this->assertTrue($this->resolver->policyIneffectiveEverywhere($shadowed));

		// Tier above law → effective → no confirm needed.
		$tighter = ['tiers' => [['afterHours' => 6, 'breakMinutes' => 45]], 'min_break_minutes' => null, 'windows' => [], 'presence_exemptions' => []];
		$this->assertFalse($this->resolver->policyIneffectiveEverywhere($tighter));

		// Windows/exemptions always count as meaningful.
		$withWindow = ['tiers' => [['afterHours' => 6, 'breakMinutes' => 30]], 'min_break_minutes' => null, 'windows' => [['start' => '12:00', 'end' => '13:00', 'minutes' => null, 'mode' => 'placement']], 'presence_exemptions' => []];
		$this->assertFalse($this->resolver->policyIneffectiveEverywhere($withWindow));

		// Empty policy never needs confirmation.
		$empty = ['tiers' => [], 'min_break_minutes' => null, 'windows' => [], 'presence_exemptions' => []];
		$this->assertFalse($this->resolver->policyIneffectiveEverywhere($empty));
	}

	public function testPolicyWarningsForShadowedRules(): void
	{
		$warnings = $this->resolver->policyWarnings([
			'tiers' => [['afterHours' => 6, 'breakMinutes' => 30]],
			'min_break_minutes' => 10,
			'windows' => [], 'presence_exemptions' => [],
		]);
		$this->assertCount(2, $warnings); // shadowed tier + sub-statutory floor
	}

	// ------------------------------------------------------------------
	// canonicalWindow — wrap + DST
	// ------------------------------------------------------------------

	public function testCanonicalWindowOvernightWrap(): void
	{
		$w = ['start' => '22:00', 'end' => '02:00', 'minutes' => null, 'mode' => 'placement'];
		$iv = $this->resolver->canonicalWindow('2026-06-01', $w);
		$this->assertNotNull($iv);
		$tz = new \DateTimeZone('Europe/Berlin');
		$this->assertSame((new \DateTimeImmutable('2026-06-01 22:00', $tz))->getTimestamp(), $iv[0]);
		$this->assertSame((new \DateTimeImmutable('2026-06-02 02:00', $tz))->getTimestamp(), $iv[1]);
	}

	public function testCanonicalWindowDstNonexistent(): void
	{
		// 2026-03-29 02:30 does not exist in Europe/Berlin (spring forward).
		$w = ['start' => '02:00', 'end' => '04:00', 'minutes' => null, 'mode' => 'placement'];
		$this->assertNull($this->resolver->canonicalWindow('2026-03-29', $w));
		// Same window on a normal day resolves.
		$this->assertNotNull($this->resolver->canonicalWindow('2026-03-28', $w));
	}

	public function testCanonicalWindowEqualTimesYieldNullNot24h(): void
	{
		// start == end is rejected by validatePolicy; a bypassed payload must
		// degrade to "no window" — never to a zero-length or 24h interval.
		$w = ['start' => '12:00', 'end' => '12:00', 'minutes' => null, 'mode' => 'placement'];
		$this->assertNull($this->resolver->canonicalWindow('2026-06-01', $w));
	}

	// ------------------------------------------------------------------
	// planAutoBreak — placement edge cases
	// ------------------------------------------------------------------

	private function makeEntry(string $start, string $end, ?array $breaks = null, ?int $id = 99): TimeEntry
	{
		$tz = new \DateTimeZone('Europe/Berlin');
		$entry = new TimeEntry();
		if ($id !== null) {
			$entry->setId($id);
		}
		$entry->setUserId('u1');
		$entry->setStartTime(new \DateTime($start, $tz));
		$entry->setEndTime(new \DateTime($end, $tz));
		if ($breaks !== null) {
			$entry->setBreaks(json_encode($breaks));
		}
		return $entry;
	}

	public function testPlanAutoBreakPlacesInsideRequiredWindow(): void
	{
		$this->setPolicy([
			'windows' => [['start' => '11:30', 'end' => '13:30', 'minutes' => 30, 'mode' => 'required']],
		]);
		$entry = $this->makeEntry('2026-06-01 08:00', '2026-06-01 17:00');
		$this->overlappingEntries = [$entry];

		$plan = $this->resolver->planAutoBreak($entry, 45);
		$this->assertNotNull($plan, 'required window must host the break');
		$this->assertSame('required', $plan['window']['mode']);
		// Break ≥ needed (45min) and ≥60% inside the window for required mode.
		$win = $this->resolver->canonicalWindow('2026-06-01', $plan['window']);
		$this->assertGreaterThanOrEqual(30 * 60, $this->overlapSecondsPublic([[$plan['start']->getTimestamp(), $plan['end']->getTimestamp()]], $win[0], $win[1]));
		$this->assertSame(45 * 60, $plan['seconds']);
		$this->assertLessThan($entry->getEndTime()->getTimestamp(), $plan['end']->getTimestamp(), 'E-23: break must end strictly before shift end');
	}

	public function testPlanAutoBreakFallsBackToMidpointWhenWindowDoesNotApply(): void
	{
		$this->setPolicy([
			'windows' => [['start' => '06:00', 'end' => '06:30', 'minutes' => null, 'mode' => 'placement']],
		]);
		$entry = $this->makeEntry('2026-06-01 08:00', '2026-06-01 17:00');
		$this->overlappingEntries = [$entry];

		$plan = $this->resolver->planAutoBreak($entry, 45);
		$this->assertNotNull($plan);
		$this->assertNull($plan['window'], 'non-applicable window must not claim the placement');
		$this->assertSame(45 * 60, $plan['seconds']);
		$this->assertGreaterThan($entry->getStartTime()->getTimestamp(), $plan['start']->getTimestamp());
		$this->assertLessThan($entry->getEndTime()->getTimestamp(), $plan['end']->getTimestamp());
	}

	public function testPlanAutoBreakSuppressedByManualBreakOnDay(): void
	{
		$this->setPolicy([
			'windows' => [['start' => '11:30', 'end' => '13:30', 'minutes' => 30, 'mode' => 'required']],
		]);
		$existing = $this->makeEntry('2026-06-01 08:00', '2026-06-01 17:00', [
			['start' => '2026-06-01T12:00:00+02:00', 'end' => '2026-06-01T12:15:00+02:00', 'automatic' => false],
		], 50);
		$live = $this->makeEntry('2026-06-01 09:00', '2026-06-01 18:00', null, 51);
		$this->overlappingEntries = [$existing, $live];

		$this->assertNull($this->resolver->planAutoBreak($live, 45), 'E-25: any manual break on the day suppresses auto insertion');
	}

	public function testPlanAutoBreakDayAlreadyCoveredByOtherEntryBreak(): void
	{
		$existing = $this->makeEntry('2026-06-01 08:00', '2026-06-01 12:00', [
			['start' => '2026-06-01T10:00:00+02:00', 'end' => '2026-06-01T10:45:00+02:00', 'automatic' => true],
		], 60);
		$live = $this->makeEntry('2026-06-01 13:00', '2026-06-01 18:00', null, 61);
		$this->overlappingEntries = [$existing, $live];

		$this->assertNull($this->resolver->planAutoBreak($live, 45), 'day requirement already met by another entry — no second auto break');
	}

	public function testPlanAutoBreakShiftsTooShortReturnsNull(): void
	{
		$entry = $this->makeEntry('2026-06-01 08:00', '2026-06-01 08:30');
		$this->overlappingEntries = [$entry];
		$this->assertNull($this->resolver->planAutoBreak($entry, 45), 'a break longer than the shift span must not be written outside the interval');
	}

	public function testPlanAutoBreakOnlyCountsRemainderForSecondEntry(): void
	{
		// Day already has a 15min automatic break; the second entry needs the rest.
		$existing = $this->makeEntry('2026-06-01 08:00', '2026-06-01 12:00', [
			['start' => '2026-06-01T10:00:00+02:00', 'end' => '2026-06-01T10:15:00+02:00', 'automatic' => true],
		], 70);
		$live = $this->makeEntry('2026-06-01 13:00', '2026-06-01 18:00', null, 71);
		$this->overlappingEntries = [$existing, $live];

		$plan = $this->resolver->planAutoBreak($live, 45);
		$this->assertNotNull($plan);
		$this->assertSame(30 * 60, $plan['seconds'], 'only the remaining 30min must be added on top of the day-level 15min');
	}

	public function testPlanAutoBreakOvernightWindowOnEntryStartDay(): void
	{
		$this->setPolicy([
			'windows' => [['start' => '22:00', 'end' => '02:00', 'minutes' => 60, 'mode' => 'required']],
		]);
		$entry = $this->makeEntry('2026-06-01 20:00', '2026-06-02 04:00');
		$this->overlappingEntries = [$entry];

		$plan = $this->resolver->planAutoBreak($entry, 60);
		$this->assertNotNull($plan, 'overnight required window must host the break');
		$win = $this->resolver->canonicalWindow('2026-06-01', $plan['window']);
		$this->assertGreaterThanOrEqual(60 * 60, $this->overlapSecondsPublic([[$plan['start']->getTimestamp(), $plan['end']->getTimestamp()]], $win[0], $win[1]));
	}

	public function testPlanAutoBreakRequiredWindowPinnedToShiftEndIsUnsatisfiable(): void
	{
		// E-23 forces break end < shift end; a required window demanding the
		// full clamped span can never be satisfied — planner must NOT silently
		// drop the requirement; it falls back (documented current behaviour,
		// surfaced here so the planner/evaluator disagreement is pinned).
		$this->setPolicy([
			'windows' => [['start' => '12:00', 'end' => '15:00', 'minutes' => 60, 'mode' => 'required']],
		]);
		$entry = $this->makeEntry('2026-06-01 08:00', '2026-06-01 13:00');
		$this->overlappingEntries = [$entry];

		$plan = $this->resolver->planAutoBreak($entry, 60);
		// Any break < 60min ending strictly before 13:00 covers at most
		// 59min59s of the window — provably unsatisfiable. Planner falls back
		// to midpoint; the evaluator will still flag the day.
		$this->assertNotNull($plan);
		$this->assertNull($plan['window'], 'unsatisfiable required window must not claim the break');

		$violations = $this->resolver->windowViolations('u1', $entry);
		$this->assertNotEmpty($violations, 'evaluator flags the day the planner could not satisfy — known inconsistency (qa finding)');
	}

	/** overlapSeconds mirror for assertions (private in the resolver). */
	private function overlapSecondsPublic(array $intervals, int $start, int $end): int
	{
		$m = new \ReflectionMethod($this->resolver, 'overlapSeconds');
		return (int)$m->invoke($this->resolver, $intervals, $start, $end);
	}

	// ------------------------------------------------------------------
	// Paid-break keys (TVöD §6 Abs. 1 S. 2)
	// ------------------------------------------------------------------

	public function testPaidBreakPolicyDefaultsOff(): void
	{
		$this->assignModel([]);
		$p = $this->resolver->paidBreakPolicyForUser('u1');
		$this->assertSame(['count_as_work' => false, 'cap_minutes' => null], $p);
	}

	public function testPaidBreakPolicyEnabledWithCap(): void
	{
		$this->assignModel(['breaks_count_as_work' => true, 'paid_break_cap_minutes' => 30]);
		$p = $this->resolver->paidBreakPolicyForUser('u1');
		$this->assertTrue($p['count_as_work']);
		$this->assertSame(30, $p['cap_minutes']);
	}

	public function testPaidBreakFlagTolerantParsing(): void
	{
		foreach ([true, 1, '1', 'true', ' true '] as $truthy) {
			$this->assignModel(['breaks_count_as_work' => $truthy]);
			$this->assertTrue(
				$this->resolver->paidBreakPolicyForUser('u1')['count_as_work'],
				'flag ' . var_export($truthy, true) . ' must enable'
			);
		}
		foreach ([false, 0, '0', 'false', 'False', '', 'yes', 2] as $falsy) {
			$this->assignModel(['breaks_count_as_work' => $falsy]);
			$this->assertFalse(
				$this->resolver->paidBreakPolicyForUser('u1')['count_as_work'],
				'flag ' . var_export($falsy, true) . ' must disable (fail closed = unpaid)'
			);
		}
	}

	public function testPaidBreakCapNormalization(): void
	{
		$this->assignModel(['breaks_count_as_work' => true, 'paid_break_cap_minutes' => '45']);
		$this->assertSame(45, $this->resolver->paidBreakPolicyForUser('u1')['cap_minutes']);

		$this->assignModel(['breaks_count_as_work' => true, 'paid_break_cap_minutes' => 0]);
		$this->assertSame(0, $this->resolver->paidBreakPolicyForUser('u1')['cap_minutes']);

		$this->assignModel(['breaks_count_as_work' => true, 'paid_break_cap_minutes' => null]);
		$this->assertNull($this->resolver->paidBreakPolicyForUser('u1')['cap_minutes']);

		foreach ([-1, 481, 'abc', 1.5, [30]] as $bad) {
			$this->assignModel(['breaks_count_as_work' => true, 'paid_break_cap_minutes' => $bad]);
			$this->assertNull(
				$this->resolver->paidBreakPolicyForUser('u1')['cap_minutes'],
				'cap ' . var_export($bad, true) . ' must normalize to null'
			);
		}
	}

	public function testPaidBreakPolicyNoUserOrAssignmentIsOff(): void
	{
		$this->assertSame(['count_as_work' => false, 'cap_minutes' => null], $this->resolver->paidBreakPolicyForUser(null));
		$this->assertSame(['count_as_work' => false, 'cap_minutes' => null], $this->resolver->paidBreakPolicyForUser(''));
		$this->assertSame(['count_as_work' => false, 'cap_minutes' => null], $this->resolver->paidBreakPolicyForUser('nobody'));
	}

	public function testPaidBreakPolicyAsOfResolvesHistoricalAssignment(): void
	{
		$this->assignModel(['breaks_count_as_work' => true]);
		$p = $this->resolver->paidBreakPolicyForUser('u1', new \DateTime('2020-01-15'));
		$this->assertTrue($p['count_as_work']);
		// A user without an as-of assignment resolves to off.
		$p = $this->resolver->paidBreakPolicyForUser('ghost', new \DateTime('2020-01-15'));
		$this->assertFalse($p['count_as_work']);
	}

	public function testNormalizeModelRulesPreservesUnknownKeysUnaffected(): void
	{
		// Unknown keys live outside the normalized view — the paid keys must
		// not clobber sibling structures the editor preserves on save.
		$normalized = $this->resolver->normalizeModelRules([
			'weekday_schedule' => ['mon' => '08:00-16:00'],
			'breaks_count_as_work' => 'true',
			'paid_break_cap_minutes' => '30',
		]);
		$this->assertTrue($normalized['breaks_count_as_work']);
		$this->assertSame(30, $normalized['paid_break_cap_minutes']);
	}

	// ------------------------------------------------------------------
	// NH-04: organisation-level default cap inheritance
	// ------------------------------------------------------------------

	public function testOrgDefaultCapIsInheritedWhenModelCapUnset(): void
	{
		$this->setPolicy(['paid_break_cap_minutes' => 45]);
		$this->assignModel(['breaks_count_as_work' => true]);
		$p = $this->resolver->paidBreakPolicyForUser('u1');
		$this->assertTrue($p['count_as_work']);
		$this->assertSame(45, $p['cap_minutes']);
	}

	public function testExplicitModelCapZeroWinsOverOrgDefault(): void
	{
		$this->setPolicy(['paid_break_cap_minutes' => 45]);
		$this->assignModel(['breaks_count_as_work' => true, 'paid_break_cap_minutes' => 0]);
		$this->assertSame(0, $this->resolver->paidBreakPolicyForUser('u1')['cap_minutes']);
	}

	public function testExplicitModelCapWinsOverOrgDefault(): void
	{
		$this->setPolicy(['paid_break_cap_minutes' => 45]);
		$this->assignModel(['breaks_count_as_work' => true, 'paid_break_cap_minutes' => 20]);
		$this->assertSame(20, $this->resolver->paidBreakPolicyForUser('u1')['cap_minutes']);
	}

	public function testNoOrgDefaultMeansFullInclusionForUncappedModel(): void
	{
		$this->assignModel(['breaks_count_as_work' => true]);
		$this->assertNull($this->resolver->paidBreakPolicyForUser('u1')['cap_minutes']);
	}

	public function testOrgCapIsIrrelevantWhenModelFlagOff(): void
	{
		$this->setPolicy(['paid_break_cap_minutes' => 45]);
		$this->assignModel(['breaks_count_as_work' => false]);
		$p = $this->resolver->paidBreakPolicyForUser('u1');
		$this->assertFalse($p['count_as_work']);
	}

	public function testMalformedOrgCapDropsToNullNotCrash(): void
	{
		// Corrupt stored doc must degrade to "no org default" (fail open =
		// full inclusion), never fatal and never a phantom cap.
		$this->setPolicy(['paid_break_cap_minutes' => 'totally-bogus']);
		$this->assignModel(['breaks_count_as_work' => true]);
		$this->assertNull($this->resolver->paidBreakPolicyForUser('u1')['cap_minutes']);

		$this->setPolicy(['paid_break_cap_minutes' => 481]);
		$this->assertNull($this->resolver->paidBreakPolicyForUser('u1')['cap_minutes']);

		$this->setPolicy(['paid_break_cap_minutes' => -5]);
		$this->assertNull($this->resolver->paidBreakPolicyForUser('u1')['cap_minutes']);
	}

	public function testValidatePolicyAcceptsAndRangesOrgCap(): void
	{
		$base = [
			'enforced' => false,
			'min_break_minutes' => null,
			'tiers' => [],
			'windows' => [],
			'presence_exemptions' => [],
		];

		$ok = $this->resolver->validatePolicy($base + ['paid_break_cap_minutes' => '30']);
		$this->assertSame([], $ok['errors']);
		$this->assertSame(30, $ok['normalized']['paid_break_cap_minutes']);

		$zero = $this->resolver->validatePolicy($base + ['paid_break_cap_minutes' => 0]);
		$this->assertSame([], $zero['errors']);
		$this->assertSame(0, $zero['normalized']['paid_break_cap_minutes']);

		$empty = $this->resolver->validatePolicy($base + ['paid_break_cap_minutes' => '']);
		$this->assertSame([], $empty['errors']);
		$this->assertNull($empty['normalized']['paid_break_cap_minutes']);

		foreach (['abc', -1, 481, 12.5] as $bad) {
			$v = $this->resolver->validatePolicy($base + ['paid_break_cap_minutes' => $bad]);
			$this->assertArrayHasKey(
				BreakPolicyResolver::FIELD_PAID_CAP,
				$v['errors'],
				'cap ' . var_export($bad, true) . ' must be rejected'
			);
		}
	}
}
