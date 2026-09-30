<?php

declare(strict_types=1);

/**
 * Atlas coverage lane — focused tests for small Support helpers that had
 * residual uncovered branches (BadgeVariant status maps, PremiumPolicy
 * serialization, VacationYearWindow::toArray, SupportUsLinks::contactMailto,
 * HoursDisplay::normalize, LaborLawProfileFactory cache/Swiss cap).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Support\BadgeVariant;
use OCA\ArbeitszeitCheck\Support\HoursDisplay;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\PremiumPolicy;
use OCA\ArbeitszeitCheck\Support\SupportUsLinks;
use OCA\ArbeitszeitCheck\Support\VacationYearWindow;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class SupportHelpersCoverageTest extends TestCase
{
	public function testBadgeVariantTariffRuleSetStatus(): void
	{
		$this->assertSame(BadgeVariant::SUCCESS, BadgeVariant::forTariffRuleSetStatus('active'));
		$this->assertSame(BadgeVariant::WARNING, BadgeVariant::forTariffRuleSetStatus('draft'));
		$this->assertSame(BadgeVariant::SECONDARY, BadgeVariant::forTariffRuleSetStatus('retired'));
		$this->assertSame(BadgeVariant::SECONDARY, BadgeVariant::forTariffRuleSetStatus('bogus'));
	}

	public function testBadgeVariantOvertimePayoutStatus(): void
	{
		$this->assertSame(BadgeVariant::SUCCESS, BadgeVariant::forOvertimePayoutStatus('paid'));
		$this->assertSame(BadgeVariant::WARNING, BadgeVariant::forOvertimePayoutStatus('pending'));
		$this->assertSame(BadgeVariant::SECONDARY, BadgeVariant::forOvertimePayoutStatus('rejected'));
	}

	public function testHoursDisplayNormalize(): void
	{
		$this->assertSame(HoursDisplay::MODE_HOURS_MINUTES, HoursDisplay::normalize('hours_minutes'));
		$this->assertSame(HoursDisplay::MODE_HOURS_MINUTES, HoursDisplay::normalize('  hours_minutes '));
		$this->assertSame(HoursDisplay::MODE_DECIMAL, HoursDisplay::normalize('decimal'));
		$this->assertSame(HoursDisplay::MODE_DECIMAL, HoursDisplay::normalize(null));
		$this->assertSame(HoursDisplay::MODE_DECIMAL, HoursDisplay::normalize('bogus'));
	}

	public function testVacationYearWindowToArray(): void
	{
		$w = new VacationYearWindow(
			VacationYearWindow::MODE_CALENDAR,
			2026,
			new \DateTimeImmutable('2026-01-01'),
			new \DateTimeImmutable('2027-01-01'),
			'2026',
			true,
		);
		$arr = $w->toArray();
		$this->assertSame('calendar', $arr['mode']);
		$this->assertSame(2026, $arr['balance_year']);
		$this->assertSame('2026-01-01', $arr['start']);
		$this->assertSame('2026-12-31', $arr['end_inclusive']);
		$this->assertSame('2026', $arr['label']);
		$this->assertTrue($arr['missing_employment_start']);
	}

	public function testSupportUsLinksContactMailto(): void
	{
		$links = new SupportUsLinks('ArbeitszeitCheck');
		$this->assertSame('mailto:info@software-by-design.de', $links->contactMailto());
	}

	public function testPremiumPolicyToArray(): void
	{
		$policy = PremiumPolicy::fromValidated([
			'stacking' => PremiumPolicy::STACKING_ADDITIVE,
			'holiday_policy' => 'ignore',
			'categories' => [
				['id' => 'night', 'label' => 'Night', 'rate' => 1.25, 'applies_to' => PremiumPolicy::APPLIES_TIME_WINDOW, 'window_start' => '22:00', 'window_end' => '06:00'],
				['id' => 'sat', 'rate' => 1.5, 'applies_to' => PremiumPolicy::APPLIES_WEEKDAY, 'weekdays' => ['sat', 'nope', 'SAT']],
				['id' => 'skip-me', 'applies_to' => 'unknown_kind'],
			],
		]);
		$arr = $policy->toArray();
		$this->assertSame(PremiumPolicy::VERSION, $arr['version']);
		$this->assertSame('hours_only', $arr['currency_mode']);
		$this->assertSame(PremiumPolicy::STACKING_ADDITIVE, $arr['stacking']);
		$this->assertSame('ignore', $arr['holiday_policy']);
		$this->assertCount(2, $arr['categories']); // unknown applies_to dropped
		$this->assertSame(['sat'], $arr['categories'][1]['weekdays']); // normalized/deduped
	}

	public function testLaborLawProfileFactoryClearCacheAndSwissMax(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn ($app, $key, $default) => match ($key) {
				'weekly_absolute_max_hours' => '50',
				default => $default,
			}
		);
		$mapper = $this->createMock(UserSettingsMapper::class);
		$factory = new LaborLawProfileFactory($config, $mapper);

		$this->assertSame(50.0, $factory->resolveSwissWeeklyAbsoluteMax());
		$factory->clearCache(); // idempotent drop — must not throw
		$this->assertSame(50.0, $factory->resolveSwissWeeklyAbsoluteMax());
	}

	public function testLaborLawProfileFactorySwissMaxFallback(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn ($app, $key, $default) => $key === 'weekly_absolute_max_hours' ? '48' : $default
		);
		$factory = new LaborLawProfileFactory($config);
		// anything other than 50 falls back to the 45 h statutory cap
		$this->assertSame(45.0, $factory->resolveSwissWeeklyAbsoluteMax());
	}

	public function testBadgeVariantBoolean(): void
	{
		$this->assertSame(BadgeVariant::SUCCESS, BadgeVariant::forBooleanEnabled(true));
		$this->assertSame(BadgeVariant::ERROR, BadgeVariant::forBooleanEnabled(false));
	}

	public function testPremiumPolicyBlankPresetAndCategories(): void
	{
		$preset = PremiumPolicy::blankPreset();
		$this->assertSame('hours_only', $preset['currency_mode']);
		$this->assertSame([], $preset['categories']);

		$policy = PremiumPolicy::tryFromArray(PremiumPolicy::blankPreset());
		$this->assertNotNull($policy);
		$this->assertSame([], $policy->getCategories());
	}

	public function testHoursDisplayModes(): void
	{
		$this->assertSame(
			[HoursDisplay::MODE_DECIMAL, HoursDisplay::MODE_HOURS_MINUTES],
			HoursDisplay::modes()
		);
	}

	public function testVacationYearWindowContains(): void
	{
		$w = new VacationYearWindow(
			'calendar', 2026,
			new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'),
			'2026'
		);
		$this->assertTrue($w->contains(new \DateTime('2026-06-15 13:37')));
		$this->assertFalse($w->contains(new \DateTime('2027-01-01')));
		$this->assertFalse($w->contains(new \DateTime('2025-12-31')));
	}

	public function testSupportUsLinksDisplayName(): void
	{
		$links = new SupportUsLinks('ArbeitszeitCheck');
		$this->assertSame('ArbeitszeitCheck', $links->appDisplayName());
	}

	public function testBreakCountableStamp(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn ($app, $key, $default) => $default
		);
		$profile = (new LaborLawProfileFactory($config))->getProfile(null);
		$entry = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		\OCA\ArbeitszeitCheck\Support\BreakCountable::stampEntry($entry, $profile);
		$this->assertSame($profile->minBreakMinutes, $entry->getCountableMinBreakMinutes());

		$config2 = $this->createMock(IConfig::class);
		$config2->method('getAppValue')->willReturnCallback(
			static fn ($app, $key, $default) => $default
		);
		$factory = new LaborLawProfileFactory($config2);
		$entry2 = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$entry2->setUserId('alice');
		\OCA\ArbeitszeitCheck\Support\BreakCountable::stampEntryForUser($entry2, $factory);
		$this->assertNotNull($entry2->getCountableMinBreakMinutes());
	}

	public function testTemplateL10nMap(): void
	{
		$l = $this->createMock(\OCP\IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s, array $p = []) => $s);
		$map = \OCA\ArbeitszeitCheck\Util\TemplateL10n::mapFromMessageIds($l, ['a', 'b']);
		$this->assertSame(['a' => 'a', 'b' => 'b'], $map);
	}

	public function testKioskCryptoHelpers(): void
	{
		$this->assertSame('04A3F2', \OCA\ArbeitszeitCheck\Kiosk\KioskCrypto::normalizeRfidUid(' 04:a3-f2 '));
		$h = \OCA\ArbeitszeitCheck\Kiosk\KioskCrypto::rfidLookupHash('04A3F2', 'salt');
		$this->assertSame(hash_hmac('sha256', '04A3F2', 'salt'), $h);
	}

	public function testLaborLawProfileForCurrentUserFallsBack(): void
	{
		// \OCP\Server::get is unavailable in unit context → uid=null arm
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn ($app, $key, $default) => $default
		);
		$factory = new LaborLawProfileFactory($config);
		$this->assertInstanceOf(
			\OCA\ArbeitszeitCheck\Support\LaborLawProfile::class,
			$factory->getProfileForCurrentUser()
		);
	}

	public function testStampReplayExceptionErrorCode(): void
	{
		$e = new StampReplayException('REPLAY_DETECTED', 'dup');
		$this->assertSame('REPLAY_DETECTED', $e->getErrorCode());
		$this->assertSame('dup', $e->getMessage());

		$e2 = new StampReplayException('REPLAY_DETECTED');
		$this->assertSame('REPLAY_DETECTED', $e2->getMessage()); // falls back to code
	}
}
