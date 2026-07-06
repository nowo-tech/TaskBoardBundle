<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;

final class TaskBoardDemoFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $user = $manager->getRepository(User::class)->findOneBy(['email' => 'demo@example.com']);
        if (!$user instanceof User) {
            return;
        }

        if ($this->demoAlreadyLoaded($manager)) {
            return;
        }

        $this->loadPlatformSquadBoard($manager, $user);
        $this->loadProductBacklogBoard($manager, $user);

        $manager->flush();
    }

    /** @return list<class-string<Fixture>> */
    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }

    private function demoAlreadyLoaded(ObjectManager $manager): bool
    {
        return (int) $manager->createQuery('SELECT COUNT(b.id) FROM ' . TaskBoard::class . ' b WHERE b.slug = :slug')
            ->setParameter('slug', 'platform-squad')
            ->getSingleScalarResult() > 0;
    }

    private function loadPlatformSquadBoard(ObjectManager $manager, User $user): void
    {
        $board = new TaskBoard(
            name: 'Platform Squad',
            slug: 'platform-squad',
            creator: $user,
            description: 'Sprint board for backend and bundle work. Includes MR links, dependencies, and subtasks.',
        );

        $todo     = new BoardColumn($board, 'To do', 0, '#94a3b8');
        $progress = new BoardColumn($board, 'In progress', 1, '#3b82f6');
        $done     = new BoardColumn($board, 'Done', 2, '#22c55e');
        $board->addColumn($todo)->addColumn($progress)->addColumn($done);

        $taskScaffold = new Task(
            board: $board,
            title: 'Scaffold TaskBoardBundle',
            creator: $user,
            column: $done,
            description: 'Initial bundle structure, entities, and Flex recipe.',
            priority: TaskPriority::Normal,
            position: 0,
            estimatedMinutes: 240,
        );
        $taskScaffold->markCompleted();

        $taskUi = new Task(
            board: $board,
            title: 'Add Tabler UI to demo',
            creator: $user,
            column: $done,
            description: 'Style manage views with Tabler cards, kanban columns, and list filters.',
            priority: TaskPriority::Normal,
            position: 1,
            estimatedMinutes: 180,
        );
        $taskUi->markCompleted();

        $taskApi = new Task(
            board: $board,
            title: 'Implement task board API',
            creator: $user,
            column: $progress,
            description: 'Expose REST endpoints for board sync and mobile clients.',
            priority: TaskPriority::High,
            position: 0,
            estimatedMinutes: 480,
            dueAt: new DateTimeImmutable('+5 days'),
            tags: ['api', 'backend', 'symfony'],
        );
        $taskApi->addLink(new TaskLink(
            task: $taskApi,
            linkType: TaskLinkType::MergeRequest,
            url: 'https://gitlab.example.com/acme/platform/-/merge_requests/42',
            label: 'MR !42 — board API',
            externalId: '42',
        ));
        $taskApi->addLink(new TaskLink(
            task: $taskApi,
            linkType: TaskLinkType::Documentation,
            url: 'https://docs.example.com/task-board/api',
            label: 'API design notes',
        ));
        $taskApi->addMember(new TaskMember($taskApi, $user, TaskMemberRole::Assignee));
        $taskApi->addMember(new TaskMember($taskApi, $user, TaskMemberRole::Reviewer));

        $subtaskOpenApi = new Task(
            board: $board,
            title: 'Design OpenAPI schema',
            creator: $user,
            column: $progress,
            parent: $taskApi,
            priority: TaskPriority::Normal,
            position: 0,
            estimatedMinutes: 120,
        );
        $subtaskTests = new Task(
            board: $board,
            title: 'Add integration tests',
            creator: $user,
            column: $todo,
            parent: $taskApi,
            priority: TaskPriority::High,
            position: 1,
            estimatedMinutes: 180,
        );
        $subtaskNested = new Task(
            board: $board,
            title: 'Cover move-task endpoint',
            creator: $user,
            column: $todo,
            parent: $subtaskTests,
            priority: TaskPriority::Normal,
            position: 0,
            estimatedMinutes: 60,
        );

        $taskAuth = new Task(
            board: $board,
            title: 'Migrate legacy auth to middleware',
            creator: $user,
            column: $progress,
            description: 'Replace session checks with configurable access checker events.',
            priority: TaskPriority::Urgent,
            position: 1,
            estimatedMinutes: 360,
            dueAt: new DateTimeImmutable('+2 days'),
        );
        $taskAuth->addMember(new TaskMember($taskAuth, $user, TaskMemberRole::Assignee));
        $taskAuth->addMember(new TaskMember($taskAuth, $user, TaskMemberRole::Watcher));

        $taskMr = new Task(
            board: $board,
            title: 'Review MR: auth middleware',
            creator: $user,
            column: $todo,
            description: 'Code review before merging dependency chain.',
            priority: TaskPriority::Normal,
            position: 0,
            estimatedMinutes: 60,
            dueAt: new DateTimeImmutable('+3 days'),
            tags: ['review', 'auth', 'gitlab'],
        );
        $taskMr->addLink(new TaskLink(
            task: $taskMr,
            linkType: TaskLinkType::MergeRequest,
            url: 'https://gitlab.example.com/acme/platform/-/merge_requests/57',
            label: 'MR !57',
            externalId: '57',
        ));
        $taskMr->addMember(new TaskMember($taskMr, $user, TaskMemberRole::Assignee));

        $taskDocs = new Task(
            board: $board,
            title: 'Write bundle integration guide',
            creator: $user,
            column: $todo,
            description: 'Document Flex recipe, ACL events, and demo setup.',
            priority: TaskPriority::Low,
            position: 1,
            estimatedMinutes: 90,
        );
        $taskDocs->addLink(new TaskLink(
            task: $taskDocs,
            linkType: TaskLinkType::Issue,
            url: 'https://gitlab.example.com/acme/platform/-/issues/128',
            label: 'Issue #128',
            externalId: '128',
        ));

        $taskCi = new Task(
            board: $board,
            title: 'Set up CI pipeline for bundle',
            creator: $user,
            column: $todo,
            priority: TaskPriority::High,
            position: 2,
            estimatedMinutes: 120,
        );
        $taskCi->addLink(new TaskLink(
            task: $taskCi,
            linkType: TaskLinkType::PullRequest,
            url: 'https://github.com/acme/task-board-bundle/pull/12',
            label: 'PR #12',
            externalId: '12',
        ));

        $docApiOverview = new TaskDocument(
            task: $taskApi,
            title: 'Endpoint overview',
            content: "GET /boards — list boards\nGET /boards/{id}/tasks — kanban payload\nPOST /tasks/{id}/move — column + position",
            creator: $user,
            position: 0,
        );
        $docApiAuth = new TaskDocument(
            task: $taskApi,
            title: 'Authentication',
            content: 'All endpoints require ROLE_USER. Board creation additionally checks TaskBoardEvents::BOARD_CREATE.',
            creator: $user,
            position: 1,
        );

        $timeApiDesign = new TaskTimeEntry(
            task: $taskApi,
            user: $user,
            minutes: 90,
            loggedAt: new DateTimeImmutable('-2 days'),
            description: 'Drafted initial route map',
        );
        $timeApiImpl = new TaskTimeEntry(
            task: $taskApi,
            user: $user,
            minutes: 150,
            loggedAt: new DateTimeImmutable('-1 day'),
            description: 'Implemented board + task controllers',
        );
        $timeAuth = new TaskTimeEntry(
            task: $taskAuth,
            user: $user,
            minutes: 45,
            loggedAt: new DateTimeImmutable('-3 hours'),
            description: 'Spiked access checker integration',
        );

        $manager->persist($board);
        foreach ([$todo, $progress, $done] as $column) {
            $manager->persist($column);
        }

        foreach ([
            $taskScaffold, $taskUi, $taskApi, $subtaskOpenApi, $subtaskTests, $subtaskNested,
            $taskAuth, $taskMr, $taskDocs, $taskCi,
        ] as $task) {
            $manager->persist($task);
        }

        $manager->persist(new TaskDependency($taskMr, $taskApi, TaskDependencyType::BlockedBy));
        $manager->persist(new TaskDependency($taskAuth, $taskMr, TaskDependencyType::Blocks));
        $manager->persist(new TaskDependency($subtaskTests, $subtaskOpenApi, TaskDependencyType::BlockedBy));
        $manager->persist(new TaskDependency($taskDocs, $taskApi, TaskDependencyType::Related));

        $manager->persist($docApiOverview);
        $manager->persist($docApiAuth);
        $manager->persist($timeApiDesign);
        $manager->persist($timeApiImpl);
        $manager->persist($timeAuth);
    }

    private function loadProductBacklogBoard(ObjectManager $manager, User $user): void
    {
        $board = new TaskBoard(
            name: 'Product Backlog',
            slug: 'product-backlog',
            creator: $user,
            description: 'Upcoming features and polish items for the task board product.',
        );

        $todo     = new BoardColumn($board, 'To do', 0, '#94a3b8');
        $progress = new BoardColumn($board, 'In progress', 1, '#3b82f6');
        $done     = new BoardColumn($board, 'Done', 2, '#22c55e');
        $board->addColumn($todo)->addColumn($progress)->addColumn($done);

        $taskGrooming = new Task(
            board: $board,
            title: 'User story grooming — Q3',
            creator: $user,
            column: $todo,
            description: 'Prioritize GitLab webhooks, time tracking UI, and custom columns.',
            priority: TaskPriority::Normal,
            position: 0,
            estimatedMinutes: 120,
            dueAt: new DateTimeImmutable('+7 days'),
        );
        $taskGrooming->addMember(new TaskMember($taskGrooming, $user, TaskMemberRole::Stakeholder));

        $taskDnD = new Task(
            board: $board,
            title: 'Polish kanban drag & drop',
            creator: $user,
            column: $progress,
            description: 'Improve drop targets, keyboard accessibility, and optimistic UI.',
            priority: TaskPriority::High,
            position: 0,
            estimatedMinutes: 240,
            tags: ['frontend', 'ux', 'stimulus'],
        );
        $taskDnD->addMember(new TaskMember($taskDnD, $user, TaskMemberRole::Assignee));
        $taskDnD->addLink(new TaskLink(
            task: $taskDnD,
            linkType: TaskLinkType::Issue,
            url: 'https://gitlab.example.com/acme/product/-/issues/45',
            label: 'UX feedback #45',
            externalId: '45',
        ));

        $subtaskDnDAnimation = new Task(
            board: $board,
            title: 'Add column drop highlight',
            creator: $user,
            column: $progress,
            parent: $taskDnD,
            priority: TaskPriority::Normal,
            position: 0,
            estimatedMinutes: 60,
        );
        $subtaskDnDKeyboard = new Task(
            board: $board,
            title: 'Keyboard move between columns',
            creator: $user,
            column: $todo,
            parent: $taskDnD,
            priority: TaskPriority::Low,
            position: 1,
            estimatedMinutes: 90,
        );

        $taskRelease = new Task(
            board: $board,
            title: 'Initial bundle release v1.0',
            creator: $user,
            column: $done,
            description: 'Published to Packagist with Flex recipe and Symfony 8 demo.',
            priority: TaskPriority::Normal,
            position: 0,
            estimatedMinutes: 60,
        );
        $taskRelease->markCompleted();
        $taskRelease->addLink(new TaskLink(
            task: $taskRelease,
            linkType: TaskLinkType::Url,
            url: 'https://packagist.org/packages/nowo-tech/task-board-bundle',
            label: 'Packagist',
        ));

        $taskFilters = new Task(
            board: $board,
            title: 'List view filters',
            creator: $user,
            column: $done,
            description: 'Client-side search, priority, and column filters via Stimulus.',
            priority: TaskPriority::Normal,
            position: 1,
            estimatedMinutes: 90,
        );
        $taskFilters->markCompleted();

        $taskNotifications = new Task(
            board: $board,
            title: 'Email notifications on assign',
            creator: $user,
            column: $todo,
            priority: TaskPriority::Low,
            position: 1,
            estimatedMinutes: 300,
        );

        $manager->persist($board);
        foreach ([$todo, $progress, $done] as $column) {
            $manager->persist($column);
        }

        foreach ([
            $taskGrooming, $taskDnD, $subtaskDnDAnimation, $subtaskDnDKeyboard,
            $taskRelease, $taskFilters, $taskNotifications,
        ] as $task) {
            $manager->persist($task);
        }

        $manager->persist(new TaskDependency($taskNotifications, $taskDnD, TaskDependencyType::Related));
        $manager->persist(new TaskTimeEntry(
            task: $taskDnD,
            user: $user,
            minutes: 75,
            loggedAt: new DateTimeImmutable('-5 hours'),
            description: 'Stimulus controller refactor',
        ));
    }
}
