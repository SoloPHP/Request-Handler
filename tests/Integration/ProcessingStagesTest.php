<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Integration\Stages;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Attributes\{Caster, Generator, Items, Normalize, PostProcess, Validate};
use Solo\RequestHandler\Attributes\Source\{FromBody, FromContext};
use Solo\RequestHandler\Contracts\{CasterInterface, GeneratorInterface, NormalizerInterface, PostProcessorInterface};
use Solo\RequestHandler\Exceptions\{FieldValidationException, ValidationException};
use Solo\RequestHandler\{ProcessContext, Request, RequestHandler};

final class ProcessingStagesTest extends TestCase
{
    private RequestHandler $handler;

    protected function setUp(): void
    {
        Trace::$stages = [];
        $this->handler = new RequestHandler(new RawValidator());
    }

    public function testBlankCustomCastingRespectsStringAndCollectionPolicies(): void
    {
        $dto = $this->handler->handleArray(BlankCustomRequest::class, [
            'text' => '', 'anything' => '', 'list' => '', 'iterable' => '',
        ]);
        self::assertSame('', $dto->text);
        self::assertSame('', $dto->anything);
        self::assertSame([], $dto->list);
        self::assertSame([], $dto->iterable);
        self::assertSame(['cast', 'cast', 'post', 'post', 'post', 'post'], Trace::$stages);
    }

    public function testSparseItemErrorPathsKeepOriginalKeysAndPreventFinalization(): void
    {
        try {
            $this->handler->handleArray(TreeRequest::class, [
                'title' => 'raw-invalid',
                'children' => [7 => ['amount' => 'bad'], 'last' => ['amount' => 'bad'], 12 => 'invalid'],
            ]);
            self::fail('Expected validation failure');
        } catch (ValidationException $e) {
            self::assertSame([
                'title' => [['rule' => 'raw']],
                'children.7.amount' => [['rule' => 'integer', 'params' => ['decimal']]],
                'children.last.amount' => [['rule' => 'integer', 'params' => ['decimal']]],
                'children.12' => [['rule' => 'array']],
            ], $e->getErrors());
            self::assertNotContains('post', Trace::$stages);
            self::assertNotContains('generate', Trace::$stages);
            self::assertNotContains('children', Trace::$stages);
        }
    }

    public function testNormalizerFieldErrorAlsoUsesItsInputPath(): void
    {
        try {
            $this->handler->handleArray(RejectedNormalizationRequest::class, ['payload' => ['count' => 'bad']]);
            self::fail('Expected validation failure');
        } catch (ValidationException $e) {
            self::assertSame(['payload.count' => [['rule' => 'normalization']]], $e->getErrors());
            self::assertSame(['normalize'], Trace::$stages);
        }
    }

    public function testContextCasterFailureIsAnApplicationError(): void
    {
        try {
            $this->handler->handleArray(ContextRequest::class, [], context: ['amount' => 'bad']);
            self::fail('Expected application error');
        } catch (\UnexpectedValueException $e) {
            self::assertInstanceOf(FieldValidationException::class, $e->getPrevious());
            self::assertStringContainsString("Context value 'amount'", $e->getMessage());
            self::assertNotContains('post', Trace::$stages);
        }
    }

    public function testParentTypeRejectsAnUnrelatedCasterResult(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('returned stdClass');
        $this->handler->handleArray(ParentRelativeRequest::class, ['value' => new \stdClass()]);
    }

    /** @return iterable<string, array{class-string<Request>}> */
    public static function invalidRejections(): iterable
    {
        yield 'normalizer aggregate exception' => [AggregateNormalizerRequest::class];
        yield 'caster aggregate exception' => [AggregateCasterRequest::class];
        yield 'post aggregate exception' => [AggregatePostRequest::class];
        yield 'generator aggregate exception' => [AggregateGeneratorRequest::class];
    }

    /** @param class-string<Request> $class */
    #[DataProvider('invalidRejections')]
    public function testInvalidRejectionIsAContractError(string $class): void
    {
        try {
            $this->handler->handleArray($class, ['amount' => '2']);
            self::fail('Expected contract error');
        } catch (\LogicException $e) {
            self::assertInstanceOf(ValidationException::class, $e->getPrevious());
        }
    }
}

final class Trace
{
    /** @var list<string> */
    public static array $stages = [];

    public static function record(string $stage): void
    {
        self::$stages[] = $stage;
    }
}

/** @implements CasterInterface<mixed> */
final class IdentityCaster implements CasterInterface
{
    public function cast(mixed $value, ProcessContext $context): mixed
    {
        Trace::record('cast');
        return $value;
    }
}

/** @implements PostProcessorInterface<mixed> */
final class IdentityProcessor implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): mixed
    {
        Trace::record('post');
        return $value;
    }
}

class RelativeRequest extends Request
{
}

class ParentRelativeRequest extends RelativeRequest
{
    #[Caster(IdentityCaster::class)]
    public parent $value;
}

class BlankCustomRequest extends Request
{
    #[Caster(IdentityCaster::class)]
    #[PostProcess(IdentityProcessor::class)]
    public string $text;

    #[Caster(IdentityCaster::class)]
    #[PostProcess(IdentityProcessor::class)]
    public mixed $anything;

    /** @var list<mixed> */
    #[Caster(IdentityCaster::class)]
    #[PostProcess(IdentityProcessor::class)]
    public array $list;

    /** @var iterable<mixed> */
    #[Caster(IdentityCaster::class)]
    #[PostProcess(IdentityProcessor::class)]
    public iterable $iterable;
}

final class RawValidator implements ValidatorInterface
{
    public function validate(array $data, array $rules): array
    {
        Trace::record('validate');
        $errors = [];
        foreach ($rules as $name => $rule) {
            if (($data[$name] ?? null) === 'raw-invalid') {
                $errors[$name] = [['rule' => 'raw']];
            }
        }
        return $errors;
    }
}

final class InputNormalizer implements NormalizerInterface
{
    public function normalize(mixed $value, ProcessContext $context): mixed
    {
        Trace::record('normalize');
        return match ($context->config['mode'] ?? 'keep') {
            'reject' => throw new FieldValidationException('normalization'),
            'aggregate' => throw new ValidationException(['unrelated' => [['rule' => 'bad']]]),
            default => $value,
        };
    }
}

/** @implements CasterInterface<mixed> */
final class IntegerCaster implements CasterInterface
{
    public function cast(mixed $value, ProcessContext $context): mixed
    {
        Trace::record('cast');
        if (($context->config['mode'] ?? '') === 'aggregate') {
            throw new ValidationException(['unrelated' => [['rule' => 'bad']]]);
        }
        if (!is_int($value) && (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1)) {
            throw new FieldValidationException('integer', ['decimal']);
        }
        return (int) $value;
    }
}

/** @implements PostProcessorInterface<mixed> */
final class ValueProcessor implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): mixed
    {
        Trace::record('post');
        return match ($context->config['mode'] ?? '') {
            'aggregate' => throw new ValidationException(['amount' => [['rule' => 'late']]]),
            default => $value,
        };
    }
}

final class IdGenerator implements GeneratorInterface
{
    public function generate(array $options = []): int
    {
        Trace::record('generate');
        return match ($options['mode'] ?? '') {
            'aggregate' => throw new ValidationException(['amount' => [['rule' => 'late']]]),
            default => 99,
        };
    }
}

class ScalarRequest extends Request
{
    #[Normalize(InputNormalizer::class)]
    #[Validate('integer')]
    #[Caster(IntegerCaster::class)]
    #[PostProcess(ValueProcessor::class)]
    public int $amount;
}

class ChildRequest extends ScalarRequest
{
    #[Generator(IdGenerator::class)]
    public int $id;
}

/** @implements PostProcessorInterface<list<ChildRequest>> */
final class ChildrenProcessor implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): array
    {
        Trace::record('children');
        return $value;
    }
}

class TreeRequest extends Request
{
    #[Validate('string')]
    public string $title;

    /** @var list<ChildRequest> */
    #[Items(ChildRequest::class)]
    #[PostProcess(ChildrenProcessor::class)]
    public array $children;

    #[Generator(IdGenerator::class)]
    public int $id;
}

class RejectedNormalizationRequest extends Request
{
    #[FromBody('payload.count')]
    #[Normalize(InputNormalizer::class, config: ['mode' => 'reject'])]
    public int $amount;
}

class ContextRequest extends Request
{
    #[FromContext('amount')]
    #[Caster(IntegerCaster::class)]
    #[PostProcess(ValueProcessor::class)]
    public int $amount;
}

class AggregateNormalizerRequest extends Request
{
    #[Normalize(InputNormalizer::class, config: ['mode' => 'aggregate'])]
    public int $amount;
}

class AggregateCasterRequest extends Request
{
    #[Caster(IntegerCaster::class, config: ['mode' => 'aggregate'])]
    public int $amount;
}

class AggregatePostRequest extends Request
{
    #[PostProcess(ValueProcessor::class, config: ['mode' => 'aggregate'])]
    public int $amount;
}

class AggregateGeneratorRequest extends Request
{
    #[Generator(IdGenerator::class, options: ['mode' => 'aggregate'])]
    public int $id;
}
