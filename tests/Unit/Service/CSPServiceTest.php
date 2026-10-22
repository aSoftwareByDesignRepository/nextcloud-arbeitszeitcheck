<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\CSPService;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use PHPUnit\Framework\TestCase;

class CSPServiceTest extends TestCase
{
	private function prop(object $obj, string $name): mixed
	{
		$p = new \ReflectionProperty(ContentSecurityPolicy::class, $name);
		$p->setAccessible(true);
		return $p->getValue($obj);
	}

	public function testDefaultPolicyIsRestrictiveButFunctional(): void
	{
		$policy = (new CSPService(
			$this->createMock(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)
		))->getDefaultPolicy();

		$this->assertInstanceOf(ContentSecurityPolicy::class, $policy);

		// Matomo telemetry is the only remote script/connect origin.
		$scripts = $this->prop($policy, 'allowedScriptDomains');
		$connects = $this->prop($policy, 'allowedConnectDomains');
		$this->assertContains("'self'", $scripts);
		$this->assertContains('https://matomo.software-by-design.de', $scripts);
		$this->assertContains("'self'", $connects);
		$this->assertContains('https://matomo.software-by-design.de', $connects);

		// Clickjacking protection: frameable by self only.
		$ancestors = $this->prop($policy, 'allowedFrameAncestors');
		$this->assertContains("'self'", $ancestors);
		foreach ($ancestors as $a) {
			$this->assertSame("'self'", $a);
		}

		// Google Fonts (style + font only — never scripts).
		$fonts = $this->prop($policy, 'allowedFontDomains');
		$styles = $this->prop($policy, 'allowedStyleDomains');
		$this->assertContains('https://fonts.gstatic.com', $fonts);
		$this->assertContains('https://fonts.googleapis.com', $styles);
		$this->assertNotContains('https://fonts.googleapis.com', $scripts);

		// No wasm-eval, no wildcard script origins (NC35 removed the JS-eval flag
		// entirely — eval is simply not part of the policy surface anymore).
		$this->assertFalse((bool)$this->prop($policy, 'evalWasmAllowed'));
		$this->assertNotContains('*', $scripts);
		$this->assertNotContains('https:', $scripts);
	}

	public function testApplyPolicyWithNoncePerContext(): void
	{
		$nonceManager = $this->createMock(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class);
		$nonceManager->method('getNonce')->willReturn('deadbeefnonce');
		$svc = new CSPService($nonceManager);

		foreach (['main', 'admin', 'modal', 'guest', 'unknown-context'] as $context) {
			$response = new \OCP\AppFramework\Http\TemplateResponse('arbeitszeitcheck', 'main', []);
			$out = $svc->applyPolicyWithNonce($response, $context);

			$this->assertSame($response, $out);
			$this->assertSame('deadbeefnonce', $out->getParams()['cspNonce'] ?? null);
			$policy = $out->getContentSecurityPolicy();
			$this->assertInstanceOf(ContentSecurityPolicy::class, $policy);
			$this->assertContains("'self'", $this->prop($policy, 'allowedScriptDomains'));
			$headers = $out->getHeaders();
			$this->assertSame('SAMEORIGIN', $headers['X-Frame-Options'] ?? null);
			$this->assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
			$this->assertSame('no-referrer', $headers['Referrer-Policy'] ?? null);
		}
	}
}