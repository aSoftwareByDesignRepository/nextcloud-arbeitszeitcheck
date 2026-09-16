<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service\Kiosk;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\KioskSessionMapper;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Exception\TimeCaptureForbiddenException;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

class KioskActionService
{
	public function __construct(
		private readonly KioskAuthService $authService,
		private readonly KioskSessionMapper $sessionMapper,
		private readonly TimeTrackingService $timeTrackingService,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly KioskBusinessRuleMapper $businessRuleMapper,
		private readonly IL10N $l10n,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return array{newStatus: string, message: string}
	 */
	public function performAction(KioskTerminal $terminal, string $sessionToken, string $action): array
	{
		$session = $this->authService->validateSession($terminal, $sessionToken);
		$userId = $session->getUserId();
		$this->authService->assertUserEligibleForAction($userId);

		// Consume the one-shot session before mutating time entries so a replay
		// cannot clock in twice even if TimeTrackingService locks are delayed.
		$now = $this->timeFactory->getDateTime();
		if (!$this->sessionMapper->claimUnused($session, $now)) {
			// claimUnused false = already used OR expired in the validate→claim gap.
			// Never lie "USED" for an unused-but-expired row (labor-trust).
			if ($this->sessionMapper->findUsedSession($terminal->getTerminalId(), $sessionToken, $now) !== null) {
				throw new KioskException('KIOSK_SESSION_USED');
			}
			throw new KioskException('KIOSK_SESSION_INVALID');
		}

		try {
			$newStatus = match ($action) {
				'clock_in' => $this->doClockIn($userId),
				'clock_out' => $this->doClockOut($userId),
				'break_start' => $this->doStartBreak($userId),
				'break_end' => $this->doEndBreak($userId),
				default => throw new KioskException('KIOSK_ACTION_INVALID'),
			};
		} catch (KioskException $e) {
			// Invalid action string — keep the claim (client bug / replay).
			throw $e;
		} catch (MonthFinalizedException) {
			// Mutation did not apply — restore the session so the employee can retry
			// after IT un-finalizes, without scanning again.
			$this->sessionMapper->releaseClaim($session, $now);
			throw new KioskException('MONTH_FINALIZED');
		} catch (TimeCaptureForbiddenException) {
			$this->sessionMapper->releaseClaim($session, $now);
			throw new KioskException('KIOSK_CLOCK_STAMPING_DISABLED');
		} catch (BusinessRuleException $e) {
			$this->sessionMapper->releaseClaim($session, $now);
			throw $this->businessRuleMapper->toKioskException($e);
		} catch (\Throwable $e) {
			// Unexpected failure after claim (DB, etc.) — do not burn the one-shot session.
			$this->sessionMapper->releaseClaim($session, $now);
			throw $e;
		}

		$this->auditLogMapper->logAction($userId, 'kiosk_action', 'kiosk_session', $session->getId(), null, [
			'action' => $action,
			'terminalId' => $terminal->getTerminalId(),
			'newStatus' => $newStatus,
		], $userId);

		return [
			'newStatus' => $newStatus,
			'message' => $this->actionMessage($action),
		];
	}

	private function doClockIn(string $userId): string
	{
		$this->timeTrackingService->clockIn($userId);
		return 'working';
	}

	private function doClockOut(string $userId): string
	{
		$this->timeTrackingService->clockOut($userId);
		return 'off';
	}

	private function doStartBreak(string $userId): string
	{
		$this->timeTrackingService->startBreak($userId);
		return 'on_break';
	}

	private function doEndBreak(string $userId): string
	{
		$this->timeTrackingService->endBreak($userId);
		return 'working';
	}

	public function actionMessageFor(string $action): string
	{
		return $this->actionMessage($action);
	}

	private function actionMessage(string $action): string
	{
		return match ($action) {
			'clock_in' => $this->l10n->t('Clocked in'),
			'clock_out' => $this->l10n->t('Clocked out'),
			'break_start' => $this->l10n->t('Break started'),
			'break_end' => $this->l10n->t('Break ended'),
			default => $this->l10n->t('Action completed'),
		};
	}
}
