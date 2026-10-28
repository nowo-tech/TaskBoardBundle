<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Dto\ColumnSetApplyFormData;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * "Add columns from set" (since 1.11): the column set whose columns are appended to the board.
 *
 * @extends AbstractType<ColumnSetApplyFormData>
 */
#[FormKitConfig('task_board')]
final class ColumnSetApplyFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 'columnSetId', ChoiceType::class, [
            'label'                     => 'task_board.form.column_set.set',
            'choices'                   => $options['column_set_choices'],
            'choice_translation_domain' => false,
            'constraints'               => [new NotBlank()],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ColumnSetApplyFormData::class,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            // Column set name => id.
            'column_set_choices' => [],
        ]);
        $resolver->setAllowedTypes('column_set_choices', 'array');
    }
}
