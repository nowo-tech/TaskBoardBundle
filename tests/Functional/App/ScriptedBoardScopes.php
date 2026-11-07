<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Board\BoardScope;
use Nowo\TaskBoardBundle\Board\BoardScopeField;
use Nowo\TaskBoardBundle\Board\BoardScopeProviderInterface;
use Nowo\TaskBoardBundle\Board\BoardTenantLabelProviderInterface;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Since 1.30.0: the host's scopes and tenant labels of the functional kernel. */
final class ScriptedBoardScopes implements BoardScopeProviderInterface, BoardTenantLabelProviderInterface
{
    /** @var list<string> */
    public static array $offered = ['personal', 'org'];

    public static ?string $preselected = null;

    /** @var array<string, TaskBoardTenant|null> `<scope>:<target>` */
    public static array $answers = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public static array $resolved = [];

    public static int $labelCalls = 0;

    public static function reset(): void
    {
        self::$offered     = ['personal', 'org'];
        self::$preselected = null;
        self::$answers     = [];
        self::$resolved    = [];
        self::$labelCalls  = 0;
    }

    public function scopes(object $user, ?TaskBoardTenant $current): array
    {
        $all = [
            'personal' => new BoardScope('personal', 'Personal', '🙂', preselected: self::$preselected === 'personal'),
            'org'      => new BoardScope('org', 'Organization', fields: ['organization' => new BoardScopeField(ChoiceType::class, [
                'label'       => 'Organization',
                'choices'     => ['Acme' => 'acme', 'Other' => 'other'],
                'placeholder' => '',
                'constraints' => [new NotBlank()],
            ])], preselected: self::$preselected === 'org'),
        ];
        $scopes = [];
        foreach (self::$offered as $id) {
            $scopes[] = $all[$id];
        }

        return $scopes;
    }

    public function resolve(object $user, string $scopeId, array $values): ?TaskBoardTenant
    {
        self::$resolved[] = [$scopeId, $values];

        return self::$answers[$scopeId . ':' . ($values['organization'] ?? '')] ?? null;
    }

    public function labelsFor(object $user, array $tenantKeys): array
    {
        ++self::$labelCalls;

        return ['tenant-a' => new TaskBoardTenant('tenant-a', 'org-a', 'Acme <b>', '🏢')];
    }
}
