<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Repair;

use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicy;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper;
use OCA\ArbeitszeitCheck\Repair\SeedWorkLocationCatalog;
use OCA\ArbeitszeitCheck\Service\WorkLocationCatalog;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class SeedWorkLocationCatalogTest extends TestCase
{
	public function testSeedsMissingBuiltinsAndSkipsExisting(): void
	{
		$definitions = $this->createMock(WorkLocationDefinitionMapper::class);
		$policies = $this->createMock(WorkLocationDefaultPolicyMapper::class);
		$settings = $this->createMock(UserSettingsMapper::class);

		$existing = [WorkLocationCatalog::BUILTIN_SEEDS[0]['code'] => true];
		$definitions->method('codeExists')->willReturnCallback(
			static fn (string $code): bool => isset($existing[$code])
		);
		$definitions->expects(self::exactly(count(WorkLocationCatalog::BUILTIN_SEEDS) - 1))
			->method('insert');

		$settings->expects(self::once())
			->method('findUserIdsWithNonEmptySetting')
			// The legacy key must stay the literal the pre-1.9.4 data was stored
			// under — this is what GH #45 proved cannot come from Constants.
			->with('work_location_default')
			->willReturn([]);

		(new SeedWorkLocationCatalog($definitions, $policies, $settings))
			->run($this->createMock(IOutput::class));
	}

	public function testMigratesOnlyEligibleLegacyUserDefaults(): void
	{
		$definitions = $this->createMock(WorkLocationDefinitionMapper::class);
		$policies = $this->createMock(WorkLocationDefaultPolicyMapper::class);
		$settings = $this->createMock(UserSettingsMapper::class);

		$definitions->method('codeExists')->willReturnCallback(
			static fn (string $code): bool => in_array($code, ['office', 'home'], true)
		);
		$settings->method('findUserIdsWithNonEmptySetting')
			->with('work_location_default')
			->willReturn(['alice', 'bob', 'carol', 'dave']);
		$settings->method('getStringSetting')->willReturnCallback(
			static function (string $userId, string $key, string $default = ''): string {
				self::assertSame('work_location_default', $key);
				return match ($userId) {
					'alice' => 'home',
					'carol' => 'unknown_code',
					default => $default,
				};
			}
		);
		$policies->method('findByScope')->willReturnCallback(
			static fn (string $scope, string $scopeId): array => $scopeId === 'bob' ? [new WorkLocationDefaultPolicy()] : []
		);

		$inserted = [];
		$policies->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (WorkLocationDefaultPolicy $policy) use (&$inserted) {
				$inserted[] = $policy;
				return $policy;
			});

		(new SeedWorkLocationCatalog($definitions, $policies, $settings))
			->run($this->createMock(IOutput::class));

		self::assertCount(1, $inserted);
		$policy = $inserted[0];
		self::assertSame(WorkLocationDefaultPolicy::SCOPE_USER, $policy->getScopeType());
		self::assertSame('alice', $policy->getScopeId());
		self::assertSame('home', $policy->getLocationCode());
		self::assertSame(0, $policy->getPriority());
		self::assertSame(1, $policy->getRevision());
		self::assertSame('system:upgrade', $policy->getCreatedBy());
		self::assertSame('1970-01-01', $policy->getEffectiveFrom()->format('Y-m-d'));
	}
}
