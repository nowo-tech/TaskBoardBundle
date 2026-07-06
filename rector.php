<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        __DIR__ . '/demo',
        __DIR__ . '/tests/App/var',
        // Placeholder repository: EntityManager required for manual DI in TaskBoardExtension.
        __DIR__ . '/src/Repository/DoctrineOrmTaskLinkRepository.php',
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
