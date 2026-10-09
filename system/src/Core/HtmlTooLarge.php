<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * HTML or SVG over a limit of Core\HtmlLimits: nothing was parsed or saved from it. An InvalidArgumentException, so an MCP
 * tool that meets it returns its English message as the error (and the call counts as refused); the administration
 * shows localized() in its own language, an import puts the reason into its report and skips the item.
 */
final class HtmlTooLarge extends \InvalidArgumentException
{
    /**
     * @param array{limit: string, value: int, max: int} $violation
     * @param string $field the parameter or form field it came in ("text"), named in the message
     */
    public function __construct(public readonly array $violation, public readonly string $field = '')
    {
        parent::__construct(($field !== '' ? $field . ': ' : '') . HtmlLimits::english($violation));
    }

    /** The same limit, for a named parameter or field. */
    public function inField(string $field): self
    {
        return new self($this->violation, $field);
    }

    /** The message in the administration's language (without the field: a form shows it at the field). */
    public function localized(): string
    {
        return HtmlLimits::message($this->violation);
    }
}
