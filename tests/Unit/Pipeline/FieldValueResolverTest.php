<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Cache\RequestMetadata;
use Solo\RequestHandler\Pipeline\FieldValueResolver;
use Solo\RequestHandler\Pipeline\ProcessorRegistry;
use Solo\RequestHandler\Request;

final class FieldValueResolverTest extends TestCase
{
    private FieldValueResolver $resolver;
    private RequestMetadata $metadata;

    protected function setUp(): void
    {
        $this->resolver = new FieldValueResolver(new ProcessorRegistry());
        $this->metadata = (new ReflectionCache())->get(ValueRequest::class);
    }

    public function testAssignmentRetainsThePhpTypeErrorAsItsCause(): void
    {
        try {
            $this->resolver->assign(new ValueRequest(), $this->metadata, ['count' => []]);
            $this->fail('Expected assignment error');
        } catch (\UnexpectedValueException $e) {
            $this->assertInstanceOf(\TypeError::class, $e->getPrevious());
            $this->assertSame(
                "Value of 'count' is array, which does not fit " . ValueRequest::class . '::$count (int).',
                $e->getMessage()
            );
        }
    }
}

final class ValueRequest extends Request
{
    public int $count;
}
