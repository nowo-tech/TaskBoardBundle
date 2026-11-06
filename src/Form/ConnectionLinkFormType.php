<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Since 1.23: links the board to a remote list. The list radios come from the container browser fragments
 * ({@see ContainerLevelFormType}, same field name); here `container` only validates the submitted id.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('task_board')]
final class ConnectionLinkFormType extends AbstractType
{
    use FormOptionsTrait;

    public const NAME = 'task_board_sync_link';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('provider', HiddenType::class, ['constraints' => [new NotBlank()]]);
        $builder->add('connection', HiddenType::class, ['constraints' => [new NotBlank()]]);
        $builder->add('container', TextType::class, [
            'label'       => 'task_board.sync.browser.list',
            'constraints' => [new NotBlank(message: 'task_board.sync.browser.pick_list'), new Regex('/^[A-Za-z0-9_:-]{1,128}$/D')],
        ]);
        $this->addCheckbox($builder, 'createMissingColumns', [
            'label'    => 'task_board.sync.option.create_columns',
            'required' => false,
        ]);
        $this->addWithDefaults($builder, 'fallbackColumnId', ChoiceType::class, [
            'label'       => 'task_board.sync.option.fallback_column',
            'choices'     => $options['column_choices'],
            'required'    => false,
            'placeholder' => 'task_board.sync.option.fallback_first',
        ]);
        // Since 1.28.0: columns no provider status maps to, at the end of the first import. Only empty ones ever go.
        $this->addWithDefaults($builder, 'unmatchedColumns', ChoiceType::class, [
            'label'   => 'task_board.sync.option.unmatched_columns',
            'choices' => [
                'task_board.sync.option.unmatched_columns_keep'   => TaskBoardConnection::UNMATCHED_KEEP,
                'task_board.sync.option.unmatched_columns_delete' => TaskBoardConnection::UNMATCHED_DELETE,
            ],
            'required'    => false,
            'placeholder' => false,
            'empty_data'  => TaskBoardConnection::UNMATCHED_KEEP,
        ]);
        $this->addCheckbox($builder, 'importComments', [
            'label'    => 'task_board.sync.option.import_comments',
            'required' => false,
        ]);
        $this->addCheckbox($builder, 'importAttachments', [
            'label'    => 'task_board.sync.option.import_attachments',
            'required' => false,
        ]);
        $this->addCheckbox($builder, 'archiveRemoteDeletions', [
            'label'    => 'task_board.sync.option.archive_deleted',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'csrf_token_id'      => 'task_board_connection_link',
            'column_choices'     => [],
        ]);
        $resolver->setAllowedTypes('column_choices', 'array');
    }

    public function getBlockPrefix(): string
    {
        return self::NAME;
    }
}
