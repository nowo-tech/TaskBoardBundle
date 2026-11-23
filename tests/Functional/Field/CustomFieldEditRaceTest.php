<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Field;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldForm;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedTransitionPolicy;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function fgets;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function trim;

use const PHP_BINARY;

/**
 * Since 2.31.0 (REQ-RUNTIME-003 rule 4, audit L1): the task edit form's custom fields. Up to 2.30 a save compared the
 * posted value with the stored one, so a form left open wrote its old value back over a change someone made
 * meanwhile. The form now posts the values it showed (`customFieldBase`):
 *
 * - a field the person did not touch is never written (the other change stays);
 * - a field the person changed, which someone else changed too, refuses the whole save before anything is written;
 * - a change committed between those checks and the field's write (here: a second process holds the task's row and
 *   commits it) is not overwritten: the field is skipped and reported, and the other change stays.
 *
 * The two-session case runs on MySQL 8.4 / 9, MariaDB 11.4 and PostgreSQL 16 (TASK_BOARD_TEST_DATABASE_URL).
 */
#[Group('database')]
final class CustomFieldEditRaceTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board/';

    private TaskBoard $board;

    private TaskCustomField $field;

    protected function setUp(): void
    {
        self::$taskBoardConfig               = ['column_transition_policy' => 'app.transition_policy'];
        ScriptedTransitionPolicy::$asked     = [];
        ScriptedTransitionPolicy::$meanwhile = null;
        parent::setUp();
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
        $this->board = $this->board('Fields');
        $this->field = $this->fields()->define($this->owner, $this->board, null, 'Repository', CustomFieldType::Text);
        $this->login($this->owner);
    }

    protected function tearDown(): void
    {
        ScriptedTransitionPolicy::$meanwhile = null;
        parent::tearDown();
    }

    public function testTheFormPostsTheValuesItShows(): void
    {
        $task    = $this->taskWith('core');
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());

        self::assertSame('{"' . $this->field->getId() . '":"core"}', $crawler->filter('input[name="task_form[' . TaskCustomFieldForm::BASE_FIELD . ']"]')->attr('value'));
    }

    public function testAnUntouchedFieldIsNotWrittenBack(): void
    {
        $task    = $this->taskWith('core');
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());
        $this->theirs($task, 'theirs');

        $form                     = $this->form($crawler);
        $form['task_form[title]'] = 'Renamed';
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame(['Renamed', 'theirs'], [$this->titleOf($task), $this->valueOf($task)], 'the edit is saved, the other change stays');
    }

    public function testAChangedFieldSomeoneElseChangedRefusesTheSave(): void
    {
        $task    = $this->taskWith('core');
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());
        $this->theirs($task, 'theirs');

        $form                                                                 = $this->form($crawler);
        $form['task_form[title]']                                             = 'Renamed';
        $form['task_form[' . TaskCustomFieldForm::nameOf($this->field) . ']'] = 'mine';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString('The task changed meanwhile: nothing was saved. Try again.', $this->client->getCrawler()->text());
        self::assertSame(['Original', 'theirs'], [$this->titleOf($task), $this->valueOf($task)], 'nothing of the refused save is written');
    }

    public function testAChangedFieldNobodyElseTouchedIsSaved(): void
    {
        $task    = $this->taskWith('core');
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());

        $form                                                                 = $this->form($crawler);
        $form['task_form[' . TaskCustomFieldForm::nameOf($this->field) . ']'] = 'mine';
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame('mine', $this->valueOf($task));
    }

    /**
     * The other save commits after this request's checks and before the field's write (here while the column transition
     * policy is asked about the new tags): the field is skipped and reported, the other change stays, the rest is saved.
     */
    public function testAChangeCommittedBetweenTheChecksAndTheWriteIsNotOverwritten(): void
    {
        $task                                = $this->taskWith('core');
        $crawler                             = $this->client->request('GET', self::BASE . 'task/' . $task->getId());
        $connection                          = $this->em->getConnection();
        $field                               = $this->field;
        ScriptedTransitionPolicy::$meanwhile = static function () use ($connection, $task, $field): void {
            $connection->executeStatement('UPDATE task_board_custom_field_values SET text_value = ? WHERE task_id = ? AND field_id = ?', ['theirs', $task->getId(), $field->getId()]);
        };
        $this->em->clear();

        $form                                                                 = $this->form($crawler);
        $form['task_form[tags]']                                              = 'urgent';
        $form['task_form[' . TaskCustomFieldForm::nameOf($this->field) . ']'] = 'mine';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString('“Repository” was not saved: someone changed it meanwhile.', $this->client->getCrawler()->text());
        self::assertSame('theirs', $this->valueOf($task), 'the other change stays');
        self::assertStringContainsString('urgent', (string) $this->em->getConnection()->fetchOne('SELECT tags FROM task_board_tasks WHERE id = ?', [$task->getId()]), 'the rest of the edit is saved');
    }

    public function testAChangeCommittedWhileTheWriteWaitsIsNotOverwritten(): void
    {
        if ($this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Two connections need a database server (TASK_BOARD_TEST_DATABASE_URL: MySQL, MariaDB, PostgreSQL).');
        }
        $task    = $this->taskWith('core');
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());

        // The other save: a second process locks the task's row, writes the field, holds the lock, then commits. This
        // request's checks run before the commit (they read "core"); its field write waits on the row.
        $process = proc_open([PHP_BINARY, __DIR__ . '/field-race-worker.php', FunctionalKernel::databaseUrl(), $task->getId(), $this->field->getId(), 'theirs', '1.5'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame('locked', trim((string) fgets($pipes[1])), (string) stream_get_contents($pipes[2]));

        $form                                                                 = $this->form($crawler);
        $form['task_form[' . TaskCustomFieldForm::nameOf($this->field) . ']'] = 'mine';
        $this->client->submit($form);

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $errors);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        // Refused before anything is written (the other save committed before the checks) or the field skipped (it
        // committed while the write waited): never written over.
        self::assertMatchesRegularExpression('/The task changed meanwhile: nothing was saved|“Repository” was not saved: someone changed it meanwhile/', $this->client->getCrawler()->text());
        self::assertSame('theirs', $this->valueOf($task), 'the other change stays');
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
    }

    /**
     * A form without the shown values (a host form, or a base that is not a JSON map of strings) keeps the 2.30 behaviour:
     * a value that differs from the stored one is written, an equal one is not, and nothing is checked before.
     *
     * @return iterable<string, array{string|null}>
     */
    public static function basesWithoutValues(): iterable
    {
        yield 'no base field' => [null];
        yield 'not JSON' => ['not json'];
        yield 'not a map of strings' => ['{"x":1}'];
        yield 'empty' => [''];
        yield 'a map without this field' => ['{}'];
    }

    #[DataProvider('basesWithoutValues')]
    public function testAFormWithoutTheShownValuesWritesAsBefore(?string $base): void
    {
        $task    = $this->taskWith('core');
        $form    = $this->hostForm($task, $base !== null);
        $name    = TaskCustomFieldForm::nameOf($this->field);
        $payload = ['title' => 'Original', $name => 'host'];
        if ($base !== null) {
            $payload[TaskCustomFieldForm::BASE_FIELD] = $base;
        }
        $form->submit($payload, false);
        $fields = new TaskCustomFieldForm($this->fields());

        $fields->assertUnchanged($form, $task);
        $changed = null;
        self::assertSame([], $fields->save($form, $task, $this->owner, $changed));
        self::assertSame([], $changed);
        self::assertSame('host', $this->valueOf($task));

        $again = $this->hostForm($task, false);
        $again->submit(['title' => 'Original', $name => 'host'], false);
        self::assertSame([], $fields->save($again, $task, $this->owner), 'an equal value is not written again');
    }

    /** @return FormInterface<mixed> */
    private function hostForm(Task $task, bool $baseDigest): FormInterface
    {
        $factory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $factory);

        return $factory->create(TaskFormType::class, TaskFormData::fromTask($task), [
            'csrf_protection' => false,
            'base_digest'     => $baseDigest,
            'custom_fields'   => (new TaskCustomFieldForm($this->fields()))->options($task->getBoard(), $task),
        ]);
    }

    private function taskWith(string $value): Task
    {
        $task = $this->task($this->board, 'Original');
        $this->fields()->setField($task, $this->field->getId(), $value, $this->owner);

        return $task;
    }

    /** Someone else's save of the field, committed now. */
    private function theirs(Task $task, string $value): void
    {
        $this->em->getConnection()->executeStatement('UPDATE task_board_custom_field_values SET text_value = ? WHERE task_id = ? AND field_id = ?', [$value, $task->getId(), $this->field->getId()]);
        $this->em->clear();
    }

    private function form(Crawler $crawler): Form
    {
        return $crawler->filter('form[name="task_form"]')->form();
    }

    private function titleOf(Task $task): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT title FROM task_board_tasks WHERE id = ?', [$task->getId()]);
    }

    private function valueOf(Task $task): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT text_value FROM task_board_custom_field_values WHERE task_id = ? AND field_id = ?', [$task->getId(), $this->field->getId()]);
    }

    private function fields(): TaskCustomFieldManager
    {
        $fields = self::getContainer()->get(TaskCustomFieldManager::class);
        self::assertInstanceOf(TaskCustomFieldManager::class, $fields);

        return $fields;
    }
}
