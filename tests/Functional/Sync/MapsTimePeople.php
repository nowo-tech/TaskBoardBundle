<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;

/**
 * Since 1.25 (audit FP-I2): the time sink only gets entries of people mapped to a user. Remote people 9001–9003 of
 * these tests carry the e-mail `u<id>@example.test` of an existing user, so the directory maps them automatically.
 */
trait MapsTimePeople
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['u9001@example.test', 'u9002@example.test', 'u9003@example.test'] as $email) {
            $this->user($email);
        }
    }

    protected static function ref(string $id, ?string $email = null, ?string $name = null): RemotePersonRef
    {
        return new RemotePersonRef($id, $name ?? 'User ' . $id, $email ?? 'u' . $id . '@example.test');
    }

    protected static function member(string $id, ?string $email = null, ?RemoteRole $role = RemoteRole::Member): RemotePerson
    {
        return new RemotePerson($id, 'User ' . $id, $email ?? 'u' . $id . '@example.test', $role);
    }
}
