<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Exception\AccessDeniedException;
use OCA\ArbeitszeitCheck\Exception\NotAuthenticatedException;
use OCA\ArbeitszeitCheck\Support\ExceptionHttpStatus;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * ExceptionHttpStatus must classify on the exception TYPE first so a
 * localized throw ("Zugriff verweigert.") still lands on 403/401 — the
 * English-substring classifiers it replaces collapsed those to 500.
 * The residual text path covers legacy plain-\Exception throws in BOTH
 * conventions: English source phrase and current-locale twin.
 */
class ExceptionHttpStatusTest extends TestCase
{
	private function l10n(array $map): IL10N
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => $map[$text] ?? $text
		);
		return $l10n;
	}

	public function testTypedAuthAndDenyClassifyWithoutMessageInspection(): void
	{
		$l10n = $this->l10n([]);
		$this->assertTrue(ExceptionHttpStatus::isUnauthenticated(new NotAuthenticatedException(), $l10n));
		$this->assertTrue(ExceptionHttpStatus::isAccessDenied(
			new AccessDeniedException('some text that does not contain the English phrase at all'),
			$l10n
		));
	}

	public function testLocalizedLegacyThrowStillClassifiesViaTwin(): void
	{
		$l10n = $this->l10n(['Access denied' => 'Zugriff verweigert']);
		$this->assertTrue(ExceptionHttpStatus::isAccessDenied(
			new \Exception('Zugriff verweigert. Sie können nur Berichte für sich selbst einsehen.'),
			$l10n
		));
		$this->assertFalse(ExceptionHttpStatus::isAccessDenied(new \Exception('Zugriff verweigert.'), null));
	}

	public function testEnglishLiteralResidualStillClassifies(): void
	{
		$l10n = $this->l10n(['User not authenticated' => 'Benutzer nicht authentifiziert']);
		$this->assertTrue(ExceptionHttpStatus::isUnauthenticated(
			new \Exception('User not authenticated'), $l10n
		));
	}

	public function testUnrelatedThrowableDoesNotClassify(): void
	{
		$l10n = $this->l10n(['Access denied' => 'Zugriff verweigert']);
		$this->assertFalse(ExceptionHttpStatus::isUnauthenticated(new \RuntimeException('db gone'), $l10n));
		$this->assertFalse(ExceptionHttpStatus::isAccessDenied(new \RuntimeException('db gone'), $l10n));
		$this->assertFalse(ExceptionHttpStatus::isUnauthenticated(new \Exception(''), $l10n));
	}

	public function testThrowingL10nFailsClosedNotLoud(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willThrowException(new \RuntimeException('l10n broken'));
		$this->assertFalse(ExceptionHttpStatus::isAccessDenied(new \Exception('Zugriff verweigert.'), $l10n));
	}
}
