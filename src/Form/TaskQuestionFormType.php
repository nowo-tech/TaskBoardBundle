<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Dto\TaskQuestionFormData;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionOption;
use Nowo\TaskBoardBundle\Question\TaskQuestionRecipientCandidate;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TiptapEditorBundle\Form\TiptapEditorType;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

use function array_filter;
use function array_map;
use function array_values;
use function is_array;
use function is_string;

/**
 * Since 2.23.0 (Beacon spec 242): *Ask a question* — the text, the Markdown context, four fixed option rows (label and
 * description; empty rows are left out) with the recommended one, single or multiple choice, *Allow another answer*, the
 * recipients (option `recipients`: the resolver's candidates) and the due date. The manager checks everything again.
 *
 * @extends AbstractType<TaskQuestionFormData>
 */
#[FormKitConfig('task_board')]
final class TaskQuestionFormType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<TaskQuestionRecipientCandidate> $candidates */
        $candidates = $options['recipients'];
        // Audit TE-L4: the candidates themselves are the choices (value: the id, label: the name), so two people with the
        // same name stay two choices; the data stays the list of ids.
        $byId = [];
        foreach ($candidates as $candidate) {
            $byId[$candidate->id] = $candidate;
        }

        $builder
            ->add('text', TextareaType::class, [
                'label'       => 'task_board.question.form.text',
                'empty_data'  => '',
                'constraints' => [new NotBlank(), new Length(max: TaskQuestion::TEXT_MAX)],
                'attr'        => ['rows' => 2, 'maxlength' => TaskQuestion::TEXT_MAX, 'class' => 'form-control nowo-task-question-form__text'],
            ])
            ->add('context', (bool) $options['rich_context'] ? TiptapEditorType::class : TextareaType::class, [
                'label'       => 'task_board.question.form.context',
                'help'        => 'task_board.question.form.context_help',
                'required'    => false,
                'constraints' => [new Length(max: TaskQuestion::CONTEXT_MAX)],
            ] + ((bool) $options['rich_context']
                // nowo-tech/tiptap-editor-bundle is optional (composer "suggest"): the task description's profile, as TaskFormType.
                ? ['config' => TaskBoardExtension::TIPTAP_TASK_PROFILE, 'min_height' => '140px', 'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN]
                : ['attr' => ['rows' => 4, 'maxlength' => TaskQuestion::CONTEXT_MAX, 'class' => 'form-control']]));
        for ($row = 1; $row <= TaskQuestionFormData::OPTION_ROWS; ++$row) {
            $builder
                ->add('option' . $row, TextType::class, [
                    'label'                        => 'task_board.question.form.option',
                    'label_translation_parameters' => ['%n%' => $row],
                    'required'                     => false,
                    'empty_data'                   => '',
                    'constraints'                  => [new Length(max: TaskQuestionOption::LABEL_MAX)],
                    'attr'                         => ['maxlength' => TaskQuestionOption::LABEL_MAX, 'class' => 'form-control'],
                ])
                ->add('option' . $row . 'Description', TextType::class, [
                    'label'                        => 'task_board.question.form.option_description',
                    'label_translation_parameters' => ['%n%' => $row],
                    'required'                     => false,
                    'constraints'                  => [new Length(max: TaskQuestionOption::DESCRIPTION_MAX)],
                    'attr'                         => ['maxlength' => TaskQuestionOption::DESCRIPTION_MAX, 'class' => 'form-control'],
                ]);
        }
        $builder
            ->add('recommended', ChoiceType::class, [
                'label'                     => 'task_board.question.form.recommended',
                'required'                  => false,
                'expanded'                  => true,
                'placeholder'               => 'task_board.question.form.recommended_none',
                'choices'                   => ['1' => '1', '2' => '2', '3' => '3', '4' => '4'],
                'choice_translation_domain' => false,
            ])
            ->add('choiceMode', ChoiceType::class, [
                'label'    => 'task_board.question.form.choice',
                'expanded' => true,
                'choices'  => ['task_board.question.form.choice_single' => 'single', 'task_board.question.form.choice_multiple' => 'multiple'],
            ])
            ->add('allowOther', CheckboxType::class, [
                'label'    => 'task_board.question.form.allow_other',
                'required' => false,
            ])
            ->add('recipients', ChoiceType::class, [
                'label'                     => 'task_board.question.form.recipients',
                'expanded'                  => true,
                'multiple'                  => true,
                'choices'                   => array_values($byId),
                'choice_value'              => static fn (?TaskQuestionRecipientCandidate $candidate): string => $candidate->id ?? '',
                'choice_label'              => static fn (TaskQuestionRecipientCandidate $candidate): string => $candidate->label,
                'choice_translation_domain' => false,
                'choice_attr'               => static fn (TaskQuestionRecipientCandidate $candidate): array => $candidate->external ? ['data-nowo-task-question-external' => '1'] : [],
                'constraints'               => [new Count(min: 1)],
            ])
            ->add('dueOn', DateType::class, [
                'label'    => 'task_board.question.form.due',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
            ]);
        $builder->get('recipients')->addModelTransformer(new CallbackTransformer(
            // The ids of the data (a re-shown form) as the candidates they name; an id no longer offered is left out.
            static fn (mixed $ids): array => array_values(array_filter(array_map(static fn (mixed $id): ?TaskQuestionRecipientCandidate => is_string($id) ? ($byId[$id] ?? null) : null, is_array($ids) ? $ids : []))),
            static fn (mixed $picked): array => array_values(array_map(static fn (TaskQuestionRecipientCandidate $candidate): string => $candidate->id, is_array($picked) ? $picked : [])),
        ));
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => TaskQuestionFormData::class,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'recipients'         => [],
            // Since 2.23.0: true, the Tiptap editor (task profile) for the context. Off by default: the context is Markdown as
            // the Task API returns it, and Tiptap stores HTML (a host that wants it passes the option, Tiptap installed).
            'rich_context' => false,
        ]);
        $resolver->setAllowedTypes('recipients', 'array');
        $resolver->setAllowedTypes('rich_context', 'bool');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'task_question';
    }
}
