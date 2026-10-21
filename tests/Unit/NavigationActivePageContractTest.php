<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every shell pageId must select a sidebar entry. A prefix that skips the
 * bare id (compliance- vs compliance) leaves that page with no current item.
 */
final class NavigationActivePageContractTest extends TestCase {
	public function testEveryShellPageIdMarksASidebarEntry(): void {
		$root = dirname(__DIR__, 2);
		$nav = (string) file_get_contents($root . '/templates/common/navigation.php');

		preg_match_all('/\$pageId === \'([^\']+)\'/', $nav, $exactMatch);
		$exact = $exactMatch[1];
		preg_match_all('/str_starts_with\(\$pageId, \'([^\']+)\'\)/', $nav, $prefixMatch);
		$prefixes = $prefixMatch[1];

		$ids = [];
		foreach (glob($root . '/lib/Controller/*.php') ?: [] as $file) {
			$src = (string) file_get_contents($file);
			preg_match_all('/build(?:Admin|Manager)?ShellParams\(\s*\'([^\']+)\'/', $src, $oneLine);
			preg_match_all('/build(?:Admin|Manager)?ShellParams\(\s*\n\s*\'([^\']+)\'/', $src, $multiLine);
			foreach (array_merge($oneLine[1], $multiLine[1]) as $id) {
				$ids[$id] = true;
			}
		}

		self::assertNotEmpty($ids);
		foreach (array_keys($ids) as $id) {
			$hit = in_array($id, $exact, true);
			if (!$hit) {
				foreach ($prefixes as $prefix) {
					if (str_starts_with($id, $prefix)) {
						$hit = true;
						break;
					}
				}
			}
			self::assertTrue($hit, $id . ' has no sidebar active match');
		}
		self::assertContains('compliance', $exact);
	}
}
