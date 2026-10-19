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
 * Lazily resolves and caches processor / caster / generator instances for
 * the lifetime of the owning `RequestHandler`, and dispatches them by their
 * pre-classified `ProcessorKind`.
 *
 * A class resolves to the instance registered for it, else the container's
 * entry for the class name, else a new instance built without arguments.
 *
 * @internal
 */
final class ProcessorRegistry
{
    /** @var array<class-string, ProcessorInterface|CasterInterface|GeneratorInterface|object> */
    private array $instances = [];

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
        return $this->instances[$className] ??= $this->resolve($className);
    }

    /**
     * @param class-string $className
     */
    private function resolve(string $className): object
    {
        if ($this->container === null || !$this->container->has($className)) {
            return $this->instantiate($className);
        }

        $entry = $this->container->get($className);
        if (!$entry instanceof $className) {
            throw ConfigurationException::containerEntryMismatch($className, get_debug_type($entry));
        }

        return $entry;
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
