<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\Plan;

use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanException;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanLayout;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStep;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedRestructurePolicy;
use ReflectionProperty;

use function array_keys;
use function array_replace_recursive;

/**
 * Since 3.3.0 (CU-3, design §8.5): the Fields step offers, per ClickUp field, *Synced*, *Do not import* and — for text,
 * number and drop-down fields — *Create* or an existing native field of that type (the tenant's, and the board's own
 * when every item fills the same existing board). Other types say *synced only*; the preview counts the choices.
 */
final class ImportPlanNativeFieldsTest extends ImportPlanTestCase
{
    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['restructure' => ['policy' => 'app.restructure_policy']]);
    }

    private const BASE = '/tools/task-board/import-plans';

    public function testTheFieldsStepOffersTheNativeTargetsOfTheBoard(): void
    {
        $this->provider->withFieldDefinitions(self::LIST, [
            new RemoteCustomField('fNum', 'Points', SyncFieldType::Number, 'number'),
            new RemoteCustomField('fDrop', 'Stage', SyncFieldType::Dropdown, 'drop_down'),
            new RemoteCustomField('fDate', 'Due', SyncFieldType::Date, 'date'),
        ]);
        $board   = $this->board('Existing', ['To do'], $this->admin);
        $manager = self::getContainer()->get(TaskCustomFieldManager::class);
        self::assertInstanceOf(TaskCustomFieldManager::class, $manager);
        $own    = $manager->define($this->admin, $board, null, 'Estimate', CustomFieldType::Number);
        $tenant = $manager->define($this->admin, null, null, 'Shared stage', CustomFieldType::Dropdown, [['name' => 'a']]);

        $plans = $this->plans();
        $plan  = $plans->draft(null, $this->admin, 'clickup');
        $plans->chooseConnection($plan, $plan->getVersion(), $plans->connections($plan, $this->admin)[0]);
        $plans->chooseWorkspace($plan, $plan->getVersion(), 'w:1', $this->admin);
        $plans->chooseSources($plan, $plan->getVersion(), [self::LIST], ImportPlanLayout::PerList, []);
        $item = $this->itemsOf($plan)[0];
        $plans->chooseBoards($plan, $plan->getVersion(), [$item->getId() => ['target' => $board->getId(), 'name' => '']], $this->admin);
        $plans->mapStatuses($plan = $this->fresh($plan), $plan->getVersion(), []);
        self::assertSame([$tenant->getId(), $own->getId()], array_keys($plans->nativeTargets($this->fresh($plan))), 'one existing board: the tenant\'s fields and its own');

        $this->login($this->admin);
        $crawler = $this->client->request('GET', self::BASE . '/' . $plan->getId() . '?step=fields');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('[data-testid="nowo-task-plan-field-row"]'));
        $points = $crawler->filter('select[name="task_board_import_plan[f_fNum]"] option')->each(static fn ($o): string => (string) $o->attr('value'));
        self::assertSame(['synced', 'excluded', 'create', $own->getId()], $points);
        $stage = $crawler->filter('select[name="task_board_import_plan[f_fDrop]"] option')->each(static fn ($o): string => (string) $o->attr('value'));
        self::assertSame(['synced', 'excluded', 'create', $tenant->getId()], $stage);
        self::assertSame(['synced', 'excluded'], $crawler->filter('select[name="task_board_import_plan[f_fDate]"] option')->each(static fn ($o): string => (string) $o->attr('value')));
        self::assertSelectorTextContains('[data-testid="nowo-task-plan-field-table"]', 'synced only');

        // A forged target is a form error; a valid choice is saved and shown again.
        $form = $crawler->filter('[data-testid="nowo-task-plan-next"]')->form();
        $this->client->request('POST', $form->getUri(), ['task_board_import_plan' => ['version' => (string) $this->fresh($plan)->getVersion(), 'f_fNum' => $tenant->getId(), 'f_fDrop' => 'synced', 'f_fDate' => 'synced', '_token' => PredictableCsrfTokenManager::value('task_board_import_plan_fields')]]);
        self::assertResponseStatusCodeSame(422);
        $form['task_board_import_plan[f_fNum]']->select($own->getId());
        $form['task_board_import_plan[f_fDrop]']->select('create');
        $form['task_board_import_plan[f_fDate]']->select('excluded');
        $this->client->submit($form);
        self::assertResponseRedirects();
        $plan = $this->fresh($plan);
        self::assertSame(ImportPlanStep::People, $plan->getStep(), 'the next step is offered');
        self::assertSame(['fNum' => $own->getId(), 'fDrop' => 'create'], $plan->getNativeFields());
        self::assertSame(['fDate'], $plan->getExcludedFields());
        $crawler = $this->client->request('GET', self::BASE . '/' . $plan->getId() . '?step=fields');
        self::assertSame($own->getId(), $crawler->filter('select[name="task_board_import_plan[f_fNum]"] option[selected]')->attr('value'));

        // A target gone since: the saved choice falls back to Synced on the page.
        $manager->delete($this->admin, $own);
        $crawler = $this->client->request('GET', self::BASE . '/' . $plan->getId() . '?step=fields');
        self::assertSame('synced', $crawler->filter('select[name="task_board_import_plan[f_fNum]"] option[selected]')->attr('value'));
    }

    public function testOnlyFieldsTheActorMayChangeAreOffered(): void
    {
        ScriptedRestructurePolicy::reset();
        $manager = self::getContainer()->get(TaskCustomFieldManager::class);
        self::assertInstanceOf(TaskCustomFieldManager::class, $manager);
        $tenant = $manager->define($this->admin, null, null, 'Shared', CustomFieldType::Text);
        $plan   = $this->preparedPlan([self::LIST]);
        self::assertSame([$tenant->getId()], array_keys($this->plans()->nativeTargets($plan, $this->admin)));
        // Audit ZCF-L1: an actor who may not manage the tenant's fields is offered none and cannot choose one.
        ScriptedRestructurePolicy::$deny = static fn (string $subject): bool => $subject === 'folders';
        try {
            self::assertSame([], $this->plans()->nativeTargets($plan, $this->admin));
            $fields = $this->plans()->fields($plan);
            $plan   = $this->fresh($plan);
            try {
                $this->plans()->chooseFields($plan, $plan->getVersion(), [], ['fld1' => $tenant->getId()], $fields, $this->admin);
                self::fail('not offered');
            } catch (ImportPlanException $exception) {
                self::assertSame(ImportPlanException::INVALID, $exception->reason);
            }
        } finally {
            ScriptedRestructurePolicy::reset();
        }
    }

    public function testThe32FieldMapIsReadAndMalformedPartsAreIgnored(): void
    {
        $plan = $this->plans()->draft(null, $this->admin, 'clickup');
        $map  = new ReflectionProperty(TaskSyncImportPlan::class, 'fieldMap');
        $map->setValue($plan, ['f1', 'f2']);
        self::assertSame([['f1', 'f2'], [], []], [$plan->getExcludedFields(), $plan->getNativeFields(), $plan->getFieldChoices()]);
        $map->setValue($plan, ['excluded' => 'f1', 'native' => ['a' => 1, 'b' => 'create'], 'fields' => ['bad', ['name' => 'n', 'type' => 't', 'choice' => 'c']]]);
        self::assertSame([[], ['b' => 'create'], [['name' => 'n', 'type' => 't', 'choice' => 'c']]], [$plan->getExcludedFields(), $plan->getNativeFields(), $plan->getFieldChoices()]);
    }
}
