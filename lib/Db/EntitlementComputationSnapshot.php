<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getPeriodKey()
 * @method void setPeriodKey(string $periodKey)
 * @method \DateTime getAsOfDate()
 * @method void setAsOfDate(\DateTime $asOfDate)
 * @method float getEffectiveEntitlementDays()
 * @method void setEffectiveEntitlementDays(float $effectiveEntitlementDays)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method int|null getRuleSetId()
 * @method void setRuleSetId(?int $ruleSetId)
 * @method string getCalculationTraceJson()
 * @method void setCalculationTraceJson(string $calculationTraceJson)
 * @method \DateTime getComputedAt()
 * @method void setComputedAt(\DateTime $computedAt)
 * @method string getComputedBy()
 * @method void setComputedBy(string $computedBy)
 * @method string|null getPolicyFingerprint()
 * @method void setPolicyFingerprint(?string $policyFingerprint)
 */
class EntitlementComputationSnapshot extends Entity {
	protected string $userId = '';
	protected string $periodKey = '';
	protected \DateTime $asOfDate;
	protected float $effectiveEntitlementDays = 0.0;
	protected string $source = 'manual';
	protected ?int $ruleSetId = null;
	protected string $calculationTraceJson = '{}';
	protected \DateTime $computedAt;
	protected string $computedBy = 'system';
	protected ?string $policyFingerprint = null;
	protected ?int $entitlementTypeId = null;
	protected ?string $algorithmVersion = null;
	protected ?\DateTime $periodStart = null;
	protected ?\DateTime $periodEnd = null;
	protected ?string $unit = null;
	protected ?float $effectiveAmount = null;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('periodKey', 'string');
		$this->addType('asOfDate', 'date');
		$this->addType('effectiveEntitlementDays', 'float');
		$this->addType('source', 'string');
		$this->addType('ruleSetId', 'integer');
		$this->addType('calculationTraceJson', 'string');
		$this->addType('computedAt', 'datetime');
		$this->addType('computedBy', 'string');
		$this->addType('policyFingerprint', 'string');
		$this->addType('entitlementTypeId', 'integer');
		$this->addType('algorithmVersion', 'string');
		$this->addType('periodStart', 'date');
		$this->addType('periodEnd', 'date');
		$this->addType('unit', 'string');
		$this->addType('effectiveAmount', 'float');
		// Typed \DateTime properties must be initialized before Entity::setter()
		// compares them; otherwise the first setAsOfDate()/setComputedAt() call
		// throws "must not be accessed before initialization".
		$today = new \DateTime('today');
		$this->asOfDate = clone $today;
		$now = new \DateTime();
		$this->computedAt = clone $now;
	}

	public function getCalculationTrace(): array {
		$decoded = json_decode($this->calculationTraceJson, true);
		return is_array($decoded) ? $decoded : [];
	}

	public function setCalculationTrace(array $trace): void {
		$this->calculationTraceJson = (string)json_encode($trace);
		$this->markFieldUpdated('calculationTraceJson');
	}
}

