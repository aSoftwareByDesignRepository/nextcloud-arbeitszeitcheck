<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Support;

use OCP\L10N\IFactory;

/**
 * Boundary translation for exception messages that reach the API wire.
 * The l10n catalog keys are the English messages themselves, so any
 * cataloged message translates and anything uncataloged falls back to
 * the original string — never a worse outcome than before.
 */
final class WireText {
	public static function t(string $message): string {
		if ($message === '') {
			return $message;
		}
		try {
			return \OCP\Server::get(IFactory::class)->get('arbeitszeitcheck')->t($message);
		} catch (\Throwable) {
			return $message;
		}
	}
}
