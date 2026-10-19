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
 * cannot accept skips it rather than raising a TypeError.
 *
 * `enumClass` is set when the declared type is a `BackedEnum`; the value is
 * resolved through `tryFrom()` instead of a built-in cast.
 *
 * `dateClass` is set for a date property: the class built from the input,
 * read in `dateFormat` when `#[DateFormat]` gives one.
 *
 * `nested` is set for a property typed with a Request subclass: the value is
 * processed as a nested DTO, like one `#[Items]` element.
 *
 * `castFailure` is the error reported when the built-in cast cannot convert
 * non-empty input (`integer`, `numeric`, `boolean`, `string`, `json`, `in`,
 * `date`, `date_format`) — decided once, at build time.
 *
 * `emptyToNull` (`#[EmptyToNull]` on the property or its class): a blank
 * string is read as null. `noTrim`: auto-trim skips it — `#[NoTrim]`, or a
 * `#[FromContext]` value, which is the application's.
 *
 * `typeGuard` is set for a union no cast applies to (`int|float`): a value
 * of a member type is kept, another one is converted to the first of
 * `unionCasts` it fits without loss — or fails with `castFailure`.
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
     * @param list<string>                                     $unionCasts
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
        public array $unionCasts = [],
    ) {
    }
}
