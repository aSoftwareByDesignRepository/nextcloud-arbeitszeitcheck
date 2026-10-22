<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

class OutlookIcalSubscriptionLayoutContractTest extends TestCase
{
	public function testOutlookPartialAlwaysShowsSubscriptionForm(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/templates/partials/admin-settings/outlook-ical-subscription.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('data-org-wide-available="1"', $src);
		$this->assertStringContainsString('id="outlookIcalCreateBtn"', $src);
		$this->assertStringNotContainsString('Enable app-owned teams first. Calendar subscriptions are available only for app team scopes.', $src);
	}

	public function testOutlookPartialUsesResponsiveActionsRow(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/templates/partials/admin-settings/outlook-ical-subscription.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('id="outlookIcalFeedLanguage"', $src);
		$this->assertStringContainsString('form-row form-row--2', $src);
		$this->assertStringNotContainsString('outlookIcalManagerSearch', $src);
		$this->assertStringContainsString('outlook-ical-quickstart__steps', $src);
		$this->assertStringContainsString('outlook-ical-subscription-table', $src);
		$this->assertStringContainsString('id="outlookIcalSubscriptionTable"', $src);
		$this->assertStringContainsString('id="outlookIcalSubscriptionsLoading"', $src);
		$this->assertStringContainsString('outlook-ical-subscription-table__loading', $src);
		$this->assertStringContainsString('data-empty-text', $src);
		$this->assertStringContainsString('id="outlookIcalSubscriptionTableBody"', $src);
		$this->assertStringContainsString('id="outlook-ical-subscription"', $src);
	}

	public function testSingleTopicPageReferencesShellLeadForAria(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/templates/partials/admin-settings/outlook-ical-subscription.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('$outlookIntroDescId', $src);
		$this->assertStringContainsString('azc-page-help', $src);
	}

	public function testAdminSettingsShellClosesSectionBeforePageStack(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/templates/admin-settings.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('</div><!-- /.section -->', $src);
		$this->assertStringContainsString('$showSettingsSaveFooter', $src);
		$this->assertMatchesRegularExpression(
			'/<\/div><!-- \/\.section -->[\s\S]*<\/div><!-- \/\.azc-page-stack -->/',
			$src,
		);
	}

	public function testOutlookPartialDocumentsIcalSubscriptionFlow(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/templates/partials/admin-settings/outlook-ical-subscription.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('New subscription', $src);
		$this->assertStringContainsString('outlookIcalEnableWebcalLocalBtn', $src);
		$this->assertStringContainsString('webcal', $src);
		$this->assertStringContainsString('azc-settings-more', $src);
		$this->assertStringContainsString('How it works', $src);
		$this->assertStringContainsString('data-outlook-create-url', $src);
		$this->assertStringContainsString('data-outlook-active-subscriptions-url', $src);
		$this->assertStringContainsString('Your subscription links', $src);
		$this->assertStringContainsString('Create subscription link', $src);
		$this->assertStringContainsString('outlookIcalSubscriptionsLoading', $src);
		$this->assertStringContainsString('azc-loading', $src);
		$this->assertStringContainsString('azc-table--responsive', $src);
		$this->assertStringContainsString('aria-busy="true"', $src);
	}

	public function testOutlookTableCssWrapsLegacyCopyWithoutFixedLayout(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/css/admin-settings.css');
		$this->assertNotFalse($src);
		$start = strpos($src, 'Outlook iCal subscription (#outlook-ical-subscription)');
		$this->assertNotFalse($start);
		$end = strpos($src, '/* === support-us:azc start ===', $start);
		$this->assertNotFalse($end);
		$block = substr($src, $start, $end - $start);
		$this->assertStringContainsString('outlook-ical-subscription-table__legacy', $block);
		$this->assertStringContainsString('overflow-wrap: anywhere', $block);
		$this->assertStringNotContainsString('table-layout: fixed', $block);
	}

	public function testOutlookResponsiveCssUsesMobileFirstMinWidthQueries(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/css/admin-settings.css');
		$this->assertNotFalse($src);
		$start = strpos($src, 'Outlook iCal subscription (#outlook-ical-subscription)');
		$this->assertNotFalse($start);
		$end = strpos($src, '/* === support-us:azc start ===', $start);
		$this->assertNotFalse($end);
		$block = substr($src, $start, $end - $start);
		$this->assertStringContainsString('@media (min-width: 769px)', $block);
		$this->assertStringContainsString('@media (min-width: 1024px)', $block);
		$this->assertDoesNotMatchRegularExpression(
			'/#outlook-ical-subscription[^{]*\{[^}]*\}[^@]*@media\s*\(\s*max-width/s',
			$block,
		);
		$this->assertStringContainsString('overflow-x: clip', $block);
		$this->assertStringContainsString('min-height: 2.75rem', $block);
		$this->assertStringContainsString('outlook-ical-subscription-table', $block);
	}

	public function testOutlookCssUsesThemeVariablesNotHardcodedColors(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/css/admin-settings.css');
		$this->assertNotFalse($src);
		$start = strpos($src, 'Outlook iCal subscription (#outlook-ical-subscription)');
		$this->assertNotFalse($start);
		$end = strpos($src, '/* === support-us:azc start ===', $start);
		$this->assertNotFalse($end);
		$block = substr($src, $start, $end - $start);
		$blockNoComments = preg_replace('/\/\*.*?\*\//s', '', $block) ?? $block;
		$this->assertDoesNotMatchRegularExpression(
			'/#[0-9a-fA-F]{3,8}\b/',
			$blockNoComments,
			'Outlook block must not use hardcoded hex colors',
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\\brgb\\s*\\(/',
			$blockNoComments,
			'Outlook block must not use hardcoded rgb() colors',
		);
		$this->assertStringContainsString('var(--azc-', $block);
	}

	public function testOutlookUserPickerCssUsesVerticalListboxLayout(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/css/common/user-picker.css');
		$this->assertNotFalse($src);
		$this->assertMatchesRegularExpression(
			'/\.user-picker__list[\s\S]*display:\s*block/s',
			$src,
		);
		$this->assertMatchesRegularExpression(
			'/\.user-picker__list button\.user-picker__item[\s\S]*flex-direction:\s*column/s',
			$src,
		);
	}

	public function testPageStartExposesHelpLandmarkId(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/templates/common/page-start.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('id="azc-page-help"', $src);
	}
}
