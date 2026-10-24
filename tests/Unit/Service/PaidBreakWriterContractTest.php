<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use PHPUnit\Framework\TestCase;

/**
 * Static contract gates for the paid-break snapshot (SPEC §11):
 *
 * - MF-04: `paid_break_seconds` is allocator-owned. No code path outside
 *   {@see PaidBreakAllocator} may write the field — not controllers, not
 *   mappers, not jobs — and client payloads must not carry it (TH-02).
 * - NN-05: the API payload contract stays additive — `getSummary()` always
 *   emits the paid keys so old clients ignore them and new clients find them.
 * - NN-09: {@see PaidBreakAllocator::recomputeSpan()} must only run under
 *   the per-user mutation lock. Every internal call site lives inside a
 *   function that acquires the lock or a helper only invoked from one.
 */
final class PaidBreakWriterContractTest extends TestCase
{
	private static function libFiles(): array
	{
		$appRoot = \dirname(__DIR__, 3);
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($appRoot . '/lib', \FilesystemIterator::SKIP_DOTS)
		);
		$files = [];
		foreach ($it as $f) {
			if ($f->isFile() && $f->getExtension() === 'php') {
				$files[] = $f->getPathname();
			}
		}
		sort($files);
		return $files;
	}

	public function testPaidBreakSecondsHasASingleWriter(): void
	{
		$writers = [];
		foreach (self::libFiles() as $file) {
			$src = file_get_contents($file);
			foreach (explode("\n", $src) as $i => $line) {
				if (strpos($line, '->setPaidBreakSeconds(') !== false) {
					$writers[] = $file . ':' . ($i + 1);
				}
				// QB-level column writes are writers too.
				if (preg_match("/->set\\(\\s*['\"]paid_break_seconds['\"]/", $line)) {
					$writers[] = $file . ':' . ($i + 1) . ' (qb)';
				}
			}
		}
		foreach ($writers as $w) {
			$this->assertStringContainsString(
				'lib/Service/PaidBreakAllocator.php',
				$w,
				"paid_break_seconds written outside the allocator: {$w}"
			);
		}
		$this->assertNotEmpty($writers, 'allocator must actually write the snapshot');
	}

	public function testRequestBoundariesNeverWriteClientPaidBreakSeconds(): void
	{
		// TH-02: the controller maps request params via an explicit setter
		// whitelist — assert no path reads the snapshot field from input.
		$appRoot = \dirname(__DIR__, 3);
		$controller = file_get_contents($appRoot . '/lib/Controller/TimeEntryController.php');
		$this->assertStringNotContainsString(
			'setPaidBreakSeconds',
			$controller,
			'controller must never call the allocator-owned setter'
		);
		foreach (['paid_break_seconds', 'paidBreakSeconds', 'paid_duration_hours', 'paidDurationHours'] as $field) {
			$this->assertDoesNotMatchRegularExpression(
				'/params\[\s*[\'"]' . $field . '[\'"]/',
				$controller,
				"controller reads {$field} from the request"
			);
		}

		// The correction service applies proposal payloads generically — the
		// defense-in-depth scrub there is required.
		$correction = file_get_contents($appRoot . '/lib/Service/TimeEntryCorrectionService.php');
		$this->assertMatchesRegularExpression(
			"/unset\\([^)]*paid_break_seconds/s",
			$correction,
			'correction proposal path must unset the client-authored snapshot'
		);
	}

	public function testSummaryPayloadStaysAdditive(): void
	{
		// NN-05: getSummary() always emits the paid keys — additive means the
		// key exists with a defined value, not just when > 0.
		$entry = new TimeEntry();
		$summary = $entry->getSummary();
		foreach (['paidBreakSeconds', 'paidBreakMinutes', 'paidBreakHours', 'paidDurationHours'] as $key) {
			$this->assertArrayHasKey($key, $summary, "summary lost the additive key {$key}");
		}
		$this->assertSame(0, $summary['paidBreakSeconds']);
		$this->assertSame(0, $summary['paidBreakMinutes']);

		// On a real entry the values are numeric, never raw strings.
		$entry->setStartTime(new \DateTime('2026-05-12 08:00:00'));
		$entry->setEndTime(new \DateTime('2026-05-12 17:00:00'));
		$entry->setPaidBreakSeconds(1800);
		$summary = $entry->getSummary();
		$this->assertSame(1800, $summary['paidBreakSeconds']);
		$this->assertSame(30, $summary['paidBreakMinutes']);
		$this->assertIsFloat($summary['paidDurationHours'] + 0.0);
	}

	public function testAllocatorIsOnlyInvokedUnderTheUserLock(): void
	{
		$appRoot = \dirname(__DIR__, 3);
		$service = file_get_contents($appRoot . '/lib/Service/TimeTrackingService.php');

		// 1) The allocator must be touched exactly once inside the service —
		//    the private already-locked helper.
		$this->assertSame(
			1,
			substr_count($service, '->recomputeSpan('),
			'recomputeSpan must funnel through a single already-locked helper'
		);
		$this->assertStringContainsString(
			'refreshPaidBreaksAlreadyLocked',
			$service
		);

		// 2) No other lib/ file may resolve or call the allocator — docblock
		//    {@see} references are fine, code references are not.
		foreach (self::libFiles() as $file) {
			if (str_ends_with($file, 'Service/PaidBreakAllocator.php')
				|| str_ends_with($file, 'Service/TimeTrackingService.php')) {
				continue;
			}
			$src = file_get_contents($file);
			$this->assertStringNotContainsString(
				'PaidBreakAllocator::class',
				$src,
				basename($file) . ' resolves the allocator directly'
			);
			$this->assertStringNotContainsString(
				'new PaidBreakAllocator',
				$src,
				basename($file) . ' instantiates the allocator directly'
			);
			$this->assertStringNotContainsString(
				'->recomputeSpan(',
				$src,
				basename($file) . ' calls the allocator directly — go through recomputePaidBreaksForUserSpan()'
			);
		}

		// 3) Every internal call site of the already-locked helper lives in a
		//    function that acquires the lock, or in a private helper that is
		//    itself only called from lock-holding functions (depth ≤ 3).
		$funcs = [];
		if (preg_match_all('/(private|public|protected)\s+function\s+(\w+)/', $service, $m, PREG_OFFSET_CAPTURE)) {
			foreach ($m[0] as $i => $cap) {
				$funcs[] = ['pos' => $cap[1], 'name' => $m[2][$i][0]];
			}
		}
		$fnLocks = function (string $name) use ($funcs, $service): bool {
			foreach ($funcs as $i => $f) {
				if ($f['name'] !== $name) {
					continue;
				}
				$end = $funcs[$i + 1]['pos'] ?? strlen($service);
				$body = substr($service, $f['pos'], $end - $f['pos']);
				return str_contains($body, 'acquireUserMutationLock')
					|| str_contains($body, 'withUserMutationLock');
			}
			return false;
		};
		$callersOf = function (string $name) use ($funcs, $service): array {
			$owners = [];
			foreach ($funcs as $i => $f) {
				$end = $funcs[$i + 1]['pos'] ?? strlen($service);
				$body = substr($service, $f['pos'], $end - $f['pos']);
				if (substr_count($body, '->' . $name . '(') > 0) {
					$owners[] = $f['name'];
				}
			}
			return $owners;
		};

		$helperOk = function (string $fn, int $depth) use (&$helperOk, $fnLocks, $callersOf): bool {
			if ($fnLocks($fn)) {
				return true;
			}
			if ($depth <= 0) {
				return false;
			}
			foreach ($callersOf($fn) as $caller) {
				if ($caller === $fn) {
					continue;
				}
				if ($helperOk($caller, $depth - 1)) {
					return true;
				}
			}
			return false;
		};

		foreach (['refreshPaidBreaksAlreadyLocked', 'recomputePaidBreaksForUserSpan'] as $entry) {
			foreach ($callersOf($entry) as $caller) {
				$this->assertTrue(
					$helperOk($caller, 3),
					"{$entry} called from {$caller} which never reaches the user mutation lock"
				);
			}
		}
	}

	/**
	 * NH-04: an org-cap change must enqueue recomputation for exactly the
	 * models that inherit the org default — flag on AND no own cap (an
	 * explicit model cap, including 0, wins and must not be recomputed).
	 */
	public function testOrgCapChangeEnqueuesRecomputeForInheritingModelsOnly(): void
	{
		$appRoot = \dirname(__DIR__, 3);
		$admin = file_get_contents($appRoot . '/lib/Controller/AdminController.php');

		// The enqueue must be gated on the org cap actually changing.
		$this->assertStringContainsString(
			'$paidCapChanged =',
			$admin,
			'persistBreakPolicyLocked must detect the org-cap delta'
		);

		// The inheriting-model filter mirrors BreakPolicyResolver
		// ::paidBreakPolicyForUser — flag true + own cap null.
		$this->assertMatchesRegularExpression(
			"/breaks_count_as_work'\s*\]\s*===\s*true\s*&&\s*\\\$rules\['paid_break_cap_minutes'\]\s*===\s*null/",
			$admin,
			'org-cap enqueue must target only models that inherit the org default'
		);
		$this->assertStringContainsString(
			'PaidBreakRecomputeJob::enqueueForModel',
			$admin,
			'org-cap change must enqueue the recompute job'
		);

		// Both enqueue sites (model-policy save, org-policy save) exist.
		$this->assertGreaterThanOrEqual(
			2,
			substr_count($admin, 'PaidBreakRecomputeJob::enqueueForModel'),
			'expected model-save and org-save enqueue sites'
		);
	}

	public function testMigrationAndColumnExist(): void
	{
		$appRoot = \dirname(__DIR__, 3);
		$found = false;
		foreach (glob($appRoot . '/lib/Migration/*.php') as $file) {
			if (str_contains(file_get_contents($file), 'paid_break_seconds')) {
				$found = true;
			}
		}
		$this->assertTrue($found, 'no migration declares at_time_entries.paid_break_seconds');
	}
}
