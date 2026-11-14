<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Enum\CustomFieldOperator;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Since 2.18.0 (Beacon spec 234, FR-028): the list view's custom field filter — a field, an operator (=, ≠, <, >, is set,
 * is not set, in) and a value (comma-separated for "in"), sent as GET query parameters (no CSRF: it changes nothing).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig('task_board')]
final class TaskCustomFieldFilterType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $fields = [];
        /** @var TaskCustomField $field */
        foreach ($options['fields'] as $field) {
            $fields[$field->getName()] = $field->getId();
        }
        $this->addWithDefaults($builder, 'cf_field', ChoiceType::class, [
            'label'                     => 'task_board.custom_field.filter.field',
            'choices'                   => $fields,
            'choice_translation_domain' => false,
            'placeholder'               => 'task_board.custom_field.filter.any',
            'required'                  => false,
        ]);
        $this->addWithDefaults($builder, 'cf_op', EnumType::class, [
            'label'        => 'task_board.custom_field.filter.operator',
            'class'        => CustomFieldOperator::class,
            'choice_label' => static fn (CustomFieldOperator $operator): string => 'task_board.custom_field.operator.' . match ($operator) {
                CustomFieldOperator::Equals      => 'eq',
                CustomFieldOperator::NotEquals   => 'ne',
                CustomFieldOperator::LessThan    => 'lt',
                CustomFieldOperator::GreaterThan => 'gt',
                CustomFieldOperator::IsSet       => 'set',
                CustomFieldOperator::IsNotSet    => 'not_set',
                CustomFieldOperator::In          => 'in',
            },
            'required' => false,
        ]);
        $this->addText($builder, 'cf_value', [
            'label'    => 'task_board.custom_field.filter.value',
            'required' => false,
            'attr'     => ['maxlength' => 500],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'fields'             => [],
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'allow_extra_fields' => true,
        ]);
        $resolver->setAllowedTypes('fields', 'array');
    }
}
