<?php

namespace Flowpack\NodeTemplates\Infrastructure\ContentRepository;

use Flowpack\NodeTemplates\Domain\CaughtException;
use Flowpack\NodeTemplates\Domain\CaughtExceptions;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
class PropertiesAndReferences
{
    private array $properties;

    private array $references;

    private function __construct(array $properties, array $references)
    {
        $this->properties = $properties;
        $this->references = $references;
    }

    public static function createFromArrayAndTypeDeclarations(array $propertiesAndReferences, NodeType $nodeType): self
    {
        $references = [];
        $properties = [];
        foreach ($propertiesAndReferences as $propertyName => $propertyValue) {
            $declaration = $nodeType->getPropertyType($propertyName);
            if ($declaration === 'reference' || $declaration === 'references') {
                $references[$propertyName] = $propertyValue;
                continue;
            }
            $properties[$propertyName] = $propertyValue;
        }
        return new self($properties, $references);
    }

    /**
     * A few checks are run against the properties before they are applied on the node.
     *
     * 1. It is checked, that only properties will be set, that were declared in the NodeType
     *
     * 2. In case the property is a select-box, it is checked, that the current value is a valid option of the select-box
     *
     * 3. It is made sure is that a property value is not null when there is a default value:
     *  In case that due to a condition in the nodeTemplate `null` is assigned to a node property, it will override the defaultValue.
     *  This is a problem, as setting `null` might not be possible via the Neos UI and the Fusion rendering is most likely not going to handle this edge case.
     *  Related discussion {@link https://github.com/Flowpack/Flowpack.NodeTemplates/issues/41}
     */
    public function requireValidProperties(NodeType $nodeType, CaughtExceptions $caughtExceptions): array
    {
        $validProperties = [];
        $defaultValues = $nodeType->getDefaultValuesForProperties();
        foreach ($this->properties as $propertyName => $propertyValue) {
            $this->assertValidPropertyName($propertyName);
            try {
                if (!isset($nodeType->getProperties()[$propertyName])) {
                    throw new PropertyIgnoredException(
                        sprintf(
                            'Because property is not declared in NodeType. Got value `%s`.',
                            json_encode($propertyValue)
                        ),
                        1685869035209
                    );
                }
                if (array_key_exists($propertyName, $defaultValues) && $propertyValue === null) {
                    throw new PropertyIgnoredException(
                        sprintf(
                            'Because property is `null` and would override the default value `%s`.',
                            json_encode($defaultValues[$propertyName])
                        ),
                        1685869035371
                    );
                }
                $propertyType = PropertyType::fromPropertyOfNodeType($propertyName, $nodeType);
                if (!$propertyType->isMatchedBy($propertyValue)) {
                    throw new PropertyIgnoredException(
                        sprintf(
                            'Because value `%s` is not assignable to property type "%s".',
                            json_encode($propertyValue),
                            $propertyType->getValue()
                        ),
                        1685958105644
                    );
                }
                $propertyConfiguration = $nodeType->getProperties()[$propertyName];
                $editor = $propertyConfiguration['ui']['inspector']['editor'] ?? null;
                $type = $propertyConfiguration['type'] ?? null;
                $selectBoxValues = $propertyConfiguration['ui']['inspector']['editorOptions']['values'] ?? null;
                if ($editor === 'Neos.Neos/Inspector/Editors/SelectBoxEditor' && $selectBoxValues && in_array($type, ['string', 'array'], true)) {
                    $selectedValue = $type === 'string' ? [$propertyValue] : $propertyValue;
                    $difference = array_diff($selectedValue, array_keys($selectBoxValues));
                    if (\count($difference) !== 0) {
                        throw new PropertyIgnoredException(
                            sprintf(
                                'Because property has illegal select-box value(s): (%s)',
                                join(', ', $difference)
                            ),
                            1685869035452
                        );
                    }
                }
                $validProperties[$propertyName] = $propertyValue;
            } catch (PropertyIgnoredException $propertyNotSetException) {
                $caughtExceptions->add(
                    CaughtException::fromException($propertyNotSetException)->withOrigin(sprintf('Property "%s" in NodeType "%s"', $propertyName, $nodeType->getName()))
                );
            }
        }
        return $validProperties;
    }

    public function requireValidReferences(NodeType $nodeType, Context $subgraph, CaughtExceptions $caughtExceptions): array
    {
        $validReferences = [];
        foreach ($this->references as $referenceName => $referenceValue) {
            $referenceType = ReferenceType::fromPropertyOfNodeType($referenceName, $nodeType);
            if (!$referenceType->isMatchedBy($referenceValue, $subgraph)) {
                $caughtExceptions->add(CaughtException::fromException(new \RuntimeException(
                    sprintf(
                        'Reference could not be set, because node reference(s) %s cannot be resolved.',
                        json_encode($referenceValue)
                    ),
                    1685958176560
                ))->withOrigin(sprintf('Reference "%s" in NodeType "%s"', $referenceName, $nodeType->getName())));
            }
            $validReferences[$referenceName] = $referenceValue;
        }
        return $validReferences;
    }

    /**
     * In the old CR, it was common practice to set internal or meta properties via this syntax: `_hidden` but we don't allow this anymore.
     * @throws \InvalidArgumentException
     */
    private function assertValidPropertyName($propertyName): void
    {
        $legacyInternalProperties = ['_accessRoles', '_contentObject', '_hidden', '_hiddenAfterDateTime', '_hiddenBeforeDateTime', '_hiddenInIndex',
            '_index', '_name', '_nodeType', '_removed', '_workspace'];
        if (!is_string($propertyName) || $propertyName === '') {
            throw new \InvalidArgumentException(sprintf('Property name must be a non empty string. Got "%s".', $propertyName));
        }
        if ($propertyName[0] === '_') {
            $lowerPropertyName = strtolower($propertyName);
            if ($lowerPropertyName === '_hidden') {
                throw new \InvalidArgumentException('Using "_hidden" as property declaration was removed. Please use "hidden" on the first level instead.');
            }
            foreach ($legacyInternalProperties as $legacyInternalProperty) {
                if ($lowerPropertyName === strtolower($legacyInternalProperty)) {
                    throw new \InvalidArgumentException(sprintf('Internal legacy property "%s" not implement.', $propertyName));
                }
            }
        }
    }
}