<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Util;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Util\AbsenceCreateQueryPrefill;
use PHPUnit\Framework\TestCase;

class AbsenceCreateQueryPrefillTest extends TestCase
{
	public function testSickLeaveIsKept(): void
	{
		self::assertSame(Absence::TYPE_SICK_LEAVE, AbsenceCreateQueryPrefill::type('sick_leave'));
		self::assertSame(Absence::TYPE_SICK_LEAVE, AbsenceCreateQueryPrefill::type('  sick_leave  '));
	}

	public function testVacationBookmarkStillPrefills(): void
	{
		self::assertSame(Absence::TYPE_VACATION, AbsenceCreateQueryPrefill::type('vacation'));
	}

	public function testUnknownInactiveAndNonStringsPrefillNothing(): void
	{
		self::assertSame('', AbsenceCreateQueryPrefill::type(''));
		self::assertSame('', AbsenceCreateQueryPrefill::type('   '));
		self::assertSame('', AbsenceCreateQueryPrefill::type('SICK_LEAVE'));
		self::assertSame('', AbsenceCreateQueryPrefill::type('sick_leave<script>'));
		self::assertSame('', AbsenceCreateQueryPrefill::type('not_a_real_type'));
		self::assertSame('', AbsenceCreateQueryPrefill::type(['sick_leave']));
		self::assertSame('', AbsenceCreateQueryPrefill::type(null));
	}
}
