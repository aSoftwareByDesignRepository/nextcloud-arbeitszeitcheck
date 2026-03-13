<?php

declare(strict_types=1);

/**
 * Substitution requests (Vertretungs-Freigabe) template
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');
$requests = $_['requests'] ?? [];
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$apiListUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.substitute.getPending');
$apiApproveUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.substitute.approve', ['absenceId' => '__ID__']);
$apiDeclineUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.substitute.decline', ['absenceId' => '__ID__']);
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content" class="substitution-requests-page">
    <div id="app-content-wrapper" role="main" aria-label="<?php p($l->t('Substitution requests')); ?>">
        <!-- Breadcrumb -->
        <div class="breadcrumb-container">
            <nav class="breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
                <ol>
                    <li><a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"><?php p($l->t('Dashboard')); ?></a></li>
                    <li aria-current="page"><?php p($l->t('Substitution requests')); ?></li>
                </ol>
            </nav>
        </div>

        <!-- Page Header -->
        <header class="section substitution-requests__header" aria-labelledby="substitution-title">
            <h1 id="substitution-title" class="substitution-requests__title"><?php p($l->t('Substitution requests')); ?></h1>
            <p class="substitution-requests__desc"><?php p($l->t('You have been asked to cover for colleagues during their absence. Approve or decline each request.')); ?></p>
        </header>

        <!-- Requests List -->
        <section class="section substitution-requests__list" id="substitution-requests-section" aria-labelledby="requests-heading">
            <h2 id="requests-heading" class="visually-hidden"><?php p($l->t('Pending substitution requests')); ?></h2>

            <div id="substitution-requests-content" class="substitution-requests-content" role="region" aria-live="polite">
                <p id="substitution-requests-loading" class="substitution-requests-loading" role="status" aria-live="polite" aria-hidden="false"><?php p($l->t('Loading…')); ?></p>
                <div id="substitution-requests-items" class="substitution-requests-items" aria-hidden="true"></div>
                <div id="substitution-requests-empty" class="substitution-requests-empty visually-hidden" role="status">
                    <p><?php p($l->t('No substitution requests.')); ?></p>
                    <p class="substitution-requests-empty__hint"><?php p($l->t('When a colleague requests an absence and selects you as their substitute, you will see the request here.')); ?></p>
                </div>
            </div>
        </section>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.substitutionApi = {
    list: <?php echo json_encode($apiListUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
    approve: <?php echo json_encode($apiApproveUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
    decline: <?php echo json_encode($apiDeclineUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
};
</script>
