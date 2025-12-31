<?php

declare(strict_types=1);

/**
 * Page controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

/**
 * PageController
 */
class PageController extends Controller
{
	/**
	 * PageController constructor
	 *
	 * @param string $appName
	 * @param IRequest $request
	 */
	public function __construct(string $appName, IRequest $request)
	{
		parent::__construct($appName, $request);
	}

	/**
	 * Main index page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index(): TemplateResponse
	{
		// Load translations for the app
		Util::addTranslations('arbeitszeitcheck');
		// Try to add CSS stylesheet if it exists (extracted by webpack)
		// CSS may be embedded in JS until MiniCssExtractPlugin is properly configured
		try {
			Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');
		} catch (\Exception $e) {
			// CSS not found, might be embedded in JS - this is OK
		}
		// Add script using modern API (replaces deprecated script() function in template)
		Util::addScript('arbeitszeitcheck', 'arbeitszeitcheck-main');
		
		$response = new TemplateResponse('arbeitszeitcheck', 'index');
		// Disable caching to ensure fresh template is always served
		$response->cacheFor(0);
		// Add cache-busting headers
		$response->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
		$response->addHeader('Pragma', 'no-cache');
		$response->addHeader('Expires', '0');
		return $response;
	}

	/**
	 * Dashboard page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function dashboard(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');
		Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');
		Util::addScript('arbeitszeitcheck', 'arbeitszeitcheck-main');
		$response = new TemplateResponse('arbeitszeitcheck', 'index');
		$response->cacheFor(0);
		return $response;
	}

	/**
	 * Reports page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function reports(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');
		$response = new TemplateResponse('arbeitszeitcheck', 'index');
		$response->cacheFor(0);
		return $response;
	}

	/**
	 * Calendar page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function calendar(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');
		try {
			Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');
		} catch (\Exception $e) {
			// CSS not found, might be embedded in JS
		}
		Util::addScript('arbeitszeitcheck', 'arbeitszeitcheck-main');
		$response = new TemplateResponse('arbeitszeitcheck', 'index');
		$response->cacheFor(0);
		return $response;
	}

	/**
	 * Timeline page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function timeline(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');
		try {
			Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');
		} catch (\Exception $e) {
			// CSS not found, might be embedded in JS
		}
		Util::addScript('arbeitszeitcheck', 'arbeitszeitcheck-main');
		$response = new TemplateResponse('arbeitszeitcheck', 'index');
		$response->cacheFor(0);
		return $response;
	}
}