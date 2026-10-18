<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\ExternalStamp;
use OCA\ArbeitszeitCheck\Db\ExternalStampMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/**
 * Suite flanges F16/F19b — worker-confirmed draft stamps from sibling apps.
 *
 * A proposed stamp is evidence, never counted time: `confirm()` promotes it
 * through the regular compliance path (stamp → clock-in, segment → completed
 * entry with automatic breaks and the daily-maximum adjustment), `dismiss()`
 * discards it. Every decision is journaled. Decisions are idempotent —
 * repeating the same decision returns the stored outcome; contradicting a
 * stored decision is a 409.
 */
class ExternalStampService
{
	/** Producer apps that may write through the facade. */
	private const ALLOWED_SOURCES = ['tourcheck', 'carecheck'];
	private const REF_PATTERN = '/^[A-Za-z0-9:_-]{1,96}$/';
	/** Reject evidence pointing at timestamps before a sane lower bound. */
	private const MIN_TS = 946684800; // 2000-01-01 UTC
	/** A proposed segment longer than a day is corrupt, not a marathon shift. */
	private const MAX_SEGMENT_S = 86400;
	/** Small future-grace for clock skew between producer and server. */
	private const FUTURE_GRACE_S = 300;

	public function __construct(
		private readonly ExternalStampMapper $stamps,
		private readonly TimeEntryMapper $timeEntryMapper,
		private readonly AuditLogMapper $auditLog,
		private readonly TimeTrackingService $timeTrackingService,
		private readonly MobileStampReplayService $stampReplayService,
		private readonly ComplianceService $complianceService,
		private readonly MonthClosureGuard $monthClosureGuard,
		private readonly IUserManager $userManager,
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly IL10N $l10n,
		private readonly ILockingProvider $lockingProvider,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Propose a point-in-time stamp (F16: TourCheck first scan) or a worked
	 * segment (F19b: CareCheck `check:work-log` pull). Idempotent on
	 * (source_app, source_ref): replays return the existing row untouched.
	 *
	 * @return array{stamp: ExternalStamp, created: bool}|null null when the
	 *         input is invalid or the target user does not exist — the caller
	 *         (facade/job) decides how to surface it.
	 */
	public function propose(string $uid, int $startTs, ?int $endTs, string $sourceApp, string $sourceRef): ?array
	{
		if (!in_array($sourceApp, self::ALLOWED_SOURCES, true)
			|| preg_match(self::REF_PATTERN, $sourceRef) !== 1
			|| $this->userManager->get($uid) === null) {
			return null;
		}
		$now = time();
		if ($startTs < self::MIN_TS || $startTs > $now + self::FUTURE_GRACE_S) {
			return null;
		}
		$kind = ExternalStamp::KIND_STAMP;
		if ($endTs !== null) {
			if ($endTs <= $startTs || $endTs > $now + self::FUTURE_GRACE_S
				|| ($endTs - $startTs) > self::MAX_SEGMENT_S) {
				return null;
			}
			$kind = ExternalStamp::KIND_SEGMENT;
		}

		$existing = $this->stamps->findBySourceRef($sourceApp, $sourceRef);
		if ($existing !== null) {
			return ['stamp' => $existing, 'created' => false];
		}

		$stamp = new ExternalStamp();
		$stamp->setUserId($uid);
		$stamp->setKind($kind);
		$stamp->setStartTs($startTs);
		$stamp->setEndTs($endTs);
		$stamp->setSourceApp($sourceApp);
		$stamp->setSourceRef($sourceRef);
		$stamp->setStatus(ExternalStamp::STATUS_PENDING);
		$stamp->setCreatedAt(AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config));

		$inserted = $this->stamps->tryInsert($stamp);
		if ($inserted === null) {
			// Lost the propose race — the winner's row is the outcome.
			$existing = $this->stamps->findBySourceRef($sourceApp, $sourceRef);
			return $existing !== null ? ['stamp' => $existing, 'created' => false] : null;
		}
		$this->auditLog->logAction(
			$uid,
			'external_stamp_proposed',
			'external_stamp',
			(int)$inserted->getId(),
			null,
			['source_app' => $sourceApp, 'source_ref' => $sourceRef, 'kind' => $kind],
			$sourceApp,
		);
		return ['stamp' => $inserted, 'created' => true];
	}

	/** @return list<ExternalStamp> */
	public function listPending(string $uid): array
	{
		return $this->stamps->findPendingForUser($uid);
	}

	public function countPending(string $uid): int
	{
		return $this->stamps->countPendingForUser($uid);
	}

	/**
	 * Worker confirms: promote the stamp into the real time ledger.
	 * Idempotent — a repeated confirm returns the stored promoted entry.
	 *
	 * @return array{timeEntryId: int}
	 */
	public function confirm(string $uid, int $id): array
	{
		return $this->decide($uid, $id, true);
	}

	public function dismiss(string $uid, int $id): void
	{
		$this->decide($uid, $id, false);
	}

	/**
	 * @return array{timeEntryId: ?int}
	 */
	private function decide(string $uid, int $id, bool $confirm): array
	{
		$lock = 'azc_ext_stamp_decide_' . $id;
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$this->db->beginTransaction();
			try {
				$stamp = $this->stamps->findForUserLocked($id, $uid);
				if ($stamp === null) {
					throw new BusinessRuleException('Not found.', 'not_found');
				}
				if ($stamp->getStatus() !== ExternalStamp::STATUS_PENDING) {
					$same = $stamp->getStatus() === ($confirm ? ExternalStamp::STATUS_CONFIRMED : ExternalStamp::STATUS_DISMISSED);
					$entryId = $stamp->getPromotedEntryId();
					$this->db->commit();
					if ($same) {
						return ['timeEntryId' => $entryId];
					}
					throw new BusinessRuleException(
						$this->l10n->t('This suggestion was already decided.'),
						'already_decided',
					);
				}

				$entryId = null;
				if ($confirm) {
					$entryId = $this->promote($stamp);
					$stamp->setPromotedEntryId($entryId);
				}
				$stamp->setStatus($confirm ? ExternalStamp::STATUS_CONFIRMED : ExternalStamp::STATUS_DISMISSED);
				$stamp->setDecidedBy($uid);
				$stamp->setDecidedAt(AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config));
				$this->stamps->update($stamp);
				$this->db->commit();
			} catch (\Throwable $e) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
				throw $e;
			}
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}

		$this->auditLog->logAction(
			$uid,
			$confirm ? 'external_stamp_confirmed' : 'external_stamp_dismissed',
			'external_stamp',
			(int)$stamp->getId(),
			['status' => ExternalStamp::STATUS_PENDING],
			['status' => $stamp->getStatus(), 'promoted_entry_id' => $entryId],
			$uid,
		);
		return ['timeEntryId' => $entryId];
	}

	/**
	 * Promotion runs inside the caller's transaction + row lock. A failed
	 * promotion rolls the decision back with it — a stamp is never marked
	 * confirmed without a ledger entry, and never re-promotes on retry
	 * (replay id 'azc_ext:{id}' dedupes the stamp path).
	 */
	private function promote(ExternalStamp $stamp): int
	{
		$uid = $stamp->getUserId();
		if ($stamp->getKind() === ExternalStamp::KIND_STAMP) {
			$payload = $this->stampReplayService->clockIn(
				$uid,
				null,
				$this->sourceLabel($stamp),
				'azc_ext_stamp_' . (int)$stamp->getId(),
				(new \DateTimeImmutable('@' . $stamp->getStartTs()))->format(\DateTimeInterface::ATOM),
				null,
			);
			$entryId = (int)($payload['timeEntry']['id'] ?? 0);
			if ($entryId <= 0) {
				throw new BusinessRuleException('Promotion produced no time entry.', 'promote_failed');
			}
			return $entryId;
		}

		$startTs = $stamp->getStartTs();
		$endTs = (int)$stamp->getEndTs();
		$storageTz = AppLocalNaiveDateTimeNormalizer::appStorageTimeZoneFromConfig($this->config);
		$start = (new \DateTimeImmutable('@' . $startTs))->setTimezone($storageTz);
		$end = (new \DateTimeImmutable('@' . $endTs))->setTimezone($storageTz);
		$this->monthClosureGuard->assertUserDayMutable($uid, \DateTime::createFromImmutable($start));

		$entryId = 0;
		$this->timeTrackingService->withUserMutationLock($uid, function () use ($uid, $start, $end, $stamp, &$entryId) {
			$restCheck = $this->complianceService->checkRestPeriodForStartTime($uid, \DateTime::createFromImmutable($start));
			if (!$restCheck['valid']) {
				throw new BusinessRuleException((string)$restCheck['message'], 'rest_period');
			}
			$entry = new TimeEntry();
			$entry->setUserId($uid);
			$entry->setStartTime(\DateTime::createFromImmutable($start));
			$entry->setEndTime(\DateTime::createFromImmutable($end));
			$entry->setDescription($this->sourceLabel($stamp));
			// Final class — resolved lazily like other AZC optional services.
			$wl = \OCP\Server::get(UserWorkLocationService::class);
			$entry->setWorkLocation($wl->resolveForNewEntry($uid, null, \DateTime::createFromImmutable($start)));
			$entry->setStatus(TimeEntry::STATUS_COMPLETED);
			$entry->setIsManualEntry(false);
			$entry->setJustification('External stamp confirmed by worker');
			$nowAt = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
			$entry->setCreatedAt($nowAt);
			$entry->setUpdatedAt(clone $nowAt);
			$this->timeTrackingService->calculateAndSetAutomaticBreak($entry);
			$this->timeTrackingService->adjustEndTimeForDailyMaximum($entry);
			$entry = $this->timeEntryMapper->insert($entry);
			$entryId = (int)$entry->getId();
		});
		return $entryId;
	}

	private function sourceLabel(ExternalStamp $stamp): string
	{
		return match ($stamp->getSourceApp()) {
			'tourcheck' => $this->l10n->t('Confirmed stamp from TourCheck'),
			'carecheck' => $this->l10n->t('Confirmed stamp from CareCheck'),
			default => $this->l10n->t('Confirmed stamp'),
		};
	}
}
