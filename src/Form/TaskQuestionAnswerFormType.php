<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Dto\TaskQuestionAnswerFormData;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionAnswer;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

use function assert;

/**
 * Since 2.23.0 (Beacon spec 242): the answer to a question (option `question`) — radios for a single-choice question,
 * checkboxes for a multiple-choice one, the *Other* text when allowed, and the version read (hidden: a racing answer is
 * refused, never overwritten).
 *
 * @extends AbstractType<TaskQuestionAnswerFormData>
 */
#[FormKitConfig('task_board')]
final class TaskQuestionAnswerFormType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $question = $options['question'];
        assert($question instanceof TaskQuestion);
        $choices = [];
        foreach ($question->getOptions() as $option) {
            $choices[$option->getLabel()] = $option->getId();
        }
        if ($choices !== []) {
            $builder->add('options', ChoiceType::class, [
                'label'                     => 'task_board.question.answer.options',
                'label_attr'                => ['class' => 'nowo-task-sr-only'],
                'expanded'                  => true,
                'multiple'                  => $question->isMultiple(),
                'required'                  => false,
                'placeholder'               => false,
                'choices'                   => $choices,
                'choice_translation_domain' => false,
            ]);
        }
        if ($question->allowsOther()) {
            $builder->add('otherText', TextareaType::class, [
                'label'       => $choices === [] ? 'task_board.question.answer.text' : 'task_board.question.answer.other',
                'required'    => false,
                'constraints' => [new Length(max: TaskQuestionAnswer::OTHER_MAX)],
                'attr'        => ['rows' => 2, 'maxlength' => TaskQuestionAnswer::OTHER_MAX, 'class' => 'form-control'],
            ]);
        }
        $builder->add('version', HiddenType::class);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => TaskQuestionAnswerFormData::class,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
        $resolver->setRequired('question');
        $resolver->setAllowedTypes('question', TaskQuestion::class);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'task_question_answer';
    }
}
