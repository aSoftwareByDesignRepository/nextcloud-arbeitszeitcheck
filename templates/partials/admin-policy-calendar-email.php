<?php
declare(strict_types=1);
/**
 * Partial: admin-policy-calendar-email.php
 * Expects: $l, $settings, $urlGenerator (where needed), $absenceTypes/$eventTypes for HR, $premiumNightPreset for premiums.
 * @license AGPL-3.0-or-later
 */
/** @var \OCP\IL10N $l */
/** @var array $settings */
?>
				<section class="azc-card azc-admin-policy-section admin-settings-section" aria-labelledby="section-absence-workflow-heading">
                    <header class="azc-card__header">
                        <div class="azc-card__header-text">
                            <h2 id="section-absence-workflow-heading" class="azc-card__title"><?php p($l->t('Calendar & email')); ?></h2>
                            <p class="azc-card__lead"><?php p($l->t('iCal attachments and substitution emails when absences are approved.')); ?></p>
                        </div>
                    </header>
                    <div class="azc-card__body">
					<div class="azc-settings-subsection" role="group" aria-labelledby="send-ical-heading">
						<h3 id="send-ical-heading" class="admin-settings-subsection__title"><?php p($l->t('Absences: Send iCal via email')); ?></h3>
						<p class="form-help form-help--block">
							<?php p($l->t('For approved absences, an email with an iCal attachment (.ics) can be sent automatically.')); ?>
						</p>
						<p class="form-help form-help--block form-help--note">
							<?php p($l->t('Important: This is best-effort email delivery, not a guaranteed real-time calendar sync. Delivery can be delayed or fail due to mail server/network issues. Source of truth remains ArbeitszeitCheck.')); ?>
						</p>
						<p class="form-help form-help--block form-help--note">
							<?php p($l->t('Privacy note: To reduce sensitive data exposure, iCal details for substitutes/managers intentionally avoid private absence reasons.')); ?>
						</p>
						<div class="form-group">
							<div class="form-checkbox">
								<input type="checkbox" id="sendIcalApprovedAbsences" name="sendIcalApprovedAbsences" value="1"
									<?php echo ($settings['sendIcalApprovedAbsences'] ?? true) ? 'checked' : ''; ?>
									aria-describedby="send-ical-heading">
								<label for="sendIcalApprovedAbsences" class="form-label">
									<?php p($l->t('Send iCal to the person with approved absence')); ?>
								</label>
							</div>
						</div>
						<div class="form-group">
							<div class="form-checkbox">
								<input type="checkbox" id="sendIcalToSubstitute" name="sendIcalToSubstitute" value="1"
									<?php echo ($settings['sendIcalToSubstitute'] ?? false) ? 'checked' : ''; ?>
									aria-describedby="send-ical-heading">
								<label for="sendIcalToSubstitute" class="form-label">
									<?php p($l->t('Also send iCal to substitute (if selected)')); ?>
								</label>
							</div>
						</div>
						<div class="form-group">
							<div class="form-checkbox">
								<input type="checkbox" id="sendIcalToManagers" name="sendIcalToManagers" value="1"
									<?php echo ($settings['sendIcalToManagers'] ?? false) ? 'checked' : ''; ?>
									aria-describedby="send-ical-heading">
								<label for="sendIcalToManagers" class="form-label">
									<?php p($l->t('Also send iCal to managers (team managers)')); ?>
								</label>
							</div>
						</div>
					</div>

					<div class="azc-settings-subsection" role="group" aria-labelledby="email-notifications-heading">
						<h3 id="email-notifications-heading" class="admin-settings-subsection__title"><?php p($l->t('Absences: Email notifications for substitution workflow')); ?></h3>
						<p class="form-help form-help--block">
							<?php p($l->t('When a substitute is selected, emails can be sent at each step of the approval process.')); ?>
						</p>
						<div class="form-group">
							<div class="form-checkbox">
								<input type="checkbox" id="sendEmailSubstitutionRequest" name="sendEmailSubstitutionRequest" value="1"
									<?php echo ($settings['sendEmailSubstitutionRequest'] ?? true) ? 'checked' : ''; ?>
									aria-describedby="email-notifications-heading">
								<label for="sendEmailSubstitutionRequest" class="form-label">
									<?php p($l->t('Email substitute when a substitution request is created')); ?>
								</label>
							</div>
						</div>
						<div class="form-group">
							<div class="form-checkbox">
								<input type="checkbox" id="sendEmailSubstituteApprovedToEmployee" name="sendEmailSubstituteApprovedToEmployee" value="1"
									<?php echo ($settings['sendEmailSubstituteApprovedToEmployee'] ?? true) ? 'checked' : ''; ?>
									aria-describedby="email-notifications-heading">
								<label for="sendEmailSubstituteApprovedToEmployee" class="form-label">
									<?php p($l->t('Email employee when substitute approves')); ?>
								</label>
							</div>
						</div>
						<div class="form-group">
							<div class="form-checkbox">
								<input type="checkbox" id="sendEmailSubstituteApprovedToManager" name="sendEmailSubstituteApprovedToManager" value="1"
									<?php echo ($settings['sendEmailSubstituteApprovedToManager'] ?? true) ? 'checked' : ''; ?>
									aria-describedby="email-notifications-heading">
								<label for="sendEmailSubstituteApprovedToManager" class="form-label">
									<?php p($l->t('Email managers when substitute approves (requires app teams)')); ?>
								</label>
							</div>
						</div>
					</div>

					<div class="azc-settings-subsection" role="group" aria-labelledby="manager-pending-email-heading">
						<h3 id="manager-pending-email-heading" class="admin-settings-subsection__title"><?php p($l->t('Email managers about open approvals')); ?></h3>
						<p class="form-help form-help--block">
							<?php p($l->t('Team managers can receive email when employees submit absences, manual time entries, or corrections that need approval. Requires app teams with managers assigned.')); ?>
						</p>
						<div class="form-group">
							<div class="form-checkbox">
								<input type="checkbox" id="managerPendingEmailAbsences" name="managerPendingEmailAbsences" value="1"
									<?php echo ($settings['managerPendingEmailAbsences'] ?? true) ? 'checked' : ''; ?>
									aria-describedby="manager-pending-email-heading">
								<label for="managerPendingEmailAbsences" class="form-label">
									<?php p($l->t('Absences / vacation requests')); ?>
								</label>
							</div>
						</div>
						<div class="form-group">
							<div class="form-checkbox">
								<input type="checkbox" id="managerPendingEmailManualEntries" name="managerPendingEmailManualEntries" value="1"
									<?php echo ($settings['managerPendingEmailManualEntries'] ?? true) ? 'checked' : ''; ?>
									aria-describedby="manager-pending-email-heading">
								<label for="managerPendingEmailManualEntries" class="form-label">
									<?php p($l->t('Manual time entries awaiting approval')); ?>
								</label>
							</div>
						</div>
						<div class="form-group">
							<div class="form-checkbox">
								<input type="checkbox" id="managerPendingEmailCorrections" name="managerPendingEmailCorrections" value="1"
									<?php echo ($settings['managerPendingEmailCorrections'] ?? true) ? 'checked' : ''; ?>
									aria-describedby="manager-pending-email-heading">
								<label for="managerPendingEmailCorrections" class="form-label">
									<?php p($l->t('Time entry corrections')); ?>
								</label>
							</div>
						</div>
						<div class="form-group">
							<label for="managerPendingEmailMode" class="form-label"><?php p($l->t('Delivery')); ?></label>
							<select id="managerPendingEmailMode" name="managerPendingEmailMode" class="form-select" aria-describedby="manager-pending-email-mode-help">
								<option value="immediate" <?php echo (($settings['managerPendingEmailMode'] ?? 'immediate') === 'immediate') ? 'selected' : ''; ?>>
									<?php p($l->t('Immediate email for each request')); ?>
								</option>
								<option value="digest" <?php echo (($settings['managerPendingEmailMode'] ?? '') === 'digest') ? 'selected' : ''; ?>>
									<?php p($l->t('Daily summary of open approvals')); ?>
								</option>
							</select>
							<p id="manager-pending-email-mode-help" class="form-help">
								<?php p($l->t('Daily summary is sent once per day and only when something is still waiting. Immediate emails are best when volume is low.')); ?>
							</p>
						</div>
					</div>
                    </div>
				</section>
