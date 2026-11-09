<?php
/**
 * Rendered by AppAccessMiddleware when the user cannot use ArbeitszeitCheck.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\ArbeitszeitCheck\Service\FrontEndAssetService;
use OCA\ArbeitszeitCheck\Service\IconCatalog;

FrontEndAssetService::registerCore();

$message = (string)($_['message'] ?? $l->t('You are not allowed to use ArbeitszeitCheck right now.'));
$hint = (string)($_['hint'] ?? $l->t('If you believe this is a mistake, contact your ArbeitszeitCheck administrator.'));
$homeUrl = (string)($_['homeUrl'] ?? '/');
?>
<div id="app-content" class="azc-app azc-app--access-denied">
	<div id="app-content-wrapper" class="azc-shell azc-shell--minimal">
	<?php /* Skip links are provided by Nextcloud core's #skip-actions strip. */ ?>
	<div class="azc-denied">
		<section id="azc-denied-main" class="azc-card azc-denied__card" role="alert" aria-labelledby="azc-denied-title" tabindex="-1">
			<div class="azc-page-header__icon azc-denied__icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('lock', 'azc-page-header__icon-svg')); ?>
			</div>
			<h1 id="azc-denied-title"><?php p($l->t('Access denied')); ?></h1>
			<p><?php p($message); ?></p>
			<p class="azc-callout__hint"><?php p($hint); ?></p>
			<a class="azc-btn azc-btn--primary" href="<?php p($homeUrl); ?>">
				<?php p($l->t('Back to Nextcloud')); ?>
			</a>
		</section>
	</div>
	</div>
</div>
