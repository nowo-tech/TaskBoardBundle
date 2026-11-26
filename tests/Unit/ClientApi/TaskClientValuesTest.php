<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\ClientApi;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientContext;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientSource;
use Nowo\TaskBoardBundle\ClientApi\TaskClientApiException;
use Nowo\TaskBoardBundle\ClientApi\TaskClientCursor;
use Nowo\TaskBoardBundle\ClientApi\TaskClientInput;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;

use function base64_encode;
use function rtrim;
use function str_repeat;
use function strtr;

/**
 * Since 3.4.0 (client API v1): the principal, context, cursor and input rules.
 */
final class TaskClientValuesTest extends TestCase
{
    public function testThePrincipalKeepsStringScopesOnceAndChecksItsKeys(): void
    {
        $principal = new TaskClientPrincipal(new stdClass(), ['task-board:read', 'task-board:read', 5, 'task-board:write'], 'tok-1', 'Laptop', 'org:acme');
        self::assertSame(['task-board:read', 'task-board:write'], $principal->scopes);
        self::assertTrue($principal->hasScope('task-board:write'));
        self::assertFalse($principal->hasScope('Task-board:write'));

        $this->expectException(InvalidArgumentException::class);
        new TaskClientPrincipal(new stdClass(), [], 'bad token id');
    }

    public function testABadContextKeyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TaskClientPrincipal(new stdClass(), [], 'tok', '', 'bad key');
    }

    public function testAContextsLabelIsPlainText(): void
    {
        self::assertSame(['key' => 'org:1', 'label' => 'Acme Inc'], (new TaskClientContext('org:1', "Acme\u{202E}\nInc"))->toArray());
        $this->expectException(InvalidArgumentException::class);
        new TaskClientContext('bad key', 'x');
    }

    public function testCursorsAreBoundToTheirKindAndShape(): void
    {
        $cursor = TaskClientCursor::encode('k', ['2026-01-01 00:00:00', 3, 'id']);
        self::assertSame(['2026-01-01 00:00:00', 3, 'id'], TaskClientCursor::decode($cursor, 'k', 3));
        self::assertNull(TaskClientCursor::decode($cursor, 'other', 3));
        self::assertNull(TaskClientCursor::decode($cursor, 'k', 2));
        $encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        foreach (['', '***', str_repeat('a', 513), $encode('v1.k.{nope'), $encode('v1.k.{"a":1}'), $encode('v1.k.[1.5]'), $encode('v1.k.["' . str_repeat('x', 192) . '"]')] as $bad) {
            self::assertNull(TaskClientCursor::decode($bad, 'k', 1), $bad);
        }
    }

    public function testInputRules(): void
    {
        self::assertSame([], TaskClientInput::json(new Request(content: ''), 100));
        self::assertSame(['a' => 1], TaskClientInput::json(new Request(content: '{"a":1}'), 100));
        $this->refused('invalid_json', static fn (): array => TaskClientInput::json(new Request(content: '{"a":"' . str_repeat('x', 200) . '"}'), 100));
        self::assertNull(TaskClientInput::date(['d' => null], 'd'));
        self::assertSame('2026-01-01T09:00:00+00:00', TaskClientInput::date(['d' => '2026-01-01T10:00:00.123+01:00'], 'd')?->format('c'));
        $this->refused('invalid_field', static fn (): ?DateTimeImmutable => TaskClientInput::date(['d' => '0001-01-01T00:00:00Z'], 'd'));
        $this->refused('invalid_field', static fn (): ?string => TaskClientInput::id(['i' => 5], 'i'));
        $this->refused('invalid_field', static fn (): string => TaskClientInput::string(['s' => 5], 's'));
        $this->refused('invalid_field', static fn (): ?string => TaskClientInput::nullableString(['s' => 5], 's'));
        self::assertNull(TaskClientInput::nullableString(['s' => ' '], 's'));
        $this->refused('invalid_field', static fn (): array => TaskClientInput::strings(['l' => ['a', 'b']], 'l', 1));
        $this->refused('invalid_field', static fn (): bool => TaskClientInput::bool([], 'b'));
        self::assertNull(TaskClientInput::nullableInt([], 'n', 0, 1));
        $this->refused('invalid_field', static fn (): TaskClientSource => TaskClientInput::source(['type' => 'chat', 'bad name' => 'x']));
        $this->refused('invalid_field', static fn (): TaskClientSource => TaskClientInput::source(['type' => 'chat', 'id' => str_repeat('x', 129)]));
        self::assertSame(['messageUuid' => 'm'], TaskClientInput::source(['type' => 'chat', 'messageUuid' => 'm'])->fields);

        $request = new Request();
        self::assertNull(TaskClientInput::ifMatch($request, false));
        $request->headers->set('If-Match', 'W/"7"');
        self::assertSame(7, TaskClientInput::ifMatch($request, true));
        $request->headers->set('If-Match', '*');
        self::assertNull(TaskClientInput::ifMatch($request, true, true));
        self::assertSame(30, TaskClientInput::limit(new Request(), 30));
        self::assertSame(5, TaskClientInput::limit(new Request(['limit' => '5']), 30));
        $this->refused('invalid_limit', static fn (): int => TaskClientInput::limit(new Request(['limit' => ['5']]), 30));
        self::assertFalse(TaskClientInput::isId(null));
    }

    public function testARefusalsBody(): void
    {
        self::assertSame(['ok' => false, 'error' => 'invalid_field', 'field' => 'x'], TaskClientApiException::invalidField('x')->body());
    }

    private function refused(string $error, callable $call): void
    {
        try {
            $call();
            self::fail('Expected ' . $error);
        } catch (TaskClientApiException $refusal) {
            self::assertSame($error, $refusal->error);
        }
    }
}
