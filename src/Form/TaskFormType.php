<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\TagInputBundle\Form\TagType;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TiptapEditorBundle\Form\TiptapEditorType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<TaskFormData>
 */
final class TaskFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $columnChoices */
        $columnChoices = $options['column_choices'];
        $compact       = (bool) $options['compact'];

        $builder
            ->add('title', TextType::class, [
                'label'       => 'task_board.form.task.title',
                'constraints' => [new NotBlank()],
            ]);

        if (!$compact) {
            $builder
                ->add('description', TiptapEditorType::class, [
                    'label'              => 'task_board.form.task.description',
                    'required'           => false,
                    'config'             => 'task',
                    'min_height'         => '220px',
                    'placeholder'        => 'task_board.task.description_placeholder',
                    'translation_domain' => 'NowoTaskBoardBundle',
                ])
                ->add('priority', EnumType::class, [
                    'class' => TaskPriority::class,
                    'label' => 'task_board.form.task.priority',
                ]);
        }

        if ($compact) {
            $builder->add('columnId', HiddenType::class);
        } elseif ($columnChoices !== []) {
            $builder->add('columnId', ChoiceType::class, [
                'label'    => 'task_board.form.task.column',
                'choices'  => $columnChoices,
                'required' => false,
            ]);
        }

        if (!$compact) {
            $builder
                ->add('estimatedMinutes', IntegerType::class, [
                    'label'    => 'task_board.form.task.estimate',
                    'required' => false,
                ])
                ->add('dueAt', DateType::class, [
                    'label'           => 'task_board.form.task.due',
                    'required'        => false,
                    'widget'          => 'single_text',
                    'input'           => 'datetime_immutable',
                    'invalid_message' => 'task_board.form.task.due_invalid',
                ])
                ->add('tags', TagType::class, [
                    'label'              => 'task_board.form.task.tags',
                    'translation_domain' => 'NowoTaskBoardBundle',
                    'required'           => false,
                    'input_class'        => 'form-control',
                    'max_tags'           => 20,
                    'duplicates'         => false,
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'     => TaskFormData::class,
            'column_choices' => [],
            'compact'        => false,
        ]);
    }
}
