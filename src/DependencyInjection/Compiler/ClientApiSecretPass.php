<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\DependencyInjection\Compiler;

use Nowo\TaskBoardBundle\ClientApi\TaskClientCursor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;

use function is_scalar;
use function trim;

/**
 * Since 3.4.1 (audit ZCS-v-I4): with the client API on, an empty `kernel.secret` fails the container build — the
 * cursor keys derived from it would be public. A secret read from an env var is only known at runtime: the cursor
 * service refuses an empty one when it is built (the first client API request).
 *
 * @internal
 */
final class ClientApiSecretPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TaskClientCursor::class)) {
            return;
        }
        $secret = $container->hasParameter('kernel.secret') ? $container->getParameter('kernel.secret') : null;
        // Audit ZCY-I2: anything but a non-blank scalar (null, an array) is refused too.
        if (!is_scalar($secret) || trim((string) $secret) === '') {
            throw new LogicException('nowo_task_board.client_api needs a non-empty kernel.secret (framework.secret, usually APP_SECRET): the client API cursors are sealed with keys derived from it.');
        }
    }
}
