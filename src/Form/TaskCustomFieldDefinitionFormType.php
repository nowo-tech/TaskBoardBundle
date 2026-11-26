<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

use function is_string;
use function preg_match;
use function preg_split;
use function trim;

/**
 * Since 3.3.0: a board's native custom field — name, type (only when it is created: a field keeps its type) and, for a
 * dropdown, its options. An existing option is a row of its own (`o_<n>` name, `c_<n>` colour, `r_<n>` remove; the
 * version check makes the row order safe), so a renamed option stays the same option and keeps the tasks' values; new options are typed one per line
 * (`Name` or `Name #rrggbb`). The edit form carries the definition's `version` (REQ-RUNTIME-003: a definition changed
 * since the page was shown is refused).
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('task_board')]
final class TaskCustomFieldDefinitionFormType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'task_board_custom_field';

    /** The longest text of the new options box. */
    public const NEW_OPTIONS_MAX = 20000;

    /** A colour as the field stores it. */
    private const COLOR = '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var TaskCustomField|null $field */
        $field = $options['field'];
        $this->addWithDefaults($builder, 'name', TextType::class, [
            'label'       => 'task_board.custom_field.form.name',
            'data'        => $field?->getName(),
            'attr'        => ['maxlength' => TaskCustomField::NAME_MAX, 'autocomplete' => 'off'],
            'constraints' => [new Assert\NotBlank(), new Assert\Length(max: TaskCustomField::NAME_MAX)],
        ]);
        if (!$field instanceof TaskCustomField) {
            $this->addWithDefaults($builder, 'type', ChoiceType::class, [
                'label'   => 'task_board.custom_field.form.type',
                'choices' => [
                    'task_board.custom_field.type.text'     => CustomFieldType::Text->value,
                    'task_board.custom_field.type.number'   => CustomFieldType::Number->value,
                    'task_board.custom_field.type.dropdown' => CustomFieldType::Dropdown->value,
                ],
                'data'        => CustomFieldType::Text->value,
                'expanded'    => true,
                'constraints' => [new Assert\NotBlank()],
            ]);
        } else {
            $builder->add('version', HiddenType::class, ['data' => (string) $field->getVersion()]);
        }
        foreach ($field?->getOptions() ?? [] as $index => $option) {
            $this->addWithDefaults($builder, 'o_' . $index, TextType::class, [
                'label'                        => 'task_board.custom_field.form.option_name',
                'label_translation_parameters' => ['%n%' => $index + 1],
                'data'                         => $option['name'],
                'attr'                         => ['maxlength' => TaskCustomField::NAME_MAX],
                'constraints'                  => [new Assert\NotBlank(), new Assert\Length(max: TaskCustomField::NAME_MAX)],
            ]);
            $this->addWithDefaults($builder, 'c_' . $index, TextType::class, [
                'label'       => 'task_board.custom_field.form.option_color',
                'data'        => $option['color'],
                'required'    => false,
                'attr'        => ['maxlength' => 7, 'placeholder' => '#rrggbb', 'pattern' => '#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})', 'autocomplete' => 'off'],
                'constraints' => [new Assert\Regex(pattern: self::COLOR, message: 'task_board.custom_field.form.color_invalid')],
            ]);
            $this->addCheckbox($builder, 'r_' . $index, [
                'label'    => 'task_board.custom_field.form.option_remove',
                'required' => false,
            ]);
        }
        if (!$field instanceof TaskCustomField || $field->getType() === CustomFieldType::Dropdown) {
            $this->addWithDefaults($builder, 'newOptions', TextareaType::class, [
                'label'       => $field instanceof TaskCustomField ? 'task_board.custom_field.form.add_options' : 'task_board.custom_field.form.options',
                'help'        => 'task_board.custom_field.form.options_help',
                'required'    => false,
                'attr'        => ['rows' => 4, 'maxlength' => self::NEW_OPTIONS_MAX],
                'constraints' => [new Assert\Length(max: self::NEW_OPTIONS_MAX)],
            ]);
        }
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'field'              => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
        ]);
        $resolver->setAllowedTypes('field', ['null', TaskCustomField::class]);
    }

    /**
     * The options the form describes, in order: the kept existing ones (by id, renamed and recoloured), then the new
     * lines (`Name` or `Name #rrggbb`; blank lines skipped). The manager validates them (unique names, at most
     * {@see TaskCustomField::OPTIONS_MAX}).
     *
     * @param array<string, mixed> $data the submitted form's data
     *
     * @return list<array{id?: string, name: string, color: ?string}>
     */
    public static function options(array $data, ?TaskCustomField $field): array
    {
        $options = [];
        foreach ($field?->getOptions() ?? [] as $index => $option) {
            if (($data['r_' . $index] ?? false) === true) {
                continue;
            }
            $color     = $data['c_' . $index] ?? null;
            $options[] = ['id' => $option['id'], 'name' => is_string($data['o_' . $index] ?? null) ? $data['o_' . $index] : $option['name'], 'color' => is_string($color) && $color !== '' ? $color : null];
        }
        foreach (preg_split('/\R/u', is_string($data['newOptions'] ?? null) ? $data['newOptions'] : '') ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $color = null;
            if (preg_match('/^(.*\S)\s+(#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}))$/uD', $line, $match) === 1) {
                [$line, $color] = [$match[1], $match[2]];
            }
            $options[] = ['name' => $line, 'color' => $color];
        }

        return $options;
    }

    public function getBlockPrefix(): string
    {
        return 'task_board_custom_field';
    }
}
