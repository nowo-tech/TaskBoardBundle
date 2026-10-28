<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<TaskBoardFormData>
 */
#[FormKitConfig('task_board')]
final class TaskBoardFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', [
            'label'       => 'task_board.form.board.name',
            'constraints' => [new NotBlank(), new Length(max: 255)],
            'attr'        => ['maxlength' => 255],
        ]);
        $this->addText($builder, 'slug', [
            'label'    => 'task_board.form.board.slug',
            'required' => false,
            // Since 1.14.1: an empty slug is '' (the board creator derives it from the name), not null.
            'empty_data'  => '',
            'help'        => 'task_board.form.board.slug_help',
            'constraints' => [new Length(max: 255)],
            'attr'        => ['maxlength' => 255],
        ]);
        $this->addTextarea($builder, 'description', [
            'label'    => 'task_board.form.board.description',
            'required' => false,
        ]);

        // Since 1.11: start from a column set instead of board.default_columns (no field without sets).
        if ($options['column_set_choices'] !== []) {
            $this->addWithDefaults($builder, 'columnSetId', ChoiceType::class, [
                'label'                     => 'task_board.form.board.column_set',
                'required'                  => false,
                'choices'                   => $options['column_set_choices'],
                'choice_translation_domain' => false,
                'placeholder'               => 'task_board.form.board.default_columns',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => TaskBoardFormData::class,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            // Since 1.11: column set name => id.
            'column_set_choices' => [],
        ]);
        $resolver->setAllowedTypes('column_set_choices', 'array');
    }
}
