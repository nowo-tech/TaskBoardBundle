<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Dto\TaskTimeEntryFormData;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Since 1.15: the "Log time" dialog of the task detail — minutes spent, the day and an optional note.
 *
 * @extends AbstractType<TaskTimeEntryFormData>
 */
#[FormKitConfig('task_board')]
final class TaskTimeEntryFormType extends AbstractType
{
    use FormOptionsTrait;

    /** At most a week of work in one entry. */
    public const MAX_MINUTES = 10080;

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
                'constraints' => [new NotNull(), new Range(min: 1, max: self::MAX_MINUTES)],
                'attr'        => ['class' => 'form-control', 'min' => 1, 'max' => self::MAX_MINUTES, 'inputmode' => 'numeric'],
            ])
            ->add('loggedAt', DateType::class, [
                'label'           => 'task_board.time.form.date',
                'widget'          => 'single_text',
                'input'           => 'datetime_immutable',
                'constraints'     => [new NotNull()],
                'invalid_message' => 'task_board.form.task.due_invalid',
                'attr'            => ['class' => 'form-control'],
            ])
            ->add('note', TextType::class, [
                'label'       => 'task_board.time.form.note',
                'required'    => false,
                'constraints' => [new Length(max: 512)],
                'attr'        => ['class' => 'form-control', 'maxlength' => 512],
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
