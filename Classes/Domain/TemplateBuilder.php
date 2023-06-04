<?php

namespace Flowpack\NodeTemplates\Domain;

use Neos\Flow\Annotations as Flow;
use Neos\Utility\Arrays;

/**
 * @internal implementation detail of {@see TemplateFactory}
 * @psalm-immutable
 * @Flow\Proxy(false)
 */
class TemplateBuilder
{
    /**
     * @psalm-readonly
     */
    private array $configuration;

    /**
     * @psalm-readonly
     */
    private array $evaluationContext;

    /**
     * @psalm-readonly
     * @psalm-var \Closure(mixed $value, array<string, mixed> $evaluationContext): mixed
     */
    private \Closure $configurationValueProcessor;

    /**
     * @psalm-readonly
     */
    private CaughtExceptions $caughtExceptions;

    /**
     * @psalm-param array<string, mixed> $configuration
     * @psalm-param array<string, mixed> $evaluationContext
     * @psalm-param \Closure(mixed $value, array<string, mixed> $evaluationContext): mixed $configurationValueProcessor
     */
    private function __construct(
        array $configuration,
        array $evaluationContext,
        \Closure $configurationValueProcessor,
        CaughtExceptions $caughtExceptions
    ) {
        $this->configuration = $configuration;
        $this->evaluationContext = $evaluationContext;
        $this->configurationValueProcessor = $configurationValueProcessor;
        $this->caughtExceptions = $caughtExceptions;
        $this->validateNestedLevelTemplateConfigurationKeys();
    }

    /**
     * @psalm-param array<string, mixed> $configuration
     * @psalm-param array<string, mixed> $evaluationContext
     * @psalm-param \Closure(mixed $value, array<string, mixed> $evaluationContext): mixed $configurationValueProcessor
     */
    public static function createForRoot(
        array $configuration,
        array $evaluationContext,
        \Closure $configurationValueProcessor,
        CaughtExceptions $caughtExceptions
    ): self {
        $builder = new self(
            $configuration,
            $evaluationContext,
            $configurationValueProcessor,
            $caughtExceptions
        );
        $builder->validateRootLevelTemplateConfigurationKeys();
        return $builder;
    }

    public function getCaughtExceptions(): CaughtExceptions
    {
        return $this->caughtExceptions;
    }

    /**
     * @psalm-param array<string, mixed> $configuration
     */
    public function withConfiguration(array $configuration): self
    {
        return new self(
            $configuration,
            $this->evaluationContext,
            $this->configurationValueProcessor,
            $this->caughtExceptions
        );
    }

    /**
     * @psalm-param array<string, mixed> $evaluationContext
     */
    public function withMergedEvaluationContext(array $evaluationContext): self
    {
        if ($evaluationContext === []) {
            return $this;
        }
        return new self(
            $this->configuration,
            array_merge($this->evaluationContext, $evaluationContext),
            $this->configurationValueProcessor,
            $this->caughtExceptions
        );
    }

    /**
     * @psalm-param string|list<string> $configurationPath
     * @param mixed $fallback
     * @return mixed
     * @throws StopBuildingTemplatePartException
     */
    public function processConfiguration($configurationPath, $fallback)
    {
        if (($value = $this->getRawConfiguration($configurationPath)) === null) {
            return $fallback;
        }
        try {
            return ($this->configurationValueProcessor)($value, $this->evaluationContext);
        } catch (\Throwable $exception) {
            $this->caughtExceptions->add(
                CaughtException::fromException($exception)->withCause(
                    sprintf('Expression "%s" in "%s"', $value, is_array($configurationPath) ? join('.', $configurationPath) : $configurationPath)
                )
            );
            throw new StopBuildingTemplatePartException();
        }
    }

    /**
     * @psalm-param string|list<string> $configurationPath
     */
    public function getRawConfiguration($configurationPath)
    {
        return Arrays::getValueByPath($this->configuration, $configurationPath);
    }

    private function validateNestedLevelTemplateConfigurationKeys(): void
    {
        foreach (array_keys($this->configuration) as $key) {
            if (!in_array($key, ['type', 'name', 'properties', 'childNodes', 'when', 'withItems', 'withContext'], true)) {
                throw new \InvalidArgumentException(sprintf('Template configuration has illegal key "%s', $key));
            }
        }
    }

    private function validateRootLevelTemplateConfigurationKeys(): void
    {
        foreach (array_keys($this->configuration) as $key) {
            if (!in_array($key, ['properties', 'childNodes', 'when', 'withContext'], true)) {
                throw new \InvalidArgumentException(sprintf('Root template configuration has illegal key "%s', $key));
            }
        }
    }
}
