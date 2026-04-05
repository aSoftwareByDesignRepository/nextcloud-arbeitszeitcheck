<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Maps an absence to a CalDAV object written to the user's calendar.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getAbsenceId()
 * @method void setAbsenceId(int $absenceId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCalendarId()
 * @method void setCalendarId(int $calendarId)
 * @method string getObjectUri()
 * @method void setObjectUri(string $objectUri)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class AbsenceCalendar extends Entity
{
	protected $absenceId;
	protected $userId;
	protected $calendarId;
	protected $objectUri;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('absenceId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('calendarId', 'integer');
		$this->addType('objectUri', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
