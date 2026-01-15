# Generators

Generators automatically create field values. Useful for UUIDs, sequences, and computed IDs.

## Basic Usage

```php
use Solo\RequestHandler\Contracts\GeneratorInterface;

final class UuidGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// Usage
#[Field(generator: UuidGenerator::class)]
public string $id;
```

## GeneratorInterface

```php
interface GeneratorInterface
{
    /**
     * @param array<string, mixed> $options
     * @return mixed
     */
    public function generate(array $options = []): mixed;
}
```

---

## Generator Options

Pass options via `generatorOptions` parameter:

```php
#[Field(
    generator: SequenceGenerator::class,
    generatorOptions: ['table' => 'orders', 'prefix' => 'ORD']
)]
public string $orderNumber;
```

Access options in generator:

```php
final class SequenceGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $table = $options['table'] ?? 'default';
        $prefix = $options['prefix'] ?? '';
        $id = $this->getNextId($table);
        
        return $prefix ? "{$prefix}-{$id}" : (string) $id;
    }
}
```

---

## Generators with Dependencies

Register generator instances for dependency injection:

```php
final class DatabaseSequenceGenerator implements GeneratorInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function generate(array $options = []): int
    {
        $table = $options['table'] ?? 'sequences';
        $column = $options['column'] ?? 'id';
        
        $result = $this->connection->executeQuery(
            "SELECT MAX({$column}) + 1 FROM {$table}"
        );
        
        return (int) $result->fetchOne();
    }
}

// Register with dependencies
$handler->register(
    DatabaseSequenceGenerator::class,
    new DatabaseSequenceGenerator($connection)
);
```

---

## Behavior

When a field has `generator`:

1. **Any request value is ignored** — A new value is always generated
2. **No validation** — Generated values bypass validation rules
3. **Runs before casting** — The generated value is used directly

```php
#[Field(generator: UuidGenerator::class)]
public string $id;

// Even if request contains {"id": "custom-id"},
// the generated UUID will be used instead
```

---

## Practical Examples

### UUID v4

```php
final class Uuid4Generator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
```

### Prefixed ID

```php
final class PrefixedIdGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $prefix = $options['prefix'] ?? 'ID';
        $timestamp = time();
        $random = mt_rand(1000, 9999);
        
        return "{$prefix}-{$timestamp}-{$random}";
    }
}

#[Field(generator: PrefixedIdGenerator::class, generatorOptions: ['prefix' => 'ORD'])]
public string $orderId;
// "ORD-1705276800-4521"
```

### ULID (Time-sortable)

```php
final class UlidGenerator implements GeneratorInterface
{
    private const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function generate(array $options = []): string
    {
        $time = (int) (microtime(true) * 1000);
        $ulid = '';
        
        // Encode timestamp (10 chars)
        for ($i = 9; $i >= 0; $i--) {
            $ulid = self::ENCODING[$time % 32] . $ulid;
            $time = (int) ($time / 32);
        }
        
        // Add randomness (16 chars)
        for ($i = 0; $i < 16; $i++) {
            $ulid .= self::ENCODING[random_int(0, 31)];
        }
        
        return $ulid;
    }
}
```

### Timestamp

```php
final class TimestampGenerator implements GeneratorInterface
{
    public function generate(array $options = []): int
    {
        return time();
    }
}

#[Field(generator: TimestampGenerator::class)]
public int $createdAt;
```

### Random Token

```php
final class TokenGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $length = $options['length'] ?? 32;
        return bin2hex(random_bytes($length / 2));
    }
}

#[Field(generator: TokenGenerator::class, generatorOptions: ['length' => 64])]
public string $apiToken;
```

---

## Using with Libraries

### ramsey/uuid

```php
use Ramsey\Uuid\Uuid;

final class RamseyUuidGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $version = $options['version'] ?? 4;
        
        return match ($version) {
            1 => Uuid::uuid1()->toString(),
            4 => Uuid::uuid4()->toString(),
            7 => Uuid::uuid7()->toString(),
            default => Uuid::uuid4()->toString(),
        };
    }
}
```

### symfony/uid

```php
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\Ulid;

final class SymfonyUidGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $type = $options['type'] ?? 'uuid4';
        
        return match ($type) {
            'uuid4' => Uuid::v4()->toRfc4122(),
            'uuid7' => Uuid::v7()->toRfc4122(),
            'ulid' => (new Ulid())->toBase32(),
            default => Uuid::v4()->toRfc4122(),
        };
    }
}
```

---

## Configuration Validation

Invalid generators throw `ConfigurationException`:

```php
// ❌ Error: class does not exist
#[Field(generator: 'NonExistentGenerator')]
public string $id;

// ❌ Error: must implement GeneratorInterface
#[Field(generator: SomeClass::class)]
public string $id;
```
