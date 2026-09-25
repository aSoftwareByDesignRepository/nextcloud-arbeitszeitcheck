<?php
declare(strict_types=1);

use OCA\ArbeitszeitCheck\Service\IconCatalog;
use OCA\ArbeitszeitCheck\Support\BadgeVariant;
use OCA\ArbeitszeitCheck\Util\HalfDayVacationShortcut;
use OCA\ArbeitszeitCheck\Util\TemplateL10n;

/**
 * Absences template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */

// Assets registered by PageController / AbsenceController

$absences = $_['absences'] ?? [];
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$stats = $_['stats'] ?? [];
$mode = $_['mode'] ?? 'list'; // 'list', 'create', 'edit'
$absence = $_['absence'] ?? null;
$error = $_['error'] ?? null;
$currentUserId = $_['currentUserId'] ?? '';
$usersUrl = $_['usersUrl'] ?? '';
$substituteDisplayName = $_['substituteDisplayName'] ?? null;
$requireSubstituteTypes = $_['requireSubstituteTypes'] ?? [];
$colleagues = $_['colleagues'] ?? [];
$employeeHasAssignableManager = $_['employeeHasAssignableManager'] ?? true;
$useAppTeams = $_['useAppTeams'] ?? false;
$prefillStart = $_['prefillStart'] ?? null;
$prefillEnd = $_['prefillEnd'] ?? null;
$prefillHalf = !empty($_['prefillHalf']);
$today = new \DateTimeImmutable('today');
$entitlementTraceUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.absence.entitlementTrace');
if (preg_match('#^https?://[^/]+(/.*)$#', $entitlementTraceUrl, $entitlementTraceMatch)) {
	$entitlementTraceUrl = $entitlementTraceMatch[1];
}
$absenceFormStartDisplay = ($mode === 'create')
	? (is_string($prefillStart) ? $prefillStart : '')
	: (($absence && $absence->getStartDate()) ? $absence->getStartDate()->format('d.m.Y') : '');
$absenceFormEndDisplay = ($mode === 'create')
	? (is_string($prefillEnd) ? $prefillEnd : (is_string($prefillStart) ? $prefillStart : ''))
	: (($absence && $absence->getEndDate()) ? $absence->getEndDate()->format('d.m.Y') : '');
$vacationUnit = (string)($stats['vacation_unit'] ?? 'days');
$isVacationHours = $vacationUnit === 'hours';
// Days-mode create: default both dates to today so Length / Half day appear immediately (Bachus).
if ($mode === 'create' && !$isVacationHours && $absenceFormStartDisplay === '' && $absenceFormEndDisplay === '') {
	$todayDisplay = $today->format('d.m.Y');
	$absenceFormStartDisplay = $todayDisplay;
	$absenceFormEndDisplay = $todayDisplay;
}
$halfDayTodayUrl = '';
$halfDayShortcutLabel = $l->t('Half day today');
$halfDayShortcutAria = $l->t('Request a half day of vacation for today');
if ($mode === 'list' && !$isVacationHours) {
	$halfAnchor = HalfDayVacationShortcut::anchorDate($today);
	$halfDayTodayUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.absence.create', [
		'start' => $halfAnchor->format('Y-m-d'),
		'end' => $halfAnchor->format('Y-m-d'),
		'half' => '1',
	]);
	if (!HalfDayVacationShortcut::isSameCalendarDay($halfAnchor, $today)) {
		$halfDayShortcutLabel = $l->t('Half day (next workday)');
		$halfDayShortcutAria = $l->t('Request a half day of vacation for the next workday');
	}
}
// Half-day deep link and create form default to Vacation (one less decision).
$typeIsVacation = ($absence && $absence->getType() === 'vacation')
	|| ($mode === 'create' && !$absence);
$editIsHalf = $absence
	&& $absence->getType() === 'vacation'
	&& !$isVacationHours
	&& $absence->getDays() !== null
	&& abs((float)$absence->getDays() - 0.5) < 0.011
	&& $absence->getStartDate()
	&& $absence->getEndDate()
	&& $absence->getStartDate()->format('Y-m-d') === $absence->getEndDate()->format('Y-m-d');
$halfSelected = $editIsHalf || ($mode === 'create' && $prefillHalf && !$isVacationHours);
$showDayFractionInitially = !$isVacationHours
	&& in_array($mode, ['create', 'edit'], true)
	&& $typeIsVacation
	&& $absenceFormStartDisplay !== ''
	&& $absenceFormStartDisplay === $absenceFormEndDisplay;
$unitWord = $isVacationHours ? $l->t('hours') : $l->t('days');
$unitSublabel = $isVacationHours ? $l->t('vacation hours') : $l->t('vacation days');
$hoursPerDay = (float)($stats['vacation_hours_per_day'] ?? 8);
if ($hoursPerDay < 0.25) {
	$hoursPerDay = 8.0;
}
$oneDayHours = (float)($stats['vacation_one_day_hours'] ?? $hoursPerDay);
if ($oneDayHours < 0.25) {
	$oneDayHours = $hoursPerDay;
}
$averageDailyHours = (float)($stats['vacation_average_daily'] ?? $oneDayHours);
$weekdayNetsJson = json_encode($stats['vacation_weekday_nets'] ?? null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$vacationDebitBasis = (string)($stats['vacation_debit_basis'] ?? '');
$showOrgDebitFallbackWarn = $isVacationHours
	&& ($mode === 'create' || $mode === 'edit')
	&& $vacationDebitBasis === 'org_hours_per_day';
$estimateHoursUrl = \OCP\Server::get(\OCP\IURLGenerator::class)
	->linkToRoute('arbeitszeitcheck.absence.estimateVacationHours');
$absenceDurationHoursPrefill = '';
if ($absence && method_exists($absence, 'getDurationHours')) {
	$dh = $absence->getDurationHours();
	if ($dh !== null && (float)$dh > 0) {
		$absenceDurationHoursPrefill = (string)round((float)$dh, 2);
	}
}
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack">
        <?php if ($mode === 'list' && $error): ?>
            <div class="azc-callout azc-callout--danger absences-page__list-error" role="alert">
                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('alert-triangle', 'azc-callout__icon-svg')); ?></span>
                <p class="azc-callout__text"><?php p($error); ?></p>
            </div>
        <?php endif; ?>
        <?php if ($mode === 'list'): ?>
        <div class="header-actions azc-page-actions-source">
            <button id="btn-request-absence"
                    class="azc-btn azc-btn--primary"
                    type="button"
                    aria-label="<?php p($l->t('Request time off for vacation or sick leave')); ?>">
                <?php p($l->t('Request Time Off')); ?>
            </button>
            <?php if ($halfDayTodayUrl !== ''): ?>
            <a id="btn-half-day-today"
               class="azc-btn azc-btn--secondary"
               href="<?php p($halfDayTodayUrl); ?>"
               aria-label="<?php p($halfDayShortcutAria); ?>">
                <?php p($halfDayShortcutLabel); ?>
            </a>
            <?php endif; ?>
            <button id="btn-filter"
                    class="azc-btn azc-btn--secondary"
                    type="button"
                    aria-label="<?php p($l->t('Filter absence requests by date or status')); ?>">
                <?php p($l->t('Filter')); ?>
            </button>
        </div>
        <?php endif; ?>

        <?php if (in_array($mode, ['create', 'edit'], true) && $useAppTeams): ?>
            <?php if (!$employeeHasAssignableManager): ?>
            <div class="azc-callout azc-callout--info absence-request-callout" role="status" aria-live="polite" aria-labelledby="approval-hint-title">
                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('info', 'azc-callout__icon-svg')); ?></span>
                <div class="azc-callout__body">
                    <p id="approval-hint-title" class="azc-callout__title"><?php p($l->t('How your request is approved')); ?></p>
                    <p class="azc-callout__text"><?php p($l->t('No approver is assigned to your team in the app. Requests you submit without a substitute are approved automatically when you send them.')); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="azc-callout azc-callout--info absence-request-callout" role="status" aria-labelledby="approval-hint-manager-title">
                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('user-check', 'azc-callout__icon-svg')); ?></span>
                <div class="azc-callout__body">
                    <p id="approval-hint-manager-title" class="azc-callout__title"><?php p($l->t('How your request is approved')); ?></p>
                    <p class="azc-callout__text"><?php p($l->t('After you submit, your manager reviews the request. If you choose a substitute, they must approve first, then your manager.')); ?></p>
                </div>
            </div>
            <?php endif; ?>
        <?php elseif ($useAppTeams && !$employeeHasAssignableManager && $mode === 'list'): ?>
            <div class="azc-callout azc-callout--info absence-request-callout" role="status" aria-live="polite" aria-labelledby="approval-hint-list-title">
                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('info', 'azc-callout__icon-svg')); ?></span>
                <div class="azc-callout__body">
                    <p id="approval-hint-list-title" class="azc-callout__title"><?php p($l->t('How your request is approved')); ?></p>
                    <p class="azc-callout__text"><?php p($l->t('No approver is assigned to your team in the app. Requests you submit without a substitute are approved automatically when you send them.')); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'list'): ?>
            <?php
            $filterStartDate = $_['filterStartDate'] ?? '';
            $filterEndDate = $_['filterEndDate'] ?? '';
            $filterStatus = $_['filterStatus'] ?? '';
            ?>
            <section id="filter-section" class="azc-card azc-filter-panel absences-page__filter" style="display: none;" aria-labelledby="absences-filter-title">
                <header class="azc-filter-panel__head">
                    <h2 id="absences-filter-title"><?php p($l->t('Filter')); ?></h2>
                    <p class="azc-filter-panel__intro"><?php p($l->t('Narrow the list by date range or status, then click Apply.')); ?></p>
                </header>
                <div class="azc-filter-panel__body">
                    <form class="azc-filter-panel__form" novalidate>
                        <div class="azc-filter-grid absences-page__filter-grid" role="group" aria-label="<?php p($l->t('Filter options')); ?>">
                            <div class="azc-filter-field">
                                <label for="filter-start-date" class="azc-filter-field__label"><?php p($l->t('Start Date')); ?></label>
                                <div class="azc-filter-field__control">
                                    <input type="text" id="filter-start-date" name="start_date" class="form-input datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" value="<?php p($filterStartDate); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly>
                                </div>
                            </div>
                            <div class="azc-filter-field">
                                <label for="filter-end-date" class="azc-filter-field__label"><?php p($l->t('End Date')); ?></label>
                                <div class="azc-filter-field__control">
                                    <input type="text" id="filter-end-date" name="end_date" class="form-input datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" value="<?php p($filterEndDate); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly>
                                </div>
                            </div>
                            <div class="azc-filter-field">
                                <label for="filter-status" class="azc-filter-field__label"><?php p($l->t('Status')); ?></label>
                                <div class="azc-filter-field__control">
                                    <select id="filter-status" name="status" class="form-select">
                                        <option value=""><?php p($l->t('All')); ?></option>
                                        <option value="pending" <?php echo ($filterStatus === 'pending') ? 'selected' : ''; ?>><?php p($l->t('Pending')); ?></option>
                                        <option value="approved" <?php echo ($filterStatus === 'approved') ? 'selected' : ''; ?>><?php p($l->t('Approved')); ?></option>
                                        <option value="rejected" <?php echo ($filterStatus === 'rejected') ? 'selected' : ''; ?>><?php p($l->t('Rejected')); ?></option>
                                        <option value="substitute_declined" <?php echo ($filterStatus === 'substitute_declined') ? 'selected' : ''; ?>><?php p($l->t('Declined by substitute')); ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="azc-filter-field azc-filter-field--actions">
                                <span class="azc-filter-field__label" aria-hidden="true">&nbsp;</span>
                                <div class="azc-filter-field__control azc-filter-field__control--actions">
                                    <button type="button" id="btn-apply-filter" class="azc-btn azc-btn--primary"><?php p($l->t('Apply')); ?></button>
                                    <button type="button" id="btn-clear-filter" class="azc-btn azc-btn--secondary"><?php p($l->t('Clear')); ?></button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($mode === 'create' || $mode === 'edit'): ?>
            <section class="azc-card absence-request-card" aria-labelledby="form-title" aria-describedby="form-desc">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="form-title" class="azc-card__title"><?php
                            if ($mode === 'create') {
                                p($l->t('Request time off'));
                            } else {
                                p($l->t('Edit absence request'));
                            }
                        ?></h2>
                        <p id="form-desc" class="azc-card__lead"><?php p($l->t('Fill in the type, dates, and optional reason and substitute.')); ?></p>
                    </div>
                </header>
                <div class="azc-card__body">
                <p class="form-required-note">
                    <span class="form-required" aria-hidden="true">*</span>
                    <?php p($l->t('Required field')); ?>
                </p>
                <div class="azc-callout azc-callout--danger" role="alert" id="absence-form-error"<?php echo $error ? '' : ' hidden'; ?>>
                    <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('alert-triangle', 'azc-callout__icon-svg')); ?></span>
                    <p id="absence-form-error-text" class="azc-callout__text"><?php echo $error ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : ''; ?></p>
                </div>

                <form id="absence-form" class="form absence-request-form" method="POST" novalidate
                      action="<?php 
                    if ($mode === 'create') {
                        p($urlGenerator->linkToRoute('arbeitszeitcheck.absence.store'));
                    } else {
                        p($urlGenerator->linkToRoute('arbeitszeitcheck.absence.updatePost', ['id' => $absence->getId()]));
                    }
                ?>">
                    <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">
                    <fieldset class="absence-form-fieldset" id="absence-form-details-fieldset">
                        <legend class="absence-form-fieldset__legend"><?php p($l->t('Request details')); ?></legend>

                    <div class="azc-callout azc-callout--info absence-past-entry-hint" role="note" aria-labelledby="absence-past-entry-title">
                        <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('calendar', 'azc-callout__icon-svg')); ?></span>
                        <div class="azc-callout__body">
                            <p id="absence-past-entry-title" class="azc-callout__title"><?php p($l->t('Past absences are allowed')); ?></p>
                            <p class="azc-callout__text"><?php p($l->t('Use the same form for old vacation, sick leave, migration records, and future requests. Closed months stay protected and cannot be changed unless an administrator reopens them.')); ?></p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="absence-type" class="form-label">
                            <?php p($l->t('Type')); ?> <span class="form-required" aria-hidden="true">*</span>
                        </label>
                        <select id="absence-type" name="type" class="form-select" required aria-describedby="absence-type-help">
                            <?php if ($mode === 'edit'): ?>
                            <option value=""><?php p($l->t('Choose absence type…')); ?></option>
                            <?php endif; ?>
                            <option value="vacation" <?php echo $typeIsVacation ? 'selected' : ''; ?>>
                                <?php p($l->t('Vacation')); ?>
                            </option>
                            <option value="sick_leave" <?php echo ($absence && $absence->getType() === 'sick_leave') ? 'selected' : ''; ?>>
                                <?php p($l->t('Sick Leave')); ?>
                            </option>
                            <option value="personal_leave" <?php echo ($absence && $absence->getType() === 'personal_leave') ? 'selected' : ''; ?>>
                                <?php p($l->t('Personal Leave')); ?>
                            </option>
                            <option value="parental_leave" <?php echo ($absence && $absence->getType() === 'parental_leave') ? 'selected' : ''; ?>>
                                <?php p($l->t('Parental Leave')); ?>
                            </option>
                            <option value="special_leave" <?php echo ($absence && $absence->getType() === 'special_leave') ? 'selected' : ''; ?>>
                                <?php p($l->t('Special Leave')); ?>
                            </option>
                            <option value="unpaid_leave" <?php echo ($absence && $absence->getType() === 'unpaid_leave') ? 'selected' : ''; ?>>
                                <?php p($l->t('Unpaid Leave')); ?>
                            </option>
                            <option value="home_office" <?php echo ($absence && $absence->getType() === 'home_office') ? 'selected' : ''; ?>>
                                <?php p($l->t('Home Office')); ?>
                            </option>
                            <option value="business_trip" <?php echo ($absence && $absence->getType() === 'business_trip') ? 'selected' : ''; ?>>
                                <?php p($l->t('Business Trip')); ?>
                            </option>
                        </select>
                        <p id="absence-type-help" class="form-help"><?php p($l->t('Vacation, sick leave, and other types each follow your organisation’s rules.')); ?></p>
                    </div>

                    <div class="absence-form-dates" role="group" aria-labelledby="absence-dates-legend">
                        <p id="absence-dates-legend" class="absence-form-dates__legend"><?php p($l->t('Period')); ?> <span class="form-required" aria-hidden="true">*</span></p>
                        <div class="absence-form-dates__grid">
                            <div class="form-group">
                                <label for="absence-start-date" class="form-label">
                                    <?php p($l->t('Start Date')); ?> <span class="form-required" aria-hidden="true">*</span>
                                </label>
                                <input type="text"
                                       id="absence-start-date"
                                       name="start_date"
                                       class="form-input datepicker-input"
                                       data-datepicker-min=""
                                       data-datepicker-sync-month-with="absence-end-date"
                                       value="<?php p($absenceFormStartDisplay); ?>"
                                       placeholder="<?php p($l->t('dd.mm.yyyy')); ?>"
                                       pattern="\d{2}\.\d{2}\.\d{4}"
                                       maxlength="10"
                                       inputmode="numeric"
                                       autocomplete="off"
                                       spellcheck="false"
                                       required
                                       aria-required="true"
                                       aria-describedby="absence-start-date-help">
                                <p id="absence-start-date-help" class="form-help"><?php p($l->t('First day away')); ?></p>
                            </div>
                            <div class="form-group">
                                <label for="absence-end-date" class="form-label">
                                    <?php p($l->t('End Date')); ?> <span class="form-required" aria-hidden="true">*</span>
                                </label>
                                <input type="text"
                                       id="absence-end-date"
                                       name="end_date"
                                       class="form-input datepicker-input"
                                       data-datepicker-min=""
                                       data-datepicker-sync-month-with="absence-start-date"
                                       value="<?php p($absenceFormEndDisplay); ?>"
                                       placeholder="<?php p($l->t('dd.mm.yyyy')); ?>"
                                       pattern="\d{2}\.\d{2}\.\d{4}"
                                       maxlength="10"
                                       inputmode="numeric"
                                       autocomplete="off"
                                       spellcheck="false"
                                       required
                                       aria-required="true"
                                       aria-describedby="absence-end-date-help">
                                <p id="absence-end-date-help" class="form-help"><?php p($l->t('Last day away (can match start for one day)')); ?></p>
                            </div>
                        </div>
                        <p id="absence-multi-day-half-tip"
                           class="form-help absence-multi-day-half-tip"
                           hidden
                           role="status"
                           aria-live="polite"
                           data-days-mode="<?php echo $isVacationHours ? '0' : '1'; ?>">
                            <?php p($l->t('Need a half day plus full days? Submit the half day as its own request, then the remaining days.')); ?>
                        </p>
                    </div>

                    <div id="absence-day-fraction-group"
                         class="form-group absence-day-fraction"
                         data-days-mode="<?php echo $isVacationHours ? '0' : '1'; ?>"
                         <?php echo $showDayFractionInitially ? '' : 'hidden'; ?>>
                        <fieldset class="absence-day-fraction__fieldset">
                            <legend id="absence-day-fraction-legend" class="form-label absence-day-fraction__legend"><?php p($l->t('Day length')); ?></legend>
                            <div class="absence-day-fraction__segments"
                                 role="radiogroup"
                                 aria-labelledby="absence-day-fraction-legend"
                                 aria-describedby="absence-day-fraction-help absence-day-fraction-preview absence-day-fraction-error">
                                <label class="absence-day-fraction__segment">
                                    <input type="radio"
                                           name="day_fraction"
                                           id="absence-day-fraction-full"
                                           value="1"
                                           <?php echo $halfSelected ? '' : 'checked'; ?>
                                           class="absence-day-fraction__input">
                                    <span class="absence-day-fraction__face"><?php p($l->t('Full day')); ?></span>
                                </label>
                                <label class="absence-day-fraction__segment">
                                    <input type="radio"
                                           name="day_fraction"
                                           id="absence-day-fraction-half"
                                           value="0.5"
                                           <?php echo $halfSelected ? 'checked' : ''; ?>
                                           class="absence-day-fraction__input">
                                    <span class="absence-day-fraction__face"><?php p($l->t('Half day')); ?></span>
                                </label>
                            </div>
                        </fieldset>
                        <p id="absence-day-fraction-help" class="form-help"><?php p($l->t('Full day or half day for this date. Morning or afternoon is not tracked.')); ?></p>
                        <p id="absence-day-fraction-preview" class="azc-callout azc-callout--info absence-day-fraction__preview" role="status" aria-live="polite"></p>
                        <p id="absence-day-fraction-live" class="sr-only" aria-live="polite"></p>
                        <p id="absence-day-fraction-error" class="form-error" role="alert" hidden></p>
                    </div>

                    <div id="absence-duration-hours-group"
                         class="form-group absence-duration-hours"
                         data-hours-mode="<?php echo $isVacationHours ? '1' : '0'; ?>"
                         data-hours-per-day="<?php p((string)$hoursPerDay); ?>"
                         data-one-day-hours="<?php p((string)$oneDayHours); ?>"
                         data-average-daily="<?php p((string)$averageDailyHours); ?>"
                         data-debit-basis="<?php p($vacationDebitBasis); ?>"
                         data-weekday-nets="<?php echo $weekdayNetsJson !== false ? $weekdayNetsJson : 'null'; ?>"
                         data-estimate-url="<?php p($estimateHoursUrl); ?>"
                         <?php echo $isVacationHours ? '' : 'hidden'; ?>>
                        <label for="absence-duration-hours" class="form-label">
                            <?php p($l->t('Hours')); ?>
                            <span id="absence-duration-hours-req" class="form-required" aria-hidden="true">*</span>
                        </label>
                        <input type="number"
                               id="absence-duration-hours"
                               name="duration_hours"
                               class="form-input form-input--hours"
                               min="0.25"
                               max="744"
                               step="0.25"
                               inputmode="decimal"
                               value="<?php p($absenceDurationHoursPrefill); ?>"
                               placeholder="<?php p($l->t('e.g. 4')); ?>"
                               aria-describedby="absence-duration-hours-help absence-duration-hours-presets<?php echo $showOrgDebitFallbackWarn ? ' absence-org-debit-warn-text' : ''; ?>"
                               <?php echo $isVacationHours ? '' : 'disabled'; ?>>
                        <div id="absence-duration-hours-presets" class="absence-hours-presets" role="group" aria-label="<?php p($l->t('Quick hours')); ?>">
                            <button type="button" class="azc-btn azc-btn--secondary absence-hours-preset" data-hours="4" aria-label="<?php p($l->t('Set 4 hours')); ?>"><?php p($l->t('4 hours')); ?></button>
                            <button type="button" class="azc-btn azc-btn--secondary absence-hours-preset" data-hours-half="1" aria-label="<?php p($l->t('Set half day')); ?>"><?php p($l->t('Half day')); ?></button>
                            <button type="button" class="azc-btn azc-btn--secondary absence-hours-preset" data-hours-full="1" aria-label="<?php p($l->t('Set one full day')); ?>"><?php p($l->t('1 full day')); ?></button>
                            <button type="button" class="azc-btn azc-btn--primary absence-hours-preset" data-hours-range="1" aria-label="<?php p($l->t('Fill planned hours for the date range')); ?>"><?php p($l->t('Full range')); ?></button>
                        </div>
                        <p id="absence-duration-hours-help" class="form-help">
                            <?php p($l->t('Total vacation hours for the whole request (not per day). “Full range” and “1 full day” use your work model (e.g. shorter Friday), not a fixed 8 hours. Public holidays reduce the final debit.')); ?>
                        </p>
                        <?php if ($showOrgDebitFallbackWarn): ?>
                        <div id="absence-org-debit-warn" class="azc-callout azc-callout--warning absence-org-debit-warn" role="status" aria-live="polite">
                            <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('alert-triangle', 'azc-callout__icon-svg')); ?></span>
                            <div class="azc-callout__body">
                                <p class="azc-callout__title"><?php p($l->t('No working-time model assigned')); ?></p>
                                <p id="absence-org-debit-warn-text" class="azc-callout__text"><?php p($l->t('Hours for this request use the organisation conversion factor until your administrator assigns a working-time model. Ask them to set your schedule so shorter Fridays and weekday nets apply correctly.')); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <p id="absence-duration-hours-preview" class="azc-callout azc-callout--info absence-hours-preview" role="status" aria-live="polite" hidden></p>
                        <input type="hidden" id="absence-require-duration-hours" name="require_duration_hours" value="1" <?php echo $isVacationHours ? '' : 'disabled'; ?>>
                    </div>

                    <div id="absence-historical-hint"
                         class="azc-callout azc-callout--warning absence-historical-hint"
                         role="status"
                         aria-live="polite"
                         hidden>
                        <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('clock', 'azc-callout__icon-svg')); ?></span>
                        <div class="azc-callout__body">
                            <p class="azc-callout__title"><?php p($l->t('Historical entry – the dates you selected are in the past')); ?></p>
                            <p class="azc-callout__text" id="absence-historical-hint-default-text"><?php p($l->t('You can submit this as a regular request. Your manager will still review and approve or reject it like any other request, and the substitute workflow does not apply to dates that already passed.')); ?></p>
                            <p class="azc-callout__text absence-historical-hint__text--auto" id="absence-historical-hint-auto-text" hidden><?php p($l->t('No approver is assigned to your team in the app, so this historical absence will be auto-approved as soon as you submit it.')); ?></p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="absence-reason" class="form-label">
                            <?php p($l->t('Reason')); ?> <span class="form-optional"><?php p($l->t('(optional)')); ?></span>
                        </label>
                        <textarea id="absence-reason"
                                  name="reason"
                                  class="form-textarea"
                                  rows="4"
                                  maxlength="500"
                                  aria-describedby="absence-reason-help"
                                  placeholder="<?php p($l->t('Optional notes for your manager')); ?>"><?php p($absence ? ($absence->getReason() ?? '') : ''); ?></textarea>
                        <p id="absence-reason-help" class="form-help"><?php p($l->t('Short note only — not shown on calendars. Maximum 500 characters.')); ?></p>
                    </div>
                    </fieldset>

                    <fieldset class="absence-form-fieldset absence-form-fieldset--substitute" id="absence-substitute-group">
                        <legend class="absence-form-fieldset__legend" id="absence-substitute-legend">
                            <?php p($l->t('Substitute (Vertretung)')); ?>
                            <span id="absence-substitute-required-mark" class="form-required" hidden aria-hidden="true">*</span>
                        </legend>
                        <div class="form-group form-group--substitute">
                        <label for="absence-substitute" class="form-label" id="absence-substitute-label">
                            <?php p($l->t('Who will cover for you?')); ?>
                        </label>
                        <select id="absence-substitute"
                                name="substitute_user_id"
                                class="form-select"
                                aria-describedby="absence-substitute-help absence-substitute-status"
                                aria-required="false"
                                aria-busy="false">
                            <option value=""><?php p($l->t('No substitute')); ?></option>
                        </select>
                        <p id="absence-substitute-help" class="form-help"><?php p($l->t('Choose a colleague from your team who will cover your tasks during your absence. Only team members appear in this list.')); ?></p>
                        <p id="absence-substitute-status" class="form-help form-help--status" aria-live="polite" role="status"></p>
                        <p id="absence-substitute-empty" class="form-help form-help--info" hidden role="status"><?php p($l->t('No team members found. Add yourself to a team or group to select a substitute.')); ?></p>
                        <p id="absence-substitute-error" class="form-help form-help--error" hidden role="alert"><?php p($l->t('Could not load team members. Please try again.')); ?></p>
                        <p id="absence-substitute-required-msg" class="form-help form-help--error" hidden role="alert"><?php p($l->t('A substitute is required for this absence type. Please select who will cover for you.')); ?></p>
                        </div>
                    </fieldset>

                    <div class="form-actions absence-request-form__actions">
                        <button type="submit" class="azc-btn azc-btn--primary">
                            <?php p($mode === 'create' ? $l->t('Submit Request') : $l->t('Update Request')); ?>
                        </button>
                        <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.absences')); ?>" class="azc-btn azc-btn--secondary">
                            <?php p($l->t('Cancel')); ?>
                        </a>
                    </div>
                </form>
                </div>
            </section>
        <?php elseif ($mode === 'view' && $absence): ?>
            <?php
            $start = $absence->getStartDate();
            $end = $absence->getEndDate();
            $days = $absence->getDays();
            if ($days === null) {
                $days = $_['displayDays'] ?? ($_['computedWorkingDays'][$absence->getId()] ?? $absence->calculateWorkingDays());
            }
            $canCancel = $start > $today
                && !in_array($absence->getStatus(), ['cancelled', 'rejected', 'substitute_declined'], true);
            $isPastAbsence = $end < $today;
            ?>
            <!-- Read-only Absence Details -->
            <section class="section section--detail absence-detail-view" aria-labelledby="detail-title">
                <h2 id="detail-title" class="section__title visually-hidden"><?php p($l->t('Absence details')); ?></h2>

                <?php if ($absence->getStatus() === 'pending' && $useAppTeams && !$employeeHasAssignableManager): ?>
                    <div class="absence-detail-stuck-callout">
                        <?php
                        $calloutVariant = 'warning';
                        $calloutRole = 'alert';
                        $calloutBanner = false;
                        $calloutIcon = 'alert-triangle';
                        $calloutTitle = $l->t('Waiting for approval');
                        $calloutText = $l->t('This request is waiting for approval, but no approver is assigned to your team in the app. Contact your administrator to fix the team setup, or wait until the system can process it.');
                        $calloutHint = '';
                        include __DIR__ . '/common/alert-callout.php';
                        ?>
                    </div>
                <?php endif; ?>

                <!-- Header: type, status, period summary -->
                <div class="absence-detail-hero">
                    <div class="absence-detail-badges" role="group" aria-label="<?php p($l->t('Type and status')); ?>">
                        <span class="absence-type-badge type-<?php p($absence->getType()); ?>">
                            <?php
                            $typeKey = $absence->getType();
                            $typeLabel = match($typeKey) {
                                'vacation' => $l->t('Vacation'),
                                'sick' => $l->t('Sick Leave'),
                                'sick_leave' => $l->t('Sick Leave'),
                                'personal_leave' => $l->t('Personal Leave'),
                                'parental_leave' => $l->t('Parental Leave'),
                                'special_leave' => $l->t('Special Leave'),
                                'unpaid_leave' => $l->t('Unpaid Leave'),
                                'home_office' => $l->t('Home Office'),
                                'business_trip' => $l->t('Business Trip'),
                                default => $l->t('Absence')
                            };
                            p($typeLabel);
                            ?>
                        </span>
                        <span class="badge badge--<?php p(BadgeVariant::forAbsenceStatus((string)$absence->getStatus())); ?>">
                            <?php
                            $statusKey = $absence->getStatus();
                            $statusLabel = match($statusKey) {
                                'approved' => $l->t('Approved'),
                                'pending' => $l->t('Awaiting manager approval'),
                                'substitute_pending' => $l->t('Awaiting substitute approval'),
                                'rejected' => $l->t('Rejected'),
                                'substitute_declined' => $l->t('Declined by substitute'),
                                'cancelled' => $l->t('Cancelled'),
                                default => $l->t(ucfirst(str_replace('_', ' ', $statusKey)))
                            };
                            p($statusLabel);
                            ?>
                        </span>
                        <?php if ($isPastAbsence): ?>
                            <span class="badge badge--past-record"><?php p($l->t('Past record')); ?></span>
                        <?php endif; ?>
                        <?php
                        $detailIsHalf = $absence->getType() === 'vacation'
                            && !$isVacationHours
                            && $days !== null
                            && abs((float)$days - 0.5) < 0.011;
                        if ($detailIsHalf): ?>
                            <span class="badge badge--info absence-half-day-badge"><?php p($l->t('Half day')); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="absence-detail-period" aria-label="<?php p($l->t('Period and duration')); ?>">
                        <?php p($start->format('d.m.Y')); ?><?php echo ' – '; ?><?php p($end->format('d.m.Y')); ?>
                        <span class="absence-detail-period-sep" aria-hidden="true">·</span>
                        <?php
                        $detailHours = $absence->getDurationHours();
                        $showHoursDetail = $absence->getType() === 'vacation'
                            && $detailHours !== null && (float)$detailHours > 0;
                        if ($showHoursDetail) {
                            p(sprintf('%s %s', (string)round((float)$detailHours, 1), $l->t('hours')));
                        } else {
                            $dayLabel = round((float)$days, 1);
                            // n() needs an integer for plural selection; show the real amount separately when fractional.
                            if (abs($dayLabel - (int)$dayLabel) < 0.05) {
                                p($l->n('%n working day', '%n working days', (int)round($dayLabel)));
                            } else {
                                p($l->t('%s working days', [(string)$dayLabel]));
                            }
                        }
                        ?>
                    </p>
                </div>

                <?php
                $canEditAfterDecline = $absence->getStatus() === 'substitute_declined';
                ?>
                <?php if ($canEditAfterDecline): ?>
                    <div class="absence-detail-actions absence-detail-actions--top" role="group" aria-label="<?php p($l->t('Actions after substitute declined')); ?>">
                        <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.absence.edit', ['id' => $absence->getId()])); ?>" class="btn btn--primary" aria-label="<?php p($l->t('Edit to select a different substitute')); ?>">
                            <?php p($l->t('Edit and select different substitute')); ?>
                        </a>
                        <p class="absence-detail-hint"><?php p($l->t('You can edit to select a different substitute or delete this request from the overview.')); ?></p>
                    </div>
                <?php elseif ($canCancel): ?>
                    <div class="absence-detail-actions absence-detail-actions--top">
                        <form method="POST"
                              action="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.absence.cancel', ['id' => $absence->getId()])); ?>"
                              class="js-confirm-form">
                            <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">
                            <button type="submit"
                                    class="btn btn--secondary btn--danger js-confirm-submit"
                                    data-confirm-title="<?php p($l->t('Cancel absence')); ?>"
                                    data-confirm-message="<?php p($l->t('Do you really want to cancel this absence? This cannot be undone.')); ?>"
                                    data-confirm-variant="danger"
                                    data-confirm-label="<?php p($l->t('Yes, cancel absence')); ?>"
                                    aria-label="<?php p($l->t('Cancel this absence request')); ?>">
                                <?php p($l->t('Cancel absence')); ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php
                $canShorten = $absence->getStatus() === 'approved'
                    && $start <= $today
                    && $end > $today;
                ?>
                <?php if ($canShorten): ?>
                <div class="absence-detail-section absence-detail-shorten" role="region" aria-labelledby="shorten-heading">
                    <h3 id="shorten-heading" class="absence-detail-section__title"><?php p($l->t('I returned early')); ?></h3>
                    <p class="absence-detail-shorten__desc"><?php p($l->t('Set the actual last day of your absence so your records and your substitute\'s calendar stay accurate.')); ?></p>
                    <form id="form-shorten-absence" class="form form--inline absence-detail-shorten__form" method="POST"
                          action="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.absence.shortenForm', ['id' => $absence->getId()])); ?>">
                        <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">
                        <div class="form-group">
                            <label for="shorten-end-date" class="form-label"><?php p($l->t('New end date')); ?></label>
                            <input type="text"
                                   id="shorten-end-date"
                                   name="end_date"
                                   class="form-input datepicker-input"
                                   data-datepicker-min="<?php p($start->format('d.m.Y')); ?>"
                                   data-datepicker-max="<?php p((clone $end)->modify('-1 day')->format('d.m.Y')); ?>"
                                   value="<?php p((new \DateTime())->format('d.m.Y')); ?>"
                                   placeholder="<?php p($l->t('dd.mm.yyyy')); ?>"
                                   pattern="\d{2}\.\d{2}\.\d{4}"
                                   maxlength="10"
                                   required
                                   aria-required="true"
                                   aria-describedby="shorten-help">
                            <p id="shorten-help" class="form-help"><?php p($l->t('Pick the day you actually returned. Must be before the original end date.')); ?></p>
                        </div>
                        <div class="form-group form-group--actions">
                            <button type="submit" class="btn btn--primary" aria-label="<?php p($l->t('Update end date and shorten absence')); ?>">
                                <?php p($l->t('Update end date')); ?>
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Dates & Duration -->
                <div class="absence-detail-section" role="region" aria-labelledby="absence-detail-dates-heading">
                    <h3 id="absence-detail-dates-heading" class="absence-detail-section__title"><?php p($l->t('Dates and duration')); ?></h3>
                    <dl class="absence-detail-list">
                        <div class="absence-detail-row">
                            <dt class="absence-detail-label"><?php p($l->t('Period')); ?></dt>
                            <dd class="absence-detail-value"><?php p($start->format('d.m.Y')); ?> – <?php p($end->format('d.m.Y')); ?></dd>
                        </div>
                        <div class="absence-detail-row">
                            <dt class="absence-detail-label"><?php
                                $detailHoursVal = $absence->getDurationHours();
                                $detailShowHours = $absence->getType() === 'vacation'
                                    && $detailHoursVal !== null && (float)$detailHoursVal > 0;
                                echo $detailShowHours ? $l->t('Duration') : $l->t('Working days');
                            ?></dt>
                            <dd class="absence-detail-value"><?php
                                if ($detailShowHours) {
                                    p((string)round((float)$detailHoursVal, 1) . ' ' . $l->t('hours'));
                                } else {
                                    p((string)$days);
                                }
                            ?></dd>
                        </div>
                        <?php if ($detailShowHours && (float)$days > 0) { ?>
                        <details class="absence-detail-more">
                            <summary><?php p($l->t('Schedule note (working days in range)')); ?></summary>
                            <dl class="absence-detail-list">
                                <div class="absence-detail-row">
                                    <dt class="absence-detail-label"><?php p($l->t('Working days in range')); ?></dt>
                                    <dd class="absence-detail-value"><?php p((string)round((float)$days, 1)); ?></dd>
                                </div>
                            </dl>
                            <p class="form-help"><?php p($l->t('This is the calendar weight for your schedule — not a second vacation balance in days.')); ?></p>
                        </details>
                        <?php } ?>
                    </dl>
                </div>

                <!-- Details: Reason, Substitute, Approval comment -->
                <div class="absence-detail-section" role="region" aria-labelledby="absence-detail-info-heading">
                    <h3 id="absence-detail-info-heading" class="absence-detail-section__title"><?php p($l->t('Details')); ?></h3>
                    <dl class="absence-detail-list">
                        <div class="absence-detail-row">
                            <dt class="absence-detail-label"><?php p($l->t('Reason')); ?></dt>
                            <dd class="absence-detail-value"><?php
                                $reason = $absence->getReason();
                                p($reason ?: $l->t('No additional reason provided'));
                            ?></dd>
                        </div>
                        <div class="absence-detail-row">
                            <dt class="absence-detail-label"><?php p($l->t('Substitute')); ?></dt>
                            <dd class="absence-detail-value"><?php p($substituteDisplayName ?? $absence->getSubstituteUserId() ?? $l->t('None')); ?></dd>
                        </div>
                        <div class="absence-detail-row">
                            <dt class="absence-detail-label"><?php p($absence->getStatus() === 'substitute_declined' ? $l->t('Reason for declining') : $l->t('Approval comment')); ?></dt>
                            <dd class="absence-detail-value"><?php
                                $comment = $absence->getApproverComment();
                                p($comment ?: $l->t('No approval comment available'));
                            ?></dd>
                        </div>
                    </dl>
                </div>

                <!-- Audit trail: Created, Last updated, Approved at -->
                <div class="absence-detail-section" role="region" aria-labelledby="absence-detail-audit-heading">
                    <h3 id="absence-detail-audit-heading" class="absence-detail-section__title"><?php p($l->t('History')); ?></h3>
                    <dl class="absence-detail-list">
                        <div class="absence-detail-row">
                            <dt class="absence-detail-label"><?php p($l->t('Created')); ?></dt>
                            <dd class="absence-detail-value"><?php p($absence->getCreatedAt()->format('d.m.Y H:i')); ?></dd>
                        </div>
                        <div class="absence-detail-row">
                            <dt class="absence-detail-label"><?php p($l->t('Last updated')); ?></dt>
                            <dd class="absence-detail-value"><?php p($absence->getUpdatedAt()->format('d.m.Y H:i')); ?></dd>
                        </div>
                        <?php if ($absence->getApprovedAt() !== null): ?>
                        <div class="absence-detail-row">
                            <dt class="absence-detail-label"><?php p($l->t('Approved at')); ?></dt>
                            <dd class="absence-detail-value"><?php p($absence->getApprovedAt()->format('d.m.Y H:i')); ?></dd>
                        </div>
                        <?php endif; ?>
                    </dl>
                </div>

                <div class="absence-detail-actions">
                    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.absences')); ?>" class="btn btn--secondary">
                        <?php p($l->t('Back to overview')); ?>
                    </a>
                </div>
            </section>
        <?php else: ?>
            <!-- Stats Cards: Vacation only (sick leave etc. excluded) -->
            <section class="section section--stats vacation-stats" aria-labelledby="stats-title">
                <?php
                // Unit labels computed once at top of template ($isVacationHours, $unitWord, …).
                ?>
                <h2 id="stats-title" class="section__title stats-section-title">
                    <?php
                    $vyLabel = (string)($stats['vacation_year_label'] ?? ($stats['vacation_year'] ?? date('Y')));
                    p($l->t('Vacation balance') . ' ' . $vyLabel);
                    ?>
                </h2>
                <?php if (!empty($stats['vacation_year_error'])) { ?>
                <p class="azc-callout azc-callout--warning" role="status" id="absences-vacation-year-error">
                    <?php p($l->t('Your vacation year uses hire anniversary, but no employment start date is set. Ask your admin to set it under Employees.')); ?>
                </p>
                <?php } ?>
                <p id="stats-desc" class="stats-section-desc visually-hidden">
                    <?php
                    echo $isVacationHours
                        ? $l->t('Remaining vacation hours for this year (annual entitlement plus carryover minus approved vacation). Sick leave and other absences are not deducted.')
                        : $l->t('Remaining vacation days for this year (annual entitlement plus carryover minus approved vacation). Sick leave and other absences are not deducted.');
                    ?>
                </p>
                <?php
                $carryoverExpiresOn = $stats['vacation_carryover_expires_on'] ?? null;
                $carryoverExpiresFmt = '';
                if ($carryoverExpiresOn) {
                    try {
                        $carryoverExpiresFmt = (new \DateTimeImmutable((string)$carryoverExpiresOn))->format('d.m.Y');
                    } catch (\Throwable $e) {
                        $carryoverExpiresFmt = (string)$carryoverExpiresOn;
                    }
                }
                $carryoverLockedAfterDeadline = !empty($stats['vacation_carryover_locked_after_deadline']);
                ?>
                <?php if (!empty($stats)): ?>
                    <p class="stats-section__intro" id="stats-intro">
                        <?php
                        echo $isVacationHours
                            ? $l->t('These numbers only count approved vacation. Amounts are in hours. Carryover must be used on or before the expiry date; after that, new requests use your regular annual entitlement first.')
                            : $l->t('These numbers only count approved vacation. Carryover days must be used for vacation days on or before the expiry date; after that, new requests use your regular annual entitlement first.');
                        ?>
                        <?php if ($carryoverExpiresFmt !== '' && (float)($stats['vacation_carryover_days'] ?? 0) > 0.0001) { ?>
                            <?php p(' ' . $l->t('Carryover expiry this year: %s.', [$carryoverExpiresFmt])); ?>
                        <?php } ?>
                        <?php if (isset($stats['vacation_carryover_max_cap']) && $stats['vacation_carryover_max_cap'] !== null && $stats['vacation_carryover_max_cap'] !== '') { ?>
                            <?php p(' ' . $l->t('Admin cap on opening carryover: %s %s.', [(string)round((float)$stats['vacation_carryover_max_cap'], 1), $unitWord])); ?>
                        <?php } ?>
                    </p>
                    <?php if ($carryoverLockedAfterDeadline && $carryoverExpiresFmt !== '') { ?>
					<div class="vacation-stats__notice vacation-stats__notice--deadline" role="status" aria-live="polite" id="stats-carryover-deadline-notice">
                        <p class="vacation-stats__notice-text"><?php
                            echo $isVacationHours
                                ? $l->t('Carryover deadline has passed (%1$s). New requests can no longer use last year’s remaining hours. The opening balance above is your HR record; approved vacation already reduced it.', [$carryoverExpiresFmt])
                                : $l->t('Carryover deadline has passed (%1$s). New requests can no longer use last year’s remaining days. The opening balance above is your HR record; approved vacation already reduced it.', [$carryoverExpiresFmt]);
                        ?></p>
                        <p class="vacation-stats__notice-text vacation-stats__notice-text--secondary"><?php
                            echo $isVacationHours
                                ? $l->t('You still have hours left on paper, but they can no longer be booked as carryover—use your regular annual entitlement for new requests.')
                                : $l->t('You still have days left on paper, but they can no longer be booked as carryover—use your regular annual entitlement for new requests.');
                        ?></p>
                    </div>
                    <?php } ?>
                    <div class="stats-grid" role="group" aria-labelledby="stats-title" aria-describedby="stats-desc stats-intro<?php echo $carryoverLockedAfterDeadline ? ' stats-carryover-deadline-notice' : ''; ?>">
                        <div class="stat-card stat-card--remaining stat-card--hero">
                            <span class="stat-label" id="stat-remaining-label"><?php p($l->t('Remaining')); ?></span>
                            <span class="stat-value stat-value--hero" aria-labelledby="stat-remaining-label"><?php p((string)round($stats['vacation_days_remaining'] ?? 0, 1)); ?></span>
                            <span class="stat-sublabel"><?php p($unitSublabel); ?></span>
                            <?php if ((float)($stats['vacation_carryover_usable'] ?? 0) > 0.0001 && $carryoverExpiresFmt !== '') { ?>
                            <span class="stat-card__meta" id="stat-carryover-usable"><?php p($l->t('Of which %1$s %2$s carryover until %3$s', [
								(string)round((float)($stats['vacation_carryover_usable'] ?? 0), 1),
								$unitWord,
								$carryoverExpiresFmt,
							])); ?></span>
                            <?php } elseif ($carryoverLockedAfterDeadline && $carryoverExpiresFmt !== '') { ?>
                            <span class="stat-card__meta" id="stat-carryover-usable"><?php p($l->t('Carryover ended %s — book from annual leave only', [$carryoverExpiresFmt])); ?></span>
                            <?php } ?>
                        </div>
                        <div class="stat-card stat-card--entitlement">
                            <span class="stat-label" id="stat-entitlement-label"><?php p($l->t('Annual entitlement')); ?></span>
                            <span class="stat-value" aria-labelledby="stat-entitlement-label"><?php p((string)round($stats['vacation_annual_entitlement'] ?? 0, 1)); ?></span>
                            <span class="stat-sublabel"><?php p($unitSublabel); ?></span>
                            <button type="button" id="entitlement-explain" class="stat-card__action stat-card__action--explain"
                                    aria-haspopup="dialog" aria-controls="entitlement-explain-dialog"
                                    aria-label="<?php p($l->t('Show how my vacation entitlement was calculated')); ?>"><?php p($l->t('How is this calculated?')); ?></button>
                        </div>
                        <div class="stat-card stat-card--used">
                            <span class="stat-label" id="stat-used-label"><?php p($l->t('Used this year')); ?></span>
                            <span class="stat-value stat-value--secondary" aria-labelledby="stat-used-label"><?php p((string)round($stats['vacation_days_used_this_year'] ?? 0, 1)); ?></span>
                            <span class="stat-sublabel"><?php p($unitSublabel); ?></span>
                        </div>
                        <div class="stat-card stat-card--pending">
                            <span class="stat-label" id="stat-pending-label"><?php p($l->t('Pending requests')); ?></span>
                            <span class="stat-value stat-value--secondary" aria-labelledby="stat-pending-label"><?php p((string)($stats['pending_requests'] ?? 0)); ?></span>
                            <span class="stat-sublabel"><?php p($l->t('awaiting approval')); ?></span>
                        </div>
                    </div>
					<details class="vacation-stats__details" id="vacation-stats-details">
						<summary><?php p($l->t('More balance details')); ?></summary>
						<div class="stats-grid stats-grid--compact" role="group" aria-label="<?php p($l->t('Detailed vacation pools')); ?>">
							<div class="stat-card stat-card--carryover<?php echo $carryoverLockedAfterDeadline ? ' stat-card--carryover-locked' : ''; ?>">
								<span class="stat-label" id="stat-carryover-label"><?php p($l->t('Carryover (opening balance)')); ?></span>
								<span class="stat-value" aria-labelledby="stat-carryover-label"><?php p((string)round($stats['vacation_carryover_days'] ?? 0, 1)); ?></span>
								<span class="stat-sublabel"><?php p($unitSublabel); ?></span>
							</div>
							<div class="stat-card stat-card--annual-left">
								<span class="stat-label" id="stat-annual-left-label"><?php p($l->t('Annual leave left')); ?></span>
								<span class="stat-value" aria-labelledby="stat-annual-left-label"><?php p((string)round((float)($stats['vacation_annual_remaining'] ?? 0), 1)); ?></span>
								<span class="stat-sublabel"><?php p($l->t('after approved absences')); ?></span>
							</div>
							<div class="stat-card stat-card--carryover-pool">
								<span class="stat-label" id="stat-carryover-pool-label"><?php p($l->t('Carryover pool left')); ?></span>
								<span class="stat-value" aria-labelledby="stat-carryover-pool-label"><?php p((string)round((float)($stats['vacation_carryover_remaining'] ?? 0), 1)); ?></span>
								<span class="stat-sublabel"><?php p($l->t('after approved absences')); ?></span>
							</div>
						</div>
					</details>
                <?php endif; ?>
            </section>

            <!-- Absences List -->
            <section class="section section--list" aria-labelledby="list-title">
                <h2 id="list-title" class="section__title visually-hidden"><?php p($l->t('Your absence requests')); ?></h2>
                <div class="table-container">
                    <table class="table table--hover absences-table azc-table--responsive" id="absences-table" role="table" aria-labelledby="list-title">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Type')); ?></th>
                                <th scope="col"><?php p($l->t('Start Date')); ?></th>
                                <th scope="col"><?php p($l->t('End Date')); ?></th>
                                <th scope="col"><?php p($isVacationHours ? $l->t('Duration') : $l->t('Days')); ?></th>
                                <th scope="col"><?php p($l->t('Reason')); ?></th>
                                <th scope="col"><?php p($l->t('Status')); ?></th>
                                <th scope="col" class="azc-table-actions-col"><?php p($l->t('Actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($absences)): ?>
                                <?php foreach (($absences ?? []) as $absence): ?>
                                    <?php $isPastAbsence = $absence->getEndDate() < $today; ?>
                                    <tr data-absence-id="<?php p($absence->getId()); ?>" data-status="<?php p($absence->getStatus()); ?>">
                                        <td data-label="<?php p($l->t('Type')); ?>">
                                            <span class="absence-type-badges">
                                                <span class="absence-type-badge type-<?php p($absence->getType()); ?>">
                                                    <?php
                                                    $typeKey = $absence->getType();
                                                    $typeLabel = match($typeKey) {
                                                        'vacation' => $l->t('Vacation'),
                                                        'sick' => $l->t('Sick Leave'),
                                                        'sick_leave' => $l->t('Sick Leave'),
                                                        'personal_leave' => $l->t('Personal Leave'),
                                                        'parental_leave' => $l->t('Parental Leave'),
                                                        'special_leave' => $l->t('Special Leave'),
                                                        'unpaid_leave' => $l->t('Unpaid Leave'),
                                                        'home_office' => $l->t('Home Office'),
                                                        'business_trip' => $l->t('Business Trip'),
                                                        default => $l->t('Absence')
                                                    };
                                                    p($typeLabel);
                                                    ?>
                                                </span>
                                                <?php if ($isPastAbsence): ?>
                                                    <span class="badge badge--past-record absence-past-record-badge"><?php p($l->t('Past record')); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td data-label="<?php p($l->t('Start Date')); ?>"><?php p($absence->getStartDate()->format('d.m.Y')); ?></td>
                                        <td data-label="<?php p($l->t('End Date')); ?>"><?php p($absence->getEndDate()->format('d.m.Y')); ?></td>
                                        <td data-label="<?php p($isVacationHours ? $l->t('Duration') : $l->t('Days')); ?>"><?php
                                            $rowHours = $absence->getDurationHours();
                                            if ($absence->getType() === 'vacation' && $isVacationHours && $rowHours !== null && (float)$rowHours > 0) {
                                                p((string)round((float)$rowHours, 1) . ' ' . $l->t('h'));
                                            } elseif ($absence->getType() === 'vacation' && $isVacationHours) {
                                                // Missing duration_hours: never invent org×days (BANSS-wrong).
                                                $d = $absence->getDays();
                                                $displayD = $d !== null ? (float)$d : (float)(($_['computedWorkingDays'] ?? [])[$absence->getId()] ?? $absence->calculateWorkingDays());
                                                p((string)round($displayD, 1));
                                            } else {
                                                $d = $absence->getDays();
                                                $displayD = $d !== null ? (float)$d : (float)(($_['computedWorkingDays'] ?? [])[$absence->getId()] ?? $absence->calculateWorkingDays());
                                                $listIsHalf = $absence->getType() === 'vacation'
                                                    && !$isVacationHours
                                                    && abs($displayD - 0.5) < 0.011;
                                                if ($listIsHalf) {
                                                    echo '<span class="absence-days-cell absence-days-cell--half">';
                                                    p($l->t('Half day'));
                                                    echo ' <span class="absence-days-cell__qty" aria-hidden="true">(0.5)</span>';
                                                    echo '</span>';
                                                } else {
                                                    p((string)round($displayD, 1));
                                                }
                                            }
                                        ?></td>
                                        <td class="reason-cell" data-label="<?php p($l->t('Reason')); ?>">
                                            <?php 
                                            $reason = $absence->getReason();
                                            if (!$reason): ?>
                                                <span class="text-muted">-</span>
                                            <?php elseif (strlen($reason) <= 60): ?>
                                                <?php p($reason); ?>
                                            <?php else: ?>
                                                <span class="reason-truncated" aria-expanded="false">
                                                    <span class="reason-truncated__short"><?php p(substr($reason, 0, 60)); ?>&hellip;</span>
                                                    <span class="reason-truncated__full" hidden><?php p($reason); ?></span>
                                                    <button type="button"
                                                            class="btn btn--tertiary btn--sm reason-truncated__toggle"
                                                            aria-label="<?php p($l->t('Show full reason')); ?>">
                                                        <?php p($l->t('more')); ?>
                                                    </button>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="<?php p($l->t('Status')); ?>">
                                            <span class="badge badge--<?php p(BadgeVariant::forAbsenceStatus((string)$absence->getStatus())); ?>">
                                                <?php 
                                                $statusKey = $absence->getStatus();
                                                $statusLabel = match($statusKey) {
                                                    'approved' => $l->t('Approved'),
                                                    'pending' => $l->t('Awaiting manager approval'),
                                                    'substitute_pending' => $l->t('Awaiting substitute approval'),
                                                    'rejected' => $l->t('Rejected'),
                                                    'substitute_declined' => $l->t('Declined by substitute'),
                                                    default => $l->t(ucfirst(str_replace('_', ' ', $statusKey)))
                                                };
                                                p($statusLabel);
                                                ?>
                                            </span>
                                        </td>
                                        <td class="actions-cell" data-label="<?php p($l->t('Actions')); ?>">
                                            <div class="azc-table-actions" role="group" aria-label="<?php p($l->t('Actions')); ?>">
                                            <?php if (in_array($absence->getStatus(), ['pending', 'substitute_pending', 'substitute_declined'], true)): ?>
                                                <button type="button" class="btn-icon btn-icon--edit" 
                                                        data-absence-id="<?php p($absence->getId()); ?>"
                                                        aria-label="<?php p($l->t('Edit this absence request')); ?>"
                                                        title="<?php p($l->t('Edit')); ?>">
                                                    <span class="icon icon-rename" aria-hidden="true"></span>
                                                </button>
                                                <button type="button" class="btn-icon btn-icon--cancel" 
                                                        data-absence-id="<?php p($absence->getId()); ?>"
                                                        aria-label="<?php p($l->t('Cancel this absence request')); ?>"
                                                        title="<?php p($l->t('Cancel')); ?>">
                                                    <span class="icon icon-delete" aria-hidden="true"></span>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn-icon btn-icon--view" 
                                                        data-absence-id="<?php p($absence->getId()); ?>"
                                                        aria-label="<?php p($l->t('View details of this absence')); ?>"
                                                        title="<?php p($l->t('View Details')); ?>">
                                                    <span class="icon icon-details" aria-hidden="true"></span>
                                                </button>
                                            <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="empty-state">
                                        <div class="empty-state">
                                            <h3 class="empty-state__title"><?php p($l->t('No absences yet')); ?></h3>
                                            <p class="empty-state__description">
                                                <?php p($l->t('You have not requested any absences yet. Use the button below to request vacation, sick leave, or other time off.')); ?>
                                            </p>
                                            <div class="absence-empty-actions">
                                            <button id="btn-request-first-absence"
                                                class="btn btn--primary"
                                                type="button"
                                                aria-label="<?php p($l->t('Request your first absence')); ?>">
                                                <?php p($l->t('Request Time Off')); ?>
                                            </button>
                                            <?php if ($halfDayTodayUrl !== ''): ?>
                                            <a id="btn-half-day-today-empty"
                                               class="azc-btn azc-btn--secondary"
                                               href="<?php p($halfDayTodayUrl); ?>"
                                               aria-label="<?php p($halfDayShortcutAria); ?>">
                                                <?php p($halfDayShortcutLabel); ?>
                                            </a>
                                            <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>

<!--
    Employee-facing entitlement explainer dialog (REQ-UX-04).
    Built as a native <dialog> so focus is trapped, ESC closes,
    and screen readers read the title + content as a modal.
-->
<dialog id="entitlement-explain-dialog" class="entitlement-explain-dialog azc-native-dialog"
        aria-modal="true"
        aria-labelledby="entitlement-explain-title"
        aria-describedby="entitlement-explain-intro">
    <div class="entitlement-explain-dialog__panel">
        <header class="entitlement-explain-dialog__header">
            <h2 id="entitlement-explain-title" class="entitlement-explain-dialog__title">
                <?php p($l->t('How your vacation entitlement is calculated')); ?>
            </h2>
            <button type="button"
                    id="entitlement-explain-dismiss"
                    class="entitlement-explain-dialog__dismiss btn btn--tertiary btn--icon"
                    aria-label="<?php p($l->t('Close')); ?>">
                <span class="entitlement-explain-dialog__dismiss-icon" aria-hidden="true">&times;</span>
            </button>
        </header>
        <p id="entitlement-explain-intro" class="entitlement-explain-dialog__intro">
            <?php p($l->t('Your entitlement is resolved through a precedence chain. The first matching layer wins. Internal IDs, descriptions, and other employees’ policy names are hidden.')); ?>
        </p>
        <div id="entitlement-explain-body" class="entitlement-explain-dialog__body" aria-live="polite" aria-busy="false"></div>
        <div class="entitlement-explain-dialog__actions">
            <button type="button" id="entitlement-explain-retry" class="btn btn--secondary" hidden>
                <?php p($l->t('Try again')); ?>
            </button>
            <form method="dialog" class="entitlement-explain-dialog__close-form">
                <button type="submit" id="entitlement-explain-close" class="btn btn--primary">
                    <?php p($l->t('Close')); ?>
                </button>
            </form>
        </div>
    </div>
</dialog>
<script id="entitlement-explainer-bootstrap" type="application/json" nonce="<?php p($_['cspNonce'] ?? ''); ?>"><?php
	echo json_encode([
		'traceUrl' => $entitlementTraceUrl,
		'vacationUnit' => $isVacationHours ? 'hours' : 'days',
		'individualRule' => $l->t('Individual rule'),
		'teamPolicy' => $l->t('Team policy'),
		'workingTimeModel' => $l->t('Working time model'),
		'organisationDefault' => $l->t('Organisation default'),
		'defaultFallback' => $l->t('Default fallback'),
		'applied' => $l->t('Applied'),
		'skipped' => $l->t('Skipped'),
		'partialHistoryHint' => $l->t('(team membership for past dates is best-effort)'),
		'degradedBanner' => $l->t('Your entitlement was resolved with a safety default. Please contact your HR administrator if this looks wrong.'),
		'clampedBanner' => $isVacationHours
			? $l->t('Your computed entitlement was outside the allowed 0–4000 hour range and has been adjusted. Please contact HR if you expected a different value.')
			: $l->t('Your computed entitlement was outside the allowed 0–366 day range and has been adjusted. Please contact HR if you expected a different value.'),
		'summaryTitle' => $l->t('Your annual vacation entitlement'),
		'daysPerYear' => $isVacationHours ? $l->t('hours per year') : $l->t('days per year'),
		'asOfDate' => $l->t('Calculated as of %s', ['%s']),
		'prorationNoteTwelfths' => $isVacationHours
			? $l->t('Reduced for your employment period this year: {prorated} of {full} hours (employed {months} of 12 months).')
			: $l->t('Reduced for your employment period this year: {prorated} of {full} days (employed {months} of 12 months).'),
		'prorationNoteDaily' => $isVacationHours
			? $l->t('Reduced for your employment period this year: {prorated} of {full} hours.')
			: $l->t('Reduced for your employment period this year: {prorated} of {full} days.'),
		'prorationNoteNone' => $l->t('You were not employed during this calendar year, so no vacation is granted for it.'),
		'chainTitle' => $l->t('How the system checked each layer'),
		'layerDeterminedLead' => $l->t('Determined by:'),
		'hintContactHr' => $l->t('If you think the result is wrong, please contact your HR administrator.'),
		'loading' => $l->t('Loading explanation…'),
		'loadError' => $l->t('Could not load explanation. Please try again later.'),
		'closeLabel' => $l->t('Close'),
	], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?></script>

<?php include __DIR__ . '/common/main-ui-l10n.php'; ?>

<!-- Initialize JavaScript -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.page = 'absences';
    window.ArbeitszeitCheck.mode = <?php echo json_encode($mode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.currentUserId = <?php echo json_encode($currentUserId ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.usersUrl = <?php echo json_encode($usersUrl ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.colleagues = <?php echo json_encode($colleagues, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.selectedSubstituteId = <?php echo json_encode(($absence && $absence->getSubstituteUserId()) ? $absence->getSubstituteUserId() : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.requireSubstituteTypes = <?php echo json_encode($requireSubstituteTypes ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.absences = <?php echo json_encode(array_map(function($a) {
        return [
            'id' => $a->getId(),
            'type' => $a->getType(),
            'startDate' => $a->getStartDate()->format('Y-m-d'),
            'endDate' => $a->getEndDate()->format('Y-m-d'),
            'status' => $a->getStatus()
        ];
    }, $absences), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    
    window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
    window.ArbeitszeitCheck.l10n.confirmCancel = <?php echo json_encode($l->t('Are you sure you want to cancel this absence request?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.confirmCancelAbsenceTitle = <?php echo json_encode($l->t('Cancel absence request'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.entitlementExplainer = <?php echo json_encode([
    	'traceUrl' => $entitlementTraceUrl,
    	'vacationUnit' => $isVacationHours ? 'hours' : 'days',
    	'individualRule' => $l->t('Individual rule'),
    	'teamPolicy' => $l->t('Team policy'),
    	'workingTimeModel' => $l->t('Working time model'),
    	'organisationDefault' => $l->t('Organisation default'),
    	'defaultFallback' => $l->t('Default fallback'),
    	'applied' => $l->t('Applied'),
    	'skipped' => $l->t('Skipped'),
    	'partialHistoryHint' => $l->t('(team membership for past dates is best-effort)'),
    	'degradedBanner' => $l->t('Your entitlement was resolved with a safety default. Please contact your HR administrator if this looks wrong.'),
    	'clampedBanner' => $isVacationHours
    		? $l->t('Your computed entitlement was outside the allowed 0–4000 hour range and has been adjusted. Please contact HR if you expected a different value.')
    		: $l->t('Your computed entitlement was outside the allowed 0–366 day range and has been adjusted. Please contact HR if you expected a different value.'),
    	'summaryTitle' => $l->t('Your annual vacation entitlement'),
    	'daysPerYear' => $isVacationHours ? $l->t('hours per year') : $l->t('days per year'),
    	'asOfDate' => $l->t('Calculated as of %s', ['%s']),
    	'prorationNoteTwelfths' => $isVacationHours
    		? $l->t('Reduced for your employment period this year: {prorated} of {full} hours (employed {months} of 12 months).')
    		: $l->t('Reduced for your employment period this year: {prorated} of {full} days (employed {months} of 12 months).'),
    	'prorationNoteDaily' => $isVacationHours
    		? $l->t('Reduced for your employment period this year: {prorated} of {full} hours.')
    		: $l->t('Reduced for your employment period this year: {prorated} of {full} days.'),
    	'prorationNoteNone' => $l->t('You were not employed during this calendar year, so no vacation is granted for it.'),
    	'chainTitle' => $l->t('How the system checked each layer'),
    	'layerDeterminedLead' => $l->t('Determined by:'),
    	'hintContactHr' => $l->t('If you think the result is wrong, please contact your HR administrator.'),
    	'loading' => $l->t('Loading explanation…'),
    	'loadError' => $l->t('Could not load explanation. Please try again later.'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    
    window.ArbeitszeitCheck.apiUrl = {
        absences: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.absence.index'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        create: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.absence.store'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        show: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.absence.show', ['id' => '__ID__']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        edit: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.absence.edit', ['id' => '__ID__']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        update: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.absence.updatePost', ['id' => '__ID__']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        delete: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.absence.delete', ['id' => '__ID__']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };
    
    // Handle form submission for create/edit
    <?php if ($mode === 'create' || $mode === 'edit'): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('absence-form');
        const startDateInput = document.getElementById('absence-start-date');
        const endDateInput = document.getElementById('absence-end-date');

        /* Ensure calendar pickers attach (global init + form fields that load with the page). */
        (function initAbsenceFormDatepickers() {
            const dp = window.ArbeitszeitCheckDatepicker;
            if (!dp) return;
            if (typeof dp.initInRoot === 'function') {
                dp.initInRoot(form || document);
            } else if (typeof dp.initializeDatepicker === 'function') {
                [startDateInput, endDateInput].forEach(function (el) {
                    if (!el || el.dataset.datepickerInit === '1') return;
                    dp.initializeDatepicker(el, {});
                });
            }
        })();
        const typeSelect = document.getElementById('absence-type');
        const substituteSelect = document.getElementById('absence-substitute');
        const substituteLabel = document.getElementById('absence-substitute-label');
        const substituteRequiredMsg = document.getElementById('absence-substitute-required-msg');
        const requireSubstituteTypes = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.requireSubstituteTypes) || [];
        const currentUserId = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.currentUserId) || '';
        const usersUrl = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.usersUrl) || '';
        const selectedSubstituteId = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.selectedSubstituteId) || '';
        if (substituteSelect) {
            var substituteGroup = document.getElementById('absence-substitute-group');
            var emptyHint = document.getElementById('absence-substitute-empty');
            var errorHint = document.getElementById('absence-substitute-error');
            var statusEl = document.getElementById('absence-substitute-status');
            var loadingText = (window.t && window.t('arbeitszeitcheck', 'Loading team members…')) || 'Loading team members…';
            var errorText = (window.t && window.t('arbeitszeitcheck', 'Could not load team members. Please try again.')) || 'Could not load team members. Please try again.';
            var colleagues = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.colleagues) || [];

            function setSubstituteState(loading, error, empty) {
                if (substituteSelect) {
                    substituteSelect.setAttribute('aria-busy', loading ? 'true' : 'false');
                    substituteSelect.disabled = loading;
                }
                if (statusEl) {
                    statusEl.textContent = loading ? loadingText : '';
                    statusEl.hidden = !loading;
                }
                if (errorHint) {
                    errorHint.textContent = errorText;
                    errorHint.hidden = !error;
                }
                if (emptyHint) emptyHint.hidden = !(empty && !error);
            }

            function fillSubstituteOptions(users) {
                var opts = substituteSelect.querySelectorAll('option:not([value=""])');
                opts.forEach(function(o) { o.remove(); });
                var count = 0;
                (users || []).forEach(function(u) {
                    if (u.userId === currentUserId) return;
                    var opt = document.createElement('option');
                    opt.value = u.userId;
                    opt.textContent = u.displayName || u.display_name || u.userId;
                    if (u.userId === selectedSubstituteId) opt.selected = true;
                    substituteSelect.appendChild(opt);
                    count++;
                });
                if (emptyHint) emptyHint.hidden = count !== 0;
            }

            if (Array.isArray(colleagues) && colleagues.length > 0) {
                setSubstituteState(false, false, false);
                fillSubstituteOptions(colleagues);
            } else if (usersUrl) {
                setSubstituteState(true, false, false);
                var requestToken = (typeof OC !== 'undefined' && OC.requestToken) ? OC.requestToken : (document.querySelector('head') && document.querySelector('head').getAttribute('data-requesttoken')) || '';
                fetch(usersUrl, {
                    headers: { 'requesttoken': requestToken },
                    credentials: 'same-origin'
                })
                    .then(function(r) {
                        if (!r.ok) {
                            setSubstituteState(false, false, true);
                            return null;
                        }
                        return r.json().catch(function() { return null; });
                    })
                    .then(function(data) {
                        setSubstituteState(false, false, false);
                        var users = (data && Array.isArray(data.users)) ? data.users : [];
                        fillSubstituteOptions(users);
                    })
                    .catch(function() {
                        setSubstituteState(false, false, true);
                    });
            } else {
                setSubstituteState(false, false, true);
            }
        }

        function updateSubstituteRequiredState() {
            if (!typeSelect || !substituteSelect || !substituteRequiredMsg) return;
            const type = typeSelect.value || '';
            const required = requireSubstituteTypes.indexOf(type) !== -1;
            substituteSelect.setAttribute('aria-required', required ? 'true' : 'false');
            substituteSelect.required = required;
            const requiredMark = document.getElementById('absence-substitute-required-mark');
            if (requiredMark) {
                requiredMark.hidden = !required;
                requiredMark.setAttribute('aria-hidden', required ? 'false' : 'true');
            }
            if (substituteLabel) {
                substituteLabel.textContent = <?php echo json_encode($l->t('Who will cover for you?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            }
            substituteRequiredMsg.hidden = true;
        }
        if (typeSelect) {
            typeSelect.addEventListener('change', updateSubstituteRequiredState);
            updateSubstituteRequiredState();
        }

        (function initDayFractionField() {
            const group = document.getElementById('absence-day-fraction-group');
            if (!group || group.getAttribute('data-days-mode') !== '1') {
                return;
            }
            const fullRadio = document.getElementById('absence-day-fraction-full');
            const halfRadio = document.getElementById('absence-day-fraction-half');
            const previewEl = document.getElementById('absence-day-fraction-preview');
            const liveEl = document.getElementById('absence-day-fraction-live');
            const errorEl = document.getElementById('absence-day-fraction-error');
            const multiTip = document.getElementById('absence-multi-day-half-tip');
            const startEl = document.getElementById('absence-start-date');
            const endEl = document.getElementById('absence-end-date');
            const typeEl = document.getElementById('absence-type');
            const rangeAnnounce = <?php echo json_encode($l->t('Half day is only for a single day. This request will use full working days.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const previewHalf = <?php echo json_encode($l->t('This request uses 0.5 vacation day.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const previewFull = <?php echo json_encode($l->t('This request uses 1 vacation day.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            let lastAnnounced = '';

            function parseDDMMYYYY(s) {
                if (!s || !/^\d{2}\.\d{2}\.\d{4}$/.test(s)) return null;
                const p = s.split('.');
                return p[2] + '-' + p[1] + '-' + p[0];
            }

            function isSingleDay() {
                if (!startEl || !endEl) return false;
                const a = parseDDMMYYYY(String(startEl.value || '').trim());
                const b = parseDDMMYYYY(String(endEl.value || '').trim());
                return !!(a && b && a === b);
            }

            function isMultiDayRange() {
                if (!startEl || !endEl) return false;
                const a = parseDDMMYYYY(String(startEl.value || '').trim());
                const b = parseDDMMYYYY(String(endEl.value || '').trim());
                return !!(a && b && a !== b);
            }

            function isVacation() {
                return typeEl && String(typeEl.value || '') === 'vacation';
            }

            function selectedFraction() {
                if (halfRadio && halfRadio.checked) return '0.5';
                return '1';
            }

            function updatePreview() {
                if (!previewEl || group.hidden) {
                    if (previewEl) previewEl.textContent = '';
                    return;
                }
                previewEl.textContent = selectedFraction() === '0.5' ? previewHalf : previewFull;
            }

            function syncMultiTip() {
                if (!multiTip || multiTip.getAttribute('data-days-mode') !== '1') {
                    return;
                }
                multiTip.hidden = !(isVacation() && isMultiDayRange());
            }

            function syncVisibility() {
                const show = isVacation() && isSingleDay();
                const wasVisible = !group.hidden;
                group.hidden = !show;
                if (!show) {
                    if (fullRadio) fullRadio.checked = true;
                    if (halfRadio) halfRadio.checked = false;
                    if (wasVisible && liveEl && lastAnnounced !== rangeAnnounce) {
                        liveEl.textContent = rangeAnnounce;
                        lastAnnounced = rangeAnnounce;
                    }
                    if (errorEl) {
                        errorEl.hidden = true;
                        errorEl.textContent = '';
                    }
                    group.removeAttribute('aria-invalid');
                } else {
                    lastAnnounced = '';
                    if (liveEl) liveEl.textContent = '';
                }
                updatePreview();
                syncMultiTip();
            }

            if (fullRadio) fullRadio.addEventListener('change', updatePreview);
            if (halfRadio) halfRadio.addEventListener('change', updatePreview);
            if (typeEl) typeEl.addEventListener('change', syncVisibility);
            if (startEl) {
                startEl.addEventListener('change', syncVisibility);
                startEl.addEventListener('input', syncVisibility);
            }
            if (endEl) {
                endEl.addEventListener('change', syncVisibility);
                endEl.addEventListener('input', syncVisibility);
            }
            syncVisibility();

            window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
            window.ArbeitszeitCheck.getAbsenceDayFraction = function () {
                if (group.hidden) return null;
                return selectedFraction();
            };
            window.ArbeitszeitCheck.showDayFractionError = function (msg) {
                if (!errorEl) return;
                errorEl.textContent = msg || '';
                errorEl.hidden = !msg;
                group.setAttribute('aria-invalid', msg ? 'true' : 'false');
                if (msg && fullRadio) fullRadio.focus();
            };
        })();

        (function initVacationHoursField() {
            const hoursGroup = document.getElementById('absence-duration-hours-group');
            const hoursInput = document.getElementById('absence-duration-hours');
            const requireInput = document.getElementById('absence-require-duration-hours');
            const previewEl = document.getElementById('absence-duration-hours-preview');
            if (!hoursGroup || !hoursInput) {
                return;
            }
            const hoursMode = hoursGroup.getAttribute('data-hours-mode') === '1';
            if (!hoursMode) {
                return;
            }
            const orgHoursPerDay = Math.max(0.25, parseFloat(hoursGroup.getAttribute('data-hours-per-day') || '8') || 8);
            let oneDayHours = Math.max(0.25, parseFloat(hoursGroup.getAttribute('data-one-day-hours') || String(orgHoursPerDay)) || orgHoursPerDay);
            let averageDaily = Math.max(0.25, parseFloat(hoursGroup.getAttribute('data-average-daily') || String(oneDayHours)) || oneDayHours);
            let weekdayNets = null;
            try {
                weekdayNets = JSON.parse(hoursGroup.getAttribute('data-weekday-nets') || 'null');
            } catch (e) {
                weekdayNets = null;
            }
            const estimateUrl = hoursGroup.getAttribute('data-estimate-url') || '';
            let userTouchedHours = false;
            let lastAutoFill = null;
            let estimateTimer = null;
            let estimateSeq = 0;
            let estimateAbort = null;

            const DOW_TO_NET = { 1: 'mon', 2: 'tue', 3: 'wed', 4: 'thu', 5: 'fri', 6: 'sat', 0: 'sun' };

            function parseDDMMYYYYLocal(s) {
                if (!s || !/^\d{2}\.\d{2}\.\d{4}$/.test(s)) return null;
                const p = s.split('.');
                return new Date(parseInt(p[2], 10), parseInt(p[1], 10) - 1, parseInt(p[0], 10));
            }

            function toYmd(d) {
                if (!d) return '';
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return y + '-' + m + '-' + day;
            }

            /** Local estimate from weekday nets (holiday-aware on server). */
            function countWeekdays(start, end) {
                if (!start || !end || end < start) {
                    return 0;
                }
                let n = 0;
                const cur = new Date(start.getFullYear(), start.getMonth(), start.getDate());
                const last = new Date(end.getFullYear(), end.getMonth(), end.getDate());
                while (cur <= last) {
                    const dow = cur.getDay();
                    if (dow !== 0 && dow !== 6) {
                        n += 1;
                    }
                    cur.setDate(cur.getDate() + 1);
                }
                return n;
            }

            function netForDate(d) {
                if (!weekdayNets || typeof weekdayNets !== 'object') {
                    return averageDaily;
                }
                const key = DOW_TO_NET[d.getDay()];
                const v = Number(weekdayNets[key]);
                return Number.isFinite(v) && v > 0 ? v : 0;
            }

            function rangeHoursEstimateLocal() {
                const start = parseDDMMYYYYLocal(startDateInput && startDateInput.value);
                const end = parseDDMMYYYYLocal(endDateInput && endDateInput.value);
                if (!start || !end || end < start) {
                    return 0;
                }
                if (weekdayNets && typeof weekdayNets === 'object') {
                    let sum = 0;
                    const cur = new Date(start.getFullYear(), start.getMonth(), start.getDate());
                    const last = new Date(end.getFullYear(), end.getMonth(), end.getDate());
                    while (cur <= last) {
                        sum += netForDate(cur);
                        cur.setDate(cur.getDate() + 1);
                    }
                    if (sum > 0.009) {
                        return Math.round(sum * 100) / 100;
                    }
                    return 0;
                }
                const days = countWeekdays(start, end);
                if (days < 1) {
                    return 0;
                }
                return Math.round(days * averageDaily * 100) / 100;
            }

            function fullDayHoursForStart() {
                const start = parseDDMMYYYYLocal(startDateInput && startDateInput.value);
                if (!start) {
                    return 0;
                }
                if (weekdayNets) {
                    const n = netForDate(start);
                    if (n >= 0.25) {
                        return Math.round(n * 100) / 100;
                    }
                    return 0;
                }
                const dow = start.getDay();
                if (dow === 0 || dow === 6) {
                    return 0;
                }
                return oneDayHours;
            }

            function updatePreview() {
                if (!previewEl) {
                    return;
                }
                const isVacation = typeSelect && typeSelect.value === 'vacation';
                if (!isVacation) {
                    previewEl.hidden = true;
                    previewEl.textContent = '';
                    return;
                }
                const raw = parseFloat(String(hoursInput.value || '').replace(',', '.'));
                if (!isFinite(raw) || raw <= 0) {
                    previewEl.hidden = true;
                    previewEl.textContent = '';
                    return;
                }
                const start = parseDDMMYYYYLocal(startDateInput && startDateInput.value);
                const end = parseDDMMYYYYLocal(endDateInput && endDateInput.value);
                const weekdays = countWeekdays(start, end);
                const factorLabel = weekdayNets
                    ? <?php echo json_encode($l->t('per your work schedule'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                    : (String(averageDaily) + ' h/day');
                const tpl = <?php echo json_encode(TemplateL10n::translate($l, 'This request: %s hours across about %s weekdays (%s). Public holidays reduce the final debit on the server.'), TemplateL10n::JSON_ENCODE_FLAGS); ?>;
                previewEl.textContent = tpl
                    .replace('%s', String(raw))
                    .replace('%s', String(weekdays || 1))
                    .replace('%s', factorLabel);
                previewEl.hidden = false;
            }

            function applyEstimate(estimate) {
                const current = String(hoursInput.value || '').trim();
                const matchesLastAuto = lastAutoFill !== null && current === String(lastAutoFill);
                if (!userTouchedHours || current === '' || matchesLastAuto || current === String(oneDayHours) || current === String(orgHoursPerDay)) {
                    hoursInput.value = String(estimate);
                    lastAutoFill = estimate;
                    userTouchedHours = false;
                }
                updatePreview();
            }

            function applyAutoRangeIfNeeded() {
                const isVacation = typeSelect && typeSelect.value === 'vacation';
                if (!isVacation) {
                    return;
                }
                const localEstimate = rangeHoursEstimateLocal();
                applyEstimate(localEstimate);
                oneDayHours = fullDayHoursForStart();

                if (!estimateUrl || !startDateInput || !endDateInput) {
                    return;
                }
                const start = parseDDMMYYYYLocal(startDateInput.value);
                const end = parseDDMMYYYYLocal(endDateInput.value);
                if (!start || !end) {
                    return;
                }
                if (estimateTimer) {
                    clearTimeout(estimateTimer);
                }
                estimateTimer = setTimeout(function () {
                    if (estimateAbort) {
                        try { estimateAbort.abort(); } catch (e) { /* ignore */ }
                    }
                    const seq = ++estimateSeq;
                    const controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
                    estimateAbort = controller;
                    const qs = 'start_date=' + encodeURIComponent(toYmd(start))
                        + '&end_date=' + encodeURIComponent(toYmd(end));
                    const token = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.getRequestToken && window.ArbeitszeitCheck.getRequestToken())
                        || (typeof OC !== 'undefined' && OC.requestToken) || '';
                    fetch(estimateUrl + (estimateUrl.indexOf('?') >= 0 ? '&' : '?') + qs, {
                        credentials: 'same-origin',
                        headers: token ? { requesttoken: token } : {},
                        signal: controller ? controller.signal : undefined
                    }).then(function (r) { return r.json(); }).then(function (j) {
                        if (seq !== estimateSeq) {
                            return;
                        }
                        if (!j || !j.success || !isFinite(j.hours) || j.hours < 0) {
                            return;
                        }
                        if (j.one_day_hours && isFinite(j.one_day_hours)) {
                            oneDayHours = Math.max(0.25, Number(j.one_day_hours));
                        }
                        if (j.average_daily && isFinite(j.average_daily)) {
                            averageDaily = Math.max(0.25, Number(j.average_daily));
                        }
                        if (j.weekday_nets && typeof j.weekday_nets === 'object') {
                            weekdayNets = j.weekday_nets;
                        }
                        applyEstimate(Math.round(Number(j.hours) * 100) / 100);
                    }).catch(function () { /* keep local estimate / abort */ });
                }, 200);
            }

            function syncHoursVisibility() {
                const isVacation = typeSelect && typeSelect.value === 'vacation';
                hoursGroup.hidden = !isVacation;
                hoursInput.disabled = !isVacation;
                if (requireInput) {
                    requireInput.disabled = !isVacation;
                }
                if (isVacation) {
                    hoursInput.setAttribute('required', 'required');
                    hoursInput.setAttribute('aria-required', 'true');
                    applyAutoRangeIfNeeded();
                } else {
                    hoursInput.removeAttribute('required');
                    hoursInput.setAttribute('aria-required', 'false');
                    hoursInput.value = '';
                    userTouchedHours = false;
                    lastAutoFill = null;
                    updatePreview();
                }
            }

            hoursInput.addEventListener('input', function () {
                userTouchedHours = true;
                updatePreview();
            });

            hoursGroup.querySelectorAll('.absence-hours-preset').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    let h = parseFloat(btn.getAttribute('data-hours') || '');
                    if (btn.hasAttribute('data-hours-half')) {
                        h = Math.round((fullDayHoursForStart() / 2) * 100) / 100;
                        userTouchedHours = true;
                    } else if (btn.hasAttribute('data-hours-full')) {
                        h = fullDayHoursForStart();
                        userTouchedHours = true;
                    } else if (btn.hasAttribute('data-hours-range')) {
                        h = rangeHoursEstimateLocal();
                        userTouchedHours = false;
                        lastAutoFill = h;
                        applyAutoRangeIfNeeded();
                    } else {
                        userTouchedHours = true;
                    }
                    if (!isFinite(h) || h <= 0) {
                        return;
                    }
                    hoursInput.value = String(h);
                    hoursInput.dispatchEvent(new Event('input', { bubbles: true }));
                    updatePreview();
                    hoursInput.focus();
                });
            });

            if (typeSelect) {
                typeSelect.addEventListener('change', syncHoursVisibility);
            }
            if (startDateInput) {
                startDateInput.addEventListener('change', applyAutoRangeIfNeeded);
                startDateInput.addEventListener('blur', applyAutoRangeIfNeeded);
            }
            if (endDateInput) {
                endDateInput.addEventListener('change', applyAutoRangeIfNeeded);
                endDateInput.addEventListener('blur', applyAutoRangeIfNeeded);
            }
            syncHoursVisibility();
        })();

        // Validate end date is not before start date
        function parseDDMMYYYY(s) {
            if (!s || !/^\d{2}\.\d{2}\.\d{4}$/.test(s)) return null;
            const p = s.split('.');
            return new Date(parseInt(p[2],10), parseInt(p[1],10)-1, parseInt(p[0],10));
        }
        function validateDates() {
            if (startDateInput.value && endDateInput.value) {
                const start = parseDDMMYYYY(startDateInput.value);
                const end = parseDDMMYYYY(endDateInput.value);
                if (start && end && end < start) {
                    endDateInput.setCustomValidity('<?php echo addslashes($l->t('End date cannot be before start date')); ?>');
                    return false;
                } else {
                    endDateInput.setCustomValidity('');
                }
            }
            return true;
        }

        /* Past-date awareness for the request form.
         *
         * When both dates have been entered AND the end date lies strictly
         * before today (in the user's local timezone, matching the visible
         * datepicker), we surface a clearly worded "historical entry" hint
         * and adjust adjacent affordances (substitute selection is meaningless
         * for past dates). The hint exists in the markup with aria-live so
         * the layout never jumps and screen readers announce the change.
         */
        const historicalHint = document.getElementById('absence-historical-hint');
        const historicalAutoText = document.getElementById('absence-historical-hint-auto-text');
        const historicalDefaultText = document.getElementById('absence-historical-hint-default-text');
        const substituteGroupEl = document.getElementById('absence-substitute-group');
        const substituteSelectEl = document.getElementById('absence-substitute');
        const substituteHelpEl = document.getElementById('absence-substitute-help');
        const employeeHasAssignableManagerForJs = <?php echo $employeeHasAssignableManager ? 'true' : 'false'; ?>;
        const useAppTeamsForJs = <?php echo $useAppTeams ? 'true' : 'false'; ?>;
        const willAutoApprovePastEntry = useAppTeamsForJs && !employeeHasAssignableManagerForJs;
        const substituteHelpDefaultText = substituteHelpEl ? substituteHelpEl.textContent : '';
        const substituteHelpHistoricalText = (window.t && window.t('arbeitszeitcheck', 'Substitute selection is disabled for past dates – the workflow only applies to upcoming absences.')) || 'Substitute selection is disabled for past dates – the workflow only applies to upcoming absences.';

        function updateHistoricalState() {
            if (!historicalHint || !startDateInput || !endDateInput) return;
            const end = parseDDMMYYYY(endDateInput.value);
            if (!end) {
                historicalHint.hidden = true;
                if (substituteGroupEl) {
                    substituteGroupEl.classList.remove('absence-form-fieldset--disabled');
                }
                if (substituteSelectEl) {
                    substituteSelectEl.disabled = false;
                    substituteSelectEl.removeAttribute('aria-disabled');
                }
                if (substituteHelpEl) substituteHelpEl.textContent = substituteHelpDefaultText;
                return;
            }
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const isPast = end < today;
            historicalHint.hidden = !isPast;
            if (historicalAutoText && historicalDefaultText) {
                historicalAutoText.hidden = !(isPast && willAutoApprovePastEntry);
                historicalDefaultText.hidden = !(isPast && !willAutoApprovePastEntry);
            }
            /* For past dates, the substitute workflow is not meaningful – nobody
             * can cover a shift that already happened. Disable the select and
             * explain it in form-help so the user is not confused. The required
             * flag is also dropped so type-based requirements (vacation etc.)
             * do not block historical submission. */
            if (substituteSelectEl) {
                if (isPast) {
                    substituteSelectEl.value = '';
                    substituteSelectEl.disabled = true;
                    substituteSelectEl.setAttribute('aria-disabled', 'true');
                    substituteSelectEl.required = false;
                    substituteSelectEl.setAttribute('aria-required', 'false');
                } else {
                    substituteSelectEl.disabled = false;
                    substituteSelectEl.removeAttribute('aria-disabled');
                    if (typeof updateSubstituteRequiredState === 'function') {
                        updateSubstituteRequiredState();
                    }
                }
            }
            if (substituteGroupEl) {
                substituteGroupEl.classList.toggle('absence-form-fieldset--disabled', isPast);
            }
            if (substituteHelpEl) {
                substituteHelpEl.textContent = isPast ? substituteHelpHistoricalText : substituteHelpDefaultText;
            }
        }

        if (startDateInput) {
            startDateInput.addEventListener('change', function() {
                if (!endDateInput.value && startDateInput.value && /^\d{2}\.\d{2}\.\d{4}$/.test(startDateInput.value)) {
                    endDateInput.value = startDateInput.value;
                    endDateInput.dispatchEvent(new Event('change', { bubbles: true }));
                } else if (endDateInput.value) {
                    validateDates();
                }
                updateHistoricalState();
            });
        }

        if (endDateInput) {
            endDateInput.addEventListener('change', function() {
                if (!startDateInput.value && endDateInput.value && /^\d{2}\.\d{2}\.\d{4}$/.test(endDateInput.value)) {
                    startDateInput.value = endDateInput.value;
                    startDateInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
                validateDates();
                updateHistoricalState();
            });
        }

        /* Run once on load so prefilled values (e.g. coming from the calendar
         * "Request absence for this day" link with a past date) immediately
         * show the historical hint. */
        updateHistoricalState();
        
        function hideFormError() {
            var errEl = document.getElementById('absence-form-error');
            if (errEl) { errEl.hidden = true; }
        }
        function showFormError(message) {
            var errEl = document.getElementById('absence-form-error');
            var errText = document.getElementById('absence-form-error-text');
            if (errText && message) { errText.textContent = message; }
            if (errEl) {
                errEl.hidden = false;
                /* Make the callout focusable so we can move keyboard focus to it
                 * (WCAG 2.1 SC 3.3.1: errors must be programmatically associated
                 * with the field that triggered them; for cross-field errors a
                 * focused alert region is the recommended fallback). */
                if (!errEl.hasAttribute('tabindex')) {
                    errEl.setAttribute('tabindex', '-1');
                }
                try { errEl.focus({ preventScroll: false }); } catch (e) { errEl.focus(); }
                if (errEl.scrollIntoView) {
                    errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        }
        if (typeSelect) typeSelect.addEventListener('change', hideFormError);
        if (startDateInput) startDateInput.addEventListener('input', hideFormError);
        if (endDateInput) endDateInput.addEventListener('input', hideFormError);

        /* When the page boots and a server-rendered error is already visible
         * (e.g. coming back from a failed no-JS POST via ?error=…), move focus
         * there so keyboard / screen-reader users land on the explanation. */
        (function() {
            var errEl = document.getElementById('absence-form-error');
            if (errEl && !errEl.hidden) {
                if (!errEl.hasAttribute('tabindex')) {
                    errEl.setAttribute('tabindex', '-1');
                }
                try { errEl.focus({ preventScroll: false }); } catch (e) { errEl.focus(); }
            }
        })();

        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                hideFormError();
                if (!validateDates()) {
                    return;
                }
                var type = typeSelect ? typeSelect.value : '';
                /* For historical entries (end date strictly before today) we do
                 * not enforce the substitute requirement, because Vertretung is
                 * meaningless once the day is over. The same rule applies on
                 * the backend (createAbsence / createApprovedAbsenceForEmployeeByManager). */
                var subSubmitEnd = parseDDMMYYYY(endDateInput ? endDateInput.value : '');
                var subSubmitToday = new Date();
                subSubmitToday.setHours(0, 0, 0, 0);
                var subSubmitIsPast = subSubmitEnd ? (subSubmitEnd < subSubmitToday) : false;
                var subRequired = !subSubmitIsPast && (requireSubstituteTypes.indexOf(type) !== -1);
                if (subRequired && substituteSelect && (!substituteSelect.value || substituteSelect.value === '')) {
                    if (substituteRequiredMsg) substituteRequiredMsg.hidden = false;
                    substituteSelect.setAttribute('aria-invalid', 'true');
                    substituteSelect.focus();
                    return;
                }
                if (substituteSelect) substituteSelect.setAttribute('aria-invalid', 'false');
                if (substituteRequiredMsg) substituteRequiredMsg.hidden = true;
                
                const formData = new FormData(form);
                const dp = window.ArbeitszeitCheckDatepicker;
                const toISO = dp ? dp.convertEuropeanToISO : function(s) { return s; };
                const startDate = toISO(formData.get('start_date') || '');
                const endDate = toISO(formData.get('end_date') || '');
                const reason = formData.get('reason') || '';

                const url = <?php echo $mode === 'create'
                    ? json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.absence.store'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                    : json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.absence.updatePost', ['id' => $absence->getId()]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>;
                const listUrl = <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.page.absences'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                const isCreate = <?php echo $mode === 'create' ? 'true' : 'false'; ?>;

                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn ? submitBtn.textContent : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = (window.t && window.t('arbeitszeitcheck', 'Submitting...')) || 'Submitting...';
                }

                // Submit as form-urlencoded so backend returns redirect (303), never JSON – user never sees raw JSON
                const body = new URLSearchParams();
                body.set('type', formData.get('type') || '');
                body.set('start_date', startDate);
                body.set('end_date', endDate);
                if (reason) body.set('reason', reason);
                body.set('substitute_user_id', substituteSelect ? substituteSelect.value : '');
                const durationHoursEl = document.getElementById('absence-duration-hours');
                const requireHoursEl = document.getElementById('absence-require-duration-hours');
                if (durationHoursEl && !durationHoursEl.disabled && durationHoursEl.value !== '') {
                    body.set('duration_hours', String(durationHoursEl.value).replace(',', '.'));
                }
                if (requireHoursEl && !requireHoursEl.disabled) {
                    body.set('require_duration_hours', '1');
                    // Web may fill from working days if the hours field is empty (backup path).
                    body.set('server_may_fill_hours', '1');
                }
                if (window.ArbeitszeitCheck && typeof window.ArbeitszeitCheck.getAbsenceDayFraction === 'function') {
                    const dayFraction = window.ArbeitszeitCheck.getAbsenceDayFraction();
                    if (dayFraction === '0.5' || dayFraction === '1') {
                        body.set('day_fraction', dayFraction);
                    }
                }
                const submittedDayFraction = body.get('day_fraction');
                const requestToken = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.getRequestToken && window.ArbeitszeitCheck.getRequestToken()) || (typeof OC !== 'undefined' && OC.requestToken) || '';
                if (requestToken) body.set('requesttoken', requestToken);

                fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'requesttoken': requestToken },
                    body: body.toString(),
                    redirect: 'follow',
                    credentials: 'same-origin'
                })
                    .then(function(response) {
                        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalText; }
                        if (response.redirected || response.ok) {
                            const successMsg = isCreate
                                ? (submittedDayFraction === '0.5'
                                    ? ((window.t && window.t('arbeitszeitcheck', 'Half-day vacation request submitted successfully')) || 'Half-day vacation request submitted successfully')
                                    : ((window.t && window.t('arbeitszeitcheck', 'Absence request submitted successfully')) || 'Absence request submitted successfully'))
                                : ((window.t && window.t('arbeitszeitcheck', 'Absence request updated')) || 'Absence request updated');
                            if (window.AzcMessaging && typeof window.AzcMessaging.showSuccess === 'function') {
                                window.AzcMessaging.showSuccess(successMsg);
                            } else if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                                window.OC.Notification.showTemporary(successMsg, { type: 'success' });
                            } else {
                                const live = document.getElementById('azc-live-region');
                                if (live) { live.textContent = successMsg; }
                            }
                            window.location.href = response.redirected ? response.url : listUrl;
                            return;
                        }
                        return response.text().then(function(text) {
                            let errMsg = (window.t && window.t('arbeitszeitcheck', 'Failed to submit absence request')) || 'Failed to submit absence request';
                            let errCode = '';
                            try {
                                const j = JSON.parse(text);
                                if (j && typeof j.error === 'string' && j.error) errMsg = j.error;
                                if (j && (j.code || j.error_code)) errCode = String(j.code || j.error_code);
                            } catch (e) { /* ignore */ }
                            if (errCode.indexOf('VAC_HALF_DAY_') === 0 && window.ArbeitszeitCheck && typeof window.ArbeitszeitCheck.showDayFractionError === 'function') {
                                window.ArbeitszeitCheck.showDayFractionError(errMsg);
                            }
                            showFormError(errMsg);
                            if (window.AzcMessaging && typeof window.AzcMessaging.showError === 'function') {
                                window.AzcMessaging.showError(errMsg);
                            } else if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                                window.OC.Notification.showTemporary(errMsg, { type: 'error', timeout: 8000 });
                            } else {
                                const alertRegion = document.getElementById('azc-alert-region');
                                if (alertRegion) { alertRegion.textContent = errMsg; }
                            }
                        });
                    })
                    .catch(function(err) {
                        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalText; }
                        const errMsg = (err && err.message) || (window.t && window.t('arbeitszeitcheck', 'Failed to submit absence request')) || 'Failed to submit absence request';
                        showFormError(errMsg);
                        if (window.AzcMessaging && typeof window.AzcMessaging.showError === 'function') {
                            window.AzcMessaging.showError(errMsg);
                        } else if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                            window.OC.Notification.showTemporary(errMsg, { type: 'error', timeout: 8000 });
                        } else {
                            const alertRegion = document.getElementById('azc-alert-region');
                            if (alertRegion) { alertRegion.textContent = errMsg; }
                        }
                    });
            });
        }
    });
    <?php endif; ?>

    // ===== REASON EXPAND/COLLAPSE =====
    document.querySelectorAll('.reason-truncated__toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const container = btn.closest('.reason-truncated');
            if (!container) return;
            const short = container.querySelector('.reason-truncated__short');
            const full  = container.querySelector('.reason-truncated__full');
            const expanded = container.getAttribute('aria-expanded') === 'true';
            container.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            if (short) short.hidden = !expanded;
            if (full)  full.hidden  =  expanded;
            btn.textContent = expanded
                ? (window.t ? window.t('arbeitszeitcheck', 'more') : 'more')
                : (window.t ? window.t('arbeitszeitcheck', 'less') : 'less');
            btn.setAttribute('aria-label', expanded
                ? (window.t ? window.t('arbeitszeitcheck', 'Show full reason') : 'Show full reason')
                : (window.t ? window.t('arbeitszeitcheck', 'Show less') : 'Show less'));
        });
    });

    // ===== ACCESSIBLE CONFIRM DIALOGS =====
    // Replace native confirm() on any .js-confirm-submit button.
    // Uses ArbeitszeitCheckComponents.showConfirmDialog which returns a Promise.
    document.querySelectorAll('.js-confirm-submit').forEach(function(btn) {
        btn.addEventListener('click', async function(e) {
            e.preventDefault();
            const form = btn.closest('form');
            if (!form) return;

            const comp = window.AzcComponents || window.ArbeitszeitCheckComponents;
            const confirmFn = comp && (
                typeof comp.confirmDialog === 'function'
                    ? comp.confirmDialog
                    : typeof comp.showConfirmDialog === 'function'
                        ? comp.showConfirmDialog
                        : null
            );
            if (!confirmFn) {
                return;
            }

            const confirmed = await confirmFn.call(comp, {
                title:        btn.dataset.confirmTitle   || '',
                message:      btn.dataset.confirmMessage || '',
                variant:      btn.dataset.confirmVariant || 'info',
                confirmLabel: btn.dataset.confirmLabel   || undefined,
            });

            if (confirmed) {
                form.submit();
            }
        });
    });

</script>
