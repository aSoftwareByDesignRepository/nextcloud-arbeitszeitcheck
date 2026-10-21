<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\CompliancePageScope;
use PHPUnit\Framework\TestCase;

final class CompliancePageScopeTest extends TestCase {
	public function testOwnPageDoesNotAskForPermission(): void {
		$asked = [];
		$scope = CompliancePageScope::resolve('employee', null, $this->spy($asked));
		self::assertSame('employee', $scope['targetUserId']);
		self::assertNull($scope['filterUserId']);
		self::assertSame([], $asked);

		$scope = CompliancePageScope::resolve('employee', '   ', $this->spy($asked));
		self::assertNull($scope['filterUserId']);
		self::assertSame([], $asked);

		$scope = CompliancePageScope::resolve('employee', 'employee', $this->spy($asked));
		self::assertSame('employee', $scope['targetUserId']);
		self::assertNull($scope['filterUserId']);
		self::assertSame([], $asked);
	}

	public function testNonStringRequestIsTheViewersOwnPage(): void {
		$asked = [];
		foreach ([[ 'foreign' ], 12, 12.5, true, new \stdClass()] as $requested) {
			$scope = CompliancePageScope::resolve('employee', $requested, $this->spy($asked));
			self::assertSame('employee', $scope['targetUserId'], get_debug_type($requested));
			self::assertNull($scope['filterUserId']);
		}
		self::assertSame([], $asked);
	}

	public function testAllowedEmployeeIsTheOnlyOtherPage(): void {
		$asked = [];
		$scope = CompliancePageScope::resolve('manager', '  employee1  ', function (string $actor, string $target) use (&$asked): bool {
			$asked[] = [$actor, $target];
			return $actor === 'manager' && $target === 'employee1';
		});
		self::assertSame([['manager', 'employee1']], $asked);
		self::assertSame('employee1', $scope['targetUserId']);
		self::assertSame('employee1', $scope['filterUserId']);
	}

	public function testDeniedEmployeeThrowsAndDoesNotReturnThatId(): void {
		try {
			CompliancePageScope::resolve('employee', 'boss', static fn (string $actor, string $target): bool => false);
			self::fail('A foreign id must not resolve');
		} catch (\InvalidArgumentException $e) {
			self::assertSame(CompliancePageScope::DENIED, $e->getMessage());
			self::assertStringNotContainsString('boss', $e->getMessage());
		}
	}

	public function testTruthyNonBooleanPermissionIsDenied(): void {
		$this->expectException(\InvalidArgumentException::class);
		CompliancePageScope::resolve('employee', 'boss', static fn (): int => 1);
	}

	/**
	 * @param list<array{0: string, 1: string}> $asked
	 */
	private function spy(array &$asked): callable {
		return static function (string $actor, string $target) use (&$asked): bool {
			$asked[] = [$actor, $target];
			return false;
		};
	}
}
