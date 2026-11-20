<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Integration\Mapping;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardMetadataListener;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SortDirection;

use function dirname;
use function implode;
use function restore_error_handler;
use function set_error_handler;
use function sys_get_temp_dir;

use const E_USER_DEPRECATED;
use const PHP_VERSION_ID;

/**
 * doctrine/orm 3.6+ wants a SortDirection for the direction of an ordered association (orm issue 11313; a string is a
 * deprecation). The question associations are ordered through the attribute and the XML mapping alike: both load
 * without a deprecation and keep their order.
 */
final class QuestionOrderByTest extends TestCase
{
    /**
     * @return iterable<string, array{0: bool}>
     */
    public static function drivers(): iterable
    {
        yield 'attributes' => [false];
        yield 'xml' => [true];
    }

    #[DataProvider('drivers')]
    public function testTheOrderedAssociationsLoadWithoutDeprecationAndKeepTheirOrder(bool $xml): void
    {
        $messages = [];
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        Deprecation::enableWithTriggerError();
        Deprecation::withoutDeduplication();

        try {
            $metadata = $this->manager($xml)->getClassMetadata(TaskQuestion::class);
        } finally {
            Deprecation::disable(); // also restores the deduplication
            restore_error_handler();
        }

        self::assertSame([], $messages, 'No deprecation while the mapping loads: ' . implode("\n", $messages));
        self::assertSame(['position' => SortDirection::Ascending], $metadata->getAssociationMapping('options')->orderBy());
        self::assertSame(['position' => SortDirection::Ascending], $metadata->getAssociationMapping('recipients')->orderBy());
        self::assertSame(['sequence' => SortDirection::Ascending], $metadata->getAssociationMapping('answers')->orderBy());
    }

    private function manager(bool $xml): EntityManager
    {
        $config = ORMSetup::createConfiguration(true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-task-board-question-order/proxies');
            $config->setProxyNamespace('NowoTaskBoardQuestionOrderProxies');
        }
        $src    = dirname(__DIR__, 3) . '/src';
        $driver = $this->driver($xml, $src);
        $config->setMetadataDriverImpl($driver);
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new TaskBoardMetadataListener('tb_tasks', 'tb_boards', 'tb_teams', 'tb_team_members', AppUser::class));

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config, $events);
    }

    private function driver(bool $xml, string $src): MappingDriver
    {
        return $xml
            ? new SimplifiedXmlDriver([$src . '/Resources/config/doctrine-xml/question' => 'Nowo\\TaskBoardBundle\\Question\\Entity'])
            : new AttributeDriver([$src . '/Question/Entity']);
    }
}
