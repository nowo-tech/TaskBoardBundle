<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TagInputBundle\Form\TagType;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Form\DataTransformer\MinutesToDurationTransformer;
use Nowo\TaskBoardBundle\Form\DataTransformer\TagsToStringTransformer;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Nowo\TiptapEditorBundle\Form\TiptapEditorType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function class_exists;

/**
 * @extends AbstractType<TaskFormData>
 */
#[FormKitConfig('task_board')]
final class TaskFormType extends AbstractType
{
    use FormOptionsTrait;
    /** Since 1.15: the longest title (task_board_tasks.title is VARCHAR(255)). */
    public const TITLE_MAX = TaskConstraints::TITLE_MAX;

    /** Since 1.14: a task colour is #rrggbb (the column holds 7 characters). */
    public const COLOR_PATTERN = TaskConstraints::TASK_COLOR_PATTERN;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $columnChoices */
        $columnChoices = $options['column_choices'];
        $compact       = (bool) $options['compact'];

        $this->addText($builder, 'title', [
            // Since 1.21: an empty or missing title is a NotBlank error, not a TypeError on the DTO (string).
            'empty_data'  => '',
            'label'       => 'task_board.form.task.title',
            'constraints' => TaskConstraints::taskTitle(),
            'attr'        => ['maxlength' => self::TITLE_MAX],
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
                // Since 1.21: a submission without the field keeps the default priority (no TypeError on the DTO).
                'empty_data' => TaskPriority::Normal->value,
            ]);
        }

        if ($compact) {
            $this->addWithDefaults($builder, 'columnId', HiddenType::class, []);
        } elseif ($columnChoices !== []) {
            $shown = (bool) $options['shown_column'];
            $this->addChoice($builder, 'columnId', [
                'label'    => 'task_board.form.task.column',
                'choices'  => $columnChoices,
                'required' => false,
            ] + ($shown ? ['error_bubbling' => false] : []));
            if ($shown) {
                // Since 2.2.1: the column the page was rendered with, so a stale page does not move the task back.
                $data = $options['data'] ?? null;
                $this->addWithDefaults($builder, 'columnIdShown', HiddenType::class, [
                    'mapped' => false,
                    'data'   => $data instanceof TaskFormData ? (string) $data->columnId : '',
                ]);
            }
        }

        if (!$compact) {
            // Since 1.20 a duration ("2 h 30 min", "90", "1.5h"), shown as one; the model keeps minutes.
            $this->addWithDefaults($builder, 'estimatedMinutes', TextType::class, [
                'label'           => 'task_board.form.task.estimate',
                'required'        => false,
                'invalid_message' => 'task_board.form.task.estimate_invalid',
                'attr'            => ['placeholder' => 'task_board.form.task.estimate_placeholder', 'inputmode' => 'text', 'autocomplete' => 'off'],
            ]);
            $builder->get('estimatedMinutes')->addModelTransformer(new MinutesToDurationTransformer());
            // Since 1.15: the start date (the Gantt bar starts there); after the due date is a form error.
            $this->addWithDefaults($builder, 'startAt', DateType::class, [
                'label'           => 'task_board.form.task.start',
                'required'        => false,
                'widget'          => 'single_text',
                'input'           => 'datetime_immutable',
                'invalid_message' => 'task_board.form.task.due_invalid',
                'constraints'     => [new Callback(static function (mixed $startAt, ExecutionContextInterface $context): void {
                    $form  = $context->getObject();
                    $dueAt = $form instanceof FormInterface ? $form->getParent()?->get('dueAt')->getData() : null;
                    if (TaskConstraints::startsAfterDue($startAt, $dueAt)) {
                        $context->buildViolation('task_board.form.task.start_after_due')
                            ->setTranslationDomain('NowoTaskBoardBundle')
                            ->addViolation();
                    }
                })],
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
                'constraints' => TaskConstraints::taskColor(),
            ]);
            $this->addWithDefaults($builder, 'customColor', ColorType::class, [
                'label'    => 'task_board.form.task.custom_color',
                'mapped'   => false,
                'required' => false,
            ]);
        }

        // Since 2.19.0 (Beacon spec 234, FR-028): one row per native custom field (`custom_fields`, from TaskCustomFieldForm),
        // unmapped; TaskCustomFieldForm::save() writes them through TaskCustomFieldManager::setField().
        foreach ($options['custom_fields'] as $definition) {
            $common = ['label' => $definition['name'], 'translation_domain' => false, 'mapped' => false, 'required' => false, 'data' => $definition['value']];
            match ($definition['type']) {
                'number'   => $this->addWithDefaults($builder, $definition['name_attr'], NumberType::class, $common + ['scale' => 10, 'html5' => true, 'attr' => ['step' => 'any']]),
                'dropdown' => $this->addWithDefaults($builder, $definition['name_attr'], ChoiceType::class, $common + ['choices' => $definition['choices'], 'placeholder' => '—', 'choice_translation_domain' => false]),
                default    => $this->addWithDefaults($builder, $definition['name_attr'], TextType::class, $common + ['attr' => ['maxlength' => 5000]]),
            };
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'     => TaskFormData::class,
            'column_choices' => [],
            'compact'        => false,
            // Since 2.2.1 (the edit form): also posts the column the page showed; a column error shows on the field.
            'shown_column' => false,
            // Rich widgets only when the optional bundles are installed (avoids "Could not load type").
            'rich_description'   => class_exists(TiptapEditorType::class),
            'tag_input'          => class_exists(TagType::class),
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            // Since 2.19.0: the board's native custom fields (TaskCustomFieldForm::options()).
            'custom_fields' => [],
        ]);
        $resolver->setAllowedTypes('custom_fields', 'array');
        $resolver->setAllowedTypes('shown_column', 'bool');
        $resolver->setAllowedTypes('rich_description', 'bool');
        $resolver->setAllowedTypes('tag_input', 'bool');
    }
}
