<?php

declare(strict_types=1);

/**
 * ArbeitszeitCheck — license host wrapper.
 *
 * The license UX is the canonical shared Check-family license page
 * (templates/common/license-page.php, synced from _shared/license-page).
 * This wrapper only adapts AZC's controller params to the shared
 * contract — do not put markup here.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @license AGPL-3.0-or-later
 */

$_['terminalMode'] = $_['terminalMode'] ?? 'link';
$_['deviceManageUrl'] = (string)($_['deviceManageUrl'] ?? $_['kioskAdminUrl'] ?? '');
$_['deviceManageLabel'] = (string)($_['deviceManageLabel'] ?? $l->t('Manage kiosk terminals'));
$_['deviceStrings'] = is_array($_['deviceStrings'] ?? null) ? $_['deviceStrings'] : [
	'title' => $l->t('Kiosk terminals'),
	'lead' => $l->t('Pair shared kiosk devices from the kiosk administration. Each paired terminal uses one license slot.'),
	'label' => $l->t('Kiosk terminals'),
];
if (is_array($_['license'] ?? null) && !isset($_['instanceBound'])) {
	$_['instanceBound'] = !empty($_['license']['instanceBound']);
}

include __DIR__ . '/common/page-start.php';
echo '<div class="azc-page-stack">';
include __DIR__ . '/common/license-page.php';
echo '</div>';
include __DIR__ . '/common/page-end.php';
