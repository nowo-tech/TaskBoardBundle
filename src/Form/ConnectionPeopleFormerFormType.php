<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Since 1.28.0 (`sync.people_bulk_former`): the button that marks every unmapped person as a former person. No field:
 * its own CSRF token, separate from the People save.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('task_board')]
final class ConnectionPeopleFormerFormType extends AbstractType
{
    public const NAME = 'task_board_sync_people_former';

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'csrf_token_id'      => 'task_board_connection_people_former',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return self::NAME;
    }
}
