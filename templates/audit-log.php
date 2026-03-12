<?php

declare(strict_types=1);

/**
 * Audit log template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$logs = $_['logs'] ?? [];
$total = $_['total'] ?? 0;
$startDate = $_['startDate'] ?? '';
$endDate = $_['endDate'] ?? '';
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content" class="audit-log-page">
    <div id="app-content-wrapper">
        <main class="audit-log-main" role="main">
            <header class="audit-log-header" role="banner">
                <h1 class="audit-log-title"><?php p($l->t('Aktivitätsprotokoll')); ?></h1>
                <p class="audit-log-subtitle"><?php p($l->t('Protokoll aller Aktionen im Zeiterfassungssystem (wer was wann geändert hat).')); ?></p>
            </header>

            <section class="audit-log-filters" aria-labelledby="audit-filters-heading">
                <h2 id="audit-filters-heading" class="visually-hidden"><?php p($l->t('Filteroptionen')); ?></h2>
                <div class="audit-log-filters__grid">
                    <div class="audit-log-filters__field">
                        <label for="start-date" class="audit-log-filters__label"><?php p($l->t('Von Datum')); ?></label>
                        <input type="text" id="start-date" class="form-input datepicker-input audit-log-filters__input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly value="<?php p($startDate); ?>">
                    </div>
                    <div class="audit-log-filters__field">
                        <label for="end-date" class="audit-log-filters__label"><?php p($l->t('Bis Datum')); ?></label>
                        <input type="text" id="end-date" class="form-input datepicker-input audit-log-filters__input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly value="<?php p($endDate); ?>">
                    </div>
                    <div class="audit-log-filters__field">
                        <label for="user-filter" class="audit-log-filters__label"><?php p($l->t('Mitarbeiter oder Benutzer-ID')); ?></label>
                        <input type="text" id="user-filter" class="form-input audit-log-filters__input" placeholder="<?php p($l->t('Nach Benutzer filtern')); ?>" autocomplete="off">
                    </div>
                    <div class="audit-log-filters__field">
                        <label for="action-filter" class="audit-log-filters__label"><?php p($l->t('Aktionstyp (vereinfacht)')); ?></label>
                        <select id="action-filter" class="form-select audit-log-filters__input">
                            <option value=""><?php p($l->t('Alle Aktionen')); ?></option>
                            <option value="create"><?php p($l->t('Erstellen')); ?></option>
                            <option value="update"><?php p($l->t('Ändern')); ?></option>
                            <option value="delete"><?php p($l->t('Löschen')); ?></option>
                        </select>
                    </div>
                    <div class="audit-log-filters__actions">
                        <button type="button" id="apply-filters" class="btn btn--primary" aria-label="<?php p($l->t('Filter anwenden und Liste aktualisieren')); ?>">
                            <?php p($l->t('Filter anwenden')); ?>
                        </button>
                        <button type="button" id="export-logs" class="btn btn--secondary" aria-label="<?php p($l->t('Aktivitätsprotokoll als CSV exportieren')); ?>">
                            <?php p($l->t('Exportieren')); ?>
                        </button>
                    </div>
                </div>
            </section>

            <section class="audit-log-table-section" aria-labelledby="audit-table-heading">
                <h2 id="audit-table-heading" class="visually-hidden"><?php p($l->t('Einträge im Aktivitätsprotokoll')); ?></h2>
                <div class="table-responsive" role="region" aria-label="<?php p($l->t('Aktivitätsprotokoll')); ?>">
                    <table class="table audit-log-table" id="audit-log-table" role="table" aria-label="<?php p($l->t('Aktivitätsprotokoll')); ?>">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Datum und Uhrzeit')); ?></th>
                                <th scope="col"><?php p($l->t('Mitarbeiter')); ?></th>
                                <th scope="col"><?php p($l->t('Aktion')); ?></th>
                                <th scope="col"><?php p($l->t('Was geändert wurde')); ?></th>
                                <th scope="col"><?php p($l->t('Wer es getan hat')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="audit-log-tbody">
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center audit-log-empty">
                                        <div class="empty-state">
                                            <h3 class="empty-state__title"><?php p($l->t('Keine Aktivitäten gefunden')); ?></h3>
                                            <p class="empty-state__description">
                                                <?php p($l->t('Für den ausgewählten Zeitraum wurden keine Aktivitäten protokolliert.')); ?>
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach (($logs ?? []) as $log): ?>
                                <tr>
                                    <td data-label="<?php p($l->t('Datum und Uhrzeit')); ?>"><?php p($log['createdAt'] ?? '-'); ?></td>
                                    <td data-label="<?php p($l->t('Mitarbeiter')); ?>"><?php p($log['userDisplayName'] ?? $log['userId']); ?></td>
                                    <td data-label="<?php p($l->t('Aktion')); ?>"><?php p($log['action']); ?></td>
                                    <td data-label="<?php p($l->t('Was geändert wurde')); ?>"><?php p($log['entityType']); ?></td>
                                    <td data-label="<?php p($l->t('Wer es getan hat')); ?>"><?php p($log['performedByDisplayName'] ?? $log['performedBy'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <p class="audit-log-pagination" aria-live="polite">
                    <?php p($l->t('Es werden %1$d von %2$d Einträgen angezeigt', [count($logs ?? []), $total ?? 0])); ?>
                </p>
            </section>
        </main>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->
