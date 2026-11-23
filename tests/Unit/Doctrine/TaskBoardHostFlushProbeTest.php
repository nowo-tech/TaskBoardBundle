<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardHostFlushProbe;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardManagerRegistry;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\HostFlushProbe;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Bridge\Doctrine\ContainerAwareEventManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function count;
use function explode;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * Since 2.28.0: which flush is TaskBoard's (the caller of `flush()` past the manager, Doctrine and the shared runners),
 * the probe's own state and its resets. The flushes through a real manager are in the functional HostFlushProbeTest.
 */
final class TaskBoardHostFlushProbeTest extends TestCase
{
    public function testTheCallerOfFlushDecides(): void
    {
        $probe = new TaskBoardHostFlushProbe();
        $flush = ['function' => 'flush', 'class' => EntityManager::class];
        $head  = [['function' => 'preFlush', 'class' => TaskBoardHostFlushProbe::class], ['function' => 'dispatchEvent', 'class' => ContainerAwareEventManager::class], ['function' => 'commit', 'class' => UnitOfWork::class], $flush];

        self::assertTrue($probe->calledByTaskBoard([...$head, ['function' => 'flush', 'class' => RecoveringFlusher::class]]), 'a TaskBoard class');
        self::assertTrue($probe->calledByTaskBoard([...$head, ['function' => 'flush', 'class' => EntityManagerDecorator::class], ['function' => 'wrapInTransaction', 'class' => EntityManager::class], ['function' => 'run', 'class' => DoctrineTransactionRunner::class], ['function' => '{closure}', 'class' => TaskBoardEraser::class]]), 'past decorators, Doctrine and the shared runners');
        self::assertFalse($probe->calledByTaskBoard([...$head, ['function' => 'save', 'class' => 'App\\Service\\Orders']]), 'an application flush');
        self::assertFalse($probe->calledByTaskBoard([...$head, ['function' => 'flush', 'class' => 'Nowo\\TaskBoardBundle\\Tests\\Functional\\SomeTest']]), 'the bundle\'s own tests play the application');
        self::assertFalse($probe->calledByTaskBoard([...$head, ['function' => 'main']]), 'a plain function');
        self::assertFalse($probe->calledByTaskBoard([...$head]), 'nothing past the manager');
        self::assertFalse($probe->calledByTaskBoard([['function' => 'flush', 'class' => RecoveringFlusher::class]]), 'no flush of a manager in the frames');
    }

    public function testThePrivateManagerIsNeverProbed(): void
    {
        $private = (new ReflectionClass(TaskBoardEntityManager::class))->newInstanceWithoutConstructor();

        self::assertSame([], (new TaskBoardHostFlushProbe())->beforeFlush($private));
    }

    public function testTheEntityNamespacesAreTheBundleEntities(): void
    {
        foreach (TaskBoardHostFlushProbe::ENTITY_NAMESPACES as $namespace) {
            self::assertTrue(TaskBoardManagerRegistry::isBundleEntity($namespace . '\\X'), $namespace);
        }
        self::assertSame(1, preg_match('/\(\?:\(\?:([A-Za-z|]+)\)/', TaskBoardManagerRegistry::ENTITY_PATTERN, $match));
        self::assertCount(1 + count(explode('|', $match[1])), TaskBoardHostFlushProbe::ENTITY_NAMESPACES, 'one namespace per entity namespace of the registry');
    }

    public function testASharedProbeIsResetByItsOwnBundleOnly(): void
    {
        $shared   = new HostFlushProbe();
        $reported = new ReflectionProperty(HostFlushProbe::class, 'reported');
        $reported->setValue($shared, ['NowoTaskBoardBundle|App\\Entity\\Order' => true]);
        $probe = new TaskBoardHostFlushProbe($shared);

        $probe->reset();
        $probe->onKernelRequest($this->request(HttpKernelInterface::MAIN_REQUEST));

        self::assertNotSame([], $reported->getValue($shared), 'transaction-doctrine\'s boundary listener resets its shared probe');
    }

    public function testAnOwnProbeIsResetAtEveryMainRequestOnly(): void
    {
        $probe    = new TaskBoardHostFlushProbe();
        $own      = (new ReflectionProperty(TaskBoardHostFlushProbe::class, 'probe'))->getValue($probe);
        $reported = new ReflectionProperty(HostFlushProbe::class, 'reported');
        $reported->setValue($own, ['NowoTaskBoardBundle|App\\Entity\\Order' => true]);

        $probe->onKernelRequest($this->request(HttpKernelInterface::SUB_REQUEST));
        self::assertNotSame([], $reported->getValue($own), 'a sub-request keeps the main request\'s state');

        $probe->onKernelRequest($this->request(HttpKernelInterface::MAIN_REQUEST));
        self::assertSame([], $reported->getValue($own), 'a main request starts afresh');

        $reported->setValue($own, ['NowoTaskBoardBundle|App\\Entity\\Order' => true]);
        $probe->reset();
        self::assertSame([], $reported->getValue($own), 'kernel.reset and the bundle\'s boundaries');
    }

    public function testThePreFlushOfAnApplicationFlushReportsNothing(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            // Called from this test (the application): no flush of a manager on the stack either.
            (new TaskBoardHostFlushProbe())->preFlush(new PreFlushEventArgs($this->createStub(EntityManagerInterface::class)));
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $deprecations);
    }

    private function request(int $type): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create('/'), $type);
    }
}
