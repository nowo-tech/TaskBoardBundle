<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

use function strpos;
use function substr;

/**
 * @extends AbstractType<BoardColumnFormData>
 */
#[FormKitConfig('task_board')]
final class BoardColumnFormType extends AbstractType
{
    use FormOptionsTrait;

    public const COLOR_PATTERN = '/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/D';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', [
            'label'       => 'task_board.form.column.name',
            'constraints' => [new NotBlank(), new Length(max: 128)],
            'attr'        => ['maxlength' => 128],
        ]);
        $this->addWithDefaults($builder, 'color', ColorType::class, [
            'label'    => 'task_board.form.column.color',
            'required' => false,
            // Rendered inside style="…": hex colours only (#rgb, #rgba, #rrggbb, #rrggbbaa).
            'constraints' => [new Regex(pattern: self::COLOR_PATTERN)],
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
