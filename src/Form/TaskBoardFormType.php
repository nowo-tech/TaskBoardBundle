<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Board\BoardScope;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

use function array_filter;
use function array_values;
use function count;
use function is_array;
use function trim;

/**
 * @extends AbstractType<TaskBoardFormData>
 */
#[FormKitConfig('task_board')]
final class TaskBoardFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addScopes($builder, $options['scopes'], $options['scope_required_message']);
        // Since 1.30.0 (spec 211): `board.icon`. A host widget (an emoji picker) keeps these constraints.
        if ($options['icon_field'] === true) {
            $this->addWithDefaults($builder, 'icon', $options['icon_form_type'] ?? TextType::class, [
                'label'       => 'task_board.form.board.icon',
                'help'        => 'task_board.form.board.icon_help',
                'required'    => false,
                'empty_data'  => null,
                'constraints' => TaskConstraints::boardIcon($options['icon_too_long_message'], $options['icon_invalid_message']),
            ] + $options['icon_form_options']);
        }
        $this->addText($builder, 'name', [
            // Since 1.21: an empty or missing name is a NotBlank error, not a TypeError on the DTO (string).
            'empty_data'  => '',
            'label'       => 'task_board.form.board.name',
            'constraints' => TaskConstraints::boardName(),
            'attr'        => ['maxlength' => 255],
        ]);
        $this->addText($builder, 'slug', [
            'label'    => 'task_board.form.board.slug',
            'required' => false,
            // Since 1.14.1: an empty slug is '' (the board creator derives it from the name), not null.
            'empty_data'  => '',
            'help'        => 'task_board.form.board.slug_help',
            'constraints' => TaskConstraints::boardSlug(),
            'attr'        => ['maxlength' => 255],
        ]);
        // Since 1.30.0 (spec 211): `board.description_form_type` replaces the widget with no Twig override.
        if ($options['description_form_type'] !== null) {
            $this->addWithDefaults($builder, 'description', $options['description_form_type'], [
                'label'    => 'task_board.form.board.description',
                'required' => false,
            ] + $options['description_form_options']);
        } else {
            $this->addTextarea($builder, 'description', [
                'label'    => 'task_board.form.board.description',
                'required' => false,
            ]);
        }

        // Since 1.11: start from a column set instead of board.default_columns (no field without sets).
        if ($options['column_set_choices'] !== []) {
            $this->addWithDefaults($builder, 'columnSetId', ChoiceType::class, [
                'label'                     => 'task_board.form.board.column_set',
                'required'                  => false,
                'choices'                   => $options['column_set_choices'],
                'choice_translation_domain' => false,
                'placeholder'               => 'task_board.form.board.default_columns',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => TaskBoardFormData::class,
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            // Since 1.11: column set name => id.
            'column_set_choices' => [],
            // Since 1.30.0 (spec 211): list<BoardScope>; none: no scope step.
            'scopes'                   => [],
            'icon_field'               => false,
            'icon_form_type'           => null,
            'icon_form_options'        => [],
            'description_form_type'    => null,
            'description_form_options' => [],
            'advanced_slug'            => false,
            // Translated by the controller: constraint messages are not in this form's domain.
            'icon_too_long_message'  => 'Too long for an icon: this emoji is several characters joined together. Pick a simpler one.',
            'icon_invalid_message'   => 'An icon is one emoji.',
            'scope_required_message' => 'Choose where the board goes.',
        ]);
        $resolver->setAllowedTypes('column_set_choices', 'array');
        $resolver->setAllowedTypes('scopes', 'array');
        $resolver->setAllowedTypes('icon_field', 'bool');
        $resolver->setAllowedTypes('icon_form_type', ['null', 'string']);
        $resolver->setAllowedTypes('icon_form_options', 'array');
        $resolver->setAllowedTypes('description_form_type', ['null', 'string']);
        $resolver->setAllowedTypes('description_form_options', 'array');
        $resolver->setAllowedTypes('advanced_slug', 'bool');
    }

    /**
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $options
     */
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['advanced_slug'] = $options['advanced_slug'];
    }

    /**
     * Since 1.30.0 (spec 211): the scope radios and one compound of fields per scope. A hidden branch cannot be required, so its
     * fields lose `required` and `constraints`: the host's `resolve()` decides.
     *
     * @param FormBuilderInterface<mixed> $builder
     * @param array<mixed> $scopes
     */
    private function addScopes(FormBuilderInterface $builder, array $scopes, string $requiredMessage): void
    {
        $scopes = array_values(array_filter($scopes, static fn (mixed $scope): bool => $scope instanceof BoardScope));
        if ($scopes === []) {
            return;
        }
        $choices = [];
        foreach ($scopes as $scope) {
            // Printed escaped by the form theme, and part of the radio's name for a screen reader.
            $label                                                                                                              = trim(($scope->icon ?? '') . ' ' . $scope->label);
            $choices[$scope->description !== null && $scope->description !== '' ? $label . ': ' . $scope->description : $label] = $scope->id;
        }
        $this->addWithDefaults($builder, 'scope', ChoiceType::class, [
            'label'                     => 'task_board.form.board.scope',
            'choices'                   => $choices,
            'choice_translation_domain' => false,
            'expanded'                  => true,
            'multiple'                  => false,
            'placeholder'               => false,
            'constraints'               => [new NotBlank(message: $requiredMessage)],
        ]);
        // One scope: nothing to choose, whatever was posted for it. An expanded choice has no `empty_data` on every Symfony.
        if (count($scopes) === 1) {
            $only = $scopes[0]->id;
            $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($only): void {
                $data = $event->getData();
                $event->setData(['scope' => $only] + (is_array($data) ? $data : []));
            });
        }
        $all = $builder->create('scopeFields', FormType::class, ['mapped' => false, 'label' => false, 'required' => false]);
        foreach ($scopes as $scope) {
            $branch = $all->create($scope->id, FormType::class, ['label' => false, 'required' => false]);
            foreach ($scope->fields as $name => $field) {
                $options = $field->options;
                unset($options['constraints']);
                $branch->add($name, $field->formType, ['required' => false] + $options);
            }
            $all->add($branch);
        }
        $builder->add($all);
    }
}
