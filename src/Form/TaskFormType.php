<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TagInputBundle\Form\TagType;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TiptapEditorBundle\Form\TiptapEditorType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<TaskFormData>
 */
#[FormKitConfig('task_board')]
final class TaskFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $columnChoices */
        $columnChoices = $options['column_choices'];
        $compact       = (bool) $options['compact'];

        $this->addText($builder, 'title', [
            'label'       => 'task_board.form.task.title',
            'constraints' => [new NotBlank()],
        ]);

        if (!$compact) {
            $this->addWithDefaults($builder, 'description', TiptapEditorType::class, [
                'label'              => 'task_board.form.task.description',
                'required'           => false,
                'config'             => 'task',
                'min_height'         => '220px',
                'placeholder'        => 'task_board.task.description_placeholder',
                'translation_domain' => 'NowoTaskBoardBundle',
            ]);
            $this->addWithDefaults($builder, 'priority', EnumType::class, [
                'class' => TaskPriority::class,
                'label' => 'task_board.form.task.priority',
            ]);
        }

        if ($compact) {
            $this->addWithDefaults($builder, 'columnId', HiddenType::class, []);
        } elseif ($columnChoices !== []) {
            $this->addChoice($builder, 'columnId', [
                'label'    => 'task_board.form.task.column',
                'choices'  => $columnChoices,
                'required' => false,
            ]);
        }

        if (!$compact) {
            $this->addInteger($builder, 'estimatedMinutes', [
                'label'    => 'task_board.form.task.estimate',
                'required' => false,
            ]);
            $this->addWithDefaults($builder, 'dueAt', DateType::class, [
                'label'           => 'task_board.form.task.due',
                'required'        => false,
                'widget'          => 'single_text',
                'input'           => 'datetime_immutable',
                'invalid_message' => 'task_board.form.task.due_invalid',
            ]);
            $this->addWithDefaults($builder, 'tags', TagType::class, [
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
            'data_class'         => TaskFormData::class,
            'column_choices'     => [],
            'compact'            => false,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
