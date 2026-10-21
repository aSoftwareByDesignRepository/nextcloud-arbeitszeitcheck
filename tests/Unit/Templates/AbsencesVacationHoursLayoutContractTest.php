<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

class AbsencesVacationHoursLayoutContractTest extends TestCase
{
	public function testAbsencesTemplateIncludesHoursBookingSurface(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../templates/absences.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('id="absence-duration-hours-group"', $src);
		$this->assertStringContainsString('id="absence-duration-hours"', $src);
		$this->assertStringContainsString('name="duration_hours"', $src);
		$this->assertStringContainsString('absence-hours-preset', $src);
		$this->assertStringContainsString('stat-card--hero', $src);
		$this->assertStringContainsString("\$l->t('Resturlaub')", $src);
		$this->assertStringContainsString("\$l->t('Opening balance at year start: %s'", $src);
		$this->assertStringContainsString("\$l->t('Expiry date: %s'", $src);
		$this->assertStringContainsString("\$l->t('How this balance is calculated')", $src);
		$this->assertStringContainsString('id="entitlement-explain"', $src);
		$this->assertStringContainsString('id="entitlement-explain-dialog"', $src);
		$this->assertStringNotContainsString('How is this calculated?', $src);
		$this->assertStringNotContainsString('stats-section__intro', $src);
		$this->assertStringNotContainsString('More balance details', $src);
		$this->assertStringNotContainsString('Carryover (opening balance)', $src);
		$this->assertStringNotContainsString('vacation-stats-details', $src);
		$css = file_get_contents(dirname(__DIR__, 2) . '/../css/absences.css');
		$this->assertNotFalse($css);
		$listAt = strpos($src, 'class="section section--list"');
		$statsAt = strpos($src, 'class="section section--stats');
		$this->assertNotFalse($listAt);
		$this->assertNotFalse($statsAt);
		$this->assertLessThan($statsAt, $listAt);
		$this->assertStringContainsString('margin: 4em 0;', $css);
		$this->assertStringContainsString('#app-content.azc-app.azc-app--absences #azc-main-content .azc-page-stack > .section--list', $css);
		$this->assertStringContainsString('#app-content.azc-app--absences .azc-page-header', $css);
		$this->assertStringContainsString('margin-bottom: var(--azc-space-3, 0.75rem);', $css);
		$this->assertStringContainsString('.absences-table th:nth-child(1)', $css);
		$this->assertStringContainsString('table-layout: fixed;', $css);
		$this->assertStringContainsString('width: 24rem;', $css);
		$this->assertStringContainsString('background-color: var(--azc-bg-soft);', $css);
		$this->assertStringContainsString('grid-template-columns: repeat(5, minmax(0, 1fr));', $css);
		$this->assertStringContainsString('stats-balance-help__mark', $src);
		$this->assertStringContainsString('#app-content.azc-app .stats-balance-help', $css);
		$this->assertStringContainsString('border: 2px solid currentColor !important;', $css);
		$this->assertStringContainsString('cursor: pointer !important;', $css);
		$this->assertStringContainsString('box-shadow: none !important;', $css);
		$this->assertStringNotContainsString('id="entitlement-explain-close"', $src);
		$this->assertStringNotContainsString('grid-column: 1 / -1', $css);
		$this->assertStringContainsString('stat-value--hero', $src);
		$this->assertStringContainsString("\$l->t('Duration')", $src);
		$this->assertStringContainsString('getDurationHours()', $src);
		$this->assertStringContainsString('data-hours-range', $src);
		$this->assertStringContainsString('id="absence-duration-hours-preview"', $src);
		$this->assertStringContainsString('Working days in range', $src);
		// Weekend/holiday ranges may legitimately estimate 0 h — do not ignore success+0.
		$this->assertStringContainsString('j.hours < 0', $src);
		$this->assertStringNotContainsString('j.hours <= 0', $src);
		$this->assertStringNotContainsString('absence-request-card" aria-labelledby="form-title"', $src);
		$this->assertStringContainsString('data-hint="absence-past-entry-hint"', $src);
		$actionsAt = strpos($src, 'absence-request-form__actions');
		$hintAt = strpos($src, 'data-hint="absence-past-entry-hint"');
		$this->assertNotFalse($actionsAt);
		$this->assertNotFalse($hintAt);
		$this->assertGreaterThan($actionsAt, $hintAt);
		$this->assertStringContainsString('azc-callout--neutral azc-hint__body', $src);
		$this->assertStringContainsString("\$l->t('Note (optional)')", $src);
		$this->assertStringNotContainsString('absence-form-fieldset--substitute {', $css);
		$this->assertStringContainsString('td colspan="7" class="azc-table-empty"', $src);
		$this->assertStringContainsString('class="azc-empty-state"', $src);
		$this->assertStringContainsString('td.azc-table-empty > .azc-empty-state', $css);
		$this->assertStringContainsString('id="absence-org-debit-warn"', $src);
		$this->assertStringContainsString('data-debit-basis', $src);
		$this->assertStringContainsString('No working-time model assigned', $src);
		// Never invent hours via days × org factor when duration_hours is missing.
		$this->assertStringNotContainsString('round($displayD * $hoursPerDay, 1)', $src);
		$this->assertStringNotContainsString('((float)$days * $hoursPerDay)', $src);
	}
}
