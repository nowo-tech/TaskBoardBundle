<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Board;

use Symfony\Component\Form\FormTypeInterface;

/**
 * Since 1.30.0 (spec 211): one field of a scope, e.g. a select of the organizations a user may create in. It sits in a branch
 * the user may not have chosen, so the bundle forces `required: false` and drops `constraints`:
 * {@see BoardScopeProviderInterface::resolve()} decides.
 */
final readonly class BoardScopeField
{
    /**
     * @param class-string<FormTypeInterface<mixed>> $formType
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $formType,
        public array $options = [],
    ) {
    }
}
