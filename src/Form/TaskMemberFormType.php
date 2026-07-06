<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<TaskMemberFormData>
 */
final class TaskMemberFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('user', EntityType::class, [
                'class'        => $options['user_class'],
                'choice_label' => $options['user_choice_label'],
                'label'        => 'task_board.form.member.user',
                'constraints'  => [new NotBlank()],
            ])
            ->add('memberRole', EnumType::class, [
                'class' => TaskMemberRole::class,
                'label' => 'task_board.form.member.role',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['user_class']);
        $resolver->setDefaults([
            'data_class'         => TaskMemberFormData::class,
            'user_choice_label'  => 'email',
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
