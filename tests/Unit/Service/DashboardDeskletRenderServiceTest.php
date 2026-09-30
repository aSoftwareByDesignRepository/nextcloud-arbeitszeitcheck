<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\DashboardDeskletConfigService;
use OCA\ArbeitszeitCheck\Service\DashboardDeskletRenderService;
use OCA\ArbeitszeitCheck\Service\DashboardDeskletWorkspaceRenderer;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Guards against regressions that broke the NC dashboard widget (invalid ITemplateManager).
 */
class DashboardDeskletRenderServiceTest extends TestCase
{
	public function testWorkspaceRendererUsesLegacyTemplateApi(): void
	{
		$source = file_get_contents(
			dirname(__DIR__, 3) . '/lib/Service/DashboardDeskletWorkspaceRenderer.php',
		);
		$this->assertIsString($source);
		$this->assertStringNotContainsString('ITemplateManager', $source);
		$this->assertStringContainsString('new \\OCP\\Template', $source);
	}

	public function testWorkspaceRendererRendersPartialWithoutPageLayout(): void
	{
		$source = file_get_contents(
			dirname(__DIR__, 3) . '/lib/Service/DashboardDeskletWorkspaceRenderer.php',
		);
		$this->assertIsString($source);
		$this->assertStringContainsString('TemplateResponse::RENDER_AS_BLANK', $source);

		foreach (["'blank'", "'user'", "'guest'", "'base'", "'error'", "'public'"] as $renderAs) {
			$this->assertStringNotContainsString(
				$renderAs,
				$source,
				'Truthy renderAs wraps the partial in TemplateLayout and breaks page script order',
			);
		}
	}

	public function testRenderForUserReturnsConfigAndHtml(): void
	{
		$config = ['status' => 'clocked_out', 'l10n' => []];
		$html = '<div class="dz-workspace" data-arbeitszeitcheck-desklet="1">'
			. '<div id="dz-error-panel" class="dz-retry dz-status-section"></div></div>';

		$deskletConfig = $this->createMock(DashboardDeskletConfigService::class);
		$deskletConfig->expects($this->once())
			->method('buildForUser')
			->with('user1')
			->willReturn($config);

		$l10n = $this->createMock(IL10N::class);
		$workspaceRenderer = $this->createMock(DashboardDeskletWorkspaceRenderer::class);
		$workspaceRenderer->expects($this->once())
			->method('render')
			->with($config, $l10n)
			->willReturn($html);

		$service = new DashboardDeskletRenderService($deskletConfig, $l10n, $workspaceRenderer);
		$result = $service->renderForUser('user1');

		$this->assertSame($config, $result['config']);
		$this->assertSame($html, $result['workspaceHtml']);
		$this->assertStringContainsString('data-arbeitszeitcheck-desklet', $result['workspaceHtml']);
		$this->assertStringContainsString('dz-error-panel', $result['workspaceHtml']);
		$this->assertStringContainsString('dz-retry', $result['workspaceHtml']);
		$this->assertStringContainsString('dz-status-section', $result['workspaceHtml']);
	}
}
