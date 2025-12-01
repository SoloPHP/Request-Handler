<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit;

use Error;
use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\DynamicRequest;

final class DynamicRequestTest extends TestCase
{
    private TestDynamicRequest $dto;

    protected function setUp(): void
    {
        $this->dto = new TestDynamicRequest();
    }

    public function testToArrayReturnsAllProperties(): void
    {
        $this->dto->name = 'John';
        $this->dto->age = 30;
        $this->dto->email = 'john@example.com';

        $expected = [
            'name' => 'John',
            'age' => 30,
            'email' => 'john@example.com',
        ];

        $this->assertEquals($expected, $this->dto->toArray());
    }

    public function testToArrayReturnsEmptyArrayWhenNoProperties(): void
    {
        $this->assertEquals([], $this->dto->toArray());
    }

    public function testHasReturnsTrueForExistingProperty(): void
    {
        $this->dto->name = 'John';

        $this->assertTrue($this->dto->has('name'));
    }

    public function testHasReturnsFalseForNonExistingProperty(): void
    {
        $this->assertFalse($this->dto->has('nonexistent'));
    }

    public function testGetReturnsPropertyValue(): void
    {
        $this->dto->name = 'John';

        $this->assertEquals('John', $this->dto->get('name'));
    }

    public function testGetReturnsDefaultForNonExistingProperty(): void
    {
        $this->assertEquals('default', $this->dto->get('nonexistent', 'default'));
    }

    public function testGetReturnsNullByDefaultForNonExistingProperty(): void
    {
        $this->assertNull($this->dto->get('nonexistent'));
    }

    public function testMagicGetThrowsErrorForUndefinedProperty(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Undefined property: ' . TestDynamicRequest::class . '::$nonexistent');

        $value = $this->dto->nonexistent;
    }
}

/**
 * @property string $name
 * @property int $age
 * @property string $email
 * @property mixed $nonexistent
 */
final class TestDynamicRequest extends DynamicRequest
{
}
