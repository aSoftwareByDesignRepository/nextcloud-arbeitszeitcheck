<?php

declare(strict_types=1);

/**
 * UserSetting entity for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * UserSetting entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getSettingKey()
 * @method void setSettingKey(string $settingKey)
 * @method string|null getSettingValue()
 * @method void setSettingValue(string|null $settingValue)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class UserSetting extends Entity
{
    /** @var string */
    protected $userId;

    /** @var string */
    protected $settingKey;

    /** @var string|null */
    protected $settingValue;

    /** @var \DateTime */
    protected $createdAt;

    /** @var \DateTime */
    protected $updatedAt;

    /**
     * UserSetting constructor
     */
    public function __construct()
    {
        $this->addType('userId', 'string');
        $this->addType('settingKey', 'string');
        $this->addType('settingValue', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }

    /**
     * Validate the user setting data
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

        // Validate setting key
        if (empty($this->settingKey)) {
            $errors['settingKey'] = 'Setting key is required';
        }

        return $errors;
    }

    /**
     * Check if the user setting data is valid
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
            'userId' => $this->getUserId(),
            'settingKey' => $this->getSettingKey(),
            'settingValue' => $this->getSettingValue(),
            'createdAt' => $createdAt ? $createdAt->format('c') : null,
            'updatedAt' => $updatedAt ? $updatedAt->format('c') : null
        ];
    }
}