<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api;

/**
 * Since 2.2.0 (spec 213): why a request has no principal. `Invalid` is one answer for unknown, revoked, expired, disabled,
 * erased: a client learns nothing about which. `ApiDisabled`: the host's instance switch.
 */
enum TaskApiAuthFailure
{
    case Invalid;
    case ApiDisabled;
}
