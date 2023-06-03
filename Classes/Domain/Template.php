<?php
declare(strict_types=1);

namespace Flowpack\NodeTemplates\Domain;

use Neos\ContentRepository\Domain\NodeType\NodeTypeName;
use Neos\ContentRepository\Domain\NodeAggregate\NodeName;
use Neos\Flow\Annotations as Flow;

/** @Flow\Proxy(false) */
class Template implements \JsonSerializable
{
    private ?NodeTypeName $type;

    private ?NodeName $name;

    /**
     * @var array<string, mixed>
     */
    private array $properties;

    private Templates $childNodes;

    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(?NodeTypeName $type, ?NodeName $name, array $properties, Templates $childNodes)
    {
        $this->type = $type;
        $this->name = $name;
        $this->properties = $properties;
        $this->childNodes = $childNodes;
    }

    public static function empty(): self
    {
        return new self(null, null, [], new Templates());
    }

    public function getType(): ?NodeTypeName
    {
        return $this->type;
    }

    public function getName(): ?NodeName
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getChildNodes(): Templates
    {
        return $this->childNodes;
    }

    public function jsonSerialize()
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'properties' => $this->properties,
            'childNodes' => $this->childNodes
        ];
    }
}