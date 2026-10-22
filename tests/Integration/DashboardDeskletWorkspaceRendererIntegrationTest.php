<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Service\DashboardDeskletWorkspaceRenderer;
use OCP\IL10N;
use Test\TestCase;

/**
 * Exercises the legacy \OCP\Template path outside the large unit suite (avoids PHP segfault from polluted globals).
 */
class DashboardDeskletWorkspaceRendererIntegrationTest extends TestCase
{
	public function testRenderProducesDeskletMarkup(): void
	{
		$config = [
			'status' => 'clocked_out',
			'l10n' => [
				'deskletTitle' => 'Quick time tracking',
				'deskletLead' => 'Clock in from here.',
				'tryAgain' => 'Try again',
			],
		];

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$renderer = new DashboardDeskletWorkspaceRenderer();
		$html = $renderer->render($config, $l10n);

		$this->assertStringContainsString('data-arbeitszeitcheck-desklet', $html);
		$this->assertStringContainsString('dz-error-panel', $html);
		$this->assertStringContainsString('dz-retry', $html);
		$this->assertStringContainsString('dz-status-section', $html);
		$this->assertStringContainsString('azc-btn azc-btn--primary', $html);
		$this->assertStringContainsString('id="dz-clock-in"', $html);
		$this->assertStringContainsString('id="dz-daily-max-notice"', $html);
		$this->assertStringNotContainsString('btn-primary', $html);
		$this->assertStringNotContainsString('dz-clock-in-project', $html);

		// Rendering through a page layout would wrap the partial in a document
		// shell and, worse, resolve the request's script registry mid-request —
		// which leaks app scripts ahead of core/js/main on host pages (Dashboard).
		$this->assertStringNotContainsString('<!DOCTYPE', $html);
		$this->assertStringNotContainsString('<html', $html);
		$this->assertDoesNotMatchRegularExpression('/<head[\s>]/', $html);
		$this->assertDoesNotMatchRegularExpression('/<body[\s>]/', $html);
		$this->assertStringNotContainsString('<script src=', $html);
	}

	public function testRenderIncludesProjectPickerWhenProjectsPresent(): void
	{
		$config = [
			'l10n' => [
				'deskletTitle' => 'Quick time tracking',
				'deskletLead' => 'Clock in from here.',
				'projectLabel' => 'Project',
				'projectHelp' => 'Optional project',
				'projectNone' => 'No project',
			],
			'projectCheck' => [
				'available' => true,
				'linkingEnabled' => true,
				'projects' => [
					['id' => '7', 'displayName' => 'Bauhof'],
					['id' => '', 'displayName' => 'MustNotBecomeOption'],
					['id' => '9', 'name' => 'Fallback name'],
				],
			],
		];

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$renderer = new DashboardDeskletWorkspaceRenderer();
		$html = $renderer->render($config, $l10n);

		$this->assertStringContainsString('id="dz-project-picker"', $html);
		$this->assertStringContainsString('id="dz-clock-in-project"', $html);
		$this->assertStringContainsString('value="7"', $html);
		$this->assertStringContainsString('Bauhof', $html);
		$this->assertStringContainsString('value="9"', $html);
		$this->assertStringContainsString('Fallback name', $html);
		$this->assertDoesNotMatchRegularExpression(
			'/<option[^>]*>MustNotBecomeOption<\/option>/',
			$html,
			'Projects with empty id must not appear as selectable options',
		);
		$this->assertStringContainsString('aria-labelledby="dz-project-label"', $html);
		$this->assertStringContainsString('aria-describedby="dz-project-help"', $html);
		$this->assertStringContainsString('"linkingEnabled":true', $html);
		$this->assertStringContainsString('"id":"7"', $html);
		$this->assertStringNotContainsString('id="dz-project-linking-off"', $html);
	}

	public function testRenderShowsLinkingOffNoticeWhenProjectCheckAvailableButDisabled(): void
	{
		$config = [
			'l10n' => [
				'deskletTitle' => 'Quick time tracking',
				'deskletLead' => 'Clock in from here.',
				'projectLinkingOffTitle' => 'ProjectCheck linking is turned off',
				'projectLinkingOffBody' => 'Ask an admin to enable linking.',
			],
			'projectCheck' => [
				'available' => true,
				'linkingEnabled' => false,
				'projects' => [],
			],
		];

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$renderer = new DashboardDeskletWorkspaceRenderer();
		$html = $renderer->render($config, $l10n);

		$this->assertStringContainsString('id="dz-project-linking-off"', $html);
		$this->assertStringContainsString('ProjectCheck linking is turned off', $html);
		$this->assertStringContainsString('Ask an admin to enable linking.', $html);
		$this->assertStringNotContainsString('id="dz-project-picker"', $html);
		$this->assertStringContainsString('dz-capture-notice--neutral', $html);
	}
}
