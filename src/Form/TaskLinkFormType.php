<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<TaskLinkFormData>
 */
#[FormKitConfig('task_board')]
final class TaskLinkFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 'linkType', EnumType::class, [
            'class'   => TaskLinkType::class,
            'choices' => TaskLinkType::userChoices(),
            'label'   => 'task_board.form.link.type',
            // Since 1.21: a submission without the field is a plain URL link (no TypeError on the DTO).
            'empty_data' => TaskLinkType::Url->value,
        ]);
        $this->addUrl($builder, 'url', [
            // Since 1.21: an empty or missing URL is a NotBlank error, not a TypeError on the DTO (string).
            'empty_data'  => '',
            'label'       => 'task_board.form.link.url',
            'constraints' => TaskConstraints::linkUrl(),
            'attr'        => ['maxlength' => 2048],
        ]);
        $this->addText($builder, 'label', [
            'label'       => 'task_board.form.link.label',
            'required'    => false,
            'constraints' => TaskConstraints::linkLabel(),
            'attr'        => ['maxlength' => 255],
        ]);
        $this->addText($builder, 'externalId', [
            'label'       => 'task_board.form.link.external_id',
            'required'    => false,
            'constraints' => TaskConstraints::linkExternalId(),
            'attr'        => ['maxlength' => 128],
            'help'        => 'task_board.form.link.external_id_help',
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
