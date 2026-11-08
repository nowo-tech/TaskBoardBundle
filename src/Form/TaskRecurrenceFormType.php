<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use DateTimeImmutable;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Dto\TaskRecurrenceFormData;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceManager;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceRule;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function array_combine;
use function array_map;
use function range;

/**
 * Since 2.3.0 (spec 215, FR-018): mode, pattern and end, and (folded) what each occurrence copies.
 *
 * @extends AbstractType<TaskRecurrenceFormData>
 */
#[FormKitConfig('task_board')]
final class TaskRecurrenceFormType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'task_board_task_recurrence';

    /**
     * @param FormBuilderInterface<TaskRecurrenceFormData|null> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $days = range(1, 31);
        $this->addWithDefaults($builder, 'mode', ChoiceType::class, [
            'label'       => 'task_board.recurrence.form.mode',
            'choices'     => ['task_board.recurrence.mode.fixed' => TaskRecurrenceRule::MODE_FIXED, 'task_board.recurrence.mode.after_completion' => TaskRecurrenceRule::MODE_AFTER_COMPLETION],
            'constraints' => [new Assert\Choice(choices: [TaskRecurrenceRule::MODE_FIXED, TaskRecurrenceRule::MODE_AFTER_COMPLETION])],
        ]);
        $this->addWithDefaults($builder, 'interval', IntegerType::class, [
            'label'       => 'task_board.recurrence.form.interval',
            'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 365)],
        ]);
        $this->addWithDefaults($builder, 'unit', ChoiceType::class, [
            'label'   => 'task_board.recurrence.form.unit',
            'choices' => ['task_board.recurrence.form.unit_day' => 'day', 'task_board.recurrence.form.unit_week' => 'week', 'task_board.recurrence.form.unit_month' => 'month', 'task_board.recurrence.form.unit_year' => 'year'],
        ]);
        $this->addWithDefaults($builder, 'weekdays', ChoiceType::class, [
            'label'    => 'task_board.recurrence.form.weekdays',
            'choices'  => array_combine(array_map(static fn (int $day): string => 'task_board.recurrence.weekday.' . $day, range(1, 7)), range(1, 7)),
            'multiple' => true,
            'expanded' => true,
            'required' => false,
        ]);
        $this->addWithDefaults($builder, 'monthDay', ChoiceType::class, [
            'label'                     => 'task_board.recurrence.form.month_day',
            'choices'                   => ['task_board.recurrence.last_day' => TaskRecurrenceRule::LAST_DAY] + array_combine(array_map(strval(...), $days), $days),
            'required'                  => false,
            'placeholder'               => 'task_board.recurrence.form.same_day',
            'choice_translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
        $this->addWithDefaults($builder, 'nextOnCompletion', CheckboxType::class, [
            'label'    => 'task_board.recurrence.next_on_completion',
            'required' => false,
        ]);
        $this->addWithDefaults($builder, 'end', ChoiceType::class, [
            'label'   => 'task_board.recurrence.form.end',
            'choices' => ['task_board.recurrence.form.end_never' => TaskRecurrenceFormData::END_NEVER, 'task_board.recurrence.form.end_date' => TaskRecurrenceFormData::END_DATE, 'task_board.recurrence.form.end_count' => TaskRecurrenceFormData::END_COUNT],
        ]);
        $this->addWithDefaults($builder, 'endDate', DateType::class, [
            'label'    => 'task_board.recurrence.form.end_date_value',
            'widget'   => 'single_text',
            'input'    => 'datetime_immutable',
            'required' => false,
        ]);
        $this->addWithDefaults($builder, 'endCount', IntegerType::class, [
            'label'       => 'task_board.recurrence.form.end_count_value',
            'required'    => false,
            'constraints' => [new Assert\Range(min: 2, max: 1000)],
        ]);
        $this->addWithDefaults($builder, 'leadDays', IntegerType::class, [
            'label'       => 'task_board.recurrence.form.lead_days',
            'help'        => 'task_board.recurrence.form.lead_days_help',
            'constraints' => [new Assert\NotNull(), new Assert\Range(min: 0, max: TaskRecurrenceManager::MAX_LEAD_DAYS)],
        ]);
        $this->addWithDefaults($builder, 'copySubtasks', CheckboxType::class, ['label' => 'task_board.recurrence.form.copy_subtasks', 'required' => false]);
        $this->addWithDefaults($builder, 'copyMembers', CheckboxType::class, ['label' => 'task_board.recurrence.form.copy_members', 'required' => false]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => TaskRecurrenceFormData::class,
            'csrf_protection'    => true,
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'constraints'        => [new Assert\Callback(static function (TaskRecurrenceFormData $data, ExecutionContextInterface $context): void {
                if ($data->end === TaskRecurrenceFormData::END_DATE && !$data->endDate instanceof DateTimeImmutable) {
                    $context->buildViolation('task_board.recurrence.form.end_date_required')->setTranslationDomain(TaskBoardBundle::TRANSLATION_DOMAIN)->atPath('endDate')->addViolation();
                }
                if ($data->end === TaskRecurrenceFormData::END_COUNT && $data->endCount === null) {
                    $context->buildViolation('task_board.recurrence.form.end_count_required')->setTranslationDomain(TaskBoardBundle::TRANSLATION_DOMAIN)->atPath('endCount')->addViolation();
                }
            })],
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'task_recurrence';
    }
}
