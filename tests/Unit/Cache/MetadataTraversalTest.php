<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Cache\Traversal;

use PHPUnit\Framework\TestCase;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Attributes\Items;
use Solo\RequestHandler\Attributes\Validate;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Exceptions\ConfigurationException;
use Solo\RequestHandler\Request;
use Solo\RequestHandler\RequestHandler;

final class MetadataTraversalTest extends TestCase
{
    public function testFailureOrderSurvivesRetriesAndCacheClearing(): void
    {
        $message = ConfigurationException::requiredWithDefault(BrokenFirstFieldParent::class, 'title')->getMessage();
        $cache = new ReflectionCache();
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            if ($attempt === 2) {
                $cache->clear();
            }
            try {
                $cache->get(BrokenFirstFieldParent::class);
                $this->fail('Expected ConfigurationException');
            } catch (ConfigurationException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->assertSame(
            ['next', 'children', 'label'],
            array_keys($cache->get(RecursiveRequest::class)->properties)
        );
    }

    public function testAbsentItemsAreValidatedBeforeInputProcessing(): void
    {
        Request::clearCache();
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->never())->method('validate');
        $handler = new RequestHandler($validator);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            ConfigurationException::nullableRuleWithNonNullableType(
                BrokenChildRequest::class,
                'quantity',
                'int'
            )->getMessage()
        );
        $handler->handleArray(BrokenItemsParent::class, []);
    }

    public function testRelativeTypesUseTheirDeclaringClassForBindingAndSchema(): void
    {
        $handler = new RequestHandler($this->createStub(ValidatorInterface::class));
        $dto = $handler->handleArray(RelativeGrandchildRequest::class, [
            'next' => ['label' => 'self', 'next' => ['label' => 'recursive']],
            'ancestor' => ['label' => 'parent'],
        ]);

        $this->assertNotNull($dto->next);
        $this->assertSame(RelativeBaseRequest::class, $dto->next::class);
        $this->assertSame('self', $dto->next->label);
        $this->assertSame('recursive', $dto->next->next?->label);
        $this->assertNotNull($dto->ancestor);
        $this->assertSame(RelativeBaseRequest::class, $dto->ancestor::class);
        $this->assertSame('parent', $dto->ancestor->label);
        $this->assertSame([
            'ancestor' => ['label' => 'parent'],
            'next' => ['next' => ['label' => 'recursive'], 'label' => 'self'],
        ], $dto->toArray(onlyProvided: true));

        $schema = $handler->schema(RelativeGrandchildRequest::class);
        $this->assertSame(
            ['$ref' => RelativeBaseRequest::class],
            $schema['next']['fields']['next']['fields'] ?? null
        );
        $this->assertSame(
            ['$ref' => RelativeBaseRequest::class],
            $schema['ancestor']['fields']['next']['fields'] ?? null
        );
    }
}

final class BrokenChildRequest extends Request
{
    #[Validate('nullable')]
    public int $quantity;
}

final class BrokenItemsParent extends Request
{
    /** @var list<BrokenChildRequest> */
    #[Items(BrokenChildRequest::class)]
    public array $children = [];
}

final class BrokenFirstFieldParent extends Request
{
    #[Validate('required')]
    public string $title = 'default';

    public ?BrokenChildRequest $child = null;
}

final class RecursiveRequest extends Request
{
    public ?RecursiveRequest $next = null;

    /** @var list<RecursiveRequest> */
    #[Items(RecursiveRequest::class)]
    public array $children = [];

    public string $label = '';
}

class RelativeBaseRequest extends Request
{
    public ?self $next = null;
    public string $label = '';
}

class RelativeChildRequest extends RelativeBaseRequest
{
    public ?parent $ancestor = null;
}

final class RelativeGrandchildRequest extends RelativeChildRequest
{
}
