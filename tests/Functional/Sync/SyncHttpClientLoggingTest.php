<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingLogger;
use ReflectionObject;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function json_encode;

/**
 * Since 1.23 (audit EG-L4): the bundle's own HTTP clients, as a host gets them (no fixture), are not given the
 * application logger — a request never puts its URL and query string (a signed attachment URL) in the logs.
 */
final class SyncHttpClientLoggingTest extends KernelTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(SyncFunctionalTestCase::SYNC_CONFIG, realHttpClient: true);
    }

    public function testNoUrlWithItsQueryReachesTheLogs(): void
    {
        self::bootKernel();
        $client = self::getContainer()->get('nowo_task_board.sync.http_client');
        self::assertInstanceOf(NoPrivateNetworkHttpClient::class, $client);
        $inner = (new ReflectionProperty(NoPrivateNetworkHttpClient::class, 'client'))->getValue($client);
        self::assertInstanceOf(HttpClientInterface::class, $inner);
        if ((new ReflectionObject($inner))->hasProperty('logger')) {
            self::assertNull((new ReflectionProperty($inner, 'logger'))->getValue($inner), 'the inner client has no logger');
        }

        // Port 9 of the loopback refuses at once; the client would log the request before connecting.
        try {
            $inner->request('GET', 'http://127.0.0.1:9/file.png?X-Amz-Signature=SIGNED_CANARY', ['timeout' => 2])->getStatusCode();
        } catch (TransportExceptionInterface) {
        }
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        foreach ($logger->records as $record) {
            self::assertStringNotContainsString('SIGNED_CANARY', (string) json_encode($record));
        }
    }
}
