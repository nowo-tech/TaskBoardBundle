<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\People;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingService;
use Nowo\TaskBoardBundle\Sync\People\PersonMappingDecision;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;

/**
 * Since 3.0.0 (audit ZAS-W1): the people services read a person, directory or board the application's manager holds
 * again by id at their entry, so the change is really written (TaskBoard's flush does not write the application's
 * instance) before the audit event is sent or the count returned. Each case hands in rows loaded through the
 * application's manager and reads the result back from the database.
 */
final class HostHeldPeopleRowsTest extends PeopleFunctionalTestCase
{
    private const WS = '9000077';

    public function testAnonymisingAPersonTheApplicationHoldsIsWritten(): void
    {
        [, , $person] = $this->setUpPeople();
        $hostPerson   = $this->host()->find(TaskSyncPerson::class, $person->getId());
        self::assertInstanceOf(TaskSyncPerson::class, $hostPerson);

        $this->rowService(TaskSyncPeopleDirectoryService::class)->anonymise($hostPerson, $this->owner);

        self::assertTrue($this->reread(TaskSyncPerson::class, $person->getId())->isAnonymised());
        self::assertFalse($hostPerson->isAnonymised(), 'the application\'s instance is unchanged');
        self::assertCount(1, $this->eventsNamed(TaskBoardEvents::SYNC_PERSON_ANONYMISED));
    }

    public function testMarkingADirectoryTheApplicationHoldsAsReviewedIsWritten(): void
    {
        [$board, $directory] = $this->setUpPeople();
        $hostDirectory       = $this->host()->find(TaskSyncDirectory::class, $directory->getId());
        $hostBoard           = $this->host()->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskSyncDirectory::class, $hostDirectory);

        $this->rowService(TaskSyncPeopleDirectoryService::class)->markReviewed($hostDirectory, $this->owner, $hostBoard);

        self::assertNotNull($this->reread(TaskSyncDirectory::class, $directory->getId())->getReviewedAt());
    }

    public function testApplyingAMappingInADirectoryTheApplicationHoldsIsWritten(): void
    {
        [$board, $directory, $person] = $this->setUpPeople();
        $hostDirectory                = $this->host()->find(TaskSyncDirectory::class, $directory->getId());
        self::assertInstanceOf(TaskSyncDirectory::class, $hostDirectory);

        $changed = $this->rowService(TaskSyncPeopleDirectoryService::class)->applyMapping($hostDirectory, [new PersonMappingDecision($person->getId(), PersonMappingDecision::EXTERNAL)], $this->owner, $this->host()->find(TaskBoard::class, $board->getId()));

        self::assertCount(1, $changed);
        self::assertSame(TaskSyncPersonMapping::External, $this->reread(TaskSyncPerson::class, $person->getId())->getMapping());
    }

    public function testCancellingTheReassignOfAPersonTheApplicationHoldsIsWritten(): void
    {
        [, , $person] = $this->setUpPeople();
        $person->setPendingReassign('reassign-1', null, true);
        $this->em->flush();
        $hostPerson = $this->host()->find(TaskSyncPerson::class, $person->getId());
        self::assertInstanceOf(TaskSyncPerson::class, $hostPerson);

        $this->rowService(TaskSyncPeopleReassigner::class)->cancelReassign($hostPerson, $this->owner);

        self::assertNull($this->reread(TaskSyncPerson::class, $person->getId())->getReassignId());
        self::assertSame('reassign-1', $hostPerson->getReassignId(), 'the application\'s instance is unchanged');
    }

    public function testMarkingUnmappedPeopleAsFormerInADirectoryTheApplicationHoldsIsWritten(): void
    {
        [$board, $directory, $person] = $this->setUpPeople();
        $hostDirectory                = $this->host()->find(TaskSyncDirectory::class, $directory->getId());
        $hostBoard                    = $this->host()->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskSyncDirectory::class, $hostDirectory);
        self::assertInstanceOf(TaskBoard::class, $hostBoard);

        self::assertSame(1, $this->rowService(PeopleMappingService::class)->markUnmappedAsFormer($hostDirectory, $hostBoard, $this->owner));

        self::assertSame(TaskSyncPersonMapping::External, $this->reread(TaskSyncPerson::class, $person->getId())->getMapping());
    }

    public function testReassigningAPersonTheApplicationHoldsIsWritten(): void
    {
        [, , $person] = $this->setUpPeople();
        $hostPerson   = $this->host()->find(TaskSyncPerson::class, $person->getId());
        self::assertInstanceOf(TaskSyncPerson::class, $hostPerson);

        self::assertTrue($this->rowService(TaskSyncPeopleReassigner::class)->reassign($hostPerson, null, $this->owner)->isDone());

        self::assertSame(TaskSyncPersonMapping::External, $this->reread(TaskSyncPerson::class, $person->getId())->getMapping());
        self::assertSame(TaskSyncPersonMapping::Unmatched, $hostPerson->getMapping(), 'the application\'s instance is unchanged');
        self::assertCount(1, $this->eventsNamed(TaskBoardEvents::SYNC_PERSON_REASSIGNED));
    }

    public function testSavingTheMappingOfADirectoryTheApplicationHoldsIsWritten(): void
    {
        [$board, $directory, $person] = $this->setUpPeople();
        $hostDirectory                = $this->host()->find(TaskSyncDirectory::class, $directory->getId());
        $hostBoard                    = $this->host()->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskSyncDirectory::class, $hostDirectory);
        self::assertInstanceOf(TaskBoard::class, $hostBoard);

        $result = $this->rowService(PeopleMappingService::class)->save($hostDirectory, $hostBoard, [$person->getId() => PeopleMappingService::NOT_MAPPED], $this->owner);

        self::assertSame([], $result->errors);
        self::assertNotNull($this->reread(TaskSyncPerson::class, $person->getId())->getMappedBy(), 'the decision is written');
        self::assertNotNull($this->reread(TaskSyncDirectory::class, $directory->getId())->getReviewedAt());
        self::assertNull($hostDirectory->getReviewedAt(), 'the application\'s instance is unchanged');
    }

    /** Since 3.0.0 (audit ZAS-X1): the directory is attached to TaskBoard's copy of a connection the application holds. */
    public function testAttachingTheDirectoryOfAConnectionTheApplicationHoldsIsWritten(): void
    {
        $connection     = $this->connection($this->board());
        $hostConnection = $this->host()->find(TaskBoardConnection::class, $connection->getId());
        self::assertInstanceOf(TaskBoardConnection::class, $hostConnection);

        $directory = $this->rowService(TaskSyncPeopleDirectoryService::class)->directoryFor($hostConnection, (new InMemoryTaskProvider())->withWorkspace(self::WS), new ProviderCredential(TestCredentialResolver::$secret, 'conn-1'));

        self::assertInstanceOf(TaskSyncDirectory::class, $directory);
        self::assertNull($hostConnection->getDirectory(), 'the application\'s instance is unchanged');
        self::assertSame($directory->getId(), $this->reread(TaskBoardConnection::class, $connection->getId())->getDirectory()?->getId());
    }

    /** Since 3.0.0 (audit ZAS-X1): a directory the application holds is pruned through TaskBoard's copy. */
    public function testPruningADirectoryTheApplicationHoldsIsWritten(): void
    {
        $board     = $this->board();
        $service   = $this->rowService(TaskSyncPeopleDirectoryService::class);
        $directory = $service->directoryForDraft($board, 'clickup', self::WS);
        $person    = $service->session($directory, $board)->person(new RemotePerson('9078', 'Nobody refers'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();
        $hostDirectory = $this->host()->find(TaskSyncDirectory::class, $directory->getId());
        self::assertInstanceOf(TaskSyncDirectory::class, $hostDirectory);

        self::assertSame(1, $service->pruneDirectory($hostDirectory));

        $this->em->clear();
        self::assertNull($this->em->find(TaskSyncPerson::class, $person->getId()));
        self::assertNull($this->em->find(TaskSyncDirectory::class, $directory->getId()));
    }

    /**
     * A board with a connection on a directory and one unmatched person, all written through TaskBoard's manager.
     *
     * @return array{TaskBoard, TaskSyncDirectory, TaskSyncPerson}
     */
    private function setUpPeople(): array
    {
        $board     = $this->board();
        $service   = $this->rowService(TaskSyncPeopleDirectoryService::class);
        $directory = $service->directoryForDraft($board, 'clickup', self::WS);
        $this->connection($board, $directory);
        $person = $service->session($directory, $board)->person(new RemotePerson('9077', 'Someone'), TaskSyncPerson::KIND_MEMBER);
        $this->em->flush();
        self::assertSame(TaskSyncPersonMapping::Unmatched, $person->getMapping());

        return [$board, $directory, $person];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function reread(string $class, string $id): object
    {
        $this->em->clear();
        $row = $this->em->find($class, $id);
        self::assertInstanceOf($class, $row);

        return $row;
    }

    /**
     * @template S of object
     *
     * @param class-string<S> $class
     *
     * @return S
     */
    private function rowService(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function host(): EntityManagerInterface
    {
        $host = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $host);

        return $host;
    }
}
