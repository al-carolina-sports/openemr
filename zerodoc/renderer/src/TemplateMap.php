<?php

declare(strict_types=1);

namespace Aem\Renderer;

use Aem\Renderer\Contracts\ElementDef;
use Aem\Renderer\Contracts\OptionDef;

/**
 * The caller-supplied lookup of element and option descriptors.
 *
 * A `finding` row (06 §1.3) references `element_id` and `option_id` but
 * carries no labels. The renderer does no database access, so the caller
 * hands it the descriptors it will need. Anything missing raises rather
 * than silently rendering an empty clause.
 */
final class TemplateMap
{
    /** @var array<int,ElementDef> */
    private array $elements = [];
    /** @var array<int,OptionDef> */
    private array $options = [];

    /**
     * @param iterable<ElementDef|array> $elements
     * @param iterable<OptionDef|array>  $options
     */
    public function __construct(iterable $elements = [], iterable $options = [])
    {
        foreach ($elements as $element) {
            $this->addElement($element instanceof ElementDef ? $element : ElementDef::fromArray($element));
        }
        foreach ($options as $option) {
            $this->addOption($option instanceof OptionDef ? $option : OptionDef::fromArray($option));
        }
    }

    public function addElement(ElementDef $element): void
    {
        $this->elements[$element->id] = $element;
    }

    public function addOption(OptionDef $option): void
    {
        $this->options[$option->id] = $option;
    }

    public function element(?int $id): ?ElementDef
    {
        if ($id === null) {
            return null;
        }
        if (!isset($this->elements[$id])) {
            throw new \OutOfBoundsException("No ElementDef supplied for element_id {$id}.");
        }

        return $this->elements[$id];
    }

    public function option(?int $id): ?OptionDef
    {
        if ($id === null) {
            return null;   // free-text finding: value_text carries the content
        }
        if (!isset($this->options[$id])) {
            throw new \OutOfBoundsException("No OptionDef supplied for option_id {$id}.");
        }

        return $this->options[$id];
    }
}
