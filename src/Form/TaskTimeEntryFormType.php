<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Dto\TaskTimeEntryFormData;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Since 1.15: the "Log time" dialog of the task detail — minutes spent, the day and an optional note. Since 2.12.0 a
 * *Billable* switch (FormKit's `switch` checkbox), preset to the host default.
 *
 * @extends AbstractType<TaskTimeEntryFormData>
 */
#[FormKitConfig('task_board')]
final class TaskTimeEntryFormType extends AbstractType
{
    use FormOptionsTrait;

    /** At most a week of work in one entry. */
    public const MAX_MINUTES = TaskConstraints::MAX_MINUTES;

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('minutes', IntegerType::class, [
                'label'       => 'task_board.time.form.minutes',
                'help'        => 'task_board.time.form.minutes_help',
                'constraints' => TaskConstraints::timeMinutes(),
                'attr'        => ['class' => 'form-control', 'min' => 1, 'max' => self::MAX_MINUTES, 'inputmode' => 'numeric'],
            ])
            ->add('loggedAt', DateType::class, [
                'label'           => 'task_board.time.form.date',
                'widget'          => 'single_text',
                'input'           => 'datetime_immutable',
                'constraints'     => TaskConstraints::timeLoggedAt(),
                'invalid_message' => 'task_board.form.task.due_invalid',
                'attr'            => ['class' => 'form-control'],
            ])
            ->add('note', TextType::class, [
                'label'       => 'task_board.time.form.note',
                'required'    => false,
                'constraints' => TaskConstraints::timeNote(),
                'attr'        => ['class' => 'form-control', 'maxlength' => 512],
            ])
            ->add('billable', CheckboxType::class, [
                'label'    => 'task_board.time.form.billable',
                'help'     => 'task_board.time.form.billable_help',
                'required' => false,
                'switch'   => true,
            ]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => TaskTimeEntryFormData::class,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'task_time_entry';
    }
}
