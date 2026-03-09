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
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper" role="main" aria-label="<?php p($l->t('Absences')); ?>">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb-container">
            <nav class="breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
                <ol>
                    <li><a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"><?php p($l->t('Dashboard')); ?></a></li>
                    <li aria-current="page"><?php p($l->t('Absences')); ?></li>
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
                        } else {
                            p($l->t('Absences'));
                        }
                    ?></h2>
                    <p><?php 
                        if ($mode === 'create') {
                            p($l->t('Request a new absence. Your manager will review and approve or reject your request.'));
                        } elseif ($mode === 'edit') {
                            p($l->t('Edit your absence request. You can only edit pending requests.'));
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
                        <input type="text" id="filter-start-date" class="form-input datepicker-input" placeholder="dd.mm.yyyy" value="<?php p($filterStartDate); ?>" data-datepicker-min="">
                    </div>
                    <div class="form-group">
                        <label for="filter-end-date" class="form-label"><?php p($l->t('End Date')); ?></label>
                        <input type="text" id="filter-end-date" class="form-input datepicker-input" placeholder="dd.mm.yyyy" value="<?php p($filterEndDate); ?>" data-datepicker-min="">
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
                <?php if ($error): ?>
                    <div class="alert alert--error" role="alert" aria-live="polite" id="absence-form-error">
                        <p><?php p($error); ?></p>
                    </div>
                <?php endif; ?>
                
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

                    <div class="form-group">
                        <label for="absence-start-date" class="form-label">
                            <?php p($l->t('Start Date')); ?> <span class="form-required">*</span>
                        </label>
                        <input type="text"
                               id="absence-start-date"
                               name="start_date"
                               class="form-input datepicker-input"
                               data-datepicker-min="today"
                               value="<?php p($absence ? $absence->getStartDate()->format('d.m.Y') : ''); ?>"
                               placeholder="dd.mm.yyyy"
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
                               data-datepicker-min="today"
                               value="<?php p($absence ? $absence->getEndDate()->format('d.m.Y') : ''); ?>"
                               placeholder="dd.mm.yyyy"
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
                    <div class="form-group form-group--substitute">
                        <label for="absence-substitute" class="form-label">
                            <?php p($l->t('Substitute')); ?>
                        </label>
                        <select id="absence-substitute"
                                name="substitute_user_id"
                                class="form-select"
                                aria-describedby="absence-substitute-help"
                                data-placeholder="<?php p($l->t('Select who will cover for you (optional)')); ?>">
                            <option value=""><?php p($l->t('None')); ?></option>
                            <!-- Options filled by JavaScript from /api/users -->
                        </select>
                        <p id="absence-substitute-help" class="form-help"><?php p($l->t('Choose a colleague from your team who will cover your tasks during your absence. Only team members appear in this list.')); ?></p>
                        <p id="absence-substitute-empty" class="form-help form-help--info" style="display: none;" role="status"><?php p($l->t('No team members found. Add yourself to a team or group to select a substitute.')); ?></p>
                    </div>
                    <?php else: ?>
                    <p class="form-help form-help--info" role="status"><?php p($l->t('No team members in your team. You cannot select a substitute. Add yourself to a team to enable this option.')); ?></p>
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
        <?php else: ?>
            <!-- Stats Cards -->
            <section class="section section--stats" aria-labelledby="stats-title">
                <h3 id="stats-title" class="section__title visually-hidden"><?php p($l->t('Your absence overview')); ?></h3>
                <?php if (!empty($stats)): ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <span class="stat-label"><?php p($l->t('Vacation Days Remaining')); ?></span>
                            <span class="stat-value"><?php p($stats['vacation_days_remaining'] ?? 0); ?></span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-label"><?php p($l->t('Pending Requests')); ?></span>
                            <span class="stat-value"><?php p($stats['pending_requests'] ?? 0); ?></span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-label"><?php p($l->t('Days Taken This Year')); ?></span>
                            <span class="stat-value"><?php p($stats['days_taken_this_year'] ?? 0); ?></span>
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
                                        <td data-label="<?php p($l->t('Days')); ?>"><?php p($absence->getDays()); ?></td>
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
                                                <button type="button" class="btn-icon btn-edit" 
                                                        data-absence-id="<?php p($absence->getId()); ?>"
                                                        aria-label="<?php p($l->t('Edit this absence request')); ?>"
                                                        title="<?php p($l->t('Edit')); ?>">
                                                    <span aria-hidden="true">✏️</span>
                                                </button>
                                                <button type="button" class="btn-icon btn-cancel" 
                                                        data-absence-id="<?php p($absence->getId()); ?>"
                                                        aria-label="<?php p($l->t('Cancel this absence request')); ?>"
                                                        title="<?php p($l->t('Cancel')); ?>">
                                                    <span aria-hidden="true">❌</span>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn-icon btn-view" 
                                                        data-absence-id="<?php p($absence->getId()); ?>"
                                                        aria-label="<?php p($l->t('View details of this absence')); ?>"
                                                        title="<?php p($l->t('View Details')); ?>">
                                                    <span aria-hidden="true">👁️</span>
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
        update: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.absence.updatePost', ['id' => '__ID__']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>.replace('__ID__', ''),
        delete: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.absence.delete', ['id' => '__ID__']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>.replace('__ID__', '')
    };
    
    // Handle form submission for create/edit
    <?php if ($mode === 'create' || $mode === 'edit'): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('absence-form');
        const startDateInput = document.getElementById('absence-start-date');
        const endDateInput = document.getElementById('absence-end-date');
        const substituteSelect = document.getElementById('absence-substitute');
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
                if (endDateInput.value) {
                    validateDates();
                }
            });
        }
        
        if (endDateInput) {
            endDateInput.addEventListener('change', validateDates);
        }
        
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (!validateDates()) {
                    return;
                }
                
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
                            }
                            window.location.href = response.redirected ? response.url : listUrl;
                            return;
                        }
                        return response.text().then(function(text) {
                            let errMsg = (window.t && window.t('arbeitszeitcheck', 'Failed to submit absence request')) || 'Failed to submit absence request';
                            try {
                                const j = JSON.parse(text);
                                if (j && j.error) errMsg = j.error;
                            } catch (e) { /* ignore */ }
                            if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                                window.OC.Notification.showTemporary(errMsg, { type: 'error' });
                            }
                        });
                    })
                    .catch(function(err) {
                        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalText; }
                        const errMsg = (err && err.message) || (window.t && window.t('arbeitszeitcheck', 'Failed to submit absence request')) || 'Failed to submit absence request';
                        if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                            window.OC.Notification.showTemporary(errMsg, { type: 'error' });
                        }
                    });
            });
        }
    });
    <?php endif; ?>
</script>
