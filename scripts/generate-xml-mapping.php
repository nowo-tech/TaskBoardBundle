<?php

declare(strict_types=1);

/*
 * Since 2.15.0 (REQ-ARCH-001 rule 2): writes the Doctrine XML mapping of every TaskBoard entity from its ORM attributes,
 * into src/Resources/config/doctrine-xml/<group>/. Names the attributes leave implicit stay implicit, so the XML
 * mapping gives the same metadata as the attributes under any naming strategy (XmlMappingParityTest checks it).
 *
 * Run it after changing an entity: php scripts/generate-xml-mapping.php
 */

use Doctrine\ORM\Mapping as ORM;

require __DIR__ . '/../vendor/autoload.php';

$root   = dirname(__DIR__);
$groups = [
    'core'       => ['dir' => $root . '/src/Entity', 'namespace' => 'Nowo\\TaskBoardBundle\\Entity'],
    'sync'       => ['dir' => $root . '/src/Sync/Entity', 'namespace' => 'Nowo\\TaskBoardBundle\\Sync\\Entity'],
    'api'        => ['dir' => $root . '/src/Api/Entity', 'namespace' => 'Nowo\\TaskBoardBundle\\Api\\Entity'],
    'recurrence' => ['dir' => $root . '/src/Recurrence/Entity', 'namespace' => 'Nowo\\TaskBoardBundle\\Recurrence\\Entity'],
    'number'     => ['dir' => $root . '/src/Number/Entity', 'namespace' => 'Nowo\\TaskBoardBundle\\Number\\Entity'],
];

/** @return list<object> */
function attrs(ReflectionClass|ReflectionProperty|ReflectionMethod $r, string $class): array
{
    $out = [];
    foreach ($r->getAttributes($class, ReflectionAttribute::IS_INSTANCEOF) as $a) {
        $out[] = $a->newInstance();
    }

    return $out;
}

function attr(ReflectionClass|ReflectionProperty|ReflectionMethod $r, string $class): ?object
{
    return attrs($r, $class)[0] ?? null;
}

function x(string $s): string
{
    return htmlspecialchars($s, \ENT_XML1 | \ENT_QUOTES);
}

function b(bool $v): string
{
    return $v ? 'true' : 'false';
}

/** @param array<string, mixed> $options */
function options(array $options, string $indent): string
{
    if ($options === []) {
        return '';
    }
    $xml = $indent . "<options>\n";
    foreach ($options as $name => $value) {
        // XmlDriver keeps an option as text: a boolean default is written as the 1 / 0 the platforms store.
        $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        $xml .= $indent . '    <option name="' . x((string) $name) . '">' . x($value) . "</option>\n";
    }

    return $xml . $indent . "</options>\n";
}

function joinColumn(ORM\JoinColumn $j, string $indent): string
{
    $a = '';
    if ($j->name !== null) {
        $a .= ' name="' . x($j->name) . '"';
    }
    if ($j->referencedColumnName !== null && $j->referencedColumnName !== 'id') {
        $a .= ' referenced-column-name="' . x($j->referencedColumnName) . '"';
    }
    if ($j->unique) {
        $a .= ' unique="true"';
    }
    if ($j->nullable === false) {
        $a .= ' nullable="false"';
    }
    if ($j->onDelete !== null) {
        $a .= ' on-delete="' . x($j->onDelete) . '"';
    }

    return $indent . '<join-column' . $a . "/>\n";
}

/** @param list<string> $cascade */
function cascade(array $cascade, string $indent): string
{
    if ($cascade === []) {
        return '';
    }
    $xml = $indent . "<cascade>\n";
    foreach ($cascade as $c) {
        $xml .= $indent . '    <cascade-' . $c . "/>\n";
    }

    return $xml . $indent . "</cascade>\n";
}

foreach ($groups as $group => $g) {
    $out = $root . '/src/Resources/config/doctrine-xml/' . $group;
    @mkdir($out, 0o777, true);
    foreach (glob($out . '/*.orm.xml') ?: [] as $old) {
        unlink($old);
    }
    foreach (glob($g['dir'] . '/*.php') ?: [] as $file) {
        $class  = $g['namespace'] . '\\' . basename($file, '.php');
        $r      = new ReflectionClass($class);
        $entity = attr($r, ORM\Entity::class);
        if (!$entity instanceof ORM\Entity) {
            continue;
        }
        $table = attr($r, ORM\Table::class);
        $a     = ' name="' . x($class) . '"';
        if ($entity->repositoryClass !== null) {
            $a .= ' repository-class="' . x($entity->repositoryClass) . '"';
        }
        if ($table instanceof ORM\Table && $table->name !== null) {
            $a .= ' table="' . x($table->name) . '"';
        }
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<!-- Generated from the ORM attributes by scripts/generate-xml-mapping.php (2.15.0, REQ-ARCH-001 rule 2). Do not edit. -->\n"
            . "<doctrine-mapping xmlns=\"http://doctrine-project.org/schemas/orm/doctrine-mapping\"\n"
            . "                  xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"\n"
            . "                  xsi:schemaLocation=\"http://doctrine-project.org/schemas/orm/doctrine-mapping https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd\">\n"
            . '    <entity' . $a . ">\n";

        $indexes = attrs($r, ORM\Index::class);
        if ($indexes !== []) {
            $xml .= "        <indexes>\n";
            foreach ($indexes as $i) {
                $xml .= '            <index' . ($i->name !== null ? ' name="' . x($i->name) . '"' : '') . ($i->columns !== null ? ' columns="' . x(implode(',', $i->columns)) . '"' : '') . ($i->fields !== null ? ' fields="' . x(implode(',', $i->fields)) . '"' : '') . ($i->flags !== null ? ' flags="' . x(implode(',', $i->flags)) . '"' : '') . "/>\n";
            }
            $xml .= "        </indexes>\n";
        }
        $uniques = attrs($r, ORM\UniqueConstraint::class);
        if ($uniques !== []) {
            $xml .= "        <unique-constraints>\n";
            foreach ($uniques as $u) {
                $xml .= '            <unique-constraint' . ($u->name !== null ? ' name="' . x($u->name) . '"' : '') . ($u->columns !== null ? ' columns="' . x(implode(',', $u->columns)) . '"' : '') . ($u->fields !== null ? ' fields="' . x(implode(',', $u->fields)) . '"' : '') . "/>\n";
            }
            $xml .= "        </unique-constraints>\n";
        }

        $parts = ['id' => '', 'field' => '', 'one-to-one' => '', 'one-to-many' => '', 'many-to-one' => ''];
        foreach ($r->getProperties() as $p) {
            if ($p->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $field = $p->getName();
            $id    = attr($p, ORM\Id::class) !== null;
            if (($c = attr($p, ORM\Column::class)) instanceof ORM\Column) {
                $tag = $id ? 'id' : 'field';
                $s   = '        <' . $tag . ' name="' . x($field) . '"';
                if ($c->type !== null) {
                    $s .= ' type="' . x($c->type) . '"';
                }
                if ($c->name !== null) {
                    $s .= ' column="' . x($c->name) . '"';
                }
                if ($c->length !== null) {
                    $s .= ' length="' . $c->length . '"';
                }
                if ($c->precision !== null) {
                    $s .= ' precision="' . $c->precision . '"';
                }
                if ($c->scale !== null) {
                    $s .= ' scale="' . $c->scale . '"';
                }
                if (!$id && $c->unique) {
                    $s .= ' unique="true"';
                }
                if (!$id && $c->nullable) {
                    $s .= ' nullable="true"';
                }
                if ($c->enumType !== null) {
                    $s .= ' enum-type="' . x($c->enumType) . '"';
                }
                if ($c->columnDefinition !== null) {
                    $s .= ' column-definition="' . x($c->columnDefinition) . '"';
                }
                $opts = options($c->options, '            ');
                $gen  = attr($p, ORM\GeneratedValue::class);
                if ($opts === '' && $gen === null) {
                    $parts[$tag] .= $s . "/>\n";
                } else {
                    $parts[$tag] .= $s . ">\n" . ($gen !== null ? '            <generator strategy="' . x($gen->strategy) . "\"/>\n" : '') . $opts . '        </' . $tag . ">\n";
                }

                continue;
            }
            foreach ([ORM\ManyToOne::class => 'many-to-one', ORM\OneToOne::class => 'one-to-one'] as $type => $tag) {
                $m = attr($p, $type);
                if ($m === null) {
                    continue;
                }
                $s = '        <' . $tag . ' field="' . x($field) . '" target-entity="' . x(ltrim((string) $m->targetEntity, '\\')) . '"';
                if ($m->inversedBy !== null) {
                    $s .= ' inversed-by="' . x($m->inversedBy) . '"';
                }
                if ($m instanceof ORM\OneToOne && $m->mappedBy !== null) {
                    $s .= ' mapped-by="' . x($m->mappedBy) . '"';
                }
                if ($m->fetch !== 'LAZY') {
                    $s .= ' fetch="' . x($m->fetch) . '"';
                }
                if ($m instanceof ORM\OneToOne && $m->orphanRemoval) {
                    $s .= ' orphan-removal="true"';
                }
                if ($id) {
                    $s .= ' id="true"';
                }
                $inner = cascade($m->cascade ?? [], '            ');
                foreach (attrs($p, ORM\JoinColumn::class) as $j) {
                    $inner .= joinColumn($j, '            ');
                }
                $parts[$tag] .= $inner === '' ? $s . "/>\n" : $s . ">\n" . $inner . '        </' . $tag . ">\n";
            }
            if (($m = attr($p, ORM\OneToMany::class)) instanceof ORM\OneToMany) {
                $s = '        <one-to-many field="' . x($field) . '" target-entity="' . x(ltrim((string) $m->targetEntity, '\\')) . '" mapped-by="' . x((string) $m->mappedBy) . '"';
                if ($m->orphanRemoval) {
                    $s .= ' orphan-removal="true"';
                }
                if ($m->fetch !== 'LAZY') {
                    $s .= ' fetch="' . x($m->fetch) . '"';
                }
                if ($m->indexBy !== null) {
                    $s .= ' index-by="' . x($m->indexBy) . '"';
                }
                $inner = cascade($m->cascade ?? [], '            ');
                if (($o = attr($p, ORM\OrderBy::class)) instanceof ORM\OrderBy) {
                    $inner .= "            <order-by>\n";
                    foreach ($o->value as $f => $dir) {
                        $inner .= '                <order-by-field name="' . x((string) $f) . '" direction="' . x(match (true) {
                            $dir instanceof BackedEnum => (string) $dir->value,
                            $dir instanceof UnitEnum   => $dir->name === 'Descending' ? 'DESC' : 'ASC',
                            default                    => (string) $dir,
                        }) . "\"/>\n";
                    }
                    $inner .= "            </order-by>\n";
                }
                $parts['one-to-many'] .= $inner === '' ? $s . "/>\n" : $s . ">\n" . $inner . "        </one-to-many>\n";
            }
            if (attr($p, ORM\ManyToMany::class) !== null) {
                throw new LogicException('ManyToMany is not generated: ' . $class . '::$' . $field);
            }
        }

        $callbacks = '';
        foreach ($r->getMethods() as $m) {
            foreach ([ORM\PrePersist::class => 'prePersist', ORM\PreUpdate::class => 'preUpdate', ORM\PreRemove::class => 'preRemove', ORM\PostPersist::class => 'postPersist', ORM\PostUpdate::class => 'postUpdate', ORM\PostRemove::class => 'postRemove', ORM\PostLoad::class => 'postLoad'] as $type => $event) {
                if (attr($m, $type) !== null) {
                    $callbacks .= '            <lifecycle-callback type="' . $event . '" method="' . x($m->getName()) . "\"/>\n";
                }
            }
        }
        if ($callbacks !== '') {
            $xml .= "        <lifecycle-callbacks>\n" . $callbacks . "        </lifecycle-callbacks>\n";
        }
        $xml .= implode('', $parts) . "    </entity>\n</doctrine-mapping>\n";
        // SimplifiedXmlDriver: the class name relative to the mapping prefix.
        file_put_contents($out . '/' . $r->getShortName() . '.orm.xml', $xml);
        echo $group, ': ', $class, "\n";
    }
}
