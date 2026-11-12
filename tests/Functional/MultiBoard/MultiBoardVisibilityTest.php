<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\MultiBoard;

use Nowo\TaskBoardBundle\Controller\TaskBoardMultiBoardController;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TeamRole;
use Nowo\TaskBoardBundle\Form\MultiBoardTaskFilterType;
use Nowo\TaskBoardBundle\MultiBoard\DoctrineMultiBoardTaskQuery;
use Nowo\TaskBoardBundle\MultiBoard\Export\MultiBoardExportTable;
use Nowo\TaskBoardBundle\MultiBoard\Export\XlsxStreamWriter;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardBoard;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardPerson;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskList;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskPage;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardWorkload;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardWorkloadRow;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ZipArchive;

use function array_slice;
use function ord;

/**
 * Since 2.13.0 (Beacon D-20261112-01): the visibility matrix of the multi-board list and its export. A board is shown only
 * when the host's scope lists it AND the bundle's own read check allows it; no filter, sort, page or format reaches another.
 *
 * Boards: A (alice's, in her scope), B (bob's, in her scope: refused by the bundle), C (alice's, not in her scope), D
 * (alice's team board, in her scope), E (alice's, archived, in her scope).
 */
#[CoversClass(TaskBoardMultiBoardController::class)]
#[CoversClass(MultiBoardTaskList::class)]
#[CoversClass(DoctrineMultiBoardTaskQuery::class)]
#[CoversClass(MultiBoardTaskFilterType::class)]
#[CoversClass(MultiBoardBoard::class)]
#[CoversClass(MultiBoardPerson::class)]
#[CoversClass(MultiBoardTaskPage::class)]
#[CoversClass(MultiBoardWorkload::class)]
#[CoversClass(MultiBoardWorkloadRow::class)]
#[CoversClass(MultiBoardExportTable::class)]
#[CoversClass(XlsxStreamWriter::class)]
#[CoversClass(TaskBoardExtension::class)]
#[CoversClass(TaskBoardRouteLoader::class)]
final class MultiBoardVisibilityTest extends MultiBoardTestCase
{
    private AppUser $alice;

    private AppUser $bob;

    /** @var array<string, TaskBoard> */
    private array $boards = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->user('alice@example.com');
        $this->bob   = $this->user('bob@example.com');

        $this->boards['A'] = $this->board('Alpha', creator: $this->alice);
        $this->boards['B'] = $this->board('Bravo', creator: $this->bob);
        $this->boards['C'] = $this->board('Charlie', creator: $this->alice);
        $team              = new Team('Delta team');
        $this->em->persist($team);
        $this->em->persist(new TeamMember($team, $this->alice, TeamRole::Member));
        $this->boards['D'] = $this->board('Delta', creator: $this->bob);
        $this->boards['D']->setTeam($team);
        $this->boards['E'] = $this->board('Echo', creator: $this->alice);
        $this->boards['E']->archive();
        $this->em->flush();

        foreach ($this->boards as $key => $board) {
            $this->make($board, 'task-' . $key, ['assignees' => [$key === 'B' ? $this->bob : $this->alice], 'tags' => ['shared'], 'due' => '2020-01-0' . (ord($key) - 64)]);
            $this->make($board, '=HYPERLINK("http://evil.example/' . $key . '")', ['tags' => ['shared']]);
        }
        $this->allow($this->alice, $this->boards['A'], $this->boards['B'], $this->boards['D'], $this->boards['E']);
        $this->allow($this->bob, $this->boards['B']);
        $this->login($this->alice);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function crafted(): iterable
    {
        yield 'nothing' => [''];
        yield 'board B' => ['board=%B%'];
        yield 'board C' => ['board=%C%'];
        yield 'board E' => ['board=%E%'];
        yield 'board array' => ['board[]=%C%'];
        yield 'bob as assignee' => ['assignee=%bob%'];
        yield 'all statuses' => ['status=all'];
        yield 'label' => ['label=shared'];
        yield 'search' => ['q=task'];
        yield 'sort by board' => ['sort=board&dir=desc'];
        yield 'unknown sort' => ['sort=b.name;DROP&dir=sideways'];
        yield 'page 99' => ['page=99&per_page=10'];
        yield 'people' => ['view=people'];
    }

    #[DataProvider('crafted')]
    public function testTheListNeverShowsABoardOutsideTheCheckedScope(string $query): void
    {
        $content = $this->get($this->expand($query));

        self::assertResponseIsSuccessful();
        foreach (['B', 'C', 'E'] as $hidden) {
            self::assertStringNotContainsString('task-' . $hidden, $content);
            self::assertStringNotContainsString('evil.example/' . $hidden, $content);
            // The viewer's own query may echo a crafted id in the sort links; no board link, choice or row carries it.
            self::assertStringNotContainsString('/board/' . $this->boards[$hidden]->getId(), $content);
            self::assertStringNotContainsString('value="' . $this->boards[$hidden]->getId() . '"', $content);
        }
        self::assertStringNotContainsString('Bravo', $content);
        self::assertStringNotContainsString('Charlie', $content);
    }

    #[DataProvider('crafted')]
    public function testTheExportsNeverHoldABoardOutsideTheCheckedScope(string $query): void
    {
        foreach (['csv', 'xlsx'] as $format) {
            $content = $this->export($format, $this->expand($query) . '&status=all');
            self::assertResponseIsSuccessful();
            $text = $format === 'xlsx' ? $this->sheet($content) : $content;
            foreach (['B', 'C', 'E'] as $hidden) {
                self::assertStringNotContainsString('task-' . $hidden, $text, $format);
                self::assertStringNotContainsString($this->boards[$hidden]->getId(), $text, $format);
            }
        }
    }

    public function testTheScopeAndTheBundleCheckBothDecide(): void
    {
        $this->get('status=all&per_page=100');

        self::assertSame(['task-A', 'task-D'], array_values(array_filter($this->titles(), static fn (string $title): bool => str_starts_with($title, 'task-'))));
        self::assertCount(4, $this->titles());
        $boardOptions = $this->client->getCrawler()->filter('select[name="board"] option')->each(static fn ($node): string => $node->text());
        self::assertSame(['Any board', 'Alpha', 'Delta'], $boardOptions);
    }

    public function testBobSeesOnlyHisBoard(): void
    {
        $this->login($this->bob);

        $this->get('status=all');

        self::assertSame(['task-B'], array_values(array_filter($this->titles(), static fn (string $title): bool => str_starts_with($title, 'task-'))));
    }

    public function testAnEmptyScopeIsAnEmptyListAndTheExportAnswers404(): void
    {
        $carol = $this->user('carol@example.com');
        $this->login($carol);

        $content = $this->get();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No boards to show.', $content);
        self::assertSame([], $this->titles());
        self::assertSelectorNotExists('[data-testid="nowo-task-multi-export-csv"]');

        foreach (['csv', 'xlsx'] as $format) {
            $this->export($format);
            self::assertResponseStatusCodeSame(404);
        }
        $this->get('view=people');
        self::assertResponseIsSuccessful();
    }

    public function testAScopeOfOnlyRefusedBoardsIsEmptyToo(): void
    {
        $this->allow($this->alice, $this->boards['B'], $this->boards['C']);
        $this->scope()->entries[(string) $this->alice->getId()] = array_slice($this->scope()->entries[(string) $this->alice->getId()], 0, 1);

        $this->get();

        self::assertStringContainsString('No boards to show.', (string) $this->client->getResponse()->getContent());
        $this->export('csv');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAUserTheAccessCheckerRefusesGetsA403(): void
    {
        $this->login($this->user('guest@example.com', ['ROLE_GUEST']));
        $this->client->request('GET', self::URL);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', self::URL . '/export.csv');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownFormatIs404(): void
    {
        $this->client->request('GET', self::URL . '/export.pdf');

        self::assertResponseStatusCodeSame(404);
    }

    private function expand(string $query): string
    {
        $replace = ['%bob%' => (string) $this->bob->getId()];
        foreach ($this->boards as $key => $board) {
            $replace['%' . $key . '%'] = $board->getId();
        }

        return strtr($query, $replace);
    }

    private function sheet(string $xlsx): string
    {
        $file = tempnam(sys_get_temp_dir(), 'tb-xlsx');
        self::assertIsString($file);
        file_put_contents($file, $xlsx);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($file);

        return $sheet;
    }
}
