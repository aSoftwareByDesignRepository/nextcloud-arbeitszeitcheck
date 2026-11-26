<?php

declare(strict_types=1);

/**
 * Employee profile layout contract.
 *
 * Pins the four profile-page rules: the back arrow is not underlined,
 * User ID and Status sit in the page header, section fields are two columns
 * from 768px, and the save bar is flush to the content column.
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

class AdminUserDetailLayoutContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testBackLinkUnderlinesTheLabelOnly(): void
	{
		$tpl = $this->read('templates/admin-user-detail.php');
		$css = $this->read('css/admin-users.css');

		$this->assertMatchesRegularExpression(
			'/<span class="admin-user-detail__back-icon" aria-hidden="true">←<\/span>\s*<span class="admin-user-detail__back-label">/s',
			$tpl,
		);
		$this->assertMatchesRegularExpression(
			'/\.admin-user-detail__back-link:hover,\s*\.admin-user-detail__back-link:focus\s*\{[^}]*text-decoration:\s*none/s',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/#app-content\.azc-app--admin-user-detail #app-content-wrapper a\.admin-user-detail__back-link:hover,\s*#app-content\.azc-app--admin-user-detail #app-content-wrapper a\.admin-user-detail__back-link:focus,\s*#app-content\.azc-app--admin-user-detail #app-content-wrapper a\.admin-user-detail__back-link:focus-visible\s*\{[^}]*text-decoration:\s*none/s',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/\.admin-user-detail__back-label\s*\{[^}]*text-decoration:\s*underline/s',
			$css,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.admin-user-detail__back-link:hover\s*\{[^}]*text-decoration:\s*underline/s',
			$css,
		);
	}

	public function testIdentityReplacesTheReviewLeadInThePageHeader(): void
	{
		$tpl = $this->read('templates/admin-user-detail.php');
		$pageStart = $this->read('templates/common/page-start.php');
		$controller = $this->read('lib/Controller/AdminController.php');

		$this->assertStringNotContainsString('admin-user-detail__identity', $tpl);
		$this->assertSame(1, substr_count($tpl, 'class="admin-user-detail__meta"'));
		$this->assertStringContainsString("\$_['pageHelp'] = '';", $tpl);
		$metaAt = strpos($tpl, 'admin-user-detail__meta');
		$includeAt = strpos($tpl, "include __DIR__ . '/common/page-start.php'");
		$this->assertNotFalse($metaAt);
		$this->assertNotFalse($includeAt);
		$this->assertLessThan($includeAt, $metaAt, 'Identity HTML must be captured before the page shell include');
		$this->assertStringContainsString('<dt class="azc-sr-only"><?php p($l->t(\'User ID\')); ?></dt>', $tpl);
		$this->assertStringContainsString('<dt class="azc-sr-only"><?php p($l->t(\'Status\')); ?></dt>', $tpl);
		$this->assertStringContainsString("p(\$l->t('Email'))", $tpl);
		$this->assertStringContainsString('admin-user-detail__missing-id', $tpl);

		$this->assertMatchesRegularExpression(
			'/if \(\$pageHeaderSub !== \'\'\):.*?print_unescaped\(\$pageHeaderSub\).*?elseif \(\$pageHelp !== \'\'\):/s',
			$pageStart,
		);

		$this->assertMatchesRegularExpression(
			'/\$pageHelp = \$found\s*\?\s*\'\'\s*:/s',
			$controller,
		);
		$this->assertStringNotContainsString(
			'Review and update this employee',
			$controller,
		);
	}

	public function testRemovedGuidesStayOutOfTheProfileForm(): void
	{
		$js = $this->read('js/admin-user-detail.js');
		$css = $this->read('css/admin-users.css');

		$this->assertStringNotContainsString('sectionGuideWorkSchedule', $js);
		$this->assertStringNotContainsString('selectWorkScheduleHelp', $js);
		$this->assertStringNotContainsString('aria-describedby="user-model-help"', $js);
		$this->assertStringNotContainsString('sectionGuideTimeRecording', $js);
		$this->assertStringNotContainsString('sectionGuideVacation', $js);
		$this->assertStringNotContainsString('employmentPeriodIntro', $js);
		$this->assertStringContainsString('sectionGuideEmployment', $js);
		$this->assertStringContainsString('individualContractHoursHelp', $js);
		$this->assertStringContainsString('user-edit-capture-intro', $js);
		$this->assertMatchesRegularExpression(
			'/#app-content\.azc-app--admin-user-detail \.user-custom-model\s*\{[^}]*gap:\s*var\(--azc-space-1, 4px\);/s',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/#app-content\.azc-app--admin-user-detail \.user-custom-model__toggle\s*\{[^}]*align-items:\s*flex-end;/s',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/#app-content\.azc-app \.admin-user-detail__meta-item dt\.azc-sr-only\s*\{[^}]*width:\s*1px;/s',
			$css,
		);
	}

	public function testVacationModeFieldsAreHiddenUntilSelected(): void
	{
		$js = $this->read('js/admin-user-detail.js');
		$css = $this->read('css/admin-users.css');

		$this->assertStringContainsString('group.hidden = !visible', $js);
		$this->assertStringContainsString("setVacationModeField(manualDaysEl, isManual)", $js);
		$this->assertStringContainsString("setVacationModeField(tariffRuleSetEl, mode === 'tariff_rule_based')", $js);
		$this->assertStringContainsString('setVacationModeField(overrideReasonEl, needsReason)', $js);
		$this->assertStringContainsString('Vacation days per year (organisation default)', $js);
		$this->assertStringNotContainsString('id="user-vacation-help"', $js);
		$this->assertStringNotContainsString('aria-describedby="user-vacation-help"', $js);
		$employmentAt = strpos($js, 'id="user-edit-employment"');
		$vacationAt = strpos($js, 'id="user-edit-vacation"');
		$captureAt = strpos($js, 'id="user-edit-capture"');
		$this->assertNotFalse($captureAt);
		$this->assertNotFalse($employmentAt);
		$this->assertNotFalse($vacationAt);
		$this->assertLessThan($employmentAt, $captureAt);
		$this->assertLessThan($vacationAt, $employmentAt);
		$this->assertMatchesRegularExpression(
			'/#app-content\.azc-app--admin-user-detail \.azc-page-header__text h1\s*\{[^}]*margin-bottom:\s*calc\(var\(--azc-space-1, 4px\) \/ 2\);/s',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/\.admin-user-detail__meta\s*\{[^}]*margin-top:\s*calc\(var\(--azc-space-2, 8px\) \/ 2\);/s',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/\.user-overtime-adjust__more-summary\s*\{[^}]*list-style:\s*disclosure-closed outside;/s',
			$css,
		);
		$this->assertStringNotContainsString('list-style: none', $css);
		$this->assertMatchesRegularExpression(
			'/\.admin-user-detail__kiosk-xref-title\s*\{[^}]*font-weight:\s*600;/s',
			$css,
		);
		$this->assertStringNotContainsString('is-disabled', $js);
		$this->assertStringNotContainsString('id="user-edit-validity"', $js);
		$this->assertStringNotContainsString('id="user-start-date"', $js);
		$this->assertStringNotContainsString('name="loadedWtmStart"', $js);
		$this->assertStringNotContainsString('href="#user-edit-validity"', $this->read('templates/admin-user-detail.php'));
		$this->assertStringContainsString('href="#user-edit-assignments"', $this->read('templates/admin-user-detail.php'));
		$payloadAt = strpos($js, 'const workingTimeModel = {');
		$policyAt = strpos($js, "const mode = (formData.get('vacationMode')");
		$this->assertNotFalse($payloadAt);
		$this->assertNotFalse($policyAt);
		$modelPayload = substr($js, $payloadAt, $policyAt - $payloadAt);
		$this->assertStringNotContainsString('startDate:', $modelPayload);
		$this->assertStringNotContainsString('endDate:', $modelPayload);
		$this->assertStringContainsString('policyEffectiveFrom || todayYmd()', $js);
		$this->assertStringContainsString('effectiveTo: policyEffectiveTo || null', $js);
		$scheduleAt = strpos($js, 'id="user-edit-assignment"');
		$assignmentsAt = strpos($js, 'id="user-edit-assignments"');
		$captureAt = strpos($js, 'id="user-edit-capture"');
		$datevAt = strpos($js, 'id="user-edit-datev"');
		$overtimeAt = strpos($js, 'id="user-edit-overtime"');
		$this->assertNotFalse($assignmentsAt);
		$this->assertLessThan($assignmentsAt, $scheduleAt);
		$this->assertLessThan($captureAt, $assignmentsAt);
		$this->assertLessThan($datevAt, $overtimeAt);
		$toc = $this->read('templates/admin-user-detail.php');
		$tocSchedule = strpos($toc, 'href="#user-edit-assignment"');
		$tocPeriods = strpos($toc, 'href="#user-edit-assignments"');
		$tocCapture = strpos($toc, 'href="#user-edit-capture"');
		$this->assertNotFalse($tocSchedule);
		$this->assertLessThan($tocPeriods, $tocSchedule);
		$this->assertLessThan($tocCapture, $tocPeriods);
		$this->assertStringNotContainsString('id="assignment-history"', $js);
		$this->assertStringNotContainsString('loadAssignmentHistory', $js);
		$this->assertStringContainsString('id="assignments-removed"', $js);
		$this->assertStringContainsString("window.location.hash === '#assignment-history'", $js);
		$this->assertStringContainsString('removedWorkSchedulePeriods', $js);
		$this->assertStringContainsString(
			"'removedWorkSchedulePeriods' => \$this->safeRemovedList(\$userId)",
			$this->read('lib/Controller/AdminController.php'),
		);
		$this->assertStringContainsString(
			"\$l->t('Removed by %s on %s', ['%s', '%s'])",
			$this->read('templates/partials/admin-user-edit-l10n.php'),
		);
		$this->assertStringContainsString('function canEndPeriod(assignment)', $js);
		$this->assertStringContainsString("end === '' || end > todayIso()", $js);
		$this->assertMatchesRegularExpression('/function todayIso\(\) \{[^}]*return todayYmd\(\);/', $js);
		$this->assertStringContainsString('canEndPeriod(a)', $js);
		$this->assertStringContainsString(
			"\$l->t('This period now ends on %s. It still applies today.', ['%s'])",
			$this->read('templates/partials/admin-user-edit-l10n.php'),
		);
		$this->assertStringContainsString('data.removed', $js);
		$this->assertStringNotContainsString('#assignment-history', $this->read('templates/admin-user-detail.php'));
		$this->assertStringNotContainsString('#assignment-history', $this->read('templates/admin-users.php'));
		$this->assertStringNotContainsString('#assignment-history', $this->read('js/admin-users.js'));
		$this->assertStringContainsString('#user-edit-assignments', $this->read('js/admin-users.js'));
		$this->assertMatchesRegularExpression(
			'/\.assignments-removed\[hidden\]\s*\{[^}]*display:\s*none;/s',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/#app-content\.azc-app--admin-user-detail \.form-group\[hidden\]\s*\{[^}]*display:\s*none;/s',
			$css,
		);
	}

	public function testSectionFieldsAreTwoColumnsFrom769AndOneBelow(): void
	{
		$css = $this->read('css/admin-users.css');

		$this->assertMatchesRegularExpression(
			'/@media \(min-width: 769px\)\s*\{[^}]*grid-template-columns:\s*repeat\(2, minmax\(0, 1fr\)\);/s',
			$css,
		);
		$this->assertStringContainsString(
			'.admin-user-detail .user-custom-model__fields:not([hidden])',
			$css,
		);
		$this->assertStringContainsString(
			'.admin-user-detail details[open] > .user-edit-section__body',
			$css,
		);
		$this->assertStringContainsString(
			'.admin-user-detail .user-overtime-adjust__more[open] > .user-overtime-adjust__more-body',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/\.user-custom-model__fields:not\(\[hidden\]\) > \.wtm-weekday-schedule,.*?grid-column:\s*1 \/ -1;/s',
			$css,
		);
		$this->assertStringContainsString(
			'.form-group:has(.entitlement-preview)',
			$css,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/@media \(max-width: 767(?:\.98)?px\)\s*\{[^}]*grid-template-columns:\s*repeat\(2/s',
			$css,
		);
	}

	public function testSaveBarIsFlushStickyAndInSectionActionsAreNot(): void
	{
		$css = $this->read('css/admin-users.css');

		$this->assertMatchesRegularExpression(
			'/#app-content\.azc-app--admin-user-detail\s*\{[^}]*--azc-page-end:\s*0px;[^}]*padding-bottom:\s*0;/s',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/#app-content\.azc-app--admin-user-detail \.admin-user-detail__actions\s*\{[^}]*position:\s*sticky;[^}]*bottom:\s*0;[^}]*margin:\s*0 calc\(-1 \* var\(--azc-space-5, 24px\)\) 0;[^}]*width:\s*auto;[^}]*border-left:\s*0;[^}]*border-right:\s*0;[^}]*border-bottom:\s*0;/s',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/#app-content\.azc-app--admin-user-detail #app-content-wrapper\.azc-shell\s*\{[^}]*align-self:\s*flex-start;/s',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/\.admin-user-detail \.form-actions\s*\{[^}]*position:\s*static;/s',
			$css,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.admin-user-detail \.form-actions[^{]*\{[^}]*position:\s*sticky/s',
			$css,
		);
	}

	private function read(string $relative): string
	{
		$path = $this->root . '/' . $relative;
		$contents = file_get_contents($path);
		$this->assertNotFalse($contents, $path);
		$this->assertNotSame('', $contents);

		return $contents;
	}
}
