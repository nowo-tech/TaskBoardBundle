<?php

declare(strict_types=1);

/*
 * Minimal stand-ins for the optional nowo-tech/tiptap-editor-bundle and nowo-tech/tag-input-bundle
 * form types, used to exercise the "bundle installed" branch of TaskFormType.
 */

namespace Nowo\TiptapEditorBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;

if (!class_exists(TiptapEditorType::class)) {
    /** @extends AbstractType<mixed> */
    class TiptapEditorType extends AbstractType
    {
        public function configureOptions(OptionsResolver $resolver): void
        {
            $resolver->setDefined(['config', 'min_height', 'placeholder']);
        }

        public function getParent(): string
        {
            return TextareaType::class;
        }
    }
}

namespace Nowo\TagInputBundle\Form;

use Nowo\TaskBoardBundle\Form\DataTransformer\TagsToStringTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

if (!class_exists(TagType::class)) {
    /** @extends AbstractType<mixed> */
    class TagType extends AbstractType
    {
        public function buildForm(FormBuilderInterface $builder, array $options): void
        {
            $builder->addModelTransformer(new TagsToStringTransformer());
        }

        public function configureOptions(OptionsResolver $resolver): void
        {
            $resolver->setDefined(['input_class', 'max_tags', 'duplicates']);
        }

        public function getParent(): string
        {
            return TextType::class;
        }
    }
}
