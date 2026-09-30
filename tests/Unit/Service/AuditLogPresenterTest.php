<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\AuditLogPresenter;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditLogPresenterTest extends TestCase
{
	private IL10N&MockObject $l10n;
	private IDateTimeFormatter&MockObject $dateTimeFormatter;
	private AuditLogPresenter $presenter;

	protected function setUp(): void
	{
		parent::setUp();

		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => empty($params) ? $text : vsprintf($text, $params));

		$this->dateTimeFormatter = $this->createMock(IDateTimeFormatter::class);
		$this->dateTimeFormatter->method('formatDateTime')->willReturn('03.06.2026, 19:04');

		$this->presenter = new AuditLogPresenter($this->l10n, $this->dateTimeFormatter);
	}

	public function testFormatActionUsesKnownLabel(): void
	{
		$this->assertSame('Time capture methods updated', $this->presenter->formatAction('user_time_capture_methods_updated'));
		$this->assertSame('Organisation time recording methods updated', $this->presenter->formatAction('organization_time_capture_methods_updated'));
	}

	public function testFormatEntityTypeUsesKnownLabel(): void
	{
		$this->assertSame('Tariff rule set', $this->presenter->formatEntityType('tariff_rule_set'));
	}

	public function testFormatActorSystem(): void
	{
		$this->assertSame('System', $this->presenter->formatActor('system'));
	}

	public function testFormatActorInternalTestAccount(): void
	{
		$this->assertSame('Internal test account', $this->presenter->formatActor('__arbeitszeitcheck_time_capture_int__'));
	}

	public function testResolveCategoryActionsForCreate(): void
	{
		$actions = $this->presenter->resolveCategoryActions(AuditLogPresenter::CATEGORY_CREATE);
		$this->assertNotNull($actions);
		$this->assertContains('tariff_rule_set_created', $actions);
		$this->assertNotContains('tariff_rule_set_deleted', $actions);
	}

	public function testResolveCategoryActionsForOtherIncludesUncategorizedActions(): void
	{
		$actions = $this->presenter->resolveCategoryActions(AuditLogPresenter::CATEGORY_OTHER);
		$this->assertNotNull($actions);
		$this->assertContains('compliance_violation_resolved', $actions);
		$this->assertContains('time_entry_correction_requested', $actions);
	}

	public function testFormatActorWithIdAppendsUidWhenNameDiffers(): void
	{
		$this->l10n->method('t')->willReturnArgument(0);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice A.');
		$this->assertSame('Alice A. (alice)', $this->presenter->formatActorWithId('alice', $user));

		// display name == uid -> plain formatActor (no redundant suffix)
		$user2 = $this->createMock(\OCP\IUser::class);
		$user2->method('getUID')->willReturn('bob');
		$user2->method('getDisplayName')->willReturn('bob');
		$this->assertSame('bob', $this->presenter->formatActorWithId('bob', $user2));

		// empty id / no user -> "Unknown"
		$this->assertSame('Unknown', $this->presenter->formatActorWithId(''));
		$this->assertSame('Unknown', $this->presenter->formatActorWithId(null, null));
	}
}