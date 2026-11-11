<?php
/**
 * Settings sub-page: Transfer configuration (test → production).
 * Two cards — Export (passphrase → .nctransfer download) and Import
 * (mode → file → passphrase → mandatory dry-run → confirm) — plus the
 * "Last transfer" receipt line. The report dialog is a native <dialog>.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$isSystemAdmin = !empty($_['isSystemAdmin']);
$lastReceipt = is_array($_['transferLastReceipt'] ?? null) ? $_['transferLastReceipt'] : null;
?>
<section class="azc-card azc-section" aria-labelledby="azc-xfer-export-h" id="azc-transfer-export-card">
	<header class="azc-section__header">
		<div>
			<h2 id="azc-xfer-export-h"><?php p($l->t('Export configuration')); ?></h2>
			<p class="azc-section__sub"><?php p($l->t('Download all settings, catalogues and business data as one encrypted file — then import it on your production instance.')); ?></p>
		</div>
	</header>
	<div class="azc-callout azc-callout--info" role="note">
		<p><strong><?php p($l->t('Secrets are never included.')); ?></strong>
		<?php p($l->t('Passwords, tokens and salts stay on this instance — the file is encrypted and still does not carry them. It does contain user and group names: handle it like personal data.')); ?></p>
	</div>
	<form id="azc-transfer-export-form" class="azc-form" novalidate>
		<div class="azc-form-row">
			<label for="azc-transfer-passphrase"><?php p($l->t('Encryption passphrase')); ?></label>
			<div class="azc-input-group">
				<input type="password" id="azc-transfer-passphrase" name="passphrase" autocomplete="new-password" required minlength="12" aria-describedby="azc-transfer-passphrase-hint azc-transfer-passphrase-strength">
				<button type="button" class="azc-btn azc-btn--ghost" id="azc-transfer-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
				<button type="button" class="azc-btn azc-btn--secondary" id="azc-transfer-passphrase-generate"><?php p($l->t('Generate')); ?></button>
			</div>
			<p class="azc-form-row__hint" id="azc-transfer-passphrase-hint"><?php p($l->t('At least 12 characters. Without this passphrase the file cannot be restored — store it safely.')); ?></p>
			<p class="azc-form-row__hint azc-transfer-strength" id="azc-transfer-passphrase-strength" role="status" aria-live="polite"></p>
		</div>
		<div class="azc-actions">
			<button type="submit" class="azc-btn azc-btn--primary" id="azc-transfer-export-btn">
				<?php p($l->t('Download encrypted transfer file')); ?>
			</button>
		</div>
	</form>
</section>

<section class="azc-card azc-section" aria-labelledby="azc-xfer-import-h" id="azc-transfer-import-card">
	<header class="azc-section__header">
		<div>
			<h2 id="azc-xfer-import-h"><?php p($l->t('Import configuration')); ?></h2>
			<p class="azc-section__sub"><?php p($l->t('Pick a transfer file, enter its passphrase, review the preview — then confirm. Nothing is written before you confirm.')); ?></p>
		</div>
	</header>
	<?php if ($lastReceipt !== null): ?>
	<p class="azc-transfer-last" id="azc-transfer-last">
		<?php p($l->t('Last transfer')); ?>:
		<strong><?php p((string)($lastReceipt['at'] ?? '—')); ?></strong>
		· <?php p((string)($lastReceipt['mode'] ?? 'merge')); ?>
		· <?php p(($lastReceipt['result'] ?? '') === 'applied' ? $l->t('applied') : $l->t('failed')); ?>
		<?php if (($lastReceipt['snapshotId'] ?? null) !== null): ?>
			· <?php p($l->t('undo via snapshot')); ?> <code><?php p((string)$lastReceipt['snapshotId']); ?></code>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<form id="azc-transfer-import-form" class="azc-form" novalidate>
		<fieldset class="azc-form-row azc-fieldset">
			<legend><?php p($l->t('Mode')); ?></legend>
			<label class="azc-radio">
				<input type="radio" name="mode" value="merge" checked>
				<span><strong><?php p($l->t('Merge')); ?></strong> — <?php p($l->t('add and update; nothing is deleted')); ?></span>
			</label>
			<label class="azc-radio azc-radio--danger">
				<input type="radio" name="mode" value="replace" <?php if (!$isSystemAdmin): ?>disabled<?php endif; ?>>
				<span><strong><?php p($l->t('Replace')); ?></strong> — <?php p($l->t('make identical to the file; prod-only rows are deleted')); ?></span>
			</label>
			<p class="azc-form-row__hint" id="azc-transfer-replace-hint">
				<?php if ($isSystemAdmin): ?>
					<?php p($l->t('Replace needs maintenance mode ON (occ maintenance:mode --on) — you will type REPLACE in the preview to confirm.')); ?>
				<?php else: ?>
					<?php p($l->t('Replace is available to Nextcloud system administrators only.')); ?>
				<?php endif; ?>
			</p>
		</fieldset>
		<div class="azc-form-row">
			<label for="azc-transfer-file"><?php p($l->t('Transfer file')); ?></label>
			<input type="file" id="azc-transfer-file" name="file" accept=".nctransfer,application/json" class="azc-file-input">
		</div>
		<div class="azc-form-row">
			<label for="azc-transfer-import-passphrase"><?php p($l->t('File passphrase')); ?></label>
			<div class="azc-input-group">
				<input type="password" id="azc-transfer-import-passphrase" name="import_passphrase" autocomplete="off">
				<button type="button" class="azc-btn azc-btn--ghost" id="azc-transfer-import-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
			</div>
		</div>
		<div id="azc-transfer-import-status" class="azc-form-row__hint" role="status" aria-live="polite"></div>
	</form>
</section>

<dialog id="azc-transfer-report" class="azc-dialog azc-transfer-report" aria-labelledby="azc-transfer-report-h">
	<div class="azc-dialog__body">
		<h3 id="azc-transfer-report-h"><?php p($l->t('Import preview')); ?></h3>
		<p id="azc-transfer-report-verdict" class="azc-transfer-report__verdict"></p>
		<p id="azc-transfer-report-file" class="azc-form-row__hint"></p>
		<div id="azc-transfer-report-groups"></div>
	</div>
	<footer class="azc-dialog__footer">
		<div id="azc-transfer-replace-confirm" class="azc-form-row" hidden>
			<label for="azc-transfer-replace-token"><?php p($l->t('Type REPLACE to confirm')); ?></label>
			<input type="text" id="azc-transfer-replace-token" autocomplete="off" spellcheck="false" placeholder="REPLACE">
		</div>
		<div class="azc-actions">
			<button type="button" class="azc-btn azc-btn--secondary" id="azc-transfer-cancel" value="cancel"><?php p($l->t('Cancel')); ?></button>
			<button type="button" class="azc-btn azc-btn--primary" id="azc-transfer-confirm"><?php p($l->t('Apply import')); ?></button>
		</div>
	</footer>
</dialog>

<dialog id="azc-transfer-progress" class="azc-dialog" aria-labelledby="azc-transfer-progress-h" aria-live="polite">
	<div class="azc-dialog__body">
		<h3 id="azc-transfer-progress-h"><?php p($l->t('Applying import')); ?></h3>
		<progress id="azc-transfer-progress-bar" max="100" value="0"></progress>
		<p id="azc-transfer-progress-text"><?php p($l->t('Starting…')); ?></p>
		<p class="azc-form-row__hint"><?php p($l->t('You can close this — the result is saved and shown under “Last transfer”.')); ?></p>
	</div>
</dialog>

<div id="azc-transfer-result-live" class="azc-visually-hidden" role="status" aria-live="polite"></div>
