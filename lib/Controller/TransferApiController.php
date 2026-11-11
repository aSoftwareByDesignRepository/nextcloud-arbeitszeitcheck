<?php

declare(strict_types=1);

/**
 * Admin API for config transfer — thin front-end over TransferService.
 *
 *   GET  /api/admin/transfer/status    job state + last receipt
 *   POST /api/admin/transfer/export    {passphrase} → .nctransfer download
 *   POST /api/admin/transfer/preview   multipart(file, passphrase, mode) → report
 *   POST /api/admin/transfer/apply     {pendingId, confirmation?} → queue job
 *   POST /api/admin/transfer/reconcile → rewrite mirrored keys (post-occ heal)
 *
 * App-agnostic by contract: the only app seam is ConfigTransferHooks (the
 * door-admin check) — error payloads use the family's canonical
 * {success:false,error,message,fields} shape (api-security §1), so no app
 * API classes are required.
 *
 * Permission model (spec AS-02/FS-2): everything requires the app
 * door-admin; Replace mode additionally requires a Nextcloud system admin.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\ConfigTransfer\ConfigTransferHooks;
use OCA\ArbeitszeitCheck\ConfigTransfer\TransferException;
use OCA\ArbeitszeitCheck\ConfigTransfer\TransferService;
use OCA\ArbeitszeitCheck\BackgroundJob\TransferApplyJob;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;

class TransferApiController extends Controller
{
	private const THROTTLE_ACTION = 'arbeitszeitcheck_transfer_import';

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConfigTransferHooks $hooks,
		private readonly TransferService $transfer,
		private readonly IJobList $jobList,
		private readonly IThrottler $throttler,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	// Read-only job/receipt state polled by the settings page — a session GET
	// without requesttoken must not bounce on CSRF (api_get_missing_nocsrf).
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(): DataResponse
	{
		return $this->guard(function (): array {
			return $this->transfer->status();
		});
	}

	#[NoAdminRequired]
	public function export(): DataResponse|DataDownloadResponse
	{
		try {
			$this->requireAdmin();
			$passphrase = (string)($this->request->getParam('passphrase', '') ?? '');
			$bytes = $this->transfer->exportContainer($passphrase, false);
		} catch (\Throwable $e) {
			return $this->errorFor($e);
		}
		return new DataDownloadResponse(
			$bytes,
			'arbeitszeitcheck-transfer-' . gmdate('Ymd-Hi') . '.nctransfer',
			'application/json',
		);
	}

	#[NoAdminRequired]
	public function preview(): DataResponse
	{
		return $this->guard(function (): array {
			$user = $this->requireAdmin();
			$mode = (string)($this->request->getParam('mode', TransferService::MODE_MERGE) ?? TransferService::MODE_MERGE);
			if ($mode === TransferService::MODE_REPLACE && !$this->groupManager->isAdmin($user)) {
				// Early refusal — the mode stays refused at apply too, but a
				// non-sysadmin should never burn a decrypt on it (FS-2).
				throw new TransferException('TRANSFER_ADMIN_REQUIRED');
			}
			try {
				$this->throttler->sleepDelayOrThrowOnMax(
					$this->request->getRemoteAddress(),
					self::THROTTLE_ACTION,
				);
			} catch (\Throwable) {
				throw new TransferException('TRANSFER_THROTTLED', ['retryAfter' => 60]);
			}
			$passphrase = (string)($this->request->getParam('passphrase', '') ?? '');
			$bytes = $this->readUpload($this->request->getUploadedFile('file'));
			try {
				return $this->transfer->preview($bytes, $passphrase, $mode, $user);
			} catch (TransferException $e) {
				if ($e->isFileError()) {
					$this->throttler->registerAttempt(
						self::THROTTLE_ACTION,
						$this->request->getRemoteAddress(),
					);
				}
				throw $e;
			}
		});
	}

	#[NoAdminRequired]
	public function apply(): DataResponse
	{
		return $this->guard(function (): array {
			$user = $this->requireAdmin();
			$pendingId = (string)($this->request->getParam('pendingId', '') ?? '');
			// The RECORDED mode gates — a client cannot downgrade a replace
			// preview into an unconfirmed merge apply.
			$mode = $this->transfer->pendingMode($pendingId);
			$replaceConfirmed = false;
			if ($mode === TransferService::MODE_REPLACE) {
				if (!$this->groupManager->isAdmin($user)) {
					throw new TransferException('TRANSFER_ADMIN_REQUIRED');
				}
				if ((string)($this->request->getParam('confirmation', '') ?? '') !== TransferService::REPLACE_TOKEN) {
					throw new TransferException('TRANSFER_CONFIRM_REQUIRED');
				}
				$replaceConfirmed = true;
			}
			// Refuse before queueing: a job killed by an env gate dies before
			// the first job-state/receipt write and would leave the status
			// surface showing a stale previous run. applyPayload re-checks.
			$this->transfer->assertModeRunnable($mode);
			$this->jobList->add(TransferApplyJob::class, [
				'pendingId' => $pendingId,
				'actor' => $user,
				'replaceConfirmed' => $replaceConfirmed,
			]);
			return ['queued' => true, 'pendingId' => $pendingId];
		});
	}

	#[NoAdminRequired]
	public function reconcile(): DataResponse
	{
		return $this->guard(function (): array {
			$this->requireAdmin();
			return $this->transfer->reconcile();
		});
	}

	// ── internals ──────────────────────────────────────────────────────

	private function actor(): string
	{
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}

	/** @throws \Throwable whatever the app's door-admin check throws */
	private function requireAdmin(): string
	{
		$user = $this->actor();
		$this->hooks->requireTransferAdmin($user);
		return $user;
	}

	/**
	 * Every endpoint funnels here: permission failures AND typed transfer
	 * failures collapse into the family's canonical error payload — no
	 * existence oracle, no stack traces (api-security §1/§10 FS-13).
	 */
	private function guard(callable $fn): DataResponse
	{
		try {
			$this->requireAdmin();
			return new DataResponse(['success' => true, 'data' => $fn()], Http::STATUS_OK);
		} catch (\Throwable $e) {
			return $this->errorFor($e);
		}
	}

	private function errorFor(\Throwable $e): DataResponse
	{
		if ($e instanceof TransferException) {
			// Uniform pre-decrypt wording: no oracle distinguishing a wrong
			// passphrase from a corrupt file (spec AC-4c / FS-9).
			$code = $e->isFileError() ? 'TRANSFER_CANNOT_READ_FILE' : $e->getMessage();
			$status = $e->getHttpStatus();
			if ($status === Http::STATUS_INTERNAL_SERVER_ERROR) {
				$status = Http::STATUS_UNPROCESSABLE_ENTITY;
			}
			$fields = isset($e->getContext()['field']) ? [(string)$e->getContext()['field'] => $code] : [];
			return new DataResponse([
				'success' => false,
				'error' => $code,
				'message' => $code,
				'fields' => $fields,
				'context' => $e->getContext(),
			], $status);
		}
		// Any other throwable (door-admin denial, app exceptions) is a
		// permission/boundary failure — canonical NOT_FOUND collapse.
		return new DataResponse([
			'success' => false,
			'error' => 'NOT_FOUND',
			'message' => 'Not found.',
			'fields' => [],
		], Http::STATUS_NOT_FOUND);
	}

	/**
	 * @param array<string,mixed>|null $file
	 */
	private function readUpload(?array $file): string
	{
		if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			throw new TransferException('TRANSFER_FILE_INVALID');
		}
		$size = (int)($file['size'] ?? 0);
		if ($size <= 0 || $size > TransferService::UI_MAX_FILE_BYTES) {
			throw new TransferException('TRANSFER_FILE_TOO_LARGE', ['limit' => TransferService::UI_MAX_FILE_BYTES]);
		}
		$tmp = (string)($file['tmp_name'] ?? '');
		$bytes = $tmp !== '' && is_file($tmp) ? file_get_contents($tmp) : false;
		if ($bytes === false || $bytes === '') {
			throw new TransferException('TRANSFER_FILE_INVALID');
		}
		return $bytes;
	}
}
