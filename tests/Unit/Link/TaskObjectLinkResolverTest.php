<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Link;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Link\TaskObjectBadge;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolver;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolverInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

use function count;

final class TaskObjectLinkResolverTest extends TestCase
{
    private Task $task;

    protected function setUp(): void
    {
        $this->task = new Task(new TaskBoard('Board', 'board', new stdClass()), 'Fix it', new stdClass());
    }

    public function testHostObjectLinkAsksTheSupportingResolver(): void
    {
        $resolver = new TaskObjectLinkResolver([$this->resolver('order', null, null), $this->resolver('issue', 'BEACON-12 Crash', '/issues/12')]);
        $link     = TaskLink::forHostObject($this->task, 'issue', '12', 'stored');

        self::assertSame('BEACON-12 Crash', $resolver->label($link));
        self::assertSame('/issues/12', $resolver->url($link));
        self::assertEquals(new TaskObjectBadge('Open', 'danger'), $resolver->badge($link));
    }

    public function testHostObjectLinkFallsBackToTheStoredLabelOrTypeAndId(): void
    {
        $resolver = new TaskObjectLinkResolver([$this->resolver('issue', '', '')]);

        self::assertSame('stored', $resolver->label(TaskLink::forHostObject($this->task, 'issue', '12', 'stored')));
        self::assertSame('issue #12', $resolver->label(TaskLink::forHostObject($this->task, 'issue', '12')));
        self::assertNull($resolver->url(TaskLink::forHostObject($this->task, 'issue', '12')));
        // No resolver supports the type.
        self::assertSame('ticket #9', $resolver->label(TaskLink::forHostObject($this->task, 'ticket', '9')));
        self::assertNull($resolver->badge(TaskLink::forHostObject($this->task, 'ticket', '9')));
    }

    public function testOtherLinksUseTheirStoredLabelAndUrl(): void
    {
        $resolver = new TaskObjectLinkResolver();
        $link     = new TaskLink($this->task, TaskLinkType::Url, 'https://example.com', 'Docs');

        self::assertSame('Docs', $resolver->label($link));
        self::assertSame('https://example.com', $resolver->url($link));
        self::assertNull($resolver->badge($link));
        self::assertSame('https://example.com', $resolver->label(new TaskLink($this->task, TaskLinkType::Url, 'https://example.com')));
        // A HostObject type without an object is not a host object link.
        self::assertNull($resolver->badge(new TaskLink($this->task, TaskLinkType::HostObject, '')));
    }

    public function testHostObjectEntityHelpers(): void
    {
        $link = TaskLink::forHostObject($this->task, 'issue', '12');

        self::assertSame(TaskLinkType::HostObject, $link->getLinkType());
        self::assertSame('', $link->getUrl());
        self::assertSame('issue', $link->getObjectType());
        self::assertSame('12', $link->getObjectId());
        self::assertTrue($link->isHostObject());
        self::assertTrue($link->isHostObjectOf('issue', '12'));
        self::assertFalse($link->isHostObjectOf('issue', '13'));
        self::assertFalse((new TaskLink($this->task, TaskLinkType::Url, 'https://example.com'))->isHostObject());
        self::assertNotContains(TaskLinkType::HostObject, TaskLinkType::userChoices());
        self::assertCount(count(TaskLinkType::cases()) - 1, TaskLinkType::userChoices());
    }

    private function resolver(string $type, ?string $label, ?string $url): TaskObjectLinkResolverInterface
    {
        return new class($type, $label, $url) implements TaskObjectLinkResolverInterface {
            public function __construct(private readonly string $type, private readonly ?string $label, private readonly ?string $url)
            {
            }

            public function supports(string $objectType): bool
            {
                return $objectType === $this->type;
            }

            public function label(string $objectType, string $objectId): ?string
            {
                return $this->label;
            }

            public function url(string $objectType, string $objectId): ?string
            {
                return $this->url;
            }

            public function badge(string $objectType, string $objectId): TaskObjectBadge
            {
                return new TaskObjectBadge('Open', 'danger');
            }
        };
    }
}
