<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Port;

use LogicException;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentsInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentStoreInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRequestAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntriesInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeStoreInterface;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneePolicyInterface;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Comment\TaskCommentCauseLabelerInterface;
use Nowo\TaskBoardBundle\Comment\TaskCommentCauseTextInterface;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesProviderInterface;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\History\TaskChangeCauseLabelerInterface;
use Nowo\TaskBoardBundle\History\TaskChangeCauseTextInterface;
use Nowo\TaskBoardBundle\Import\TaskImportLinkFinderInterface;
use Nowo\TaskBoardBundle\Import\TaskImportLinkLookupInterface;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetIdsInterface;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetsInterface;
use Nowo\TaskBoardBundle\Port\Ports;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntryReceiverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntrySinkInterface;
use Nowo\TaskBoardBundle\Sync\Hook\InlineImageRewriterInterface;
use Nowo\TaskBoardBundle\Sync\Hook\PeopleInvitationInterface;
use Nowo\TaskBoardBundle\Sync\Hook\PeopleInviterInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorProviderInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncedFileStorageInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncedImageRewriterInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonDirectoryInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncQuotaPolicyInterface;
use Nowo\TaskBoardBundle\Sync\Hook\TaskAttachmentStorageInterface;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisionerInterface;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisioningInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskCommentSnapshot;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewPolicyInterface;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 2.15.0: the selection rule of every port. A service implementing the deprecated interface is returned as it is
 * (asked exactly as in 2.14), also when it implements the twin too; one implementing only the twin is wrapped in the
 * twin's gate; one implementing neither fails closed.
 */
final class PortsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: class-string, 2: class-string}>
     */
    public static function ports(): iterable
    {
        yield 'assignee' => ['assigneeProvider', TaskAssigneeProviderInterface::class, TaskAssigneePolicyInterface::class];
        yield 'comment cause' => ['commentCauseLabeler', TaskCommentCauseLabelerInterface::class, TaskCommentCauseTextInterface::class];
        yield 'mentions' => ['mentionCandidates', TaskMentionCandidatesResolverInterface::class, TaskMentionCandidatesProviderInterface::class];
        yield 'change cause' => ['changeCauseLabeler', TaskChangeCauseLabelerInterface::class, TaskChangeCauseTextInterface::class];
        yield 'import links' => ['importLinkLookup', TaskImportLinkLookupInterface::class, TaskImportLinkFinderInterface::class];
        yield 'targets' => ['boardTargets', TaskBoardTargetsInterface::class, TaskBoardTargetIdsInterface::class];
        yield 'default view' => ['defaultViewResolver', TaskBoardDefaultViewResolverInterface::class, TaskBoardDefaultViewPolicyInterface::class];
        yield 'first board' => ['firstBoardProvisioner', FirstBoardProvisionerInterface::class, FirstBoardProvisioningInterface::class];
        yield 'authenticator' => ['apiAuthenticator', TaskApiAuthenticatorInterface::class, TaskApiRequestAuthenticatorInterface::class];
        yield 'attachments' => ['apiAttachments', TaskApiAttachmentsInterface::class, TaskApiAttachmentStoreInterface::class];
        yield 'time entries' => ['apiTimeEntries', TaskApiTimeEntriesInterface::class, TaskApiTimeStoreInterface::class];
        yield 'time sink' => ['timeEntrySink', ImportedTimeEntrySinkInterface::class, ImportedTimeEntryReceiverInterface::class];
        yield 'image rewriter' => ['inlineImageRewriter', InlineImageRewriterInterface::class, SyncedImageRewriterInterface::class];
        yield 'inviter' => ['peopleInviter', PeopleInviterInterface::class, PeopleInvitationInterface::class];
        yield 'credentials' => ['credentialResolver', ProviderCredentialResolverInterface::class, ProviderCredentialSourceInterface::class];
        yield 'actor' => ['actorResolver', SyncActorResolverInterface::class, SyncActorProviderInterface::class];
        yield 'limits' => ['limitChecker', SyncLimitCheckerInterface::class, SyncQuotaPolicyInterface::class];
        yield 'people' => ['personResolver', SyncPersonResolverInterface::class, SyncPersonDirectoryInterface::class];
        yield 'file storage' => ['attachmentStorage', TaskAttachmentStorageInterface::class, SyncedFileStorageInterface::class];
    }

    /**
     * @param class-string $legacy
     * @param class-string $twin
     */
    #[DataProvider('ports')]
    public function testDeprecatedInterfaceIsAskedAsItIs(string $factory, string $legacy, string $twin): void
    {
        $service = $this->createMock($legacy);

        self::assertSame($service, $this->select($factory, $service));
    }

    public function testServiceImplementingBothIsAskedThroughTheDeprecatedInterface(): void
    {
        $both = new class implements TaskCommentCauseLabelerInterface, TaskCommentCauseTextInterface {
            public function label(string $cause, TaskComment|TaskCommentSnapshot $comment): ?string
            {
                return null;
            }
        };

        self::assertSame($both, Ports::commentCauseLabeler($both));
    }

    /**
     * @param class-string $legacy
     * @param class-string $twin
     */
    #[DataProvider('ports')]
    public function testTwinIsAskedThroughAGateOfTheDeprecatedInterface(string $factory, string $legacy, string $twin): void
    {
        $service = $this->createMock($twin);
        $gate    = $this->select($factory, $service);

        self::assertNotSame($service, $gate);
        self::assertInstanceOf($legacy, $gate);
    }

    /**
     * @param class-string $legacy
     * @param class-string $twin
     */
    #[DataProvider('ports')]
    public function testNeitherFailsClosed(string $factory, string $legacy, string $twin): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement "' . $twin . '"');

        $this->select($factory, new stdClass());
    }

    private function select(string $factory, object $service): object
    {
        return match ($factory) {
            'boardTargets', 'firstBoardProvisioner' => Ports::{$factory}($service, $this->createStub(TaskBoardRepositoryInterface::class)),
            default                                 => Ports::{$factory}($service),
        };
    }
}
