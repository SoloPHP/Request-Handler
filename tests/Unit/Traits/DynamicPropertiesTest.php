<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Traits;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Traits\DynamicProperties;
use Error;

final class DynamicPropertiesTest extends TestCase
{
    private TestDTO $dto;

    protected function setUp(): void
    {
        $this->dto = new TestDTO();
    }

    public function testSetAndGet(): void
    {
        $this->dto->name = 'Test';
        $this->assertEquals('Test', $this->dto->name);
    }

    public function testIsset(): void
    {
        $this->assertFalse(isset($this->dto->name));
        $this->dto->name = 'Test';
        $this->assertTrue(isset($this->dto->name));
    }

    public function testGetUndefinedPropertyThrowsError(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Undefined property');
        /** @phpstan-ignore-next-line */
        $val = $this->dto->undefined;
    }

    public function testToArray(): void
    {
        $this->dto->name = 'Test';
        $this->dto->age = 25;
        $this->assertEquals(['name' => 'Test', 'age' => 25], $this->dto->toArray());
    }

    public function testHas(): void
    {
        $this->assertFalse($this->dto->has('name'));
        $this->dto->name = 'Test';
        $this->assertTrue($this->dto->has('name'));
    }

    public function testGetWithDefault(): void
    {
        $this->assertEquals('Default', $this->dto->get('name', 'Default'));
        $this->dto->name = 'Test';
        $this->assertEquals('Test', $this->dto->get('name', 'Default'));
    }
}

/**
 * @property mixed $name
 * @property mixed $age
 */
final class TestDTO
{
    use DynamicProperties;
}
