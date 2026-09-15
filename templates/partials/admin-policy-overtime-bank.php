<?php
declare(strict_types=1);
/**
 * Partial: admin-policy-overtime-bank.php
 * Expects: $l, $settings, $urlGenerator (where needed), $absenceTypes/$eventTypes for HR, $premiumNightPreset for premiums.
 * @license AGPL-3.0-or-later
 */
/** @var \OCP\IL10N $l */
/** @var array $settings */
?>
				<section class="azc-card azc-admin-policy-section admin-settings-section" aria-labelledby="overtime-bank-heading">
                    <header class="azc-card__header">
                        <div class="azc-card__header-text">
                            <h2 id="overtime-bank-heading" class="azc-card__title"><?php p($l->t('Overtime bank')); ?></h2>
                            <p class="azc-card__lead"><?php p($l->t('Employees can accumulate overtime up to a maximum (bank). Hours above the cap can be paid out at month end via Admin → Overtime payouts.')); ?></p>
                        </div>
                    </header>
                    <div class="azc-card__body">
					<div class="form-group">
						<div class="form-checkbox">
							<input type="checkbox"
								id="overtimeBankEnabled"
								name="overtimeBankEnabled"
								<?php echo ($settings['overtimeBankEnabled'] ?? false) ? 'checked' : ''; ?>
								aria-describedby="overtimeBankEnabled-help"
								aria-controls="overtime-bank-settings">
							<label for="overtimeBankEnabled" class="form-label">
								<?php p($l->t('Enable overtime bank (cap + month-end payout)')); ?>
							</label>
						</div>
						<p id="overtimeBankEnabled-help" class="form-help">
							<?php p($l->t('When enabled, the dashboard shows banked hours and payroll can record payouts above the cap.')); ?>
						</p>
					</div>
					<div id="overtime-bank-settings" class="admin-notifications-dependent-block">
					<div class="form-row form-row--thresholds">
						<div class="form-group">
							<label for="overtimeBankMaxHours" class="form-label"><?php p($l->t('Maximum banked overtime (hours)')); ?></label>
							<input type="number" class="form-input" id="overtimeBankMaxHours" name="overtimeBankMaxHours" min="1" max="500" step="0.25" value="<?php p((string)($settings['overtimeBankMaxHours'] ?? 100)); ?>">
						</div>
						<div class="form-group">
							<label for="overtimeBankYellowPercent" class="form-label"><?php p($l->t('Bank fill yellow from (%%)')); ?></label>
							<input type="number" class="form-input" id="overtimeBankYellowPercent" name="overtimeBankYellowPercent" min="0" max="100" step="1" value="<?php p((string)($settings['overtimeBankYellowPercent'] ?? 80)); ?>">
						</div>
						<div class="form-group">
							<label for="overtimeBankRedPercent" class="form-label"><?php p($l->t('Bank fill red from (%%)')); ?></label>
							<input type="number" class="form-input" id="overtimeBankRedPercent" name="overtimeBankRedPercent" min="0" max="100" step="1" value="<?php p((string)($settings['overtimeBankRedPercent'] ?? 95)); ?>">
						</div>
					</div>
					<h3 class="admin-settings-subsection__title"><?php p($l->t('After payout')); ?></h3>
					<div class="form-group">
						<div class="form-checkbox">
							<input type="checkbox" id="overtimePayoutNotifyInApp" name="overtimePayoutNotifyInApp" value="1"
								<?php echo ($settings['overtimePayoutNotifyInApp'] ?? true) ? 'checked' : ''; ?>>
							<label for="overtimePayoutNotifyInApp" class="form-label">
								<?php p($l->t('Notify employee in the app when payout is recorded')); ?>
							</label>
						</div>
					</div>
					<div class="form-group">
						<div class="form-checkbox">
							<input type="checkbox" id="overtimePayoutNotifyEmail" name="overtimePayoutNotifyEmail" value="1"
								<?php echo ($settings['overtimePayoutNotifyEmail'] ?? true) ? 'checked' : ''; ?>>
							<label for="overtimePayoutNotifyEmail" class="form-label">
								<?php p($l->t('Email employee when payout is recorded (requires valid email address)')); ?>
							</label>
						</div>
					</div>
					<nav class="admin-overtime-quicklinks" aria-label="<?php p($l->t('Overtime payroll shortcuts')); ?>">
						<p class="admin-overtime-quicklinks__label"><?php p($l->t('Next step')); ?></p>
						<a class="azc-btn azc-btn--secondary azc-btn--sm" href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.overtime_payout.index')); ?>">
							<?php p($l->t('Open payouts')); ?>
						</a>
					</nav>
					<div class="form-group">
						<div class="form-checkbox">
							<input type="checkbox"
								id="overtimeBlockMonthClosurePendingPayout"
								name="overtimeBlockMonthClosurePendingPayout"
								value="1"
								<?php echo ($settings['overtimeBlockMonthClosurePendingPayout'] ?? false) ? 'checked' : ''; ?>
								aria-describedby="overtimeBlockMonthClosurePendingPayout-help">
							<label for="overtimeBlockMonthClosurePendingPayout" class="form-label">
								<?php p($l->t('Block month finalization until overtime payout is recorded')); ?>
							</label>
						</div>
						<p id="overtimeBlockMonthClosurePendingPayout-help" class="form-help">
							<?php p($l->t('When enabled, employees cannot seal a month while hours above the bank cap are still unpaid for that month.')); ?>
						</p>
					</div>
					</div>
                    </div>
				</section>

				<section class="azc-card azc-admin-policy-section admin-settings-section" aria-labelledby="paid-absence-credit-heading">
					<header class="azc-card__header">
						<div class="azc-card__header-text">
							<h2 id="paid-absence-credit-heading" class="azc-card__title"><?php p($l->t('Sick leave and planned hours')); ?></h2>
							<p class="azc-card__lead"><?php p($l->t('Optional Entgeltausfall / Ausfallprinzip for the flextime balance. Off by default so existing balances stay unchanged.')); ?></p>
						</div>
					</header>
					<div class="azc-card__body">
						<div class="form-group">
							<div class="form-checkbox">
								<input type="checkbox"
									id="paidAbsencePlannedHoursCreditEnabled"
									name="paidAbsencePlannedHoursCreditEnabled"
									value="1"
									<?php echo ($settings['paidAbsencePlannedHoursCreditEnabled'] ?? false) ? 'checked' : ''; ?>
									aria-describedby="paidAbsencePlannedHoursCreditEnabled-help">
								<label for="paidAbsencePlannedHoursCreditEnabled" class="form-label">
									<?php p($l->t('Credit planned hours for approved sick leave')); ?>
								</label>
							</div>
							<p id="paidAbsencePlannedHoursCreditEnabled-help" class="form-help">
								<?php p($l->t('When enabled, approved sick leave credits the planned net hours for that day into the overtime balance (weekday schedule or duty roster, including planned Saturday/Sunday shifts). Free weekends stay at 0. Enabling recalculates the live balance for past sick days in open periods — confirm this matches your Betriebsvereinbarung or Tarifvertrag. Unpaid leave is never credited. Vacation and other leave types are not included yet.')); ?>
							</p>
						</div>
					</div>
				</section>
