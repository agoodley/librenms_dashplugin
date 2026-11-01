<?php

namespace Agoodley\LibrenmsCompliance\Rules;

/**
 * A rule's regular expression failed on a line of a configuration (Pattern::matches()).
 */
final class PatternFailed extends \RuntimeException
{
    public function __construct(public readonly string $regex, string $error)
    {
        parent::__construct("the pattern $regex failed on a line of the configuration ($error)");
    }
}
