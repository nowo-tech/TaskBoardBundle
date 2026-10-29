<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

use function is_array;

/**
 * @extends AbstractType<TaskMemberFormData>
 */
#[FormKitConfig('task_board')]
final class TaskMemberFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $userOptions = [
            'class' => $options['user_class'],
            // Since 1.16.1 the person's name (e-mail after it), unless the host names a property or a callable.
            'choice_label' => $options['user_choice_label'] ?? UserLabel::option(...),
            'label'        => 'task_board.form.member.user',
            'constraints'  => [new NotBlank()],
        ];
        // Since 1.10: the assignee provider's users only (TaskAssigneeProviderInterface).
        if (is_array($options['user_choices'])) {
            $userOptions['choices'] = $options['user_choices'];
        }
        $this->addWithDefaults($builder, 'user', EntityType::class, $userOptions);
        $this->addWithDefaults($builder, 'memberRole', EnumType::class, [
            'class' => TaskMemberRole::class,
            'label' => 'task_board.form.member.role',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['user_class']);
        $resolver->setDefaults([
            'data_class'         => TaskMemberFormData::class,
            'user_choice_label'  => null,
            'user_choices'       => null,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
        // After setDefaults(): an option must exist before its allowed types are set.
        $resolver->setAllowedTypes('user_choices', ['null', 'array']);
    }
}
