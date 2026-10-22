<?php

declare(strict_types=1);

/**
 * Resolve the signed-in user's hours display preference (PHP only).
 *
 * Sets $azcHoursDisplayMode for the including template.
 * Legacy default: decimal. Opt-in: hours_minutes ("5h 30").
 */

$azcHoursDisplayMode = \OCA\ArbeitszeitCheck\Support\HoursDisplay::MODE_DECIMAL;
try {
	$azcHoursUid = \OCP\Server::get(\OCP\IUserSession::class)->getUser()?->getUID();
	if (is_string($azcHoursUid) && $azcHoursUid !== '') {
		$azcHoursSetting = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class)
			->getSetting($azcHoursUid, \OCA\ArbeitszeitCheck\Support\HoursDisplay::USER_SETTING_KEY);
		if ($azcHoursSetting) {
			$azcHoursDisplayMode = \OCA\ArbeitszeitCheck\Support\HoursDisplay::normalize(
				$azcHoursSetting->getSettingValue()
			);
		}
	}
} catch (\Throwable) {
	$azcHoursDisplayMode = \OCA\ArbeitszeitCheck\Support\HoursDisplay::MODE_DECIMAL;
}
