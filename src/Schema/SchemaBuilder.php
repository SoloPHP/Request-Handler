<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Schema;

use Solo\RequestHandler\Cache\PropertyMetadata;
use Solo\RequestHandler\Cache\RequestMetadata;
use Solo\RequestHandler\FieldSource;
use Solo\RequestHandler\RuleParser;

/**
 * Builds a strictly JSON-serializable validation schema from cached request
 * metadata.
 *
 * The output lets a frontend treat the backend as the single source of truth
 * for field requiredness — including CONDITIONAL requiredness (`required_if`),
 * which the boolean `PropertyMetadata::$isRequired` (literal `required` only)
 * does not capture.
 *
 * Every value emitted is composed solely of scalars and arrays: no
 * `ReflectionProperty`, enums, objects, or closures leak through, so the result
 * round-trips losslessly through `json_encode`/`json_decode`.
 *
 * Validation rules are parsed by the shared `RuleParser`, the single source of
 * truth for rule-string split semantics (also used by `ReflectionCache`).
 *
 * @phpstan-type NormalizedRule array{name: string, args: list<string>}
 * @phpstan-type RequiredIf array{field: string, value: string}
 * @phpstan-type FieldSchema array{
 *     type: ?string,
 *     nullable: bool,
 *     required: bool,
 *     requiredIf: RequiredIf|null,
 *     hasDefault: bool,
 *     exclude: bool,
 *     source: ?string,
 *     rules: list<NormalizedRule>
 * }
 */
final readonly class SchemaBuilder
{
    /**
     * Map every managed property to its JSON-serializable field schema.
     *
     * ALL managed properties are emitted — properties are NOT filtered by
     * `exclude` or `source` (those describe the output of `Request::toArray()`,
     * not the input contract). The consumer filters using the emitted
     * `exclude`/`source` keys.
     *
     * @return array<string, FieldSchema>
     */
    public function build(RequestMetadata $metadata): array
    {
        $schema = [];

        foreach ($metadata->properties as $name => $property) {
            $schema[$name] = $this->buildField($property);
        }

        return $schema;
    }

    /**
     * @return FieldSchema
     */
    private function buildField(PropertyMetadata $property): array
    {
        $rules = RuleParser::parse($property->validationRules);

        return [
            'type'       => $property->type,
            'nullable'   => $property->isNullable,
            'required'   => $property->isRequired,
            'requiredIf' => $this->extractRequiredIf($rules),
            'hasDefault' => $property->hasDefault,
            'exclude'    => $property->exclude,
            'source'     => $this->mapSource($property->source),
            'rules'      => $rules,
        ];
    }

    /**
     * Extract the first `required_if` rule as `{field, value}`.
     *
     * Returns null when no `required_if` rule is present. A malformed
     * `required_if` missing either argument is treated as absent — the
     * convenience accessor only reports a well-formed conditional.
     *
     * @param  list<NormalizedRule> $rules
     * @return RequiredIf|null
     */
    private function extractRequiredIf(array $rules): ?array
    {
        foreach ($rules as $rule) {
            if ($rule['name'] !== 'required_if') {
                continue;
            }

            if (!isset($rule['args'][0], $rule['args'][1])) {
                return null;
            }

            return ['field' => $rule['args'][0], 'value' => $rule['args'][1]];
        }

        return null;
    }

    /**
     * Map the source enum to a lowercase string; null stays null.
     */
    private function mapSource(?FieldSource $source): ?string
    {
        return match ($source) {
            FieldSource::Body    => 'body',
            FieldSource::Query   => 'query',
            FieldSource::Route   => 'route',
            FieldSource::Context => 'context',
            null                 => null,
        };
    }
}
