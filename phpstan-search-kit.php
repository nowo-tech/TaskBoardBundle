<?php

declare(strict_types=1);

// Since 2.25.0: SearchKit (PHP >= 8.3) is optional. Without it (the PHP 8.2 floor) the SearchKit groups cannot be
// analysed: they extend its EntitySearchDefinition. They are analysed wherever SearchKit is installed (the PHPStan job).
return is_file(__DIR__ . '/vendor/nowo-tech/search-kit-bundle/src/Provider/Entity/EntitySearchDefinition.php')
    ? []
    : ['parameters' => ['excludePaths' => ['analyseAndScan' => [__DIR__ . '/src/Bridge/SearchKit']]]];
