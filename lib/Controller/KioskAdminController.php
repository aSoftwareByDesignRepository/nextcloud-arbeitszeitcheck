<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskCredentialService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskEnrollmentService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskErrorMessages;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskSettingsService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskTerminalService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TerminalDeviceService;
use OCA\ArbeitszeitCheck\Support\PeopleSearchQuery;
use OCA\ArbeitszeitCheck\Support\UserDirectorySearch;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Util;

class KioskAdminController extends Controller
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
		private readonly KioskTerminalService $terminalService,
		private readonly KioskCredentialService $credentialService,
		private readonly KioskEnrollmentService $enrollmentService,
		private readonly KioskSettingsService $settingsService,
		private readonly TerminalDeviceService $terminalDeviceService,
		private readonly KioskErrorMessages $kioskErrorMessages,
		private readonly IUserManager $userManager,
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

	/** @return array{showSubstitutionLink: bool, showManagerLink: bool, showReportsLink: bool, showAdminNav: bool} */
	private function buildAdminNavFlags(): array
	{
		return [
			'showSubstitutionLink' => false,
			'showManagerLink' => true,
			'showReportsLink' => true,
			'showAdminNav' => true,
		];
	}

	/** @return array<string, mixed> */
	private function buildAdminShellParams(string $pageId, string $title, string $help): array
	{
		return $this->buildShellParams($pageId, $title, $help, $this->buildAdminNavFlags(), $this->l10n->t('Administration'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		$this->registerFrontEndAssets('admin-kiosk', 'admin-kiosk', [], ['common/admin-user-picker']);

		$terminals = [];
		foreach ($this->terminalService->listTerminals() as $terminal) {
			$lastSeen = $terminal->getLastSeenAt();
			$terminals[] = [
				'terminalId' => $terminal->getTerminalId(),
				'label' => $terminal->getLabel(),
				'status' => $terminal->getStatus(),
				'lastSeenAt' => $lastSeen?->format('c'),
				'createdAt' => $terminal->getCreatedAt()->format('c'),
			];
		}

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-kiosk', array_merge(
			$this->buildAdminShellParams(
				'admin-kiosk',
				$this->l10n->t('Kiosk terminals'),
				$this->l10n->t('Manage foyer tablets, employee badges, and PIN credentials'),
			),
			[
				'kioskEnabled' => $this->settingsService->isKioskEnabled(),
				'terminals' => $terminals,
				'terminalDevicesUsed' => $this->terminalDeviceService->getActiveCount(),
				'terminalDevicesLimit' => $this->terminalDeviceService->getDeviceLimit(),
				'licenseAdminUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.license_admin.index'),
				'enrollmentTtlSeconds' => Constants::KIOSK_ENROLLMENT_TTL_SECONDS,
				'apiBase' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.kiosk_admin.listCredentials'),
				'apiTerminals' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.kiosk_admin.createTerminal'),
				'apiCredentials' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.kiosk_admin.listCredentials'),
				'apiRfid' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.kiosk_admin.assignRfid'),
				'apiPinGenerate' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.kiosk_admin.generatePin'),
				'apiEnrollmentStart' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.kiosk_admin.startEnrollment'),
				'apiEnrollmentStatus' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.kiosk_admin.enrollmentStatus'),
				'apiEnrollmentCancel' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.kiosk_admin.cancelEnrollment'),
				'apiKioskEnabled' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.kiosk_admin.setKioskEnabled'),
				'apiSearchUsers' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.kiosk_admin.searchUsers'),
				'apiTerminalRevoke' => $this->urlGenerator->linkToRoute(
					'arbeitszeitcheck.kiosk_admin.revokeTerminal',
					['terminalId' => '__ID__'],
				),
				'apiUserAllowed' => $this->urlGenerator->linkToRoute(
					'arbeitszeitcheck.kiosk_admin.setUserAllowed',
					['userId' => '__ID__'],
				),
				'requesttoken' => Util::callRegister(),
				'urlGenerator' => $this->urlGenerator,
				'i18n' => [
					'kioskEnabled' => $this->l10n->t('Kiosk enabled'),
					'kioskDisabled' => $this->l10n->t('Kiosk disabled'),
					'labelRequired' => $this->l10n->t('Enter a terminal label'),
					'terminalCreated' => $this->l10n->t('Terminal created — save the pairing code'),
					'terminalRevoked' => $this->l10n->t('Terminal revoked'),
					'confirmRevoke' => $this->l10n->t('Revoke this terminal? The tablet will need to be paired again.'),
					'revoke' => $this->l10n->t('Revoke'),
					'selectEmployeeTerminal' => $this->l10n->t('Select an employee and a tablet first'),
					'selectEmployee' => $this->l10n->t('Select an employee first'),
					'selectTerminal' => $this->l10n->t('Select a tablet for the scan'),
					'enableAccessFirst' => $this->l10n->t('Allow kiosk access for this employee first'),
					'enrollmentWaiting' => $this->l10n->t('Waiting for badge scan on the tablet…'),
					'enrollmentWaitingTitle' => $this->l10n->t('Scan in progress'),
					'enrollmentWaitingBody' => $this->l10n->t('Go to “{terminal}” and hold the badge on the reader. This page updates by itself.'),
					'enrollmentTimer' => $this->l10n->t('Time left: {time}'),
					'enrollmentDone' => $this->l10n->t('Badge assigned successfully'),
					'enrollmentDoneTitle' => $this->l10n->t('Badge saved'),
					'enrollmentExpired' => $this->l10n->t('Scan timed out. Click “Scan badge at tablet” to try again.'),
					'enrollmentExpiredTitle' => $this->l10n->t('Scan expired'),
					'enrollmentCancelled' => $this->l10n->t('Scan cancelled'),
					'enrollmentCancelledTitle' => $this->l10n->t('Cancelled'),
					'enrollmentCancelledBody' => $this->l10n->t('The badge scan was stopped. Nothing was saved. You can start again whenever you are ready.'),
					'enrollmentAlreadyDone' => $this->l10n->t('The badge was already saved on the tablet before cancel finished.'),
					'enrollmentAlreadyDoneTitle' => $this->l10n->t('Badge already saved'),
					'enrollmentBusy' => $this->l10n->t('A badge scan is already open for this tablet. Click “Cancel scan” first, then start again.'),
					'enrollmentCancelling' => $this->l10n->t('Stopping the scan…'),
					'enrollmentNoTerminal' => $this->l10n->t('No active tablet yet. Pair a terminal first.'),
					'enrollmentScanProblem' => $this->l10n->t('Problem while scanning'),
					'enrollmentScanRetryHint' => $this->l10n->t('The scan is still open — hold the badge again, or cancel and restart.'),
					'networkFailed' => $this->l10n->t('No connection to the server. Check your network and try again.'),
					'requestFailedDetail' => $this->l10n->t('The request failed (HTTP {status}). Refresh the page and try again.'),
					'errTerminalNotFound' => $this->l10n->t('Terminal not found. Refresh the page and select an active tablet.'),
					'errTerminalNotActive' => $this->l10n->t('Only a paired (active) tablet can enroll badges. Finish pairing first.'),
					'errBadgeAssigned' => $this->l10n->t('This badge is already assigned to another employee. Remove it there first, or use a different badge.'),
					'errBadgeInvalid' => $this->l10n->t('The badge could not be read. Hold it flat on the reader for 1–2 seconds and try again.'),
					'errEnrollmentInactive' => $this->l10n->t('No badge scan is waiting on this tablet. Click “Scan badge at tablet” again, then hold the badge.'),
					'errKioskBusy' => $this->l10n->t('Another PIN or badge change is still finishing. Wait a few seconds, then try again. If a badge scan is stuck, click “Cancel scan” — that always clears it.'),
					'enrollmentCancelForced' => $this->l10n->t('Cleared a stuck scan so you can start again.'),
					'errScanFailed' => $this->l10n->t('Badge could not be saved. Check tablet online status and kiosk access, then start the scan again.'),
					'errLicenseRequired' => $this->l10n->t('A Terminal license is required. Open License administration to apply a key.'),
					'errDeviceLimit' => $this->l10n->t('All terminal license slots are in use. Revoke an unused terminal or upgrade the license.'),
					'stepAllowHintReady' => $this->l10n->t('Turn on “Allow kiosk access” for this person, then continue with step 3.'),
					'stepAssignHintReady' => $this->l10n->t('Create a PIN or start a badge scan. You only need one method — or both.'),
					'stepAssignHintBlocked' => $this->l10n->t('Allow kiosk access for this employee first, then choose PIN or badge scan.'),
					'credentialRemoved' => $this->l10n->t('Credential removed'),
					'delete' => $this->l10n->t('Delete'),
					'deletePin' => $this->l10n->t('Delete PIN'),
					'deleteBadge' => $this->l10n->t('Delete badge'),
					'generatePin' => $this->l10n->t('Generate PIN'),
					'newPin' => $this->l10n->t('New PIN'),
					'confirmNewPin' => $this->l10n->t('Generate a new PIN for {name}? The previous PIN will stop working immediately.'),
					'kioskAllowedOn' => $this->l10n->t('Kiosk access enabled'),
					'kioskAllowedOff' => $this->l10n->t('Kiosk access disabled'),
					'kioskAllowedLabel' => $this->l10n->t('Allow kiosk access'),
					'pinTitle' => $this->l10n->t('PIN generated'),
					'pinHint' => $this->l10n->t('PIN is shown only once. Share it securely with the employee.'),
					'copyPin' => $this->l10n->t('Copy PIN'),
					'pinCopied' => $this->l10n->t('PIN copied'),
					'copyFailed' => $this->l10n->t('Could not copy. Please select the PIN and copy it manually.'),
					'shareFailed' => $this->l10n->t('Could not share. Please copy the PIN instead.'),
					'sharePin' => $this->l10n->t('Share…'),
					'sendByEmail' => $this->l10n->t('Send by email'),
					'sharePinSubject' => $this->l10n->t('Your ArbeitszeitCheck kiosk PIN'),
					'sharePinBody' => $this->l10n->t("Hello{nameSuffix},\n\nYour kiosk PIN for ArbeitszeitCheck is: {pin}\n\nKeep this PIN private. You can change it only by asking an administrator to generate a new one.\n"),
					'pinBusy' => $this->l10n->t('A PIN is already being generated. Please wait a moment.'),
					'close' => $this->l10n->t('Close'),
					'requestFailed' => $this->l10n->t('Request failed'),
					'yes' => $this->l10n->t('Yes'),
					'no' => $this->l10n->t('No'),
					'statusActive' => $this->l10n->t('Active'),
					'statusPending' => $this->l10n->t('Pending pairing'),
					'statusRevoked' => $this->l10n->t('Revoked'),
					'typePin' => $this->l10n->t('PIN'),
					'typeRfid' => $this->l10n->t('Badge'),
					'noCredentials' => $this->l10n->t('No badges or PINs yet. Select an employee above to get started.'),
					'neverSeen' => $this->l10n->t('Never'),
					'overviewOn' => $this->l10n->t('On'),
					'overviewOff' => $this->l10n->t('Off'),
					'cancelEnrollment' => $this->l10n->t('Cancel scan'),
					'pairingExpires' => $this->l10n->t('Valid until'),
					'confirmDeleteCred' => $this->l10n->t('Remove this credential? The employee will no longer be able to use it at the kiosk.'),
					'employee' => $this->l10n->t('Employee'),
					'credentials' => $this->l10n->t('Credentials'),
					'actions' => $this->l10n->t('Actions'),
					'stepSelect' => $this->l10n->t('1. Find the employee'),
					'stepAllow' => $this->l10n->t('2. Allow kiosk access'),
					'stepCredential' => $this->l10n->t('3. Assign a badge or PIN'),
				],
			],
		));

		return $this->configureCSP($response, 'admin');
	}

	#[NoAdminRequired]
	public function setKioskEnabled(): JSONResponse
	{
		$data = $this->readJsonBody();
		$enabled = !empty($data['enabled']);
		$this->settingsService->setKioskEnabled($enabled);
		return new JSONResponse(['success' => true, 'enabled' => $enabled]);
	}

	#[NoAdminRequired]
	public function createTerminal(): JSONResponse
	{
		$data = $this->readJsonBody();
		$label = trim((string)($data['label'] ?? ''));
		if ($label === '') {
			return new JSONResponse([
				'success' => false,
				'error' => 'label_required',
				'message' => $this->l10n->t('Enter a terminal label'),
			], Http::STATUS_BAD_REQUEST);
		}
		if (mb_strlen($label) > 128) {
			return new JSONResponse([
				'success' => false,
				'error' => 'label_too_long',
				'message' => $this->l10n->t('Terminal label is too long'),
			], Http::STATUS_BAD_REQUEST);
		}
		$actor = $this->userSession->getUser()?->getUID() ?? '';
		try {
			$result = $this->terminalService->createPendingTerminal($label, $actor);
			$response = new JSONResponse([
				'success' => true,
				'data' => [
					'terminalId' => $result['terminal']->getTerminalId(),
					'label' => $result['terminal']->getLabel(),
					'pairingCode' => $result['pairingCode'],
					'pairingExpiresAt' => $result['pairingExpiresAt'],
				],
			], Http::STATUS_CREATED);
			return $this->noStore($response);
		} catch (KioskException $e) {
			return $this->kioskError($e);
		}
	}

	#[NoAdminRequired]
	public function revokeTerminal(string $terminalId): JSONResponse
	{
		$this->terminalService->revoke($terminalId);
		return new JSONResponse(['success' => true]);
	}

	#[NoAdminRequired]
	public function listCredentials(): JSONResponse
	{
		$userId = trim((string)$this->request->getParam('userId', ''));
		$credentials = [];
		foreach ($this->credentialService->listCredentials($userId !== '' ? $userId : null) as $cred) {
			$user = $this->userManager->get($cred->getUserId());
			$credentials[] = [
				'id' => $cred->getId(),
				'userId' => $cred->getUserId(),
				'displayName' => $user !== null ? $user->getDisplayName() : $cred->getUserId(),
				'type' => $cred->getType(),
				'label' => $cred->getLabel(),
				'kioskAllowed' => $this->settingsService->isUserKioskAllowed($cred->getUserId()),
				'lockedUntil' => $cred->getLockedUntil()?->format('c'),
				'hasPin' => $cred->getType() === 'pin',
				'hasRfid' => $cred->getType() === 'rfid',
			];
		}
		return new JSONResponse(['success' => true, 'data' => ['credentials' => $credentials]]);
	}

	#[NoAdminRequired]
	public function assignRfid(): JSONResponse
	{
		$data = $this->readJsonBody();
		$userId = trim((string)($data['userId'] ?? ''));
		$rfidUid = trim((string)($data['rfidUid'] ?? ''));
		$label = isset($data['label']) ? trim((string)$data['label']) : null;
		$actor = $this->userSession->getUser()?->getUID() ?? '';
		try {
			$result = $this->credentialService->assignRfid($userId, $rfidUid, $actor, $label !== '' ? $label : null);
			return new JSONResponse(['success' => true, 'data' => $result], Http::STATUS_CREATED);
		} catch (KioskException $e) {
			return $this->kioskError($e);
		}
	}

	#[NoAdminRequired]
	public function generatePin(): JSONResponse
	{
		$data = $this->readJsonBody();
		$userId = trim((string)($data['userId'] ?? ''));
		$actor = $this->userSession->getUser()?->getUID() ?? '';
		try {
			$result = $this->credentialService->generatePin($userId, $actor);
			$response = new JSONResponse([
				'success' => true,
				'data' => [
					'pin' => $result['pin'],
					'message' => $this->l10n->t('PIN is shown only once'),
				],
			], Http::STATUS_CREATED);
			return $this->noStore($response);
		} catch (KioskException $e) {
			return $this->kioskError($e);
		}
	}

	#[NoAdminRequired]
	public function deleteCredential(int $id): JSONResponse
	{
		$actor = $this->userSession->getUser()?->getUID() ?? '';
		try {
			$this->credentialService->revoke($id, $actor);
			return new JSONResponse(['success' => true]);
		} catch (KioskException $e) {
			return $this->kioskError($e);
		}
	}

	#[NoAdminRequired]
	public function setUserAllowed(string $userId): JSONResponse
	{
		$userId = trim(rawurldecode($userId));
		if ($userId === '' || $this->userManager->get($userId) === null) {
			return new JSONResponse([
				'success' => false,
				'error' => 'KIOSK_USER_NOT_FOUND',
				'message' => $this->l10n->t('Employee not found'),
			], Http::STATUS_BAD_REQUEST);
		}
		$data = $this->readJsonBody();
		$allowed = !empty($data['kioskAllowed']);
		$this->settingsService->setUserKioskAllowed($userId, $allowed);
		return new JSONResponse(['success' => true, 'userId' => $userId, 'kioskAllowed' => $allowed]);
	}

	#[NoAdminRequired]
	public function importCredentials(): JSONResponse
	{
		$csv = (string)($this->request->getParam('csv') ?? '');
		if ($csv === '' && isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
			$tmp = (string)$_FILES['file']['tmp_name'];
			$size = (int)($_FILES['file']['size'] ?? 0);
			if ($size > 1_048_576) {
				return new JSONResponse([
					'success' => false,
					'error' => 'KIOSK_IMPORT_TOO_LARGE',
					'message' => $this->l10n->t('Import file is too large (max 1 MB)'),
				], Http::STATUS_BAD_REQUEST);
			}
			$csv = (string)file_get_contents($tmp);
		}
		$actor = $this->userSession->getUser()?->getUID() ?? '';
		try {
			$result = $this->credentialService->importCsv($csv, $actor);
			return new JSONResponse(['success' => true, 'data' => $result]);
		} catch (KioskException $e) {
			return $this->kioskError($e);
		}
	}

	#[NoAdminRequired]
	public function startEnrollment(): JSONResponse
	{
		$data = $this->readJsonBody();
		$userId = trim((string)($data['userId'] ?? ''));
		$terminalId = trim((string)($data['terminalId'] ?? ''));
		$actor = $this->userSession->getUser()?->getUID() ?? '';
		try {
			$result = $this->enrollmentService->start($userId, $terminalId, $actor);
			return new JSONResponse(['success' => true, 'data' => $result], Http::STATUS_CREATED);
		} catch (KioskException $e) {
			return $this->kioskError($e);
		}
	}

	#[NoAdminRequired]
	public function enrollmentStatus(): JSONResponse
	{
		$terminalId = trim((string)$this->request->getParam('terminalId', ''));
		return new JSONResponse(['success' => true, 'data' => $this->enrollmentService->getStatus($terminalId)]);
	}

	#[NoAdminRequired]
	public function cancelEnrollment(): JSONResponse
	{
		$data = $this->readJsonBody();
		$terminalId = trim((string)($data['terminalId'] ?? ''));
		if ($terminalId === '') {
			return new JSONResponse([
				'success' => false,
				'error' => 'KIOSK_TERMINAL_NOT_FOUND',
				'message' => $this->kioskErrorMessages->message('KIOSK_TERMINAL_NOT_FOUND'),
			], Http::STATUS_BAD_REQUEST);
		}
		$actor = $this->userSession->getUser()?->getUID() ?? '';
		try {
			$result = $this->enrollmentService->cancel($terminalId, $actor);
			return new JSONResponse(['success' => true, 'data' => $result]);
		} catch (KioskException $e) {
			return $this->kioskError($e);
		}
	}

	#[NoAdminRequired]
	public function searchUsers(): JSONResponse
	{
		$query = PeopleSearchQuery::fromRequest($this->request);
		$result = UserDirectorySearch::searchByIdOrName($this->userManager, $query, 25);
		$users = [];
		$seen = [];

		// Exact UID first so employee-profile deep-links always resolve, even when
		// fuzzy search pages omit the match (short UIDs, backend caps, etc.).
		$exact = $query !== '' ? $this->userManager->get($query) : null;
		if ($exact !== null && $exact->isEnabled()) {
			$uid = $exact->getUID();
			$seen[$uid] = true;
			$users[] = [
				'userId' => $uid,
				'displayName' => $exact->getDisplayName(),
				'email' => (string)($exact->getEMailAddress() ?? ''),
				'kioskAllowed' => $this->settingsService->isUserKioskAllowed($uid),
			];
		}

		foreach ($result['users'] as $user) {
			$uid = $user->getUID();
			if (isset($seen[$uid])) {
				continue;
			}
			$seen[$uid] = true;
			$users[] = [
				'userId' => $uid,
				'displayName' => $user->getDisplayName(),
				'email' => (string)($user->getEMailAddress() ?? ''),
				'kioskAllowed' => $this->settingsService->isUserKioskAllowed($uid),
			];
		}
		return new JSONResponse(['success' => true, 'users' => $users]);
	}

	/** @return array<string, mixed> */
	private function readJsonBody(): array
	{
		$body = file_get_contents('php://input');
		$data = is_string($body) ? json_decode($body, true) : null;
		return is_array($data) ? $data : $this->request->getParams();
	}

	private function noStore(JSONResponse $response): JSONResponse
	{
		$response->addHeader('Cache-Control', 'no-store, private');
		$response->addHeader('Pragma', 'no-cache');
		return $response;
	}

	private function kioskError(KioskException $e): JSONResponse
	{
		$code = $e->getErrorCode();
		$status = match ($code) {
			'KIOSK_RFID_ALREADY_ASSIGNED' => Http::STATUS_CONFLICT,
			'KIOSK_BUSY' => Http::STATUS_CONFLICT,
			'KIOSK_USER_NOT_ALLOWED', 'TERMINAL_DEVICE_LIMIT_REACHED', 'TERMINAL_LICENSE_REQUIRED' => Http::STATUS_FORBIDDEN,
			default => Http::STATUS_BAD_REQUEST,
		};
		return new JSONResponse([
			'success' => false,
			'error' => $code,
			'message' => $this->kioskErrorMessages->message($code),
		], $status);
	}
}
