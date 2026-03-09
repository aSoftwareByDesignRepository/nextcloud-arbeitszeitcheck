<?php

declare(strict_types=1);

/**
 * UserWorkingTimeModel entity for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * UserWorkingTimeModel entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getWorkingTimeModelId()
 * @method void setWorkingTimeModelId(int $workingTimeModelId)
 * @method int getVacationDaysPerYear()
 * @method void setVacationDaysPerYear(int $vacationDaysPerYear)
 * @method \DateTime getStartDate()
 * @method void setStartDate(\DateTime $startDate)
 * @method \DateTime|null getEndDate()
 * @method void setEndDate(\DateTime|null $endDate)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class UserWorkingTimeModel extends Entity
{
    /** @var string */
    protected $userId;

    /** @var int */
    protected $workingTimeModelId;

    /** @var int */
    protected $vacationDaysPerYear;

    /** @var \DateTime */
    protected $startDate;

    /** @var \DateTime|null */
    protected $endDate;

    /** @var \DateTime */
    protected $createdAt;

    /** @var \DateTime */
    protected $updatedAt;

    /**
     * UserWorkingTimeModel constructor
     */
    public function __construct()
    {
        $this->addType('userId', 'string');
        $this->addType('workingTimeModelId', 'integer');
        $this->addType('vacationDaysPerYear', 'integer');
        $this->addType('startDate', 'date');
        $this->addType('endDate', 'date');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }

    /**
     * Check if this assignment is currently active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        $now = new \DateTime();
        $now->setTime(0, 0, 0);

        return $this->startDate <= $now && ($this->endDate === null || $this->endDate >= $now);
    }

    /**
     * Check if this assignment was active on a specific date
     *
     * @param \DateTime $date
     * @return bool
     */
    public function wasActiveOn(\DateTime $date): bool
    {
        $checkDate = clone $date;
        $checkDate->setTime(0, 0, 0);

        return $this->startDate <= $checkDate && ($this->endDate === null || $this->endDate >= $checkDate);
    }

    /**
     * Validate the user working time model data
     *
     * @return array Array of validation errors
     */
    public function validate(): array
    {
        $errors = [];

        // Validate user ID
        if (empty($this->userId)) {
            $errors['userId'] = 'User ID is required';
        }

        // Validate working time model ID
        if ($this->workingTimeModelId <= 0) {
            $errors['workingTimeModelId'] = 'Valid working time model ID is required';
        }

        // Validate vacation days
        if ($this->vacationDaysPerYear < 0) {
            $errors['vacationDaysPerYear'] = 'Vacation days cannot be negative';
        }

        if ($this->vacationDaysPerYear > 366) {
            $errors['vacationDaysPerYear'] = 'Vacation days cannot exceed 366';
        }

        // Validate dates
        if (!$this->startDate) {
            $errors['startDate'] = 'Start date is required';
        }

        if ($this->endDate && $this->startDate && $this->endDate < $this->startDate) {
            $errors['endDate'] = 'End date must be after start date';
        }

        return $errors;
    }

    /**
     * Check if the user working time model data is valid
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
        $startDate = $this->getStartDate();
        $createdAt = $this->getCreatedAt();
        $updatedAt = $this->getUpdatedAt();
        
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'workingTimeModelId' => $this->getWorkingTimeModelId(),
            'vacationDaysPerYear' => $this->getVacationDaysPerYear(),
            'startDate' => $startDate ? $startDate->format('Y-m-d') : null,
            'endDate' => $this->getEndDate()?->format('Y-m-d'),
            'isActive' => $this->isActive(),
            'createdAt' => $createdAt ? $createdAt->format('c') : null,
            'updatedAt' => $updatedAt ? $updatedAt->format('c') : null
        ];
    }
}