<?php

declare(strict_types=1);

/**
 * Unit tests for DatevExportService
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\DatevExportService;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Class DatevExportServiceTest
 */
class DatevExportServiceTest extends TestCase
{
	/** @var DatevExportService */
	private $service;

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->config = $this->createMock(IConfig::class);
		$this->l10n = $this->createMock(IL10N::class);

		// Setup l10n mock to return translation keys
		$this->l10n->method('t')
			->willReturnCallback(function ($text) {
				return $text;
			});

		$this->service = new DatevExportService(
			$this->timeEntryMapper,
			$this->config,
			$this->l10n
		);
	}

	/**
	 * Test exporting time entries with valid configuration
	 */
	public function testExportTimeEntriesSuccess(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		// Mock DATEV configuration
		$this->config->expects($this->exactly(4))
			->method('getAppValue')
			->willReturnMap([
				['arbeitszeitcheck', 'datev_beraternummer', '', '1234567'],
				['arbeitszeitcheck', 'datev_mandantennummer', '', '12345'],
				['arbeitszeitcheck', 'datev_lohnart_normal', '1000', '1000'],
				['arbeitszeitcheck', 'datev_lohnart_ueberstunden', '2000', '2000']
			]);

		$this->config->expects($this->once())
			->method('getUserValue')
			->with($userId, 'arbeitszeitcheck', 'datev_personalnummer', '')
			->willReturn('12345678');

		// Mock time entries
		$entry1 = $this->createMock(TimeEntry::class);
		$entry1->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
		$entry1->method('getEndTime')->willReturn(new \DateTime('2024-01-15 17:00:00'));
		$entry1->method('getStartTime')->willReturn(new \DateTime('2024-01-15 08:00:00'));
		$entry1->method('getWorkingDurationHours')->willReturn(8.0);
		$entry1->method('getDescription')->willReturn('Regular work');

		$entry2 = $this->createMock(TimeEntry::class);
		$entry2->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
		$entry2->method('getEndTime')->willReturn(new \DateTime('2024-01-16 18:00:00'));
		$entry2->method('getStartTime')->willReturn(new \DateTime('2024-01-16 09:00:00'));
		$entry2->method('getWorkingDurationHours')->willReturn(9.0);
		$entry2->method('getDescription')->willReturn(null);

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->with($userId, $startDate, $endDate)
			->willReturn([$entry1, $entry2]);

		$result = $this->service->exportTimeEntries($userId, $startDate, $endDate);

		$this->assertIsString($result);
		$this->assertStringContainsString('1234567|12345|2024|1', $result); // Header line
		$this->assertStringContainsString('12345678|20240115|1000|8.00|Std|Regular work', $result);
		$this->assertStringContainsString('12345678|20240116|1000|9.00|Std|', $result);
		$this->assertStringContainsString("\r\n", $result); // Windows line endings
	}

	/**
	 * Test exporting time entries with missing Beraternummer
	 */
	public function testExportTimeEntriesMissingBeraternummer(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$this->config->expects($this->once())
			->method('getAppValue')
			->with('arbeitszeitcheck', 'datev_beraternummer', '')
			->willReturn(''); // Empty Beraternummer

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('DATEV configuration incomplete');

		$this->service->exportTimeEntries($userId, $startDate, $endDate);
	}

	/**
	 * Test exporting time entries with missing Mandantennummer
	 */
	public function testExportTimeEntriesMissingMandantennummer(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$this->config->expects($this->exactly(2))
			->method('getAppValue')
			->willReturnMap([
				['arbeitszeitcheck', 'datev_beraternummer', '', '1234567'],
				['arbeitszeitcheck', 'datev_mandantennummer', '', ''] // Empty Mandantennummer
			]);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('DATEV configuration incomplete');

		$this->service->exportTimeEntries($userId, $startDate, $endDate);
	}

	/**
	 * Test exporting time entries with missing Personalnummer
	 */
	public function testExportTimeEntriesMissingPersonalnummer(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$this->config->expects($this->exactly(4))
			->method('getAppValue')
			->willReturnMap([
				['arbeitszeitcheck', 'datev_beraternummer', '', '1234567'],
				['arbeitszeitcheck', 'datev_mandantennummer', '', '12345'],
				['arbeitszeitcheck', 'datev_lohnart_normal', '1000', '1000'],
				['arbeitszeitcheck', 'datev_lohnart_ueberstunden', '2000', '2000']
			]);

		$this->config->expects($this->once())
			->method('getUserValue')
			->with($userId, 'arbeitszeitcheck', 'datev_personalnummer', '')
			->willReturn(''); // Empty Personalnummer

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Personalnummer not configured');

		$this->service->exportTimeEntries($userId, $startDate, $endDate);
	}

	/**
	 * Test exporting time entries skips incomplete entries
	 */
	public function testExportTimeEntriesSkipsIncomplete(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$this->config->expects($this->exactly(4))
			->method('getAppValue')
			->willReturnMap([
				['arbeitszeitcheck', 'datev_beraternummer', '', '1234567'],
				['arbeitszeitcheck', 'datev_mandantennummer', '', '12345'],
				['arbeitszeitcheck', 'datev_lohnart_normal', '1000', '1000'],
				['arbeitszeitcheck', 'datev_lohnart_ueberstunden', '2000', '2000']
			]);

		$this->config->expects($this->once())
			->method('getUserValue')
			->willReturn('12345678');

		// Mock entries: one completed, one active (should be skipped)
		$completedEntry = $this->createMock(TimeEntry::class);
		$completedEntry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
		$completedEntry->method('getEndTime')->willReturn(new \DateTime('2024-01-15 17:00:00'));
		$completedEntry->method('getStartTime')->willReturn(new \DateTime('2024-01-15 08:00:00'));
		$completedEntry->method('getWorkingDurationHours')->willReturn(8.0);
		$completedEntry->method('getDescription')->willReturn('Completed');

		$activeEntry = $this->createMock(TimeEntry::class);
		$activeEntry->method('getStatus')->willReturn(TimeEntry::STATUS_ACTIVE);
		$activeEntry->method('getEndTime')->willReturn(null);

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$completedEntry, $activeEntry]);

		$result = $this->service->exportTimeEntries($userId, $startDate, $endDate);

		// Should only contain the completed entry
		$this->assertStringContainsString('12345678|20240115|1000|8.00|Std|Completed', $result);
		$this->assertStringNotContainsString('STATUS_ACTIVE', $result);
	}

	/**
	 * Test exporting time entries skips entries with zero hours
	 */
	public function testExportTimeEntriesSkipsZeroHours(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$this->config->expects($this->exactly(4))
			->method('getAppValue')
			->willReturnMap([
				['arbeitszeitcheck', 'datev_beraternummer', '', '1234567'],
				['arbeitszeitcheck', 'datev_mandantennummer', '', '12345'],
				['arbeitszeitcheck', 'datev_lohnart_normal', '1000', '1000'],
				['arbeitszeitcheck', 'datev_lohnart_ueberstunden', '2000', '2000']
			]);

		$this->config->expects($this->once())
			->method('getUserValue')
			->willReturn('12345678');

		// Mock entry with zero hours
		$zeroHourEntry = $this->createMock(TimeEntry::class);
		$zeroHourEntry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
		$zeroHourEntry->method('getEndTime')->willReturn(new \DateTime('2024-01-15 17:00:00'));
		$zeroHourEntry->method('getWorkingDurationHours')->willReturn(0.0);

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$zeroHourEntry]);

		$result = $this->service->exportTimeEntries($userId, $startDate, $endDate);

		// Should only contain header, no data lines
		$this->assertStringContainsString('1234567|12345|2024|1', $result);
		$lines = explode("\r\n", $result);
		$this->assertCount(1, $lines); // Only header line
	}

	/**
	 * Test exporting time entries truncates long descriptions
	 */
	public function testExportTimeEntriesTruncatesDescription(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$this->config->expects($this->exactly(4))
			->method('getAppValue')
			->willReturnMap([
				['arbeitszeitcheck', 'datev_beraternummer', '', '1234567'],
				['arbeitszeitcheck', 'datev_mandantennummer', '', '12345'],
				['arbeitszeitcheck', 'datev_lohnart_normal', '1000', '1000'],
				['arbeitszeitcheck', 'datev_lohnart_ueberstunden', '2000', '2000']
			]);

		$this->config->expects($this->once())
			->method('getUserValue')
			->willReturn('12345678');

		// Mock entry with very long description (should be truncated to 20 chars)
		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
		$entry->method('getEndTime')->willReturn(new \DateTime('2024-01-15 17:00:00'));
		$entry->method('getStartTime')->willReturn(new \DateTime('2024-01-15 08:00:00'));
		$entry->method('getWorkingDurationHours')->willReturn(8.0);
		$entry->method('getDescription')->willReturn('This is a very long description that should be truncated');

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$entry]);

		$result = $this->service->exportTimeEntries($userId, $startDate, $endDate);

		// Description should be truncated to 20 characters
		$this->assertStringContainsString('This is a very long', $result);
		$this->assertStringNotContainsString('truncated', $result);
	}

	/**
	 * Test exporting multiple users
	 */
	public function testExportMultipleUsers(): void
	{
		$userIds = ['user1', 'user2'];
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$this->config->expects($this->exactly(2))
			->method('getAppValue')
			->willReturnMap([
				['arbeitszeitcheck', 'datev_beraternummer', '', '1234567'],
				['arbeitszeitcheck', 'datev_mandantennummer', '', '12345']
			]);

		// Mock user values
		$this->config->expects($this->exactly(2))
			->method('getUserValue')
			->willReturnMap([
				['user1', 'arbeitszeitcheck', 'datev_personalnummer', '', '11111111'],
				['user2', 'arbeitszeitcheck', 'datev_personalnummer', '', '22222222']
			]);

		// Mock time entries for both users
		$entry1 = $this->createMock(TimeEntry::class);
		$entry1->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
		$entry1->method('getEndTime')->willReturn(new \DateTime('2024-01-15 17:00:00'));
		$entry1->method('getStartTime')->willReturn(new \DateTime('2024-01-15 08:00:00'));
		$entry1->method('getWorkingDurationHours')->willReturn(8.0);
		$entry1->method('getDescription')->willReturn('User 1 work');

		$entry2 = $this->createMock(TimeEntry::class);
		$entry2->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
		$entry2->method('getEndTime')->willReturn(new \DateTime('2024-01-16 17:00:00'));
		$entry2->method('getStartTime')->willReturn(new \DateTime('2024-01-16 08:00:00'));
		$entry2->method('getWorkingDurationHours')->willReturn(8.0);
		$entry2->method('getDescription')->willReturn('User 2 work');

		$this->timeEntryMapper->expects($this->exactly(2))
			->method('findByUserAndDateRange')
			->willReturnOnConsecutiveCalls([$entry1], [$entry2]);

		// Mock lohnart_normal for each user's entries
		$this->config->expects($this->exactly(2))
			->method('getAppValue')
			->with('arbeitszeitcheck', 'datev_lohnart_normal', '1000')
			->willReturn('1000');

		$result = $this->service->exportMultipleUsers($userIds, $startDate, $endDate);

		$this->assertIsString($result);
		$this->assertStringContainsString('1234567|12345|2024|1', $result); // Header
		$this->assertStringContainsString('11111111|20240115|1000|8.00|Std|User 1 work', $result);
		$this->assertStringContainsString('22222222|20240116|1000|8.00|Std|User 2 work', $result);
	}

	/**
	 * Test exporting multiple users skips users without Personalnummer
	 */
	public function testExportMultipleUsersSkipsWithoutPersonalnummer(): void
	{
		$userIds = ['user1', 'user2'];
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$this->config->expects($this->exactly(2))
			->method('getAppValue')
			->willReturnMap([
				['arbeitszeitcheck', 'datev_beraternummer', '', '1234567'],
				['arbeitszeitcheck', 'datev_mandantennummer', '', '12345']
			]);

		// Mock user values: user1 has Personalnummer, user2 doesn't
		$this->config->expects($this->exactly(2))
			->method('getUserValue')
			->willReturnMap([
				['user1', 'arbeitszeitcheck', 'datev_personalnummer', '', '11111111'],
				['user2', 'arbeitszeitcheck', 'datev_personalnummer', '', ''] // Empty
			]);

		// Mock time entries only for user1
		$entry1 = $this->createMock(TimeEntry::class);
		$entry1->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
		$entry1->method('getEndTime')->willReturn(new \DateTime('2024-01-15 17:00:00'));
		$entry1->method('getStartTime')->willReturn(new \DateTime('2024-01-15 08:00:00'));
		$entry1->method('getWorkingDurationHours')->willReturn(8.0);
		$entry1->method('getDescription')->willReturn('User 1 work');

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->with('user1', $startDate, $endDate)
			->willReturn([$entry1]);

		// Mock lohnart_normal
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('arbeitszeitcheck', 'datev_lohnart_normal', '1000')
			->willReturn('1000');

		$result = $this->service->exportMultipleUsers($userIds, $startDate, $endDate);

		// Should only contain user1's entry
		$this->assertStringContainsString('11111111|20240115|1000|8.00|Std|User 1 work', $result);
		$this->assertStringNotContainsString('22222222', $result);
	}

	/**
	 * Test getConfigurationStatus returns correct status
	 */
	public function testGetConfigurationStatusConfigured(): void
	{
		$this->config->expects($this->exactly(2))
			->method('getAppValue')
			->willReturnMap([
				['arbeitszeitcheck', 'datev_beraternummer', '', '1234567'],
				['arbeitszeitcheck', 'datev_mandantennummer', '', '12345']
			]);

		$status = $this->service->getConfigurationStatus();

		$this->assertIsArray($status);
		$this->assertTrue($status['configured']);
		$this->assertTrue($status['beraternummer_set']);
		$this->assertTrue($status['mandantennummer_set']);
		$this->assertEquals('1234567', $status['beraternummer']);
		$this->assertEquals('12345', $status['mandantennummer']);
	}

	/**
	 * Test getConfigurationStatus returns unconfigured when missing values
	 */
	public function testGetConfigurationStatusUnconfigured(): void
	{
		$this->config->expects($this->exactly(2))
			->method('getAppValue')
			->willReturnMap([
				['arbeitszeitcheck', 'datev_beraternummer', '', ''],
				['arbeitszeitcheck', 'datev_mandantennummer', '', '12345']
			]);

		$status = $this->service->getConfigurationStatus();

		$this->assertFalse($status['configured']);
		$this->assertFalse($status['beraternummer_set']);
		$this->assertTrue($status['mandantennummer_set']);
	}

	/**
	 * Test DATEV format uses correct padding for numbers
	 */
	public function testDatevFormatPadding(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		// Mock configuration with short numbers (should be padded)
		$this->config->expects($this->exactly(4))
			->method('getAppValue')
			->willReturnMap([
				['arbeitszeitcheck', 'datev_beraternummer', '', '123'], // Should be padded to 7 digits
				['arbeitszeitcheck', 'datev_mandantennummer', '', '45'], // Should be padded to 5 digits
				['arbeitszeitcheck', 'datev_lohnart_normal', '1000', '10'], // Should be padded to 4 digits
				['arbeitszeitcheck', 'datev_lohnart_ueberstunden', '2000', '2000']
			]);

		$this->config->expects($this->once())
			->method('getUserValue')
			->willReturn('123'); // Should be padded to 8 digits

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
		$entry->method('getEndTime')->willReturn(new \DateTime('2024-01-15 17:00:00'));
		$entry->method('getStartTime')->willReturn(new \DateTime('2024-01-15 08:00:00'));
		$entry->method('getWorkingDurationHours')->willReturn(8.0);
		$entry->method('getDescription')->willReturn('');

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$entry]);

		$result = $this->service->exportTimeEntries($userId, $startDate, $endDate);

		// Verify padding: Beraternummer (7 digits), Mandantennummer (5 digits), Personalnummer (8 digits), Lohnart (4 digits)
		$this->assertStringContainsString('0000123|00045|2024|1', $result); // Header with padding
		$this->assertStringContainsString('00000123|20240115|0010|8.00|Std|', $result); // Data line with padding
	}
}
