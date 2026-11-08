<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function strpos;
use function substr;

/**
 * @extends AbstractType<BoardColumnFormData>
 */
#[FormKitConfig('task_board')]
final class BoardColumnFormType extends AbstractType
{
    use FormOptionsTrait;

    public const COLOR_PATTERN = TaskConstraints::COLOR_PATTERN;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', [
            // Since 1.21: an empty or missing name is a NotBlank error, not a TypeError on the DTO (string).
            'empty_data'  => '',
            'label'       => 'task_board.form.column.name',
            'constraints' => TaskConstraints::columnName(),
            'attr'        => ['maxlength' => 128],
        ]);
        $this->addWithDefaults($builder, 'color', ColorType::class, [
            'label'       => 'task_board.form.column.color',
            'required'    => false,
            'constraints' => TaskConstraints::columnColor(),
        ]);

        // Since 1.11: an icon picked from `board.column_icons` (no field when the list is empty).
        $icons = $options['icon_choices'];
        if ($icons !== []) {
            $choices = [];
            foreach ($icons as $icon) {
                $choices[substr($icon, (int) strpos($icon, ':') + 1)] = $icon;
            }

            $this->addWithDefaults($builder, 'icon', ChoiceType::class, [
                'label'                     => 'task_board.form.column.icon',
                'required'                  => false,
                'choices'                   => $choices,
                'choice_translation_domain' => false,
                'placeholder'               => 'task_board.form.column.no_icon',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => BoardColumnFormData::class,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'icon_choices'       => [],
        ]);
        $resolver->setAllowedTypes('icon_choices', 'string[]');
    }
}
