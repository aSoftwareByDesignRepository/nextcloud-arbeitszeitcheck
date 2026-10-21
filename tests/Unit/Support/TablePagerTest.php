<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\TablePager;
use PHPUnit\Framework\TestCase;

final class TablePagerTest extends TestCase {
	public function testDefaultSizeIs25AndOnlyTheOfferedSizesStick(): void {
		self::assertSame(25, TablePager::DEFAULT_SIZE);
		self::assertSame([10, 25, 50, 100], TablePager::SIZES);
		self::assertSame(25, TablePager::perPage(null));
		self::assertSame(25, TablePager::perPage(''));
		self::assertSame(25, TablePager::perPage('15'));
		self::assertSame(25, TablePager::perPage('25.0'));
		self::assertSame(25, TablePager::perPage('2e1'));
		self::assertSame(25, TablePager::perPage(' 25'));
		self::assertSame(25, TablePager::perPage(['25']));
		self::assertSame(10, TablePager::perPage('10'));
		self::assertSame(10, TablePager::perPage(10));
		self::assertSame(50, TablePager::perPage('50'));
		self::assertSame(100, TablePager::perPage('100'));
	}

	public function testPageClampsAndRejectsNonIntegers(): void {
		self::assertSame(1, TablePager::page(null, 8));
		self::assertSame(1, TablePager::page('0', 8));
		self::assertSame(1, TablePager::page('-1', 8));
		self::assertSame(1, TablePager::page('01', 8));
		self::assertSame(1, TablePager::page('2e1', 8));
		self::assertSame(1, TablePager::page(['2'], 8));
		self::assertSame(3, TablePager::page('3', 8));
		self::assertSame(8, TablePager::page('99', 8));
		self::assertSame(1, TablePager::page('4', 0));
	}

	public function testFirstPageOfEightMatchesTheFooterExample(): void {
		self::assertSame(
			[1, 2, 3, 4, TablePager::ELLIPSIS, 8],
			TablePager::items(1, 8),
		);
	}

	public function testWindowShrinksInTheMiddleAndAtTheEnd(): void {
		self::assertSame([1, 2, 3, 4, 5, 6, 7], TablePager::items(1, 7));
		self::assertSame([1], TablePager::items(1, 1));
		self::assertSame(
			[1, TablePager::ELLIPSIS, 5, 6, 7, 8],
			TablePager::items(5, 8),
		);
		self::assertSame(
			[1, TablePager::ELLIPSIS, 5, 6, 7, TablePager::ELLIPSIS, 12],
			TablePager::items(6, 12),
		);
		self::assertSame(
			[1, TablePager::ELLIPSIS, 5, 6, 7, 8],
			TablePager::items(8, 8),
		);
		self::assertSame(
			[1, 2, 3, 4, TablePager::ELLIPSIS, 10],
			TablePager::items(4, 10),
		);
	}

	public function testStateOffsetsTheVisibleSlice(): void {
		$state = TablePager::state(75, '2', '10');
		self::assertSame(75, $state['total']);
		self::assertSame(2, $state['page']);
		self::assertSame(10, $state['perPage']);
		self::assertSame(8, $state['pages']);
		self::assertSame(10, $state['offset']);
		self::assertSame([1, 2, 3, 4, TablePager::ELLIPSIS, 8], $state['items']);

		$first = TablePager::state(3, null, null);
		self::assertSame(1, $first['page']);
		self::assertSame(25, $first['perPage']);
		self::assertSame(0, $first['offset']);
		self::assertSame(1, $first['pages']);
	}

	public function testQueryKeepsFiltersAndOmitsTheDefaultPage(): void {
		self::assertSame('', TablePager::query([], 1, 25));
		self::assertSame('per_page=10', TablePager::query([], 1, 10));
		self::assertSame(
			'start_date=2026-01-01&end_date=2026-01-31&status=pending&page=2',
			TablePager::query([
				'start_date' => '2026-01-01',
				'end_date' => '2026-01-31',
				'status' => 'pending',
				'type' => '',
				'extra' => 'nope',
			], 2, 25),
		);
		self::assertSame(
			'type=vacation&per_page=50&page=3',
			TablePager::query(['type' => 'vacation', 'start_date' => 1], 3, 50),
		);
	}

	public function testQueryUsesEachTablesOwnKeysAndDropsUnsafeValues(): void {
		self::assertSame('page', TablePager::paramName('page', 'page'));
		self::assertSame('v_page', TablePager::paramName('v_page', 'page'));
		self::assertSame('userId', TablePager::paramName('userId', 'page'));
		self::assertSame('page', TablePager::paramName('v page', 'page'));
		self::assertSame('per_page', TablePager::paramName('../per_page', 'per_page'));

		self::assertSame(
			'work_location=home&userId=ada&v_per_page=10&v_page=2',
			TablePager::query(
				[
					'work_location' => 'home',
					'userId' => 'ada',
					'note' => "line\nbreak",
					'v_page' => '9',
				],
				2,
				10,
				['work_location', 'userId', 'note', 'v_page'],
				'v_page',
				'v_per_page',
			),
		);
		self::assertSame(
			'per_page=10&page=2',
			TablePager::query([], 2, 10, [], 'v page', 'Size!'),
		);
	}
}
