<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Board\BoardScope;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardCreatedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedBoardScopes;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Since 1.30.0 (spec 211): icon, Markdown description, slug per tenant under Advanced, the scope step.
 */
final class BoardCreationSpec211Test extends FunctionalTestCase
{
    private const CREATE = '/tools/task-board/create';

    protected function setUp(): void
    {
        ScriptedBoardScopes::reset();
        self::$taskBoardConfig = ['board' => [
            'icon'                  => true,
            'description_format'    => 'markdown',
            'advanced_slug'         => true,
            'scope_provider'        => 'app.board_scopes',
            'tenant_label_provider' => 'app.board_scopes',
        ]];
        parent::setUp();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function create(array $fields): void
    {
        $this->login($this->owner);
        $this->client->request('POST', self::CREATE, ['task_board_form' => $fields + ['_token' => PredictableCsrfTokenManager::value('task_board_form')]]);
    }

    private function boardNamed(string $name): ?TaskBoard
    {
        $this->em->clear();
        $this->refreshOwnerRow();

        return $this->em->getRepository(TaskBoard::class)->findOneBy(['name' => $name]);
    }

    private function refreshOwnerRow(): void
    {
        $owner = $this->em->find($this->owner::class, $this->owner->getId());
        self::assertNotNull($owner);
        $this->owner = $owner;
    }

    public function testAScopeResolvesTheTenantAndTheEventSaysSo(): void
    {
        ScriptedBoardScopes::$answers['org:acme'] = new TaskBoardTenant('tenant-a', 'org-a', 'Acme');
        $events                                   = [];
        self::getContainer()->get('event_dispatcher')->addListener(TaskBoardEvents::BOARD_CREATED, static function (BoardCreatedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $this->create(['name' => 'Año Nuevo', 'icon' => '🚀', 'description' => '**bold**', 'scope' => 'org', 'scopeFields' => ['org' => ['organization' => 'acme']]]);

        self::assertResponseRedirects();
        $board = $this->boardNamed('Año Nuevo');
        self::assertInstanceOf(TaskBoard::class, $board);
        self::assertSame(['tenant-a', '🚀', 'markdown', 'ano-nuevo'], [$board->getTenantKey(), $board->getIcon(), $board->getDescriptionFormat(), $board->getSlug()]);
        self::assertSame([['org', ['organization' => 'acme']]], ScriptedBoardScopes::$resolved, 'only the chosen scope\'s fields');
        self::assertCount(1, $events);
        self::assertSame('org', $events[0]->scopeId);
    }

    public function testARefusalIsOneFormError(): void
    {
        $this->create(['name' => 'Nope', 'scope' => 'org', 'scopeFields' => ['org' => ['organization' => 'other']]]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('cannot be created there', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->boardNamed('Nope'));
    }

    public function testAForgedScopeOrTargetIsRefusedBeforeTheHostIsAsked(): void
    {
        $this->create(['name' => 'Forged', 'scope' => 'admin']);
        self::assertResponseStatusCodeSame(422);
        $this->create(['name' => 'Forged', 'scope' => 'org', 'scopeFields' => ['org' => ['organization' => 'hidden']]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], ScriptedBoardScopes::$resolved);
        self::assertNull($this->boardNamed('Forged'));
    }

    public function testAnIconIsOneEmojiAndTheLimitExplainsItself(): void
    {
        foreach (['x', 'ab', '🚀🚀', '<b>', "\u{202E}🚀"] as $bad) {
            $this->create(['name' => 'Bad icon', 'icon' => $bad, 'scope' => 'personal']);
            self::assertResponseStatusCodeSame(422, $bad);
        }
        // A family: 25 bytes, one grapheme.
        $this->create(['name' => 'Bad icon', 'icon' => '👨‍👩‍👧‍👦', 'scope' => 'personal']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('several characters joined together', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->boardNamed('Bad icon'));
    }

    public function testTheStepsAndTheAdvancedFoldAreDrawn(): void
    {
        ScriptedBoardScopes::$preselected = 'org';
        $this->login($this->owner);
        $page = $this->client->request('GET', '/tools/task-board');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('#nowo-task-board-create-form[data-controller="nowo-task-board-create-steps"]'));
        self::assertSame('2', $page->filter('#nowo-task-board-create-form')->attr('data-nowo-task-board-create-steps-start-value'));
        self::assertCount(2, $page->filter('#nowo-task-board-create-form [data-testid="nowo-task-board-create-scopes"] input[type=radio]'));
        self::assertCount(1, $page->filter('#nowo-task-board-create-form [data-scope="org"] select'));
        self::assertCount(1, $page->filter('#nowo-task-board-create-form details [name="task_board_form[slug]"]'));
        self::assertCount(1, $page->filter('[data-nowo-ui-dialog-back][data-nowo-task-board-create-form="nowo-task-board-create-form"]'));
    }

    public function testOneScopeSkipsTheChoice(): void
    {
        ScriptedBoardScopes::$offered              = ['personal'];
        ScriptedBoardScopes::$answers['personal:'] = new TaskBoardTenant('tenant-b', 'user-b', 'Me');
        $this->login($this->owner);
        $page = $this->client->request('GET', '/tools/task-board');
        self::assertNotNull($page->filter('[data-testid="nowo-task-board-create-scopes"]')->attr('hidden'));

        $this->create(['name' => 'Mine']);
        self::assertResponseRedirects();
        self::assertSame('tenant-b', $this->boardNamed('Mine')?->getTenantKey());
    }

    public function testSlugsAreUniquePerTenant(): void
    {
        ScriptedBoardScopes::$answers['org:acme']  = new TaskBoardTenant('tenant-a', 'org-a', 'Acme');
        ScriptedBoardScopes::$answers['org:other'] = new TaskBoardTenant('tenant-b', 'org-b', 'Other');
        foreach (['acme', 'other', 'acme'] as $index => $target) {
            $this->create(['name' => 'Roadmap', 'scope' => 'org', 'scopeFields' => ['org' => ['organization' => $target]]]);
            self::assertResponseRedirects(null, null, 'run ' . $index);
        }
        $this->em->clear();
        $this->refreshOwnerRow();
        $slugs = [];
        foreach ($this->em->getRepository(TaskBoard::class)->findBy(['name' => 'Roadmap']) as $board) {
            $slugs[(string) $board->getTenantKey()][] = $board->getSlug();
        }
        sort($slugs['tenant-a']);
        self::assertSame(['roadmap', 'roadmap-2'], $slugs['tenant-a']);
        self::assertSame(['roadmap'], $slugs['tenant-b'], 'the other tenant\'s slug is not told');
    }

    public function testTheBoardShowsItsIconAndDescriptionAndListsTheirTenant(): void
    {
        $board = $this->board('Rocket');
        $board->setIcon('🚀')->setDescriptionFormat('markdown')->setDescription("line\n<script>x</script>")->setTenantKey('tenant-a');
        $plain = $this->board('Plain');
        $plain->setDescription('<i>plain</i>');
        $this->em->flush();
        $this->login($this->owner);

        $page = $this->client->request('GET', '/tools/task-board/board/' . $board->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('🚀', $page->filter('h1 .nowo-task-board-icon')->text());
        self::assertSame('true', $page->filter('h1 .nowo-task-board-icon')->attr('aria-hidden'));
        self::assertCount(1, $page->filter('[data-controller="nowo-task-board-description"]'));
        self::assertStringNotContainsString('<script>x</script>', (string) $this->client->getResponse()->getContent());
        foreach (['list', 'gantt'] as $view) {
            $other = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/' . $view);
            self::assertResponseIsSuccessful();
            self::assertSame('🚀', $other->filter('.page-header .small .nowo-task-board-icon')->text(), $view);
        }

        $plainPage = $this->client->request('GET', '/tools/task-board/board/' . $plain->getId());
        self::assertCount(0, $plainPage->filter('[data-controller="nowo-task-board-description"]'));
        self::assertStringContainsString('&lt;i&gt;plain&lt;/i&gt;', (string) $this->client->getResponse()->getContent());

        $index = $this->client->request('GET', '/tools/task-board');
        self::assertSame(1, ScriptedBoardScopes::$labelCalls, 'one call per list');
        $tenant = $index->filter('[data-testid="nowo-task-board-list-tenant"]');
        self::assertStringContainsString('Acme <b>', $tenant->text());
        self::assertStringContainsString('&lt;b&gt;', $tenant->html());
    }

    /** FR-001: an `org-<uuid>` tenant under its own path and `name_prefix`, and the redirect by the resolved slug. */
    public function testAnOrganizationTenantHasItsOwnPathsAndTheRedirectUsesTheResolvedSlug(): void
    {
        $org  = 'org-3f2b8c1e-0000-4000-8000-000000000001';
        $user = 'user-3f2b8c1e-0000-4000-8000-000000000002';
        self::$taskBoardConfig += ['tenancy' => ['enabled' => true, 'tenant_paths' => [
            ['path' => '/u/{tenantSlug}/tasks', 'name_prefix' => 'general_', 'slug_prefix' => 'user-'],
            ['path' => '/organizations/{tenantSlug}/tasks', 'name_prefix' => 'org_', 'slug_prefix' => 'org-'],
        ]]];
        self::ensureKernelShutdown();
        parent::setUp();
        self::assertNotNull(self::getContainer()->get('router')->getRouteCollection()->get('org_nowo_task_board_board'));
        ScriptedBoardScopes::$answers['org:acme'] = new TaskBoardTenant($org, $org, 'Acme');

        $this->login($this->owner);
        $this->client->request('POST', '/u/' . $user . '/tasks/create', ['task_board_form' => [
            'name'   => 'Org board', 'scope' => 'org', 'scopeFields' => ['org' => ['organization' => 'acme']],
            '_token' => PredictableCsrfTokenManager::value('task_board_form'),
        ]]);

        self::assertResponseRedirects();
        self::assertStringStartsWith('/organizations/' . $org . '/tasks/board/', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame($org, $this->boardNamed('Org board')?->getTenantKey());
    }

    public function testTheHostWidgetsAndWhatIsOffByDefault(): void
    {
        self::$taskBoardConfig = ['board' => ['description_form_type' => TextType::class, 'description_form_options' => ['attr' => ['data-host-editor' => '1']], 'icon' => true, 'icon_form_type' => TextType::class, 'icon_form_options' => ['attr' => ['data-host-picker' => '1']]]];
        self::ensureKernelShutdown();
        parent::setUp();
        $this->login($this->owner);
        $page = $this->client->request('GET', '/tools/task-board');
        self::assertCount(1, $page->filter('#nowo-task-board-create-form input[name="task_board_form[description]"][data-host-editor]'));
        self::assertCount(1, $page->filter('#nowo-task-board-create-form input[name="task_board_form[icon]"][data-host-picker]'));
        self::assertCount(0, $page->filter('#nowo-task-board-create-form details'));

        self::$taskBoardConfig = [];
        self::ensureKernelShutdown();
        parent::setUp();
        $this->login($this->owner);
        $plain = $this->client->request('GET', '/tools/task-board');
        self::assertCount(0, $plain->filter('[name="task_board_form[icon]"], [name="task_board_form[scope]"], [data-controller="nowo-task-board-create-steps"]'));
        $this->client->request('POST', self::CREATE, ['task_board_form' => ['name' => 'Año', '_token' => PredictableCsrfTokenManager::value('task_board_form')]]);
        $board = $this->boardNamed('Año');
        self::assertSame(['a-o', null, null], [$board?->getSlug(), $board?->getIcon(), $board?->getDescriptionFormat()], 'as before');
    }

    public function testTheLabelAndAnInvalidIconOnTheEntity(): void
    {
        $board = $this->board('Plain');
        self::assertSame('Plain', TaskBoardTwigExtension::boardLabel($board));
        $board->setIcon('🇪🇸');
        self::assertSame('🇪🇸 Plain', TaskBoardTwigExtension::boardLabel($board));
        $board->setIcon('1️⃣')->setIcon(null);
        self::assertNull($board->getIcon());
        $this->expectException(InvalidArgumentException::class);
        $board->setIcon('text');
    }

    public function testAScopeIdIsChecked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BoardScope('Not valid!', 'x');
    }
}
