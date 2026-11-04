<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * Since 1.23 (audit EG-I9): `sync.providers.clickup.attachment_hosts` refuses a pattern that would allow a whole TLD.
 */
final class AttachmentHostsConfigTest extends TestCase
{
    public function testABareTldWildcardIsRefused(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => 'App\\Entity\\User', 'sync' => ['providers' => ['clickup' => ['attachment_hosts' => ['*.files.example.com', 'cdn.example.com']]]]]]);
        self::assertSame(['*.files.example.com', 'cdn.example.com'], $config['sync']['providers']['clickup']['attachment_hosts']);

        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [['user_class' => 'App\\Entity\\User', 'sync' => ['providers' => ['clickup' => ['attachment_hosts' => ['*.com']]]]]]);
    }
}
