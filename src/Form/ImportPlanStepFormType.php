<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanLayout;
use Nowo\TaskBoardBundle\Sync\Plan\ReimportMode;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

use function array_keys;
use function array_map;
use function strtr;

/**
 * Since 3.2.0 (CU-2): one step of the guided import (design §8) — its own CSRF token id
 * (`task_board_import_plan_<step>`) and the plan's `version` (a stale step is refused by the service). Only the offered
 * values are accepted (a forged id is a form error). Fields by `step`:
 *
 * - `connect`: `connection` (radio); `workspace`: `workspace` (radio);
 * - `tree`: `containers` (checkboxes of the opened levels), `opened` (the opened levels), `layout` and the import
 *   options (`comments`, `attachments`, `checklists`, `timeEntries` when the host has a time sink, `deleteUnusedColumns`);
 * - `boards`: per item `t_<item id>` (*New board* or an existing board) and `n_<item id>` (the new board's name);
 * - `statuses`: per table and status `s_<table key>_<n>` (a column, `__create` or `__skip`);
 * - `fields`: `imported` (checkboxes, all checked by default); `people`: `peopleFirst`;
 * - `preview` (and *Re-import now*): `mode` (update changed / add new only).
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('task_board')]
final class ImportPlanStepFormType extends AbstractType
{
    use FormOptionsTrait;

    public const NAME = 'task_board_import_plan';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('version', HiddenType::class, ['data' => (string) $options['version']]);
        switch ($options['step']) {
            case 'connect':
                $this->radios($builder, 'connection', 'task_board.import_plan.connect.legend', $options['choices'], $options['selected']);
                break;
            case 'workspace':
                $this->radios($builder, 'workspace', 'task_board.import_plan.workspace.legend', $options['choices'], $options['selected']);
                break;
            case 'tree':
                $this->tree($builder, $options);
                break;
            case 'boards':
                $this->boards($builder, $options['items']);
                break;
            case 'statuses':
                $this->statuses($builder, $options['groups']);
                break;
            case 'fields':
                $choices = $options['choices'];
                $this->addWithDefaults($builder, 'imported', ChoiceType::class, [
                    'label'                     => 'task_board.import_plan.fields.legend',
                    'choices'                   => array_map(strval(...), array_keys($choices)),
                    'choice_label'              => static fn (string $value): string => $choices[$value],
                    'choice_translation_domain' => false,
                    'multiple'                  => true,
                    'expanded'                  => true,
                    'required'                  => false,
                    'data'                      => $options['selected'] ?? array_map(strval(...), array_keys($choices)),
                ]);
                break;
            case 'people':
                $this->addCheckbox($builder, 'peopleFirst', [
                    'label'    => 'task_board.import_plan.people.first',
                    'required' => false,
                    'data'     => $options['people_first'],
                ]);
                break;
            default:
                $this->addWithDefaults($builder, 'mode', ChoiceType::class, [
                    'label'   => 'task_board.import_plan.mode.legend',
                    'choices' => [
                        'task_board.import_plan.mode.update'  => ReimportMode::UpdateChanged->value,
                        'task_board.import_plan.mode.add_new' => ReimportMode::AddNewOnly->value,
                    ],
                    'expanded' => true,
                    'data'     => $options['mode'],
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'version'            => 1,
            'choices'            => [],
            'selected'           => null,
            'items'              => [],
            'groups'             => [],
            'plan_options'       => [],
            'time_option'        => false,
            'layout'             => ImportPlanLayout::PerList->value,
            'opened'             => '',
            'people_first'       => false,
            'mode'               => ReimportMode::UpdateChanged->value,
        ]);
        $resolver->setRequired('step');
        $resolver->setAllowedValues('step', ['connect', 'workspace', 'tree', 'boards', 'statuses', 'fields', 'people', 'preview']);
        $resolver->setDefault('csrf_token_id', static fn (Options $options): string => 'task_board_import_plan_' . $options['step']);
        $resolver->setAllowedTypes('version', 'int');
        $resolver->setAllowedTypes('choices', 'array');
        $resolver->setAllowedTypes('items', 'array');
        $resolver->setAllowedTypes('groups', 'array');
        $resolver->setAllowedTypes('plan_options', 'array');
        $resolver->setAllowedTypes('time_option', 'bool');
        $resolver->setAllowedTypes('people_first', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return self::NAME;
    }

    /** The name of a tree node's checkbox (`c_<id>`, `:` as `_`): the template finds it by the node's id. */
    public static function nodeName(string $containerId): string
    {
        return 'c_' . strtr($containerId, ':', '_');
    }

    /**
     * @param array<string, string> $choices value => label (provider names: not translated)
     * @param FormBuilderInterface<mixed> $builder
     */
    private function radios(FormBuilderInterface $builder, string $name, string $label, array $choices, mixed $selected): void
    {
        $this->addWithDefaults($builder, $name, ChoiceType::class, [
            'label'                     => $label,
            'choices'                   => array_map(strval(...), array_keys($choices)),
            'choice_label'              => static fn (string $value): string => $choices[$value],
            'choice_translation_domain' => false,
            'expanded'                  => true,
            'data'                      => $selected,
            'constraints'               => [new NotBlank(message: 'task_board.import_plan.error.pick_one')],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @param FormBuilderInterface<mixed> $builder
     */
    private function tree(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $choices */
        $choices = $options['choices'];
        $this->addWithDefaults($builder, 'containers', ChoiceType::class, [
            'label'                     => 'task_board.import_plan.tree.legend',
            'choices'                   => array_map(strval(...), array_keys($choices)),
            'choice_label'              => static fn (string $value): string => $choices[$value],
            'choice_name'               => self::nodeName(...),
            'choice_translation_domain' => false,
            'multiple'                  => true,
            'expanded'                  => true,
            'required'                  => false,
            'data'                      => $options['selected'] ?? [],
        ]);
        $builder->add('opened', HiddenType::class, ['data' => $options['opened'], 'constraints' => [new Length(max: 2000)]]);
        $this->addWithDefaults($builder, 'layout', ChoiceType::class, [
            'label'   => 'task_board.import_plan.layout.legend',
            'choices' => [
                'task_board.import_plan.layout.per_list'   => ImportPlanLayout::PerList->value,
                'task_board.import_plan.layout.per_folder' => ImportPlanLayout::PerFolder->value,
                'task_board.import_plan.layout.single'     => ImportPlanLayout::Single->value,
            ],
            'expanded' => true,
            'data'     => $options['layout'],
        ]);
        /** @var array<string, bool> $values */
        $values = $options['plan_options'];
        $names  = [TaskSyncImportPlan::OPTION_COMMENTS => true, TaskSyncImportPlan::OPTION_ATTACHMENTS => true, TaskSyncImportPlan::OPTION_CHECKLISTS => true];
        if ($options['time_option'] === true) {
            $names[TaskSyncImportPlan::OPTION_TIME] = false;
        }
        $names[TaskSyncImportPlan::OPTION_UNMATCHED] = false;
        foreach ($names as $name => $default) {
            $this->addCheckbox($builder, $name, [
                'label'    => 'task_board.import_plan.option.' . $name,
                'required' => false,
                'data'     => $values[$name] ?? $default,
            ]);
        }
    }

    /**
     * @param list<array{id: string, label: string, name: string, target: string, choices: array<string, string>}> $items
     * @param FormBuilderInterface<mixed> $builder
     */
    private function boards(FormBuilderInterface $builder, array $items): void
    {
        foreach ($items as $item) {
            $choices = $item['choices'];
            $this->addWithDefaults($builder, 't_' . $item['id'], ChoiceType::class, [
                'label'                     => 'task_board.import_plan.boards.target',
                'choices'                   => array_map(strval(...), array_keys($choices)),
                'choice_label'              => static fn (string $value): string => $choices[$value],
                'choice_translation_domain' => false,
                'data'                      => $item['target'],
                'placeholder'               => false,
            ]);
            $this->addWithDefaults($builder, 'n_' . $item['id'], TextType::class, [
                'label'       => 'task_board.import_plan.boards.name',
                'data'        => $item['name'],
                'required'    => false,
                'constraints' => [new Length(max: 255)],
            ]);
        }
    }

    /**
     * @param list<array{key: string, statuses: list<string>, columns: array<string, string>, selected: list<string>}> $groups
     * @param FormBuilderInterface<mixed> $builder
     */
    private function statuses(FormBuilderInterface $builder, array $groups): void
    {
        foreach ($groups as $group) {
            $columns = $group['columns'];
            foreach ($group['statuses'] as $index => $status) {
                $this->addWithDefaults($builder, 's_' . $group['key'] . '_' . $index, ChoiceType::class, [
                    'label'                     => $status,
                    'translation_domain'        => false,
                    'choices'                   => array_map(strval(...), array_keys($columns)),
                    'choice_label'              => static fn (string $value): string => $columns[$value],
                    'choice_translation_domain' => false,
                    'data'                      => $group['selected'][$index] ?? null,
                    'placeholder'               => false,
                ]);
            }
        }
    }
}
