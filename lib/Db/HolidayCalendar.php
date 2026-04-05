<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Maps a holiday row to a CalDAV object in the user's Nextcloud calendar.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getHolidayId()
 * @method void setHolidayId(int $holidayId)
 * @method int getCalendarId()
 * @method void setCalendarId(int $calendarId)
 * @method string getObjectUri()
 * @method void setObjectUri(string $objectUri)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class HolidayCalendar extends Entity
{
	protected $userId;
	protected $holidayId;
	protected $calendarId;
	protected $objectUri;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('holidayId', 'integer');
		$this->addType('calendarId', 'integer');
		$this->addType('objectUri', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
