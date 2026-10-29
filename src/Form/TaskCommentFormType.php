<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\TaskBoardBundle\Dto\TaskCommentFormData;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Since 1.16: a task comment — the text (the `nowo-task-board-mention` controller offers @mentions while typing) and
 * the hidden list of the people picked.
 *
 * @extends AbstractType<TaskCommentFormData>
 */
final class TaskCommentFormType extends AbstractType
{
    private const MENTION = 'nowo-task-board-mention';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('body', TextareaType::class, [
                'label'       => 'task_board.comment.body',
                'label_attr'  => ['class' => 'nowo-task-sr-only'],
                'empty_data'  => '',
                'constraints' => [new NotBlank(), new Length(max: TaskComment::MAX_LENGTH)],
                'attr'        => [
                    'class'                             => 'form-control nowo-task-comment__input',
                    'rows'                              => 3,
                    'maxlength'                         => TaskComment::MAX_LENGTH,
                    'placeholder'                       => 'task_board.comment.placeholder',
                    'data-' . self::MENTION . '-target' => 'input',
                    'data-action'                       => 'input->' . self::MENTION . '#update keydown->' . self::MENTION . '#keydown click->' . self::MENTION . '#update blur->' . self::MENTION . '#blur',
                ],
            ])
            ->add('mentions', HiddenType::class, [
                'empty_data' => '[]',
                'attr'       => ['data-' . self::MENTION . '-target' => 'mentions'],
            ]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => TaskCommentFormData::class,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'task_comment';
    }
}
