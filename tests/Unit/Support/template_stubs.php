<?php

declare(strict_types=1);

/**
 * Minimal template helpers for Support & Us render tests (no Nextcloud kernel).
 */

if (!function_exists('p')) {
	/**
	 * @param mixed $text
	 */
	function p($text): void {
		echo htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
