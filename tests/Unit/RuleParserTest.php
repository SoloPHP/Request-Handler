<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\RuleParser;

final class RuleParserTest extends TestCase
{
    // ===== parse() =====

    public function testNullReturnsEmptyList(): void
    {
        $this->assertSame([], RuleParser::parse(null));
    }

    public function testEmptyStringReturnsEmptyList(): void
    {
        $this->assertSame([], RuleParser::parse(''));
    }

    public function testRuleWithoutArgs(): void
    {
        $this->assertSame(
            [['name' => 'required', 'args' => []]],
            RuleParser::parse('required'),
        );
    }

    public function testRuleWithSingleArg(): void
    {
        $this->assertSame(
            [['name' => 'max', 'args' => ['255']]],
            RuleParser::parse('max:255'),
        );
    }

    public function testRuleWithMultipleArgs(): void
    {
        $this->assertSame(
            [['name' => 'in', 'args' => ['a', 'b', 'c']]],
            RuleParser::parse('in:a,b,c'),
        );
    }

    public function testColonWithEmptyArgsYieldsEmptyArgList(): void
    {
        // A trailing colon with nothing after it is a rule with no arguments,
        // NOT an argument list containing a single empty string.
        $this->assertSame(
            [['name' => 'max', 'args' => []]],
            RuleParser::parse('max:'),
        );
    }

    public function testFullPipeSeparatedString(): void
    {
        $this->assertSame(
            [
                ['name' => 'nullable', 'args' => []],
                ['name' => 'string', 'args' => []],
                ['name' => 'max', 'args' => ['255']],
            ],
            RuleParser::parse('nullable|string|max:255'),
        );
    }

    public function testEmptySegmentsAreSkipped(): void
    {
        // Trailing pipe and a doubled pipe both produce empty segments.
        $this->assertSame(
            [
                ['name' => 'required', 'args' => []],
                ['name' => 'string', 'args' => []],
            ],
            RuleParser::parse('required||string|'),
        );
    }

    public function testSegmentsAreTrimmed(): void
    {
        $this->assertSame(
            [
                ['name' => 'required', 'args' => []],
                ['name' => 'string', 'args' => []],
            ],
            RuleParser::parse(' required | string '),
        );
    }

    public function testColonDoesNotSplitArgValues(): void
    {
        // The first colon delimits name from args; later commas split args,
        // but pipes inside value lists never appear (split on `|` happens first).
        $this->assertSame(
            [['name' => 'in', 'args' => ['nullable', 'active']]],
            RuleParser::parse('in:nullable,active'),
        );
    }

    // ===== has() =====

    public function testHasReturnsTrueForPresentRule(): void
    {
        $this->assertTrue(RuleParser::has('required|string|max:255', 'string'));
    }

    public function testHasReturnsFalseForAbsentRule(): void
    {
        $this->assertFalse(RuleParser::has('required|string', 'integer'));
    }

    public function testHasDoesNotMatchInsideValueList(): void
    {
        // `in:nullable,active` must NOT be read as a `nullable` rule.
        $this->assertFalse(RuleParser::has('in:nullable,active', 'nullable'));
    }

    public function testHasReturnsFalseForNullRules(): void
    {
        $this->assertFalse(RuleParser::has(null, 'required'));
    }

    // ===== hasNamePrefix() =====

    public function testHasNamePrefixMatchesExactName(): void
    {
        $this->assertTrue(RuleParser::hasNamePrefix('required|string', 'required'));
    }

    public function testHasNamePrefixMatchesConditionalVariants(): void
    {
        $this->assertTrue(RuleParser::hasNamePrefix('nullable|required_if:company_type,company', 'required'));
        $this->assertTrue(RuleParser::hasNamePrefix('required_with:field', 'required'));
        $this->assertTrue(RuleParser::hasNamePrefix('required_without_all:a,b', 'required'));
    }

    public function testHasNamePrefixReturnsFalseWhenNoRuleMatches(): void
    {
        $this->assertFalse(RuleParser::hasNamePrefix('nullable|string|max:255', 'required'));
    }

    public function testHasNamePrefixDoesNotMatchInsideValueList(): void
    {
        // `in:required,optional` must NOT be read as a presence rule.
        $this->assertFalse(RuleParser::hasNamePrefix('in:required,optional', 'required'));
    }

    public function testHasNamePrefixReturnsFalseForNullRules(): void
    {
        $this->assertFalse(RuleParser::hasNamePrefix(null, 'required'));
    }
}
