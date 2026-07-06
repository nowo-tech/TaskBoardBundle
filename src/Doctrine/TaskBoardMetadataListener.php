<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;

use function array_replace_recursive;
use function ltrim;

final readonly class TaskBoardMetadataListener
{
    public function __construct(
        private string $tasksTableName,
        private string $boardsTableName,
        private string $teamsTableName,
        private string $teamMembersTableName,
        private string $userClass,
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
        ];

        foreach ($userFieldsByClass[$class] ?? [] as $field) {
            if (isset($metadata->associationMappings[$field])) {
                $this->remapUserAssociation($metadata, $field);
            }
        }
    }

    private function remapUserAssociation(ClassMetadata $metadata, string $fieldName): void
    {
        $mapping = $metadata->associationMappings[$fieldName] ?? null;
        if (!$mapping instanceof AssociationMapping) {
            return;
        }

        $newMapping = array_replace_recursive($mapping->toArray(), [
            'targetEntity' => ltrim($this->userClass, '\\'),
        ]);
        $newMapping['fieldName'] = $mapping->fieldName;
        unset($metadata->associationMappings[$fieldName]);
        $metadata->mapManyToOne($newMapping);
    }
}
