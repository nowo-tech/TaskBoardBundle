<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Form;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\TagInputBundle\Form\TagType;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Form\BoardColumnFormType;
use Nowo\TaskBoardBundle\Form\DataTransformer\TagsToStringTransformer;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Form\TaskLinkFormType;
use Nowo\TiptapEditorBundle\Form\TiptapEditorType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class FormTypesTest extends TestCase
{
    public function testTaskFormFallsBackToPlainTypesWithoutOptionalBundles(): void
    {
        $form = $this->factory()->create(TaskFormType::class, new TaskFormData(), [
            'rich_description' => false,
            'tag_input'        => false,
        ]);

        self::assertInstanceOf(TextareaType::class, $form->get('description')->getConfig()->getType()->getInnerType());
        self::assertInstanceOf(TextType::class, $form->get('tags')->getConfig()->getType()->getInnerType());

        $form->submit(['title' => 'Task', 'description' => 'Plain', 'priority' => 'normal', 'tags' => ' api, ui ,api,, ']);

        self::assertTrue($form->isSynchronized());
        /** @var TaskFormData $data */
        $data = $form->getData();
        self::assertSame(['api', 'ui'], $data->tags);
        self::assertSame('Plain', $data->description);
    }

    public function testTaskFormDefaultsFollowInstalledBundles(): void
    {
        $form = $this->factory()->create(TaskFormType::class, new TaskFormData());

        self::assertSame(
            class_exists(TiptapEditorType::class) ? TiptapEditorType::class : TextareaType::class,
            $form->get('description')->getConfig()->getType()->getInnerType()::class,
        );
        self::assertSame(
            class_exists(TagType::class) ? TagType::class : TextType::class,
            $form->get('tags')->getConfig()->getType()->getInnerType()::class,
        );
    }

    public function testTaskFormUsesRichTypesWhenInstalled(): void
    {
        require_once __DIR__ . '/../../Fixtures/OptionalFormTypes.php';

        $form = $this->factory()->create(TaskFormType::class, new TaskFormData(), [
            'rich_description' => true,
            'tag_input'        => true,
        ]);

        self::assertInstanceOf(TiptapEditorType::class, $form->get('description')->getConfig()->getType()->getInnerType());
        self::assertInstanceOf(TagType::class, $form->get('tags')->getConfig()->getType()->getInnerType());
    }

    public function testTagsTransformer(): void
    {
        $transformer = new TagsToStringTransformer();

        self::assertSame('a, b', $transformer->transform(['a', ' b ', '', ['x']]));
        self::assertSame('', $transformer->transform(null));
        self::assertSame([], $transformer->reverseTransform(null));
        self::assertSame(['a', 'b'], $transformer->reverseTransform('a,b,a'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function linkUrls(): iterable
    {
        yield 'https' => ['https://gitlab.example.com/group/project/-/merge_requests/1', true];
        yield 'http intranet without tld' => ['http://gitlab/group/project', true];
        yield 'javascript' => ['javascript:alert(document.cookie)', false];
        yield 'javascript mixed case' => ['JaVaScRiPt:alert(1)', false];
        yield 'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==', false];
        yield 'vbscript' => ['vbscript:msgbox(1)', false];
        yield 'ftp' => ['ftp://example.com/file', false];
    }

    #[DataProvider('linkUrls')]
    public function testLinkUrlOnlyAcceptsHttpAndHttps(string $url, bool $valid): void
    {
        $form = $this->factory()->create(TaskLinkFormType::class, new TaskLinkFormData());
        $form->submit(['linkType' => 'url', 'url' => $url]);

        self::assertSame($valid, $form->isValid(), (string) $form->getErrors(true));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function columnColors(): iterable
    {
        yield 'rrggbb' => ['#3b82f6', true];
        yield 'rgb' => ['#fff', true];
        yield 'rrggbbaa' => ['#3b82f680', true];
        yield 'css injection' => ['red;background:url(https://evil.example/x)', false];
        yield 'quote breakout' => ['#fff" onmouseover="alert(1)', false];
        yield 'named colour' => ['red', false];
        yield 'trailing payload after newline' => ["#ffffff\n;background:red", false];
    }

    #[DataProvider('columnColors')]
    public function testColumnColorOnlyAcceptsHex(string $color, bool $valid): void
    {
        $form = $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData());
        $form->submit(['name' => 'Todo', 'color' => $color]);

        self::assertSame($valid, $form->isValid(), (string) $form->getErrors(true));
    }

    public function testColumnColorIsOptional(): void
    {
        $form = $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData());
        $form->submit(['name' => 'Todo', 'color' => '']);

        self::assertTrue($form->isValid());
    }

    private function factory(): FormFactoryInterface
    {
        $profile = [
            'translation_domain' => 'NowoTaskBoardBundle',
            'defaults'           => ['attr' => [], 'row_attr' => []],
            'field_types'        => [],
        ];
        $merger = new FormOptionsMerger(['default' => $profile, 'task_board' => $profile], 'default', new ConstraintDefinitionFactory());
        $types  = [new TaskFormType(), new TaskLinkFormType(), new BoardColumnFormType()];
        foreach ($types as $type) {
            $type->setFormOptionsMerger($merger);
        }

        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addTypes($types)
            ->getFormFactory();
    }
}
