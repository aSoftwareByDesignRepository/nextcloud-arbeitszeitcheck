<?php

declare(strict_types=1);

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$kioskEnabled = !empty($_['kioskEnabled']);
$terminals = is_array($_['terminals'] ?? null) ? $_['terminals'] : [];
$terminalUsed = (int)($_['terminalDevicesUsed'] ?? 0);
$terminalLimit = (int)($_['terminalDevicesLimit'] ?? 0);
$licenseAdminUrl = (string)($_['licenseAdminUrl'] ?? '');
$requesttoken = (string)($_['requesttoken'] ?? '');
$enrollmentTtlSeconds = (int)($_['enrollmentTtlSeconds'] ?? 300);
$i18n = is_array($_['i18n'] ?? null) ? $_['i18n'] : [];
$i18nJson = json_encode($i18n, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
if (!is_string($i18nJson)) {
	$i18nJson = '{}';
}

$activeTerminals = [];
foreach ($terminals as $t) {
	if (($t['status'] ?? '') === 'active') {
		$activeTerminals[] = $t;
	}
}
$activeTerminalCount = count($activeTerminals);

$statusLabel = static function (string $status) use ($l, $i18n): string {
	return match ($status) {
		'active' => (string)($i18n['statusActive'] ?? $l->t('Active')),
		'pending' => (string)($i18n['statusPending'] ?? $l->t('Pending pairing')),
		'revoked' => (string)($i18n['statusRevoked'] ?? $l->t('Revoked')),
		default => $status,
	};
};

$statusBadgeClass = static function (string $status): string {
	return match ($status) {
		'active' => 'azc-badge azc-badge--success',
		'pending' => 'azc-badge azc-badge--warning',
		default => 'azc-badge azc-badge--neutral',
	};
};

$meterPct = $terminalLimit > 0 ? min(100, (int)round(($terminalUsed / $terminalLimit) * 100)) : 0;
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack azc-kiosk-page" id="azc-kiosk-page"
	data-api-enabled="<?php p((string)($_['apiKioskEnabled'] ?? '')); ?>"
	data-api-terminals="<?php p((string)($_['apiTerminals'] ?? '')); ?>"
	data-api-credentials="<?php p((string)($_['apiCredentials'] ?? '')); ?>"
	data-api-rfid="<?php p((string)($_['apiRfid'] ?? '')); ?>"
	data-api-pin="<?php p((string)($_['apiPinGenerate'] ?? '')); ?>"
	data-api-enrollment-start="<?php p((string)($_['apiEnrollmentStart'] ?? '')); ?>"
	data-api-enrollment-status="<?php p((string)($_['apiEnrollmentStatus'] ?? '')); ?>"
	data-api-enrollment-cancel="<?php p((string)($_['apiEnrollmentCancel'] ?? '')); ?>"
	data-api-search-users="<?php p((string)($_['apiSearchUsers'] ?? '')); ?>"
	data-api-terminal-revoke="<?php p((string)($_['apiTerminalRevoke'] ?? '')); ?>"
	data-api-user-allowed="<?php p((string)($_['apiUserAllowed'] ?? '')); ?>"
	data-enrollment-ttl="<?php p((string)$enrollmentTtlSeconds); ?>"
	data-i18n="<?php p($i18nJson); ?>"
	data-requesttoken="<?php p($requesttoken); ?>">

	<div id="azc-kiosk-live" class="azc-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="azc-kiosk-alert" class="azc-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="azc-kiosk-feedback" class="azc-kiosk-feedback" role="status" hidden></div>

	<section class="azc-stat-strip azc-kiosk-overview" aria-label="<?php p($l->t('Kiosk status overview')); ?>">
		<article id="azc-kiosk-stat-mode" class="azc-stat-tile azc-kiosk-stat <?php echo $kioskEnabled ? 'azc-kiosk-stat--on' : 'azc-kiosk-stat--off'; ?>">
			<span class="azc-stat-tile__label"><?php p($l->t('Kiosk mode')); ?></span>
			<span class="azc-stat-tile__value" id="azc-kiosk-overview-mode"><?php p($kioskEnabled ? $l->t('On') : $l->t('Off')); ?></span>
			<span class="azc-stat-tile__meta"><?php p($l->t('Foyer tablet clocking')); ?></span>
		</article>
		<article class="azc-stat-tile azc-stat-tile--primary azc-kiosk-stat">
			<span class="azc-stat-tile__label"><?php p($l->t('Active tablets')); ?></span>
			<span class="azc-stat-tile__value" id="azc-kiosk-overview-active"><?php p((string)$activeTerminalCount); ?></span>
			<span class="azc-stat-tile__meta"><?php p($l->t('Ready for badge scans')); ?></span>
		</article>
		<article class="azc-stat-tile azc-kiosk-stat">
			<span class="azc-stat-tile__label"><?php p($l->t('License slots')); ?></span>
			<span class="azc-stat-tile__value">
				<span id="azc-kiosk-overview-used"><?php p((string)$terminalUsed); ?></span><span class="azc-stat-tile__sep" aria-hidden="true">/</span><span id="azc-kiosk-overview-limit"><?php p((string)$terminalLimit); ?></span>
			</span>
			<span class="azc-stat-tile__meta"><?php p($l->t('Terminal devices used')); ?></span>
		</article>
		<article class="azc-stat-tile azc-kiosk-stat">
			<span class="azc-stat-tile__label"><?php p($l->t('All terminals')); ?></span>
			<span class="azc-stat-tile__value" id="azc-kiosk-overview-total"><?php p((string)count($terminals)); ?></span>
			<span class="azc-stat-tile__meta"><?php p($l->t('Including pending / revoked')); ?></span>
		</article>
	</section>

	<section class="azc-card azc-kiosk-panel" aria-labelledby="azc-kiosk-enable-heading">
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="azc-kiosk-enable-heading" class="azc-card__title"><?php p($l->t('Kiosk mode')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Turn foyer tablet clocking on or off. Needs a Terminal license and at least one paired tablet.')); ?></p>
			</div>
		</header>
		<div class="azc-card__body">
			<label class="azc-kiosk-toggle" for="azc-kiosk-enabled">
				<input type="checkbox" id="azc-kiosk-enabled" <?php echo $kioskEnabled ? 'checked' : ''; ?>
					aria-describedby="azc-kiosk-enable-hint">
				<span><?php p($l->t('Kiosk enabled')); ?></span>
			</label>
			<div class="azc-kiosk-capacity" id="azc-kiosk-enable-hint">
				<p class="azc-kiosk-capacity__label">
					<?php p($l->t('Terminal devices')); ?>:
					<strong><span id="azc-kiosk-terminal-used"><?php p((string)$terminalUsed); ?></span></strong>
					<span class="azc-kiosk-capacity__sep" aria-hidden="true">/</span>
					<strong><span id="azc-kiosk-terminal-limit"><?php p((string)$terminalLimit); ?></span></strong>
				</p>
				<div class="azc-kiosk-meter" role="meter"
					aria-valuemin="0"
					aria-valuenow="<?php p((string)$terminalUsed); ?>"
					aria-valuemax="<?php p((string)max(1, $terminalLimit)); ?>"
					aria-label="<?php p($l->t('Terminal devices used')); ?>">
					<div class="azc-kiosk-meter__fill<?php echo $terminalUsed >= $terminalLimit && $terminalLimit > 0 ? ' azc-kiosk-meter__fill--full' : ''; ?>"
						style="width: <?php p((string)$meterPct); ?>%" id="azc-kiosk-terminal-meter"></div>
				</div>
				<?php if ($licenseAdminUrl !== ''): ?>
				<p class="azc-kiosk-capacity__link">
					<a href="<?php p($licenseAdminUrl); ?>"><?php p($l->t('Manage license')); ?></a>
				</p>
				<?php endif; ?>
			</div>

			<details class="azc-kiosk-help">
				<summary class="azc-kiosk-help__summary"><?php p($l->t('How to set up a tablet')); ?></summary>
				<div class="azc-kiosk-help__body">
					<ol class="azc-kiosk-help__steps">
						<li><?php p($l->t('Enable kiosk mode above and create a terminal with a pairing code.')); ?></li>
						<li><?php p($l->t('On the tablet: enter your Nextcloud URL (https://…) and the pairing code.')); ?></li>
						<li><?php p($l->t('In the app guide: pick sign-in methods, set a secret admin PIN, and choose NFC or an external RFID reader.')); ?></li>
						<li><?php p($l->t('Here under “Badges & PIN”: allow access, generate PINs, or start “Scan badge at tablet” (the chip UID is read automatically — you never look it up).')); ?></li>
						<li><?php p($l->t('Lock the tablet to ArbeitszeitCheck Terminal (Android screen pinning or MDM kiosk mode).')); ?></li>
						<li><?php p($l->t('To change tablet settings later: press and hold the large title at the top of the home screen for 5 seconds, then enter the admin PIN. There is no public Setup button.')); ?></li>
					</ol>
				</div>
			</details>
		</div>
	</section>

	<section class="azc-card azc-kiosk-panel" aria-labelledby="azc-kiosk-terminals-heading">
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="azc-kiosk-terminals-heading" class="azc-card__title"><?php p($l->t('Terminals')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Paired foyer tablets. Revoke a device if it is lost or replaced.')); ?></p>
			</div>
			<div class="azc-card__header-actions">
				<button type="button" id="azc-kiosk-open-create" class="azc-btn azc-btn--primary">
					<?php p($l->t('Add a tablet')); ?>
				</button>
			</div>
		</header>
		<div class="azc-card__body">
			<div id="azc-kiosk-create-backdrop" class="azc-kiosk-modal-backdrop" hidden></div>
			<div id="azc-kiosk-create-modal" class="azc-kiosk-modal" hidden role="dialog" aria-modal="true" aria-labelledby="azc-kiosk-create-heading">
				<header class="azc-kiosk-modal__header">
					<h2 id="azc-kiosk-create-heading" class="azc-kiosk-modal__title"><?php p($l->t('Add a tablet')); ?></h2>
					<button type="button" class="azc-kiosk-modal__dismiss" data-azc-modal-close
						aria-label="<?php p($l->t('Close')); ?>">
						<span aria-hidden="true">&times;</span>
					</button>
				</header>
				<div class="azc-kiosk-modal__body">
					<p class="azc-kiosk-modal__lead"><?php p($l->t('Give this foyer tablet a clear name. After you create it, a pairing code appears once — enter it on the tablet within 10 minutes.')); ?></p>
					<div class="azc-kiosk-modal__field">
						<label for="azc-kiosk-terminal-label" class="azc-kiosk-modal__label"><?php p($l->t('Tablet name')); ?></label>
						<input type="text" id="azc-kiosk-terminal-label" class="azc-input azc-kiosk-modal__input" maxlength="128"
							autocomplete="off"
							placeholder="<?php p($l->t('e.g. Main entrance')); ?>">
					</div>
				</div>
				<footer class="azc-kiosk-modal__footer">
					<button type="button" id="azc-kiosk-create-close" class="azc-btn" data-azc-modal-close>
						<?php p($l->t('Cancel')); ?>
					</button>
					<button type="button" id="azc-kiosk-create-terminal" class="azc-btn azc-btn--primary">
						<?php p($l->t('Create tablet')); ?>
					</button>
				</footer>
			</div>

			<div id="azc-kiosk-pairing-backdrop" class="azc-kiosk-modal-backdrop" hidden></div>
			<div id="azc-kiosk-pairing-modal" class="azc-kiosk-modal" hidden role="dialog" aria-modal="true" aria-labelledby="azc-kiosk-pairing-title">
				<header class="azc-kiosk-modal__header">
					<h2 id="azc-kiosk-pairing-title" class="azc-kiosk-modal__title"><?php p($l->t('Pairing code')); ?></h2>
					<button type="button" class="azc-kiosk-modal__dismiss" data-azc-modal-close
						aria-label="<?php p($l->t('Close')); ?>">
						<span aria-hidden="true">&times;</span>
					</button>
				</header>
				<div class="azc-kiosk-modal__body">
					<p class="azc-kiosk-modal__lead"><?php p($l->t('Enter this code on the tablet within 10 minutes. It is shown only once.')); ?></p>
					<p class="azc-kiosk-modal__secret" id="azc-kiosk-pairing-code" aria-live="polite"></p>
					<p class="azc-kiosk-modal__meta" id="azc-kiosk-pairing-expires" hidden></p>
				</div>
				<footer class="azc-kiosk-modal__footer">
					<button type="button" id="azc-kiosk-pairing-close" class="azc-btn azc-btn--primary" data-azc-modal-close>
						<?php p($l->t('Done')); ?>
					</button>
				</footer>
			</div>

			<div class="azc-table-wrap">
				<table class="azc-table azc-kiosk-table" id="azc-kiosk-terminals-table">
					<caption class="azc-sr-only"><?php p($l->t('Registered kiosk terminals')); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php p($l->t('Label')); ?></th>
							<th scope="col"><?php p($l->t('Status')); ?></th>
							<th scope="col"><?php p($l->t('Last seen')); ?></th>
							<th scope="col"><?php p($l->t('Actions')); ?></th>
						</tr>
					</thead>
					<tbody id="azc-kiosk-terminals-body">
						<?php if ($terminals === []): ?>
						<tr class="azc-kiosk-empty-row">
							<td colspan="4"><?php p($l->t('No terminals yet. Click “Add a tablet” to get a pairing code.')); ?></td>
						</tr>
						<?php endif; ?>
						<?php foreach ($terminals as $t): ?>
						<?php
						$tid = (string)($t['terminalId'] ?? '');
						$status = (string)($t['status'] ?? '');
						$canRevoke = $status === 'active' || $status === 'pending';
						$lastSeen = (string)($t['lastSeenAt'] ?? '');
						?>
						<tr data-terminal-id="<?php p($tid); ?>">
							<td data-label="<?php p($l->t('Label')); ?>"><?php p((string)($t['label'] ?? '')); ?></td>
							<td data-label="<?php p($l->t('Status')); ?>">
								<span class="<?php p($statusBadgeClass($status)); ?>"><?php p($statusLabel($status)); ?></span>
							</td>
							<td data-label="<?php p($l->t('Last seen')); ?>" class="azc-kiosk-last-seen" data-iso="<?php p($lastSeen); ?>">
								<?php p($lastSeen !== '' ? $lastSeen : (string)($i18n['neverSeen'] ?? $l->t('Never'))); ?>
							</td>
							<td data-label="<?php p($l->t('Actions')); ?>">
								<?php if ($canRevoke): ?>
								<button type="button" class="azc-btn azc-btn--small azc-btn--danger azc-kiosk-revoke-terminal"
									data-terminal-id="<?php p($tid); ?>">
									<?php p($l->t('Revoke')); ?>
								</button>
								<?php else: ?>
								<span class="azc-kiosk-no-action" aria-hidden="true">—</span>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>

	<section class="azc-card azc-kiosk-panel" aria-labelledby="azc-kiosk-creds-heading">
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="azc-kiosk-creds-heading" class="azc-card__title" tabindex="-1"><?php p($l->t('Badges & PIN')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Find an employee, allow kiosk access, then create a PIN or scan their badge at a tablet.')); ?></p>
				<p class="azc-kiosk-tip">
					<?php p($l->t('Tip: From an employee’s profile under Employees you can jump here with that person already selected.')); ?>
				</p>
			</div>
		</header>
		<div class="azc-card__body">

		<details class="azc-kiosk-help">
			<summary class="azc-kiosk-help__summary"><?php p($l->t('How to assign a PIN or badge')); ?></summary>
			<div class="azc-kiosk-help__body">
				<div class="azc-kiosk-help__topics">
					<div class="azc-kiosk-help__topic">
						<h3 class="azc-kiosk-help__topic-title"><?php p($l->t('PIN (name + digits)')); ?></h3>
						<ol class="azc-kiosk-help__steps">
							<li><?php p($l->t('Search the employee below and turn on “Allow kiosk access”.')); ?></li>
							<li><?php p($l->t('Click “Generate PIN”. A new 6-digit PIN is created by the server.')); ?></li>
							<li><?php p($l->t('The PIN is shown once — copy it and give it to the employee securely. It cannot be looked up later.')); ?></li>
						</ol>
					</div>
					<div class="azc-kiosk-help__topic">
						<h3 class="azc-kiosk-help__topic-title"><?php p($l->t('Badge / RFID / NFC')); ?></h3>
						<ol class="azc-kiosk-help__steps">
							<li><?php p($l->t('You do not type or look up chip numbers. The tablet reads them.')); ?></li>
							<li><?php p($l->t('Select the employee, allow kiosk access, choose an active terminal, then click “Scan badge at tablet”.')); ?></li>
							<li><?php p($l->t('On that tablet, hold the card on NFC or the USB reader. The UID is stored as a secure hash — never shown in the admin UI.')); ?></li>
						</ol>
						<p class="azc-kiosk-help__note"><?php p($l->t('Printed numbers on the card are often not the same as the NFC/RFID UID. Always enroll by scanning.')); ?></p>
					</div>
				</div>
			</div>
		</details>

		<div class="azc-kiosk-flow" id="azc-kiosk-wizard">
			<div class="azc-kiosk-flow__block" id="azc-kiosk-step-find" data-step="1">
				<p class="azc-kiosk-flow__label" id="azc-kiosk-find-heading">
					<span class="azc-kiosk-flow__step" aria-hidden="true">1</span>
					<label for="azc-kiosk-user-search"><?php p($l->t('Find employee')); ?></label>
				</p>
				<p class="azc-kiosk-flow__hint"><?php p($l->t('Type at least 2 letters of the name or login, then pick the person from the list.')); ?></p>
				<div class="azc-kiosk-search" id="azc-kiosk-search">
					<input type="search" id="azc-kiosk-user-search" class="azc-input azc-kiosk-search__input" autocomplete="off"
						role="combobox"
						aria-autocomplete="list"
						aria-expanded="false"
						aria-controls="azc-kiosk-user-results"
						aria-haspopup="listbox"
						placeholder="<?php p($l->t('Search by name…')); ?>">
					<input type="hidden" id="azc-kiosk-selected-user" value="">
					<ul id="azc-kiosk-user-results" class="azc-kiosk-search__results" role="listbox" hidden></ul>
				</div>
			</div>

			<div class="azc-kiosk-flow__block azc-kiosk-flow__block--muted" id="azc-kiosk-step-allow" data-step="2" aria-disabled="true">
				<p class="azc-kiosk-flow__label">
					<span class="azc-kiosk-flow__step" aria-hidden="true">2</span>
					<span><?php p($l->t('Allow kiosk access')); ?></span>
				</p>
				<p class="azc-kiosk-flow__hint" id="azc-kiosk-step-allow-hint">
					<?php p($l->t('Select an employee first. Then switch on access for that person.')); ?>
				</p>
				<div id="azc-kiosk-selected-panel" class="azc-kiosk-person" hidden>
					<div class="azc-kiosk-person__who">
						<span class="azc-kiosk-person__eyebrow"><?php p($l->t('Selected employee')); ?></span>
						<p class="azc-kiosk-person__name" id="azc-kiosk-selected-name"></p>
					</div>
					<label class="azc-kiosk-toggle" for="azc-kiosk-selected-allowed">
						<input type="checkbox" id="azc-kiosk-selected-allowed">
						<span><?php p($l->t('Allow kiosk access')); ?></span>
					</label>
				</div>
			</div>

			<div class="azc-kiosk-flow__block azc-kiosk-flow__block--muted" id="azc-kiosk-step-assign" data-step="3" aria-disabled="true">
				<p class="azc-kiosk-flow__label" id="azc-kiosk-assign-heading">
					<span class="azc-kiosk-flow__step" aria-hidden="true">3</span>
					<span><?php p($l->t('Assign a badge or PIN')); ?></span>
				</p>
				<p class="azc-kiosk-flow__hint" id="azc-kiosk-step-assign-hint">
					<?php p($l->t('Allow kiosk access in step 2, then choose PIN or badge scan.')); ?>
				</p>

				<div class="azc-kiosk-assign-grid" id="azc-kiosk-assign-grid" hidden>
					<div class="azc-kiosk-assign-card">
						<h3 class="azc-kiosk-assign-card__title"><?php p($l->t('PIN')); ?></h3>
						<p class="azc-kiosk-assign-card__text"><?php p($l->t('Creates a new 6-digit PIN. Shown only once — share it securely.')); ?></p>
						<button type="button" id="azc-kiosk-generate-pin" class="azc-btn azc-btn--primary" disabled aria-disabled="true">
							<?php p($l->t('Generate PIN')); ?>
						</button>
					</div>
					<div class="azc-kiosk-assign-card">
						<h3 class="azc-kiosk-assign-card__title"><?php p($l->t('Badge scan')); ?></h3>
						<p class="azc-kiosk-assign-card__text"><?php p($l->t('Starts listening on the chosen tablet. Hold the badge to NFC or the USB reader there.')); ?></p>
						<?php if ($activeTerminalCount === 0): ?>
							<p class="azc-kiosk-assign-card__warn" id="azc-kiosk-no-terminal-warn" role="status">
								<?php p($l->t('No active tablet yet. Add and pair a tablet under Terminals first, then come back here to scan.')); ?>
								<a href="#azc-kiosk-terminals-heading"><?php p($l->t('Go to Terminals')); ?></a>
							</p>
						<?php else: ?>
							<label for="azc-kiosk-enroll-terminal" class="azc-field__label"><?php p($l->t('Tablet for this scan')); ?></label>
							<select id="azc-kiosk-enroll-terminal" class="azc-input" <?php echo $activeTerminalCount === 1 ? '' : ''; ?>>
								<?php if ($activeTerminalCount > 1): ?>
								<option value=""><?php p($l->t('Select tablet…')); ?></option>
								<?php endif; ?>
								<?php foreach ($activeTerminals as $t): ?>
								<option value="<?php p((string)($t['terminalId'] ?? '')); ?>"
									<?php echo $activeTerminalCount === 1 ? ' selected' : ''; ?>>
									<?php p((string)($t['label'] ?? '')); ?>
								</option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
						<div class="azc-kiosk-assign-card__actions">
							<button type="button" id="azc-kiosk-start-enrollment" class="azc-btn azc-btn--primary"
								<?php echo $activeTerminalCount === 0 ? ' disabled aria-disabled="true"' : ' disabled aria-disabled="true"'; ?>>
								<?php p($l->t('Scan badge at tablet')); ?>
							</button>
							<button type="button" id="azc-kiosk-cancel-enrollment"
								class="azc-btn azc-btn--danger"
								hidden
								aria-label="<?php p($l->t('Cancel scan')); ?>">
								<?php p($l->t('Cancel scan')); ?>
							</button>
						</div>
					</div>
				</div>

				<div id="azc-kiosk-enrollment-panel" class="azc-kiosk-enrollment-panel" hidden role="status" aria-live="polite">
					<p class="azc-kiosk-enrollment-panel__title" id="azc-kiosk-enrollment-title"></p>
					<p class="azc-kiosk-enrollment-panel__body" id="azc-kiosk-enrollment-status"></p>
					<p class="azc-kiosk-enrollment-panel__timer" id="azc-kiosk-enrollment-timer" hidden></p>
					<ol class="azc-kiosk-enrollment-panel__steps" id="azc-kiosk-enrollment-steps" hidden>
						<li><?php p($l->t('Walk to the selected tablet (or ask a colleague there).')); ?></li>
						<li><?php p($l->t('The tablet shows that it is waiting for a badge.')); ?></li>
						<li><?php p($l->t('Hold the badge flat on the NFC area or the USB reader until you hear/see confirmation.')); ?></li>
						<li><?php p($l->t('This page updates automatically when the badge is saved.')); ?></li>
					</ol>
				</div>
			</div>
		</div>

		<div id="azc-kiosk-pin-backdrop" class="azc-kiosk-modal-backdrop" hidden></div>
		<div id="azc-kiosk-pin-modal" class="azc-kiosk-modal" hidden role="dialog" aria-modal="true" aria-labelledby="azc-kiosk-pin-title">
			<header class="azc-kiosk-modal__header">
				<h2 id="azc-kiosk-pin-title" class="azc-kiosk-modal__title"><?php p($l->t('PIN generated')); ?></h2>
				<button type="button" class="azc-kiosk-modal__dismiss" data-azc-modal-close
					aria-label="<?php p($l->t('Close')); ?>">
					<span aria-hidden="true">&times;</span>
				</button>
			</header>
			<div class="azc-kiosk-modal__body">
				<p class="azc-kiosk-modal__lead"><?php p($l->t('PIN is shown only once. Share it securely with the employee.')); ?></p>
				<p class="azc-kiosk-modal__secret" id="azc-kiosk-pin-code" aria-live="polite"></p>
				<div class="azc-kiosk-pin-actions" role="group" aria-label="<?php p($l->t('Share PIN')); ?>">
					<button type="button" id="azc-kiosk-pin-copy" class="azc-btn azc-btn--primary">
						<?php p($l->t('Copy PIN')); ?>
					</button>
					<button type="button" id="azc-kiosk-pin-share" class="azc-btn azc-btn--secondary" hidden>
						<?php p($l->t('Share…')); ?>
					</button>
					<a id="azc-kiosk-pin-email" class="azc-btn azc-btn--secondary" href="#" hidden>
						<?php p($l->t('Send by email')); ?>
					</a>
				</div>
				<p id="azc-kiosk-pin-share-status" class="azc-kiosk-pin-share-status" role="status" aria-live="polite"></p>
			</div>
			<footer class="azc-kiosk-modal__footer">
				<button type="button" id="azc-kiosk-pin-close" class="azc-btn azc-btn--secondary" data-azc-modal-close>
					<?php p($l->t('Done')); ?>
				</button>
			</footer>
		</div>

		<div class="azc-kiosk-creds-list">
			<h3 class="azc-kiosk-creds-list__title" id="azc-kiosk-creds-list-heading"><?php p($l->t('Already assigned')); ?></h3>
			<p class="azc-kiosk-creds-list__lead"><?php p($l->t('Everyone who already has a badge or PIN. Use the row buttons to create a new PIN or remove credentials.')); ?></p>
			<div class="azc-table-wrap">
				<table class="azc-table azc-kiosk-table" id="azc-kiosk-creds-table" aria-labelledby="azc-kiosk-creds-list-heading">
					<caption class="azc-sr-only"><?php p($l->t('Kiosk credentials')); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php p($l->t('Employee')); ?></th>
							<th scope="col"><?php p($l->t('Credentials')); ?></th>
							<th scope="col"><?php p($l->t('Kiosk allowed')); ?></th>
							<th scope="col"><?php p($l->t('Actions')); ?></th>
						</tr>
					</thead>
					<tbody id="azc-kiosk-creds-body"></tbody>
				</table>
			</div>
		</div>
		</div>
	</section>
</div>

<?php include __DIR__ . '/common/page-end.php'; ?>
