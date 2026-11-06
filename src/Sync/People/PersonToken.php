<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;

/**
 * Since 1.27.0 (TB-SYNC-3): how JSON read-only data (estimates, custom field people) points at a remote person.
 * `person:<id>` is a row of the people directory, so the name stays in one place and `anonymise()` is enough; without
 * a directory (`people` off) `ref:<provider user id>` keeps the reference and no name.
 */
final class PersonToken
{
    public const REF_PREFIX = 'ref:';

    public static function of(?PeopleSession $session, RemotePersonRef $person, int $kind): string
    {
        return $session instanceof PeopleSession
            ? 'person:' . $session->person($person, $kind)->getId()
            : self::REF_PREFIX . $person->providerUserId;
    }
}
