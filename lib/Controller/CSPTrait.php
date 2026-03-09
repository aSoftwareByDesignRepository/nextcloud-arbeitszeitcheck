<?php

declare(strict_types=1);

/**
 * CSP Trait for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCP\AppFramework\Http\TemplateResponse;
use OCA\ArbeitszeitCheck\Service\CSPService;

/**
 * Trait for configuring Content Security Policy
 */
trait CSPTrait
{
    /** @var CSPService */
    private CSPService $cspService;

    /**
     * Provide CSP service to classes using this trait
     */
    public function setCspService(CSPService $cspService): void
    {
        $this->cspService = $cspService;
    }

    /**
     * Configure CSP for template responses via centralized service.
     * Defaults to 'main' context when not specified by caller.
     */
    protected function configureCSP(TemplateResponse $response, string $context = 'main'): TemplateResponse
    {
        if (!isset($this->cspService)) {
            // If service isn't injected, return response unchanged to avoid fatal errors
            return $response;
        }
        return $this->cspService->applyPolicyWithNonce($response, $context);
    }
}
