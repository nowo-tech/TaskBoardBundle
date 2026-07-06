<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

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
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
