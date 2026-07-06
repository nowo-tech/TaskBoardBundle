<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<TaskLinkFormData>
 */
final class TaskLinkFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('linkType', EnumType::class, [
                'class' => TaskLinkType::class,
                'label' => 'task_board.form.link.type',
            ])
            ->add('url', UrlType::class, [
                'label'       => 'task_board.form.link.url',
                'constraints' => [new NotBlank()],
            ])
            ->add('label', TextType::class, [
                'label'    => 'task_board.form.link.label',
                'required' => false,
            ])
            ->add('externalId', TextType::class, [
                'label'    => 'task_board.form.link.external_id',
                'required' => false,
                'help'     => 'task_board.form.link.external_id_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => TaskLinkFormData::class,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
