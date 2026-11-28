<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\AuditKitBundle\Timeline\TimelineSourceInterface;
use Nowo\TaskBoardBundle\Bridge\AuditKit\TaskTimelineSource;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Since 3.7.0 (Beacon spec 235 T003): the task timeline source is registered, tagged for AuditKit's merger, only when
 * AuditKit's `TimelineSourceInterface` exists (a soft dependency).
 */
final class TimelineSourceConfigTest extends TestCase
{
    public function testRegisteredAndTaggedWhenAuditKitIsInstalled(): void
    {
        $container = $this->load(new TaskBoardExtension());
        self::assertTrue($container->hasDefinition(TaskTimelineSource::class));
        self::assertTrue($container->getDefinition(TaskTimelineSource::class)->hasTag(TaskBoardExtension::AUDIT_KIT_TIMELINE_SOURCE_TAG));
        self::assertSame('nowo_audit_kit.timeline_source', TaskBoardExtension::AUDIT_KIT_TIMELINE_SOURCE_TAG);
        self::assertSame(TimelineSourceInterface::class, TaskBoardExtension::AUDIT_KIT_TIMELINE_SOURCE);
    }

    public function testAbsentWithoutAuditKit(): void
    {
        $container = $this->load(new TaskBoardExtension(timelineSourceInterface: 'Nowo\Missing\TimelineSourceInterface'));
        self::assertFalse($container->hasDefinition(TaskTimelineSource::class));
    }

    private function load(TaskBoardExtension $extension): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        $extension->load([['user_class' => User::class]], $container);

        return $container;
    }
}
