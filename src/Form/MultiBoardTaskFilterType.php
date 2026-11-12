<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskFilter;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Since 2.13.0 (Beacon D-20261112-01): the filters of the multi-board task list. A GET form without CSRF, so a link
 * reproduces the view; the server validates every value again ({@see MultiBoardTaskFilter::fromQuery()}) and only ever
 * compares the board and the assignee with what the viewer may read.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('task_board')]
final class MultiBoardTaskFilterType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $priorities = [];
        foreach (TaskPriority::cases() as $priority) {
            $priorities['task_board.priority.' . $priority->value] = $priority->value;
        }
        $statuses = [];
        foreach (MultiBoardTaskFilter::STATUSES as $status) {
            $statuses['task_board.multi.status.' . $status] = $status;
        }
        $select = ['class' => 'form-select'];

        $builder
            ->add('view', HiddenType::class)
            ->add('sort', HiddenType::class)
            ->add('dir', HiddenType::class)
            ->add('q', SearchType::class, [
                'required' => false,
                'label'    => 'task_board.multi.filter.search',
                'attr'     => ['class' => 'form-control', 'maxlength' => 100, 'placeholder' => 'task_board.multi.filter.search_placeholder'],
            ])
            ->add('board', ChoiceType::class, [
                'required'                  => false,
                'label'                     => 'task_board.multi.filter.board',
                'placeholder'               => 'task_board.multi.filter.any_board',
                'choices'                   => $options['boards'],
                'choice_translation_domain' => false,
                'attr'                      => $select,
            ])
            ->add('assignee', ChoiceType::class, [
                'required'                  => false,
                'label'                     => 'task_board.multi.filter.assignee',
                'placeholder'               => 'task_board.multi.filter.any_assignee',
                'choices'                   => $options['assignees'],
                'choice_translation_domain' => false,
                'attr'                      => $select,
            ])
            ->add('column', ChoiceType::class, [
                'required'                  => false,
                'label'                     => 'task_board.multi.filter.column',
                'placeholder'               => 'task_board.multi.filter.any_column',
                'choices'                   => $options['columns'],
                'choice_translation_domain' => false,
                'attr'                      => $select,
            ])
            ->add('status', ChoiceType::class, [
                'required' => false,
                'label'    => 'task_board.multi.filter.status',
                'choices'  => $statuses,
                'attr'     => $select,
            ])
            ->add('priority', ChoiceType::class, [
                'required'    => false,
                'label'       => 'task_board.multi.filter.priority',
                'placeholder' => 'task_board.multi.filter.any_priority',
                'choices'     => $priorities,
                'attr'        => $select,
            ])
            ->add('due_from', TextType::class, [
                'required' => false,
                'label'    => 'task_board.multi.filter.due_from',
                'attr'     => ['class' => 'form-control', 'type' => 'date'],
            ])
            ->add('due_to', TextType::class, [
                'required' => false,
                'label'    => 'task_board.multi.filter.due_to',
                'attr'     => ['class' => 'form-control', 'type' => 'date'],
            ])
            ->add('label', TextType::class, [
                'required' => false,
                'label'    => 'task_board.multi.filter.label',
                'attr'     => ['class' => 'form-control', 'maxlength' => 100],
            ])
            ->add('overdue', CheckboxType::class, [
                'required'   => false,
                'label'      => 'task_board.multi.filter.overdue',
                'label_attr' => ['class' => 'nowo-task-check__label'],
                'row_attr'   => ['class' => 'nowo-task-check'],
            ]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'boards'             => [],
            'assignees'          => [],
            'columns'            => [],
        ]);
        $resolver->setAllowedTypes('boards', 'array');
        $resolver->setAllowedTypes('assignees', 'array');
        $resolver->setAllowedTypes('columns', 'array');
    }
}
