<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSet;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;

/**
 * Versioned rule-set catalog (spec §9.3). Rule sets are immutable once
 * they leave `draft`; every change creates a new version row with a
 * sha256 config hash.
 */
final class BusinessTripRuleCatalog
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ILockingProvider $lockingProvider,
		private readonly BusinessTripRuleSetMapper $ruleSets,
		private readonly BusinessTripRuleConfigValidator $validator,
	) {
	}

	/**
	 * Atomic activation per code: exactly one row may hold `active` per
	 * code. Runs under the catalog lock (first tier of the spec lock order)
	 * so concurrent activations can never produce two active versions.
	 */
	public function activate(int $ruleSetId, string $actor): BusinessTripRuleSet
	{
		$lock = DbLockKeys::businessTripRuleCatalog();
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Business-trip rule catalog');
		try {
			try {
				$target = $this->ruleSets->find($ruleSetId);
			} catch (DoesNotExistException) {
				throw new BusinessTripException('RULE_SET_NOT_FOUND', 'Rule set not found.', 404);
			}
			// Spec §10.5: legal review is a release gate — no profile version
			// may go live without a recorded reviewer and timestamp.
			if ($target->getReviewedAt() === null || $target->getReviewedBy() === null || (string)$target->getReviewedBy() === '') {
				throw new BusinessTripException('RULE_REVIEW_REQUIRED', 'A documented legal review is required before activation.', 409, ['reviewedBy' => 'required']);
			}
			$this->db->beginTransaction();
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->update('at_btrip_ruleset')
					->set('status', $qb->createNamedParameter(BusinessTripRuleSet::STATUS_RETIRED))
					->set('activated_by', $qb->createNamedParameter($actor))
					->where($qb->expr()->eq('code', $qb->createNamedParameter((string)$target->getCode())))
					->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(BusinessTripRuleSet::STATUS_ACTIVE)))
					->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($ruleSetId, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();

				$target->setStatus(BusinessTripRuleSet::STATUS_ACTIVE);
				$target->setActivatedBy($actor);
				$target->setActivatedAt(new \DateTime());
				$saved = $this->ruleSets->update($target);
				$this->db->commit();
				return $saved;
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function createDraft(
		string $code,
		string $name,
		string $jurisdiction,
		string $configJson,
		?\DateTime $validFrom,
		?\DateTime $validTo,
		string $actor,
		?array $provenance = null,
	): BusinessTripRuleSet {
		if (!in_array($jurisdiction, BusinessTripRuleSet::JURISDICTIONS, true)) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'Unknown jurisdiction.', 400, ['jurisdiction' => 'invalid']);
		}
		if ($validFrom !== null && $validTo !== null && $validTo < $validFrom) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'validTo must not precede validFrom.', 400, ['validTo' => 'before_valid_from']);
		}
		// Strict allowlist validation — a draft must never carry an
		// unvalidated config into the catalog.
		$this->validator->validate($configJson);
		$lock = DbLockKeys::businessTripRuleCatalog();
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Business-trip rule catalog');
		try {
			$this->db->beginTransaction();
			try {
				$nextVersion = 1;
				foreach ($this->ruleSets->findVersions($code) as $row) {
					$nextVersion = max($nextVersion, (int)$row->getVersion() + 1);
				}
				$set = new BusinessTripRuleSet();
				$set->setCode($code);
				$set->setVersion($nextVersion);
				$set->setName($name);
				$set->setJurisdiction($jurisdiction);
				$set->setStatus(BusinessTripRuleSet::STATUS_DRAFT);
				$set->setValidFrom($validFrom);
				$set->setValidTo($validTo);
				$set->setConfigJson($configJson);
				$set->setConfigHash(hash('sha256', $configJson));
				if (is_array($provenance)) {
					$set->setSourceTitle(isset($provenance['sourceTitle']) ? mb_substr((string)$provenance['sourceTitle'], 0, 255) : null);
					$set->setSourceReference(isset($provenance['sourceReference']) ? mb_substr((string)$provenance['sourceReference'], 0, 255) : null);
					$set->setClassifierSchemaVersion(isset($provenance['classifierSchemaVersion']) ? (int)$provenance['classifierSchemaVersion'] : null);
				}
				$set->setCreatedBy($actor);
				$set->setCreatedAt(new \DateTime());
				$saved = $this->ruleSets->insert($set);
				$this->db->commit();
				return $saved;
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Record the legal review of a draft (spec §10.5). Reviewing is bound
	 * to the exact config hash — any subsequent draft edit must clear the
	 * review, which updateDraft does by resetting the review fields
	 * whenever the config changes. The caller passes the hash it reviewed;
	 * a mismatch means the draft changed underneath the reviewer and the
	 * review must not be stamped on a config nobody signed off.
	 */
	public function markReviewed(BusinessTripRuleSet $set, string $actor, string $configHash): BusinessTripRuleSet
	{
		if ($set->getStatus() !== BusinessTripRuleSet::STATUS_DRAFT) {
			throw new BusinessTripException('RULE_SET_IMMUTABLE', 'Only draft rule sets can be reviewed; create a new version instead.', 409);
		}
		if (!hash_equals((string)$set->getConfigHash(), $configHash)) {
			throw new BusinessTripException('RULE_REVIEW_STALE', 'The rule set changed since it was reviewed; reload and review the current draft.', 409);
		}
		$set->setReviewedAt(new \DateTime());
		$set->setReviewedBy($actor);
		return $this->ruleSets->update($set);
	}

	/**
	 * Update an existing draft's config/name — versioning is only
	 * immutable once a version leaves `draft`.
	 */
	public function updateDraft(BusinessTripRuleSet $set, string $configJson, string $name, string $actor): BusinessTripRuleSet
	{
		if ($set->getStatus() !== BusinessTripRuleSet::STATUS_DRAFT) {
			throw new BusinessTripException('RULE_SET_IMMUTABLE', 'Only draft rule sets can be edited; create a new version instead.', 409);
		}
		$this->validator->validate($configJson);
		$newHash = hash('sha256', $configJson);
		// The recorded legal review covers exactly this config hash — editing
		// the config voids the review and forces a fresh sign-off.
		if ($newHash !== (string)$set->getConfigHash()) {
			$set->setReviewedAt(null);
			$set->setReviewedBy(null);
		}
		$set->setName($name);
		$set->setConfigJson($configJson);
		$set->setConfigHash($newHash);
		return $this->ruleSets->update($set);
	}
}
