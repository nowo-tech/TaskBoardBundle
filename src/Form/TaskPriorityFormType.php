<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Priority picker of the task priority modal (flat `priority` radio + `_token`) — REQ-TWIG-005.
 *
 * @extends AbstractType<array{priority?: string|null}>
 */
#[FormKitConfig('task_board')]
final class TaskPriorityFormType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'task_board_task_priority';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach (TaskPriority::cases() as $priority) {
            $choices[$priority->value] = $priority->value;
        }

        $builder->add('priority', ChoiceType::class, [
            'choices'                   => $choices,
            'expanded'                  => true,
            'label'                     => false,
            'choice_translation_domain' => false,
        ]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'          => 'POST',
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id'   => self::CSRF_TOKEN_ID,
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
