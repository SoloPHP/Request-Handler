<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Grouping;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Group;
use Solo\RequestHandler\Request;

final class GroupBuilderTest extends TestCase
{
    public function testGroupThrowsExceptionOnDuplicateKeyFromScalar(): void
    {
        $dto = new ScalarDuplicateRequest();
        $dto->filters = ['limit' => 100];
        $dto->limit = 10;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Duplicate key 'limit' in group 'criteria' from property 'limit'");

        $dto->group('criteria');
    }

    public function testGroupUsesCurrentValuesWithoutMutatingTheDto(): void
    {
        $dto = new DuplicateKeyRequest();
        $dto->first = ['AND' => ['a' => 1]];
        $dto->second = ['AND' => ['b' => 2]];
        $before = $dto->toArray();

        $this->assertSame(
            ['AND' => ['a' => 1], 0 => ['AND' => ['b' => 2]]],
            $dto->group('criteria')
        );
        $this->assertSame($before, $dto->toArray());

        unset($dto->first);
        $dto->second = ['status' => 'active'];

        $this->assertSame(['status' => 'active'], $dto->group('criteria'));
    }
}

final class DuplicateKeyRequest extends Request
{
    /** @var array<array-key, mixed> */
    #[Group('criteria')]
    public array $first;

    /** @var array<array-key, mixed> */
    #[Group('criteria')]
    public array $second;
}

final class ScalarDuplicateRequest extends Request
{
    /** @var array<string, mixed> */
    #[Group('criteria')]
    public array $filters;

    #[Group('criteria')]
    public int $limit;
}
