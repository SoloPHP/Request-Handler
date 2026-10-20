<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Cache;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use ReflectionProperty;
use Solo\RequestHandler\FieldSource;
use Solo\RequestHandler\Pipeline\ParameterTypeGuard;
use Solo\RequestHandler\Request;

/**
 * Cached metadata for a single field.
 *
 * `source` is null when the property uses the default source dictated by the
 * `handle*` method (Body for `handleBody`/`handleArray`, Query for `handleQuery`).
 * `sourceKey` is always populated.
 *
 * `validatesWhenAbsent` marks a field whose rule chain can produce an error
 * even when the key is missing from the payload — the literal `required` rule
 * and the whole conditional-presence family (`required_if`, `required_with`,
 * `required_without_all`, ...). `isRequired` stays literal-`required` only,
 * because it also drives the "required field cannot have a default" check and
 * the exported schema.
 *
 * `pathSegments` is non-null only when the property is sourced via
 * `#[FromBody('a.b.c')]` — in that case `InputBags::lookup` walks the body
 * bag segment by segment instead of doing a flat array_key_exists lookup.
 *
 * `preProcessorGuard` / `postProcessorGuard` are set for function and
 * static-method handlers whose first parameter is typed: a value the handler
 * cannot accept skips it rather than raising a TypeError. `preProcessorFailure`
 * is set when nothing but that pre-processor produces the property's type (a
 * class): input is converted to the parameter's scalar types in
 * `preProcessorCasts` when that loses nothing, and input that fits none is
 * the client's wrong type, reported with this rule.
 *
 * `enumClass` is set when the declared type is a `BackedEnum`; the value is
 * resolved through `tryFrom()` instead of a built-in cast.
 *
 * `dateClass` is set for a date property: the class built from the input,
 * read in `dateFormat` when `#[DateFormat]` gives one.
 *
 * `nested` and `items` are set when the handler builds DTOs from the value:
 * one nested object, or an `#[Items]` list of them. A list the application
 * supplies (context) holds its DTOs already: `suppliedItems` names their
 * class, and they are only checked.
 *
 * `castFailure` is the error reported when the built-in cast cannot convert
 * non-empty input (`integer`, `numeric`, `boolean`, `string`, `json`,
 * `array`, `in`, `date`, `date_format`) — decided once, at build time.
 *
 * `emptyToNull` (`#[EmptyToNull]` on the property or its class): a blank
 * string is read as null. `noTrim`: auto-trim skips it — `#[NoTrim]`, or a
 * value the application supplies.
 *
 * `holdsInput` is false for a type no request input can have — a class,
 * `object`, an intersection: its value is produced, and `""` there is no
 * value.
 *
 * `typeGuard` and `unionCasts` are set for an input type no cast handles,
 * such as `int|float` or a standalone `true`: a value of a member type is
 * kept, another one is converted to the first of `unionCasts` it fits
 * without loss — or fails with `castFailure`. `typeGuard` is also set for a
 * value the application supplies (context, generator), checked right after
 * its cast — before any hook or generator runs.
 *
 * @internal
 */
final readonly class PropertyMetadata
{
    /**
     * @param array<string, mixed>            $postProcessConfig
     * @param array<string, mixed>            $generatorOptions
     * @param class-string<Request>|null      $items
     * @param list<string>|null               $pathSegments
     * @param class-string<BackedEnum>|null   $enumClass
     * @param array{rule: string, params?: list<string>}|null $castFailure
     * @param class-string<DateTime|DateTimeImmutable>|null    $dateClass
     * @param class-string<Request>|null                       $nested
     * @param list<string>|null                                $unionCasts
     * @param class-string<Request>|null                       $suppliedItems
     * @param array{rule: string}|null                         $preProcessorFailure
     * @param list<string>|null                                $preProcessorCasts
     */
    public function __construct(
        public string $name,
        public ?FieldSource $source,
        public string $sourceKey,
        public ?string $type,
        public bool $isNullable,
        public bool $hasDefault,
        public mixed $defaultValue,
        public ?string $validationRules,
        public ?string $effectiveCastType,
        public ?string $customCasterClass,
        public ?string $preProcessor,
        public ?ProcessorKind $preProcessorKind,
        public ?string $postProcessor,
        public ?ProcessorKind $postProcessorKind,
        public array $postProcessConfig,
        public ?string $group,
        public bool $isRequired,
        public bool $validatesWhenAbsent,
        public ReflectionProperty $reflection,
        public ?string $generator = null,
        public array $generatorOptions = [],
        public bool $exclude = false,
        public ?string $items = null,
        public ?array $pathSegments = null,
        public ?ParameterTypeGuard $preProcessorGuard = null,
        public ?ParameterTypeGuard $postProcessorGuard = null,
        public ?string $enumClass = null,
        public ?array $castFailure = null,
        public ?string $groupMapTo = null,
        public ?string $dateClass = null,
        public ?string $dateFormat = null,
        public ?string $nested = null,
        public bool $emptyToNull = false,
        public bool $noTrim = false,
        public ?ParameterTypeGuard $typeGuard = null,
        public ?array $unionCasts = null,
        public ?string $suppliedItems = null,
        public ?array $preProcessorFailure = null,
        public bool $holdsInput = true,
        public ?array $preProcessorCasts = null,
    ) {
    }

    /**
     * Whether a post-processor or custom caster decides the final value — and,
     * for nested objects and items, what they are built from. Those run in
     * the transform pass.
     */
    public function decidedInTransform(): bool
    {
        return $this->postProcessorKind !== null || $this->customCasterClass !== null;
    }

    /**
     * Whether the handler builds DTOs from the value: a nested object or an
     * `#[Items]` list.
     */
    public function buildsChildren(): bool
    {
        return $this->items !== null || $this->nested !== null;
    }
}
