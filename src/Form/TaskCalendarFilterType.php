<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_map;

/**
 * Since 2.1.0 (spec 214, FR-021): the filters of the calendar. A GET form, so a link reproduces the view; the server applies them.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('task_board')]
final class TaskCalendarFilterType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $sr         = ['class' => 'nowo-task-sr-only'];
        $priorities = [];
        foreach (array_map(static fn (TaskPriority $priority): string => $priority->value, TaskPriority::cases()) as $priority) {
            $priorities['task_board.priority.' . $priority] = $priority;
        }

        $builder
            ->add('mode', HiddenType::class)
            ->add('date', HiddenType::class)
            ->add('assignee', ChoiceType::class, [
                'required'                  => false,
                'label'                     => 'task_board.calendar.filter_assignee',
                'label_attr'                => $sr,
                'placeholder'               => 'task_board.calendar.filter_any_assignee',
                'choices'                   => $options['assignees'],
                'choice_translation_domain' => false,
                'attr'                      => ['class' => 'form-select'],
            ])
            ->add('priority', ChoiceType::class, [
                'required'    => false,
                'label'       => 'task_board.list.filter_priority',
                'label_attr'  => $sr,
                'placeholder' => 'task_board.list.filter_any_priority',
                'choices'     => $priorities,
                'attr'        => ['class' => 'form-select'],
            ])
            ->add('column', ChoiceType::class, [
                'required'                  => false,
                'label'                     => 'task_board.list.filter_column',
                'label_attr'                => $sr,
                'placeholder'               => 'task_board.list.filter_any_column',
                'choices'                   => $options['columns'],
                'choice_translation_domain' => false,
                'attr'                      => ['class' => 'form-select'],
            ])
            ->add('tag', TextType::class, [
                'required'   => false,
                'label'      => 'task_board.calendar.filter_tag',
                'label_attr' => $sr,
                'attr'       => ['class' => 'form-control', 'placeholder' => 'task_board.calendar.filter_tag', 'maxlength' => 100],
            ]);

        foreach (['completed' => 'task_board.calendar.filter_completed', 'subtasks' => 'task_board.calendar.filter_subtasks'] as $name => $label) {
            $builder->add($name, CheckboxType::class, [
                'required'   => false,
                'label'      => $label,
                'label_attr' => ['class' => 'nowo-task-check__label'],
                'row_attr'   => ['class' => 'nowo-task-check'],
            ]);
        }
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'assignees'          => [],
            'columns'            => [],
        ]);
        $resolver->setAllowedTypes('assignees', 'array');
        $resolver->setAllowedTypes('columns', 'array');
    }
}
