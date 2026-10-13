<?php

declare(strict_types=1);

/**
 * CustomAbsenceType entity for the arbeitszeitcheck app
 *
 * Administrator-defined absence types ("weitere betriebliche Fehlzeiten").
 * Rows are soft-deleted via `active = 0` so historical absence records keep
 * a resolvable label and semantics — the row is never physically removed
 * while absences may still reference its `code`.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * CustomAbsenceType entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getCode()
 * @method void setCode(string $code)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string|null getLabelI18n()
 * @method void setLabelI18n(string|null $labelI18n)
 * @method bool getSollCredit()
 * @method void setSollCredit(bool $sollCredit)
 * @method int getMaxDays()
 * @method void setMaxDays(int $maxDays)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method string getIcon()
 * @method void setIcon(string $icon)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(\DateTime|null $updatedAt)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(string|null $updatedBy)
 */
class CustomAbsenceType extends Entity
{
	/** Type code prefix — reserves the `custom_*` namespace for admin-defined types. */
	public const CODE_PREFIX = 'custom_';

	/** Canonical type colors mapped to existing calendar chip token families. */
	public const COLORS = ['info', 'primary', 'success', 'warning', 'error'];

	/** Icons restricted to glyphs present in {@see \OCA\ArbeitszeitCheck\Service\IconCatalog}
	 *  (safe SVG names, always rendered aria-hidden). */
	public const ICONS = [
		'calendar',
		'calendar-heart',
		'calendar-off',
		'heart-handshake',
		'coffee',
		'shield-check',
		'rotate',
		'home',
		'briefcase',
		'users',
		'user-check',
		'activity',
		'clock',
		'coins',
		'layers',
		'list',
	];

	/** @var string */
	protected $code;

	/** @var string */
	protected $label;

	/** @var string|null JSON map {locale: label}, e.g. {"de": "Betriebsausflug"} */
	protected $labelI18n;

	/** @var bool Whether planned-day hours credit Soll when the opt-in credit feature is enabled. */
	protected $sollCredit;

	/** @var int Maximum calendar days per single request. */
	protected $maxDays;

	/** @var string */
	protected $color;

	/** @var string */
	protected $icon;

	/** @var bool Soft-delete flag; inactive types stay resolvable for history. */
	protected $active;

	/** @var \DateTime */
	protected $createdAt;

	/** @var string */
	protected $createdBy;

	/** @var \DateTime|null */
	protected $updatedAt;

	/** @var string|null */
	protected $updatedBy;

	public function __construct()
	{
		$this->addType('code', 'string');
		$this->addType('label', 'string');
		$this->addType('labelI18n', 'string');
		$this->addType('sollCredit', 'boolean');
		$this->addType('maxDays', 'integer');
		$this->addType('color', 'string');
		$this->addType('icon', 'string');
		$this->addType('active', 'boolean');
		$this->addType('createdAt', 'datetime');
		$this->addType('createdBy', 'string');
		$this->addType('updatedAt', 'datetime');
		$this->addType('updatedBy', 'string');
	}

	/**
	 * Decoded per-locale label overrides. Invalid/empty JSON degrades to [].
	 *
	 * @return array<string,string>
	 */
	public function getLabelMap(): array
	{
		$raw = $this->getLabelI18n();
		if (!is_string($raw) || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	public function setLabelMap(array $map): void
	{
		$this->setLabelI18n($map === [] ? null : json_encode($map, JSON_UNESCAPED_UNICODE));
	}

	/**
	 * Resolve the label for a locale: exact locale → language-prefix → base label.
	 */
	public function resolveLabel(?string $locale): string
	{
		$map = $this->getLabelMap();
		if ($locale !== null && $locale !== '') {
			if (isset($map[$locale]) && $map[$locale] !== '') {
				return (string)$map[$locale];
			}
			$prefix = explode('_', str_replace('-', '_', $locale))[0];
			foreach ($map as $loc => $text) {
				if (is_string($text) && $text !== '' && explode('_', str_replace('-', '_', (string)$loc))[0] === $prefix) {
					return $text;
				}
			}
		}
		return $this->getLabel();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getSummary(?string $locale = null): array
	{
		return [
			'id' => $this->getId(),
			'code' => $this->getCode(),
			'label' => $this->getLabel(),
			'resolvedLabel' => $this->resolveLabel($locale),
			'labelMap' => $this->getLabelMap(),
			'sollCredit' => (bool)$this->getSollCredit(),
			'maxDays' => (int)$this->getMaxDays(),
			'color' => $this->getColor(),
			'icon' => $this->getIcon(),
			'active' => (bool)$this->getActive(),
			'createdBy' => $this->getCreatedBy(),
			'updatedBy' => $this->getUpdatedBy(),
		];
	}
}
