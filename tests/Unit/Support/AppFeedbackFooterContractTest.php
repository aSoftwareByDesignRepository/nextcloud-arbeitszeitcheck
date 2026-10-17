<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\AppFeedbackLinks;
use PHPUnit\Framework\TestCase;

final class AppFeedbackFooterContractTest extends TestCase
{
	private function footer(): string
	{
		$path = dirname(__DIR__, 3) . '/templates/parts/feedback-nav-footer.php';
		$src = file_get_contents($path);
		self::assertNotFalse($src);

		return $src;
	}

	public function testFooterHasThreeChannels(): void
	{
		$src = $this->footer();
		self::assertStringContainsString('data-app-feedback="1"', $src);
		self::assertStringContainsString('<nav', $src);
		// landmark_uniqueness: the footer <nav> sits inside #app-navigation's
		// own navigation landmark — it must carry a distinguishing accessible name.
		self::assertMatchesRegularExpression('/<nav[\s\S]*?aria-label=/', $src);
		self::assertStringContainsString('nav-footer__trigger', $src);
		self::assertStringContainsString('nav-footer__menu', $src);
		self::assertStringContainsString('role="menu"', $src);
		self::assertStringContainsString('aria-haspopup="true"', $src);
		self::assertStringContainsString('Report a problem', $src);
		self::assertStringContainsString('Suggest an improvement', $src);
		self::assertStringContainsString('Open GitHub Issues', $src);
		self::assertStringContainsString('(opens in a new tab)', $src);
		self::assertStringContainsString('nav-footer__new-tab', $src);
		self::assertStringContainsString('nav-footer__note', $src);
		self::assertStringContainsString('problemMailto', $src);
		self::assertStringContainsString('ideaMailto', $src);
		self::assertStringContainsString('githubIssuesUrl', $src);
		self::assertStringContainsString('rel="noopener noreferrer"', $src);
		self::assertStringContainsString('no reply SLA', $src);
		self::assertStringContainsString("\$l->t('Help')", $src);
		self::assertStringContainsString('Use Support & us.', $src);
		self::assertStringNotContainsString("\$l->t('Support & us')", $src);
		self::assertSame('dev@software-by-design.de', AppFeedbackLinks::FEEDBACK_EMAIL);
	}

	public function testJsHelperStripsSecretsWrapsToastsAndInstallsPopover(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/common/app-feedback.js');
		self::assertStringContainsString('dev@software-by-design.de', $js);
		self::assertStringContainsString('sanitizePageUrl', $js);
		self::assertStringContainsString('token|password|code|secret|key|auth|session', $js);
		self::assertStringContainsString('showToast', $js);
		self::assertStringContainsString('installPopover', $js);
		self::assertStringContainsString('Report this problem', $js);
		self::assertStringContainsString('SbdAppFeedback', $js);
	}

	public function testCssHasTouchTargetAndReducedMotion(): void
	{
		$css = '';
		$root = dirname(__DIR__, 3);
		foreach (['css/app.css', 'css/navigation.css', 'css/admin-settings.css', 'css/azc.css'] as $rel) {
			$path = $root . '/' . $rel;
			if (is_file($path)) {
				$css .= (string)file_get_contents($path);
			}
		}
		$start = strpos($css, '/* === app-feedback:azc start === */');
		$end = strpos($css, '/* === app-feedback:azc end === */');
		self::assertNotFalse($start);
		self::assertNotFalse($end);
		$block = substr($css, $start, $end - $start);
		self::assertStringContainsString('azc-nav-footer', $block);
		self::assertStringContainsString('nav-footer__trigger', $block);
		self::assertStringContainsString('nav-footer__menu', $block);
		self::assertStringContainsString('nav-footer__menu-item', $block);
		self::assertStringContainsString('min-height: 44px', $block);
		self::assertStringContainsString('min-width: 44px', $block);
		self::assertStringContainsString('overflow-wrap: anywhere', $block);
		self::assertStringContainsString('prefers-reduced-motion', $block);
		self::assertStringContainsString('prefers-contrast', $block);
		self::assertStringContainsString('forced-colors', $block);
		self::assertStringContainsString(':focus-visible', $block);
		self::assertStringContainsString(':focus:not(:focus-visible)', $block);
		self::assertStringContainsString('var(--color-primary-element)', $block);
		self::assertStringContainsString('var(--color-error-text', $block);
		self::assertStringContainsString('env(safe-area-inset-bottom', $block);
		self::assertStringNotContainsString('#fff', $block);
		self::assertStringNotContainsString('#000', $block);
		self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $block);
		self::assertDoesNotMatchRegularExpression('/(?:^|[\\s;{])width:\\s*\\d{3,}px\\b/', $block);
	}
}
