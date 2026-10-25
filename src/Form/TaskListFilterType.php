<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Client-side filters of the task list (REQ-TWIG-005).
 *
 * Never submitted: the `nowo-task-board-list-filter` Stimulus controller reads the fields through
 * their `data-*-target` attributes, so every field is unmapped and keeps those attributes.
 *
 * @extends AbstractType<null>
 */
final class TaskListFilterType extends AbstractType
{
    private const STIMULUS = 'nowo-task-board-list-filter';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $priorities */
        $priorities = $options['priorities'];
        /** @var list<string> $columnNames */
        $columnNames = $options['column_names'];

        $builder
            ->add('search', SearchType::class, [
                'mapped'     => false,
                'required'   => false,
                'label'      => 'task_board.list.filter_search',
                'label_attr' => ['class' => 'form-label mb-0'],
                'attr'       => [
                    'class'                              => 'form-control',
                    'data-' . self::STIMULUS . '-target' => 'search',
                    'data-action'                        => 'input->' . self::STIMULUS . '#filter',
                ],
            ])
            ->add('priority', ChoiceType::class, [
                'mapped'                    => false,
                'required'                  => false,
                'label'                     => 'task_board.list.filter_priority',
                'label_attr'                => ['class' => 'form-label mb-0'],
                'placeholder'               => 'task_board.list.filter_all',
                'choices'                   => array_combine($priorities, $priorities),
                'choice_translation_domain' => false,
                'attr'                      => [
                    'class'                              => 'form-select',
                    'data-' . self::STIMULUS . '-target' => 'priority',
                    'data-action'                        => 'change->' . self::STIMULUS . '#filter',
                ],
            ])
            ->add('column', ChoiceType::class, [
                'mapped'                    => false,
                'required'                  => false,
                'label'                     => 'task_board.list.filter_column',
                'label_attr'                => ['class' => 'form-label mb-0'],
                'placeholder'               => 'task_board.list.filter_all',
                'choices'                   => array_combine($columnNames, $columnNames),
                'choice_translation_domain' => false,
                'attr'                      => [
                    'class'                              => 'form-select',
                    'data-' . self::STIMULUS . '-target' => 'column',
                    'data-action'                        => 'change->' . self::STIMULUS . '#filter',
                ],
            ]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'priorities'         => [],
            'column_names'       => [],
            'attr'               => ['onsubmit' => 'return false;'],
        ]);
        $resolver->setAllowedTypes('priorities', 'string[]');
        $resolver->setAllowedTypes('column_names', 'string[]');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'task_list_filter';
    }
}
