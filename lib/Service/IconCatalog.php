<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

/**
 * Inline SVG icon catalogue for ArbeitszeitCheck templates.
 *
 * Lucide-style 24×24 strokes inheriting currentColor for theme parity with
 * sibling check-apps. Safe to embed: no user data, always aria-hidden.
 */
final class IconCatalog
{
	/** @var array<string,string> */
	private const ICONS = [
		'layout-grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
		'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
		'calendar-off' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h11"/><path d="M3 3l18 18"/>',
		'calendar-heart' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M12 15.5c1.5-1.2 3-2.3 3-3.8a2 2 0 0 0-4 0c0 1.5 1.5 2.6 3 3.8Z"/>',
		'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
		'shield-check' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
		'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
		'alert-triangle' => '<path d="m12 3 10 17H2Z"/><path d="M12 9v4"/><circle cx="12" cy="17" r="1" fill="currentColor" stroke="none"/>',
		'triangle-alert' => '<path d="m12 3 10 17H2Z"/><path d="M12 9v4"/><circle cx="12" cy="17" r="1" fill="currentColor" stroke="none"/>',
		'file-analytics' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M16 13H8M16 17H8M10 9H8"/>',
		'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M16 13H8M16 17H8M10 9H8"/>',
		'file-down' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M12 18v-6M9 15l3 3 3-3"/>',
		'settings' => '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 0 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 0 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 0 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3 1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 0 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8 1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
		'user-check' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
		'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
		'coins' => '<circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/>',
		'clipboard-list' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6"/><path d="M9 16h6"/>',
		'briefcase' => '<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><path d="M3 13h18"/>',
		'building-2' => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4M10 10h4M10 14h4M10 18h4"/>',
		'layers' => '<path d="m12.83 2.18 8.49 4.92a1 1 0 0 1 0 1.74l-8.49 4.92a2 2 0 0 1-2 0L2.34 8.84a1 1 0 0 1 0-1.74l8.49-4.92a2 2 0 0 1 2 0Z"/><path d="m2.34 12.34 8.49 4.92a2 2 0 0 0 2 0l8.49-4.92"/><path d="m2.34 16.34 8.49 4.92a2 2 0 0 0 2 0l8.49-4.92"/>',
		'scroll-text' => '<path d="M8 21h12a2 2 0 0 0 2-2v-2H10v2a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v3h4"/><path d="M19 17V5a2 2 0 0 0-2-2H4"/><path d="M15 8h-5M15 12h-5"/>',
		'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
		'tablet' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M12 18h.01"/>',
		'smartphone' => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
		'key' => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 1 0 1.4L12 17H9v-3l5.1-5.1a1 1 0 0 1 1.4 0Z"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="2.5"/>',
		'heart-handshake' => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="m12 13 2-2"/><path d="m8.5 9.5 1.5 1.5"/><path d="m15 8.5-1.5 1.5"/>',
		'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
		'plus' => '<path d="M12 5v14M5 12h14"/>',
		'filter' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
		'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
		'play' => '<polygon points="6 3 20 12 6 21 6 3"/>',
		'coffee' => '<path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><path d="M6 2v2M10 2v2M14 2v2"/>',
		'square' => '<rect x="3" y="3" width="18" height="18" rx="2"/>',
		'check' => '<path d="m5 12 5 5L20 7"/>',
		'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
		'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
		'edit' => '<path d="M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
		'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
		'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><circle cx="12" cy="8" r="1" fill="currentColor" stroke="none"/>',
		'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.5 9a2.5 2.5 0 1 1 4.5 1.5c-.7.6-1.5.9-1.5 1.5v.5"/><circle cx="12" cy="17" r="1" fill="currentColor" stroke="none"/>',
		'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
		'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
		'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
		'rotate' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>',
		'circle-check' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
		'circle-x' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
		'circle-alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><circle cx="12" cy="16" r="1" fill="currentColor" stroke="none"/>',
		'circle' => '<circle cx="12" cy="12" r="10"/>',
		'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
		'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
		'pause' => '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>',
	];

	public static function render(string $name, ?string $extraClass = null, float $strokeWidth = 2.0): string
	{
		$inner = self::ICONS[$name] ?? null;
		if ($inner === null) {
			return '';
		}
		$class = 'azc-icon';
		if ($extraClass !== null && $extraClass !== '') {
			$class .= ' ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8');
		}
		$strokeWidth = max(1.5, min(3.5, $strokeWidth));

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%s" stroke-linecap="round" stroke-linejoin="round" class="%s" aria-hidden="true" focusable="false">%s</svg>',
			htmlspecialchars((string)$strokeWidth, ENT_QUOTES, 'UTF-8'),
			$class,
			$inner
		);
	}

	/**
	 * Callout / notification icon well (theme-safe variant class on the well).
	 */
	public static function renderCalloutWell(string $name, string $variant = 'info'): string
	{
		$variant = match ($variant) {
			'error' => 'danger',
			default => $variant,
		};
		if (!in_array($variant, ['info', 'warning', 'danger', 'success', 'neutral'], true)) {
			$variant = 'info';
		}

		return sprintf(
			'<span class="azc-callout__icon azc-notif-icon-well azc-notif-icon-well--%s" aria-hidden="true">%s</span>',
			htmlspecialchars($variant, ENT_QUOTES, 'UTF-8'),
			self::render($name, 'azc-callout__icon-svg', 2.75)
		);
	}

	/** @return list<string> */
	public static function names(): array
	{
		return array_keys(self::ICONS);
	}
}
