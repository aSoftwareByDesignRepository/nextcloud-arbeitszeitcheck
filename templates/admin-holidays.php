<?php

declare(strict_types=1);

/**
 * Admin holidays template for arbeitszeitcheck app
 *
 * Pflege von Feiertagskalendern pro Bundesland und globalem Standardkalender.
 *
 * @copyright Copyright (c) 2025
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);

$defaultState = $_['defaultState'] ?? 'NW';
$currentYear = (int)date('Y');

// Server-side strings for admin-holidays.js (window.t is not always available in this view)
$holidaysUiStrings = [
	'dd.mm.yyyy' => $l->t('dd.mm.yyyy'),
	'Full-day holiday' => $l->t('Full-day holiday'),
	'Half-day holiday' => $l->t('Half-day holiday'),
	'Company holiday' => $l->t('Company holiday'),
	'custom' => $l->t('custom'),
	'Statutory' => $l->t('Statutory'),
	'Save' => $l->t('Save'),
	'Remove' => $l->t('Remove'),
	'Technical error: Required fields for the holiday could not be found.' => $l->t('Technical error: Required fields for the holiday could not be found.'),
	'Please specify date and name of the holiday.' => $l->t('Please specify date and name of the holiday.'),
	'Holiday was saved.' => $l->t('Holiday was saved.'),
	'Holiday could not be saved.' => $l->t('Holiday could not be saved.'),
	'An error occurred while saving the holiday.' => $l->t('An error occurred while saving the holiday.'),
	'Holidays could not be loaded.' => $l->t('Holidays could not be loaded.'),
	'Remove holiday {name} on {date}' => $l->t('Remove holiday {name} on {date}'),
	'Remove holiday' => $l->t('Remove holiday'),
	'Do you really want to remove the holiday "{name}" on {date}?' => $l->t('Do you really want to remove the holiday "{name}" on {date}?'),
	'Statutory holidays are automatically restored when the calendar is viewed, unless "Auto-restore statutory holidays" is disabled in Settings.' => $l->t('Statutory holidays are automatically restored when the calendar is viewed, unless "Auto-restore statutory holidays" is disabled in Settings.'),
	'Cancel' => $l->t('Cancel'),
	'No holidays configured for this year.' => $l->t('No holidays configured for this year.'),
	'Holiday was removed.' => $l->t('Holiday was removed.'),
	'Holiday could not be removed.' => $l->t('Holiday could not be removed.'),
	'An error occurred while removing the holiday.' => $l->t('An error occurred while removing the holiday.'),
];
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content" class="admin-holidays-page">
<script type="application/json" nonce="<?php p($_['cspNonce'] ?? ''); ?>" id="arbeitszeitcheck-admin-holidays-ui-strings">
<?php echo json_encode($holidaysUiStrings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
</script>
    <div id="app-content-wrapper">
            <div class="section admin-holidays-section">
                <header class="section-header admin-holidays-section__header">
                    <h2><?php p($l->t('Holidays and calendar')); ?></h2>
                    <p class="section-intro">
                        <?php p($l->t('Manage statutory and additional holidays per federal state. These calendars are used for vacation, working time calculations, and compliance checks.')); ?>
                    </p>
                </header>

                <div class="section-content admin-holidays-section__content">
                    <section class="admin-holidays-card" aria-labelledby="holiday-default-state-heading">
                        <header class="admin-holidays-card__header">
                            <h3 id="holiday-default-state-heading" class="admin-holidays-card__title">
                                <?php p($l->t('Default federal state for holidays')); ?>
                            </h3>
                            <p class="admin-holidays-card__subtitle">
                                <?php p($l->t('This federal state is used automatically when no own state is configured for employees or teams.')); ?>
                            </p>
                        </header>

                        <div class="admin-holidays-card__body">
                            <div class="form-group">
                                <label for="holiday-default-state" class="form-label">
                                    <?php p($l->t('Select default federal state')); ?>
                                </label>
                                <select id="holiday-default-state"
                                        name="holidayDefaultState"
                                        class="form-select"
                                        aria-describedby="holiday-default-state-help">
                                    <?php
                                    $states = [
                                        'BW' => 'Baden‑Württemberg',
                                        'BY' => 'Bayern',
                                        'BE' => 'Berlin',
                                        'BB' => 'Brandenburg',
                                        'HB' => 'Bremen',
                                        'HH' => 'Hamburg',
                                        'HE' => 'Hessen',
                                        'MV' => 'Mecklenburg‑Vorpommern',
                                        'NI' => 'Niedersachsen',
                                        'NW' => 'Nordrhein‑Westfalen',
                                        'RP' => 'Rheinland‑Pfalz',
                                        'SL' => 'Saarland',
                                        'SN' => 'Sachsen',
                                        'ST' => 'Sachsen‑Anhalt',
                                        'SH' => 'Schleswig‑Holstein',
                                        'TH' => 'Thüringen',
                                    ];
                                    foreach ($states as $code => $name) {
                                        $selected = $code === $defaultState ? ' selected' : '';
                                        $label = $l->t($name);
                                        echo '<option value="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' .
                                            htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
                                            '</option>';
                                    }
                                    ?>
                                </select>
                                <p id="holiday-default-state-help" class="form-help">
                                    <?php
                                    $usersUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.admin.users');
                                    print_unescaped($l->t(
                                        'The federal state for an employee is set by administrators or managers, for example in %1$sEmployee settings%2$s. If no own state is configured there, the default state configured here is used.',
                                        [
                                            '<a href="' . \OCP\Util::sanitizeHTML($usersUrl) . '">',
                                            '</a>',
                                        ]
                                    ));
                                    ?>
                                </p>
                            </div>
                        </div>
                    </section>

                    <section class="admin-holidays-card" aria-labelledby="state-calendar-heading">
                        <header class="admin-holidays-card__header">
                            <h3 id="state-calendar-heading" class="admin-holidays-card__title">
                                <?php p($l->t('Manage calendar per federal state')); ?>
                            </h3>
                            <p class="admin-holidays-card__subtitle">
                                <?php p($l->t('Select a federal state and year to view and edit statutory holidays plus additional company or custom holidays.')); ?>
                            </p>
                        </header>

                        <div class="section-content admin-holidays-filters" aria-label="<?php p($l->t('Calendar selection')); ?>">
                        <div class="admin-holidays-filters__controls">
                            <div class="form-group form-group--inline">
                                <label for="holiday-state-select" class="form-label">
                                    <?php p($l->t('Federal state')); ?>
                                </label>
                                <select id="holiday-state-select"
                                        name="holidayState"
                                        class="form-select">
                                    <?php foreach ($states as $code => $name): ?>
                                        <?php $selected = $code === $defaultState ? ' selected' : ''; ?>
                                        <option value="<?php p($code); ?>"<?php p($selected); ?>>
                                            <?php p($l->t($name)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group form-group--inline">
                                <label for="holiday-year-select" class="form-label">
                                    <?php p($l->t('Year')); ?>
                                </label>
                                <select id="holiday-year-select"
                                        name="holidayYear"
                                        class="form-select">
                                    <?php for ($y = $currentYear - 1; $y <= $currentYear + 3; $y++): ?>
                                        <option value="<?php p($y); ?>"<?php if ($y === $currentYear) { echo ' selected'; } ?>>
                                            <?php p($y); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <div class="admin-holidays-filters__actions">
                            <div class="form-group form-group--inline">
                                <button type="button"
                                        id="holiday-add-entry"
                                        class="btn btn--primary"
                                        aria-label="<?php p($l->t('Create new holiday')); ?>">
                                    <?php p($l->t('Add new holiday')); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="table-container admin-holidays-table-container" role="region" aria-label="<?php p($l->t('List of holidays for the selected federal state and year')); ?>">
                        <table class="table table--hover admin-holidays-table" id="holiday-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php p($l->t('Date')); ?></th>
                                    <th scope="col"><?php p($l->t('Holiday name')); ?></th>
                                    <th scope="col"><?php p($l->t('Type')); ?></th>
                                    <th scope="col"><?php p($l->t('Scope')); ?></th>
                                    <th scope="col"><?php p($l->t('Actions')); ?></th>
                                </tr>
                            </thead>
                            <tbody id="holiday-tbody">
                                <!-- Wird per JavaScript geladen -->
                            </tbody>
                        </table>
                    </div>

                    <div class="admin-holidays-legend" aria-label="<?php p($l->t('Column explanations')); ?>">
                        <p class="form-help">
                            <?php p($l->t('"Type" determines whether a day is treated as a full-day holiday (not counted as a working day) or as a half-day holiday (e.g., 0.5 vacation day).')); ?>
                        </p>
                        <p class="form-help">
                            <?php p($l->t('"Scope" distinguishes between statutory holidays, organization-wide company holidays, and custom entries. Statutory holidays are always treated as full-day holidays.')); ?>
                        </p>
                    </div>
                    </section>
            </div>
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

