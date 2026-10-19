<?php

declare(strict_types=1);

/**
 * First-login card on the dashboard.
 *
 * People who can open Administration see the setup steps. Everyone else
 * sees how to record time. Included only when the visit is a first login.
 *
 * Expects from the caller: $l, $urlGenerator, $_, $clockStampingEnabled,
 * $manualTimeEntryEnabled.
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */

$showAdminWelcome = !empty($_['showAdminSetupWelcome']);
$shellUrls = is_array($_['urls'] ?? null) ? $_['urls'] : [];
$welcomeHref = static function (string $key, string $route) use ($shellUrls, $urlGenerator): string {
	$fromShell = $shellUrls[$key] ?? '';
	if (is_string($fromShell) && $fromShell !== '') {
		return $fromShell;
	}

	return $urlGenerator->linkToRoute($route);
};
$welcomeAnchor = static function (string $href, string $step): string {
	return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '" data-welcome-step="' . htmlspecialchars($step, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
};
?>
<article class="azc-card azc-dashboard-welcome" role="region" aria-labelledby="welcome-title" data-welcome="<?php p($showAdminWelcome ? 'admin' : 'employee'); ?>">
    <header class="azc-card__header">
        <div class="azc-card__header-text">
            <?php if ($showAdminWelcome): ?>
            <h2 id="welcome-title" class="azc-card__title"><?php p($l->t('Set up ArbeitszeitCheck first')); ?></h2>
            <p class="azc-card__lead"><?php p($l->t('Do these three steps before anyone records time. Until then, holidays, target hours, and break rules are not reliable.')); ?></p>
            <?php else: ?>
            <h2 id="welcome-title" class="azc-card__title"><?php p($l->t('Welcome to Time Tracking!')); ?></h2>
            <p class="azc-card__lead"><?php p($l->t('This app helps you record your work time and follow the configured labour law. Here\'s how to get started:')); ?></p>
            <?php endif; ?>
        </div>
    </header>
    <div class="azc-card__body">
        <?php if ($showAdminWelcome): ?>
        <ol class="azc-dashboard-welcome__steps">
            <li>
                <h3 class="azc-dashboard-welcome__step-title"><strong><?php p($l->t('Country, region, and holidays')); ?></strong></h3>
                <p class="azc-dashboard-welcome__step-text"><?php print_unescaped($l->t(
                	'Open Administration, then %1$sHolidays and calendars%2$s. Choose the country and the default region for public holidays, then check the calendar for this year.',
                	[$welcomeAnchor($welcomeHref('adminHolidays', 'arbeitszeitcheck.admin.holidays'), 'holidays'), '</a>']
                )); ?></p>
            </li>
            <li>
                <h3 class="azc-dashboard-welcome__step-title"><strong><?php p($l->t('A working time model')); ?></strong></h3>
                <p class="azc-dashboard-welcome__step-text"><?php print_unescaped($l->t(
                	'Open Administration, then %1$sWorking time models%2$s. Create at least one schedule, for example full-time or part-time.',
                	[$welcomeAnchor($welcomeHref('adminWorkingTimeModels', 'arbeitszeitcheck.admin.workingTimeModels'), 'models'), '</a>']
                )); ?></p>
            </li>
            <li>
                <h3 class="azc-dashboard-welcome__step-title"><strong><?php p($l->t('Assign it')); ?></strong></h3>
                <p class="azc-dashboard-welcome__step-text"><?php print_unescaped($l->t(
                	'Open Administration, then %1$sEmployees%2$s, and open the person. Under Work schedule, choose that model. Repeat for everyone who records time, including yourself.',
                	[$welcomeAnchor($welcomeHref('adminUsers', 'arbeitszeitcheck.admin.users'), 'employees'), '</a>']
                )); ?></p>
            </li>
        </ol>
        <?php else: ?>
        <ol class="azc-dashboard-welcome__steps">
            <?php if ($clockStampingEnabled): ?>
            <li><?php p($l->t('Click the "Clock In" button below when you start work')); ?></li>
            <li><?php p($l->t('Click "Clock Out" when you finish work')); ?></li>
            <li><?php p($l->t('The system will automatically track your hours and remind you to take breaks')); ?></li>
            <?php endif; ?>
            <?php if ($manualTimeEntryEnabled): ?>
            <li><?php p($l->t('You can add time entries manually under Time entries when you need to record hours by hand')); ?></li>
            <?php endif; ?>
            <li><?php p($l->t('Request vacation and other absences in the Absences section')); ?></li>
        </ol>
        <?php endif; ?>
        <div class="azc-dashboard-welcome__actions">
            <?php if (!$showAdminWelcome && $manualTimeEntryEnabled): ?>
            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>"
                class="azc-btn azc-btn--primary">
                <?php p($l->t('Learn More About Time Entries')); ?>
            </a>
            <?php endif; ?>
            <button type="button" class="azc-btn azc-btn--secondary" id="dismiss-welcome">
                <?php p($l->t('Got it, thanks!')); ?>
            </button>
        </div>
    </div>
</article>
