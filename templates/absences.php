<?php
declare(strict_types=1);

/**
 * Absences template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCP\Util;

/** @var array $_ */
/** @var \OCP\IL10N $l */

// Add common + page-specific styles and scripts
Util::addTranslations('arbeitszeitcheck');
Util::addStyle('arbeitszeitcheck', 'common/colors');
Util::addStyle('arbeitszeitcheck', 'common/typography');
Util::addStyle('arbeitszeitcheck', 'common/base');
Util::addStyle('arbeitszeitcheck', 'common/components');
Util::addStyle('arbeitszeitcheck', 'common/layout');
Util::addStyle('arbeitszeitcheck', 'common/app-layout');
Util::addStyle('arbeitszeitcheck', 'common/utilities');
Util::addStyle('arbeitszeitcheck', 'common/responsive');
Util::addStyle('arbeitszeitcheck', 'common/accessibility');
Util::addStyle('arbeitszeitcheck', 'navigation');
Util::addStyle('arbeitszeitcheck', 'absences');
Util::addScript('arbeitszeitcheck', 'common/utils');
Util::addScript('arbeitszeitcheck', 'common/datepicker');
Util::addScript('arbeitszeitcheck', 'arbeitszeitcheck-main');

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
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper" role="main" aria-label="<?php p($l->t('Absences')); ?>">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb-container">
            <nav class="breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
                <ol>
                    <li><a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"><?php p($l->t('Dashboard')); ?></a></li>
                    <li><a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.absences')); ?>"><?php p($l->t('Absences')); ?></a></li>
                    <?php if ($mode === 'view' && $absence): ?>
                    <li aria-current="page"><?php p($l->t('Absence details')); ?></li>
                    <?php elseif ($mode === 'create'): ?>
                    <li aria-current="page"><?php p($l->t('Request Time Off')); ?></li>
                    <?php elseif ($mode === 'edit' && $absence): ?>
                    <li aria-current="page"><?php p($l->t('Edit Absence Request')); ?></li>
                    <?php else: ?>
                    <li aria-current="page"><?php p($l->t('Absences')); ?></li>
                    <?php endif; ?>
                </ol>
            </nav>
        </div>

        <!-- Page Header -->
        <header class="section page-header-section" aria-labelledby="page-title">
            <div class="header-content">
                <div class="header-text">
                    <h2 id="page-title" class="page-title"><?php 
                        if ($mode === 'create') {
                            p($l->t('Request Time Off'));
                        } elseif ($mode === 'edit') {
                            p($l->t('Edit Absence Request'));
                        } elseif ($mode === 'view') {
                            p($l->t('Absence Details'));
                        } else {
                            p($l->t('Absences'));
                        }
                    ?></h2>
                    <p><?php 
                        if ($mode === 'create') {
                            p($l->t('Request a new absence. Your manager will review and approve or reject your request.'));
                        } elseif ($mode === 'edit') {
                            p($l->t('Edit your absence request. You can only edit pending requests.'));
                        } elseif ($mode === 'view') {
                            p($l->t('See all important details for this absence in one simple overview.'));
                        } else {
                            p($l->t('Manage vacation, sick leave, and other absences'));
                        }
                    ?></p>
                </div>
                <?php if ($mode === 'list'): ?>
                <div class="header-actions">
                    <button id="btn-request-absence" 
                            class="btn btn--primary" 
                            type="button"
                            aria-label="<?php p($l->t('Request time off for vacation or sick leave')); ?>"
                            title="<?php p($l->t('Click to request time off. You can request vacation days, sick leave, or other types of absences.')); ?>">
                        <?php p($l->t('Request Time Off')); ?>
                    </button>
                    <button id="btn-filter" 
                            class="btn btn--secondary" 
                            type="button"
                            aria-label="<?php p($l->t('Filter absence requests by date or status')); ?>"
                            title="<?php p($l->t('Click to show options for filtering your absence requests. You can filter by date range or approval status.')); ?>">
                        <?php p($l->t('Filter')); ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($mode === 'list'): ?>
            <!-- Filter section (hidden by default, toggled by Filter button) -->
            <section id="filter-section" class="section section--filter" aria-labelledby="filter-title" style="display: none;">
                <h3 id="filter-title" class="section__title visually-hidden"><?php p($l->t('Filter absence requests')); ?></h3>
                <?php
                        $filterStartDate = $_['filterStartDate'] ?? '';
                        $filterEndDate = $_['filterEndDate'] ?? '';
                        $filterStatus = $_['filterStatus'] ?? '';
                        ?>
                <div class="form form--inline">
                    <div class="form-group">
                        <label for="filter-start-date" class="form-label"><?php p($l->t('Start Date')); ?></label>
                        <input type="text" id="filter-start-date" class="form-input datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" value="<?php p($filterStartDate); ?>" data-datepicker-min="">
                    </div>
                    <div class="form-group">
                        <label for="filter-end-date" class="form-label"><?php p($l->t('End Date')); ?></label>
                        <input type="text" id="filter-end-date" class="form-input datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" value="<?php p($filterEndDate); ?>" data-datepicker-min="">
                    </div>
                    <div class="form-group">
                        <label for="filter-status" class="form-label"><?php p($l->t('Status')); ?></label>
                        <select id="filter-status" class="form-select">
                            <option value=""><?php p($l->t('All')); ?></option>
                            <option value="pending" <?php echo ($filterStatus === 'pending') ? 'selected' : ''; ?>><?php p($l->t('Pending')); ?></option>
                            <option value="approved" <?php echo ($filterStatus === 'approved') ? 'selected' : ''; ?>><?php p($l->t('Approved')); ?></option>
                            <option value="rejected" <?php echo ($filterStatus === 'rejected') ? 'selected' : ''; ?>><?php p($l->t('Rejected')); ?></option>
                            <option value="substitute_declined" <?php echo ($filterStatus === 'substitute_declined') ? 'selected' : ''; ?>><?php p($l->t('Declined by substitute')); ?></option>
                        </select>
                    </div>
                    <div class="form-group form-group--actions">
                        <button type="button" id="btn-apply-filter" class="btn btn--primary"><?php p($l->t('Apply')); ?></button>
                        <button type="button" id="btn-clear-filter" class="btn btn--secondary"><?php p($l->t('Clear')); ?></button>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($mode === 'create' || $mode === 'edit'): ?>
            <!-- Create/Edit Form -->
            <section class="section section--form" aria-labelledby="form-title" aria-describedby="form-desc">
                <h3 id="form-title" class="section__title visually-hidden"><?php p($l->t('Absence request details')); ?></h3>
                <p id="form-desc" class="section__desc visually-hidden"><?php p($l->t('Fill in the type, dates, and optional reason and substitute.')); ?></p>
                <div class="alert alert--error" role="alert" aria-live="polite" id="absence-form-error"<?php echo $error ? '' : ' style="display: none;"'; ?>>
                    <p id="absence-form-error-text"><?php echo $error ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : ''; ?></p>
                </div>
                
                <form id="absence-form" class="form" method="POST" action="<?php 
                    if ($mode === 'create') {
                        p($urlGenerator->linkToRoute('arbeitszeitcheck.absence.store'));
                    } else {
                        p($urlGenerator->linkToRoute('arbeitszeitcheck.absence.updatePost', ['id' => $absence->getId()]));
                    }
                ?>">
                    <div class="form-group">
                        <label for="absence-type" class="form-label">
                            <?php p($l->t('Type')); ?> <span class="form-required">*</span>
                        </label>
                        <select id="absence-type" name="type" class="form-select" required>
                            <option value=""><?php p($l->t('Select the type of absence you want to request')); ?></option>
                            <option value="vacation" <?php echo ($absence && $absence->getType() === 'vacation') ? 'selected' : ''; ?>>
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
                        <p class="form-help"><?php p($l->t('Select the type of absence you want to request')); ?></p>
                    </div>

                    <?php
                    $todayFormatted = (new DateTime())->format('d.m.Y');
                    $sickMinDate = (new DateTime())->modify('-7 days')->format('d.m.Y');
                    ?>
                    <div class="form-group">
                        <label for="absence-start-date" class="form-label">
                            <?php p($l->t('Start Date')); ?> <span class="form-required">*</span>
                        </label>
                        <input type="text"
                               id="absence-start-date"
                               name="start_date"
                               class="form-input datepicker-input"
                               data-datepicker-min="<?php echo $todayFormatted; ?>"
                               data-datepicker-min-sick="<?php echo $sickMinDate; ?>"
                               data-datepicker-sync-month-with="absence-end-date"
                               value="<?php p($absence ? $absence->getStartDate()->format('d.m.Y') : ''); ?>"
                               placeholder="<?php p($l->t('dd.mm.yyyy')); ?>"
                               pattern="\d{2}\.\d{2}\.\d{4}"
                               maxlength="10"
                               required>
                        <p class="form-help"><?php p($l->t('The first day of your absence')); ?></p>
                    </div>

                    <div class="form-group">
                        <label for="absence-end-date" class="form-label">
                            <?php p($l->t('End Date')); ?> <span class="form-required">*</span>
                        </label>
                        <input type="text"
                               id="absence-end-date"
                               name="end_date"
                               class="form-input datepicker-input"
                               data-datepicker-min="<?php echo $todayFormatted; ?>"
                               data-datepicker-min-sick="<?php echo $sickMinDate; ?>"
                               data-datepicker-sync-month-with="absence-start-date"
                               value="<?php p($absence ? $absence->getEndDate()->format('d.m.Y') : ''); ?>"
                               placeholder="<?php p($l->t('dd.mm.yyyy')); ?>"
                               pattern="\d{2}\.\d{2}\.\d{4}"
                               maxlength="10"
                               required>
                        <p class="form-help"><?php p($l->t('The last day of your absence')); ?></p>
                    </div>

                    <div class="form-group">
                        <label for="absence-reason" class="form-label">
                            <?php p($l->t('Reason')); ?>
                        </label>
                        <textarea id="absence-reason" 
                                  name="reason" 
                                  class="form-textarea" 
                                  rows="4"
                                  placeholder="<?php p($l->t('Optional reason or notes for your absence request')); ?>"><?php p($absence ? ($absence->getReason() ?? '') : ''); ?></textarea>
                        <p class="form-help"><?php p($l->t('You can provide additional information about your absence request')); ?></p>
                    </div>

                    <?php if (($_['hasColleagues'] ?? true)): ?>
                    <div class="form-group form-group--substitute" id="absence-substitute-group">
                        <label for="absence-substitute" class="form-label" id="absence-substitute-label">
                            <?php p($l->t('Substitute')); ?>
                        </label>
                        <select id="absence-substitute"
                                name="substitute_user_id"
                                class="form-select"
                                aria-describedby="absence-substitute-help"
                                aria-required="false">
                            <option value=""><?php p($l->t('None')); ?></option>
                            <!-- Options filled by JavaScript from /api/users -->
                        </select>
                        <p id="absence-substitute-help" class="form-help"><?php p($l->t('Choose a colleague from your team who will cover your tasks during your absence. Only team members appear in this list.')); ?></p>
                        <p id="absence-substitute-empty" class="form-help form-help--info" style="display: none;" role="status"><?php p($l->t('No team members found. Add yourself to a team or group to select a substitute.')); ?></p>
                        <p id="absence-substitute-required-msg" class="form-help form-help--error" style="display: none;" role="alert"><?php p($l->t('A substitute is required for this absence type. Please select who will cover for you.')); ?></p>
                    </div>
                    <?php else: ?>
                    <p class="form-help form-help--info" role="status"><?php
                        $hasRequired = !empty($requireSubstituteTypes);
                        if ($hasRequired) {
                            p($l->t('Some absence types require a substitute. Add yourself to a team to select one.'));
                        } else {
                            p($l->t('No team members in your team. You cannot select a substitute. Add yourself to a team to enable this option.'));
                        }
                    ?></p>
                    <?php endif; ?>

                    <div class="form-actions">
                        <button type="submit" class="btn btn--primary">
                            <?php p($mode === 'create' ? $l->t('Submit Request') : $l->t('Update Request')); ?>
                        </button>
                        <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.absences')); ?>" class="btn btn--secondary">
                            <?php p($l->t('Cancel')); ?>
                        </a>
                    </div>
                </form>
            </section>
        <?php elseif ($mode === 'view' && $absence): ?>
            <?php
            $start = $absence->getStartDate();
            $end = $absence->getEndDate();
            $days = $absence->getDays();
            if ($days === null) {
                $days = $_['displayDays'] ?? ($_['computedWorkingDays'][$absence->getId()] ?? $absence->calculateWorkingDays());
            }
            $today = new \DateTimeImmutable('today');
            $canCancel = $start > $today
                && !in_array($absence->getStatus(), ['cancelled', 'rejected', 'substitute_declined'], true);
            ?>
            <!-- Read-only Absence Details -->
            <section class="section section--detail absence-detail-view" aria-labelledby="detail-title">
                <h3 id="detail-title" class="section__title visually-hidden"><?php p($l->t('Absence details')); ?></h3>

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
                        <span class="badge badge--<?php
                            echo match($absence->getStatus()) {
                                'approved' => 'success',
                                'pending' => 'warning',
                                'substitute_pending' => 'warning',
                                'rejected' => 'error',
                                'substitute_declined' => 'error',
                                'cancelled' => 'secondary',
                                default => 'secondary'
                            };
                        ?>">
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
                    </div>
                    <p class="absence-detail-period" aria-label="<?php p($l->t('Period and duration')); ?>">
                        <?php p($start->format('d.m.Y')); ?><?php echo ' – '; ?><?php p($end->format('d.m.Y')); ?>
                        <span class="absence-detail-period-sep" aria-hidden="true">·</span>
                        <?php p($l->n('%n working day', '%n working days', (int)$days)); ?>
                    </p>
                </div>

                <?php if ($canCancel): ?>
                    <div class="absence-detail-actions absence-detail-actions--top">
                        <form method="POST"
                              action="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.absence.cancel', ['id' => $absence->getId()])); ?>">
                            <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">
                            <button type="submit"
                                    class="btn btn--secondary btn--danger"
                                    onclick="return confirm('<?php echo addslashes($l->t('Do you really want to cancel this absence? This cannot be undone.')); ?>');"
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
                    <h4 id="shorten-heading" class="absence-detail-section__title"><?php p($l->t('I returned early')); ?></h4>
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
                    <h4 id="absence-detail-dates-heading" class="absence-detail-section__title"><?php p($l->t('Dates and duration')); ?></h4>
                    <dl class="absence-detail-list">
                        <div class="absence-detail-row">
                            <dt class="absence-detail-label"><?php p($l->t('Period')); ?></dt>
                            <dd class="absence-detail-value"><?php p($start->format('d.m.Y')); ?> – <?php p($end->format('d.m.Y')); ?></dd>
                        </div>
                        <div class="absence-detail-row">
                            <dt class="absence-detail-label"><?php p($l->t('Working days')); ?></dt>
                            <dd class="absence-detail-value"><?php p((string)$days); ?></dd>
                        </div>
                    </dl>
                </div>

                <!-- Details: Reason, Substitute, Approval comment -->
                <div class="absence-detail-section" role="region" aria-labelledby="absence-detail-info-heading">
                    <h4 id="absence-detail-info-heading" class="absence-detail-section__title"><?php p($l->t('Details')); ?></h4>
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
                            <dt class="absence-detail-label"><?php p($l->t('Approval comment')); ?></dt>
                            <dd class="absence-detail-value"><?php
                                $comment = $absence->getApproverComment();
                                p($comment ? $l->t($comment) : $l->t('No approval comment available'));
                            ?></dd>
                        </div>
                    </dl>
                </div>

                <!-- Audit trail: Created, Last updated, Approved at -->
                <div class="absence-detail-section" role="region" aria-labelledby="absence-detail-audit-heading">
                    <h4 id="absence-detail-audit-heading" class="absence-detail-section__title"><?php p($l->t('History')); ?></h4>
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
            <section class="section section--stats" aria-labelledby="stats-title">
                <h3 id="stats-title" class="section__title stats-section-title">
                    <?php p($l->t('Vacation balance') . ' ' . (string)($stats['vacation_year'] ?? date('Y'))); ?>
                </h3>
                <p id="stats-desc" class="stats-section-desc visually-hidden">
                    <?php p($l->t('Remaining vacation days for this year. Only approved vacation (not sick leave or other absences) is deducted.')); ?>
                </p>
                <?php if (!empty($stats)): ?>
                    <div class="stats-grid" role="group" aria-labelledby="stats-title" aria-describedby="stats-desc">
                        <div class="stat-card stat-card--remaining">
                            <span class="stat-label" id="stat-remaining-label"><?php p($l->t('Remaining')); ?></span>
                            <span class="stat-value" aria-labelledby="stat-remaining-label"><?php p((string)round($stats['vacation_days_remaining'] ?? 0, 1)); ?></span>
                            <span class="stat-sublabel"><?php p($l->t('vacation days')); ?></span>
                        </div>
                        <div class="stat-card stat-card--used">
                            <span class="stat-label" id="stat-used-label"><?php p($l->t('Used this year')); ?></span>
                            <span class="stat-value stat-value--secondary" aria-labelledby="stat-used-label"><?php p((string)round($stats['vacation_days_used_this_year'] ?? 0, 1)); ?></span>
                            <span class="stat-sublabel"><?php p($l->t('vacation days')); ?></span>
                        </div>
                        <div class="stat-card stat-card--pending">
                            <span class="stat-label" id="stat-pending-label"><?php p($l->t('Pending requests')); ?></span>
                            <span class="stat-value stat-value--secondary" aria-labelledby="stat-pending-label"><?php p((string)($stats['pending_requests'] ?? 0)); ?></span>
                            <span class="stat-sublabel"><?php p($l->t('awaiting approval')); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Absences List -->
            <section class="section section--list" aria-labelledby="list-title">
                <h3 id="list-title" class="section__title visually-hidden"><?php p($l->t('Your absence requests')); ?></h3>
                <div class="table-container">
                    <table class="table table--hover absences-table" id="absences-table" role="table" aria-labelledby="list-title">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Type')); ?></th>
                                <th scope="col"><?php p($l->t('Start Date')); ?></th>
                                <th scope="col"><?php p($l->t('End Date')); ?></th>
                                <th scope="col"><?php p($l->t('Days')); ?></th>
                                <th scope="col"><?php p($l->t('Reason')); ?></th>
                                <th scope="col"><?php p($l->t('Status')); ?></th>
                                <th scope="col"><?php p($l->t('Actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($absences)): ?>
                                <?php foreach (($absences ?? []) as $absence): ?>
                                    <tr data-absence-id="<?php p($absence->getId()); ?>">
                                        <td data-label="<?php p($l->t('Type')); ?>">
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
                                        </td>
                                        <td data-label="<?php p($l->t('Start Date')); ?>"><?php p($absence->getStartDate()->format('d.m.Y')); ?></td>
                                        <td data-label="<?php p($l->t('End Date')); ?>"><?php p($absence->getEndDate()->format('d.m.Y')); ?></td>
                                        <td data-label="<?php p($l->t('Days')); ?>"><?php
                                            $d = $absence->getDays();
                                            $displayD = $d !== null ? (float)$d : (float)(($_['computedWorkingDays'] ?? [])[$absence->getId()] ?? $absence->calculateWorkingDays());
                                            p((string)round($displayD, 1));
                                        ?></td>
                                        <td class="reason-cell" data-label="<?php p($l->t('Reason')); ?>">
                                            <?php 
                                            $reason = $absence->getReason();
                                            p($reason ? substr($reason, 0, 50) : '-'); 
                                            ?>
                                            <?php if ($reason && strlen($reason) > 50): ?>
                                                <span class="reason-more">...</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="<?php p($l->t('Status')); ?>">
                                            <span class="badge badge--<?php 
                                                echo match($absence->getStatus()) {
                                                    'approved' => 'success',
                                                    'pending' => 'warning',
                                                    'substitute_pending' => 'warning',
                                                    'rejected' => 'error',
                                                    'substitute_declined' => 'error',
                                                    default => 'secondary'
                                                };
                                            ?>">
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
                                            <?php if (in_array($absence->getStatus(), ['pending', 'substitute_pending'], true)): ?>
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
                                            <button id="btn-request-first-absence"
                                                class="btn btn--primary"
                                                type="button"
                                                aria-label="<?php p($l->t('Request your first absence')); ?>">
                                                <?php p($l->t('Request Time Off')); ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

<!-- Initialize JavaScript -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.page = 'absences';
    window.ArbeitszeitCheck.mode = <?php echo json_encode($mode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.currentUserId = <?php echo json_encode($currentUserId ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.usersUrl = <?php echo json_encode($usersUrl ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
        const typeSelect = document.getElementById('absence-type');
        const substituteSelect = document.getElementById('absence-substitute');
        const substituteLabel = document.getElementById('absence-substitute-label');
        const substituteRequiredMsg = document.getElementById('absence-substitute-required-msg');
        const requireSubstituteTypes = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.requireSubstituteTypes) || [];
        const currentUserId = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.currentUserId) || '';
        const usersUrl = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.usersUrl) || '';
        const selectedSubstituteId = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.selectedSubstituteId) || '';
        if (substituteSelect && usersUrl) {
            var requestToken = (typeof OC !== 'undefined' && OC.requestToken) ? OC.requestToken : (document.querySelector('head') && document.querySelector('head').getAttribute('data-requesttoken')) || '';
            fetch(usersUrl, {
                headers: { 'requesttoken': requestToken },
                credentials: 'same-origin'
            })
                .then(function(r) {
                    if (!r.ok) return null;
                    return r.json();
                })
                .then(function(data) {
                    var opts = substituteSelect.querySelectorAll('option[value!=""]');
                    opts.forEach(function(o) { o.remove(); });
                    var emptyHint = document.getElementById('absence-substitute-empty');
                    if (!data || !Array.isArray(data.users)) {
                        if (emptyHint) emptyHint.style.display = 'block';
                        return;
                    }
                    var count = 0;
                    data.users.forEach(function(u) {
                        if (u.userId === currentUserId) return;
                        var opt = document.createElement('option');
                        opt.value = u.userId;
                        opt.textContent = u.displayName || u.display_name || u.userId;
                        if (u.userId === selectedSubstituteId) opt.selected = true;
                        substituteSelect.appendChild(opt);
                        count++;
                    });
                    if (emptyHint) emptyHint.style.display = count === 0 ? 'block' : 'none';
                })
                .catch(function() {});
        }

        function updateSubstituteRequiredState() {
            if (!typeSelect || !substituteSelect || !substituteLabel || !substituteRequiredMsg) return;
            const type = typeSelect.value || '';
            const required = requireSubstituteTypes.indexOf(type) !== -1;
            substituteSelect.setAttribute('aria-required', required ? 'true' : 'false');
            substituteSelect.required = required;
            if (substituteLabel) {
                var base = <?php echo json_encode($l->t('Substitute'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                var reqLabel = <?php echo json_encode($l->t('required'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                substituteLabel.innerHTML = base + (required ? ' <span class="form-required" aria-label="' + reqLabel + '">*</span>' : '');
            }
            substituteRequiredMsg.style.display = 'none';
        }
        if (typeSelect) {
            typeSelect.addEventListener('change', updateSubstituteRequiredState);
            updateSubstituteRequiredState();
        }

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
        
        if (startDateInput) {
            startDateInput.addEventListener('change', function() {
                if (!endDateInput.value && startDateInput.value && /^\d{2}\.\d{2}\.\d{4}$/.test(startDateInput.value)) {
                    endDateInput.value = startDateInput.value;
                    endDateInput.dispatchEvent(new Event('change', { bubbles: true }));
                } else if (endDateInput.value) {
                    validateDates();
                }
            });
        }

        if (endDateInput) {
            endDateInput.addEventListener('change', function() {
                if (!startDateInput.value && endDateInput.value && /^\d{2}\.\d{2}\.\d{4}$/.test(endDateInput.value)) {
                    startDateInput.value = endDateInput.value;
                    startDateInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
                validateDates();
            });
        }
        
        function hideFormError() {
            var errEl = document.getElementById('absence-form-error');
            if (errEl) { errEl.style.display = 'none'; }
        }
        if (typeSelect) typeSelect.addEventListener('change', hideFormError);
        if (startDateInput) startDateInput.addEventListener('input', hideFormError);
        if (endDateInput) endDateInput.addEventListener('input', hideFormError);

        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                hideFormError();
                if (!validateDates()) {
                    return;
                }
                var type = typeSelect ? typeSelect.value : '';
                var subRequired = requireSubstituteTypes.indexOf(type) !== -1;
                if (subRequired && substituteSelect && (!substituteSelect.value || substituteSelect.value === '')) {
                    if (substituteRequiredMsg) substituteRequiredMsg.style.display = 'block';
                    substituteSelect.setAttribute('aria-invalid', 'true');
                    substituteSelect.focus();
                    return;
                }
                if (substituteSelect) substituteSelect.setAttribute('aria-invalid', 'false');
                if (substituteRequiredMsg) substituteRequiredMsg.style.display = 'none';
                
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
                                ? ((window.t && window.t('arbeitszeitcheck', 'Absence request submitted successfully')) || 'Absence request submitted successfully')
                                : ((window.t && window.t('arbeitszeitcheck', 'Absence request updated')) || 'Absence request updated');
                            if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                                window.OC.Notification.showTemporary(successMsg, { type: 'success' });
                            } else {
                                // Fallback so users always get feedback, even if Nextcloud notifications are unavailable
                                try {
                                    alert(successMsg);
                                } catch (e) {}
                            }
                            window.location.href = response.redirected ? response.url : listUrl;
                            return;
                        }
                        return response.text().then(function(text) {
                            let errMsg = (window.t && window.t('arbeitszeitcheck', 'Failed to submit absence request')) || 'Failed to submit absence request';
                            try {
                                const j = JSON.parse(text);
                                if (j && typeof j.error === 'string' && j.error) errMsg = j.error;
                            } catch (e) { /* ignore */ }
                            var errEl = document.getElementById('absence-form-error');
                            var errText = document.getElementById('absence-form-error-text');
                            if (errEl && errText) {
                                errText.textContent = errMsg;
                                errEl.style.display = 'block';
                                errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                            }
                            if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                                window.OC.Notification.showTemporary(errMsg, { type: 'error', timeout: 8000 });
                            } else {
                                try { alert(errMsg); } catch (e) {}
                            }
                        });
                    })
                    .catch(function(err) {
                        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalText; }
                        const errMsg = (err && err.message) || (window.t && window.t('arbeitszeitcheck', 'Failed to submit absence request')) || 'Failed to submit absence request';
                        var errEl = document.getElementById('absence-form-error');
                        var errText = document.getElementById('absence-form-error-text');
                        if (errEl && errText) {
                            errText.textContent = errMsg;
                            errEl.style.display = 'block';
                            errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                        if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                            window.OC.Notification.showTemporary(errMsg, { type: 'error', timeout: 8000 });
                        } else {
                            try { alert(errMsg); } catch (e) {}
                        }
                    });
            });
        }
    });
    <?php endif; ?>
</script>
