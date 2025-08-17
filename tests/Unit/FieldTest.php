<?php

namespace Solo\RequestHandler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Field;

final class FieldTest extends TestCase
{
    public function testFieldCreationWithBasicConfiguration(): void
    {
        $field = Field::for('email');

        $this->assertEquals('email', $field->name);
        $this->assertEquals('email', $field->inputName);
        $this->assertNull($field->default);
        $this->assertNull($field->rules);
        $this->assertNull($field->preprocessor);
        $this->assertNull($field->postprocessor);
    }

    public function testFieldMappingFromDifferentInputName(): void
    {
        $field = Field::for('email')->mapFrom('user.contact.email');

        $this->assertEquals('email', $field->name);
        $this->assertEquals('user.contact.email', $field->inputName);
    }

    public function testFieldWithDefaultValue(): void
    {
        $field = Field::for('status')->default('active');

        $this->assertEquals('active', $field->default);
    }

    public function testFieldWithValidationRules(): void
    {
        $field = Field::for('email')->validate('required|email|max:255');

        $this->assertEquals('required|email|max:255', $field->rules);
    }

    public function testFieldWithPreprocessor(): void
    {
        $preprocessor = fn(mixed $value): string => trim((string)$value);
        $field = Field::for('title')->preprocess($preprocessor);

        $this->assertEquals($preprocessor, $field->preprocessor);
        $this->assertEquals('Test Title', $field->processPre('  Test Title  '));
    }

    public function testFieldWithPostprocessor(): void
    {
        $postprocessor = fn(mixed $value): string => strtoupper((string)$value);
        $field = Field::for('status')->postprocess($postprocessor);

        $this->assertEquals($postprocessor, $field->postprocessor);
        $this->assertEquals('ACTIVE', $field->processPost('active'));
    }

    public function testComplexFieldConfiguration(): void
    {
        $field = Field::for('categories')
            ->mapFrom('meta.tags')
            ->default([])
            ->validate('array|max:5')
            ->preprocess(fn(mixed $value): array =>
            is_string($value) ? explode(',', $value) : (array)$value
            )
            ->postprocess(fn(array $value): array => array_unique($value));

        $this->assertEquals('categories', $field->name);
        $this->assertEquals('meta.tags', $field->inputName);
        $this->assertEquals([], $field->default);
        $this->assertEquals('array|max:5', $field->rules);

        $processedInput = $field->processPre('tag1,tag2,tag3');
        $this->assertEquals(['tag1', 'tag2', 'tag3'], $processedInput);

        $finalOutput = $field->processPost(['tag1', 'tag2', 'tag1']);
        $this->assertEquals(['tag1', 'tag2'], array_values($finalOutput));
    }

    public function testProcessPreWithoutPreprocessor(): void
    {
        $field = Field::for('title');

        $this->assertEquals('original', $field->processPre('original'));
    }

    public function testProcessPostWithoutPostprocessor(): void
    {
        $field = Field::for('title');

        $this->assertEquals('original', $field->processPost('original'));
    }
}