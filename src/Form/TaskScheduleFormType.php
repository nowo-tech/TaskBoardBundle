<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Since 2.1.0 (spec 214, FR-031): the fields of the Reschedule dialog. Only drawn: it posts `start`, `due` and `_token` to
 * `task_schedule`, the same request a drag sends, and that route validates them.
 *
 * @extends AbstractType<null>
 */
#[FormKitConfig('task_board')]
final class TaskScheduleFormType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'task_board_task_schedule';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['start' => ['task_board.form.task.start', 'startField'], 'due' => ['task_board.form.task.due', 'dueField']] as $name => [$label, $target]) {
            $this->addWithDefaults($builder, $name, DateType::class, [
                'mapped'   => false,
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'label'    => $label,
                'attr'     => ['data-nowo-task-board-calendar-target' => $target],
            ]);
        }
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
