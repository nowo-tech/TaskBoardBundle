<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Since 2.3.0 (spec 217, FR-003): the choices of a move or a duplicate, sent with GET so that the preview below follows
 * them with no JavaScript: the target board, then (move) the column each used column goes to, or (duplicate) what the
 * copy carries. Nothing is written by this form; the confirmation posts its own token.
 *
 * @extends AbstractType<null>
 */
#[FormKitConfig('task_board')]
final class TaskCopySelectFormType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 'target', ChoiceType::class, [
            'label'                     => 'task_board.copy.target',
            'choices'                   => $options['board_choices'],
            'placeholder'               => $options['target_placeholder'],
            'required'                  => true,
            'data'                      => $options['target'],
            'choice_translation_domain' => false,
        ]);
        /** @var list<array{id: string, label: string, choices: array<string, string>, data: string|null}> $columns */
        $columns = $options['columns'];
        foreach ($columns as $column) {
            $this->addWithDefaults($builder, 'c' . $column['id'], ChoiceType::class, [
                'label'                     => $column['label'],
                'translation_domain'        => false,
                'choices'                   => $column['choices'],
                'data'                      => $column['data'],
                'required'                  => true,
                'choice_translation_domain' => false,
            ]);
        }
        if ($options['copy_fields']) {
            foreach (['subtasks', 'dates', 'members'] as $name) {
                $this->addWithDefaults($builder, $name, CheckboxType::class, [
                    'label'    => 'task_board.copy.option.' . $name,
                    'required' => false,
                    'data'     => $options['copy_data'][$name] ?? true,
                ]);
            }
        }
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'board_choices'      => [],
            'target'             => null,
            'target_placeholder' => null,
            'columns'            => [],
            'copy_fields'        => false,
            'copy_data'          => [],
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
        $resolver->setAllowedTypes('board_choices', 'array');
        $resolver->setAllowedTypes('columns', 'array');
        $resolver->setAllowedTypes('copy_fields', 'bool');
        $resolver->setAllowedTypes('copy_data', 'array');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'copy';
    }
}
