<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<BoardColumnFormData>
 */
final class BoardColumnFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'       => 'task_board.form.column.name',
                'constraints' => [new NotBlank()],
            ])
            ->add('color', ColorType::class, [
                'label'    => 'task_board.form.column.color',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => BoardColumnFormData::class,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
