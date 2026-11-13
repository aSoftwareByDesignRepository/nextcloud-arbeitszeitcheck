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

	public function testValidateModelBreakRulesNormalizesTiersAndFloor(): void
	{
		$v = $this->resolver->validateModelBreakRules([
			'tiers' => [
				['afterHours' => '9', 'breakMinutes' => '180'],
				['afterHours' => 6, 'breakMinutes' => 30],
			],
			'min_break_minutes' => '20',
			// Unknown keys are owned elsewhere — left untouched, no error.
			'weekday_schedule' => ['x' => 1],
		]);
		$this->assertSame([], $v['errors']);
		$this->assertSame(6.0, $v['normalized']['tiers'][0]['afterHours']);
		$this->assertSame(30, $v['normalized']['tiers'][0]['breakMinutes']);
		$this->assertSame(9.0, $v['normalized']['tiers'][1]['afterHours']);
		$this->assertSame(180, $v['normalized']['tiers'][1]['breakMinutes']);
		$this->assertSame(20, $v['normalized']['min_break_minutes']);
	}

	public function testValidateModelBreakRulesRejectsBadTiers(): void
	{
		foreach ([
			['afterHours' => 'abc', 'breakMinutes' => 30],
			['afterHours' => 0, 'breakMinutes' => 30],
			['afterHours' => 25, 'breakMinutes' => 30],
			['afterHours' => 6, 'breakMinutes' => 0],
			['afterHours' => 6, 'breakMinutes' => 481],
			['afterHours' => '2e3', 'breakMinutes' => 30], // float-notation smuggle
			'not-a-map',
		] as $badTier) {
			$v = $this->resolver->validateModelBreakRules(['tiers' => [$badTier]]);
			$this->assertNotSame([], $v['errors'], 'tier ' . json_encode($badTier) . ' must be rejected');
		}

		$v = $this->resolver->validateModelBreakRules(['tiers' => 'not-an-array']);
		$this->assertSame('VALIDATION_SHAPE', $v['errors']['tiers']);

		$v = $this->resolver->validateModelBreakRules([
			'tiers' => array_fill(0, 9, ['afterHours' => 6, 'breakMinutes' => 30]),
		]);
		$this->assertSame('VALIDATION_TOO_MANY', $v['errors']['tiers']);
	}

	public function testValidateModelBreakRulesFloorAndFallbackBounds(): void
	{
		foreach (['abc', 0, 481, -5, 12.5] as $bad) {
			$v = $this->resolver->validateModelBreakRules(['min_break_minutes' => $bad]);
			$this->assertSame('VALIDATION_RANGE', $v['errors']['min_break_minutes'] ?? null, 'floor ' . var_export($bad, true));
		}
		foreach ([14, 721, 'abc'] as $bad) {
			$v = $this->resolver->validateModelBreakRules(['auto_fallback_minutes' => $bad]);
			$this->assertSame('VALIDATION_RANGE', $v['errors']['auto_fallback_minutes'] ?? null, 'fallback ' . var_export($bad, true));
		}
		$v = $this->resolver->validateModelBreakRules(['break_policy' => 'bogus']);
		$this->assertSame('VALIDATION_VALUE', $v['errors']['break_policy']);

		// null/'' clears a scalar → normalized null lets the caller unset.
		$v = $this->resolver->validateModelBreakRules(['min_break_minutes' => null]);
		$this->assertSame([], $v['errors']);
		$this->assertNull($v['normalized']['min_break_minutes']);
	}

	public function testValidateModelBreakRulesSundayWorkStrictBool(): void
	{
		foreach (['false', '0', 'yes', 0, ''] as $off) {
			$v = $this->resolver->validateModelBreakRules(['allow_sunday_work' => $off]);
			$this->assertFalse($v['normalized']['allow_sunday_work'], 'value ' . var_export($off, true));
		}
		foreach ([true, 1, '1', 'true'] as $on) {
			$v = $this->resolver->validateModelBreakRules(['allow_sunday_work' => $on]);
			$this->assertTrue($v['normalized']['allow_sunday_work'], 'value ' . var_export($on, true));
		}
	}

	// ------------------------------------------------------------------
	// Deduction mode — resolution + persistence contract
	// ------------------------------------------------------------------

	public function testDeductionModeDefaultsStrict(): void
	{
		$this->assertSame('strict', $this->resolver->deductionModeForUser('u1'));
		$this->assertSame('strict', $this->resolver->deductionModeForUser(null));
	}

	public function testDeductionModeOrgCap(): void
	{
		$this->setPolicy(['deduction_mode' => 'cap_overhang']);
		$this->assertSame('cap_overhang', $this->resolver->deductionModeForUser('u1'));
	}

	public function testDeductionModeModelOverridesOrg(): void
	{
		$this->setPolicy(['deduction_mode' => 'strict']);
		$this->assignModel(['deduction_mode' => 'cap_overhang']);
		$this->assertSame('cap_overhang', $this->resolver->deductionModeForUser('u1'));
		$this->assertSame('strict', $this->resolver->deductionModeForUser('other'));
	}

	public function testDeductionModeModelNullInheritsOrg(): void
	{
		$this->setPolicy(['deduction_mode' => 'cap_overhang']);
		$this->assignModel(['deduction_mode' => null]);
		$this->assertSame('cap_overhang', $this->resolver->deductionModeForUser('u1'));
	}

	public function testDeductionModeUnknownStoredFallsBackStrict(): void
	{
		$this->setPolicy(['deduction_mode' => 'bogus']);
		$this->assertSame('strict', $this->resolver->deductionModeForUser('u1'));

		$this->assignModel(['deduction_mode' => 'nonsense']);
		$this->setPolicy(['deduction_mode' => 'cap_overhang']);
		$this->assertSame('cap_overhang', $this->resolver->deductionModeForUser('u1'),
			'invalid model value must inherit, not poison');
	}

	public function testValidatePolicyRejectsUnknownDeductionMode(): void
	{
		$v = $this->resolver->validatePolicy(['deduction_mode' => 'bogus']);
		$this->assertSame('VALIDATION_VALUE', $v['errors'][BreakPolicyResolver::FIELD_DEDUCTION_MODE] ?? null);

		foreach (['strict', 'cap_overhang'] as $ok) {
			$v = $this->resolver->validatePolicy(['deduction_mode' => $ok]);
			$this->assertArrayNotHasKey(BreakPolicyResolver::FIELD_DEDUCTION_MODE, $v['errors']);
			$this->assertSame($ok, $v['normalized']['deduction_mode']);
		}
	}

	public function testValidateModelBreakRulesDeductionMode(): void
	{
		foreach (['strict', 'cap_overhang'] as $ok) {
			$v = $this->resolver->validateModelBreakRules(['deduction_mode' => $ok]);
			$this->assertSame([], $v['errors']);
			$this->assertSame($ok, $v['normalized']['deduction_mode']);
		}
		$v = $this->resolver->validateModelBreakRules(['deduction_mode' => null]);
		$this->assertSame([], $v['errors']);
		$this->assertNull($v['normalized']['deduction_mode']);

		$v = $this->resolver->validateModelBreakRules(['deduction_mode' => 'bogus']);
		$this->assertSame('VALIDATION_VALUE', $v['errors']['deduction_mode'] ?? null);
	}

	// ------------------------------------------------------------------
	// Monotone tiers — write boundary + stored-document repair
	// ------------------------------------------------------------------

	public function testValidatePolicyRejectsNonMonotoneTiers(): void
	{
		$v = $this->resolver->validatePolicy([
			'tiers' => [
				['afterHours' => 6, 'breakMinutes' => 45],
				['afterHours' => 9, 'breakMinutes' => 15],
			],
		]);
		$this->assertSame('VALIDATION_TIER_MONOTONE', $v['errors'][BreakPolicyResolver::FIELD_TIERS . '.1'] ?? null);
	}

	public function testValidatePolicyAcceptsMonotoneTiers(): void
	{
		$v = $this->resolver->validatePolicy([
			'tiers' => [
				['afterHours' => 9, 'breakMinutes' => 45],
				['afterHours' => 6, 'breakMinutes' => 45], // sorted ascending by normalize
			],
		]);
		$this->assertSame([], $v['errors']);
	}

	public function testStoredNonMonotoneTierIsDroppedTolerantly(): void
	{
		$this->setPolicy([
			'tiers' => [
				['afterHours' => 6, 'breakMinutes' => 45],
				['afterHours' => 9, 'breakMinutes' => 15],
			],
		]);
		$org = $this->resolver->orgConfig();
		$this->assertCount(1, $org['tiers']);
		$this->assertSame(45, $org['tiers'][0]['breakMinutes']);
	}

	// ------------------------------------------------------------------
	// Strict fixpoint — spec §9.4 worked-example matrix (DE tiers, E=0)
	// ------------------------------------------------------------------

	/**
	 * @return array<string,array{0:int,1:int}>
	 */
	public static function strictFixpointMatrix(): array
	{
		$h = 3600; $m = 60;
		return [
			'5h59m' => [5 * $h + 59 * $m, 0],
			'6h00m00s' => [6 * $h, 0],
			'6h00m01s' => [6 * $h + 1, 30 * $m],
			'6h45m' => [6 * $h + 45 * $m, 30 * $m],
			'9h00m00s' => [9 * $h, 30 * $m],
			'9h00m01s' => [9 * $h + 1, 30 * $m],
			'9h05m' => [9 * $h + 5 * $m, 30 * $m],
			'9h20m' => [9 * $h + 20 * $m, 30 * $m],
			'9h31m' => [9 * $h + 31 * $m, 45 * $m],
			'9h40m' => [9 * $h + 40 * $m, 45 * $m],
			'9h50m' => [9 * $h + 50 * $m, 45 * $m],
			'10h00m' => [10 * $h, 45 * $m],
		];
	}

	/**
	 * @dataProvider strictFixpointMatrix
	 */
	public function testStrictFixpointMatrix(int $presence, int $expectedTotal): void
	{
		$r = $this->resolver->minimalConsistentBreakSeconds($presence, 0, 'strict');
		$this->assertSame($expectedTotal, $r['total_seconds'], 'P=' . $presence);
		$this->assertSame($expectedTotal, $r['insert_seconds']);
		$this->assertFalse($r['capped']);
		$this->assertSame($expectedTotal, $r['strict_target_seconds']);

		// Consistency invariant: recorded books always satisfy R(P−d*) ≤ d*.
		$net = max(0, $presence - $r['total_seconds']);
		$this->assertLessThanOrEqual(
			$r['total_seconds'],
			$this->resolver->requiredBreakMinutes($net) * 60,
			'required(P−d*) ≤ d* for P=' . $presence
		);
	}

	public function testStrictExistingBreakSuppressesInsert(): void
	{
		// 9h10m presence with 30m recorded → consistent already, insert 0.
		$r = $this->resolver->minimalConsistentBreakSeconds(9 * 3600 + 10 * 60, 30 * 60, 'strict');
		$this->assertSame(30 * 60, $r['total_seconds']);
		$this->assertSame(0, $r['insert_seconds']);

		// 9h40m presence with 30m recorded → still short; insert the delta.
		$r = $this->resolver->minimalConsistentBreakSeconds(9 * 3600 + 40 * 60, 30 * 60, 'strict');
		$this->assertSame(45 * 60, $r['total_seconds']);
		$this->assertSame(15 * 60, $r['insert_seconds']);
	}

	public function testStrictExcessExistingBreakNeverNegative(): void
	{
		// Employee recorded more than needed — never a negative insert.
		$r = $this->resolver->minimalConsistentBreakSeconds(9 * 3600 + 10 * 60, 60 * 60, 'strict');
		$this->assertSame(30 * 60, $r['total_seconds']);
		$this->assertSame(0, $r['insert_seconds']);
	}

	// ------------------------------------------------------------------
	// Cap-overhang — spec §9.4 worked examples + A7–A11
	// ------------------------------------------------------------------

	public function testCapOverhangWorkedExamples(): void
	{
		$h = 3600; $m = 60;
		// [presence, expected total deduction, expected capped flag]
		$cases = [
			'5h59m' => [5 * $h + 59 * $m, 0, false],
			'6h00m01s' => [6 * $h + 1, 1 * $m, true],   // sub-minute overhang ceil→1m (EC-2)
			'6h10m' => [6 * $h + 10 * $m, 10 * $m, true],
			'6h45m' => [6 * $h + 45 * $m, 30 * $m, false], // equals strict tier amount
			'9h05m' => [9 * $h + 5 * $m, 30 * $m, false],
			'9h20m' => [9 * $h + 20 * $m, 30 * $m, false],
			'9h31m' => [9 * $h + 31 * $m, 31 * $m, true],  // net lands on 9h00m
			'9h40m' => [9 * $h + 40 * $m, 40 * $m, true],
			'9h50m' => [9 * $h + 50 * $m, 45 * $m, false], // saturates at requirement
			'10h00m' => [10 * $h, 45 * $m, false],
		];
		foreach ($cases as $label => [$presence, $expectedTotal, $expectedCapped]) {
			$r = $this->resolver->minimalConsistentBreakSeconds($presence, 0, 'cap_overhang');
			$this->assertSame($expectedTotal, $r['total_seconds'], $label);
			$this->assertSame($expectedTotal, $r['insert_seconds'], $label);
			$this->assertSame($expectedCapped, $r['capped'], $label);

			// NN-02 invariant: cap output still satisfies R(P−d*) ≤ d*.
			$net = max(0, $presence - $r['total_seconds']);
			$this->assertLessThanOrEqual(
				$r['total_seconds'],
				$this->resolver->requiredBreakMinutes($net) * 60,
				'consistency for ' . $label
			);
		}
	}

	public function testCapOverhangRespectsExistingBreak(): void
	{
		// 6h10m presence, 5m recorded → cap totals 10m, insert 5m.
		$r = $this->resolver->minimalConsistentBreakSeconds(6 * 3600 + 10 * 60, 5 * 60, 'cap_overhang');
		$this->assertSame(10 * 60, $r['total_seconds']);
		$this->assertSame(5 * 60, $r['insert_seconds']);

		// 9h40m presence, 30m recorded → cap totals 40m, insert 10m.
		$r = $this->resolver->minimalConsistentBreakSeconds(9 * 3600 + 40 * 60, 30 * 60, 'cap_overhang');
		$this->assertSame(40 * 60, $r['total_seconds']);
		$this->assertSame(10 * 60, $r['insert_seconds']);
		$this->assertTrue($r['capped']);
	}

	public function testCapOverhangCustomTierLatticeEc12(): void
	{
		// Org adds a 4h→20m tier below the statutory 6h→30 (EC-12/A-11).
		$this->setPolicy(['tiers' => [['afterHours' => 4, 'breakMinutes' => 20]]]);

		$r = $this->resolver->minimalConsistentBreakSeconds(6 * 3600 + 5 * 60, 0, 'cap_overhang');
		// min(30m, overhang 5m)=5 → required(6h00m)=20m > 5 → land on 20m.
		$this->assertSame(20 * 60, $r['total_seconds']);
		$net = 6 * 3600 + 5 * 60 - $r['total_seconds'];
		$this->assertLessThanOrEqual(
			$r['total_seconds'],
			$this->resolver->requiredBreakMinutes($net) * 60
		);
	}

	/**
	 * Property invariant over the presence lattice for both modes and a
	 * few representative existing-break values (NN-02).
	 */
	public function testConsistencyInvariantAcrossLattice(): void
	{
		foreach (['strict', 'cap_overhang'] as $mode) {
			foreach ([0, 10 * 60, 30 * 60] as $existing) {
				for ($p = 5 * 3600; $p <= 11 * 3600; $p += 67) {
					$r = $this->resolver->minimalConsistentBreakSeconds($p, $existing, $mode);
					$total = $r['total_seconds'];
					$net = max(0, $p - $total);
					$this->assertLessThanOrEqual(
						$total,
						$this->resolver->requiredBreakMinutes($net) * 60,
						"mode=$mode P=$p E=$existing"
					);
					$this->assertLessThanOrEqual($p, $total, "deduction never exceeds presence");
				}
			}
		}
	}

	public function testChFractionalThresholdStrict(): void
	{
		// CH tiers include a 5.5h threshold — second-precision comparisons,
		// never float equality (EC-13).
		$this->appValues['country'] = 'CH';
		$this->resolver->clearCache();
		for ($p = 4 * 3600; $p <= 10 * 3600; $p += 137) {
			$r = $this->resolver->minimalConsistentBreakSeconds($p, 0, 'strict');
			$net = max(0, $p - $r['total_seconds']);
			$this->assertLessThanOrEqual(
				$r['total_seconds'],
				$this->resolver->requiredBreakMinutes($net) * 60,
				"CH P=$p"
			);
		}
		$this->appValues['country'] = 'DE';
		$this->resolver->clearCache();
	}

	public function testModelTierParticipatesInFixpoint(): void
	{
		// Model tier (>4h→60m) merges as a producer — the fixpoint must
		// evaluate the effective merged tiers, not just law.
		$this->assignModel(['tiers' => [['afterHours' => 4, 'breakMinutes' => 60]]]);
		$r = $this->resolver->minimalConsistentBreakSeconds(4 * 3600 + 10 * 60, 0, 'strict', 'u1');
		// Candidates {0,60m,30m,45m}: R(4h10m−30m=3h40m)=0≤30m → D*=30m.
		// Hmm — 30m is a statutory-amount candidate that satisfies R(P−30m)=0.
		// The strict target is the SMALLEST consistent statutory amount.
		$this->assertSame(30 * 60, $r['total_seconds']);
		$net = 4 * 3600 + 10 * 60 - $r['total_seconds'];
		$this->assertLessThanOrEqual(
			$r['total_seconds'],
			$this->resolver->requiredBreakMinutes($net, 'u1') * 60
		);
	}
}
