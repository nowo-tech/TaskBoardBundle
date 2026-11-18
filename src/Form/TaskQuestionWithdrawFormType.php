<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Dto\TaskQuestionWithdrawFormData;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Since 2.23.0 (Beacon spec 242): *Withdraw* a question — the reason (required) and the version read.
 *
 * @extends AbstractType<TaskQuestionWithdrawFormData>
 */
#[FormKitConfig('task_board')]
final class TaskQuestionWithdrawFormType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reason', TextType::class, [
                'label'       => 'task_board.question.withdraw.reason',
                'empty_data'  => '',
                'constraints' => [new NotBlank(), new Length(max: TaskQuestion::REASON_MAX)],
                'attr'        => ['maxlength' => TaskQuestion::REASON_MAX, 'class' => 'form-control'],
            ])
            ->add('version', HiddenType::class);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => TaskQuestionWithdrawFormData::class,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'task_question_withdraw';
    }
}
