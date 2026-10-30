<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Dto\TaskDependencyFormData;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Since 1.15: the "Add dependency" dialog of the task detail — the relation and the other task of the board, picked
 * from a list the unmapped `search` field filters as you type (`nowo-task-board-option-filter` controller).
 *
 * Option `task_choices`: label => task id (the board's other tasks).
 *
 * @extends AbstractType<TaskDependencyFormData>
 */
#[FormKitConfig('task_board')]
final class TaskDependencyFormType extends AbstractType
{
    use FormOptionsTrait;

    private const FILTER = 'nowo-task-board-option-filter';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $taskChoices */
        $taskChoices = $options['task_choices'];

        $builder
            ->add('type', EnumType::class, [
                'class'        => TaskDependencyType::class,
                'label'        => 'task_board.dependency.form.type',
                'choice_label' => static fn (TaskDependencyType $type): string => 'task_board.dependency.relation.' . $type->relation(),
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('search', SearchType::class, [
                'mapped'   => false,
                'required' => false,
                'label'    => 'task_board.dependency.form.search',
                'attr'     => [
                    'class'                            => 'form-control',
                    'placeholder'                      => 'task_board.dependency.form.search_placeholder',
                    'autocomplete'                     => 'off',
                    'data-' . self::FILTER . '-target' => 'search',
                    'data-action'                      => 'input->' . self::FILTER . '#filter keydown.enter->' . self::FILTER . '#prevent',
                ],
            ])
            ->add('taskId', ChoiceType::class, [
                'label'                     => 'task_board.dependency.form.task',
                'choices'                   => $taskChoices,
                'choice_translation_domain' => false,
                'placeholder'               => false,
                'constraints'               => [new NotBlank()],
                'attr'                      => [
                    'class'                            => 'form-select nowo-task-dependency-picker',
                    'size'                             => 8,
                    'data-' . self::FILTER . '-target' => 'select',
                ],
            ]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => TaskDependencyFormData::class,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'task_choices'       => [],
        ]);
        $resolver->setAllowedTypes('task_choices', 'array');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'task_dependency';
    }
}
