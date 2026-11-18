<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Form;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\TagInputBundle\Form\TagType;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Dto\TaskQuestionFormData;
use Nowo\TaskBoardBundle\Form\BoardColumnFormType;
use Nowo\TaskBoardBundle\Form\DataTransformer\TagsToStringTransformer;
use Nowo\TaskBoardBundle\Form\TaskBoardFormType;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Form\TaskLinkFormType;
use Nowo\TaskBoardBundle\Form\TaskQuestionFormType;
use Nowo\TaskBoardBundle\Question\TaskQuestionRecipientCandidate;
use Nowo\TiptapEditorBundle\Form\TiptapEditorType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormErrorIterator;
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

    /** Since 2.23.0: the question's context is a plain textarea without Tiptap, the task profile's editor with it. */
    public function testTheQuestionContextUsesTiptapWhenInstalled(): void
    {
        $plain = $this->factory()->create(TaskQuestionFormType::class, new TaskQuestionFormData(), ['rich_context' => false]);
        self::assertInstanceOf(TextareaType::class, $plain->get('context')->getConfig()->getType()->getInnerType());

        require_once __DIR__ . '/../../Fixtures/OptionalFormTypes.php';
        $rich = $this->factory()->create(TaskQuestionFormType::class, new TaskQuestionFormData(), ['rich_context' => true]);
        self::assertInstanceOf(TiptapEditorType::class, $rich->get('context')->getConfig()->getType()->getInnerType());
        self::assertSame(TaskBoardExtension::TIPTAP_TASK_PROFILE, $rich->get('context')->getConfig()->getOption('config'));
    }

    /** Audit TE-L4: two candidates with the same name stay two choices, each keyed by its id. */
    public function testTheQuestionRecipientsAreKeyedByIdNotByName(): void
    {
        $candidates = [new TaskQuestionRecipientCandidate('user:1', 'Alex Kim'), new TaskQuestionRecipientCandidate('user:2', 'Alex Kim', true)];
        $form       = $this->factory()->create(TaskQuestionFormType::class, new TaskQuestionFormData(recipients: ['user:2', 'user:9']), ['recipients' => $candidates]);
        $view       = $form->createView();
        self::assertSame(['user:1', 'user:2'], array_map(static fn ($choice): string => $choice->value, $view['recipients']->vars['choices']));
        self::assertSame(['user:2'], array_map(static fn (TaskQuestionRecipientCandidate $c): string => $c->id, $form->get('recipients')->getNormData()), 'an id no longer offered is left out');
        self::assertSame('1', $view['recipients']->children[1]->vars['attr']['data-nowo-task-question-external'] ?? null);

        $form = $this->factory()->create(TaskQuestionFormType::class, new TaskQuestionFormData(), ['recipients' => $candidates]);
        $form->submit(['text' => 'Q', 'choiceMode' => 'single', 'recipients' => ['user:1']]);
        /** @var TaskQuestionFormData $data */
        $data = $form->getData();
        self::assertSame(['user:1'], $data->recipients);
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

    /** Since 2.9.0: `board.column_icon_form_type` replaces the grid; the bundle's constraint applies to that widget too. */
    public function testAColumnIconWidgetTakesOneEmojiOrAKnownId(): void
    {
        self::assertFalse($this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData())->has('icon'), 'no icons, no widget: as before');
        $grid = $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData(), ['icon_choices' => ['tabler:bug']]);
        self::assertArrayHasKey('choices', $grid->createView()['icon']->vars);

        foreach (['🚧' => true, 'tabler:bug' => true, 'tabler:inbox' => true, 'tabler:x' => false, '<b>x</b>' => false, 'Doing' => false, '' => true] as $icon => $valid) {
            $form = $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData(), [
                'icon_choices'         => ['tabler:bug'],
                'icon_form_type'       => TextType::class,
                'icon_form_options'    => ['attr' => ['data-host' => '1']],
                'icon_known_ids'       => ['tabler:bug', 'tabler:inbox'],
                'icon_invalid_message' => 'Bad icon',
            ]);
            self::assertArrayNotHasKey('choices', $form->createView()['icon']->vars);
            self::assertSame('1', $form->createView()['icon']->vars['attr']['data-host']);
            $form->submit(['name' => 'Doing', 'icon' => $icon]);
            self::assertSame($valid, $form->isValid(), $icon);
            self::assertSame($valid ? [] : ['Bad icon'], array_map(static fn (\Symfony\Component\Form\FormError|FormErrorIterator $e): string => $e->getMessage(), iterator_to_array($form->get('icon')->getErrors())));
        }

        // Audit JM-L1: the edited column's own icon is a choice of the grid, only when storable and not offered already.
        $grid = $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData(), ['icon_choices' => ['tabler:bug'], 'icon_current' => '📝']);
        self::assertContains('📝', array_map(static fn ($choice): string => (string) $choice->value, $grid->createView()['icon']->vars['choices']));
        $grid->submit(['name' => 'Doing', 'icon' => '📝']);
        self::assertTrue($grid->isValid());
        foreach (['tabler:bug', ''] as $current) {
            $form = $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData(), ['icon_choices' => ['tabler:bug'], 'icon_current' => $current]);
            self::assertCount(1, $form->createView()['icon']->vars['choices'], $current);
        }

        // Audit JM-I7: a stored icon that is no longer storable is still posted back by a rename (the update drops it).
        foreach (['fa fa-bug', '<b>x</b>'] as $current) {
            $form = $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData(), ['icon_choices' => ['tabler:bug'], 'icon_current' => $current]);
            $form->submit(['name' => 'Renamed', 'icon' => $current]);
            self::assertTrue($form->isValid(), $current);
            self::assertSame($current, $form->getData()->icon);
        }
        $form = $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData(), ['icon_choices' => ['tabler:bug'], 'icon_current' => 'fa fa-bug']);
        $form->submit(['name' => 'Renamed', 'icon' => 'fa fa-star']);
        self::assertFalse($form->isValid(), 'only the column\'s own value');

        // Audit JM-I8: a legacy bare current icon whose name is an offered icon's label does not replace that choice.
        $form    = $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData(), ['icon_choices' => ['tabler:bug'], 'icon_current' => 'bug']);
        $choices = [];
        foreach ($form->createView()['icon']->vars['choices'] as $choice) {
            $choices[(string) $choice->label] = (string) $choice->value;
        }
        self::assertSame(['bug' => 'tabler:bug', BoardColumnFormType::CURRENT_ICON_LABEL_PREFIX . 'bug' => 'bug'], $choices);
        foreach (['tabler:bug', 'bug'] as $icon) {
            $form = $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData(), ['icon_choices' => ['tabler:bug'], 'icon_current' => 'bug']);
            $form->submit(['name' => 'Renamed', 'icon' => $icon]);
            self::assertTrue($form->isValid(), $icon);
            self::assertSame($icon, $form->getData()->icon);
        }

        // Without icon_known_ids the offered icons are board.column_icons.
        $form = $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData(), ['icon_choices' => ['tabler:bug'], 'icon_form_type' => TextType::class]);
        $form->submit(['name' => 'Doing', 'icon' => 'tabler:inbox']);
        self::assertFalse($form->isValid());
        self::assertNull((new BoardColumnFormData())->icon);
    }

    public function testColumnColorIsOptional(): void
    {
        $form = $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData());
        $form->submit(['name' => 'Todo', 'color' => '']);

        self::assertTrue($form->isValid());
    }

    public function testBoardSlugIsOptional(): void
    {
        $form = $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData());
        $form->submit(['name' => 'Release', 'slug' => '', 'description' => '']);

        self::assertTrue($form->isValid());
        self::assertSame('', $form->getData()->slug);
    }

    /** @return iterable<string, array{string, bool, ?string}> */
    public static function taskColors(): iterable
    {
        yield 'none' => ['', true, null];
        yield 'rrggbb' => ['#3B82F6', true, '#3B82F6'];
        yield 'short hex' => ['#fff', false, null];
        yield 'css injection' => ['red;background:url(https://evil.example/x)', false, null];
        yield 'trailing payload after newline' => ["#ffffff\n;x", false, null];
    }

    #[DataProvider('taskColors')]
    public function testTaskColorIsAnOptionalHexColour(string $color, bool $valid, ?string $expected): void
    {
        $form = $this->factory()->create(TaskFormType::class, new TaskFormData(), ['rich_description' => false, 'tag_input' => false]);
        self::assertInstanceOf(ColorType::class, $form->get('customColor')->getConfig()->getType()->getInnerType());
        self::assertFalse($form->get('customColor')->getConfig()->getMapped());

        $form->submit(['title' => 'Task', 'priority' => 'normal', 'color' => $color, 'customColor' => '#000000']);

        self::assertSame($valid, $form->isValid(), (string) $form->getErrors(true));
        if ($valid) {
            /** @var TaskFormData $data */
            $data = $form->getData();
            self::assertSame($expected, $data->color);
        }
    }

    public function testCompactTaskFormHasNoColour(): void
    {
        $form = $this->factory()->create(TaskFormType::class, new TaskFormData(), ['compact' => true]);

        self::assertFalse($form->has('color'));
        self::assertFalse($form->has('customColor'));
    }

    private function factory(): FormFactoryInterface
    {
        $profile = [
            'translation_domain' => 'NowoTaskBoardBundle',
            'defaults'           => ['attr' => [], 'row_attr' => []],
            'field_types'        => [],
        ];
        $merger = new FormOptionsMerger(['default' => $profile, 'task_board' => $profile], 'default', new ConstraintDefinitionFactory());
        $types  = [new TaskFormType(), new TaskLinkFormType(), new BoardColumnFormType(), new TaskBoardFormType(), new TaskQuestionFormType()];
        foreach ($types as $type) {
            $type->setFormOptionsMerger($merger);
        }

        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addTypes($types)
            ->getFormFactory();
    }
}
