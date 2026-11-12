<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepositoryInterface;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Repository\RepositoryFactory;
use Nowo\TaskBoardBundle\Doctrine\PrivateManagerRepositoryFactory;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 2.15.0 (audit NZ's corrected pattern): the private manager's own repository factory. A service repository comes
 * from the source configuration's factory (the container service); every other repository is built once per manager.
 */
final class PrivateManagerRepositoryFactoryTest extends TestCase
{
    public function testAServiceRepositoryComesFromTheSourceFactory(): void
    {
        $metadata                            = new ClassMetadata(stdClass::class);
        $metadata->customRepositoryClassName = ServiceLikeRepository::class;
        $manager                             = $this->createStub(EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $service = new ServiceLikeRepository($manager, $metadata);
        $source  = $this->createMock(RepositoryFactory::class);
        $source->expects(self::exactly(2))->method('getRepository')->with($manager, stdClass::class)->willReturn($service);

        $factory = new PrivateManagerRepositoryFactory($source);

        self::assertSame($service, $factory->getRepository($manager, stdClass::class));
        self::assertSame($service, $factory->getRepository($manager, stdClass::class), 'asked again: the source keeps it, not this factory');
    }

    public function testOtherRepositoriesAreBuiltOnceForTheManager(): void
    {
        $metadata      = new ClassMetadata(stdClass::class);
        $configuration = $this->createStub(Configuration::class);
        $configuration->method('getDefaultRepositoryClassName')->willReturn(EntityRepository::class);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $manager->method('getConfiguration')->willReturn($configuration);
        $source = $this->createMock(RepositoryFactory::class);
        $source->expects(self::never())->method('getRepository');

        $factory    = new PrivateManagerRepositoryFactory($source);
        $repository = $factory->getRepository($manager, stdClass::class);

        self::assertSame(EntityRepository::class, $repository::class);
        self::assertSame($repository, $factory->getRepository($manager, stdClass::class));

        $metadata->customRepositoryClassName = PlainCustomRepository::class;
        $custom                              = (new PrivateManagerRepositoryFactory($source))->getRepository($manager, stdClass::class);
        self::assertInstanceOf(PlainCustomRepository::class, $custom, 'a mapped repository class that is no service');
    }
}

/**
 * @extends EntityRepository<stdClass>
 */
final class ServiceLikeRepository extends EntityRepository implements ServiceEntityRepositoryInterface
{
}

/**
 * @extends EntityRepository<stdClass>
 */
final class PlainCustomRepository extends EntityRepository
{
}
