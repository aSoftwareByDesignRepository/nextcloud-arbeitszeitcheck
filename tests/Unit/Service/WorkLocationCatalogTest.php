<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\WorkLocationDefinition;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper;
use OCA\ArbeitszeitCheck\Service\WorkLocationCatalog;
use OCA\ArbeitszeitCheck\Support\WorkLocation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WorkLocationCatalogTest extends TestCase
{
	private function definition(string $code, bool $active = true, ?string $labelI18n = null, string $category = 'office'): WorkLocationDefinition
	{
		$def = new WorkLocationDefinition();
		$def->setCode($code);
		$def->setBaseLabel('Label ' . $code);
		$def->setLabelI18n($labelI18n);
		$def->setReportingCategory($category);
		$def->setActive($active);
		$def->setBuiltIn(in_array($code, WorkLocation::ALL, true));
		$def->setVersion(1);
		return $def;
	}

	private function catalog(array $rows, bool $mapperThrows = false): WorkLocationCatalog
	{
		$mapper = $this->createMock(WorkLocationDefinitionMapper::class);
		if ($mapperThrows) {
			$mapper->method('findAllIndexed')->willThrowException(new \RuntimeException('table missing'));
		} else {
			$indexed = [];
			foreach ($rows as $row) {
				$indexed[$row->getCode()] = $row;
			}
			$mapper->method('findAllIndexed')->willReturn($indexed);
		}
		return new WorkLocationCatalog($mapper, new NullLogger());
	}

	public function testMissingTableDegradesToBuiltIns(): void
	{
		$catalog = $this->catalog([], mapperThrows: true);
		self::assertTrue($catalog->isKnown(WorkLocation::OFFICE));
		self::assertTrue($catalog->isCreatable(WorkLocation::HOME));
		self::assertFalse($catalog->isKnown('custom_x'));
		self::assertCount(3, $catalog->all());
	}

	public function testDbRowsExtendBuiltIns(): void
	{
		$catalog = $this->catalog([$this->definition('custom_halberstadt')]);
		self::assertCount(4, $catalog->all());
		self::assertTrue($catalog->isCreatable('custom_halberstadt'));
	}

	public function testInactiveRowIsKnownButNotCreatable(): void
	{
		$catalog = $this->catalog([$this->definition('custom_old', active: false)]);
		self::assertTrue($catalog->isKnown('custom_old'));
		self::assertFalse($catalog->isCreatable('custom_old'));
		self::assertTrue($catalog->isInactiveCode('custom_old'));
		self::assertFalse($catalog->isInactiveCode('custom_unknown'));
	}

	public function testNormalizeRejectsUnknownAndNonString(): void
	{
		$catalog = $this->catalog([]);
		self::assertNull($catalog->normalize(null));
		self::assertNull($catalog->normalize(''));
		self::assertSame(WorkLocation::HOME, $catalog->normalize(' Home '));
		$this->expectException(\InvalidArgumentException::class);
		$catalog->normalize('UPPER_BAD!');
	}

	public function testNormalizeRejectsUnknownWellFormedCode(): void
	{
		$catalog = $this->catalog([]);
		$this->expectException(\InvalidArgumentException::class);
		$catalog->normalize('custom_missing');
	}

	public function testNormalizeCreatableRejectsInactive(): void
	{
		$catalog = $this->catalog([$this->definition('custom_old', active: false)]);
		$this->expectException(\InvalidArgumentException::class);
		$catalog->normalizeCreatable('custom_old');
	}

	public function testLabelForUsesLocaleMapThenBaseThenCode(): void
	{
		$i18n = json_encode(['en' => 'HQ Office', 'de' => 'Zentrale']);
		$catalog = $this->catalog([$this->definition('custom_hq', labelI18n: $i18n)]);
		self::assertSame('Zentrale', $catalog->labelFor('custom_hq', 'de'));
		self::assertSame('HQ Office', $catalog->labelFor('custom_hq', 'en_US'));
		self::assertSame('Label custom_hq', $catalog->labelFor('custom_hq', 'fr'));
		self::assertSame('ghost_code', $catalog->labelFor('ghost_code', 'de'));
	}

	public function testCustomCodeForPrefixing(): void
	{
		self::assertSame('custom_halberstadt', WorkLocationCatalog::customCodeFor('Halberstadt'));
		self::assertSame('custom_x', WorkLocationCatalog::customCodeFor('custom_x'));
	}

	public function testIsValidCodeStemRejectsBuiltInsAndMalformed(): void
	{
		self::assertTrue(WorkLocationCatalog::isValidCodeStem('halberstadt'));
		self::assertTrue(WorkLocationCatalog::isValidCodeStem('custom_ok'));
		self::assertFalse(WorkLocationCatalog::isValidCodeStem('office'));
		self::assertFalse(WorkLocationCatalog::isValidCodeStem(''));
		self::assertFalse(WorkLocationCatalog::isValidCodeStem('9starts_digit'));
		self::assertFalse(WorkLocationCatalog::isValidCodeStem('has space'));
	}

	public function testEncodeLabelMapCapsEntriesAndLength(): void
	{
		$map = [];
		for ($i = 0; $i < 15; $i++) {
			$map[chr(97 + $i) . 'x'] = 'x';
		}
		$encoded = WorkLocationCatalog::encodeLabelMap($map);
		self::assertNotNull($encoded);
		self::assertCount(WorkLocationCatalog::LABEL_I18N_MAX_ENTRIES, WorkLocationCatalog::decodeLabelMap($encoded));

		$long = WorkLocationCatalog::encodeLabelMap(['de' => str_repeat('a', 200)]);
		self::assertSame(str_repeat('a', 80), WorkLocationCatalog::decodeLabelMap($long)['de']);

		self::assertNull(WorkLocationCatalog::encodeLabelMap(['!!bad!!' => 'x']));
		self::assertNull(WorkLocationCatalog::encodeLabelMap([]));
	}

	public function testDecodeLabelMapDropsMalformed(): void
	{
		self::assertSame([], WorkLocationCatalog::decodeLabelMap(null));
		self::assertSame([], WorkLocationCatalog::decodeLabelMap('not json'));
		self::assertSame(['de' => 'Büro'], WorkLocationCatalog::decodeLabelMap(json_encode(['de' => 'Büro', 'en' => ''])));
	}
}
