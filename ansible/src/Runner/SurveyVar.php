<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * One variable a template asks for when it is launched (a Semaphore survey variable, an AWX survey question).
 * The plugin sends only these, checked against their type (Runs\VariableValidator).
 */
final class SurveyVar
{
    /** Types the plugin knows: a line of text, several lines, a whole number, one of a list, a password. */
    public const TYPES = ['string', 'text', 'int', 'enum', 'secret'];

    /**
     * @param  string  $type  one of TYPES, or "unknown" (see $rawType)
     * @param  string  $target  "" (an extra variable) or "env" (an environment variable of the run)
     * @param  array<int, array{name: string, value: string}>  $values  the choices of an enum
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly string $type,
        public readonly string $rawType,
        public readonly bool $required,
        public readonly string $target = '',
        public readonly array $values = [],
        public readonly ?string $default = null,
    ) {
    }

    /**
     * Is the answer a password (sent to the runner only, never stored or shown)?
     */
    public function isSecret(): bool
    {
        return $this->type === 'secret';
    }

    /**
     * The label of the form field.
     */
    public function label(): string
    {
        return $this->title !== '' ? $this->title : $this->name;
    }
}
