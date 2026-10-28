<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
                'mapped'   => false,
                'required' => false,
                'label'    => 'task_board.list.filter_search',
                // Since 1.13.1 the labels stay for screen readers and the fields read as one filter bar.
                'label_attr' => ['class' => 'nowo-task-sr-only'],
                'attr'       => [
                    'class'                              => 'form-control',
                    'placeholder'                        => 'task_board.list.filter_search_placeholder',
                    'data-' . self::STIMULUS . '-target' => 'search',
                    'data-action'                        => 'input->' . self::STIMULUS . '#filter',
                ],
            ])
            ->add('priority', ChoiceType::class, [
                'mapped'      => false,
                'required'    => false,
                'label'       => 'task_board.list.filter_priority',
                'label_attr'  => ['class' => 'nowo-task-sr-only'],
                'placeholder' => 'task_board.list.filter_any_priority',
                // Since 1.13.1 the priorities read in the user's language.
                'choices' => array_combine(array_map(static fn (string $priority): string => 'task_board.priority.' . $priority, $priorities), $priorities),
                'attr'    => [
                    'class'                              => 'form-select',
                    'data-' . self::STIMULUS . '-target' => 'priority',
                    'data-action'                        => 'change->' . self::STIMULUS . '#filter',
                ],
            ])
            ->add('column', ChoiceType::class, [
                'mapped'                    => false,
                'required'                  => false,
                'label'                     => 'task_board.list.filter_column',
                'label_attr'                => ['class' => 'nowo-task-sr-only'],
                'placeholder'               => 'task_board.list.filter_any_column',
                'choices'                   => array_combine($columnNames, $columnNames),
                'choice_translation_domain' => false,
                'attr'                      => [
                    'class'                              => 'form-select',
                    'data-' . self::STIMULUS . '-target' => 'column',
                    'data-action'                        => 'change->' . self::STIMULUS . '#filter',
                ],
            ])
            // Since 1.15: the order of the rows inside each group (a display option, not a filter).
            ->add('sort', ChoiceType::class, [
                'mapped'      => false,
                'required'    => false,
                'label'       => 'task_board.list.sort',
                'label_attr'  => ['class' => 'nowo-task-sr-only'],
                'placeholder' => 'task_board.list.sort_manual',
                'choices'     => [
                    'task_board.list.sort_title'    => 'title',
                    'task_board.list.sort_priority' => 'priority',
                    'task_board.list.sort_due'      => 'due',
                    'task_board.list.sort_column'   => 'column',
                ],
                'attr' => [
                    'class'                              => 'form-select',
                    'data-' . self::STIMULUS . '-target' => 'sort',
                    'data-action'                        => 'change->' . self::STIMULUS . '#sort',
                    'data-nowo-task-filter-ignore'       => '',
                ],
            ])
            // Since 1.15: only the tasks that took longer than estimated, and the Estimate / Spent / Deviation columns.
            ->add('overEstimate', CheckboxType::class, [
                'mapped'     => false,
                'required'   => false,
                'label'      => 'task_board.list.filter_over_estimate',
                'label_attr' => ['class' => 'nowo-task-check__label'],
                'row_attr'   => ['class' => 'nowo-task-check'],
                'attr'       => [
                    'data-' . self::STIMULUS . '-target' => 'overEstimate',
                    'data-action'                        => 'change->' . self::STIMULUS . '#filter',
                ],
            ])
            ->add('timeColumns', CheckboxType::class, [
                'mapped'     => false,
                'required'   => false,
                'label'      => 'task_board.list.show_time_columns',
                'label_attr' => ['class' => 'nowo-task-check__label'],
                'row_attr'   => ['class' => 'nowo-task-check'],
                'attr'       => [
                    'data-' . self::STIMULUS . '-target' => 'timeColumns',
                    'data-action'                        => 'change->' . self::STIMULUS . '#toggleTimeColumns',
                    // A display option, not a filter: the folded filters do not count it as active.
                    'data-nowo-task-filter-ignore' => '',
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
            // Since 1.13.1 no inline handler (strict CSP): the list filter controller stops the submit.
            'attr' => ['data-action' => 'submit->' . self::STIMULUS . '#prevent'],
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
