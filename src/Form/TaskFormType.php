<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TagInputBundle\Form\TagType;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Form\DataTransformer\TagsToStringTransformer;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TiptapEditorBundle\Form\TiptapEditorType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

use function class_exists;

/**
 * @extends AbstractType<TaskFormData>
 */
#[FormKitConfig('task_board')]
final class TaskFormType extends AbstractType
{
    use FormOptionsTrait;

    /** Since 1.14: a task colour is #rrggbb (the column holds 7 characters). */
    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/D';

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
            if ((bool) $options['rich_description']) {
                $this->addWithDefaults($builder, 'description', TiptapEditorType::class, [
                    'label'              => 'task_board.form.task.description',
                    'required'           => false,
                    'config'             => TaskBoardExtension::TIPTAP_TASK_PROFILE,
                    'min_height'         => '220px',
                    'placeholder'        => 'task_board.task.description_placeholder',
                    'translation_domain' => 'NowoTaskBoardBundle',
                ]);
            } else {
                // nowo-tech/tiptap-editor-bundle is optional (composer "suggest"): plain textarea fallback.
                $this->addWithDefaults($builder, 'description', TextareaType::class, [
                    'label'    => 'task_board.form.task.description',
                    'required' => false,
                    'attr'     => ['rows' => 8],
                ]);
            }
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
            if ((bool) $options['tag_input']) {
                $this->addWithDefaults($builder, 'tags', TagType::class, [
                    'label'              => 'task_board.form.task.tags',
                    'translation_domain' => 'NowoTaskBoardBundle',
                    'required'           => false,
                    'input_class'        => 'form-control',
                    'max_tags'           => 20,
                    'duplicates'         => false,
                ]);
            } else {
                // nowo-tech/tag-input-bundle is optional (composer "suggest"): comma-separated text fallback.
                $this->addWithDefaults($builder, 'tags', TextType::class, [
                    'label'    => 'task_board.form.task.tags',
                    'required' => false,
                ]);
                $builder->get('tags')->addModelTransformer(new TagsToStringTransformer());
            }

            // Since 1.14: the task colour. A native colour input cannot be empty, so the value lives in a hidden field
            // (empty = no colour) that the palette swatches and the unmapped custom colour input fill (picker controller).
            $this->addWithDefaults($builder, 'color', HiddenType::class, [
                'label'       => 'task_board.form.task.color',
                'required'    => false,
                'constraints' => [new Regex(pattern: self::COLOR_PATTERN)],
            ]);
            $this->addWithDefaults($builder, 'customColor', ColorType::class, [
                'label'    => 'task_board.form.task.custom_color',
                'mapped'   => false,
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'     => TaskFormData::class,
            'column_choices' => [],
            'compact'        => false,
            // Rich widgets only when the optional bundles are installed (avoids "Could not load type").
            'rich_description'   => class_exists(TiptapEditorType::class),
            'tag_input'          => class_exists(TagType::class),
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
        $resolver->setAllowedTypes('rich_description', 'bool');
        $resolver->setAllowedTypes('tag_input', 'bool');
    }
}
