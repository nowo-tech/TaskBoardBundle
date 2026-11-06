<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingRow;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingService;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_keys;
use function array_map;

/**
 * Since 1.27.0 (TB-SYNC-2): the People page — one host-user select per remote person (`p_<person id>`): *Not mapped*,
 * the board's candidates and, with a host inviter and a remote e-mail, *Invite by e-mail*. Only these values are
 * accepted (a forged id is a form error). Labels are the host's user labels, escaped by Twig.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('task_board')]
final class ConnectionPeopleFormType extends AbstractType
{
    use FormOptionsTrait;

    public const NAME = 'task_board_sync_people';

    public const FIELD_PREFIX = 'p_';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $candidates */
        $candidates = $options['candidates'];
        /** @var list<PeopleMappingRow> $rows */
        $rows = $options['rows'];
        foreach ($rows as $row) {
            $labels = [PeopleMappingService::NOT_MAPPED => $options['not_mapped_label']];
            if ($options['invite'] === true && $row->person->getEmail() !== null) {
                $labels[PeopleMappingService::INVITE] = $options['invite_label'];
            }
            foreach ($candidates as $id => $label) {
                $labels[(string) $id] = $label;
            }
            $mapped = $row->person->getMappedUser();
            if ($row->currentUserId !== '' && !isset($labels[$row->currentUserId]) && $mapped !== null) {
                // The mapped user is no longer listed (candidate limit, or no longer a candidate): keep it selectable.
                $labels[$row->currentUserId] = UserLabel::option($mapped);
            }
            $this->addWithDefaults($builder, self::FIELD_PREFIX . $row->person->getId(), ChoiceType::class, [
                'label'                     => false,
                'choices'                   => array_map(strval(...), array_keys($labels)),
                'choice_label'              => static fn (string $value): string => $labels[$value],
                'choice_translation_domain' => false,
                'data'                      => $row->selectedUserId,
                'required'                  => false,
                'placeholder'               => false,
                'attr'                      => ['data-prefill' => $row->prefill, 'aria-labelledby' => 'nowo-task-sync-person-' . $row->person->getId()],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => TaskBoardBundle::TRANSLATION_DOMAIN,
            'csrf_token_id'      => 'task_board_connection_people',
            'rows'               => [],
            'candidates'         => [],
            'invite'             => false,
            'not_mapped_label'   => 'Not mapped',
            'invite_label'       => 'Invite by e-mail',
        ]);
        $resolver->setAllowedTypes('not_mapped_label', 'string');
        $resolver->setAllowedTypes('invite_label', 'string');
        $resolver->setAllowedTypes('rows', 'array');
        $resolver->setAllowedTypes('candidates', 'array');
        $resolver->setAllowedTypes('invite', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return self::NAME;
    }
}
