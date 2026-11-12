<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Port;

use DateTimeImmutable;
use LogicException;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentStoreInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRequestAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntryBillableInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeStoreInterface;
use Nowo\TaskBoardBundle\Api\TaskApiAuthFailure;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiAttachment;
use Nowo\TaskBoardBundle\Api\Value\TaskApiNewTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRequestContext;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimePage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeQuery;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUpload;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneePolicyInterface;
use Nowo\TaskBoardBundle\Comment\MentionCandidate;
use Nowo\TaskBoardBundle\Comment\TaskCommentCauseTextInterface;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesProviderInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\History\TaskChangeCauseTextInterface;
use Nowo\TaskBoardBundle\Import\TaskImportLinkFinderInterface;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetIdsInterface;
use Nowo\TaskBoardBundle\Port\FirstBoardProvisioningGate;
use Nowo\TaskBoardBundle\Port\ImportedTimeEntryReceiverGate;
use Nowo\TaskBoardBundle\Port\OwnerlessTimeEntryReceiverGate;
use Nowo\TaskBoardBundle\Port\Ports;
use Nowo\TaskBoardBundle\Port\SyncPersonDirectoryGate;
use Nowo\TaskBoardBundle\Port\TaskApiBillableTimeStoreGate;
use Nowo\TaskBoardBundle\Port\TaskApiTimeStoreGate;
use Nowo\TaskBoardBundle\Port\TaskBoardTargetIdsGate;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyPolicyInterface;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntryReceiverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\OwnerlessTimeEntryReceiverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\OwnerlessTimeEntrySinkInterface;
use Nowo\TaskBoardBundle\Sync\Hook\PeopleInvitationInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Hook\StoredAttachment;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorProviderInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncedFileStorageInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncedImageRewriterInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonDirectoryInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonMatch;
use Nowo\TaskBoardBundle\Sync\Hook\SyncQuotaPolicyInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocation;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccessMode;
use Nowo\TaskBoardBundle\Sync\Time\TimeWindow;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisioningInterface;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskChangeSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskCommentSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSyncDirectorySnapshot;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewPolicyInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

use function count;
use function file_put_contents;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Since 2.15.0: every gate turns the entities into snapshots, passes the other arguments through and returns the twin's
 * answer unchanged; a twin that throws makes the gate throw the same exception.
 */
final class PortGatesTest extends TestCase
{
    use PortFixtures;

    public function testAssigneePolicy(): void
    {
        $board  = $this->board();
        $user   = new stdClass();
        $policy = new class implements TaskAssigneePolicyInterface {
            /** @var list<TaskBoardSnapshot> */
            public array $boards = [];

            public function assignableUsers(TaskBoardSnapshot $board): array
            {
                $this->boards[] = $board;

                return [new stdClass()];
            }

            public function canBeAssigned(TaskBoardSnapshot $board, object $user): bool
            {
                $this->boards[] = $board;

                return $user instanceof stdClass;
            }
        };
        $gate = Ports::assigneeProvider($policy);

        self::assertCount(1, $gate->assignableUsers($board));
        self::assertTrue($gate->canBeAssigned($board, $user));
        self::assertSame([$board->getId(), $board->getId()], [$policy->boards[0]->id, $policy->boards[1]->id]);
    }

    public function testCauseTexts(): void
    {
        $comment = $this->comment();
        $change  = $this->change();
        $text    = new class implements TaskCommentCauseTextInterface, TaskChangeCauseTextInterface {
            public function label(string $cause, TaskCommentSnapshot|TaskChangeSnapshot $entry): string
            {
                return $cause . '@' . $entry->id;
            }
        };

        self::assertSame('rule:1@' . $comment->getId(), Ports::commentCauseLabeler($text)->label('rule:1', $comment));
        self::assertSame('rule:2@' . $change->getId(), Ports::changeCauseLabeler($text)->label('rule:2', $change));
    }

    public function testMentionCandidates(): void
    {
        $task      = $this->task();
        $candidate = new MentionCandidate('u-1', 'Ana', new stdClass());
        $provider  = new class($candidate) implements TaskMentionCandidatesProviderInterface {
            public ?TaskSnapshot $task = null;

            public function __construct(private readonly MentionCandidate $candidate)
            {
            }

            public function candidates(TaskSnapshot $task): array
            {
                $this->task = $task;

                return [$this->candidate];
            }
        };

        self::assertSame([$candidate], Ports::mentionCandidates($provider)->candidates($task));
        self::assertSame($task->getId(), $provider->task?->id);
    }

    public function testImportLinkFinder(): void
    {
        $board  = $this->board();
        $finder = new class implements TaskImportLinkFinderInterface {
            /** @var list<mixed> */
            public array $args = [];

            public function findOnOtherBoards(TaskBoardSnapshot $board, string $scope, array $externalIds): array
            {
                $this->args = [$board->id, $scope, $externalIds];

                return ['x' => true];
            }
        };

        self::assertSame(['x' => true], Ports::importLinkLookup($finder)->findOnOtherBoards($board, 'clickup', ['x', 'y']));
        self::assertSame([$board->getId(), 'clickup', ['x', 'y']], $finder->args);
    }

    public function testBoardTargetIdsLoadsTheBoardsInOrderAndSkipsUnknownAndRepeatedIds(): void
    {
        $task = $this->task();
        $one  = $this->board();
        $two  = $this->board();
        $repo = $this->createMock(TaskBoardRepositoryInterface::class);
        $repo->method('findById')->willReturnCallback(static fn (string $id): ?TaskBoard => ['one' => $one, 'two' => $two][$id] ?? null);
        $targets = new class implements TaskBoardTargetIdsInterface {
            public ?TaskSnapshot $task = null;

            public function targetIds(TaskSnapshot $task, object $user): array
            {
                $this->task = $task;

                return ['two', 'missing', 'one', 'two'];
            }
        };
        $gate = Ports::boardTargets($targets, $repo);

        self::assertInstanceOf(TaskBoardTargetIdsGate::class, $gate);
        self::assertSame([$two, $one], $gate->targets($task, new stdClass()));
        self::assertSame($task->getId(), $targets->task?->id);
    }

    public function testDefaultViewPolicy(): void
    {
        $board  = $this->board();
        $policy = new class implements TaskBoardDefaultViewPolicyInterface {
            public function defaultView(object $user, TaskBoardSnapshot $board): ?string
            {
                return $board->tenantKey === 'acme' ? 'list' : null;
            }
        };

        self::assertSame('list', Ports::defaultViewResolver($policy)->defaultView(new stdClass(), $board));
    }

    public function testFirstBoardProvisioningLoadsTheReturnedBoard(): void
    {
        $board = $this->board();
        $repo  = $this->createMock(TaskBoardRepositoryInterface::class);
        $repo->method('findById')->willReturnCallback(static fn (string $id): ?TaskBoard => $id === 'b-1' ? $board : null);
        $provisioning = new class implements FirstBoardProvisioningInterface {
            public function provision(TaskBoardTenant $tenant, object $user): ?string
            {
                return ['acme' => 'b-1', 'gone' => 'b-404'][$tenant->key] ?? null;
            }
        };
        $gate = Ports::firstBoardProvisioner($provisioning, $repo);

        self::assertInstanceOf(FirstBoardProvisioningGate::class, $gate);
        self::assertSame($board, $gate->provision(new TaskBoardTenant('acme', 'acme', 'Acme'), new stdClass()));
        self::assertNull($gate->provision(new TaskBoardTenant('gone', 'gone', 'Gone'), new stdClass()));
        self::assertNull($gate->provision(new TaskBoardTenant('none', 'none', 'None'), new stdClass()));
    }

    public function testRequestAuthenticatorGetsTheRequestContext(): void
    {
        $principal     = $this->principal();
        $authenticator = new class($principal) implements TaskApiRequestAuthenticatorInterface {
            public ?TaskApiRequestContext $request = null;

            public function __construct(private readonly TaskApiPrincipal $principal)
            {
            }

            public function authenticate(TaskApiRequestContext $request): TaskApiPrincipal|TaskApiAuthFailure
            {
                $this->request = $request;

                return $request->credential === 'token-REDACTED-TEST' ? $this->principal : TaskApiAuthFailure::Invalid;
            }
        };
        $request = Request::create('/api/tasks/v2/team', 'GET', server: ['REMOTE_ADDR' => '10.0.0.9', 'HTTP_AUTHORIZATION' => 'Bearer token-REDACTED-TEST', 'HTTP_X_TRACE' => 'abc', 'HTTP_COOKIE' => 'sid=secret']);
        $request->attributes->set('_route', 'task_api_teams');

        self::assertSame($principal, Ports::apiAuthenticator($authenticator)->authenticate($request));
        $context = $authenticator->request;
        self::assertInstanceOf(TaskApiRequestContext::class, $context);
        self::assertSame('GET', $context->method);
        self::assertSame('/api/tasks/v2/team', $context->path);
        self::assertSame('task_api_teams', $context->route);
        self::assertSame('10.0.0.9', $context->clientIp);
        self::assertSame('token-REDACTED-TEST', $context->credential);
        self::assertSame('abc', $context->header('X-Trace'));
        self::assertNull($context->header('cookie'), 'Cookies are never copied.');
        self::assertSame(TaskApiAuthFailure::Invalid, Ports::apiAuthenticator($authenticator)->authenticate(Request::create('/api')));
        self::assertNull($authenticator->request->route);
    }

    public function testAttachmentStoreGetsTheTaskSnapshotAndTheUpload(): void
    {
        $task = $this->task();
        $path = (string) tempnam(sys_get_temp_dir(), 'tb-upload');
        file_put_contents($path, 'hello');
        $file  = new UploadedFile($path, 'notes.txt', 'text/plain', null, true);
        $store = new class implements TaskApiAttachmentStoreInterface {
            /** @var list<mixed> */
            public array $seen = [];

            public function store(TaskApiPrincipal $principal, TaskSnapshot $task, TaskApiUpload $file): TaskApiAttachment
            {
                $stream     = $file->open();
                $this->seen = [$task->id, $file->clientName, $file->clientMimeType, $file->size, stream_get_contents($stream)];
                fclose($stream);

                return new TaskApiAttachment('a-1', $file->clientName, 'https://files.example/a-1', $file->size);
            }
        };

        try {
            $attachment = Ports::apiAttachments($store)->store($this->principal(), $task, $file);
        } finally {
            unlink($path);
        }

        self::assertSame('a-1', $attachment->id);
        self::assertSame([$task->getId(), 'notes.txt', 'text/plain', 5, 'hello'], $store->seen);
    }

    public function testTimeStoreWithoutAndWithBillable(): void
    {
        $task  = $this->task();
        $entry = new TaskApiTimeEntry('e-1', 'u-1', new DateTimeImmutable('2026-11-01'), 60);
        $store = new class($entry) implements TaskApiTimeStoreInterface {
            /** @var list<string|null> */
            public array $tasks = [];

            public function __construct(private readonly TaskApiTimeEntry $entry)
            {
            }

            public function list(TaskApiPrincipal $principal, TaskApiTimeQuery $query): TaskApiTimePage
            {
                return new TaskApiTimePage([$this->entry]);
            }

            public function log(TaskApiPrincipal $principal, ?TaskSnapshot $task, TaskApiNewTimeEntry $entry): TaskApiTimeEntry
            {
                $this->tasks[] = $task?->id;

                return $this->entry;
            }
        };
        $gate = Ports::apiTimeEntries($store);

        self::assertInstanceOf(TaskApiTimeStoreGate::class, $gate);
        self::assertNotInstanceOf(TaskApiTimeEntryBillableInterface::class, $gate, 'A store without billable keeps 403 TIME_003.');
        self::assertSame([$entry], $gate->list($this->principal(), new TaskApiTimeQuery('t-1'))->entries);
        $new = new TaskApiNewTimeEntry('t-1', new DateTimeImmutable('2026-11-01'), 60);
        self::assertSame($entry, $gate->log($this->principal(), $task, $new));
        self::assertSame($entry, $gate->log($this->principal(), null, $new));
        self::assertSame([$task->getId(), null], $store->tasks);

        $billable = new class($entry) implements TaskApiTimeStoreInterface, TaskApiTimeEntryBillableInterface {
            /** @var list<mixed> */
            public array $calls = [];

            public function __construct(private readonly TaskApiTimeEntry $entry)
            {
            }

            public function list(TaskApiPrincipal $principal, TaskApiTimeQuery $query): TaskApiTimePage
            {
                return new TaskApiTimePage([]);
            }

            public function log(TaskApiPrincipal $principal, ?TaskSnapshot $task, TaskApiNewTimeEntry $entry): TaskApiTimeEntry
            {
                $this->calls[] = $task?->id;

                return $this->entry;
            }

            public function setBillable(TaskApiPrincipal $principal, string $entryId, bool $billable): TaskApiTimeEntry
            {
                $this->calls[] = [$entryId, $billable];

                return $this->entry;
            }
        };
        $gate = Ports::apiTimeEntries($billable);

        self::assertInstanceOf(TaskApiBillableTimeStoreGate::class, $gate);
        self::assertInstanceOf(TaskApiTimeEntryBillableInterface::class, $gate);
        self::assertSame([], $gate->list($this->principal(), new TaskApiTimeQuery('t-1'))->entries);
        self::assertSame($entry, $gate->log($this->principal(), $task, $new));
        self::assertSame($entry, $gate->log($this->principal(), null, $new));
        self::assertSame($entry, $gate->setBillable($this->principal(), 'e-1', true));
        self::assertSame([$task->getId(), null, ['e-1', true]], $billable->calls);
    }

    public function testTimeReceivers(): void
    {
        $board     = $this->board();
        $directory = $this->directory();
        $window    = new TimeWindow(new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-08'), RemoteLocation::list('l-1'));
        $access    = new TimeAccess(TimeAccessMode::Admin);
        $receiver  = new class implements ImportedTimeEntryReceiverInterface {
            /** @var list<mixed> */
            public array $seen = [];

            public function accept(TaskBoardSnapshot $board, TaskSyncDirectorySnapshot $directory, TimeWindow $window, TimeAccess $access, array $entries, bool $windowComplete): void
            {
                $this->seen = [$board->id, $directory->id, $window, $access, $entries, $windowComplete];
            }
        };
        $gate = Ports::timeEntrySink($receiver);
        self::assertInstanceOf(ImportedTimeEntryReceiverGate::class, $gate);
        self::assertNotInstanceOf(OwnerlessTimeEntrySinkInterface::class, $gate);
        $gate->accept($board, $directory, $window, $access, [], true);
        self::assertSame([$board->getId(), $directory->getId(), $window, $access, [], true], $receiver->seen);

        $ownerless = new class implements OwnerlessTimeEntryReceiverInterface {
            /** @var list<mixed> */
            public array $seen = [];

            public function accept(TaskBoardSnapshot $board, TaskSyncDirectorySnapshot $directory, TimeWindow $window, TimeAccess $access, array $entries, bool $windowComplete): void
            {
                $this->seen = [$board->id, $directory->id, $windowComplete];
            }
        };
        $gate = Ports::timeEntrySink($ownerless);
        self::assertInstanceOf(OwnerlessTimeEntryReceiverGate::class, $gate);
        $gate->accept($board, $directory, $window, $access, [], false);
        self::assertSame([$board->getId(), $directory->getId(), false], $ownerless->seen);
    }

    public function testImageRewriterInvitationCredentialsActorQuotaAndStorage(): void
    {
        $board = $this->board();
        $task  = $this->task($board);
        $actor = new stdClass();

        $rewriter = new class implements SyncedImageRewriterInterface {
            public function rewrite(TaskSnapshot $task, string $markdown, array $storedByRemoteUrl): string
            {
                return $task->id . ':' . $markdown . ':' . count($storedByRemoteUrl);
            }
        };
        self::assertSame($task->getId() . ':md:0', Ports::inlineImageRewriter($rewriter)->rewrite($task, 'md', []));

        $invitation = new class implements PeopleInvitationInterface {
            public function canInvite(TaskBoardSnapshot $board, object $actor): bool
            {
                return $board->slug === 'roadmap';
            }

            public function invite(TaskBoardSnapshot $board, string $email, string $name, object $actor): ?object
            {
                return $email === 'a@example.com' ? $actor : null;
            }
        };
        $inviter = Ports::peopleInviter($invitation);
        self::assertTrue($inviter->canInvite($board, $actor));
        self::assertSame($actor, $inviter->invite($board, 'a@example.com', 'A', $actor));
        self::assertNull($inviter->invite($board, 'b@example.com', 'B', $actor));

        $credential = new ProviderCredential('REDACTED-TEST', 'c-1');
        $source     = new class($credential) implements ProviderCredentialSourceInterface {
            public function __construct(private readonly ProviderCredential $credential)
            {
            }

            public function connectionsFor(TaskBoardSnapshot $board, object $user, string $provider): array
            {
                return [new ConnectionChoice($board->id, $provider, '1234')];
            }

            public function credentialFor(TaskBoardSnapshot $board, string $connectionRef): ?ProviderCredential
            {
                return $connectionRef === 'c-1' ? $this->credential : null;
            }

            public function credentialFormUrl(TaskBoardSnapshot $board, string $provider): string
            {
                return '/tokens/' . $board->tenantKey . '/' . $provider;
            }
        };
        $resolver = Ports::credentialResolver($source);
        self::assertSame($board->getId(), $resolver->connectionsFor($board, $actor, 'clickup')[0]->ref);
        self::assertSame($credential, $resolver->credentialFor($board, 'c-1'));
        self::assertNull($resolver->credentialFor($board, 'c-2'));
        self::assertSame('/tokens/acme/clickup', $resolver->credentialFormUrl($board, 'clickup'));

        $actors = new class($actor) implements SyncActorProviderInterface {
            public function __construct(private readonly object $actor)
            {
            }

            public function actorFor(TaskBoardSnapshot $board, ?string $actorId = null): ?object
            {
                return $actorId === 'u-1' ? $this->actor : null;
            }
        };
        self::assertSame($actor, Ports::actorResolver($actors)->actorFor($board, 'u-1'));
        self::assertNull(Ports::actorResolver($actors)->actorFor($board));

        $quota = new class implements SyncQuotaPolicyInterface {
            public function check(TaskBoardSnapshot $board, int $newTasks, int $newBytes): ?LimitRefusal
            {
                return $newTasks > 1 ? new LimitRefusal('tasks', null, [], $newTasks - 1) : null;
            }
        };
        self::assertNull(Ports::limitChecker($quota)->check($board, 1, 0));
        self::assertSame(2, Ports::limitChecker($quota)->check($board, 3, 0)?->overTasks);

        $stored  = new StoredAttachment('ref-1', 'https://files.example/1', 'a.png', 3, 'image/png');
        $storage = new class($stored) implements SyncedFileStorageInterface {
            /** @var list<string> */
            public array $deleted = [];

            public function __construct(private readonly StoredAttachment $stored)
            {
            }

            public function store(TaskSnapshot $task, RemoteAttachment $file, callable $download): StoredAttachment
            {
                return $this->stored;
            }

            public function delete(TaskSnapshot $task, StoredAttachment $stored): void
            {
                $this->deleted[] = $task->id . ':' . $stored->ref;
            }
        };
        $gate = Ports::attachmentStorage($storage);
        self::assertSame($stored, $gate->store($task, new RemoteAttachment('r-1', 'a.png', 3, 'image/png', 'https://cdn.example/a.png'), static fn (): null => null));
        $gate->delete($task, $stored);
        self::assertSame([$task->getId() . ':ref-1'], $storage->deleted);
    }

    public function testPersonDirectoryAndItsAttributionAnswer(): void
    {
        $board = $this->board();
        $user  = new stdClass();
        $match = new SyncPersonMatch($user, true);

        $plain = new class($user, $match) implements SyncPersonDirectoryInterface {
            public function __construct(private readonly object $user, private readonly SyncPersonMatch $match)
            {
            }

            public function directoryScope(TaskBoardSnapshot $board): string
            {
                return 'project:' . $board->tenantKey;
            }

            public function candidates(TaskBoardSnapshot $board, string $query = '', int $limit = 20): iterable
            {
                return $query === 'an' && $limit === 5 ? [$this->user] : [];
            }

            public function isCandidate(TaskBoardSnapshot $board, object $user): bool
            {
                return $user === $this->user;
            }

            public function autoMatch(TaskBoardSnapshot $board, RemotePerson|RemotePersonRef $person, ?string $normalizedEmail = null): ?SyncPersonMatch
            {
                return $normalizedEmail === 'a@example.com' ? $this->match : null;
            }

            public function candidateById(TaskBoardSnapshot $board, string $userId): ?object
            {
                return $userId === 'u-1' ? $this->user : null;
            }
        };
        $gate = Ports::personResolver($plain);
        self::assertInstanceOf(SyncPersonDirectoryGate::class, $gate);
        self::assertSame('project:acme', $gate->directoryScope($board));
        self::assertSame([$user], $gate->candidates($board, 'an', 5));
        self::assertTrue($gate->isCandidate($board, $user));
        self::assertSame($match, $gate->autoMatch($board, new RemotePersonRef('p-1', 'Ana'), 'a@example.com'));
        self::assertNull($gate->autoMatch($board, new RemotePersonRef('p-1', 'Ana')));
        self::assertSame($user, $gate->candidateById($board, 'u-1'));
        self::assertFalse($gate->isAttributionOnly($board, $user), 'No attribution interface: never attribution only.');
        self::assertFalse(Ports::isAttributionOnly($gate, $board, $user));

        $attributing = new class implements SyncPersonDirectoryInterface, AttributionOnlyPolicyInterface {
            public function directoryScope(TaskBoardSnapshot $board): string
            {
                return 'x';
            }

            public function candidates(TaskBoardSnapshot $board, string $query = '', int $limit = 20): iterable
            {
                return [];
            }

            public function isCandidate(TaskBoardSnapshot $board, object $user): bool
            {
                return false;
            }

            public function autoMatch(TaskBoardSnapshot $board, RemotePerson|RemotePersonRef $person, ?string $normalizedEmail = null): ?SyncPersonMatch
            {
                return null;
            }

            public function candidateById(TaskBoardSnapshot $board, string $userId): ?object
            {
                return null;
            }

            public function isAttributionOnly(TaskBoardSnapshot $board, object $user): bool
            {
                return $board->tenantKey === 'acme';
            }
        };
        $gate = Ports::personResolver($attributing);
        self::assertInstanceOf(AttributionOnlyResolverInterface::class, $gate);
        self::assertTrue($gate->isAttributionOnly($board, $user));
        self::assertTrue(Ports::isAttributionOnly($gate, $board, $user));
    }

    public function testATwinThatThrowsMakesTheGateThrow(): void
    {
        $quota = new class implements SyncQuotaPolicyInterface {
            public function check(TaskBoardSnapshot $board, int $newTasks, int $newBytes): ?LimitRefusal
            {
                throw new LogicException('host down');
            }
        };

        $this->expectExceptionObject(new LogicException('host down'));
        Ports::limitChecker($quota)->check($this->board(), 1, 0);
    }

    private function principal(): TaskApiPrincipal
    {
        return new TaskApiPrincipal(new stdClass(), TaskApiLevel::Full, TaskApiWhere::everything(), 'tok-1', 'Token');
    }
}
