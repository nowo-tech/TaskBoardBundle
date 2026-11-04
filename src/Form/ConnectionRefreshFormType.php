<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Since 1.23: "Refresh from <provider>" (a full refresh optional). With `allow_mass_archive` it is the explicit
 * "Refresh and archive anyway" confirmation of the mass-archive guard, with its own CSRF token id.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('task_board')]
final class ConnectionRefreshFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['allow_mass_archive']) {
            $builder->add('allowMassArchive', HiddenType::class, ['data' => '1']);

            return;
        }
        if ($options['with_full']) {
            $this->addCheckbox($builder, 'full', [
                'label'    => 'task_board.sync.refresh.full',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'allow_mass_archive' => false,
            'with_full'          => true,
            'csrf_token_id'      => 'task_board_connection_refresh',
        ]);
        $resolver->setNormalizer('csrf_token_id', static fn ($options, string $id): string => $options['allow_mass_archive'] ? 'task_board_connection_refresh_archive' : $id);
        $resolver->setAllowedTypes('allow_mass_archive', 'bool');
        $resolver->setAllowedTypes('with_full', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'task_board_sync_refresh';
    }
}
