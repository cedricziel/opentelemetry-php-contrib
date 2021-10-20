<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Symfony\OtelSdkBundle\Factory;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionParameter;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Throwable;

trait GenericFactoryTrait
{
    private OptionsResolver $resolver;
    private ReflectionClass $reflectionClass;
    private array $options = [];
    private array $requiredOptions = [];
    private array $defaults = [];

    /**
     * @param string $buildClass
     * @param OptionsResolver|null $resolver
     */
    public function __construct(string $buildClass, ?OptionsResolver $resolver = null)
    {
        $this->init($buildClass, $resolver ?? new OptionsResolver());
    }

    /**
     * @param string $buildClass
     * @param OptionsResolver|null $resolver
     * @return self
     */
    public static function create(string $buildClass, ?OptionsResolver $resolver = null): self
    {
        return new self($buildClass, $resolver);
    }

    /**
     * @param array $options
     * @throws \ReflectionException
     * @return object
     */
    private function doBuild(array $options = []): object
    {
        $options = $this->getOptionsResolver()->resolve($options);
        // make sure arguments are in the correct order;
        $arguments = [];
        foreach ($this->getOptions() as $option) {
            if (!isset($options[$option])) {
                break;
            }
            $arguments[] = $options[$option];
        }

        return $this->getReflectionClass()->newInstanceArgs($arguments);
    }

    /**
     * @param string $option
     * @param mixed $value
     * @return $this
     */
    public function setDefault(string $option, $value): self
    {
        $this->getOptionsResolver()->setDefault($option, $value);
        $this->defaults[$option] = $value;

        return $this;
    }

    /**
     * @param array $options
     * @return $this
     */
    public function setDefaults(array $options): self
    {
        foreach ($options as $option => $value) {
            $this->setDefault($option, $value);
        }

        return $this;
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getRequiredOptions(): array
    {
        return $this->requiredOptions;
    }

    /**
     * @return array
     */
    public function getDefaults(): array
    {
        return $this->defaults;
    }

    /**
     * @return ReflectionClass
     */
    public function getReflectionClass(): ReflectionClass
    {
        return $this->reflectionClass;
    }

    /**
     * @return string
     */
    public function getClass(): string
    {
        return $this->getReflectionClass()->getName();
    }

    /**
     * @return OptionsResolver
     */
    public function getOptionsResolver(): OptionsResolver
    {
        return $this->resolver;
    }

    private function init(string $buildClass, OptionsResolver $resolver)
    {
        try {
            $this->validateExporterClass($buildClass);
            $this->setupReflectionClass($buildClass);
        } catch (Throwable $t) {
            throw new InvalidArgumentException(
                sprintf('Could not create Reflection for class %s.', $buildClass),
                E_ERROR,
                $t
            );
        }
        $this->setOptionsResolver($resolver);
        $this->inspectExporter();
    }

    private function validateExporterClass(string $buildClass)
    {
        if (!class_exists($buildClass)) {
            throw new InvalidArgumentException(
                sprintf('Could not find given class %s.', $buildClass)
            );
        }
    }

    private function setupReflectionClass(string $buildClass)
    {
        $this->reflectionClass = new ReflectionClass($buildClass);
    }

    private function setOptionsResolver(OptionsResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    private function inspectExporter()
    {
        if (!$constructor = $this->getReflectionClass()->getConstructor()) {
            return;
        }

        $parameters = $constructor->getParameters();

        foreach ($parameters as $parameter) {
            $option = self::camelToSnakeCase($parameter->getName());
            $this->addOption($parameter->getPosition(), $option);
            if (!$parameter->isOptional()) {
                $this->addRequiredOption($option);
            }
            if ($type = $parameter->getType()) {
                $this->getOptionsResolver()
                    ->setAllowedTypes($option, (string) $type);
            }
            if ($parameter->isDefaultValueAvailable()) {
                $this->setDefault($option, $parameter->getDefaultValue());
            }
            $this->parameterCallback($parameter, $option);
        }
        $this->getOptionsResolver()->setRequired(
            $this->getRequiredOptions()
        );
    }

    private function addOption(int $position, string $option)
    {
        $this->options[$position] = $option;
        $this->getOptionsResolver()->define($option);
    }

    private function addRequiredOption(string $option)
    {
        $this->requiredOptions[] = $option;
        $this->getOptionsResolver()->setRequired($option);
    }

    /**
     * To be overwritten by implementing classes
     * @param ReflectionParameter $parameter
     */
    private function parameterCallback(ReflectionParameter $parameter, string $option)
    {
    }

    private static function camelToSnakeCase(string $value): string
    {
        return ltrim(
            strtolower(
                preg_replace(
                    '/[A-Z]([A-Z](?![a-z]))*/',
                    '_$0',
                    $value
                )
            ),
            '_'
        );
    }
}
