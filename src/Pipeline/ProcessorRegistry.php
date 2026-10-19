<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Pipeline;

use Psr\Container\ContainerInterface;
use Solo\RequestHandler\Cache\ProcessorKind;
use Solo\RequestHandler\Contracts\CasterInterface;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\RequestHandler\Contracts\ProcessorInterface;
use Solo\RequestHandler\Exceptions\ConfigurationException;
use Solo\RequestHandler\ProcessContext;

/**
 * Resolves processor / caster / generator instances and dispatches them by
 * their pre-classified `ProcessorKind`.
 *
 * A class resolves to the instance registered for it, else the container's
 * entry for the class name, else a new instance built without arguments.
 * Registered and argument-less instances live as long as the owning
 * `RequestHandler`; a container entry is fetched on every use, so the
 * container decides whether it is shared, built per call or request-scoped.
 *
 * @internal
 */
final class ProcessorRegistry
{
    /** @var array<class-string, ProcessorInterface|CasterInterface|GeneratorInterface|object> */
    private array $instances = [];

    /** @var array<class-string, bool> Whether the container holds a class — asked once. */
    private array $inContainer = [];

    public function __construct(
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    /**
     * Register a pre-built instance — used when the processor/caster/generator
     * has constructor dependencies that the registry cannot satisfy itself.
     *
     * Fails fast if `$instance` is not actually an instance of `$className`
     * (typical copy-paste error: `register(MyCaster::class, $logger)`).
     *
     * @param class-string $className
     */
    public function register(string $className, object $instance): void
    {
        if (!$instance instanceof $className) {
            throw new \InvalidArgumentException(sprintf(
                "ProcessorRegistry::register(%s, ...) received an instance of %s, which is not a %s.",
                $className,
                $instance::class,
                $className,
            ));
        }
        $this->instances[$className] = $instance;
    }

    /**
     * Dispatch a pre/postProcess handler on a value.
     *
     * `$context` is passed only to `ProcessorInterface` handlers; the other
     * dispatch kinds get just the value.
     *
     * @param class-string $className The Request class being processed (for static-method dispatch).
     */
    public function runProcessor(
        string $handler,
        ProcessorKind $kind,
        mixed $value,
        string $className,
        ProcessContext $context,
    ): mixed {
        return match ($kind) {
            ProcessorKind::Func               => $handler($value), // @phpstan-ignore-line callable.nonCallable
            ProcessorKind::ProcessorInterface => $this->invokeProcessor($handler, $value, $context),
            ProcessorKind::CasterInterface    => $this->invokeCaster($handler, $value),
            ProcessorKind::StaticMethod       => $className::$handler($value),
        };
    }

    /**
     * @param class-string<GeneratorInterface> $generatorClass
     * @param array<string, mixed>             $options
     */
    public function runGenerator(string $generatorClass, array $options): mixed
    {
        /** @var GeneratorInterface $generator */
        $generator = $this->getOrCreate($generatorClass);
        return $generator->generate($options);
    }

    /**
     * Apply a `CasterInterface` directly (used by `RequestHandler::transform()`
     * for `#[Caster]`-marked properties).
     */
    public function invokeCaster(string $handler, mixed $value): mixed
    {
        /** @var CasterInterface $caster */
        $caster = $this->getOrCreate($handler);
        return $caster->cast($value);
    }

    private function invokeProcessor(string $handler, mixed $value, ProcessContext $context): mixed
    {
        /** @var ProcessorInterface $processor */
        $processor = $this->getOrCreate($handler);
        return $processor->process($value, $context);
    }

    /**
     * The class string here is validated upstream by `ReflectionCache` —
     * `runProcessor` / `runGenerator` / `invokeCaster` are only called with
     * names that already passed `classifyProcessor` / `validateGenerator` /
     * `isCasterClass`.
     */
    private function getOrCreate(string $className): object
    {
        /** @var class-string $className */
        if (isset($this->instances[$className])) {
            return $this->instances[$className];
        }

        if ($this->container !== null && ($this->inContainer[$className] ??= $this->container->has($className))) {
            $entry = $this->container->get($className);
            if (!$entry instanceof $className) {
                throw ConfigurationException::containerEntryMismatch($className, get_debug_type($entry));
            }

            return $entry;
        }

        return $this->instances[$className] = $this->instantiate($className);
    }

    /**
     * Whether a class can be built without arguments is only decidable here:
     * an abstract class, or one with constructor dependencies, is valid
     * configuration as long as an instance was registered for it or the
     * container provides one.
     *
     * @param class-string $className
     */
    private function instantiate(string $className): object
    {
        $reflection = new \ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if (
            !$reflection->isInstantiable()
            || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)
        ) {
            throw ConfigurationException::handlerNotInstantiable($className);
        }

        return $reflection->newInstance();
    }
}
