<?php

declare(strict_types=1);

/**
 * CSP Service for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

/**
 * CSP nonce manager: Nextcloud does not expose an OCP interface for the nonce manager.
 * We use the internal class intentionally; migrate to OCP once available.
 *
 * @see https://github.com/nextcloud/server/issues (IContentSecurityPolicyNonceManager)
 */
use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;

/**
 * Centralized CSP policy management for ArbeitszeitCheck
 */
class CSPService
{
	public function __construct(
		private readonly ContentSecurityPolicyNonceManager $nonceManager,
	) {
	}
    /**
     * Base policy shared by all contexts.
     * 
     * Note: Nextcloud core will merge this with its default policy.
     * We add external font domains here to allow Google Fonts used by Nextcloud themes.
     */
    public function getDefaultPolicy(): ContentSecurityPolicy
    {
        $policy = new ContentSecurityPolicy();

        // Scripts, styles, images, fonts, media, and connections from self
        $policy->addAllowedScriptDomain("'self'");
        $policy->addAllowedStyleDomain("'self'");
        $policy->addAllowedImageDomain("'self'");
        $policy->addAllowedFontDomain("'self'");
        $policy->addAllowedMediaDomain("'self'");
        $policy->addAllowedConnectDomain("'self'");

        // Allow data/blob where commonly needed
        $policy->addAllowedImageDomain('data:');
        $policy->addAllowedImageDomain('blob:');
        $policy->addAllowedFontDomain('data:');
        $policy->addAllowedMediaDomain('blob:');

        // Clickjacking protection (allow framing by self only)
        $policy->addAllowedFrameAncestorDomain("'self'");

        // Allow Google Fonts used by some Nextcloud themes.
        // Note: this relaxes CSP only for font and style sources, not for scripts.
        $policy->addAllowedFontDomain('https://fonts.gstatic.com');
        $policy->addAllowedFontDomain('fonts.gstatic.com');
        $policy->addAllowedFontDomain('*.googleusercontent.com');
        $policy->addAllowedStyleDomain('https://fonts.googleapis.com');
        $policy->addAllowedStyleDomain('fonts.googleapis.com');

        // IMPORTANT: We intentionally do NOT enable eval in scripts (`unsafe-eval`).
        // Nextcloud 31 ships with a strict CSP by default and does not require eval for core code.
        // Keeping eval disabled significantly reduces XSS risk and avoids adding `unsafe-eval`
        // or `wasm-unsafe-eval` to `script-src` / `script-src-elem`.

        return $policy;
    }

    public function getMainAppPolicy(): ContentSecurityPolicy
    {
        $policy = $this->getDefaultPolicy();

        // Add Google Fonts for main app pages (dashboard, time entries, etc.)
        // These are used by Nextcloud themes and UI components
        $policy->addAllowedFontDomain('https://fonts.gstatic.com');
        $policy->addAllowedFontDomain('fonts.gstatic.com');
        $policy->addAllowedStyleDomain('https://fonts.googleapis.com');
        $policy->addAllowedStyleDomain('fonts.googleapis.com');

        return $policy;
    }

    public function getModalPolicy(): ContentSecurityPolicy
    {
        return $this->getDefaultPolicy();
    }

    public function getGuestPolicy(): ContentSecurityPolicy
    {
        return $this->getDefaultPolicy();
    }

    public function getAdminPolicy(): ContentSecurityPolicy
    {
        return $this->getDefaultPolicy();
    }

    /**
     * Apply CSP and inject a template nonce parameter.
     * 
     * Nextcloud core merges app CSP policies with its own default policy.
     * We add our policy additions (like external fonts) which will be merged
     * with core's policy, not override it.
     */
    public function applyPolicyWithNonce(TemplateResponse $response, string $context): TemplateResponse
    {
        // Expose nonce to templates that use inline tags
        $params = $response->getParams();
        $params['cspNonce'] = $this->nonceManager->getNonce();
        $response->setParams($params);

        // Get the appropriate policy for this context
        switch ($context) {
            case 'guest':
                $policy = $this->getGuestPolicy();
                break;
            case 'modal':
                $policy = $this->getModalPolicy();
                break;
            case 'admin':
                $policy = $this->getAdminPolicy();
                break;
            case 'main':
            default:
                $policy = $this->getMainAppPolicy();
                break;
        }

        // Set the policy - Nextcloud core will merge this with its default policy
        $response->setContentSecurityPolicy($policy);
        
        // Add additional security headers
        // Note: Nextcloud core already sets these via .htaccess and Response::getHeaders(),
        // but we set them explicitly here to ensure they're present even if core defaults change
        $response->addHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        $response->addHeader('X-XSS-Protection', '1; mode=block');
        $response->addHeader('Referrer-Policy', 'no-referrer');
        
        // Strict-Transport-Security should be set by web server for HTTPS
        // We don't set it here as it should be configured at server level
        
        return $response;
    }
}
