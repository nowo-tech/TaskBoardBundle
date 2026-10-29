<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Support;

use Nowo\TaskBoardBundle\Support\UserLabel;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 1.16.1: the people dialog lists a person by name (the e-mail after it), not by e-mail alone.
 */
final class UserLabelTest extends TestCase
{
    public function testOptionShowsTheNameThenTheEmail(): void
    {
        $user              = new stdClass();
        $user->displayName = 'Ada Lovelace';
        $user->email       = 'ada@example.com';

        self::assertSame('Ada Lovelace', UserLabel::of($user));
        self::assertSame('Ada Lovelace (ada@example.com)', UserLabel::option($user));
    }

    public function testOptionWithoutANameShowsTheEmailOnce(): void
    {
        $user        = new stdClass();
        $user->email = 'ada@example.com';

        self::assertSame('ada@example.com', UserLabel::option($user));
    }

    public function testOptionWithoutAnEmailShowsTheName(): void
    {
        $user           = new stdClass();
        $user->username = 'ada';

        self::assertSame('ada', UserLabel::option($user));
        self::assertSame('#?', UserLabel::option(new stdClass()));
    }
}
