<?php

declare(strict_types=1);

/**
 * WorkingTimeModel entity for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * WorkingTimeModel entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(string|null $description)
 * @method string getType()
 * @method void setType(string $type)
 * @method float getWeeklyHours()
 * @method void setWeeklyHours(float $weeklyHours)
 * @method float getDailyHours()
 * @method void setDailyHours(float $dailyHours)
 * @method string|null getBreakRules()
 * @method void setBreakRules(string|null $breakRules)
 * @method string|null getOvertimeRules()
 * @method void setOvertimeRules(string|null $overtimeRules)
 * @method bool getIsDefault()
 * @method void setIsDefault(bool $isDefault)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class WorkingTimeModel extends Entity
{
    public const TYPE_FULL_TIME = 'full_time';
    public const TYPE_PART_TIME = 'part_time';
    public const TYPE_FLEXIBLE = 'flexible';
    public const TYPE_TRUST_BASED = 'trust_based';
    public const TYPE_SHIFT_WORK = 'shift_work';

    /** @var string */
    protected $name;

    /** @var string|null */
    protected $description;

    /** @var string */
    protected $type;

    /** @var float */
    protected $weeklyHours;

    /** @var float */
    protected $dailyHours;

    /** @var string|null */
    protected $breakRules;

    /** @var string|null */
    protected $overtimeRules;

    /** @var bool */
    protected $isDefault = false;

    /** @var \DateTime */
    protected $createdAt;

    /** @var \DateTime */
    protected $updatedAt;

    /**
     * WorkingTimeModel constructor
     */
    public function __construct()
    {
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('type', 'string');
        $this->addType('weeklyHours', 'float');
        $this->addType('dailyHours', 'float');
        $this->addType('breakRules', 'string');
        $this->addType('overtimeRules', 'string');
        $this->addType('isDefault', 'boolean');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }

    /**
     * Get break rules as array
     *
     * @return array|null
     */
    public function getBreakRulesArray(): ?array
    {
        if (!$this->breakRules) {
            return null;
        }

        return json_decode($this->breakRules, true);
    }

    /**
     * Set break rules from array
     *
     * @param array|null $rules
     */
    public function setBreakRulesArray(?array $rules): void
    {
        $this->breakRules = $rules ? json_encode($rules) : null;
    }

    /**
     * Get overtime rules as array
     *
     * @return array|null
     */
    public function getOvertimeRulesArray(): ?array
    {
        if (!$this->overtimeRules) {
            return null;
        }

        return json_decode($this->overtimeRules, true);
    }

    /**
     * Set overtime rules from array
     *
     * @param array|null $rules
     */
    public function setOvertimeRulesArray(?array $rules): void
    {
        $this->overtimeRules = $rules ? json_encode($rules) : null;
    }

    /**
     * Validate the working time model data
     *
     * @return array Array of validation errors
     */
    public function validate(): array
    {
        $errors = [];

        // Validate name
        if (empty($this->name)) {
            $errors['name'] = 'Name is required';
        }

        // Validate type
        $validTypes = [
            self::TYPE_FULL_TIME,
            self::TYPE_PART_TIME,
            self::TYPE_FLEXIBLE,
            self::TYPE_TRUST_BASED,
            self::TYPE_SHIFT_WORK
        ];
        if (!in_array($this->type, $validTypes)) {
            $errors['type'] = 'Invalid working time model type';
        }

        // Validate hours
        if ($this->weeklyHours <= 0) {
            $errors['weeklyHours'] = 'Weekly hours must be greater than 0';
        }

        if ($this->dailyHours <= 0) {
            $errors['dailyHours'] = 'Daily hours must be greater than 0';
        }

        // Validate weekly hours don't exceed reasonable limits
        if ($this->weeklyHours > 80) {
            $errors['weeklyHours'] = 'Weekly hours cannot exceed 80';
        }

        return $errors;
    }

    /**
     * Check if the working time model data is valid
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return empty($this->validate());
    }

    /**
     * Get a summary array for API responses
     *
     * @return array
     */
    public function getSummary(): array
    {
        $createdAt = $this->getCreatedAt();
        $updatedAt = $this->getUpdatedAt();
        
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'type' => $this->getType(),
            'weeklyHours' => $this->getWeeklyHours(),
            'dailyHours' => $this->getDailyHours(),
            'breakRules' => $this->getBreakRulesArray(),
            'overtimeRules' => $this->getOvertimeRulesArray(),
            'isDefault' => $this->getIsDefault(),
            'createdAt' => $createdAt ? $createdAt->format('c') : null,
            'updatedAt' => $updatedAt ? $updatedAt->format('c') : null
        ];
    }
}