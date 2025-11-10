<?php

namespace Solo\RequestHandler\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Helpers\ParameterParser;

final class ParameterParserTest extends TestCase
{
    public function testParseSortWithNullValue(): void
    {
        $result = ParameterParser::sort(null);
        $this->assertNull($result);
    }

    public function testParseSortWithEmptyString(): void
    {
        $result = ParameterParser::sort('');
        $this->assertNull($result);
    }

    public function testParseSortWithAscendingOrder(): void
    {
        $result = ParameterParser::sort('name');
        $this->assertEquals(['name' => 'ASC'], $result);
    }

    public function testParseSortWithDescendingOrder(): void
    {
        $result = ParameterParser::sort('-created_at');
        $this->assertEquals(['created_at' => 'DESC'], $result);
    }

    public function testParseSortWithFieldStartingWithDash(): void
    {
        $result = ParameterParser::sort('--field');
        $this->assertEquals(['-field' => 'DESC'], $result);
    }

    public function testParseSearchWithNull(): void
    {
        $result = ParameterParser::search(null);
        $this->assertEquals([], $result);
    }

    public function testParseSearchWithEmptyString(): void
    {
        $result = ParameterParser::search('');
        $this->assertEquals([], $result);
    }

    public function testParseSearchWithString(): void
    {
        $result = ParameterParser::search('john doe');
        $this->assertEquals(['john doe'], $result);
    }

    public function testParseSearchWithArray(): void
    {
        $input = ['john', 'doe'];
        $result = ParameterParser::search($input);
        $this->assertEquals(['john', 'doe'], $result);
    }

    public function testParseFilterWithNull(): void
    {
        $result = ParameterParser::filter(null);
        $this->assertEquals([], $result);
    }

    public function testParseFilterWithEmptyString(): void
    {
        $result = ParameterParser::filter('');
        $this->assertEquals([], $result);
    }

    public function testParseFilterWithArray(): void
    {
        $input = ['status' => 'active', 'role' => 'admin'];
        $result = ParameterParser::filter($input);
        $this->assertEquals(['filter' => ['status' => 'active', 'role' => 'admin']], $result);
    }

    public function testParseFilterWithString(): void
    {
        $result = ParameterParser::filter('active');
        $this->assertEquals(['filter' => ['active']], $result);
    }

    public function testParseBooleanWithTrueBoolean(): void
    {
        $result = ParameterParser::boolean(true);
        $this->assertEquals(1, $result);
    }

    public function testParseBooleanWithFalseBoolean(): void
    {
        $result = ParameterParser::boolean(false);
        $this->assertEquals(0, $result);
    }

    public function testParseBooleanWithTrueString(): void
    {
        $this->assertEquals(1, ParameterParser::boolean('true'));
        $this->assertEquals(1, ParameterParser::boolean('TRUE'));
        $this->assertEquals(1, ParameterParser::boolean('True'));
    }

    public function testParseBooleanWithFalseString(): void
    {
        $this->assertEquals(0, ParameterParser::boolean('false'));
        $this->assertEquals(0, ParameterParser::boolean('FALSE'));
        $this->assertEquals(0, ParameterParser::boolean('False'));
    }

    public function testParseBooleanWithNumericStrings(): void
    {
        $this->assertEquals(1, ParameterParser::boolean('1'));
        $this->assertEquals(0, ParameterParser::boolean('0'));
    }

    public function testParseBooleanWithYesNoStrings(): void
    {
        $this->assertEquals(1, ParameterParser::boolean('yes'));
        $this->assertEquals(1, ParameterParser::boolean('YES'));
        $this->assertEquals(0, ParameterParser::boolean('no'));
        $this->assertEquals(0, ParameterParser::boolean('NO'));
    }

    public function testParseBooleanWithOnOffStrings(): void
    {
        $this->assertEquals(1, ParameterParser::boolean('on'));
        $this->assertEquals(1, ParameterParser::boolean('ON'));
        $this->assertEquals(0, ParameterParser::boolean('off'));
        $this->assertEquals(0, ParameterParser::boolean('OFF'));
    }

    public function testParseBooleanWithUnrecognizedString(): void
    {
        $this->assertEquals(1, ParameterParser::boolean('anything'));
        $this->assertEquals(0, ParameterParser::boolean(''));
    }

    public function testParseBooleanWithInteger(): void
    {
        $this->assertEquals(1, ParameterParser::boolean(1));
        $this->assertEquals(1, ParameterParser::boolean(42));
        $this->assertEquals(0, ParameterParser::boolean(0));
    }

    public function testParseBooleanWithNull(): void
    {
        $result = ParameterParser::boolean(null);
        $this->assertEquals(0, $result);
    }

    public function testParseBooleanWithArray(): void
    {
        $this->assertEquals(1, ParameterParser::boolean(['something']));
        $this->assertEquals(0, ParameterParser::boolean([]));
    }

    public function testUniqueIdGeneratesCorrectLength(): void
    {
        $id = ParameterParser::uniqueId();
        $this->assertEquals(8, strlen((string)$id));
    }

    public function testUniqueIdGeneratesCustomLength(): void
    {
        $id = ParameterParser::uniqueId(10);
        $this->assertEquals(10, strlen((string)$id));
    }

    public function testUniqueIdGeneratesUniqueValues(): void
    {
        $id1 = ParameterParser::uniqueId();
        $id2 = ParameterParser::uniqueId();
        $this->assertNotEquals($id1, $id2);
    }

    public function testUniqueIdWithMinimumLength(): void
    {
        $id = ParameterParser::uniqueId(1);
        $this->assertEquals(1, strlen((string)$id));
        $this->assertGreaterThanOrEqual(1, $id);
        $this->assertLessThanOrEqual(9, $id);
    }

    public function testUniqueIdWithLargeLength(): void
    {
        $id = ParameterParser::uniqueId(15);
        $this->assertEquals(15, strlen((string)$id));
    }
}
