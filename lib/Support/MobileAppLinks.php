<?php

declare(strict_types=1);

/**
 * Canonical store / product links for ArbeitszeitCheck Mobile + Terminal (Get the App).
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class MobileAppLinks
{
	public const PLAY_STORE_PACKAGE_ID = 'de.softwarebydesign.arbeitszeitcheck';
	public const PLAY_STORE_URL = 'https://play.google.com/store/apps/details?id=de.softwarebydesign.arbeitszeitcheck';
	public const KIOSK_PLAY_STORE_PACKAGE_ID = 'de.softwarebydesign.arbeitszeitcheck.kiosk';
	public const KIOSK_PLAY_STORE_URL = 'https://play.google.com/store/apps/details?id=de.softwarebydesign.arbeitszeitcheck.kiosk';
	public const APP_STORE_URL = 'https://apps.apple.com/app/arbeitszeitcheck/id6803192234';
	public const KIOSK_APP_STORE_URL = 'https://apps.apple.com/app/arbeitszeitcheck-terminal/id6806563753';
	public const PRODUCT_PAGE_PATH = '/en/apps/arbeitszeitcheck.html#mobile-app';
	public const PRODUCT_PAGE_PATH_DE = '/de/apps/arbeitszeitcheck.html#mobile-app';
	public const PRIVACY_PAGE_PATH = '/en/privacy-arbeitszeitcheck-mobile.html';
	public const PRIVACY_PAGE_PATH_DE = '/de/datenschutz-arbeitszeitcheck-mobile.html';
	public const KIOSK_PRIVACY_PAGE_PATH = '/en/privacy-arbeitszeitcheck-terminal.html';
	public const KIOSK_PRIVACY_PAGE_PATH_DE = '/de/datenschutz-arbeitszeitcheck-terminal.html';
	public const PLAY_LISTED = true;

	public function playStoreUrl(): string
	{
		return self::PLAY_STORE_URL;
	}

	public function playStorePackageId(): string
	{
		return self::PLAY_STORE_PACKAGE_ID;
	}

	public function kioskPlayStoreUrl(): string
	{
		return self::KIOSK_PLAY_STORE_URL;
	}

	public function kioskPlayStorePackageId(): string
	{
		return self::KIOSK_PLAY_STORE_PACKAGE_ID;
	}

	public function appStoreUrl(): string
	{
		return self::APP_STORE_URL;
	}

	public function kioskAppStoreUrl(): string
	{
		return self::KIOSK_APP_STORE_URL;
	}

	public function vendorAppsPageUrl(string $languageCode): string
	{
		return (new SupportUsLinks('ArbeitszeitCheck'))->appsPageUrl($languageCode);
	}

	/**
	 * Accept an href only when it is https and begins with a host prefix that itself
	 * ends in "/". A missing slash would treat play.google.com.evil.com as Play.
	 * Empty or hostile values use $fallback when that fallback is https; otherwise "".
	 */
	public static function publicHttpsUrl(string $candidate, string $requiredPrefix, string $fallback): string
	{
		if (!str_starts_with($requiredPrefix, 'https://') || !str_ends_with($requiredPrefix, '/')) {
			return '';
		}
		if ($fallback !== '' && !str_starts_with($fallback, 'https://')) {
			return '';
		}
		$candidate = trim($candidate);
		if ($candidate === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $candidate) === 1) {
			return $fallback;
		}
		if (!str_starts_with($candidate, $requiredPrefix)) {
			return $fallback;
		}

		return $candidate;
	}

	public function playListed(): bool
	{
		return self::PLAY_LISTED;
	}

	public function productPageUrl(string $languageCode): string
	{
		$path = $this->isGermanLocale($languageCode) ? self::PRODUCT_PAGE_PATH_DE : self::PRODUCT_PAGE_PATH;
		return SupportUsLinks::SITE_ORIGIN . $path;
	}

	public function privacyPageUrl(string $languageCode): string
	{
		$path = $this->isGermanLocale($languageCode) ? self::PRIVACY_PAGE_PATH_DE : self::PRIVACY_PAGE_PATH;
		return SupportUsLinks::SITE_ORIGIN . $path;
	}

	public function kioskPrivacyPageUrl(string $languageCode): string
	{
		$path = $this->isGermanLocale($languageCode) ? self::KIOSK_PRIVACY_PAGE_PATH_DE : self::KIOSK_PRIVACY_PAGE_PATH;
		return SupportUsLinks::SITE_ORIGIN . $path;
	}

	public function isGermanLocale(string $languageCode): bool
	{
		$lang = strtolower(str_replace('_', '-', trim($languageCode)));
		return $lang === 'de' || str_starts_with($lang, 'de-');
	}
}
