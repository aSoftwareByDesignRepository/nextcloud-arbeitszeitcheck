<?php

declare(strict_types=1);

/**
 * Neutral callout when ProjectCheck is installed but the admin connection is off.
 *
 * Expects:
 *   - $l (\OCP\IL10N)
 *   - $azcProjectCheckCalloutContext (optional): 'dashboard' | 'time-entry' | 'edit'
 *   - $azcProjectCheckCalloutDismissible (optional): dashboard only — X hides it
 *     via the server-persisted hint set. Time-entry and edit stay until linking is on.
 *
 * @var \OCP\IL10N $l
 * @var array $_
 */

use OCA\ArbeitszeitCheck\Service\IconCatalog;

$context = isset($azcProjectCheckCalloutContext) ? (string)$azcProjectCheckCalloutContext : 'dashboard';
$dismissible = !empty($azcProjectCheckCalloutDismissible);
$azcProjectCheckCalloutDismissible = false;
$title = $l->t('ProjectCheck linking is turned off');
$body = $context === 'edit'
	? $l->t('An administrator turned off new project links. You can still see or remove an existing link on this entry, but you cannot pick a different project.')
	: $l->t('An administrator must enable the ProjectCheck connection in Global settings before you can link hours to a project.');
$hintId = 'dashboard-projectcheck-linking-off';
$dismissedHints = is_array($_['dismissedHints'] ?? null) ? $_['dismissedHints'] : [];
$hintDismissed = $dismissible && in_array($hintId, $dismissedHints, true);
?>
<?php if ($dismissible): ?>
<script type="application/json" id="azc-dismissed-hints-data"><?php print_unescaped(json_encode(array_values($dismissedHints), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?></script>
<div class="azc-hint azc-dashboard-punch__projectcheck" data-hint="<?php p($hintId); ?>">
	<div class="azc-callout azc-callout--neutral azc-projectcheck-linking-off azc-hint__body" id="<?php p($hintId); ?>" role="note" aria-labelledby="<?php p($hintId); ?>-title"<?php echo $hintDismissed ? ' hidden' : ''; ?>>
		<div class="azc-hint__header">
			<span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('briefcase', 'azc-callout__icon-svg')); ?></span>
			<p class="azc-callout__title azc-hint__title" id="<?php p($hintId); ?>-title"><?php p($title); ?></p>
			<button type="button"
				class="azc-hint__dismiss"
				data-hint-dismiss="<?php p($hintId); ?>"
				aria-expanded="<?php echo $hintDismissed ? 'false' : 'true'; ?>"
				aria-controls="<?php p($hintId); ?>"
				aria-label="<?php p($l->t('Hide this help text permanently')); ?>">
				<?php print_unescaped(IconCatalog::render('x', 'azc-hint__dismiss-icon')); ?>
			</button>
		</div>
		<p class="azc-callout__text"><?php p($body); ?></p>
	</div>
	<button type="button"
		class="azc-hint__show azc-btn azc-btn--tertiary azc-btn--small"
		data-hint-show="<?php p($hintId); ?>"
		aria-expanded="<?php echo $hintDismissed ? 'true' : 'false'; ?>"
		aria-controls="<?php p($hintId); ?>"
		<?php echo $hintDismissed ? '' : 'hidden'; ?>>
		<?php p($l->t('Show hint')); ?>
	</button>
</div>
<?php else: ?>
<div class="azc-callout azc-callout--neutral azc-projectcheck-linking-off" role="status">
	<span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('briefcase', 'azc-callout__icon-svg')); ?></span>
	<div class="azc-callout__body">
		<p class="azc-callout__title"><?php p($title); ?></p>
		<p class="azc-callout__text"><?php p($body); ?></p>
	</div>
</div>
<?php endif; ?>
