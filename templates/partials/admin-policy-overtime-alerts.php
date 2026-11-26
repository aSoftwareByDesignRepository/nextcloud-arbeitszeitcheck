<?php
declare(strict_types=1);
/**
 * Partial: admin-policy-overtime-alerts.php
 * Expects: $l, $settings, $urlGenerator (where needed), $absenceTypes/$eventTypes for HR, $premiumNightPreset for premiums.
 * @license AGPL-3.0-or-later
 */
/** @var \OCP\IL10N $l */
/** @var array $settings */
?>
				<section class="azc-card azc-admin-policy-section admin-settings-section" aria-labelledby="overtime-trafficlight-heading">
                    <header class="azc-card__header">
                        <div class="azc-card__header-text">
                            <h2 id="overtime-trafficlight-heading" class="azc-card__title"><?php p($l->t('Overtime alerts')); ?></h2>
                            <p class="azc-card__lead"><?php p($l->t('Yellow/red thresholds and who gets overtime or undertime alerts.')); ?></p>
                        </div>
                    </header>
                    <div class="azc-card__body">
					<input type="hidden" id="overtimeNotificationRevision" name="overtimeNotificationRevision"
						value="<?php p((string)($settings['overtimeNotificationRevision'] ?? '')); ?>">
					<h3 id="block-trafficlight-recipients-heading" class="admin-settings-subsection__title"><?php p($l->t('Activation and recipients')); ?></h3>
					<div class="form-group">
						<div class="form-checkbox">
							<input type="checkbox"
								id="overtimeTrafficLightEnabled"
								name="overtimeTrafficLightEnabled"
								<?php echo ($settings['overtimeTrafficLightEnabled'] ?? false) ? 'checked' : ''; ?>
								aria-describedby="overtimeTrafficLightEnabled-help"
								aria-controls="overtime-trafficlight-settings">
							<label for="overtimeTrafficLightEnabled" class="form-label">
								<?php p($l->t('Enable overtime traffic light notifications')); ?>
							</label>
						</div>
						<p id="overtimeTrafficLightEnabled-help" class="form-help">
							<?php p($l->t('When enabled, transitions to yellow or red levels can trigger in-app and email notifications.')); ?>
						</p>
					</div>

					<div id="overtime-trafficlight-settings" class="admin-notifications-dependent-block">
					<p class="admin-settings-subsection__intro form-help form-help--note"><?php p($l->t('Define when overtime changes from green to yellow and yellow to red (hours).')); ?></p>
					<div class="form-row form-row--thresholds" role="group" aria-labelledby="block-trafficlight-recipients-heading">
						<div class="form-group">
							<label for="overtimeYellowOver" class="form-label"><?php p($l->t('Overtime yellow threshold (hours)')); ?></label>
							<input type="number" class="form-input" id="overtimeYellowOver" name="overtimeYellowOver" min="0" max="500" step="0.25" value="<?php p((string)($settings['overtimeYellowOver'] ?? 5)); ?>">
						</div>
						<div class="form-group">
							<label for="overtimeRedOver" class="form-label"><?php p($l->t('Overtime red threshold (hours)')); ?></label>
							<input type="number" class="form-input" id="overtimeRedOver" name="overtimeRedOver" min="0" max="500" step="0.25" value="<?php p((string)($settings['overtimeRedOver'] ?? 15)); ?>">
						</div>
					</div>

					<p class="admin-settings-subsection__intro form-help form-help--note"><?php p($l->t('Define equivalent thresholds for undertime (negative balance).')); ?></p>
					<div class="form-row form-row--thresholds" role="group" aria-label="<?php p($l->t('Undertime thresholds')); ?>">
						<div class="form-group">
							<label for="overtimeYellowUnder" class="form-label"><?php p($l->t('Undertime yellow threshold (hours)')); ?></label>
							<input type="number" class="form-input" id="overtimeYellowUnder" name="overtimeYellowUnder" min="0" max="500" step="0.25" value="<?php p((string)($settings['overtimeYellowUnder'] ?? 5)); ?>">
						</div>
						<div class="form-group">
							<label for="overtimeRedUnder" class="form-label"><?php p($l->t('Undertime red threshold (hours)')); ?></label>
							<input type="number" class="form-input" id="overtimeRedUnder" name="overtimeRedUnder" min="0" max="500" step="0.25" value="<?php p((string)($settings['overtimeRedUnder'] ?? 15)); ?>">
						</div>
					</div>

					<div class="form-group">
						<label for="overtimeRecipients" class="form-label"><?php p($l->t('Balance traffic light recipients (overtime + undertime, comma separated emails)')); ?></label>
						<textarea
							id="overtimeRecipients"
							name="overtimeRecipients"
							rows="3"
							class="form-input"
							placeholder="<?php p($l->t('lead@example.com, hr@example.com')); ?>"
							aria-describedby="overtimeRecipients-help"><?php p((string)($settings['overtimeRecipients'] ?? '')); ?></textarea>
						<p id="overtimeRecipients-help" class="form-help">
							<?php p($l->t('These recipients are used for both overtime and undertime alerts. Use valid email addresses separated by commas. Duplicates are removed automatically.')); ?>
						</p>
					</div>

					<h3 id="block-trafficlight-matrix-heading" class="admin-settings-subsection__title"><?php p($l->t('Notification matrix')); ?></h3>
					<p class="form-help form-help--block">
						<?php p($l->t('Choose which severity levels should trigger notifications for overtime and undertime.')); ?>
					</p>
					<div class="table-container azc-table-wrap admin-notifications-matrix-wrap"
						role="region"
						tabindex="0"
						aria-label="<?php p($l->t('Overtime alert matrix')); ?>">
						<table class="grid-table admin-notifications-matrix azc-table--matrix" role="table" aria-labelledby="block-trafficlight-matrix-heading">
							<caption class="sr-only"><?php p($l->t('Severity levels that trigger notifications for overtime and undertime')); ?></caption>
							<thead>
								<tr>
									<th scope="col"><?php p($l->t('Direction')); ?></th>
									<th scope="col"><?php p($l->t('Yellow notifications')); ?></th>
									<th scope="col"><?php p($l->t('Red notifications')); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<th scope="row"><?php p($l->t('Overtime')); ?></th>
									<td>
										<div class="form-checkbox form-checkbox--center">
											<input type="checkbox" name="overtimeMatrix[over][yellow]" <?php echo !empty($settings['overtimeMatrix']['over']['yellow']) ? 'checked' : ''; ?> aria-label="<?php p($l->t('Notify on overtime yellow')); ?>">
										</div>
									</td>
									<td>
										<div class="form-checkbox form-checkbox--center">
											<input type="checkbox" name="overtimeMatrix[over][red]" <?php echo !empty($settings['overtimeMatrix']['over']['red']) ? 'checked' : ''; ?> aria-label="<?php p($l->t('Notify on overtime red')); ?>">
										</div>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php p($l->t('Undertime')); ?></th>
									<td>
										<div class="form-checkbox form-checkbox--center">
											<input type="checkbox" name="overtimeMatrix[under][yellow]" <?php echo !empty($settings['overtimeMatrix']['under']['yellow']) ? 'checked' : ''; ?> aria-label="<?php p($l->t('Notify on undertime yellow')); ?>">
										</div>
									</td>
									<td>
										<div class="form-checkbox form-checkbox--center">
											<input type="checkbox" name="overtimeMatrix[under][red]" <?php echo !empty($settings['overtimeMatrix']['under']['red']) ? 'checked' : ''; ?> aria-label="<?php p($l->t('Notify on undertime red')); ?>">
										</div>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					</div>
                    </div>
				</section>
