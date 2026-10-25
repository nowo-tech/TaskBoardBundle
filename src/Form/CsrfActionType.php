<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-only manage action (advance column, remove link, remove member) — REQ-TWIG-005.
 *
 * Created with an empty name so the token keeps its historical flat name `_token`. Submit buttons
 * outside the child loop may post extra values (`action`, `columnId`), hence `allow_extra_fields`.
 *
 * @extends AbstractType<null>
 */
final class CsrfActionType extends AbstractType
{
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'POST',
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'allow_extra_fields' => true,
        ]);
        $resolver->setRequired('csrf_token_id');
        $resolver->setAllowedTypes('csrf_token_id', 'string');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
