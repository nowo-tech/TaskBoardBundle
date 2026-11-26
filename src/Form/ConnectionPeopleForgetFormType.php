<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Since 3.4.0 (`sync.people_forget`, Beacon spec 208 E): "Forget this person" on the People page, one per person shown.
 * No field: its own CSRF token (bound to the action, as the bundle's other CSRF ids), separate from the People save.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('task_board')]
final class ConnectionPeopleForgetFormType extends AbstractType
{
    public const NAME = 'task_board_sync_people_forget';

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'csrf_token_id'      => 'task_board_connection_people_forget',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return self::NAME;
    }
}
