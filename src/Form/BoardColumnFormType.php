<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Support\ColumnIcon;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function in_array;
use function is_string;
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
        // Since 2.9.0: `board.column_icon_form_type` (e.g. an emoji picker) replaces the grid; the bundle's constraint always applies.
        $icons = $options['icon_choices'];
        if ($options['icon_form_type'] !== null) {
            $this->addWithDefaults($builder, 'icon', $options['icon_form_type'], [
                'label'       => 'task_board.form.column.icon',
                'help'        => 'task_board.form.column.icon_help',
                'required'    => false,
                'empty_data'  => null,
                'constraints' => TaskConstraints::columnIcon($options['icon_known_ids'] ?? $icons, $options['icon_invalid_message']),
            ] + $options['icon_form_options']);
        } elseif ($icons !== []) {
            $choices = [];
            foreach ($icons as $icon) {
                $choices[substr($icon, (int) strpos($icon, ':') + 1)] = $icon;
            }
            // Since 2.9.0 (audit JM-L1): the column's own icon when the grid does not offer it (an emoji of the default columns,
            // the fill command or the API), so an edit can post it back.
            $current = $options['icon_current'];
            if (is_string($current) && $current !== '' && !in_array($current, $icons, true) && ColumnIcon::isStorable($current)) {
                $choices[$current] = $current;
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
            // Since 2.9.0: `board.column_icon_form_type` / `_options`; the ids a value may name besides an emoji (null: icon_choices).
            'icon_form_type'    => null,
            'icon_form_options' => [],
            'icon_known_ids'    => null,
            // Since 2.9.0 (audit JM-L1): the edited column's icon, a valid choice of the grid even when not offered.
            'icon_current'         => null,
            'icon_invalid_message' => 'A column icon is one emoji or one of the offered icons.',
        ]);
        $resolver->setAllowedTypes('icon_choices', 'string[]');
        $resolver->setAllowedTypes('icon_form_type', ['null', 'string']);
        $resolver->setAllowedTypes('icon_form_options', 'array');
        $resolver->setAllowedTypes('icon_known_ids', ['null', 'string[]']);
        $resolver->setAllowedTypes('icon_current', ['null', 'string']);
        $resolver->setAllowedTypes('icon_invalid_message', 'string');
    }
}
