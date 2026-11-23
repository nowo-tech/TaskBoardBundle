<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Cast\RecastingRemovalRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NarrowObjectReturnTypeRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        __DIR__ . '/demo',
        __DIR__ . '/tests/App/var',
        // Placeholder repositories: EntityManager required for manual DI in TaskBoardExtension.
        __DIR__ . '/src/Repository/DoctrineOrmTaskLinkRepository.php',
        __DIR__ . '/src/Repository/DoctrineOrmTaskDependencyRepository.php',
        __DIR__ . '/src/Repository/DoctrineOrmTaskDocumentRepository.php',
        __DIR__ . '/src/Repository/DoctrineOrmTaskMemberRepository.php',
        __DIR__ . '/src/Repository/DoctrineOrmTaskTimeEntryRepository.php',
        // The checklist names are array keys: PHP turns a numeric name ("checklist:2024") into an int key, so the
        // (string) casts are needed (checklistId() takes a string under strict_types, and the API answers "name" as a string).
        RecastingRemovalRector::class => [__DIR__ . '/src/Api/Serializer/TaskApiReadSerializer.php'],
        // Public API: canManageTenantTemplates(object $user, ?string $tenantKey) is public on a service hosts call;
        // dropping the unused $tenantKey breaks every caller that passes it, so it waits for the 3.0 major.
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__ . '/src/Security/TaskBoardResourceAccessChecker.php'],
        // Public signatures: narrowing the private attempt() to RedirectResponse cascades to the public actions
        // (ask/answer/withdraw/publish: Response -> RedirectResponse); public return types stay as they are in a minor.
        NarrowObjectReturnTypeRector::class => [__DIR__ . '/src/Controller/TaskQuestionController.php'],
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
