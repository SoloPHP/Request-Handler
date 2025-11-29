<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Attributes\AsRequest;
use Solo\RequestHandler\Attributes\Field;
use Solo\RequestHandler\Traits\DynamicProperties;
use InvalidArgumentException;

final class ReflectionCacheTest extends TestCase
{
    private ReflectionCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ReflectionCache();
    }

    public function testGetReturnsCachedMetadata(): void
    {
        $metadata1 = $this->cache->get(ValidRequest::class);
        $metadata2 = $this->cache->get(ValidRequest::class);

        $this->assertSame($metadata1, $metadata2);
    }

    public function testClearRemovesAllCache(): void
    {
        $metadata1 = $this->cache->get(ValidRequest::class);
        $this->cache->clear();
        $metadata2 = $this->cache->get(ValidRequest::class);

        $this->assertNotSame($metadata1, $metadata2);
    }

    public function testValidateClassThrowsExceptionForMissingAttribute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must have #[AsRequest] attribute');

        $this->cache->get(InvalidRequest::class);
    }
}

/**
 * @property string $name
 */
#[AsRequest]
#[Field('name', 'required|string')]
final class ValidRequest
{
    use DynamicProperties;
}

final class InvalidRequest
{
    use DynamicProperties;
}
