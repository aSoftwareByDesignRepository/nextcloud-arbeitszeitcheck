<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\AppInfo;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Repair\SeedWorkLocationCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the upgrade path against the "stale Constants class" fatal.
 *
 * During an in-request app update (settings/apps/update or occ upgrade) the
 * previously installed copy of OCA\ArbeitszeitCheck\Constants is already loaded
 * in PHP memory when new repair steps and migrations run. Any reference to a
 * constant added in the same release resolves against the *old* class and
 * throws "Undefined constant", leaving the instance stuck in maintenance mode
 * (GH #45 — SeedWorkLocationCatalog / SETTING_WORK_LOCATION_DEFAULT in 1.9.4;
 * the same latent pattern existed in Version1018/1030/1046).
 *
 * Rule: files under lib/Repair and lib/Migration must not reference
 * OCA\ArbeitszeitCheck\Constants at all. Stable wire/DB values (setting keys,
 * mode strings) are inlined as literals or private consts instead.
 */
final class UpgradePathConstantsSafetyTest extends TestCase
{
	/**
	 * @return list<string>
	 */
	private static function upgradePathFiles(): array
	{
		$root = dirname(__DIR__, 3) . '/lib';
		$files = array_merge(
			glob($root . '/Repair/*.php') ?: [],
			glob($root . '/Migration/*.php') ?: [],
		);
		sort($files);
		return $files;
	}

	public function testUpgradePathDoesNotReferenceAppConstants(): void
	{
		$offenders = [];
		foreach (self::upgradePathFiles() as $file) {
			$source = file_get_contents($file);
			$this->assertIsString($source, $file);
			if (preg_match('/\bConstants::|use\s+OCA\\\\ArbeitszeitCheck\\\\Constants\b/', $source) === 1) {
				$offenders[] = basename($file);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'Upgrade-path code must not reference OCA\ArbeitszeitCheck\Constants — '
			. 'the previous release\'s copy may already be loaded when repair steps '
			. 'and migrations run during an app update (GH #45). Inline stable values.',
		);
	}

	public function testInlinedLegacySettingKeyMatchesConstants(): void
	{
		$value = (new ReflectionClass(SeedWorkLocationCatalog::class))
			->getReflectionConstant('LEGACY_SETTING_KEY')
			->getValue();

		$this->assertSame(
			Constants::SETTING_WORK_LOCATION_DEFAULT,
			$value,
			'SeedWorkLocationCatalog::LEGACY_SETTING_KEY drifted from Constants::SETTING_WORK_LOCATION_DEFAULT',
		);
	}
}
