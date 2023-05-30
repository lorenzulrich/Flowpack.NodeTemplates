<?php
namespace Flowpack\NodeTemplates;

use Neos\Flow\Annotations as Flow;
use Flowpack\NodeTemplates\Service\EelEvaluationService;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Utility as NodeUtility;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Neos\Service\NodeOperations;
use Neos\Utility\ObjectAccess;

class Template
{
    /**
     * @var string
     */
    protected $type;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var array
     */
    protected $properties;

    /**
     * @var array<Template>
     */
    protected $childNodes;

    /**
     * Options can be used to configure third party processing
     *
     * @var array
     */
    protected $options;

    /**
     * @var string
     */
    protected $when;

    /**
     * @var string
     */
    protected $withItems;

    /**
     * @var array
     */
    protected $withContext;

    /**
     * @var EelEvaluationService
     * @Flow\Inject
     */
    protected $eelEvaluationService;

    /**
     * @var NodeOperations
     * @Flow\Inject
     */
    protected $nodeOperations;

    /**
     * @var PersistenceManager
     * @Flow\Inject
     */
    protected $persistenceManager;

    /**
     * Template constructor
     *
     * @param string $type
     * @param string $name
     * @param array $properties
     * @param array<Template> $childNodes
     * @param array $options
     * @param string $when
     * @param string $withItems
     * @param array $withContext
     */
    public function __construct(
        $type = null,
        $name = null,
        array $properties = [],
        array $childNodes = [],
        array $options = [],
        $when = null,
        $withItems = null,
        $withContext = []
    ) {
        $this->type = $type;
        $this->name = $name;
        $this->properties = $properties;
        $this->childNodes = $childNodes;
        $this->options = $options;
        $this->when = $when;
        $this->withItems = $withItems;
        $this->withContext = $withContext;
    }

    /**
     * Apply this template to the given node while providing context for EEL processing
     *
     * The entry point
     */
    public function apply(NodeInterface $node, array $context): void
    {
        $context = $this->mergeContextAndWithContext($context);
        $this->applyTemplateOnNode($node, $context);
    }

    private function applyTemplateOnNode(NodeInterface $node, array $context): void
    {
        $context['node'] = $node;

        // Check if this template should be applied at all
        if (!$this->isApplicable($context)) {
            return;
        }
        $this->setProperties($node, $context);

        // Create child nodes if applicable
        /** @var Template $childNodeTemplate */
        foreach ($this->childNodes as $childNodeTemplate) {
            $childNodeTemplate->createOrFetchAndApply($node, $context);
        }

        $this->emitNodeTemplateApplied($node, $context, $this->options);
    }

    /**
     * @deprecated will be made internal and private
     * @internal
     */
    public function createOrFetchAndApply(NodeInterface $parentNode, array $context): void
    {
        $context['parentNode'] = $parentNode;

        $context = $this->mergeContextAndWithContext($context);

        if (!$this->isApplicable($context)) {
            return;
        }

        $items = $this->withItems;

        if (!$items) { // Not set
            $items = [false];
        } else if (preg_match(\Neos\Eel\Package::EelExpressionRecognizer, $items)) { // Eel expression
            $items = $this->eelEvaluationService->evaluateEelExpression($items, $context);
        } else { // Yaml array converted to comma-delimited string
            $items = explode(',', $items);
        }

        foreach ($items as $key => $item) {
            // only set item context if withItems is set in template to prevent losing item context from parent template
            if ($this->withItems) {
                $context['item'] = $item;
                $context['key'] = $key;
            }
            $node = null;
            $name = $this->name;
            if (preg_match(\Neos\Eel\Package::EelExpressionRecognizer, $name)) {
                $name = $this->eelEvaluationService->evaluateEelExpression($name, $context);
            }
            if ($name !== null) {
                $flowQuery = new FlowQuery(array($parentNode));
                $node = $flowQuery->find($name)->get(0);
            }
            if (!$node instanceof NodeInterface) {
                $type = $this->type;
                if (preg_match(\Neos\Eel\Package::EelExpressionRecognizer, $type)) {
                    $type = $this->eelEvaluationService->evaluateEelExpression($type, $context);
                }
                $node = $this->nodeOperations->create($parentNode, ['nodeType' => $type, 'nodeName' => $name], 'into');

                // All document node types get a uri path segment; if it is not explicitly set in the properties,
                // it should be built based on the title property
                if ($node->getNodeType()->isOfType('Neos.Neos:Document')
                    && isset($this->properties['title'])
                    && !isset($this->properties['uriPathSegment'])) {
                    $node->setProperty('uriPathSegment', NodeUtility::renderValidNodeName($this->properties['title']));
                }
            }
            if ($node instanceof NodeInterface) {
                $this->applyTemplateOnNode($node, $context);
            }
        }
    }

    public function isApplicable(array $context): bool
    {
        $isApplicable = true;
        if ($this->when !== null) {
            $isApplicable = $this->when;
            if (preg_match(\Neos\Eel\Package::EelExpressionRecognizer, $isApplicable)) {
                $isApplicable = $this->eelEvaluationService->evaluateEelExpression($isApplicable, $context);
            }
        }
        return (bool)$isApplicable;
    }

    /**
     * TODO: Handle EEL parsing for nested properties
     */
    protected function setProperties(NodeInterface $node, array $context): void
    {
        foreach ($this->properties as $propertyName => $propertyValue) {
            //evaluate Eel only on string properties
            if (is_string($propertyValue) && preg_match(\Neos\Eel\Package::EelExpressionRecognizer, $propertyValue)) {
                $this->persistenceManager->persistAll();
                $propertyValue = $this->eelEvaluationService->evaluateEelExpression($propertyValue, $context);
            }
            if ($propertyName[0] === '_') {
                ObjectAccess::setProperty($node, substr($propertyName, 1), $propertyValue);
            } else {
                $node->setProperty($propertyName, $propertyValue);
            }
        }
    }

    /**
     * Merge `withContext` onto the current $context, evaluating EEL if necessary
     *
     * The option `withContext` takes an array of items whose value can be any yaml/php type
     * and might also contain eel expressions
     *
     * ```yaml
     * withContext:
     *   someText: '<p>foo</p>'
     *   processedData: "${String.trim(data.bla)}"
     *   booleanType: true
     *   arrayType: ["value"]
     * ```
     *
     * scopes and order of evaluation:
     *
     * - inside `withContext` the "upper" context may be accessed in eel expressions,
     * but sibling context values are not available
     *
     * - `withContext` is evaluated before `when` and `withItems` so you can access computed values,
     * that means the context `item` from `withItems` will not be available yet
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function mergeContextAndWithContext(array $context): array
    {
        if ($this->withContext === []) {
            return $context;
        }
        $withContext = [];
        foreach ($this->withContext as $key => $value) {
            if (is_string($value) && preg_match(\Neos\Eel\Package::EelExpressionRecognizer, $value)) {
                $value = $this->eelEvaluationService->evaluateEelExpression($value, $context);
            }
            $withContext[$key] = $value;
        }
        return array_merge($context, $withContext);
    }

    /**
     * Signals that a node template has been applied to the given node.
     *
     * @Flow\Signal
     * @api
     */
    public function emitNodeTemplateApplied(NodeInterface $node, array $context, array $options): void
    {
    }
}
