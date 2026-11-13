<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Integration\Mapping;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Mapping\NamingStrategy;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardMetadataListener;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function dirname;
use function get_object_vars;
use function is_array;
use function is_bool;
use function is_int;
use function is_object;
use function ksort;
use function sort;
use function str_starts_with;
use function sys_get_temp_dir;

use const CASE_LOWER;
use const PHP_VERSION_ID;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 2): the XML mapping of `Resources/config/doctrine-xml` gives exactly the metadata of
 * the ORM attributes, entity by entity (table, indexes, unique constraints, fields with their columns, types, lengths,
 * options and enum types, associations with their join columns, cascades and orderings, lifecycle callbacks), under the
 * default and the underscore naming strategies, with the bundle's metadata listener (table prefix, user class) applied;
 * and the schema both generate is the same.
 */
final class XmlMappingParityTest extends TestCase
{
    private const GROUPS = [
        'core'       => ['Entity', 'Nowo\\TaskBoardBundle\\Entity'],
        'sync'       => ['Sync/Entity', 'Nowo\\TaskBoardBundle\\Sync\\Entity'],
        'api'        => ['Api/Entity', 'Nowo\\TaskBoardBundle\\Api\\Entity'],
        'recurrence' => ['Recurrence/Entity', 'Nowo\\TaskBoardBundle\\Recurrence\\Entity'],
        'number'     => ['Number/Entity', 'Nowo\\TaskBoardBundle\\Number\\Entity'],
    ];

    /**
     * @return iterable<string, array{0: NamingStrategy}>
     */
    public static function namingStrategies(): iterable
    {
        yield 'default naming' => [new DefaultNamingStrategy()];
        yield 'underscore naming' => [new UnderscoreNamingStrategy(CASE_LOWER, true)];
    }

    #[DataProvider('namingStrategies')]
    public function testEveryEntityMapsTheSameFromXml(NamingStrategy $naming): void
    {
        $attributes = $this->manager($naming, false);
        $xml        = $this->manager($naming, true);
        $classes    = [];
        foreach ($attributes->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (str_starts_with($metadata->getName(), 'Nowo\\TaskBoardBundle\\Tests\\')) {
                continue; // the test host's user entity (the target of the user relations)
            }
            $classes[] = $metadata->getName();
            self::assertSame($this->normalize($metadata), $this->normalize($xml->getClassMetadata($metadata->getName())), $metadata->getName());
        }
        self::assertCount(29, $classes, 'every TaskBoard entity has an XML mapping');
        self::assertCount(30, $xml->getMetadataFactory()->getAllMetadata(), 'and nothing else (plus the test user)');

        self::assertSame($this->schema($attributes), $this->schema($xml), 'the same tables, columns, indexes and foreign keys');
    }

    /**
     * The generated schema, table by table, independent of the column order: each column's SQL declaration, the indexes
     * and the foreign keys.
     *
     * @return array<string, mixed>
     */
    private function schema(EntityManager $em): array
    {
        $platform = $em->getConnection()->getDatabasePlatform();
        $tables   = [];
        foreach ((new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata())->getTables() as $table) {
            $columns = [];
            foreach ($table->getColumns() as $column) {
                $columns[$column->getName()] = $platform->getColumnDeclarationSQL($column->getName(), $column->toArray());
            }
            ksort($columns);
            $indexes = [];
            foreach ($table->getIndexes() as $index) {
                $indexes[$index->getName()] = [$index->getColumns(), $index->isUnique(), $index->isPrimary()];
            }
            ksort($indexes);
            $keys = [];
            foreach ($table->getForeignKeys() as $key) {
                $keys[] = [$key->getLocalColumns(), $key->getForeignTableName(), $key->getForeignColumns(), $key->onDelete()];
            }
            sort($keys);
            $tables[$table->getName()] = ['columns' => $columns, 'indexes' => $indexes, 'foreignKeys' => $keys];
        }
        ksort($tables);

        return $tables;
    }

    private function manager(NamingStrategy $naming, bool $xml): EntityManager
    {
        $config = ORMSetup::createConfiguration(true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-task-board-xml-parity/proxies');
            $config->setProxyNamespace('NowoTaskBoardXmlParityProxies');
        }
        $config->setNamingStrategy($naming);
        $src   = dirname(__DIR__, 3) . '/src';
        $chain = new MappingDriverChain();
        foreach (self::GROUPS as $group => [$dir, $namespace]) {
            $driver = $xml
                ? new SimplifiedXmlDriver([$src . '/Resources/config/doctrine-xml/' . $group => $namespace])
                : new AttributeDriver([$src . '/' . $dir]);
            $chain->addDriver($driver, $namespace);
        }
        $chain->addDriver(new AttributeDriver([dirname(__DIR__, 2) . '/Functional/App/Entity']), 'Nowo\\TaskBoardBundle\\Tests\\Functional\\App\\Entity');
        $config->setMetadataDriverImpl($chain);
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new TaskBoardMetadataListener('tb_tasks', 'tb_boards', 'tb_teams', 'tb_team_members', AppUser::class));

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config, $events);
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, mixed>
     */
    private function normalize(ClassMetadata $metadata): array
    {
        return $this->plain([
            'table'              => $metadata->table,
            'identifier'         => $metadata->identifier,
            'fields'             => $metadata->fieldMappings,
            'associations'       => $metadata->associationMappings,
            'lifecycleCallbacks' => $metadata->lifecycleCallbacks,
            'repository'         => $metadata->customRepositoryClassName,
            'generator'          => $metadata->generatorType,
            'changeTracking'     => $metadata->changeTrackingPolicy,
            'readOnly'           => $metadata->isReadOnly,
        ]);
    }

    /**
     * Objects as arrays, keys sorted. The drivers spell an unset flag differently (the attributes write `false`, the XML
     * driver leaves `null`; an empty options list against none): both mean the same and are compared as `null`.
     */
    private function plain(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if ($value === false || $value === []) {
            return null;
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach (array_keys($value) as $key) {
            if ($key === 'options' && is_array($value[$key])) {
                // A column option as XML keeps it (text): `0` / `false` in an attribute is "0" in XML.
                foreach ($value[$key] as $name => $option) {
                    $value[$key][$name] = is_bool($option) ? ($option ? '1' : '0') : (is_int($option) ? (string) $option : $option);
                }
            }
            // The attribute driver numbers a single association id (0); the XML driver does not (only composite keys of
            // several associations use the position, and the bundle has none).
            $value[$key] = $key === 'idPosition' ? null : $this->plain($value[$key]);
        }
        ksort($value);

        return $value;
    }
}
