<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class LocaleFormatServiceTest extends TestCase
{
	private function service(?IFactory $l10nFactory = null, ?IUserSession $session = null, ?IDateTimeZone $dtz = null, ?IDateTimeFormatter $fmt = null): LocaleFormatService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $a, string $k, string $d = '') => $d
		);
		$tzDtz = $this->createMock(IDateTimeZone::class);
		$tzDtz->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$tzSession = $this->createMock(IUserSession::class);
		$tzSession->method('getUser')->willReturn(null);
		$tzs = new TimeZoneService($config, $tzDtz, $tzSession, new NullLogger());

		return new LocaleFormatService(
			$l10nFactory ?? $this->createMock(IFactory::class),
			$fmt ?? $this->createMock(IDateTimeFormatter::class),
			$session ?? $this->createMock(IUserSession::class),
			$dtz ?? $this->createMock(IDateTimeZone::class),
			$tzs,
		);
	}

	public function testCanonicalHtmlLang(): void
	{
		$svc = $this->service();
		$this->assertSame('en-US', $svc->canonicalHtmlLangFromLocaleString(null));
		$this->assertSame('en-US', $svc->canonicalHtmlLangFromLocaleString(''));
		$this->assertSame('de-DE', $svc->canonicalHtmlLangFromLocaleString('de'));
		$this->assertSame('de-DE', $svc->canonicalHtmlLangFromLocaleString('de_DE'));
		$this->assertSame('fr-FR', $svc->canonicalHtmlLangFromLocaleString('fr'));
		$this->assertSame('es-ES', $svc->canonicalHtmlLangFromLocaleString('es'));
		$this->assertSame('da-DK', $svc->canonicalHtmlLangFromLocaleString('da'));
		$this->assertSame('nl-NL', $svc->canonicalHtmlLangFromLocaleString('nl'));
		$this->assertSame('it-IT', $svc->canonicalHtmlLangFromLocaleString('it'));
		$this->assertSame('pl-PL', $svc->canonicalHtmlLangFromLocaleString('pl'));
		$this->assertSame('pt-PT', $svc->canonicalHtmlLangFromLocaleString('pt'));
		$this->assertSame('en-US', $svc->canonicalHtmlLangFromLocaleString('xx'));
		// already-canonical xx-YY gets region upper-cased
		$this->assertSame('de-CH', $svc->canonicalHtmlLangFromLocaleString('DE_CH'));
	}

	public function testClientHintsUsesUserLanguageAndDisplayTz(): void
	{
		$user = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('getUserLanguage')->willReturn('de');
		$dtz = $this->createMock(IDateTimeZone::class);
		$dtz->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Vienna'));

		$hints = $this->service($l10nFactory, $session, $dtz)->clientHints();
		$this->assertSame('de-DE', $hints['htmlLang']);
		$this->assertSame('Europe/Vienna', $hints['timezone']);
		$this->assertSame('Europe/Vienna', $hints['displayTimezone']);
	}

	public function testClientHintsFallsBackWhenAnonymousOrTzFails(): void
	{
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findLanguage')->willReturn('en');
		$dtz = $this->createMock(IDateTimeZone::class);
		$dtz->method('getTimeZone')->willThrowException(new \RuntimeException('no tz'));

		$hints = $this->service($l10nFactory, $session, $dtz)->clientHints();
		$this->assertSame('en-US', $hints['htmlLang']);
		// fallback: storage TZ name
		$this->assertSame('Europe/Berlin', $hints['timezone']);
	}

	public function testFormatYearMonthValidAndInvalid(): void
	{
		$fmt = $this->createMock(IDateTimeFormatter::class);
		$fmt->method('formatDate')->willReturn('Jun 2026');
		$svc = $this->service(fmt: $fmt);
		$this->assertSame('Jun 2026', $svc->formatYearMonth('2026-06'));
		// malformed input passes through untouched
		$this->assertSame('not-a-month', $svc->formatYearMonth('not-a-month'));
		$this->assertSame('2026-13-x', $svc->formatYearMonth('2026-13-x'));
	}
}
