<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Board;

/**
 * Since 1.30.0 (spec 211): one field of a scope, e.g. a select of the organizations a user may create in. It sits in a branch
 * the user may not have chosen, so the bundle forces `required: false` and drops `constraints`:
 * {@see BoardScopeProviderInterface::resolve()} decides.
 *
 * Since 2.15.0 (REQ-ARCH-001 rule 3) the value object names the form type by class only (a plain class string, no
 * Symfony Form type in its signature or docblock); the bundle's New board form builds the field from it.
 */
final readonly class BoardScopeField
{
    /**
     * @param class-string $formType a Symfony form type class (e.g. ChoiceType::class)
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $formType,
        public array $options = [],
    ) {
    }
}
