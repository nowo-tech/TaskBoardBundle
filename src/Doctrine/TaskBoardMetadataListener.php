<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Nowo\TaskBoardBundle\Doctrine\Type\TaskBoardUserRefType;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberCounter;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberEntry;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskRecurrence;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Template\Entity\TaskTemplate;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;
use Psr\Log\LoggerInterface;

use function array_replace_recursive;
use function ltrim;
use function sha1;
use function substr;

final readonly class TaskBoardMetadataListener
{
    /**
     * Since 2.15.1 (REQ-SEC-006, audit F5): the string keys the bundle looks rows up by, compared byte for byte
     * ({@see KeyCollation}).
     *
     * @var array<class-string, list<string>>
     */
    public const KEY_FIELDS = [
        TaskBoard::class         => ['tenantKey', 'slug'],
        TaskLink::class          => ['importSource', 'externalId'],
        TaskNumberEntry::class   => ['scopeKey'],
        TaskNumberCounter::class => ['scopeKey'],
        ColumnSet::class         => ['scopeKey'],
        TaskSyncDirectory::class => ['scopeKey', 'workspaceId'],
        TaskSyncItem::class      => ['externalId'],
        // Since 2.16.0 (spec 234, FR-061): a template's tenant, mapped only with `task_templates.enabled`.
        TaskTemplate::class => ['tenantKey'],
        // Since 2.19.0 (Beacon spec 234, FR-031): a folder's tenant.
        TaskBoardFolder::class => ['tenantKey'],
    ];

    public function __construct(
        private string $tasksTableName,
        private string $boardsTableName,
        private string $teamsTableName,
        private string $teamMembersTableName,
        private string $userClass,
        // Since 2.15.0 (REQ-ARCH-001 rule 2): `database.user_reference`.
        private string $userReference = TaskBoardUsers::ENTITY,
        // Since 2.15.1 (REQ-SEC-006 item 8): warns about a `server_version` without its patch number.
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $class    = $metadata->getName();

        match ($class) {
            Task::class       => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->tasksTableName])),
            TaskBoard::class  => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->boardsTableName])),
            Team::class       => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->teamsTableName])),
            TeamMember::class => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->teamMembersTableName])),
            default           => null,
        };

        $userFieldsByClass = [
            Task::class              => ['creator'],
            TaskBoard::class         => ['creator'],
            TeamMember::class        => ['user'],
            TaskMember::class        => ['user'],
            TaskChangeHistory::class => ['user'],
            TaskDocument::class      => ['creator'],
            TaskTimeEntry::class     => ['user'],
            ColumnSet::class         => ['createdBy'],
            TaskComment::class       => ['author'],
            // Since 1.23 (sync tables, mapped with sync.enabled).
            TaskBoardConnection::class => ['createdBy'],
            TaskSyncRun::class         => ['actor'],
            // Since 1.25: the people directory.
            TaskSyncDirectory::class => ['reviewedBy'],
            TaskSyncPerson::class    => ['mappedUser', 'suggestedUser', 'mappedBy', 'reassignToUser'],
            // Since 2.3.0 (spec 215, mapped with recurrence.enabled).
            TaskRecurrence::class => ['author'],
        ];

        $userFields = $userFieldsByClass[$class] ?? [];
        $keyFields  = self::KEY_FIELDS[$class] ?? [];
        $idMode     = $this->userReference === TaskBoardUsers::ID;
        $collation  = null;
        if ($keyFields !== [] || ($idMode && $userFields !== [])) {
            $connection = $args->getObjectManager()->getConnection();
            $collation  = KeyCollation::forConnection($connection);
            if ($class === TaskBoard::class) {
                KeyCollation::warnAboutPartialVersion($connection, $this->logger);
            }
        }

        foreach ($userFields as $field) {
            if (isset($metadata->associationMappings[$field])) {
                if ($idMode) {
                    $this->mapUserReference($metadata, $field, $collation);
                } else {
                    $this->remapUserAssociation($metadata, $field);
                }
            }
        }
        if ($collation !== null) {
            foreach ($keyFields as $field) {
                if (isset($metadata->fieldMappings[$field])) {
                    $mapping          = $metadata->fieldMappings[$field];
                    $mapping->options = ['collation' => $collation] + ($mapping->options ?? []);
                }
            }
        }
    }

    /**
     * Since 2.15.0 (`database.user_reference: id`): the user field becomes a string column with the user's id
     * ({@see TaskBoardUserRefType}) in the join column's place, same name and nullability, with an index of its own. On
     * MySQL / MariaDB the column compares byte for byte with NO PAD (`utf8mb4_0900_bin` on MySQL 8, `utf8mb4_nopad_bin` on
     * MariaDB; audit OC-M1, BUNDLES_FIX_RULES "Identifier columns").
     *
     * @param ClassMetadata<object> $metadata
     */
    private function mapUserReference(ClassMetadata $metadata, string $fieldName, ?string $collation): void
    {
        $mapping = $metadata->associationMappings[$fieldName];
        $join    = $mapping instanceof ToOneOwningSideMapping ? ($mapping->joinColumns[0] ?? null) : null;
        $column  = $join instanceof JoinColumnMapping ? $join->name : $fieldName . '_id';
        unset($metadata->associationMappings[$fieldName]);
        $field = [
            'fieldName'  => $fieldName,
            'type'       => TaskBoardUserRefType::NAME,
            'columnName' => $column,
            'length'     => TaskBoardUserRef::MAX_LENGTH,
            'nullable'   => !$join instanceof JoinColumnMapping || $join->nullable !== false,
        ];
        // Audit OC-M1: a binary NO PAD collation (utf8mb4_bin is PAD SPACE: "u " = "u"); since 2.15.1 through
        // KeyCollation, which also honours a MySQL server_version older than 8.0.17 (REQ-SEC-006 item 8).
        if ($collation !== null) {
            $field['options'] = ['collation' => $collation];
        }
        $metadata->mapField($field);
        $metadata->table['indexes']['idx_' . substr(sha1($metadata->getTableName() . '.' . $column), 0, 16)] = ['columns' => [$column]];
    }

    /**
     * Called for a field the metadata maps as an association (ORM 3: always an {@see AssociationMapping}).
     *
     * @param ClassMetadata<object> $metadata
     */
    private function remapUserAssociation(ClassMetadata $metadata, string $fieldName): void
    {
        $mapping = $metadata->associationMappings[$fieldName];

        $newMapping = array_replace_recursive($mapping->toArray(), [
            'targetEntity' => ltrim($this->userClass, '\\'),
        ]);
        $newMapping['fieldName'] = $mapping->fieldName;
        unset($metadata->associationMappings[$fieldName]);
        $metadata->mapManyToOne($newMapping);
    }
}
