<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Nowo\TaskBoardBundle\Doctrine\Type\TaskBoardUserRefType;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskRecurrence;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;

use function array_replace_recursive;
use function ltrim;
use function sha1;
use function substr;

final readonly class TaskBoardMetadataListener
{
    public function __construct(
        private string $tasksTableName,
        private string $boardsTableName,
        private string $teamsTableName,
        private string $teamMembersTableName,
        private string $userClass,
        // Since 2.15.0 (REQ-ARCH-001 rule 2): `database.user_reference`.
        private string $userReference = TaskBoardUsers::ENTITY,
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

        foreach ($userFieldsByClass[$class] ?? [] as $field) {
            if (isset($metadata->associationMappings[$field])) {
                if ($this->userReference === TaskBoardUsers::ID) {
                    $this->mapUserReference($metadata, $field, $args->getObjectManager()->getConnection()->getDatabasePlatform());
                } else {
                    $this->remapUserAssociation($metadata, $field);
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
    private function mapUserReference(ClassMetadata $metadata, string $fieldName, AbstractPlatform $platform): void
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
        // Audit OC-M1: a binary NO PAD collation (utf8mb4_bin is PAD SPACE: "u " = "u").
        $collation = match (true) {
            $platform instanceof MariaDBPlatform       => 'utf8mb4_nopad_bin',
            $platform instanceof MySQL80Platform       => 'utf8mb4_0900_bin',
            $platform instanceof AbstractMySQLPlatform => 'utf8mb4_bin', // MySQL 5.7: no NO PAD binary collation; ids with edge spaces are refused
            default                                    => null,
        };
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
