<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Form;

use Nowo\TaskBoardBundle\Form\TaskMemberFormType;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 1.10.1: `user_choices` (the assignee provider's users) is a declared option — the task page answered 500 with an
 * assignee provider configured.
 */
final class TaskMemberFormTypeTest extends TestCase
{
    public function testUserChoicesIsAnOptionalList(): void
    {
        $resolver = new OptionsResolver();
        (new TaskMemberFormType())->configureOptions($resolver);

        self::assertNull($resolver->resolve(['user_class' => stdClass::class])['user_choices']);
        // 1.16.1: no property by default — the options show the person's name (UserLabel::option).
        self::assertNull($resolver->resolve(['user_class' => stdClass::class])['user_choice_label']);
        $users = [new stdClass()];
        self::assertSame($users, $resolver->resolve(['user_class' => stdClass::class, 'user_choices' => $users])['user_choices']);

        $this->expectException(InvalidOptionsException::class);
        $resolver->resolve(['user_class' => stdClass::class, 'user_choices' => 'nope']);
    }
}
