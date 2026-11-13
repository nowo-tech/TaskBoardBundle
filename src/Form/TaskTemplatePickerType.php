<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Since 2.16.0 (Beacon spec 234, FR-061): the *Template* picker of the add-task dialog. A GET form to the board page
 * (no CSRF: it changes nothing): `taskTemplate` is the template's id and `taskTitle` the title typed so far. The page
 * answers with the add-task form pre-filled from the template (description, tags, column), every field still editable,
 * and the dialog open. The `nowo-task-board-add-task-modal` controller submits it on change.
 *
 * @extends AbstractType<null>
 */
#[FormKitConfig('task_board')]
final class TaskTemplatePickerType extends AbstractType
{
    use FormOptionsTrait;

    public const FIELD_TEMPLATE = 'taskTemplate';

    public const FIELD_TITLE = 'taskTitle';

    private const STIMULUS = 'nowo-task-board-add-task-modal';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $choices name => id */
        $choices = $options['templates'];

        $this->addChoice($builder, self::FIELD_TEMPLATE, [
            'mapped'                    => false,
            'required'                  => false,
            'label'                     => 'task_board.template.picker',
            'placeholder'               => 'task_board.template.none',
            'choices'                   => $choices,
            'choice_translation_domain' => false,
            'data'                      => $options['selected'],
            'attr'                      => [
                'data-testid' => 'nowo-task-template-picker',
                'data-action' => 'change->' . self::STIMULUS . '#applyTemplate',
            ],
        ]);
        $this->addWithDefaults($builder, self::FIELD_TITLE, HiddenType::class, [
            'mapped' => false,
            'attr'   => ['data-' . self::STIMULUS . '-target' => 'templateTitle'],
        ]);
        // The board view itself: a user's default view (list, Gantt) would otherwise redirect and drop the template.
        $this->addWithDefaults($builder, 'view', HiddenType::class, ['mapped' => false, 'data' => 'board']);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'templates'          => [],
            'selected'           => null,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
        $resolver->setAllowedTypes('templates', 'array');
        $resolver->setAllowedTypes('selected', ['null', 'string']);
    }
}
