<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Form\AbstractType;

use function dirname;

/**
 * REQ-TWIG-005 rule 11: every page form type of the bundle is a FormKit Type on the `task_board` profile.
 */
final class FormKitTypesTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function formTypes(): iterable
    {
        foreach (glob(dirname(__DIR__, 3) . '/src/Form/*Type.php') ?: [] as $file) {
            $class = 'Nowo\\TaskBoardBundle\\Form\\' . basename($file, '.php');
            if (is_subclass_of($class, AbstractType::class) && !(new ReflectionClass($class))->isAbstract()) {
                yield basename($file, '.php') => [$class];
            }
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('formTypes')]
    public function testFormTypeUsesTheTaskBoardProfile(string $class): void
    {
        $type = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        self::assertSame('task_board', FormKitConfig::nameFrom($type));
    }

    public function testEveryFormTypeIsListed(): void
    {
        self::assertGreaterThanOrEqual(14, iterator_count(self::formTypes()));
    }
}
