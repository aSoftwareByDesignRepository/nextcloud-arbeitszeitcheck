<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Exception\NotAppAdminException;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\OvertimePayoutAuditService;
use OCA\ArbeitszeitCheck\Service\OvertimePayoutService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Util;

/**
 * Admin-only overtime bank payout (Auszahlung) workflows.
 */
class OvertimePayoutController extends Controller
{
	use CSPTrait;
	use PageShellTrait;

	protected PermissionService $permissionService;
	protected IUserSession $userSession;
	protected IURLGenerator $urlGenerator;
	protected IL10N $l10n;
	protected LocaleFormatService $localeFormat;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OvertimePayoutService $payoutService,
		private readonly OvertimePayoutAuditService $auditService,
		private readonly OvertimeBankService $bankService,
		private readonly MonthClosureService $monthClosureService,
		PermissionService $permissionService,
		IUserSession $userSession,
		CSPService $cspService,
		IURLGenerator $urlGenerator,
		LocaleFormatService $localeFormat,
		IL10N $l10n,
	) {
		parent::__construct($appName, $request);
		$this->permissionService = $permissionService;
		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->localeFormat = $localeFormat;
		$this->l10n = $l10n;
		$this->setCspService($cspService);
	}

	/**
	 * @return array{showSubstitutionLink: bool, showManagerLink: bool, showReportsLink: bool, showAdminNav: bool}
	 */
	private function buildAdminNavFlags(): array
	{
		return [
			'showSubstitutionLink' => false,
			'showManagerLink' => true,
			'showReportsLink' => true,
			'showAdminNav' => true,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildAdminShellParams(string $pageId, string $title, string $help): array
	{
		return $this->buildShellParams($pageId, $title, $help, $this->buildAdminNavFlags(), $this->l10n->t('Administration'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function auditIndex(): TemplateResponse
	{
		$this->assertAppAdmin();

		$this->registerFrontEndAssets(
			'admin-overtime-payout-audit',
			'admin-overtime-payout-audit',
			['admin-notifications'],
			['common/admin-user-picker'],
		);

		$now = new \DateTime();
		$defaultYear = (int)$now->format('Y');
		$monthLabels = [];
		$fmt = new \IntlDateFormatter(
			$this->l10n->getLanguageCode(),
			\IntlDateFormatter::NONE,
			\IntlDateFormatter::NONE,
			null,
			null,
			'MMMM',
		);
		for ($m = 1; $m <= 12; $m++) {
			$dt = \DateTime::createFromFormat('!Y-n-j', sprintf('%d-%d-1', $defaultYear, $m));
			$monthLabels[$m] = $dt !== false ? (string)$fmt->format($dt) : (string)$m;
		}

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-overtime-payout-audit', $this->buildAdminShellParams(
			'admin-overtime-payout-audit',
			$this->l10n->t('Overtime payout audit'),
			$this->l10n->t('Search and review recorded overtime payouts'),
		) + [
			'defaultYear' => $defaultYear,
			'monthLabels' => $monthLabels,
			'bankEnabled' => $this->bankService->isEnabled(),
			'payoutProcessUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.overtime_payout.index'),
			'adminUserSearchUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.admin.getUsers'),
		]);

		return $this->configureCSP($response, 'admin');
	}

	#[NoAdminRequired]
	public function listAudit(): JSONResponse
	{
		try {
			$this->assertAppAdmin();
			$year = $this->request->getParam('year');
			$month = $this->request->getParam('month');
			$userId = trim((string)($this->request->getParam('userId') ?? ''));
			$limit = max(1, min(500, (int)($this->request->getParam('limit') ?? 100)));
			$offset = max(0, (int)($this->request->getParam('offset') ?? 0));

			if ($year === null || $year === '') {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Year is required.'),
				], Http::STATUS_BAD_REQUEST);
			}

			$yearInt = (int)$year;
			if ($yearInt < 2000 || $yearInt > 2100) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Year must be between 2000 and 2100.'),
				], Http::STATUS_BAD_REQUEST);
			}

			$monthInt = null;
			if ($month !== null && $month !== '') {
				$monthInt = (int)$month;
				if ($monthInt < 1 || $monthInt > 12) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Month must be between 1 and 12.'),
					], Http::STATUS_BAD_REQUEST);
				}
			}

			if ($userId !== '' && (strlen($userId) > 64 || !preg_match('/^[a-zA-Z0-9_@.\-]+$/', $userId))) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid user ID filter.'),
				], Http::STATUS_BAD_REQUEST);
			}

			$userFilter = $userId !== '' ? $userId : null;

			$data = $this->auditService->listAuditEntries($yearInt, $monthInt, $userFilter, $limit, $offset);
			$gaps = $this->auditService->findComplianceGaps($yearInt, $monthInt, 50);
			$shown = count($data['items'] ?? []);

			return new JSONResponse([
				'success' => true,
				'data' => $data,
				'compliance_gaps' => $gaps,
				'meta' => [
					'limit' => $limit,
					'offset' => $offset,
					'shown' => $shown,
					'truncated' => ($data['total'] ?? 0) > ($offset + $shown),
				],
			]);
		} catch (\RuntimeException $e) {
			if ($e->getMessage() === 'forbidden') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Access denied')], Http::STATUS_FORBIDDEN);
			}

			return new JSONResponse(['success' => false, 'error' => $this->l10n->t($e->getMessage())], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('OvertimePayoutController::listAudit: ' . $e->getMessage(), ['exception' => $e]);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function adminMonthClosurePdf(): DataDownloadResponse|JSONResponse
	{
		try {
			$this->assertAppAdmin();
			$targetUserId = trim((string)($this->request->getParam('userId') ?? ''));
			$year = (int)($this->request->getParam('year') ?? 0);
			$month = (int)($this->request->getParam('month') ?? 0);
			if ($targetUserId === '' || $year < 1970 || $year > 2100 || $month < 1 || $month > 12) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Invalid request.')], Http::STATUS_BAD_REQUEST);
			}
			if (!$this->monthClosureService->isMonthFinalized($targetUserId, $year, $month)) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('This month is not finalized yet.')], Http::STATUS_BAD_REQUEST);
			}

			$user = $this->userSession->getUser();
			$actorName = $user !== null ? $user->getDisplayName() : '';
			$pdf = $this->monthClosureService->buildPdfContent($targetUserId, $year, $month, $actorName, $this->l10n);
			$filename = sprintf('month-closure-%s-%04d-%02d.pdf', $targetUserId, $year, $month);

			return new DataDownloadResponse($pdf, $filename, 'application/pdf');
		} catch (\RuntimeException $e) {
			if ($e->getMessage() === 'forbidden') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Access denied')], Http::STATUS_FORBIDDEN);
			}

			return new JSONResponse(['success' => false, 'error' => $this->l10n->t($e->getMessage())], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('OvertimePayoutController::adminMonthClosurePdf: ' . $e->getMessage(), ['exception' => $e]);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		$this->assertAppAdmin();

		$this->registerFrontEndAssets('admin-overtime-payouts', 'admin-overtime-payouts', ['admin-notifications']);

		$now = new \DateTime();
		$response = new TemplateResponse('arbeitszeitcheck', 'admin-overtime-payouts', $this->buildAdminShellParams(
			'admin-overtime-payouts',
			$this->l10n->t('Overtime payouts'),
			$this->l10n->t('Record month-end payout of overtime hours above the bank cap for payroll. Each payout is stored permanently for audit.'),
		) + [
			'defaultYear' => (int)$now->format('Y'),
			'defaultMonth' => (int)$now->format('n'),
			'bankEnabled' => $this->bankService->isEnabled(),
			'bankMaxHours' => $this->bankService->getBankMaxHours(),
		]);

		return $this->configureCSP($response, 'admin');
	}

	#[NoAdminRequired]
	public function listMonth(): JSONResponse
	{
		try {
			$this->assertAppAdmin();
			$year = (int)($this->request->getParam('year') ?? date('Y'));
			$month = (int)($this->request->getParam('month') ?? date('n'));
			$data = $this->payoutService->listMonthOverview($year, $month);

			return new JSONResponse(['success' => true, 'data' => $data]);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t($e->getMessage())], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('OvertimePayoutController::listMonth: ' . $e->getMessage(), ['exception' => $e]);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function processOne(): JSONResponse
	{
		try {
			$this->assertAppAdmin();
			$userId = trim((string)($this->request->getParam('userId') ?? ''));
			$year = (int)($this->request->getParam('year') ?? 0);
			$month = (int)($this->request->getParam('month') ?? 0);
			$dryRun = $this->toBool($this->request->getParam('dryRun') ?? false);

			if ($userId === '') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User is required.')], Http::STATUS_BAD_REQUEST);
			}

			$actor = $this->userSession->getUser()?->getUID() ?? 'unknown';
			$result = $this->payoutService->processPayout($userId, $year, $month, $actor, $dryRun);

			return new JSONResponse(['success' => true, 'result' => $result]);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t($e->getMessage())], Http::STATUS_BAD_REQUEST);
		} catch (\OCP\Lock\LockedException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('This overtime balance is being updated by another request. Please wait a moment and try again.'),
				'code' => 'OVERTIME_BALANCE_BUSY',
			], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('OvertimePayoutController::processOne: ' . $e->getMessage(), ['exception' => $e]);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function exportCsv(): DataDownloadResponse|JSONResponse
	{
		try {
			$this->assertAppAdmin();
			$year = (int)($this->request->getParam('year') ?? 0);
			$month = (int)($this->request->getParam('month') ?? 0);
			$csv = $this->payoutService->buildPayrollCsv($year, $month);
			$filename = sprintf('overtime-payouts-%04d-%02d.csv', $year, $month);

			return new DataDownloadResponse($csv, $filename, 'text/csv; charset=UTF-8');
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t($e->getMessage())], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('OvertimePayoutController::exportCsv: ' . $e->getMessage(), ['exception' => $e]);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function processBulk(): JSONResponse
	{
		try {
			$this->assertAppAdmin();
			$year = (int)($this->request->getParam('year') ?? 0);
			$month = (int)($this->request->getParam('month') ?? 0);
			$dryRun = $this->toBool($this->request->getParam('dryRun') ?? false);
			$userIds = $this->request->getParam('userIds');
			$ids = is_array($userIds) ? array_values(array_filter(array_map('strval', $userIds))) : null;

			$actor = $this->userSession->getUser()?->getUID() ?? 'unknown';
			$result = $this->payoutService->processBulkPayouts($year, $month, $actor, $ids, $dryRun);

			return new JSONResponse(['success' => true, 'result' => $result]);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t($e->getMessage())], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('OvertimePayoutController::processBulk: ' . $e->getMessage(), ['exception' => $e]);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function myHistory(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Unauthorized')], Http::STATUS_UNAUTHORIZED);
			}
			if (!$this->bankService->isEnabled()) {
				return new JSONResponse(['success' => true, 'data' => ['items' => [], 'total' => 0]]);
			}

			$userId = $user->getUID();
			$limit = max(1, min(100, (int)($this->request->getParam('limit') ?? 24)));
			$offset = max(0, (int)($this->request->getParam('offset') ?? 0));
			$data = $this->payoutService->listPayoutHistoryForUser($userId, $limit, $offset);

			return new JSONResponse(['success' => true, 'data' => $data]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('OvertimePayoutController::myHistory: ' . $e->getMessage(), ['exception' => $e]);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function toBool(mixed $value): bool
	{
		return $value === true || $value === 'true' || $value === '1' || $value === 1;
	}

	private function assertAppAdmin(): void
	{
		$user = $this->userSession->getUser();
		if ($user === null || !$this->permissionService->isAdmin($user->getUID())) {
			throw new NotAppAdminException($this->l10n->t('Access denied. You are not an ArbeitszeitCheck app administrator.'));
		}
	}
}
