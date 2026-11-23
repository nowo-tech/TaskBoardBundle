<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Question;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionRecipient;
use Nowo\TaskBoardBundle\Question\TaskQuestionChoiceMode;
use Nowo\TaskBoardBundle\Question\TaskQuestionView;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\MemberTenantResolver;
use Nowo\TaskBoardBundle\Tests\Functional\App\TenantCreateChecker;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;
use function preg_replace;
use function sort;

/**
 * Since 2.23.1 (Beacon request, security): *My open questions* under tenancy denies by default. 2.23.0 read a tenant URL
 * whose tenant does not resolve for the viewer (unknown, or one they are not a member of) as "no tenant" and listed the
 * open questions of every board their access checks let through: with a host that grants view access by its own rules, a
 * non-member of a project got a 200 listing that project's questions.
 *
 * Tenants: `acme` (owner and bob), `globex` (bob), `hidden` (bob; refused by the tenant-aware checker's board list in
 * {@see self::testAShareeSeesTheirQuestionsInATenantTheyMayNotList()}), `other` (nobody). dave is a member of none.
 */
final class TaskQuestionTenancyTest extends QuestionTestCase
{
    private const TO_ANSWER = '[data-testid="nowo-task-my-question-row"]';

    protected function setUp(): void
    {
        self::$taskBoardConfig = self::$taskBoardConfig !== [] ? self::$taskBoardConfig : $this->config();
        parent::setUp();
        MemberTenantResolver::$members = [
            'acme'   => ['owner@example.com', 'bob@example.com'],
            'globex' => ['bob@example.com'],
            'hidden' => ['bob@example.com'],
        ];
    }

    protected function tearDown(): void
    {
        MemberTenantResolver::$members = [];
        parent::tearDown();
    }

    public function testAnUnresolvedTenantIs404AndNeverEveryBoard(): void
    {
        $this->ask();
        $this->login($this->bob);
        $this->client->request('GET', '/t/acme/questions');
        self::assertResponseIsSuccessful();

        foreach (['/t/nowhere/questions', '/t/nowhere/questions?tab=asked', '/t/other/questions'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        $this->login($this->owner);
        $this->client->request('GET', '/t/nowhere/questions?tab=asked');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A host whose listeners grant view access by their own rules (here: every task) and grant every question action: the
     * tenant still decides, on the tenant page and on the global page alike; TASK_QUESTION_ACCESS_CHECK never widens a list.
     */
    public function testANonMemberGets404AndNoRowsEvenWhenTheHostGrantsEverything(): void
    {
        $dave = $this->user('dave@example.com');
        $this->direct($this->task, $dave, $this->owner);
        $this->grantEverything();
        $this->login($dave);

        $this->client->request('GET', '/t/acme/questions');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorNotExists('[data-testid="nowo-task-my-questions"]');

        $crawler = $this->client->request('GET', '/tools/task-board/questions');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::TO_ANSWER));

        // A host widget on a page that names a tenant the viewer is not a member of: no rows, never every board.
        self::assertSame([], $this->mineAt($dave, 'acme'));
        self::assertSame([], $this->mineAt($dave, 'nowhere'));
        self::assertSame([], $this->mineAt($dave, null));
    }

    public function testAMemberSeesOnlyTheQuestionsOfBoardsTheyMaySee(): void
    {
        $carol   = $this->user('carol@example.com');
        $roadmap = $this->ask();
        // acme, but a board bob may not see (carol's).
        $hidden = $this->direct($this->task($this->board('Hidden', creator: $carol, tenantKey: 'acme'), 'Secret'), $this->bob, $carol);
        // globex: bob's own board.
        $globex = $this->direct($this->task($this->board('Globex', creator: $this->bob, tenantKey: 'globex'), 'Globex task'), $this->bob, $this->owner);
        // A tenant bob is not a member of, and a board without a tenant: never listed with tenancy, even though he made them.
        $other  = $this->direct($this->task($this->board('Other', creator: $this->bob, tenantKey: 'other'), 'Other task'), $this->bob, $this->owner);
        $global = $this->direct($this->task($this->board('Untenanted', creator: $this->bob), 'Untenanted task'), $this->bob, $this->owner);
        $this->login($this->bob);

        self::assertSame([$roadmap->getId()], $this->rows('/t/acme/questions'));
        self::assertSame([$globex->getId()], $this->rows('/t/globex/questions'));
        $both = [$roadmap->getId(), $globex->getId()];
        sort($both);
        self::assertSame($both, $this->rows('/tools/task-board/questions'));
        foreach ([$hidden, $other, $global] as $unseen) {
            self::assertNotContains($unseen->getId(), $this->rows('/tools/task-board/questions'));
        }

        // The widget of a tenant page: that tenant's visible rows only.
        self::assertSame([$roadmap->getId()], array_map(static fn (TaskQuestion $q): string => $q->getId(), $this->mineAt($this->bob, 'acme')));
        // The Questions section of a task the viewer may not see, rendered by a host template: none.
        self::assertNull($this->atRequest(null, fn (): ?array => $this->viewAs($this->bob)->section($hidden->getTask())));
        // With tenancy, a board without a tenant is in no tenant the viewer may open, even their own board.
        self::assertNull($this->atRequest(null, fn (): ?array => $this->viewAs($this->bob)->section($global->getTask())));
        // On the tenant's page, the request's own tenant.
        self::assertNotNull($this->atRequest('acme', fn (): ?array => $this->viewAs($this->bob)->section($this->task)));
    }

    /**
     * Audit UG-1: a sharee — a participant of a task on a board of a tenant the resolver opens to them but whose board list
     * the tenant-aware checker refuses (Beacon: someone else's general tenant) — sees their question on the global page,
     * in the widget and on that tenant's page; `canListBoards()` decides the board index only. A non-member still gets
     * nothing, even with a host that grants everything.
     */
    public function testAShareeSeesTheirQuestionsInATenantTheyMayNotList(): void
    {
        self::$taskBoardConfig = $this->config() + ['security' => ['access_checker' => 'app.tenant_create_checker']];
        self::ensureKernelShutdown();
        $this->setUp();
        $carol  = $this->user('carol@example.com');
        $shared = $this->task($this->board('Shared', creator: $carol, tenantKey: TenantCreateChecker::UNLISTED_TENANT), 'Shared task');
        $shared->addMember(new TaskMember($shared, $this->bob, TaskMemberRole::Assignee));
        $this->em->flush();
        $question = $this->direct($shared, $this->bob, $carol);
        // dave: a recipient of the same board, member of no tenant.
        $dave = $this->user('dave@example.com');
        $this->direct($shared, $dave, $carol);
        $this->login($this->bob);

        self::assertContains($question->getId(), $this->rows('/tools/task-board/questions'));
        self::assertSame([$question->getId()], $this->rows('/t/' . TenantCreateChecker::UNLISTED_TENANT . '/questions'));
        self::assertContains($question->getId(), array_map(static fn (TaskQuestion $q): string => $q->getId(), $this->mineAt($this->bob, null)));
        self::assertSame([$question->getId()], array_map(static fn (TaskQuestion $q): string => $q->getId(), $this->mineAt($this->bob, TenantCreateChecker::UNLISTED_TENANT)));
        self::assertNotNull($this->atRequest(null, fn (): ?array => $this->viewAs($this->bob)->section($shared)));

        $this->grantEverything();
        $this->login($dave);
        $this->client->request('GET', '/t/' . TenantCreateChecker::UNLISTED_TENANT . '/questions');
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->rows('/tools/task-board/questions'));
        self::assertSame([], $this->mineAt($dave, null));
        self::assertNull($this->atRequest(null, fn (): ?array => $this->viewAs($dave)->section($shared)));
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'task_questions' => ['enabled' => true, 'recipient_resolver' => 'app.question_recipients'],
            'tenancy'        => ['enabled' => true, 'resolver' => 'app.member_tenant_resolver', 'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']]],
        ];
    }

    /**
     * An open question on `$task` addressed to `$recipient`, written as rows (no access check: the asker's right is not
     * what these tests are about).
     */
    private function direct(Task $task, AppUser $recipient, AppUser $asker): TaskQuestion
    {
        $question = new TaskQuestion($task, 'Direct question', null, TaskQuestionChoiceMode::Single, true, $asker, new DateTimeImmutable());
        $question->addRecipient(new TaskQuestionRecipient($question, $this->subject($recipient), $recipient->getUserIdentifier(), false, 0));
        $this->em->persist($question);
        $this->em->flush();

        return $question;
    }

    private function grantEverything(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $grant = static function (TaskAccessCheckEvent $event): void {
            $event->grant();
        };
        $dispatcher->addListener('nowo_task_board.task_access_check', $grant);
        $dispatcher->addListener('nowo_task_board.task_question_access_check', $grant);
    }

    /** @return list<string> the question ids of the page's rows, sorted */
    private function rows(string $path): array
    {
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful($path);
        $ids = $crawler->filter('[data-testid="nowo-task-my-question-link"]')->each(static fn (Crawler $link): string => (string) preg_replace('/^.*#nowo-task-question-/', '', (string) $link->attr('href')));
        sort($ids);

        return $ids;
    }

    /**
     * {@see TaskQuestionView::mine()} as a host widget calls it, on a request naming `$tenantSlug` (null: none).
     *
     * @return list<TaskQuestion>
     */
    private function mineAt(AppUser $user, ?string $tenantSlug): array
    {
        return $this->atRequest($tenantSlug, fn (): array => $this->viewAs($user)->mine());
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function atRequest(?string $tenantSlug, callable $call): mixed
    {
        $stack = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $request = Request::create('/host/dashboard');
        $request->setSession(new Session(new MockArraySessionStorage()));
        if ($tenantSlug !== null) {
            $request->attributes->set('tenantSlug', $tenantSlug);
        }
        $stack->push($request);
        try {
            return $call();
        } finally {
            $stack->pop();
        }
    }

    private function viewAs(AppUser $user): TaskQuestionView
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $view = self::getContainer()->get(TaskQuestionView::class);
        self::assertInstanceOf(TaskQuestionView::class, $view);

        return $view;
    }
}
