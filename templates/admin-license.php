<?php

declare(strict_types=1);

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$license = is_array($_['license'] ?? null) ? $_['license'] : null;
$mobileUsed = (int)($_['mobileSeatsUsed'] ?? 0);
$mobileLimit = (int)($_['mobileSeatsLimit'] ?? 0);
$terminalUsed = (int)($_['terminalDevicesUsed'] ?? 0);
$terminalLimit = (int)($_['terminalDevicesLimit'] ?? 0);
$mobileSeats = is_array($_['mobileSeats'] ?? null) ? $_['mobileSeats'] : [];
$showMobile = !empty($_['showMobileSeats']);
$showTerminal = !empty($_['showTerminal']);
$instanceId = (string)($_['instanceId'] ?? '');
$licenseRenewMailto = (string)($_['licenseRenewMailto'] ?? 'mailto:info@software-by-design.de');
$productsUrl = (string)($_['productsUrl'] ?? 'https://nextcloud.software-by-design.de/');
$kioskAdminUrl = (string)($_['kioskAdminUrl'] ?? '');
$apiLicenseUrl = (string)($_['apiLicenseUrl'] ?? '');
$apiClearLicenseUrl = (string)($_['apiClearLicenseUrl'] ?? '');
$apiSeatsUrl = (string)($_['apiSeatsUrl'] ?? '');
$apiSeatsBatchUrl = (string)($_['apiSeatsBatchUrl'] ?? '');
$apiRemoveSeatUrl = (string)($_['apiRemoveSeatUrl'] ?? '');
$apiSearchUsersUrl = (string)($_['apiSearchUsersUrl'] ?? '');
$requesttoken = (string)($_['requesttoken'] ?? '');
$i18n = is_array($_['i18n'] ?? null) ? $_['i18n'] : [];
$i18nJson = json_encode($i18n, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
if (!is_string($i18nJson)) {
	$i18nJson = '{}';
}

$hasLicense = $license !== null;
$dateValid = $hasLicense && !empty($license['dateValid']);
$cryptoValid = $hasLicense && !empty($license['cryptographicallyValid']);
$isActive = $hasLicense && !empty($license['active']);
$cryptoInvalid = $dateValid && !$cryptoValid;
$validUntil = $hasLicense ? (string)($license['validUntil'] ?? '') : '';
$customerId = $hasLicense ? (string)($license['customerId'] ?? '') : '';
$expiresSoon = false;
if ($validUntil !== '') {
	$untilDt = DateTimeImmutable::createFromFormat('Y-m-d', $validUntil);
	$today = new DateTimeImmutable('today');
	if ($untilDt !== false) {
		$daysLeft = (int)$today->diff($untilDt)->format('%r%a');
		$expiresSoon = $daysLeft >= 0 && $daysLeft <= 30;
	}
}

$formatAssignedAt = static function (string $iso) use ($l): string {
	if ($iso === '') {
		return '—';
	}
	try {
		$dt = new DateTimeImmutable($iso);
		return $dt->format('d.m.Y H:i');
	} catch (\Exception) {
		return $iso;
	}
};

$mobilePct = $mobileLimit > 0 ? min(100, (int)round(($mobileUsed / $mobileLimit) * 100)) : 0;
$terminalPct = $terminalLimit > 0 ? min(100, (int)round(($terminalUsed / $terminalLimit) * 100)) : 0;
$mobileFull = $mobileLimit > 0 && $mobileUsed >= $mobileLimit;

/** @return list<array{href: string, label: string, class: string, target?: string, rel?: string}> */
$licenseContactActions = static function (\OCP\IL10N $l) use ($licenseRenewMailto, $productsUrl): array {
	return [
		[
			'href' => $licenseRenewMailto,
			'label' => $l->t('Purchase or renew license'),
			'class' => 'azc-btn azc-btn--secondary azc-btn--sm',
		],
		[
			'href' => $productsUrl,
			'label' => $l->t('Software by Design — Nextcloud Apps'),
			'class' => 'azc-btn azc-btn--secondary azc-btn--sm',
			'target' => '_blank',
			'rel' => 'noopener noreferrer',
		],
	];
};
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack azc-license-page" id="azc-license-page"
	data-api-license="<?php p($apiLicenseUrl); ?>"
	data-api-clear-license="<?php p($apiClearLicenseUrl); ?>"
	data-api-seats="<?php p($apiSeatsUrl); ?>"
	data-api-seats-batch="<?php p($apiSeatsBatchUrl); ?>"
	data-api-remove-seat="<?php p($apiRemoveSeatUrl); ?>"
	data-api-search-users="<?php p($apiSearchUsersUrl); ?>"
	data-i18n="<?php p($i18nJson); ?>"
	data-requesttoken="<?php p($requesttoken); ?>">

	<div id="azc-license-live" class="azc-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="azc-license-alert" class="azc-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>

	<div id="azc-license-feedback" class="azc-license-feedback" role="alert" hidden></div>

	<?php if ($cryptoInvalid): ?>
		<?php
		$calloutVariant = 'warning';
		$calloutRole = 'alert';
		$calloutId = 'azc-license-crypto-callout';
		$calloutTitleId = 'azc-license-crypto-title';
		$calloutTitle = $l->t('License signature cannot be verified');
		$calloutText = $l->t('A license key is stored, but this server cannot verify its signature with the vendor public key. Mobile and Terminal apps will reject the license until the key was signed with the matching vendor signing key. Re-apply a correctly signed license, or contact support if this persists after an app update.');
		$calloutExtraClass = 'azc-license-crypto-callout';
		include __DIR__ . '/common/alert-callout.php';
		?>
	<?php endif; ?>

	<?php if (!$hasLicense): ?>
		<?php
		$calloutVariant = 'info';
		$calloutRole = 'region';
		$calloutId = 'azc-license-no-key-callout';
		$calloutTitleId = 'azc-license-no-key-title';
		$calloutTitle = $l->t('No license yet');
		$calloutText = $l->t('Paste your AZC2 license key below to unlock the Mobile and Terminal apps. The web app stays free.');
		$calloutExtraClass = 'azc-license-no-key-callout';
		$calloutActions = $licenseContactActions($l);
		include __DIR__ . '/common/alert-callout.php';
		?>
	<?php endif; ?>

	<section class="azc-license-overview" aria-label="<?php p($l->t('License overview')); ?>">
		<div class="azc-stat-strip azc-license-stat-grid">
			<article class="azc-stat-tile azc-license-stat <?php echo $isActive ? 'azc-stat-tile--success' : ($hasLicense ? 'azc-stat-tile--warning' : 'azc-stat-tile--neutral'); ?>" aria-labelledby="azc-license-stat-status-label">
				<p id="azc-license-stat-status-label" class="azc-stat-tile__label"><?php p($l->t('Status')); ?></p>
				<p class="azc-stat-tile__value">
					<span id="azc-license-active-badge" class="azc-badge <?php echo $isActive ? 'azc-badge--success' : 'azc-badge--warning'; ?>"
						data-active-label="<?php p($l->t('Active')); ?>"
						data-inactive-label="<?php p($l->t('Expired or invalid')); ?>"
						data-signature-invalid-label="<?php p($l->t('Signature mismatch')); ?>">
						<?php
						if ($isActive) {
							p($l->t('Active'));
						} elseif ($cryptoInvalid) {
							p($l->t('Signature mismatch'));
						} elseif ($hasLicense) {
							p($l->t('Expired or invalid'));
						} else {
							p($l->t('Not configured'));
						}
						?>
					</span>
				</p>
				<?php if ($hasLicense): ?>
				<p class="azc-stat-tile__meta">
					<span class="azc-license-stat__meta-label"><?php p($l->t('Valid until')); ?>:</span>
					<span id="azc-license-valid-until"><?php p($validUntil !== '' ? $validUntil : '—'); ?></span>
				</p>
				<?php endif; ?>
			</article>

			<?php if ($showMobile || !$hasLicense): ?>
			<article class="azc-stat-tile azc-stat-tile--primary azc-license-stat" aria-labelledby="azc-license-stat-mobile-label">
				<p id="azc-license-stat-mobile-label" class="azc-stat-tile__label"><?php p($l->t('Mobile seats')); ?></p>
				<p class="azc-stat-tile__value">
					<span id="azc-license-mobile-used"><?php p((string)$mobileUsed); ?></span>
					<span class="azc-stat-tile__sep" aria-hidden="true">/</span>
					<span id="azc-license-mobile-limit"><?php p((string)$mobileLimit); ?></span>
				</p>
				<div class="azc-license-meter" role="meter"
					aria-valuemin="0"
					aria-valuemax="<?php p((string)max(1, $mobileLimit)); ?>"
					aria-valuenow="<?php p((string)$mobileUsed); ?>"
					aria-label="<?php p($l->t('Mobile seats used')); ?>">
					<div class="azc-license-meter__fill <?php echo $mobileFull ? 'azc-license-meter__fill--full' : ''; ?>"
						id="azc-license-mobile-meter"
						style="width: <?php p((string)$mobilePct); ?>%;"></div>
				</div>
			</article>
			<?php endif; ?>

			<?php if ($showTerminal || !$hasLicense): ?>
			<article class="azc-stat-tile azc-stat-tile--primary azc-license-stat" aria-labelledby="azc-license-stat-terminal-label">
				<p id="azc-license-stat-terminal-label" class="azc-stat-tile__label"><?php p($l->t('Terminal devices')); ?></p>
				<p class="azc-stat-tile__value">
					<span id="azc-license-terminal-used"><?php p((string)$terminalUsed); ?></span>
					<span class="azc-stat-tile__sep" aria-hidden="true">/</span>
					<span id="azc-license-terminal-limit"><?php p((string)$terminalLimit); ?></span>
				</p>
				<div class="azc-license-meter" role="meter"
					aria-valuemin="0"
					aria-valuemax="<?php p((string)max(1, $terminalLimit)); ?>"
					aria-valuenow="<?php p((string)$terminalUsed); ?>"
					aria-label="<?php p($l->t('Terminal devices in use')); ?>">
					<div class="azc-license-meter__fill"
						id="azc-license-terminal-meter"
						style="width: <?php p((string)$terminalPct); ?>%;"></div>
				</div>
			</article>
			<?php endif; ?>
		</div>
	</section>

	<section class="azc-card azc-license-section" aria-labelledby="azc-license-key-heading">
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="azc-license-key-heading" class="azc-card__title"><?php p($l->t('Organisation license')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Paste the license key you received after purchase. The web app stays free — this key unlocks Mobile and Terminal apps for your organisation.')); ?></p>
			</div>
		</header>
		<div class="azc-card__body">
		<div class="azc-license-form">
			<label for="azc-license-key-input" class="azc-field__label"><?php p($l->t('License key')); ?></label>
			<textarea id="azc-license-key-input"
				class="azc-license-key-input azc-input"
				name="licenseKey"
				rows="4"
				spellcheck="false"
				autocomplete="off"
				aria-describedby="azc-license-key-hint"
				placeholder="AZC2.…"></textarea>
			<p id="azc-license-key-hint" class="azc-field__hint"><?php p($l->t('Format: AZC2 followed by a signed payload. One key per organisation.')); ?></p>
			<?php if ($instanceId !== ''): ?>
			<div class="azc-license-instance-box" role="note" aria-labelledby="azc-license-instance-label">
				<p id="azc-license-instance-label" class="azc-field__label"><?php p($l->t('Nextcloud instance ID')); ?></p>
				<p class="azc-field__hint"><?php p($l->t('Provide this ID when ordering a license so the key can be bound to this server.')); ?></p>
				<code class="azc-license-instance-id" id="azc-license-instance-id"><?php p($instanceId); ?></code>
			</div>
			<?php endif; ?>
			<div class="azc-license-form__actions">
				<button type="button" id="azc-license-save" class="azc-btn azc-btn--primary">
					<?php p($l->t('Save license')); ?>
				</button>
				<?php if ($hasLicense): ?>
				<button type="button" id="azc-license-clear" class="azc-btn azc-btn--danger">
					<?php p($l->t('Remove license')); ?>
				</button>
				<?php endif; ?>
			</div>
		</div>

		<div id="azc-license-status" class="azc-license-status" <?php echo $hasLicense ? '' : 'hidden'; ?>>
			<h3 class="azc-license-status__title"><?php p($l->t('Current license')); ?></h3>
			<dl class="azc-license-status__grid">
				<div class="azc-license-status__item">
					<dt><?php p($l->t('Customer ID')); ?></dt>
					<dd id="azc-license-customer"><?php p($customerId); ?></dd>
				</div>
			</dl>
			<?php if ($expiresSoon && $isActive): ?>
				<?php
				$calloutVariant = 'warning';
				$calloutRole = 'note';
				$calloutTitle = $l->t('License expires soon');
				$calloutText = $l->t('Your license expires within 30 days. Contact your vendor to renew.');
				$calloutExtraClass = 'azc-license-expiry-callout';
				$calloutActions = $licenseContactActions($l);
				include __DIR__ . '/common/alert-callout.php';
				?>
			<?php else: ?>
			<div class="azc-license-status__purchase">
				<?php foreach ($licenseContactActions($l) as $action): ?>
				<a href="<?php p($action['href']); ?>"
					class="<?php p($action['class']); ?>"
					<?php if (!empty($action['target'])) { ?>target="<?php p($action['target']); ?>"<?php } ?>
					<?php if (!empty($action['rel'])) { ?>rel="<?php p($action['rel']); ?>"<?php } ?>>
					<?php p($action['label']); ?>
				</a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
		</div><!-- /.azc-card__body -->
	</section>

	<?php if ($showMobile): ?>
	<section class="azc-card azc-license-section" id="azc-mobile-seats-section" aria-labelledby="azc-mobile-seats-heading">
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="azc-mobile-seats-heading" class="azc-card__title"><?php p($l->t('Mobile seats')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Choose which employees may use the ArbeitszeitCheck Mobile app. Only assigned users can clock in from their phone.')); ?></p>
			</div>
		</header>
		<div class="azc-card__body">
		<div class="azc-license-seat-picker">
			<label for="azc-seat-user-search" class="azc-field__label"><?php p($l->t('Add employee')); ?></label>
			<div class="azc-license-search-wrap">
				<input type="search"
					id="azc-seat-user-search"
					class="azc-input azc-license-search-input"
					autocomplete="off"
					placeholder="<?php p($l->t('Search by name or login…')); ?>"
					aria-describedby="azc-seat-picker-hint azc-seat-count"
					aria-controls="azc-seat-search-results"
					aria-expanded="false"
					aria-autocomplete="list"
					role="combobox"
					<?php echo $mobileFull ? 'disabled aria-disabled="true"' : ''; ?>>
				<ul id="azc-seat-search-results" class="azc-seat-search-results" role="listbox" hidden></ul>
			</div>
			<p id="azc-seat-picker-hint" class="azc-field__hint"><?php p($l->t('Type at least two characters, tick several people, then assign them together.')); ?></p>
			<p id="azc-seat-count" class="azc-license-seat-count" aria-live="polite">
				<?php p($l->t('%1$d of %2$d seats assigned', [$mobileUsed, $mobileLimit])); ?>
			</p>
			<button type="button" id="azc-seat-assign-selected" class="azc-btn azc-btn--primary" hidden disabled
				aria-live="polite">
				<?php p($l->t('Assign selected')); ?>
			</button>
			<p id="azc-seats-full-hint" class="azc-field__hint azc-license-seats-full-hint" role="status" <?php echo $mobileFull ? '' : 'hidden'; ?>>
				<?php p($l->t('All mobile seats are assigned. Remove a user or upgrade your license.')); ?>
			</p>
			<div id="azc-seat-batch-result" class="azc-callout azc-callout--info" role="status" aria-live="polite" hidden></div>
		</div>

		<div class="table-container azc-license-seats-table-wrap">
			<table class="table table--hover azc-table--responsive azc-license-seats-table" aria-labelledby="azc-mobile-seats-heading">
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Employee')); ?></th>
						<th scope="col"><?php p($l->t('User ID')); ?></th>
						<th scope="col"><?php p($l->t('Assigned')); ?></th>
						<th scope="col" class="azc-table-actions-col"><span class="azc-sr-only"><?php p($l->t('Actions')); ?></span></th>
					</tr>
				</thead>
				<tbody id="azc-seat-list-body">
					<?php foreach ($mobileSeats as $seat): ?>
						<tr data-user-id="<?php p((string)($seat['userId'] ?? '')); ?>">
							<td data-label="<?php p($l->t('Employee')); ?>"><?php p((string)($seat['displayName'] ?? '')); ?></td>
							<td data-label="<?php p($l->t('User ID')); ?>"><code class="azc-license-user-id"><?php p((string)($seat['userId'] ?? '')); ?></code></td>
							<td data-label="<?php p($l->t('Assigned')); ?>"><?php p($formatAssignedAt((string)($seat['assignedAt'] ?? ''))); ?></td>
							<td data-label="<?php p($l->t('Actions')); ?>" class="actions-cell">
								<button type="button" class="azc-btn azc-btn--secondary azc-btn--small azc-seat-remove" data-user-id="<?php p((string)($seat['userId'] ?? '')); ?>">
									<?php p($l->t('Remove')); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div id="azc-seat-empty" class="azc-empty-state" <?php echo count($mobileSeats) > 0 ? 'hidden' : ''; ?>>
				<p class="azc-empty-state__title"><?php p($l->t('No mobile seats assigned yet.')); ?></p>
				<p class="azc-empty-state__text"><?php p($l->t('Search for an employee above to grant Mobile app access.')); ?></p>
			</div>
		</div>
		</div><!-- /.azc-card__body -->
	</section>
	<?php endif; ?>

	<?php if ($showTerminal): ?>
	<section class="azc-card azc-license-section" aria-labelledby="azc-terminal-heading">
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="azc-terminal-heading" class="azc-card__title"><?php p($l->t('Terminal devices')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Pair kiosk tablets from the Kiosk admin area. Each paired device uses one license slot.')); ?></p>
			</div>
			<?php if ($kioskAdminUrl !== ''): ?>
			<div class="azc-card__header-actions">
				<a href="<?php p($kioskAdminUrl); ?>" class="azc-btn azc-btn--primary">
					<?php p($l->t('Open kiosk administration')); ?>
				</a>
			</div>
			<?php endif; ?>
		</header>
		<div class="azc-card__body">
			<p class="azc-license-terminal-summary">
				<?php p($l->t('%1$d of %2$d terminal slots in use.', [$terminalUsed, $terminalLimit])); ?>
			</p>
		</div>
	</section>
	<?php endif; ?>

	<div id="azc-license-clear-backdrop" class="azc-license-modal-backdrop" hidden></div>
	<div id="azc-license-clear-modal" class="azc-license-modal" hidden role="dialog" aria-modal="true" aria-labelledby="azc-license-clear-title">
		<h3 id="azc-license-clear-title"><?php p($l->t('Remove license')); ?></h3>
		<p class="azc-license-modal__hint"><?php p($l->t('Remove the organisation license and revoke all mobile seats and kiosk terminals? This cannot be undone.')); ?></p>
		<div class="azc-license-modal__actions">
			<button type="button" id="azc-license-clear-cancel" class="azc-btn azc-btn--secondary"><?php p($l->t('Cancel')); ?></button>
			<button type="button" id="azc-license-clear-confirm" class="azc-btn azc-btn--danger"><?php p($l->t('Remove license')); ?></button>
		</div>
	</div>
</div>

<?php include __DIR__ . '/common/page-end.php'; ?>
