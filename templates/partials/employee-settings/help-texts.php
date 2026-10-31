<?php

declare(strict_types=1);

/**
 * Employee settings — show or hide every dismissible help text.
 *
 * @var \OCP\IL10N $l
 * @var bool $azcSettingsShowCardChrome
 */

use OCA\ArbeitszeitCheck\Support\DismissedHintCatalog;

$showChrome = !empty($azcSettingsShowCardChrome);
$dismissedHints = array_values(array_filter(
	is_array($_['dismissedHints'] ?? null) ? $_['dismissedHints'] : [],
	static fn ($id): bool => is_string($id) && $id !== ''
));
$knownIds = DismissedHintCatalog::IDS;
$hiddenKnown = array_values(array_intersect($knownIds, $dismissedHints));
$allKnownHidden = $knownIds !== [] && count($hiddenKnown) === count($knownIds);
$dismissedCount = count($dismissedHints);
$shownCount = count($knownIds) - count($hiddenKnown);
$totalCount = count($knownIds);
$helpTextCountTemplate = static function (int $count) use ($l): string {
	$rendered = $l->n(
		'%n of %s help text is shown.',
		'%n of %s help texts are shown.',
		$count,
		['{total}']
	);
	return (string)preg_replace('/\b' . $count . '\b/u', '{count}', $rendered, 1);
};
?>
<section class="settings-section azc-card" aria-labelledby="settings-hints-heading">
	<header class="azc-card__header<?php echo $showChrome ? '' : ' azc-card__header--page-title-only'; ?>">
		<div class="azc-card__header-text">
			<?php if ($showChrome): ?>
				<h2 id="settings-hints-heading" class="azc-card__title"><?php p($l->t('Help texts')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Help texts you hid on pages stay hidden until you show them again here or use "Show hint" where they appear.')); ?></p>
			<?php else: ?>
				<h2 id="settings-hints-heading" class="azc-card__title visually-hidden"><?php p($l->t('Help texts')); ?></h2>
			<?php endif; ?>
		</div>
	</header>
	<div class="azc-card__body">
		<?php include __DIR__ . '/_section-intro.php'; ?>
		<script type="application/json" id="azc-help-text-ids"><?php print_unescaped(json_encode($knownIds, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?></script>
		<script type="application/json" id="azc-dismissed-hints-data"><?php print_unescaped(json_encode($dismissedHints, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?></script>
		<div class="settings-help-texts__actions">
			<button type="button"
				id="btn-hide-all-hints"
				class="azc-btn azc-btn--secondary"
				<?php echo $allKnownHidden ? 'disabled' : ''; ?>
				aria-describedby="show-all-hints-status">
				<?php p($l->t('Hide all help texts')); ?>
			</button>
			<button type="button"
				id="btn-show-all-hints"
				class="azc-btn azc-btn--secondary"
				<?php echo $dismissedCount === 0 ? 'disabled' : ''; ?>
				aria-describedby="show-all-hints-status">
				<?php p($l->t('Show all help texts again')); ?>
			</button>
		</div>
		<p id="show-all-hints-status"
			class="settings-version-line"
			aria-live="polite"
			data-total="<?php p((string)$totalCount); ?>"
			data-one="<?php p($helpTextCountTemplate(1)); ?>"
			data-many="<?php p($helpTextCountTemplate(5)); ?>">
			<?php p($l->n('%n of %s help text is shown.', '%n of %s help texts are shown.', $shownCount, [(string)$totalCount])); ?>
		</p>
	</div>
</section>
