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
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content" class="admin-holidays-page">
    <div id="app-content-wrapper">
        <div class="section">
            <div class="section-header">
                <h2><?php p($l->t('Feiertage & Kalender')); ?></h2>
            </div>

            <div class="section-content">
                <p class="section-intro">
                    <?php p($l->t('Verwalten Sie hier die gesetzlichen und zusätzlichen Feiertage pro Bundesland. Diese Kalender werden für Urlaub, Arbeitszeitberechnungen und Compliance-Prüfungen genutzt.')); ?>
                </p>
                <p class="form-help">
                    <?php p($l->t('Gesetzliche Feiertage sind immer ganze Tage. Halbe Tage (z. B. 24.12. oder 31.12. am Nachmittag) werden nur als zusätzliche Firmenfeiertage konfiguriert und gelten dann nicht automatisch als gesetzlicher Feiertag.')); ?>
                </p>

                <div class="form-group">
                    <label for="holiday-default-state" class="form-label">
                        <?php p($l->t('Standard-Bundesland für Feiertage')); ?>
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
                        <?php p($l->t('Dieses Bundesland wird verwendet, wenn für Mitarbeitende oder Teams kein eigenes Bundesland hinterlegt ist.')); ?>
                    </p>
                    <p class="form-help">
                        <?php
                        $usersUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.admin.users');
                        print_unescaped($l->t(
                            'Das konkrete Bundesland eines Mitarbeiters oder einer Mitarbeiterin wird ausschließlich von Administratoren oder Managerinnen festgelegt, zum Beispiel in den %1$sMitarbeiter-Einstellungen%2$s. Wenn dort kein eigenes Bundesland hinterlegt ist, greift automatisch das hier konfigurierte Standard-Bundesland.',
                            [
                                '<a href="' . \OCP\Util::sanitizeHTML($usersUrl) . '">',
                                '</a>',
                            ]
                        ));
                        ?>
                    </p>
                </div>

                <div class="section">
                    <div class="section-header">
                        <h3><?php p($l->t('Kalender je Bundesland verwalten')); ?></h3>
                        <p><?php p($l->t('Wählen Sie ein Bundesland und ein Jahr, um gesetzliche Feiertage sowie zusätzliche Firmen- oder Custom-Feiertage zu prüfen und anzupassen.')); ?></p>
                    </div>

                    <div class="section-content admin-holidays-filters" aria-label="<?php p($l->t('Kalenderauswahl')); ?>">
                        <div class="form-group form-group--inline">
                            <label for="holiday-state-select" class="form-label">
                                <?php p($l->t('Bundesland')); ?>
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
                                <?php p($l->t('Jahr')); ?>
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

                        <div class="form-group form-group--inline">
                            <button type="button"
                                    id="holiday-add-entry"
                                    class="btn btn--primary"
                                    aria-label="<?php p($l->t('Neuen Feiertag anlegen')); ?>">
                                <?php p($l->t('Neuen Feiertag hinzufügen')); ?>
                            </button>
                        </div>
                    </div>

                    <div class="table-container" role="region" aria-label="<?php p($l->t('Liste der Feiertage für das ausgewählte Bundesland und Jahr')); ?>">
                        <table class="table table--hover" id="holiday-table" role="table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php p($l->t('Datum')); ?></th>
                                    <th scope="col"><?php p($l->t('Name')); ?></th>
                                    <th scope="col"><?php p($l->t('Art')); ?></th>
                                    <th scope="col"><?php p($l->t('Geltungsbereich')); ?></th>
                                    <th scope="col"><?php p($l->t('Aktionen')); ?></th>
                                </tr>
                            </thead>
                            <tbody id="holiday-tbody">
                                <!-- Wird per JavaScript geladen -->
                            </tbody>
                        </table>
                    </div>

                    <p class="form-help">
                        <?php p($l->t('„Art“ bestimmt, ob ein Tag als voller Feiertag (zählt nicht als Arbeitstag) oder als halber Feiertag (z. B. 0,5 Urlaubstag) behandelt wird. „Geltungsbereich“ unterscheidet zwischen gesetzlichen Feiertagen, organisationsweiten Firmenfeiertagen und benutzerdefinierten Einträgen.')); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

