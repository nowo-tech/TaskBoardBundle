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
 * Since 2.24.0: the field of the Move event dialog (the route that needs no pointer). Only drawn: the calendar posts `date`
 * and `_token`, with the event's source, id and expected values, to `calendar_event_reschedule`, the request a drag sends,
 * and that route validates them.
 *
 * @extends AbstractType<null>
 */
#[FormKitConfig('task_board')]
final class CalendarEventMoveFormType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'task_board_calendar_event_reschedule';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 'date', DateType::class, [
            'mapped'   => false,
            'required' => true,
            'widget'   => 'single_text',
            'input'    => 'datetime_immutable',
            'label'    => 'task_board.calendar.event_move_date',
            'attr'     => ['data-nowo-task-board-calendar-target' => 'eventDateField'],
        ]);
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
