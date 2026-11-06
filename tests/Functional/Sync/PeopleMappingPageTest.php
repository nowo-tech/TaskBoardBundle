<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Sync\Event\PeopleMappingChangedEvent;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingPeopleInviter;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedPeopleResolver;

use function array_map;
use function array_replace_recursive;
use function json_decode;
use function sort;

/**
 * Since 1.27.0 (TB-SYNC-2): the People step and page — rows pre-filled by e-mail then by name, the host candidates, the
 * invite option, saving (deny by default: only saved choices map), re-application to the imported tasks, the run
 * summary, the audit event, and the refusals.
 */
final class PeopleMappingPageTest extends SyncPeopleFunctionalTestCase
{
    private const BASE = '/tools/task-board/board/';

    /** @var list<PeopleMappingChangedEvent> */
    private array $events = [];

    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => ['people' => [
            'resolver'  => 'app.people_resolver',
            'inviter'   => 'app.people_inviter',
            'step'      => true,
            'page_size' => 10,
        ]]]);
    }

    protected function setUp(): void
    {
        ScriptedPeopleResolver::reset();
        RecordingPeopleInviter::reset();
        parent::setUp();
        $this->events = [];
        $this->dispatcher()->addListener(TaskBoardEvents::SYNC_PEOPLE_MAPPING_CHANGED, function (PeopleMappingChangedEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testTheStepScansPeoplePreFillsThemAndTheSaveStartsTheImport(): void
    {
        $board = $this->board();
        $ana   = $this->viewer('ana@example.com');
        $bob   = $this->viewer('bob@host.test')->setDisplayName('Bob Stone');
        $this->em->flush();
        ScriptedPeopleResolver::$candidates = ['ana@example.com', 'bob@host.test'];
        $this->workspace([
            new RemotePerson('1', 'Ana Lopez', 'ANA@example.com', RemoteRole::Member),
            new RemotePerson('2', 'Bób  Stone', null, RemoteRole::Member),
            new RemotePerson('3', 'Dora <b>Remote</b>', 'dora@remote.test', RemoteRole::Guest),
        ]);
        $this->put($this->row('t1', 'Shared task', 1, creator: self::ref('3', 'dora@remote.test'), assignees: [self::ref('1', 'ana@example.com'), self::ref('2')]));
        $this->login($this->owner);

        $this->link($board);
        self::assertResponseRedirects(self::BASE . $board->getId() . '/connection/people');
        self::assertSame(0, $this->rows(TaskSyncRun::class), 'the step comes before the first import');

        $crawler = $this->client->request('GET', self::BASE . $board->getId() . '/connection/people');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="nowo-task-sync-people-step"]');
        self::assertCount(3, $crawler->filter('[data-testid="nowo-task-sync-person-row"]'));
        self::assertStringContainsString('Dora &lt;b&gt;Remote&lt;/b&gt;', (string) $this->client->getResponse()->getContent(), 'remote names are escaped');
        self::assertStringContainsString('dora@remote.test', (string) $this->client->getResponse()->getContent());
        $p1 = $this->personOf('1');
        $p2 = $this->personOf('2');
        $p3 = $this->personOf('3');
        self::assertSame((string) $ana->getId(), $crawler->filter('select[name="task_board_sync_people[p_' . $p1->getId() . ']"] option[selected]')->attr('value'), 'pre-filled by e-mail');
        self::assertSame('', $crawler->filter('select[name="task_board_sync_people[p_' . $p2->getId() . ']"] option[selected]')->attr('value'), 'a name match is only a suggestion (audit GW-L3)');
        self::assertStringContainsString('Bob Stone', $crawler->filter('[data-testid="nowo-task-sync-person-suggestion"]')->text());
        self::assertSame('name', $crawler->filter('select[name="task_board_sync_people[p_' . $p2->getId() . ']"]')->attr('data-prefill'));
        self::assertCount(1, $crawler->filter('select[name="task_board_sync_people[p_' . $p3->getId() . ']"] option[value="__invite"]'));
        self::assertCount(0, $crawler->filter('select[name="task_board_sync_people[p_' . $p2->getId() . ']"] option[value="__invite"]'), 'no invite without an e-mail');
        self::assertSame(TaskSyncPersonMapping::Unmatched, $p1->getMapping(), 'nothing is mapped before the save');

        $this->client->request('POST', self::BASE . $board->getId() . '/connection/people/save?filter=all&page=1', ['task_board_sync_people' => [
            'p_' . $p1->getId() => (string) $ana->getId(),
            'p_' . $p2->getId() => (string) $bob->getId(),
            'p_' . $p3->getId() => '__invite',
            '_token'            => PredictableCsrfTokenManager::value('task_board_connection_people'),
        ]]);
        self::assertResponseRedirects(self::BASE . $board->getId() . '/connection');
        self::assertSame(['dora@remote.test'], RecordingPeopleInviter::$invited);
        self::assertCount(1, $this->events);
        self::assertCount(2, $this->events[0]->changes);
        self::assertSame(1, $this->events[0]->invited);
        self::assertSame($board->getId(), $this->events[0]->boardId);

        // Audit GW-L4: a row the saved page did not have stays as it is.
        $mapped = $this->personOf('1');
        self::assertSame(TaskSyncPersonMapping::Manual, $mapped->getMapping());
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/people/save?filter=all&page=1', ['task_board_sync_people' => ['_token' => PredictableCsrfTokenManager::value('task_board_connection_people')]]);
        $this->em->clear();
        self::assertSame(TaskSyncPersonMapping::Manual, $this->personOf('1')->getMapping(), 'not on the POST: unchanged');
        $this->refreshOwner();
        $this->login($this->owner);

        // The import ran with the saved mapping: both are assignees; Dora stays a reference.
        $task   = $this->taskTitledFresh('Shared task');
        $emails = array_map(static fn (TaskMember $member): string => $member->getUser() instanceof AppUser ? $member->getUser()->getEmail() : '', $task->getMembers()->toArray());
        sort($emails);
        self::assertSame(['ana@example.com', 'bob@host.test'], $emails);
        self::assertSame(TaskSyncPersonMapping::Unmatched, $this->personOf('3')->getMapping());

        // The connection page counts the people nobody mapped yet and links the People page; so does the progress JSON.
        $this->client->request('GET', self::BASE . $board->getId() . '/connection');
        self::assertSelectorTextContains('[data-testid="nowo-task-sync-people-unmapped"]', '1');
        self::assertSelectorExists('[data-testid="nowo-task-sync-people-link"]');
        $run = $this->em->getRepository(TaskSyncRun::class)->findOneBy([]);
        self::assertInstanceOf(TaskSyncRun::class, $run);
        $this->client->request('GET', self::BASE . $board->getId() . '/connection/progress/' . $run->getId());
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($json);
        self::assertSame(1, $json['unmappedPeople']);

        // Filter "not mapped yet": only Dora.
        $crawler = $this->client->request('GET', self::BASE . $board->getId() . '/connection/people?filter=unmapped');
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-sync-person-row"]'));
        self::assertSelectorNotExists('[data-testid="nowo-task-sync-people-step"]');
    }

    public function testChangingAMappingReappliesItToTheImportedTasks(): void
    {
        $board                              = $this->board();
        $ana                                = $this->viewer('ana@example.com');
        $carl                               = $this->viewer('carl@example.com');
        ScriptedPeopleResolver::$candidates = ['ana@example.com', 'carl@example.com'];
        $this->workspace([new RemotePerson('1', 'Ann', 'ann@remote.test', RemoteRole::Member)]);
        $this->put($this->row('t1', 'One', 1, assignees: [self::ref('1', 'ann@remote.test')]), $this->row('t2', 'Two', 2, watchers: [self::ref('1', 'ann@remote.test')]));
        $this->sync($board);
        self::assertCount(0, $this->taskTitledFresh('One')->getMembers(), 'unmatched: nobody is assigned');
        $this->login($this->owner);
        $person = $this->personOf('1');

        $this->save($board, [$person->getId() => (string) $ana->getId()]);
        self::assertResponseRedirects(self::BASE . $board->getId() . '/connection/people?filter=all&page=1');
        self::assertSame(['ana@example.com'], $this->memberEmails('One'));
        self::assertSame(['ana@example.com'], $this->memberEmails('Two'));
        self::assertSame(TaskSyncPersonMapping::Manual, $this->personOf('1')->getMapping());

        $this->save($board, [$person->getId() => (string) $carl->getId()]);
        self::assertSame(['carl@example.com'], $this->memberEmails('One'));

        // Carl is no longer a candidate: his row still shows him; choosing a user the resolver refuses is reported.
        ScriptedPeopleResolver::$candidates = ['ana@example.com'];
        $crawler                            = $this->client->request('GET', self::BASE . $board->getId() . '/connection/people');
        self::assertSame('carl@example.com', $crawler->filter('option[value="' . $carl->getId() . '"]')->text());
        ScriptedPeopleResolver::$refuseById = ['ana@example.com'];
        $this->save($board, [$person->getId() => (string) $ana->getId()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'cannot be chosen');
        ScriptedPeopleResolver::$candidates = ['ana@example.com', 'carl@example.com'];
        ScriptedPeopleResolver::$refuseById = [];

        // "Not mapped" on a mapped person keeps them as external: the members the sync added go.
        $this->save($board, [$person->getId() => '']);
        self::assertSame([], $this->memberEmails('One'));
        self::assertSame(TaskSyncPersonMapping::External, $this->personOf('1')->getMapping());
        self::assertCount(3, $this->events);

        // Saving the same values changes nothing and dispatches nothing.
        $this->save($board, [$person->getId() => '']);
        self::assertCount(3, $this->events);

        // A later sync keeps the decision.
        $this->sync($board, full: true);
        self::assertSame([], $this->memberEmails('One'));
    }

    public function testRefusalsAndEdges(): void
    {
        $board                              = $this->board();
        $other                              = $this->user('other@example.com');
        ScriptedPeopleResolver::$candidates = ['ana@example.com'];
        $this->user('ana@example.com');
        $this->login($this->owner);

        // Not linked: 404.
        $this->client->request('GET', self::BASE . $board->getId() . '/connection/people');
        self::assertResponseStatusCodeSame(404);

        // Linked without a provider workspace: no directory, the step is skipped and the import starts.
        $this->link($board);
        self::assertResponseRedirects(self::BASE . $board->getId() . '/connection');
        $this->client->request('GET', self::BASE . $board->getId() . '/connection/people');
        self::assertSelectorExists('[data-testid="nowo-task-sync-people-none"]');
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/people/save', []);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', self::BASE . $board->getId() . '/connection');
        self::assertSelectorNotExists('[data-testid="nowo-task-sync-people-summary"]');

        // A board whose people scan fails: the import starts at once.
        $this->refreshOwner();
        $second = $this->board('Second');
        $this->workspace([new RemotePerson('1', 'Ann', null, RemoteRole::Member)]);
        $this->provider->failNext(null)->failNext(null)->failNext(new ProviderException(ProviderErrorCode::ServerError));
        $this->link($second);
        self::assertResponseRedirects(self::BASE . $second->getId() . '/connection');

        // Someone who cannot manage the connection gets 404.
        $this->login($other);
        $this->client->request('GET', self::BASE . $second->getId() . '/connection/people');
        self::assertResponseStatusCodeSame(404);
        $this->login($this->owner);

        // Bad token: 403; a forged value: the form refuses it.
        $person = $this->personOf('1');
        $this->client->request('POST', self::BASE . $second->getId() . '/connection/people/save', ['task_board_sync_people' => ['p_' . $person->getId() => '']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::BASE . $second->getId() . '/connection/people/save', []);
        self::assertResponseStatusCodeSame(403);
        $this->save($second, [$person->getId() => (string) $other->getId()]);
        self::assertResponseRedirects(self::BASE . $second->getId() . '/connection/people?filter=all&page=1');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger, .alert-error, [role="alert"]');
        self::assertSame(TaskSyncPersonMapping::Unmatched, $this->personOf('1')->getMapping());

        // The inviter refuses: the row is reported, nothing else changes.
        RecordingPeopleInviter::$allowed = false;
        $crawler                         = $this->client->request('GET', self::BASE . $second->getId() . '/connection/people');
        self::assertCount(0, $crawler->filter('option[value="__invite"]'));
    }

    public function testTheStepCanBeSkippedAndPagesThePeople(): void
    {
        $board  = $this->board();
        $people = [];
        for ($i = 1; $i <= 11; ++$i) {
            $people[] = new RemotePerson((string) $i, 'Person ' . ($i < 10 ? '0' : '') . $i, null, RemoteRole::Member);
        }
        $this->workspace($people);
        $this->put($this->row('t1', 'One', 1));
        $this->login($this->owner);
        $this->link($board);
        $crawler = $this->client->request('GET', self::BASE . $board->getId() . '/connection/people');
        self::assertCount(10, $crawler->filter('[data-testid="nowo-task-sync-person-row"]'));
        self::assertSelectorExists('[data-testid="nowo-task-sync-people-next"]');
        $crawler = $this->client->request('GET', self::BASE . $board->getId() . '/connection/people?page=2');
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-sync-person-row"]'));

        $this->client->request('POST', self::BASE . $board->getId() . '/connection/people/save?page=2', ['skip' => '1', 'task_board_sync_people' => [
            'p_' . $this->personOf('11')->getId() => '',
            '_token'                              => PredictableCsrfTokenManager::value('task_board_connection_people'),
        ]]);
        self::assertResponseRedirects(self::BASE . $board->getId() . '/connection');
        self::assertSame(1, $this->rows(Task::class), 'the import ran');
        self::assertSame([], $this->events);
    }

    private function link(TaskBoard $board): void
    {
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/link', ['task_board_sync_link' => [
            'provider' => 'clickup', 'connection' => 'conn-1', 'container' => self::LIST,
            '_token'   => PredictableCsrfTokenManager::value('task_board_connection_link'),
        ]]);
    }

    /**
     * @param array<string, string> $choices person id => value
     */
    private function save(TaskBoard $board, array $choices): void
    {
        $fields = ['_token' => PredictableCsrfTokenManager::value('task_board_connection_people')];
        foreach ($choices as $id => $value) {
            $fields['p_' . $id] = $value;
        }
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/people/save?filter=all&page=1', ['task_board_sync_people' => $fields]);
    }

    private function taskTitledFresh(string $title): Task
    {
        $this->em->clear();
        $this->refreshOwner();

        return $this->taskTitled($title);
    }

    /**
     * @return list<string>
     */
    private function memberEmails(string $title): array
    {
        $emails = array_map(static fn (TaskMember $member): string => $member->getUser() instanceof AppUser ? $member->getUser()->getEmail() : '', $this->taskTitledFresh($title)->getMembers()->toArray());
        sort($emails);

        return $emails;
    }

    protected function personOf(string $providerUserId): TaskSyncPerson
    {
        $this->em->clear();
        $this->refreshOwner();

        return parent::personOf($providerUserId);
    }
}
