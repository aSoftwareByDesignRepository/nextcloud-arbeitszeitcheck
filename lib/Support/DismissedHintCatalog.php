<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Support;

/**
 * Stable ids of dismissible help texts.
 *
 * "Hide all help texts" writes this whole set. Add an id here when a page
 * grows a new data-hint, or the shortcut will leave that text visible.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
final class DismissedHintCatalog
{
	/**
	 * @var list<string>
	 */
	public const IDS = [
		'absence-past-entry-hint',
		'dashboard-projectcheck-linking-off',
		'time-entries-export-hint',
	];
}
