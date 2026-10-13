<?php

declare(strict_types=1);

/**
 * Tests for {@see UserWorkLocationService} (GH #43).
 *
 * Covers the trust boundaries that matter for audits:
 *  - corrupt/stale stored values read as unset (never trusted blindly)
 *  - setDefault validates against the allowlist and audit-logs the change
 *  - resolveForNewEntry honours explicit → stored default → office
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\UserWorkLocationService;
use PHPUnit\Framework\TestCase;

class UserWorkLocationServiceTest extends TestCase
{
	/** @var UserSettingsMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $settings;
	/** @var AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $audit;
	private UserWorkLocationService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->settings = $this->createMock(UserSettingsMapper::class);
		$this->audit = $this->createMock(AuditLogMapper::class);
		$this->service = new UserWorkLocationService($this->settings, $this->audit);
	}

	public function testGetDefaultReturnsNullWhenUnset(): void
	{
		$this->settings->method('getStringSetting')->willReturn('');
		$this->assertNull($this->service->getDefault('alice'));
	}

	public function testGetDefaultTreatsCorruptValueAsUnset(): void
	{
		// A tampered/legacy value must never reach callers as a valid default.
		$this->settings->method('getStringSetting')->willReturn('remote');
		$this->assertNull($this->service->getDefault('alice'));
	}

	public function testGetDefaultReturnsStoredAllowlistValue(): void
	{
		$this->settings->method('getStringSetting')->willReturn('home');
		$this->assertSame('home', $this->service->getDefault('alice'));
	}

	public function testSetDefaultPersistsValidValueAndAudits(): void
	{
		$this->settings->method('getStringSetting')->willReturn('office');
		$this->settings->expects($this->once())
			->method('setSetting')
			->with('alice', Constants::SETTING_WORK_LOCATION_DEFAULT, 'home');
		$this->audit->expects($this->once())
			->method('logAction')
			->with(
				'alice',
				'user_work_location_default_updated',
				'user',
				null,
				[Constants::SETTING_WORK_LOCATION_DEFAULT => 'office'],
				[Constants::SETTING_WORK_LOCATION_DEFAULT => 'home'],
				'manager1'
			);
		$this->service->setDefault('alice', 'home', 'manager1');
	}

	public function testSetDefaultClearDeletesRowAndAudits(): void
	{
		$this->settings->method('getStringSetting')->willReturn('external');
		$this->settings->expects($this->once())
			->method('deleteSetting')
			->with('alice', Constants::SETTING_WORK_LOCATION_DEFAULT);
		$this->audit->expects($this->once())->method('logAction');
		$this->service->setDefault('alice', '', 'alice');
	}

	public function testSetDefaultRejectsInvalidValueWithoutWriteOrAudit(): void
	{
		$this->settings->expects($this->never())->method('setSetting');
		$this->settings->expects($this->never())->method('deleteSetting');
		$this->audit->expects($this->never())->method('logAction');
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setDefault('alice', 'moon', 'alice');
	}

	public function testSetDefaultNoChangeSkipsWriteAndAudit(): void
	{
		$this->settings->method('getStringSetting')->willReturn('home');
		$this->settings->expects($this->never())->method('setSetting');
		$this->audit->expects($this->never())->method('logAction');
		$this->service->setDefault('alice', 'home', 'alice');
	}

	public function testResolveForNewEntryPrefersExplicitValue(): void
	{
		$this->settings->method('getStringSetting')->willReturn('home');
		$this->assertSame('external', $this->service->resolveForNewEntry('alice', 'external'));
	}

	public function testResolveForNewEntryFallsBackToUserDefault(): void
	{
		$this->settings->method('getStringSetting')->willReturn('external');
		$this->assertSame('external', $this->service->resolveForNewEntry('alice', null));
		$this->assertSame('external', $this->service->resolveForNewEntry('alice', ''));
	}

	public function testResolveForNewEntryFallsBackToOffice(): void
	{
		$this->settings->method('getStringSetting')->willReturn('');
		$this->assertSame('office', $this->service->resolveForNewEntry('alice', null));
	}

	public function testResolveForNewEntrySkipsCorruptDefault(): void
	{
		$this->settings->method('getStringSetting')->willReturn('garbage');
		$this->assertSame('office', $this->service->resolveForNewEntry('alice', null));
	}

	public function testResolveForNewEntryThrowsOnInvalidExplicitValue(): void
	{
		$this->settings->method('getStringSetting')->willReturn('home');
		$this->expectException(\InvalidArgumentException::class);
		$this->service->resolveForNewEntry('alice', 'garbage');
	}
}
