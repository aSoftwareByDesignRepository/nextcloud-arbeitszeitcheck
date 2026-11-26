<?php
declare(strict_types=1);
/**
 * Partial: admin-policy-hr-office.php
 * Expects: $l, $settings, $urlGenerator (where needed), $absenceTypes/$eventTypes for HR, $premiumNightPreset for premiums.
 * @license AGPL-3.0-or-later
 */
/** @var \OCP\IL10N $l */
/** @var array $settings */
?>
				<section class="azc-card azc-admin-policy-section admin-settings-section" aria-labelledby="hr-notifications-heading">
                    <header class="azc-card__header">
                        <div class="azc-card__header-text">
                            <h2 id="hr-notifications-heading" class="azc-card__title"><?php p($l->t('HR office')); ?></h2>
                            <p class="azc-card__lead"><?php p($l->t('When HR gets email updates for absence workflows.')); ?></p>
                        </div>
                    </header>
                    <div class="azc-card__body">
					<input type="hidden" id="hrNotificationRevision" name="hrNotificationRevision"
						value="<?php p((string)($settings['hrNotificationRevision'] ?? '')); ?>">
					<h3 id="block-hr-setup-heading" class="admin-settings-subsection__title"><?php p($l->t('General HR notification setup')); ?></h3>
					<div class="form-group">
						<div class="form-checkbox">
							<input type="checkbox"
								id="hrNotificationsEnabled"
								name="hrNotificationsEnabled"
								<?php echo ($settings['enabled'] ?? false) ? 'checked' : ''; ?>
								aria-describedby="hrNotificationsEnabled-help"
								aria-controls="hr-notification-settings">
							<label for="hrNotificationsEnabled" class="form-label">
								<?php p($l->t('Enable HR office email notifications')); ?>
							</label>
						</div>
						<p id="hrNotificationsEnabled-help" class="form-help">
							<?php p($l->t('When enabled, selected workflow events send email updates to the configured HR recipients.')); ?>
						</p>
					</div>

					<div id="hr-notification-settings" class="admin-notifications-dependent-block">
					<div class="form-group">
						<label for="hrRecipients" class="form-label"><?php p($l->t('HR office recipients (comma separated emails)')); ?></label>
						<textarea
							id="hrRecipients"
							name="hrRecipients"
							rows="3"
							class="form-input"
							placeholder="<?php p($l->t('hr@example.com, office@example.com')); ?>"
							aria-describedby="hrRecipients-help"><?php p((string)($settings['recipients'] ?? '')); ?></textarea>
						<p id="hrRecipients-help" class="form-help">
							<?php p($l->t('Use valid email addresses separated by commas. Duplicates are removed automatically.')); ?>
						</p>
					</div>

					<h3 id="notification-matrix-heading" class="admin-settings-subsection__title"><?php p($l->t('Rules by absence type and event')); ?></h3>
					<p class="form-help form-help--block">
						<?php p($l->t('Activate exactly which event should trigger an HR email for each absence type. Disabled cells mean no email is sent for that combination.')); ?>
					</p>
					<div class="table-container azc-table-wrap admin-notifications-matrix-wrap"
						role="region"
						tabindex="0"
						aria-label="<?php p($l->t('HR notification matrix')); ?>">
						<table class="grid-table admin-notifications-matrix azc-table--matrix" role="table" aria-labelledby="notification-matrix-heading">
							<caption class="sr-only"><?php p($l->t('Notification rules by absence type and event')); ?></caption>
							<thead>
								<tr>
									<th scope="col"><?php p($l->t('Absence type')); ?></th>
									<?php foreach ($eventTypes as $event): ?>
										<th scope="col"><?php p($event['label'] ?? (string)$event['key']); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php
								$builtinTypes = array_values(array_filter($absenceTypes, static fn ($t) => empty($t['custom'])));
								$customTypes = array_values(array_filter($absenceTypes, static fn ($t) => !empty($t['custom'])));
								// A-R6: more than a handful of custom rows collapse behind one toggle
								// (progressive disclosure — the matrix stays scannable).
								$customCollapsed = count($customTypes) > 6;
								$renderMatrixRow = function (array $type, bool $hidden = false) use ($eventTypes, $settings, $l): void {
									$typeKey = (string)($type['key'] ?? '');
									?>
									<tr<?php if ($hidden) { ?> class="admin-notifications-matrix__custom-row" hidden<?php } ?>>
										<th scope="row">
											<?php p($type['label'] ?? $typeKey); ?>
											<?php if (!empty($type['custom'])): ?>
												<span class="azc-badge azc-badge--neutral admin-notifications-matrix__custom-badge"><?php p($l->t('Custom type')); ?></span>
											<?php endif; ?>
										</th>
										<?php foreach ($eventTypes as $event): ?>
											<?php
											$eventKey = (string)($event['key'] ?? '');
											$enabled = !empty($settings['matrix'][$typeKey][$eventKey]);
											$inputId = 'rule_' . $typeKey . '_' . $eventKey;
											?>
											<td>
												<div class="form-checkbox form-checkbox--center">
													<input type="checkbox"
														id="<?php p($inputId); ?>"
														name="matrix[<?php p($typeKey); ?>][<?php p($eventKey); ?>]"
														<?php echo $enabled ? 'checked' : ''; ?>
														aria-label="<?php p($l->t('%1$s -> %2$s', [$type['label'] ?? $typeKey, $event['label'] ?? $eventKey])); ?>">
												</div>
											</td>
										<?php endforeach; ?>
									</tr>
									<?php
								};
								foreach ($builtinTypes as $type) {
									$renderMatrixRow($type);
								}
								if ($customCollapsed): ?>
									<tr class="admin-notifications-matrix__custom-toggle">
										<td colspan="<?php p(count($eventTypes) + 1); ?>">
											<button type="button"
												class="azc-btn azc-btn--secondary admin-notifications-matrix__custom-toggle-btn"
												aria-expanded="false"
												data-label-show="<?php p($l->t('Show %d custom absence types', [count($customTypes)])); ?>"
												data-label-hide="<?php p($l->t('Hide custom absence types')); ?>">
												<?php p($l->t('Show %d custom absence types', [count($customTypes)])); ?>
											</button>
										</td>
									</tr>
								<?php endif;
								foreach ($customTypes as $type) {
									$renderMatrixRow($type, $customCollapsed);
								}
								?>
							</tbody>
						</table>
					</div>
					</div>
                    </div>
				</section>
