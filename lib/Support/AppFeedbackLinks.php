<?php

declare(strict_types=1);

/**
 * Canonical mailto + GitHub builders for the in-app feedback footer.
 *
 * Security notes (auditor-facing):
 * - Destinations are compile-time constants or validated app metadata.
 * - Mailto subjects/bodies are rawurlencoded; display names reject CR/LF.
 * - Page URLs drop credential-shaped query keys before they enter the body.
 * - No user identity (uid, email, display name) is interpolated.
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class AppFeedbackLinks
{
	public const FEEDBACK_EMAIL = 'dev@software-by-design.de';
	public const GITHUB_ISSUES_URL = 'https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck/issues';
	public const VENDOR_NAME = 'Software by Design GbR';

	private string $appId;
	private string $appDisplayName;
	private string $appVersion;

	public function __construct(string $appId, string $appDisplayName, string $appVersion = '')
	{
		$appId = strtolower(trim($appId));
		if ($appId === '' || !preg_match('/^[a-z0-9_-]{2,40}$/', $appId)) {
			throw new \InvalidArgumentException('AppFeedbackLinks: invalid app id');
		}
		$normalized = trim($appDisplayName);
		if ($normalized === '' || !$this->isSafeDisplayName($normalized)) {
			throw new \InvalidArgumentException('AppFeedbackLinks: invalid app display name');
		}
		$version = trim($appVersion);
		if ($version !== '' && !$this->isSafeVersion($version)) {
			$version = '';
		}
		$this->appId = $appId;
		$this->appDisplayName = $normalized;
		$this->appVersion = $version;
	}

	public function appId(): string
	{
		return $this->appId;
	}

	public function appDisplayName(): string
	{
		return $this->appDisplayName;
	}

	public function appVersion(): string
	{
		return $this->appVersion;
	}

	public function feedbackEmail(): string
	{
		return self::FEEDBACK_EMAIL;
	}

	public function githubIssuesUrl(): string
	{
		$url = self::GITHUB_ISSUES_URL;
		if (!$this->isSafeGithubIssuesUrl($url)) {
			return '';
		}

		return $url;
	}

	public function isGermanLocale(string $languageCode): bool
	{
		$lang = strtolower(str_replace('_', '-', trim($languageCode)));
		if ($lang === '') {
			return false;
		}

		return $lang === 'de' || str_starts_with($lang, 'de-');
	}

	/**
	 * @param array{pageUrl?:string,locale?:string,errorCode?:string,ncVersion?:string,userAgent?:string} $ctx
	 */
	public function problemMailto(array $ctx = [], string $languageCode = 'en'): string
	{
		$subject = $this->isGermanLocale($languageCode)
			? $this->appDisplayName . ': Fehlermeldung'
			: $this->appDisplayName . ': Problem report';

		return $this->mailtoWith($subject, $this->buildBody($ctx, 'problem', $languageCode));
	}

	/**
	 * @param array{pageUrl?:string,locale?:string,ncVersion?:string,userAgent?:string} $ctx
	 */
	public function ideaMailto(array $ctx = [], string $languageCode = 'en'): string
	{
		$subject = $this->appDisplayName . ': Feedback';

		return $this->mailtoWith($subject, $this->buildBody($ctx, 'idea', $languageCode));
	}

	/**
	 * @param array{pageUrl?:string,locale?:string,errorCode?:string,ncVersion?:string,userAgent?:string} $ctx
	 * @return array{
	 *   feedbackEmail: string,
	 *   problemMailto: string,
	 *   ideaMailto: string,
	 *   githubIssuesUrl: string,
	 *   appId: string,
	 *   appDisplayName: string,
	 *   appVersion: string
	 * }
	 */
	public function forLocale(string $languageCode, array $ctx = []): array
	{
		return [
			'feedbackEmail' => self::FEEDBACK_EMAIL,
			'problemMailto' => $this->problemMailto($ctx, $languageCode),
			'ideaMailto' => $this->ideaMailto($ctx, $languageCode),
			'githubIssuesUrl' => $this->githubIssuesUrl(),
			'appId' => $this->appId,
			'appDisplayName' => $this->appDisplayName,
			'appVersion' => $this->appVersion,
		];
	}

	public function sanitizePageUrl(string $url): string
	{
		$url = trim($url);
		if ($url === '' || strlen($url) > 500) {
			return '';
		}
		if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
			return '';
		}
		$lower = strtolower($url);
		if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:') || str_starts_with($lower, 'vbscript:')) {
			return '';
		}
		$parts = parse_url($url);
		if (!is_array($parts)) {
			return '';
		}
		$query = [];
		if (!empty($parts['query'])) {
			parse_str((string)$parts['query'], $query);
			foreach (array_keys($query) as $key) {
				if ($this->isBlockedQueryKey((string)$key)) {
					unset($query[$key]);
				}
			}
		}
		$rebuilt = '';
		if (!empty($parts['scheme']) && !empty($parts['host'])) {
			$scheme = strtolower((string)$parts['scheme']);
			if ($scheme !== 'http' && $scheme !== 'https') {
				return '';
			}
			$rebuilt = $scheme . '://' . $parts['host'];
			if (!empty($parts['port'])) {
				$rebuilt .= ':' . (int)$parts['port'];
			}
			$rebuilt .= (string)($parts['path'] ?? '');
		} elseif (!empty($parts['path']) && str_starts_with((string)$parts['path'], '/')) {
			$rebuilt = (string)$parts['path'];
		} else {
			return '';
		}
		if ($query !== []) {
			$rebuilt .= '?' . http_build_query($query);
		}

		return strlen($rebuilt) > 500 ? substr($rebuilt, 0, 500) : $rebuilt;
	}

	/**
	 * @param array{pageUrl?:string,locale?:string,errorCode?:string,ncVersion?:string,userAgent?:string} $ctx
	 */
	private function buildBody(array $ctx, string $kind, string $languageCode): string
	{
		$intro = $kind === 'idea'
			? "--- Please describe your idea below ---\n"
			: "--- Please describe what went wrong below ---\nSteps:\n1.\n2.\n\nExpected:\nActual:\n";
		$page = $this->sanitizePageUrl(isset($ctx['pageUrl']) ? (string)$ctx['pageUrl'] : '');
		$errorCode = isset($ctx['errorCode']) ? trim((string)$ctx['errorCode']) : '';
		if ($errorCode !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $errorCode)) {
			$errorCode = '';
		}
		$ncVersion = isset($ctx['ncVersion']) ? trim((string)$ctx['ncVersion']) : '';
		if ($ncVersion !== '' && !$this->isSafeVersion($ncVersion)) {
			$ncVersion = '';
		}
		$ua = isset($ctx['userAgent']) ? trim((string)$ctx['userAgent']) : '';
		if ($ua !== '') {
			$ua = preg_replace('/[\x00-\x1F\x7F]/', '', $ua) ?? '';
			$ua = strlen($ua) > 180 ? substr($ua, 0, 180) : $ua;
		}
		$locale = isset($ctx['locale']) ? trim((string)$ctx['locale']) : $languageCode;
		if ($locale !== '' && !preg_match('/^[A-Za-z0-9_-]{1,16}$/', $locale)) {
			$locale = '';
		}

		$lines = [
			$intro,
			'--- Auto-filled (you can delete) ---',
			'App: ' . $this->appDisplayName . ($this->appVersion !== '' ? (' ' . $this->appVersion) : ''),
			'App id: ' . $this->appId,
		];
		if ($ncVersion !== '') {
			$lines[] = 'Nextcloud: ' . $ncVersion;
		}
		if ($page !== '') {
			$lines[] = 'Page: ' . $page;
		}
		if ($locale !== '') {
			$lines[] = 'Locale: ' . $locale;
		}
		$lines[] = 'Time (UTC): ' . gmdate('Y-m-d\TH:i:s\Z');
		if ($errorCode !== '') {
			$lines[] = 'Error code: ' . $errorCode;
		}
		if ($ua !== '') {
			$lines[] = 'Browser: ' . $ua;
		}
		$body = implode("\n", $lines);
		if (strlen($body) > 1500) {
			$body = substr($body, 0, 1500);
		}

		return $body;
	}

	private function mailtoWith(string $subject, string $body): string
	{
		if (!$this->isSafeMailtoSubject($subject)) {
			throw new \InvalidArgumentException('AppFeedbackLinks: unsafe mailto subject');
		}

		return 'mailto:' . self::FEEDBACK_EMAIL
			. '?subject=' . rawurlencode($subject)
			. '&body=' . rawurlencode($body);
	}

	private function isBlockedQueryKey(string $key): bool
	{
		static $blocked = [
			'token' => true,
			'password' => true,
			'code' => true,
			'secret' => true,
			'key' => true,
			'auth' => true,
			'session' => true,
			'requesttoken' => true,
			'guestname' => true,
			'guestpassword' => true,
			'iknowthatthisisabiginstanceandtheupdaterequestcouldrunintoatimeoutandhowtorestoreabackup' => true,
		];

		return isset($blocked[strtolower($key)]);
	}

	private function isSafeDisplayName(string $value): bool
	{
		if (strlen($value) > 80) {
			return false;
		}

		return preg_match('/^[\p{L}\p{N} .+\-_&()]+$/u', $value) === 1
			&& !preg_match('/[\x00-\x1F\x7F]/', $value);
	}

	private function isSafeVersion(string $value): bool
	{
		return preg_match('/^[A-Za-z0-9._+-]{1,32}$/', $value) === 1;
	}

	private function isSafeMailtoSubject(string $value): bool
	{
		if ($value === '' || strlen($value) > 200) {
			return false;
		}

		return !preg_match('/[\x00-\x1F\x7F]/', $value);
	}

	private function isSafeGithubIssuesUrl(string $url): bool
	{
		if (!str_starts_with($url, 'https://github.com/aSoftwareByDesignRepository/')) {
			return false;
		}
		if (!str_ends_with($url, '/issues')) {
			return false;
		}

		return !preg_match('/[\x00-\x1F\x7F\s]/', $url);
	}
}
