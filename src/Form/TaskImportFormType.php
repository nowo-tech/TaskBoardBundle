<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\TaskBoardBundle\Dto\TaskImportFormData;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<TaskImportFormData>
 */
final class TaskImportFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('source', EnumType::class, [
                'class'        => TaskImportSource::class,
                'label'        => 'task_board.import.form.source',
                'choice_label' => static fn (TaskImportSource $source): string => $source->labelKey(),
            ])
            ->add('file', FileType::class, [
                'label'       => 'task_board.import.form.file',
                'constraints' => [
                    new NotBlank(),
                    new File(maxSize: '20M', mimeTypes: [
                        'text/csv',
                        'text/plain',
                        'application/json',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ], mimeTypesMessage: 'task_board.import.form.file_invalid'),
                ],
            ])
            ->add('createMissingColumns', CheckboxType::class, [
                'label'    => 'task_board.import.form.create_columns',
                'required' => false,
            ])
            ->add('skipExisting', CheckboxType::class, [
                'label'    => 'task_board.import.form.skip_existing',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => TaskImportFormData::class,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
