<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_combine;
use function array_keys;
use function preg_replace;

/**
 * Since 1.23: the list radios of one level of the container browser. Named like {@see ConnectionLinkFormType}, so
 * the radios post `task_board_sync_link[container]` inside the link form; never submitted on its own.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('task_board')]
final class ContainerLevelFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $labels = $options['lists'];
        $builder->add('container', ChoiceType::class, [
            'choices'      => array_combine(array_keys($labels), array_keys($labels)),
            'choice_label' => static fn (string $id): string => $labels[$id],
            // Unique radio ids across the levels of the browser (each level is its own form).
            'choice_name'               => static fn (string $id): string => 'list_' . preg_replace('/[^A-Za-z0-9_]/', '_', $id),
            'expanded'                  => true,
            'multiple'                  => false,
            'label'                     => false,
            'placeholder'               => false,
            'required'                  => false,
            'choice_translation_domain' => false,
            'data'                      => $options['selected'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'csrf_protection'    => false,
            'lists'              => [],
            'selected'           => null,
        ]);
        // id => label ("Backlog — 1 234 tasks")
        $resolver->setAllowedTypes('lists', 'array');
        $resolver->setAllowedTypes('selected', ['null', 'string']);
    }

    public function getBlockPrefix(): string
    {
        return ConnectionLinkFormType::NAME;
    }
}
