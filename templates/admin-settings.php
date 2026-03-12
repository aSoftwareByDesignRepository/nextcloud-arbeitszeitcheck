<?php

declare(strict_types=1);

/**
 * Admin settings template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$settings = $_['settings'] ?? [];
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <div class="section">
            <div class="section-header">
                <h2><?php p($l->t('Einstellungen für ArbeitszeitCheck')); ?></h2>
                <p><?php p($l->t('Hier legen Sie fest, wie die Zeiterfassung und die Prüfungen für alle Beschäftigten funktionieren.')); ?></p>
            </div>

            <?php if (isset($_['error']) && !empty($_['error'])): ?>
                <div class="alert alert--error" role="alert" aria-live="polite">
                    <span class="alert-icon" aria-hidden="true">⚠️</span>
                    <div class="alert-content">
                        <strong class="alert-title"><?php p($l->t('Es ist ein Fehler aufgetreten')); ?></strong>
                        <p class="alert-message">
                            <?php 
                            // Make error message more helpful
                            $error = $_['error'];
                            if (strpos($error, 'Exception') !== false || strpos($error, 'Error') !== false || strpos($error, 'SQL') !== false) {
                                p($l->t('Bitte versuchen Sie es erneut. Wenn das Problem bleibt, wenden Sie sich an Ihre Administratorin oder Ihren Administrator.'));
                            } else {
                                p($error);
                            }
                            ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <form id="admin-settings-form" class="form">
                <div class="form-group">
                    <div class="form-checkbox">
                        <input type="checkbox" id="autoComplianceCheck" name="autoComplianceCheck" 
                            <?php echo ($settings['autoComplianceCheck'] ?? true) ? 'checked' : ''; ?>>
                        <label for="autoComplianceCheck" class="form-label">
                            <?php p($l->t('Arbeitszeitregeln automatisch prüfen')); ?>
                        </label>
                    </div>
                    <p class="form-help">
                        <?php p($l->t('Die App prüft automatisch, ob Arbeitszeiten das Arbeitszeitgesetz einhalten (z. B. Warnung bei mehr als 8 Stunden ohne Pause).')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <div class="form-checkbox">
                        <input type="checkbox" id="requireBreakJustification" name="requireBreakJustification"
                            <?php echo ($settings['requireBreakJustification'] ?? true) ? 'checked' : ''; ?>>
                        <label for="requireBreakJustification" class="form-label">
                            <?php p($l->t('Grund für Pausen verlangen')); ?>
                        </label>
                    </div>
                    <p class="form-help">
                        <?php p($l->t('Bei Pausen muss ein kurzer Grund eingetragen werden. Das hilft, Pausen korrekt und nachvollziehbar zu dokumentieren.')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <div class="form-checkbox">
                        <input type="checkbox" id="enableViolationNotifications" name="enableViolationNotifications"
                            <?php echo ($settings['enableViolationNotifications'] ?? true) ? 'checked' : ''; ?>>
                        <label for="enableViolationNotifications" class="form-label">
                            <?php p($l->t('Hinweise bei Verstößen gegen Arbeitszeitregeln senden')); ?>
                        </label>
                    </div>
                    <p class="form-help">
                        <?php p($l->t('Bei Überschreitung von Grenzwerten (z. B. zu viele Stunden, fehlende Pausen) werden Hinweise an Beschäftigte und ggf. Vorgesetzte gesendet.')); ?>
                    </p>
                </div>

                <fieldset class="form-fieldset" aria-labelledby="send-ical-legend">
                    <legend id="send-ical-legend" class="form-legend"><?php p($l->t('Abwesenheiten: iCal per E‑Mail versenden')); ?></legend>
                    <p class="form-help form-help--block">
                        <?php p($l->t('Bei genehmigten Abwesenheiten kann automatisch eine E‑Mail mit einem iCalendar‑Anhang (.ics) verschickt werden.')); ?>
                    </p>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="sendIcalApprovedAbsences" name="sendIcalApprovedAbsences" value="1"
                                <?php echo ($settings['sendIcalApprovedAbsences'] ?? true) ? 'checked' : ''; ?>
                                aria-describedby="send-ical-legend">
                            <label for="sendIcalApprovedAbsences" class="form-label">
                                <?php p($l->t('iCal an die Person mit genehmigter Abwesenheit senden')); ?>
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="sendIcalToSubstitute" name="sendIcalToSubstitute" value="1"
                                <?php echo ($settings['sendIcalToSubstitute'] ?? false) ? 'checked' : ''; ?>
                                aria-describedby="send-ical-legend">
                            <label for="sendIcalToSubstitute" class="form-label">
                                <?php p($l->t('Zusätzlich iCal an Vertretung senden (falls ausgewählt)')); ?>
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="sendIcalToManagers" name="sendIcalToManagers" value="1"
                                <?php echo ($settings['sendIcalToManagers'] ?? false) ? 'checked' : ''; ?>
                                aria-describedby="send-ical-legend">
                            <label for="sendIcalToManagers" class="form-label">
                                <?php p($l->t('Zusätzlich iCal an Vorgesetzte senden (Team-Manager:innen)')); ?>
                            </label>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="form-fieldset" aria-labelledby="require-substitute-legend">
                    <legend id="require-substitute-legend" class="form-legend"><?php p($l->t('Abwesenheiten: Vertretung erforderlich')); ?></legend>
                    <p class="form-help form-help--block">
                        <?php p($l->t('Für die ausgewählten Abwesenheitsarten muss eine Vertretung angegeben werden. So ist klar, wer während der Abwesenheit zuständig ist.')); ?>
                    </p>
                    <?php
                    $requireTypes = $settings['requireSubstituteTypes'] ?? [];
                    $absenceTypes = [
                        'vacation' => $l->t('Urlaub'),
                        'sick_leave' => $l->t('Krankschreibung'),
                        'personal_leave' => $l->t('Persönliche Gründe'),
                        'parental_leave' => $l->t('Elternzeit'),
                        'special_leave' => $l->t('Sonderurlaub'),
                        'unpaid_leave' => $l->t('Unbezahlter Urlaub'),
                        'home_office' => $l->t('Homeoffice'),
                        'business_trip' => $l->t('Dienstreise'),
                    ];
                    foreach ($absenceTypes as $typeKey => $typeLabel):
                        $checked = in_array($typeKey, $requireTypes, true);
                    ?>
                    <div class="form-group form-group--inline">
                        <div class="form-checkbox">
                            <input type="checkbox" id="requireSubstitute_<?php p($typeKey); ?>" name="requireSubstituteTypes[]" value="<?php p($typeKey); ?>"
                                <?php echo $checked ? 'checked' : ''; ?>
                                aria-describedby="require-substitute-legend">
                            <label for="requireSubstitute_<?php p($typeKey); ?>" class="form-label"><?php p($typeLabel); ?></label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </fieldset>

                <div class="form-group">
                    <label for="maxDailyHours" class="form-label">
                        <?php p($l->t('Maximale Arbeitsstunden pro Tag')); ?>
                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                    </label>
                    <input type="number" 
                           id="maxDailyHours" 
                           name="maxDailyHours" 
                           class="form-input <?php echo isset($_['errors']['maxDailyHours']) ? 'form-input--error' : ''; ?>"
                           value="<?php p($settings['maxDailyHours'] ?? 10); ?>" 
                           min="1" 
                           max="24" 
                           step="0.5" 
                           required
                           aria-describedby="maxDailyHours-help <?php echo isset($_['errors']['maxDailyHours']) ? 'maxDailyHours-error' : ''; ?>"
                           aria-invalid="<?php echo isset($_['errors']['maxDailyHours']) ? 'true' : 'false'; ?>">
                    <p id="maxDailyHours-help" class="form-help">
                        <?php p($l->t('Obergrenze der täglichen Arbeitszeit in Stunden. Nach ArbZG in der Regel 8 Stunden, in Ausnahmen bis 10 Stunden.')); ?>
                    </p>
                    <?php if (isset($_['errors']['maxDailyHours'])): ?>
                        <?php 
                        $fieldName = 'maxDailyHours';
                        $errorMessage = is_array($_['errors']['maxDailyHours']) ? $_['errors']['maxDailyHours'][0] : $_['errors']['maxDailyHours'];
                        include __DIR__ . '/common/form-error.php';
                        ?>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="minRestPeriod" class="form-label">
                        <?php p($l->t('Mindest-Ruhezeit zwischen zwei Arbeitstagen')); ?>
                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                    </label>
                    <input type="number" 
                           id="minRestPeriod" 
                           name="minRestPeriod" 
                           class="form-input"
                           value="<?php p($settings['minRestPeriod'] ?? 11); ?>" 
                           min="1" 
                           max="24" 
                           step="0.5" 
                           required
                           aria-describedby="minRestPeriod-help">
                    <p id="minRestPeriod-help" class="form-help">
                        <?php p($l->t('Anzahl Stunden Ruhezeit zwischen Arbeitsende und nächstem Arbeitsbeginn (ArbZG verlangt mindestens 11 Stunden).')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <label for="defaultWorkingHours" class="form-label">
                        <?php p($l->t('Standard-Arbeitszeit pro Tag')); ?>
                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                    </label>
                    <input type="number" 
                           id="defaultWorkingHours" 
                           name="defaultWorkingHours" 
                           class="form-input"
                           value="<?php p($settings['defaultWorkingHours'] ?? 8); ?>" 
                           min="1" 
                           max="24" 
                           step="0.5" 
                           required
                           aria-describedby="defaultWorkingHours-help">
                    <p id="defaultWorkingHours-help" class="form-help">
                        <?php p($l->t('Übliche tägliche Arbeitszeit in Stunden. Wird für neue Mitarbeitende als Voreinstellung verwendet, bis individuelle Modelle gesetzt sind.')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <label for="germanState" class="form-label">
                        <?php p($l->t('Standard-Bundesland für Feiertage')); ?>
                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                    </label>
                    <select id="germanState" 
                            name="germanState" 
                            class="form-select" 
                            required
                            aria-describedby="germanState-help">
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
                        $currentState = $settings['germanState'] ?? 'NW';
                        foreach ($states as $code => $name) {
                            $selected = ($currentState === $code) ? ' selected' : '';
                            $label = $l->t($name);
                            echo '<option value="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' .
                                htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
                                '</option>';
                        }
                        ?>
                    </select>
                    <p id="germanState-help" class="form-help">
                        <?php p($l->t('Wird für gesetzliche Feiertage und arbeitszeitrechtliche Prüfungen verwendet, wenn für Mitarbeitende/Teams nichts Spezielleres hinterlegt ist.')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <label for="retentionPeriod" class="form-label">
                        <?php p($l->t('Aufbewahrungsdauer für Zeitnachweise (in Jahren)')); ?>
                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                    </label>
                    <input type="number" 
                           id="retentionPeriod" 
                           name="retentionPeriod" 
                           class="form-input"
                           value="<?php p($settings['retentionPeriod'] ?? 2); ?>" 
                           min="1" 
                           max="10" 
                           required
                           aria-describedby="retentionPeriod-help">
                    <p id="retentionPeriod-help" class="form-help">
                        <?php p($l->t('Anzahl der Jahre, für die Arbeitszeitdaten gespeichert werden, bevor sie automatisch gelöscht werden (typisch: mindestens 2 Jahre).')); ?>
                    </p>
                </div>

                <div class="card-actions">
                    <button type="submit" 
                            class="btn btn--primary"
                            aria-label="<?php p($l->t('Alle Einstellungen speichern')); ?>"
                            title="<?php p($l->t('Änderungen speichern und für alle Benutzerinnen und Benutzer übernehmen')); ?>">
                        <?php p($l->t('Einstellungen speichern')); ?>
                    </button>
                    <a href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.index')); ?>"
                       class="btn btn--secondary"
                       aria-label="<?php p($l->t('Abbrechen und zurück zur Übersicht')); ?>"
                       title="<?php p($l->t('Zurück, ohne Änderungen zu speichern')); ?>">
                        <?php p($l->t('Abbrechen')); ?>
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->
